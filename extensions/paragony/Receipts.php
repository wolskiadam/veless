<?php
declare(strict_types=1);

namespace PaseExt\Paragony;

use PDO;

/**
 * Paragony z NIP-em (faktury uproszczone) z zakupów firmy: własna tabela i pliki zdjęć.
 *
 * Zdjęcia leżą w storage/uploads/paragony/ (katalog storage ma blokadę WWW), pod losową nazwą.
 * Pokazuje je tylko strona paragony.plik po zalogowaniu. Tabela tworzy się przy pierwszym użyciu
 * (składnia działa w MySQL i SQLite). Identyfikator to losowy tekst, bo AUTO_INCREMENT pisze się
 * w obu bazach inaczej.
 */
final class Receipts
{
    public const PAYMENTS = ['' => '-', 'card' => 'Karta', 'cash' => 'Gotówka', 'blik' => 'BLIK', 'transfer' => 'Przelew'];

    /** Rozpoznane po zawartości pliku, nie po nazwie: typ => rozszerzenie na dysku. */
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/pdf' => 'pdf'];
    private const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_paragony_receipts (
            id VARCHAR(16) NOT NULL PRIMARY KEY,
            purchase_date VARCHAR(10) NOT NULL,
            seller VARCHAR(200) NOT NULL,
            seller_nip VARCHAR(20) NOT NULL,
            receipt_no VARCHAR(64) NOT NULL,
            gross DECIMAL(12,2) NOT NULL,
            vat DECIMAL(12,2) NULL,
            payment VARCHAR(16) NOT NULL,
            description TEXT NOT NULL,
            file VARCHAR(64) NOT NULL,
            file_name VARCHAR(180) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
    }

    public static function dir(): string
    {
        return (defined('PASE_ROOT') ? PASE_ROOT : dirname(__DIR__, 2)) . '/storage/uploads/paragony';
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ext_paragony_receipts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Lata z paragonami, od najnowszego. @return list<string> */
    public function years(): array
    {
        $years = $this->pdo->query('SELECT DISTINCT SUBSTR(purchase_date, 1, 4) AS y FROM ext_paragony_receipts ORDER BY y DESC')->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $years);
    }

    /**
     * Paragony z roku (albo z miesiąca RRRR-MM), od najnowszego, opcjonalnie z wyszukiwaniem.
     * @return list<array<string,mixed>>
     */
    public function list(string $period, string $q = ''): array
    {
        $sql = 'SELECT * FROM ext_paragony_receipts WHERE purchase_date LIKE ?';
        $args = [$period . '-%'];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $sql .= ' AND (seller LIKE ? OR description LIKE ? OR receipt_no LIKE ?' . ($digits !== '' ? ' OR seller_nip LIKE ?' : '') . ')';
            array_push($args, $like, $like, $like);
            if ($digits !== '') {
                $args[] = '%' . $digits . '%'; // NIP szukany bez kresek
            }
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY purchase_date DESC, created_at DESC');
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sprzedawcy z poprzednich paragonów (do podpowiedzi w formularzu): nazwa => NIP. @return array<string,string> */
    public function sellers(): array
    {
        $rows = $this->pdo->query('SELECT seller, seller_nip FROM ext_paragony_receipts ORDER BY updated_at DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['seller']] ??= (string) $r['seller_nip'];
        }
        return $out;
    }

    /**
     * Zapisuje paragon (nowy albo zmieniony). Zdjęcie jest wymagane przy nowym; przy zmianie nowy plik zastępuje stary.
     * @param array<string,mixed> $in pola formularza
     * @param array<string,mixed>|null $file wpis z $_FILES
     * @throws \RuntimeException z komunikatem dla użytkownika
     */
    public function save(?string $id, array $in, ?array $file, string $by): array
    {
        $old = $id !== null ? ($this->get($id) ?? throw new \RuntimeException('Nie ma takiego paragonu.')) : null;
        $data = self::clean($in);
        $stored = $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $this->storeUpload($file) : null;
        if ($stored === null && $old === null) {
            throw new \RuntimeException('Dodaj zdjęcie paragonu.');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($stored !== null) {
            $data += $stored;
        }
        try {
            if ($old === null) {
                $id = bin2hex(random_bytes(8));
                $data += ['id' => $id, 'created_by' => mb_substr($by, 0, 100), 'created_at' => $now, 'updated_at' => $now];
                $cols = array_keys($data);
                $this->pdo->prepare('INSERT INTO ext_paragony_receipts (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($data));
            } else {
                $data['updated_at'] = $now;
                $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($data)));
                $this->pdo->prepare('UPDATE ext_paragony_receipts SET ' . $set . ' WHERE id = ?')->execute([...array_values($data), $id]);
            }
        } catch (\Throwable $e) {
            if ($stored !== null) {
                @unlink(self::dir() . '/' . $stored['file']);
            }
            throw $e;
        }
        if ($stored !== null && $old !== null) {
            $this->removeFile((string) $old['file']);
        }
        return $this->get((string) $id) ?? [];
    }

    public function delete(string $id): void
    {
        $row = $this->get($id) ?? throw new \RuntimeException('Nie ma takiego paragonu.');
        $this->pdo->prepare('DELETE FROM ext_paragony_receipts WHERE id = ?')->execute([$id]);
        $this->removeFile((string) $row['file']);
    }

    /** Pełna ścieżka zdjęcia paragonu albo null, gdy pliku nie ma. */
    public static function filePath(array $row): ?string
    {
        $name = (string) $row['file'];
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|heic|pdf)$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /**
     * Oczyszcza pola formularza. Kwoty przyjmują polski zapis („1 234,50”).
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function clean(array $in): array
    {
        $text = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')) ?? ''), 0, $max);
        $date = (string) ($in['purchase_date'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > date('Y-m-d', strtotime('+1 day'))) {
            throw new \RuntimeException('Podaj datę zakupu.');
        }
        $seller = $text('seller', 200);
        if ($seller === '') {
            throw new \RuntimeException('Podaj sprzedawcę (nazwę sklepu).');
        }
        $nip = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) ($in['seller_nip'] ?? ''))) ?? '';
        if (strlen($nip) > 20) {
            throw new \RuntimeException('NIP sprzedawcy jest za długi.');
        }
        $gross = self::amount((string) ($in['gross'] ?? ''));
        if ($gross === null || $gross <= 0) {
            throw new \RuntimeException('Podaj kwotę z paragonu.');
        }
        $vatRaw = trim((string) ($in['vat'] ?? ''));
        $vat = $vatRaw === '' ? null : self::amount($vatRaw);
        if ($vatRaw !== '' && ($vat === null || $vat < 0 || $vat > $gross)) {
            throw new \RuntimeException('Kwota VAT jest niepoprawna (nie może być większa niż kwota paragonu).');
        }
        $payment = (string) ($in['payment'] ?? '');
        return [
            'purchase_date' => $date,
            'seller' => $seller,
            'seller_nip' => $nip,
            'receipt_no' => $text('receipt_no', 64),
            'gross' => number_format($gross, 2, '.', ''),
            'vat' => $vat === null ? null : number_format($vat, 2, '.', ''),
            'payment' => isset(self::PAYMENTS[$payment]) ? $payment : '',
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000),
        ];
    }

    public static function amount(string $s): ?float
    {
        $s = str_replace([' ', "\u{00A0}", 'zł', 'zl', 'PLN'], '', trim($s));
        if (preg_match('/^\d+[,.]\d{3}[,.]\d{1,2}$/', $s)) {
            $s = preg_replace('/[,.](?=\d{3}[,.])/', '', $s) ?? $s; // 1.234,50
        }
        $s = str_replace(',', '.', $s);
        return preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s) ? round((float) $s, 2) : null;
    }

    /** Zapisuje przesłane zdjęcie albo PDF. @return array{file:string,file_name:string,mime:string} */
    private function storeUpload(array $file): array
    {
        if (($file['error'] ?? 1) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 1) === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('Plik jest za duży (najwyżej 15 MB).');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Nie udało się przesłać zdjęcia. Spróbuj jeszcze raz.');
        }
        $mime = self::sniff($tmp) ?? throw new \RuntimeException('To nie jest zdjęcie ani PDF. Dodaj zdjęcie (JPG, PNG, WEBP, HEIC) albo PDF.');
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu na paragony.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('Nie udało się zapisać zdjęcia.');
        }
        $orig = preg_replace('/[^\w.\- ąćęłńóśżźĄĆĘŁŃÓŚŻŹ]/u', '_', basename((string) ($file['name'] ?? ''))) ?? '';
        return ['file' => $name, 'file_name' => mb_substr($orig !== '' ? $orig : 'paragon.' . self::TYPES[$mime], 0, 180), 'mime' => $mime];
    }

    /** Typ pliku po pierwszych bajtach. */
    public static function sniff(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'mif1', 'msf1', 'heis'], true) => 'image/heic',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    private function removeFile(string $name): void
    {
        $path = self::filePath(['file' => $name]);
        if ($path !== null) {
            @unlink($path);
        }
    }
}

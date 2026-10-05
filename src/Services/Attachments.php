<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Obsługa załączników wiadomości. Pliki trafiają do storage/uploads/ (poza web-rootem,
 * katalog storage ma .htaccess „deny all"), pobierane wyłącznie przez skrypt PHP po
 * weryfikacji dostępu. Nazwa na dysku jest losowa; oryginalna nazwa trzymana w bazie.
 */
final class Attachments
{
    /** Dozwolone rozszerzenia (bezpieczne, nie wykonywalne). */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    public static function dir(): string
    {
        return PASE_ROOT . '/storage/uploads';
    }

    /**
     * Zapisuje przesłany plik ($_FILES[...]). Zwraca [pathRelative, originalName] lub null gdy brak/niepoprawny.
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{0:string,1:string}|null
     * @throws \RuntimeException przy odrzuceniu (typ/rozmiar) - komunikat do pokazania userowi
     */
    public static function store(array $file): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null; // brak pliku - to nie błąd
        }
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Błąd przesyłania pliku.');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('Plik jest za duży (max 8 MB).');
        }

        $orig = (string) ($file['name'] ?? 'plik');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED, true)) {
            throw new \RuntimeException('Niedozwolony typ pliku.');
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu załączników.');
        }

        $safe = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $safe;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Nie udało się zapisać pliku.');
        }

        // Ścieżka względna trzymana w bazie (bez ujawniania absolutnej).
        return ['uploads/' . $safe, self::sanitizeName($orig)];
    }

    /**
     * Zapisuje plik wygenerowany przez CRM (np. PDF faktury z wFirma) tak jak przesłany.
     * @return array{0:string,1:string} [pathRelative, name]
     */
    public static function storeContent(string $content, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED, true)) {
            throw new \RuntimeException('Niedozwolony typ pliku.');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu załączników.');
        }
        $safe = bin2hex(random_bytes(16)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $safe, $content) === false) {
            throw new \RuntimeException('Nie udało się zapisać pliku.');
        }
        return ['uploads/' . $safe, self::sanitizeName($name)];
    }

    /** Pełna ścieżka na dysku z rekordu (attachment_path). Null gdy poza katalogiem (ochrona path traversal). */
    public static function absolutePath(string $relative): ?string
    {
        $base = realpath(PASE_ROOT . '/storage');
        $full = realpath(PASE_ROOT . '/storage/' . ltrim($relative, '/'));
        if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $full;
    }

    /** MIME na podstawie rozszerzenia (do nagłówka pobierania). */
    public static function mimeFor(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            'txt', 'csv' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    private static function sanitizeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ąćęłńóśżźĄĆĘŁŃÓŚŻŹ]/u', '_', $name) ?? 'plik';
        return mb_substr($name, 0, 180);
    }
}

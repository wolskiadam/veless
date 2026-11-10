<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Pliki przy obiektach księgowości (archiwum): oryginał wczytanego wyciągu (MT940/CSV) i PDF z banku.
 * Treść w bazie księgowości (`ext_ksg_files`, base64), więc idzie z jej kopią zapasową i nie zależy od katalogów serwera.
 */
final class Files
{
    public const MAX_BYTES = 10 * 1024 * 1024; // base64 w bazie: ok. 13 MB, poniżej typowego max_allowed_packet 16 MB
    public const KINDS = ['source' => 'Plik wyciągu', 'pdf' => 'PDF z banku'];

    public function __construct(private readonly Db $db)
    {
    }

    /** Typ pliku z treści (nie z nazwy): PDF, tekst wyciągu albo odmowa. */
    public static function mime(string $kind, string $content): string
    {
        if ($kind === 'pdf') {
            if (!str_starts_with($content, '%PDF-')) {
                throw new \RuntimeException('To nie jest plik PDF.');
            }
            return 'application/pdf';
        }
        return 'text/plain';
    }

    public function save(string $ownerType, string $ownerId, string $kind, string $name, string $content, string $user): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \RuntimeException('Nieznany rodzaj pliku.');
        }
        if ($content === '') {
            throw new \RuntimeException('Plik jest pusty.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new \RuntimeException('Plik jest za duży (najwyżej ' . (self::MAX_BYTES / 1024 / 1024) . ' MB).');
        }
        $mime = self::mime($kind, $content);
        $name = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', basename($name)) ?: 'plik', 0, 200);
        $row = ['id' => Db::id(), 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind, 'name' => $name, 'mime' => $mime,
            'size' => strlen($content), 'sha1' => sha1($content), 'content' => base64_encode($content), 'created_by' => $user, 'created_at' => Db::now()];
        $this->db->insert('ext_ksg_files', $row);
        $this->db->log($user, 'file.add', $ownerType, $ownerId, self::KINDS[$kind] . ': ' . $name);
        return $row;
    }

    /** @return list<array<string,mixed>> bez treści */
    public function forOwner(string $ownerType, string $ownerId): array
    {
        return $this->db->all('SELECT id, owner_type, owner_id, kind, name, mime, size, created_by, created_at FROM ext_ksg_files
            WHERE owner_type = ? AND owner_id = ? ORDER BY created_at, name', [$ownerType, $ownerId]);
    }

    /** @return array<string,int> owner_id => liczba plików PDF */
    public function pdfCounts(string $ownerType): array
    {
        $out = [];
        foreach ($this->db->all("SELECT owner_id, COUNT(*) AS n FROM ext_ksg_files WHERE owner_type = ? AND kind = 'pdf' GROUP BY owner_id", [$ownerType]) as $r) {
            $out[(string) $r['owner_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** Plik z treścią (odkodowaną). */
    public function get(string $id): ?array
    {
        $f = $this->db->one('SELECT * FROM ext_ksg_files WHERE id = ?', [$id]);
        if ($f === null) {
            return null;
        }
        $f['content'] = (string) base64_decode((string) $f['content'], true);
        return $f;
    }

    public function delete(string $id, string $user): void
    {
        $f = $this->db->one('SELECT id, owner_type, owner_id, kind, name FROM ext_ksg_files WHERE id = ?', [$id]) ?? throw new \RuntimeException('Nie ma takiego pliku.');
        if ($f['kind'] === 'source') {
            throw new \RuntimeException('Oryginału wyciągu nie usuwa się osobno - zostaje razem z wyciągiem.');
        }
        $this->db->run('DELETE FROM ext_ksg_files WHERE id = ?', [$id]);
        $this->db->log($user, 'file.delete', (string) $f['owner_type'], (string) $f['owner_id'], (string) $f['name']);
    }

    public function deleteForOwner(string $ownerType, string $ownerId): void
    {
        $this->db->run('DELETE FROM ext_ksg_files WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]);
    }
}

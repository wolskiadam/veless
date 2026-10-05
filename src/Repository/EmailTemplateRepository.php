<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Szablony e-mail (tabela email_templates). Body to HTML; wersję tekstową
 * generuje Mailer przy wysyłce. Klucz (tpl_key) jest stabilnym identyfikatorem
 * używanym w regułach automatyzacji (akcja send_email).
 */
final class EmailTemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM email_templates ORDER BY name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tylko aktywne (do dropdownu w regule). @return array<int,array<string,mixed>> */
    public function active(): array
    {
        return $this->pdo->query('SELECT * FROM email_templates WHERE is_active = 1 ORDER BY name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE tpl_key = ? LIMIT 1');
        $stmt->execute([$key]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function keyExists(string $key, int $exceptId = 0): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM email_templates WHERE tpl_key = ? AND id <> ?');
        $stmt->execute([$key, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Tworzy szablon; klucz generowany ze sluga nazwy (unikalny). Zwraca id. */
    public function create(string $name, string $subject, string $body, bool $isActive): int
    {
        $key = $this->uniqueKey($this->slug($name));
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_templates (tpl_key, name, subject, body, is_active)
             VALUES (:k, :n, :s, :b, :a)'
        );
        $stmt->execute([
            ':k' => $key, ':n' => $name, ':s' => $subject, ':b' => $body, ':a' => $isActive ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $subject, string $body, bool $isActive): void
    {
        $this->pdo->prepare(
            'UPDATE email_templates SET name=:n, subject=:s, body=:b, is_active=:a WHERE id=:id'
        )->execute([
            ':n' => $name, ':s' => $subject, ':b' => $body, ':a' => $isActive ? 1 : 0, ':id' => $id,
        ]);
    }

    /** Konto nadawcy szablonu (0 = automatycznie wg sklepu zamówienia). */
    public function setMailAccount(int $id, int $mailAccountId): void
    {
        try {
            $this->pdo->prepare('UPDATE email_templates SET mail_account_id = ? WHERE id = ?')
                ->execute([$mailAccountId > 0 ? $mailAccountId : null, $id]);
        } catch (\Throwable $e) {
            // kolumna jeszcze nie istnieje (migracja) - pomijamy
        }
    }

    /** Czy do e-maila dołączać PDF faktury zamówienia z wFirma. */
    public function setAttachInvoice(int $id, bool $attach): void
    {
        try {
            $this->pdo->prepare('UPDATE email_templates SET attach_invoice = ? WHERE id = ?')->execute([$attach ? 1 : 0, $id]);
        } catch (\Throwable $e) {
            // kolumna jeszcze nie istnieje (migracja) - pomijamy
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
    }

    private function uniqueKey(string $base): string
    {
        $key = $base;
        $n = 2;
        while ($this->keyExists($key)) {
            $key = $base . '_' . $n++;
        }
        return $key;
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string) $s, '_');
        return $s !== '' ? $s : ('szablon_' . substr(md5((string) microtime(true)), 0, 6));
    }
}

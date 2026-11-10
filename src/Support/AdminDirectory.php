<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Lista kont panelu bez sekretów (id, login, nazwa, rola) - dla rozszerzeń, które nadają własne uprawnienia
 * (np. Księgowość). Rozszerzenie nie czyta tabeli kont samo: tam leżą hasła i klucze 2FA.
 */
final class AdminDirectory
{
    /** @return list<array{id:int,username:string,name:string,role:string}> aktywne konta, alfabetycznie */
    public static function activeUsers(PDO $pdo): array
    {
        try {
            $st = $pdo->prepare('SELECT id, username, display_name, role FROM admin_users WHERE is_active = 1 ORDER BY display_name, username');
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'username' => (string) $r['username'],
            'name' => (string) ($r['display_name'] ?: $r['username']), 'role' => (string) $r['role']], $rows);
    }
}

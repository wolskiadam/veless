<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use PDO;

/**
 * Dostęp do księgowości, osobno od ról CRM (tu będą płace i dane pracowników, więc domyślnie nikt poza
 * administratorem CRM nic nie widzi). Poziomy rosną: każdy wyższy ma też uprawnienia niższych.
 *
 * Zapis (POST) dodatkowo wymaga w CRM roli z edycją - pilnuje tego rdzeń (ext.php), zanim strona się uruchomi.
 */
final class Access
{
    public const NONE = 'none';
    public const VIEW = 'view';
    public const BOOK = 'book';
    public const MANAGE = 'manage';

    public const LEVELS = [
        self::NONE => 'Brak dostępu',
        self::VIEW => 'Podgląd ksiąg',
        self::BOOK => 'Księgowanie dokumentów',
        self::MANAGE => 'Zarządzanie (plan kont, lata, firma, dostęp)',
    ];

    private const RANK = [self::NONE => 0, self::VIEW => 1, self::BOOK => 2, self::MANAGE => 3];

    public function __construct(private readonly Db $db)
    {
    }

    /** Poziom użytkownika; administrator CRM ma zawsze pełny dostęp. */
    public function level(?int $userId, bool $isAdmin): string
    {
        if ($isAdmin) {
            return self::MANAGE;
        }
        if ($userId === null) {
            return self::NONE;
        }
        $r = $this->db->one('SELECT level FROM ext_ksg_access WHERE user_id = ?', [$userId]);
        $level = (string) ($r['level'] ?? self::NONE);
        return isset(self::RANK[$level]) ? $level : self::NONE;
    }

    public static function allows(string $have, string $need): bool
    {
        return (self::RANK[$have] ?? 0) >= (self::RANK[$need] ?? 99);
    }

    /** @return array<int,string> user_id => poziom */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT user_id, level FROM ext_ksg_access') as $r) {
            $out[(int) $r['user_id']] = (string) $r['level'];
        }
        return $out;
    }

    public function set(int $userId, string $level, string $by): void
    {
        if (!isset(self::RANK[$level])) {
            throw new \RuntimeException('Nieznany poziom dostępu.');
        }
        $old = $this->db->one('SELECT level FROM ext_ksg_access WHERE user_id = ?', [$userId]);
        if (($old['level'] ?? self::NONE) === $level) {
            return;
        }
        $this->db->run('DELETE FROM ext_ksg_access WHERE user_id = ?', [$userId]);
        if ($level !== self::NONE) {
            $this->db->insert('ext_ksg_access', ['user_id' => $userId, 'level' => $level, 'updated_by' => $by, 'updated_at' => Db::now()]);
        }
        $this->db->log($by, 'access', 'user', (string) $userId, ($old['level'] ?? self::NONE) . ' → ' . $level);
    }

    /**
     * Użytkownicy CRM do nadania dostępu - z rdzenia (AdminDirectory), bez sekretów. Lista kont jest częścią wspólnego
     * logowania; po wyniesieniu modułu zastąpi ją lista z usługi logowania.
     * @return list<array{id:int,name:string,username:string,role:string}>
     */
    public static function crmUsers(PDO $crm): array
    {
        return \Pase\Support\AdminDirectory::activeUsers($crm);
    }
}

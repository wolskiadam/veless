<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Skala interfejsu panelu (jak powiększenie w przeglądarce, ale ustawiana w CRM).
 * Zapisywana per konto w user_layouts (view_key 'ui_scale'), więc działa w każdej przeglądarce.
 */
final class UiScale
{
    public const MIN = 60;
    public const MAX = 130;
    public const STEP = 5;
    public const DEFAULT = 100;
    private const VIEW_KEY = 'ui_scale';

    /** Procent przycięty do zakresu i zaokrąglony do kroku. */
    public static function normalize(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT;
        }
        $v = (int) (round((float) $value / self::STEP) * self::STEP);
        return max(self::MIN, min(self::MAX, $v));
    }

    public static function load(PDO $pdo, int $userId): int
    {
        try {
            $st = $pdo->prepare('SELECT layout FROM user_layouts WHERE user_id = ? AND view_key = ?');
            $st->execute([$userId, self::VIEW_KEY]);
            $raw = $st->fetchColumn();
        } catch (\Throwable) {
            return self::DEFAULT;
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return self::normalize(is_array($data) ? ($data['scale'] ?? null) : null);
    }

    public static function save(PDO $pdo, int $userId, int $scale): int
    {
        $scale = self::normalize($scale);
        // DELETE + INSERT zamiast ON DUPLICATE KEY - działa też na SQLite (testy).
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_layouts WHERE user_id = ? AND view_key = ?')->execute([$userId, self::VIEW_KEY]);
            $pdo->prepare('INSERT INTO user_layouts (user_id, view_key, layout) VALUES (?, ?, ?)')
                ->execute([$userId, self::VIEW_KEY, json_encode(['scale' => $scale])]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $scale;
    }

    /** Skala bieżącego użytkownika; odczyt z bazy raz na sesję. */
    public static function current(PDO $pdo, ?int $userId): int
    {
        if ($userId === null) {
            return self::DEFAULT;
        }
        if (!isset($_SESSION['ui_scale']) || ($_SESSION['ui_scale_user'] ?? null) !== $userId) {
            $_SESSION['ui_scale'] = self::load($pdo, $userId);
            $_SESSION['ui_scale_user'] = $userId;
        }
        return self::normalize($_SESSION['ui_scale']);
    }
}

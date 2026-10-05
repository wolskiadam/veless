<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Mapowania statusów PASE <-> statusy kanałów (WooCommerce, Allegro).
 *
 * Dwa kierunki trzymane są w dwóch różnych miejscach, i to celowo:
 *
 *  - PASE -> kanał: kolumny `woo_status` / `allegro_status` w order_statuses.
 *    Każdy status PASE ma dokładnie jeden odpowiednik w kanale, więc kolumna
 *    przy wierszu jest naturalna.
 *
 *  - kanał -> PASE: klucze w settings (JSON). Tu przypisanie jest wiele-do-jednego
 *    (np. `sent` i `picked_up` mogą oba wpadać do "Wysłane"), a kluczem jest status
 *    kanału - dzięki temu nie da się zapisać niejednoznaczności, w której jeden
 *    status kanału prowadzi do dwóch statusów PASE naraz.
 */
final class StatusMapRepository
{
    public const SETTING_IN_WOO     = 'STATUS_MAP_IN_WOO';
    public const SETTING_IN_ALLEGRO = 'STATUS_MAP_IN_ALLEGRO';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Komplet mapowań w formacie, którego oczekuje Pase\Domain\OrderStatus.
     *
     * @return array{in_woo:array<string,string>,in_allegro:array<string,string>,
     *               out_woo:array<string,string>,out_allegro:array<string,string>}
     */
    public function all(): array
    {
        return [
            'in_woo'      => $this->inbound(self::SETTING_IN_WOO),
            'in_allegro'  => $this->inbound(self::SETTING_IN_ALLEGRO),
            'out_woo'     => $this->outbound('woo_status'),
            'out_allegro' => $this->outbound('allegro_status'),
        ];
    }

    /**
     * Kierunek kanał -> PASE. Klucze normalizujemy do małych liter, bo Allegro
     * zwraca statusy wielkimi (SENT), a porównujemy je po stronie PASE małymi.
     *
     * @return array<string,string>
     */
    public function inbound(string $settingKey): array
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$settingKey]);
        $raw = (string) ($stmt->fetchColumn() ?: '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $channelStatus => $paseStatus) {
            $channelStatus = strtolower(trim((string) $channelStatus));
            $paseStatus    = trim((string) $paseStatus);
            if ($channelStatus !== '' && $paseStatus !== '') {
                $map[$channelStatus] = $paseStatus;
            }
        }
        return $map;
    }

    /**
     * Kierunek PASE -> kanał. Bierzemy tylko wiersze, w których kolumna nie jest
     * NULL-em: NULL znaczy "nie ustawiono, użyj domyślnego", a pusty string
     * (zapisany świadomie) znaczy "nie wysyłaj nic".
     *
     * @return array<string,string>
     */
    public function outbound(string $column): array
    {
        if (!in_array($column, ['woo_status', 'allegro_status'], true)) {
            return [];   // nazwa kolumny idzie do SQL - wpuszczamy tylko znane
        }

        $rows = $this->pdo
            ->query("SELECT status_key, {$column} AS mapped FROM order_statuses WHERE {$column} IS NOT NULL")
            ->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['status_key']] = (string) $row['mapped'];
        }
        return $map;
    }

    /** Zapis kierunku PASE -> kanał dla jednego statusu. Null = wróć do domyślnego. */
    public function setOutbound(string $statusKey, string $column, ?string $channelStatus): void
    {
        if (!in_array($column, ['woo_status', 'allegro_status'], true)) {
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE order_statuses SET {$column} = :val WHERE status_key = :key");
        $stmt->execute([':val' => $channelStatus, ':key' => $statusKey]);
    }

    /** Zapis kierunku kanał -> PASE (cała mapa naraz). */
    public function saveInbound(string $settingKey, array $map): void
    {
        $clean = [];
        foreach ($map as $channelStatus => $paseStatus) {
            $channelStatus = strtolower(trim((string) $channelStatus));
            $paseStatus    = trim((string) $paseStatus);
            if ($channelStatus !== '' && $paseStatus !== '') {
                $clean[$channelStatus] = $paseStatus;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([
            ':k' => $settingKey,
            ':v' => json_encode($clean, JSON_UNESCAPED_UNICODE),
        ]);
    }
}

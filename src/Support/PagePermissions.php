<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Uprawnienia do stron panelu - ten sam model co w sds-generator:
 * dla każdej strony użytkownik ma poziom hidden (Ukryte) / view (Podgląd) / edit (Edycja).
 *
 *  - Administrator zawsze ma pełny dostęp; uprawnienia go nie dotyczą.
 *  - Konto BEZ żadnych zapisanych uprawnień działa jak dotąd, wg roli (editor/viewer) -
 *    po wdrożeniu nikt nie traci dostępu. Szczegółowe uprawnienia włączają się dopiero,
 *    gdy administrator zapisze je dla danej osoby (System → Użytkownicy).
 *  - Podgląd = strona się otwiera, ale zapis (POST) jest blokowany na serwerze.
 *  - Strony pomocnicze (np. podgląd zamówienia, nadanie przesyłki) należą do strony głównej
 *    swojej sekcji i dziedziczą jej poziom.
 *  - Konta użytkowników i własne 2FA nie są w rejestrze: Użytkownicy tylko dla administratora
 *    (inaczej ktoś mógłby nadać sobie rolę admina), 2FA każdy ustawia sobie sam.
 *
 * Tabela user_permissions (user_id, page_key, access_level) - tworzona w bootstrap_admin.php.
 */
final class PagePermissions
{
    public const LEVELS = ['hidden', 'view', 'edit'];

    /**
     * Rejestr stron: klucz => [etykieta, grupa, pliki, minimalna rola w starym modelu, blokady GET w podglądzie].
     * 'legacy' = rola, która dotąd miała dostęp (viewer < editor < admin) - z tego liczone są
     * domyślne uprawnienia proponowane przy pierwszej edycji konta.
     * 'getWrites' = parametry GET, które coś zmieniają (np. ?run= uruchamia import) - w podglądzie zablokowane.
     *
     * @return array<string,array{label:string,group:string,files:list<string>,legacy:string,getWrites?:array<string,list<string>>}>
     */
    public static function registry(): array
    {
        return [
            // --- Zamówienia ---
            'orders' => ['label' => 'Zamówienia (lista i szczegóły)', 'group' => 'Zamówienia', 'legacy' => 'viewer', 'files' => [
                'index.php', 'orders.php', 'orders_search.php', 'order_icons.php', 'order_view.php', 'shipment_create.php', 'shipment_cancel.php',
                'shipment_track.php', 'courier_shipment.php', 'label_download.php', 'attachment.php', 'print_document.php', 'order_merge.php', 'order_new.php']],
            'orders_import' => ['label' => 'Import zamówień', 'group' => 'Zamówienia', 'legacy' => 'editor',
                'files' => ['import_orders.php', 'import_allegro.php', 'import_woo.php'], 'getWrites' => ['import_orders.php' => ['run']]],
            'packing' => ['label' => 'Asystent pakowania (także telefon)', 'group' => 'Zamówienia', 'legacy' => 'editor',
                'files' => ['packing.php', 'packing_api.php', 'packing_photo.php']],
            'queue' => ['label' => 'Kolejka zadań', 'group' => 'Zamówienia', 'legacy' => 'viewer', 'files' => ['queue.php']],
            'customers' => ['label' => 'Klienci (lista i karta klienta)', 'group' => 'Zamówienia', 'legacy' => 'viewer', 'files' => ['customers.php']],
            'returns' => ['label' => 'Zwroty i korekty', 'group' => 'Zamówienia', 'legacy' => 'editor', 'files' => ['returns.php', 'payu_refund.php']],
            'wfirma_receipts' => ['label' => 'Synchronizacja z wFirma (paragony i faktury do zamówień)', 'group' => 'Zamówienia', 'legacy' => 'editor', 'files' => ['wfirma_receipts.php']],
            'order_history' => ['label' => 'Historia zmian', 'group' => 'Zamówienia', 'legacy' => 'editor', 'files' => ['order_history.php']],
            'documents_check' => ['label' => 'Dokumenty do sprawdzenia', 'group' => 'Zamówienia', 'legacy' => 'admin', 'files' => ['document_operations.php']],

            // --- Magazyn ---
            'products' => ['label' => 'Produkty', 'group' => 'Magazyn', 'legacy' => 'viewer', 'files' => ['products.php', 'product_view.php']],
            'products_import' => ['label' => 'Import produktów', 'group' => 'Magazyn', 'legacy' => 'editor',
                'files' => ['import_products.php'], 'getWrites' => ['import_products.php' => ['run']]],
            'categories' => ['label' => 'Kategorie', 'group' => 'Magazyn', 'legacy' => 'viewer', 'files' => ['categories.php']],
            'product_fields' => ['label' => 'Pola produktów', 'group' => 'Magazyn', 'legacy' => 'editor', 'files' => ['product_fields.php']],
            'locations' => ['label' => 'Lokalizacje w magazynie (regały, półki)', 'group' => 'Magazyn', 'legacy' => 'editor',
                'files' => ['locations.php', 'location_labels.php']],
            'product_costs' => ['label' => 'Koszty zakupu i marża (także w Statystykach)', 'group' => 'Magazyn', 'legacy' => 'editor',
                'files' => ['product_costs.php']],
            'gs1' => ['label' => 'GS1 - kody EAN (MojeGS1)', 'group' => 'Magazyn', 'legacy' => 'editor', 'files' => ['gs1.php']],
            'mappings' => ['label' => 'Mapowania SKU', 'group' => 'Magazyn', 'legacy' => 'viewer', 'files' => ['mappings.php']],

            // --- Allegro ---
            'allegro_sync' => ['label' => 'Pobieranie zamówień', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_sync.php']],
            'allegro_listing' => ['label' => 'Wystawianie', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['offer_allegro.php']],
            'allegro_offers' => ['label' => 'Zarządzanie ofertami', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_offers.php']],
            'allegro_dashboard' => ['label' => 'Dashboard Allegro', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_dashboard.php']],
            'allegro_settings' => ['label' => 'Ustawienia i połączenie konta', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_settings.php', 'allegro_connect.php']],
            'allegro_shipping' => ['label' => 'Cenniki wysyłek', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_shipping.php']],
            'allegro_handling' => ['label' => 'Harmonogram czasu wysyłki', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_handling_schedule.php']],
            'allegro_categories' => ['label' => 'Kategorie i parametry', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_categories.php']],
            'allegro_persons' => ['label' => 'Osoby odpowiedzialne', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_persons.php']],
            'allegro_producers' => ['label' => 'Producenci odpowiedzialni', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_producers.php']],
            'allegro_templates' => ['label' => 'Szablony aukcji', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_templates.php']],
            'allegro_links' => ['label' => 'Powiązania', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_links.php']],
            'allegro_ads' => ['label' => 'Allegro Ads', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_ads.php']],
            'allegro_messages' => ['label' => 'Wiadomości', 'group' => 'Allegro', 'legacy' => 'editor', 'files' => ['allegro_messages.php']],

            // --- Konfiguracja ---
            'automations' => ['label' => 'Automatyzacje', 'group' => 'Konfiguracja', 'legacy' => 'viewer', 'files' => ['automations.php']],
            'templates' => ['label' => 'Szablony', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['templates.php']],
            'email_templates' => ['label' => 'Szablony e-mail', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['email_templates.php']],
            'print_templates' => ['label' => 'Szablony wydruku i pliki do druku', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => [
                'print_templates.php', 'print_template_image.php', 'print_asset.php', 'print_asset_image.php', 'print_asset_preview.php', 'print_drop.php']],
            'email_settings' => ['label' => 'E-mail (SMTP)', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['email_settings.php']],
            'client_settings' => ['label' => 'Strona klienta', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['client_settings.php']],
            'company'       => ['label' => 'Firma', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['company.php']],
            'sync_settings' => ['label' => 'Synchronizacja', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['sync_settings.php']],
            'statuses' => ['label' => 'Statusy zamówień', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['statuses.php']],
            'printing' => ['label' => 'Drukowanie', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['printing.php', 'agent_download.php', 'agent_app_download.php']],
            'integrations' => ['label' => 'Integracje', 'group' => 'Konfiguracja', 'legacy' => 'editor',
                'files' => ['integrations.php', 'integration_edit.php', 'tiktokshop_connect.php'],
                'getWrites' => ['integration_edit.php' => ['action'], 'tiktokshop_connect.php' => ['id', 'code', 'state']]],
            'url_migration' => ['label' => 'Adres URL panelu', 'group' => 'Konfiguracja', 'legacy' => 'editor', 'files' => ['url_migration.php']],
            'plugins' => ['label' => 'Wtyczki', 'group' => 'Konfiguracja', 'legacy' => 'admin', 'files' => ['plugins.php']],
            'menu_order' => ['label' => 'Kolejność menu', 'group' => 'Konfiguracja', 'legacy' => 'admin', 'files' => ['menu_order.php']],

            // --- System ---
            'dashboard' => ['label' => 'Pulpit / stan', 'group' => 'System', 'legacy' => 'viewer', 'files' => ['dashboard.php']],
            'statistics' => ['label' => 'Statystyki', 'group' => 'System', 'legacy' => 'viewer', 'files' => ['statistics.php']],
            'email_log' => ['label' => 'Wysłane e-maile (monitoring wysyłki)', 'group' => 'System', 'legacy' => 'editor', 'files' => ['email_log.php']],
            'help' => ['label' => 'Baza wiedzy', 'group' => 'System', 'legacy' => 'viewer', 'files' => ['help.php', 'help_content.php']],
            'server_usage' => ['label' => 'Obciążenie serwera', 'group' => 'System', 'legacy' => 'admin', 'files' => ['server_usage.php']],
            'payu' => ['label' => 'PayU - saldo i wypłaty', 'group' => 'System', 'legacy' => 'admin', 'files' => ['payu.php']],
            'backup' => ['label' => 'Kopia zapasowa', 'group' => 'System', 'legacy' => 'admin', 'files' => ['backup.php']],
            'handover' => ['label' => 'Przeniesienie danych (online ⇄ komputer)', 'group' => 'System', 'legacy' => 'admin', 'files' => ['handover.php']],
        ];
    }

    /** Klucz strony dla pliku (np. 'order_view.php' => 'orders') albo null, gdy plik nie jest w rejestrze. */
    public static function keyForFile(string $file): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::registry() as $key => $page) {
                foreach ($page['files'] as $f) { $map[$f] = $key; }
            }
        }
        return $map[basename($file)] ?? null;
    }

    /** Domyślny poziom wg roli - dokładnie to, co rola dawała przed wprowadzeniem uprawnień. */
    public static function roleDefault(string $role, string $key): string
    {
        if ($role === 'admin') { return 'edit'; }
        $rank = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
        $need = self::registry()[$key]['legacy'] ?? 'admin';
        if (($rank[$role] ?? 0) < $rank[$need]) { return 'hidden'; }
        return $role === 'editor' ? 'edit' : 'view';
    }

    /** @return array<string,string> domyślne uprawnienia roli dla wszystkich stron */
    public static function roleDefaults(string $role): array
    {
        $out = [];
        foreach (array_keys(self::registry()) as $key) { $out[$key] = self::roleDefault($role, $key); }
        return $out;
    }

    public static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INT NOT NULL,
            page_key VARCHAR(50) NOT NULL,
            access_level VARCHAR(10) NOT NULL DEFAULT \'hidden\',
            PRIMARY KEY (user_id, page_key)
        )' . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    /**
     * Zapisane uprawnienia użytkownika albo null, gdy nie ma żadnych (= działa wg roli).
     * @return array<string,string>|null
     */
    public static function load(PDO $pdo, int $userId): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT page_key, access_level FROM user_permissions WHERE user_id = ?');
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\PDOException) {
            return null; // tabela jeszcze nie istnieje
        }
        return $rows ? array_map('strval', $rows) : null;
    }

    /**
     * Zapisuje komplet uprawnień (brakujące strony = Ukryte). Nieznane klucze i poziomy są pomijane.
     * @param array<string,mixed> $posted
     */
    public static function save(PDO $pdo, int $userId, array $posted): void
    {
        self::migrate($pdo); // tabela mogła jeszcze nie powstać (np. pierwsze wejście po wdrożeniu)
        $started = !$pdo->inTransaction() && $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
            $ins = $pdo->prepare('INSERT INTO user_permissions (user_id, page_key, access_level) VALUES (?, ?, ?)');
            foreach (array_keys(self::registry()) as $key) {
                $level = $posted[$key] ?? 'hidden';
                $ins->execute([$userId, $key, in_array($level, self::LEVELS, true) ? $level : 'hidden']);
            }
            if ($started) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** Usuwa szczegółowe uprawnienia - konto wraca do działania wg roli. */
    public static function clear(PDO $pdo, int $userId): void
    {
        self::migrate($pdo);
        $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * Poziom dostępu do strony.
     * @param array<string,string>|null $perms zapisane uprawnienia (null = wg roli)
     */
    public static function access(string $role, ?array $perms, string $key): string
    {
        if ($role === 'admin') { return 'edit'; }
        if ($perms === null) { return self::roleDefault($role, $key); }
        $level = $perms[$key] ?? 'hidden'; // strona dodana później = ukryta, dopóki admin jej nie nada
        return in_array($level, self::LEVELS, true) ? $level : 'hidden';
    }

    /** Czy w trybie podglądu to żądanie GET coś zmienia (np. ?run= w imporcie)? */
    public static function isGetWrite(string $file, array $query): bool
    {
        $key = self::keyForFile($file);
        $params = $key !== null ? (self::registry()[$key]['getWrites'][basename($file)] ?? []) : [];
        foreach ($params as $p) {
            if (isset($query[$p]) && $query[$p] !== '') { return true; }
        }
        return false;
    }
}

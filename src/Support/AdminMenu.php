<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Menu panelu: definicja pozycji + układ ustawiany przez administratora
 * (Konfiguracja → Układ menu, public/admin/menu_order.php).
 *
 * Układ trzymamy w settings pod kluczem NAV_ORDER jako JSON (wersja 2):
 *   {"v":2,
 *    "groups":[{"key":"orders","name":"","items":["index.php",{"key":"custom_x1","name":"Wysyłka","children":["packing.php"]}]},
 *              {"key":"custom_a1","name":"Moja zakładka","items":[...]}],
 *    "hidden":["queue.php"]}
 * Zakładki (grupy) i podkategorie (pod-menu) mogą być wbudowane albo własne (klucz custom_…),
 * każdą można nazwać po swojemu ("name", pusta = nazwa domyślna). Każdą pozycję można
 * przenieść do dowolnej zakładki lub podkategorii albo ukryć z menu.
 * Klucz pozycji = href (albo 'key' dla pod-menu, np. 'allegro').
 * Pozycje nieobecne w układzie (np. nowo dodane w kodzie) trafiają na swoje domyślne miejsce
 * - nowa funkcja nigdy nie zniknie z menu. Puste zakładki i podkategorie znikają z paska.
 * Starszy format (sama kolejność: {"groups":[...],"items":{...}}) nadal działa (applyOrder).
 * Układ nie zmienia uprawnień: pozycje tylko dla administratora nadal widzi tylko on,
 * a ukrycie pozycji nie blokuje dostępu do strony.
 */
final class AdminMenu
{
    public const SETTING = 'NAV_ORDER';
    /** Klucz własnej zakładki/podkategorii utworzonej w edytorze. */
    public const CUSTOM_KEY = '/^custom_[a-z0-9]{1,24}$/';
    /** Tej pozycji nie da się ukryć - inaczej nie byłoby jak wrócić do edytora. */
    public const NOT_HIDEABLE = ['menu_order.php'];

    /** @return array<string,array{label:string,items:list<array<string,mixed>>}> */
    public static function build(bool $canEdit, bool $isAdmin): array
    {
        $groups = [
            'orders' => [
                'label' => t('nav.orders'),
                'items' => [
                    ['href' => 'index.php',         'label' => t('nav.orders.list'), 'desc' => ''],
                    ['href' => 'packing.php',       'label' => t('nav.orders.packing'), 'desc' => ''],
                    ['href' => 'customers.php',     'label' => t('nav.orders.customers'), 'desc' => ''],
                    ['href' => 'returns.php',       'label' => t('nav.orders.returns'), 'desc' => ''],
                    ['href' => 'wfirma_receipts.php', 'label' => t('nav.orders.wfirma_receipts'), 'desc' => ''],
                    ['href' => 'import_orders.php', 'label' => t('nav.orders.import'), 'desc' => ''],
                    ['href' => 'queue.php',         'label' => t('nav.orders.queue'), 'desc' => ''],
                ],
            ],
            'catalog' => [
                'label' => t('nav.catalog'),
                'items' => [
                    ['href' => 'products.php',        'label' => t('nav.products'), 'desc' => ''],
                    ['href' => 'locations.php',       'label' => t('nav.locations'), 'desc' => ''],
                    ['href' => 'product_costs.php',   'label' => t('nav.product_costs'), 'desc' => ''],
                    ['href' => 'gs1.php',             'label' => t('nav.gs1'), 'desc' => ''],
                    ['href' => 'import_products.php', 'label' => t('nav.products.import'), 'desc' => ''],
                    ['href' => 'categories.php',      'label' => t('nav.categories'), 'desc' => ''],
                    ['href' => 'product_fields.php',  'label' => t('nav.fields'), 'desc' => ''],
                    ['href' => 'mappings.php',        'label' => t('nav.mappings'), 'desc' => ''],
                ],
            ],
            'marketplace' => [
                'label' => t('nav.marketplace'),
                // Pozycje = marketplace'y. Allegro ma własne pod-menu (children → flyout).
                // Kolejne marketplace'y (eBay, Amazon...) dodasz jako następne pozycje tutaj.
                'items' => [
                    [
                        'key'   => 'allegro',
                        'label' => t('nav.allegro'),
                        'children' => [
                            ['href' => 'allegro_dashboard.php',  'label' => t('nav.allegro.dashboard')],
                            ['href' => 'allegro_sync.php',       'label' => t('nav.allegro.sync')],
                            ['href' => 'offer_allegro.php',      'label' => t('nav.allegro.listing')],
                            ['href' => 'allegro_offers.php',     'label' => t('nav.allegro.offers')],
                            ['href' => 'allegro_settings.php',   'label' => t('nav.allegro.settings')],
                            ['href' => 'allegro_shipping.php',   'label' => t('nav.allegro.shipping')],
                            ['href' => 'allegro_handling_schedule.php', 'label' => t('nav.allegro.handling_schedule')],
                            ['href' => 'allegro_categories.php', 'label' => t('nav.allegro.categories')],
                            ['href' => 'allegro_persons.php',    'label' => t('nav.allegro.persons')],
                            ['href' => 'allegro_producers.php',  'label' => t('nav.allegro.producers')],
                            ['href' => 'allegro_templates.php',  'label' => t('nav.allegro.templates')],
                            ['href' => 'allegro_links.php',      'label' => t('nav.allegro.links')],
                            ['href' => 'allegro_ads.php',        'label' => t('nav.allegro.ads')],
                            ['href' => 'allegro_messages.php',   'label' => t('nav.allegro.messages')],
                        ],
                    ],
                    [
                        'key'   => 'erli',
                        'label' => t('nav.erli'),
                        'children' => [
                            ['href' => 'erli.php',            'label' => t('nav.erli.dashboard')],
                            ['href' => 'erli_listing.php',    'label' => t('nav.erli.listing')],
                            ['href' => 'erli_products.php',   'label' => t('nav.erli.products')],
                            ['href' => 'erli_orders.php',     'label' => t('nav.erli.orders')],
                            ['href' => 'erli_templates.php',  'label' => t('nav.erli.templates')],
                            ['href' => 'erli_categories.php', 'label' => t('nav.erli.categories')],
                            ['href' => 'erli_shipping.php',   'label' => t('nav.erli.shipping')],
                            ['href' => 'erli_settings.php',   'label' => t('nav.erli.settings')],
                        ],
                    ],
                    [
                        'key'   => 'vonhalsky',
                        'label' => t('nav.vonhalsky'),
                        'children' => [
                            ['href' => 'vonhalsky_listing.php',    'label' => t('nav.vonhalsky.listing')],
                            ['href' => 'vonhalsky_offers.php',     'label' => t('nav.vonhalsky.offers')],
                            ['href' => 'vonhalsky_categories.php', 'label' => t('nav.vonhalsky.categories')],
                            ['href' => 'vonhalsky_returns.php',    'label' => t('nav.vonhalsky.returns')],
                        ],
                    ],
                ],
            ],
            'config' => [
                'label' => t('nav.config'),
                'items' => [
                    ['href' => 'company.php',         'label' => t('nav.company'), 'desc' => ''],
                    ['href' => 'automations.php',     'label' => t('nav.automations'), 'desc' => ''],
                    ['href' => 'templates.php',       'label' => t('nav.templates'), 'desc' => ''],
                    ['href' => 'email_templates.php', 'label' => t('nav.email_tpl'), 'desc' => ''],
                    ['href' => 'print_templates.php', 'label' => t('nav.print_tpl'), 'desc' => ''],
                    ['href' => 'email_settings.php',  'label' => t('nav.email_smtp'), 'desc' => ''],
                    ['href' => 'client_settings.php', 'label' => t('nav.client_page'), 'desc' => ''],
                    ['href' => 'texts.php',           'label' => t('nav.texts'), 'desc' => ''],
                    ['href' => 'sync_settings.php',   'label' => t('nav.sync'), 'desc' => ''],
                    ['href' => 'statuses.php',        'label' => t('nav.statuses'), 'desc' => ''],
                    ['href' => 'printing.php',        'label' => t('nav.printing'), 'desc' => ''],
                    ['href' => 'integrations.php',    'label' => t('nav.integrations'), 'desc' => ''],
                    ['href' => 'url_migration.php',   'label' => t('nav.url_migration'), 'desc' => ''],
                ],
            ],
            'system' => [
                'label' => t('nav.system'),
                'items' => [
                    ['href' => 'dashboard.php',  'label' => t('nav.dashboard'), 'desc' => ''],
                    ['href' => 'statistics.php', 'label' => t('nav.statistics'), 'desc' => ''],
                    ['href' => 'courier_pickups.php', 'label' => t('nav.courier_pickups'), 'desc' => ''],
                    ['href' => 'payu.php',       'label' => t('nav.payu'), 'desc' => ''],
                ],
            ],
        ];

        // GS1 to wtyczka - bez niej (wyłączonej albo usuniętej) strona znika z menu.
        if (!\Pase\Services\Gs1::available()) {
            $groups['catalog']['items'] = array_values(array_filter($groups['catalog']['items'], static fn(array $i): bool => ($i['href'] ?? '') !== 'gs1.php'));
        }

        // ERLI to wtyczka - bez niej (wyłączonej albo usuniętej) zakładka znika z Marketplace.
        if (!\Pase\Services\Erli::available()) {
            $groups['marketplace']['items'] = array_values(array_filter($groups['marketplace']['items'], static fn(array $i): bool => ($i['key'] ?? '') !== 'erli'));
        }

        // InPost vonHalsky to wtyczka - bez niej zakładka znika z Marketplace.
        if (!\Pase\Services\Vonhalsky::available()) {
            $groups['marketplace']['items'] = array_values(array_filter($groups['marketplace']['items'], static fn(array $i): bool => ($i['key'] ?? '') !== 'vonhalsky'));
        }

        // PayU (wypłaty = prawdziwe pieniądze) - wtyczka i tylko administrator albo konto z nadanym uprawnieniem do strony.
        if (!\Pase\Services\PayuPayouts::available() || (!$isAdmin && !(function_exists('usesPagePermissions') && usesPagePermissions()))) {
            $groups['system']['items'] = array_values(array_filter($groups['system']['items'], static fn(array $i): bool => ($i['href'] ?? '') !== 'payu.php'));
        }

        // Historia ma te same uprawnienia co jej kontroler (administrator i edytor).
        if ($canEdit) {
            $groups['orders']['items'][] = ['href' => 'order_history.php', 'label' => t('nav.orders.history'), 'desc' => ''];
            // Wysłane e-maile zawierają adresy klientów - te same uprawnienia co historia.
            $groups['system']['items'][] = ['href' => 'email_log.php', 'label' => t('nav.email_log'), 'desc' => ''];
        }

        // Wtyczki integracji, konta, kopie i kolejność menu - tylko dla administratora.
        if ($isAdmin) {
            $groups['orders']['items'][] = ['href' => 'document_operations.php', 'label' => t('nav.documents_check'), 'desc' => ''];
            $groups['config']['items'][] = ['href' => 'plugins.php', 'label' => t('nav.plugins'), 'desc' => ''];
            $groups['config']['items'][] = ['href' => 'menu_order.php', 'label' => t('nav.menu_order'), 'desc' => ''];
            $groups['config']['items'][] = ['href' => 'appearance.php', 'label' => t('nav.appearance'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'users.php', 'label' => t('nav.users'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'server_usage.php', 'label' => t('nav.server_usage'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'backup.php', 'label' => t('nav.backup'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'update.php', 'label' => t('nav.update'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'handover.php', 'label' => t('nav.handover'), 'desc' => ''];
        }

        // Baza wiedzy na końcu Systemu (osobna grupa „Pomoc” pod administracją).
        $groups['system']['items'][] = ['href' => 'help.php', 'label' => t('nav.help'), 'desc' => ''];

        // Strony rozszerzeń (Hooks::addPage) w wybranej grupie, wg roli z definicji strony.
        foreach (\Pase\Plugin\Hooks::pages() as $key => $page) {
            $role = (string) $page['role'];
            if (empty($page['menu']) || ($role === 'admin' && !$isAdmin) || ($role === 'editor' && !$canEdit)) {
                continue;
            }
            $group = isset($groups[$page['group']]) ? (string) $page['group'] : 'system';
            $groups[$group]['items'][] = ['href' => \Pase\Plugin\Hooks::pageUrl($key),
                'label' => htmlspecialchars((string) $page['title']),
                'desc' => htmlspecialchars((string) ($page['desc'] ?? '')),
                'section' => htmlspecialchars((string) ($page['section'] ?? ''))];
        }
        // Rozszerzenia mogą też przestawić, dodać albo usunąć pozycje menu.
        return self::describe(\Pase\Plugin\Hooks::applyFilters('admin.menu', $groups, $canEdit, $isAdmin));
    }

    /**
     * Grupa w panelu menu (mały szary nagłówek) i jednolinijkowy opis pozycji wbudowanych:
     * href => [klucz grupy, klucz opisu] w słownikach lang/ (Konfiguracja → Teksty).
     */
    private const META = [
        'index.php' => ['nav.sec.orders_work', 'nav.d.orders_list'],
        'packing.php' => ['nav.sec.orders_work', 'nav.d.packing'],
        'customers.php' => ['nav.sec.orders_work', 'nav.d.customers'],
        'returns.php' => ['nav.sec.orders_work', 'nav.d.returns'],
        'import_orders.php' => ['nav.sec.orders_control', 'nav.d.orders_import'],
        'queue.php' => ['nav.sec.orders_control', 'nav.d.queue'],
        'order_history.php' => ['nav.sec.orders_control', 'nav.d.order_history'],
        'document_operations.php' => ['nav.sec.orders_control', 'nav.d.documents_check'],
        'wfirma_receipts.php' => ['nav.sec.orders_docs', 'nav.d.wfirma_receipts'],
        'products.php' => ['nav.sec.catalog_products', 'nav.d.products'],
        'categories.php' => ['nav.sec.catalog_products', 'nav.d.categories'],
        'product_fields.php' => ['nav.sec.catalog_products', 'nav.d.fields'],
        'locations.php' => ['nav.sec.catalog_products', 'nav.d.locations'],
        'product_costs.php' => ['nav.sec.catalog_products', 'nav.d.product_costs'],
        'import_products.php' => ['nav.sec.catalog_data', 'nav.d.products_import'],
        'mappings.php' => ['nav.sec.catalog_data', 'nav.d.mappings'],
        'gs1.php' => ['nav.sec.catalog_data', 'nav.d.gs1'],
        'company.php' => ['nav.sec.config_panel', 'nav.d.company'],
        'appearance.php' => ['nav.sec.config_panel', 'nav.d.appearance'],
        'menu_order.php' => ['nav.sec.config_panel', 'nav.d.menu_order'],
        'texts.php' => ['nav.sec.config_panel', 'nav.d.texts'],
        'url_migration.php' => ['nav.sec.config_panel', 'nav.d.url_migration'],
        'statuses.php' => ['nav.sec.config_sales', 'nav.d.statuses'],
        'automations.php' => ['nav.sec.config_sales', 'nav.d.automations'],
        'sync_settings.php' => ['nav.sec.config_sales', 'nav.d.sync'],
        'integrations.php' => ['nav.sec.config_sales', 'nav.d.integrations'],
        'plugins.php' => ['nav.sec.config_sales', 'nav.d.plugins'],
        'templates.php' => ['nav.sec.config_templates', 'nav.d.templates'],
        'email_templates.php' => ['nav.sec.config_templates', 'nav.d.email_tpl'],
        'print_templates.php' => ['nav.sec.config_templates', 'nav.d.print_tpl'],
        'printing.php' => ['nav.sec.config_templates', 'nav.d.printing'],
        'email_settings.php' => ['nav.sec.config_client', 'nav.d.email_smtp'],
        'client_settings.php' => ['nav.sec.config_client', 'nav.d.client_page'],
        'dashboard.php' => ['nav.sec.system_overview', 'nav.d.dashboard'],
        'statistics.php' => ['nav.sec.system_overview', 'nav.d.statistics'],
        'courier_pickups.php' => ['nav.sec.system_overview', 'nav.d.courier_pickups'],
        'payu.php' => ['nav.sec.system_overview', 'nav.d.payu'],
        'email_log.php' => ['nav.sec.system_overview', 'nav.d.email_log'],
        'users.php' => ['nav.sec.system_admin', 'nav.d.users'],
        'server_usage.php' => ['nav.sec.system_admin', 'nav.d.server_usage'],
        'backup.php' => ['nav.sec.system_admin', 'nav.d.backup'],
        'update.php' => ['nav.sec.system_admin', 'nav.d.update'],
        'handover.php' => ['nav.sec.system_admin', 'nav.d.handover'],
        'help.php' => ['nav.sec.system_help', 'nav.d.help'],
        'allegro_dashboard.php' => [null, 'nav.d.allegro.dashboard'],
        'allegro_sync.php' => [null, 'nav.d.allegro.sync'],
        'offer_allegro.php' => [null, 'nav.d.allegro.listing'],
        'allegro_offers.php' => [null, 'nav.d.allegro.offers'],
        'allegro_settings.php' => [null, 'nav.d.allegro.settings'],
        'allegro_shipping.php' => [null, 'nav.d.allegro.shipping'],
        'allegro_handling_schedule.php' => [null, 'nav.d.allegro.handling_schedule'],
        'allegro_categories.php' => [null, 'nav.d.allegro.categories'],
        'allegro_persons.php' => [null, 'nav.d.allegro.persons'],
        'allegro_producers.php' => [null, 'nav.d.allegro.producers'],
        'allegro_templates.php' => [null, 'nav.d.allegro.templates'],
        'allegro_links.php' => [null, 'nav.d.allegro.links'],
        'allegro_ads.php' => [null, 'nav.d.allegro.ads'],
        'allegro_messages.php' => [null, 'nav.d.allegro.messages'],
        'erli.php' => [null, 'nav.d.erli.dashboard'],
        'erli_listing.php' => [null, 'nav.d.erli.listing'],
        'erli_products.php' => [null, 'nav.d.erli.products'],
        'erli_orders.php' => [null, 'nav.d.erli.orders'],
        'erli_templates.php' => [null, 'nav.d.erli.templates'],
        'erli_categories.php' => [null, 'nav.d.erli.categories'],
        'erli_shipping.php' => [null, 'nav.d.erli.shipping'],
        'erli_settings.php' => [null, 'nav.d.erli.settings'],
        'vonhalsky_listing.php' => [null, 'nav.d.vonhalsky.listing'],
        'vonhalsky_offers.php' => [null, 'nav.d.vonhalsky.offers'],
        'vonhalsky_categories.php' => [null, 'nav.d.vonhalsky.categories'],
        'vonhalsky_returns.php' => [null, 'nav.d.vonhalsky.returns'],
    ];

    /** Opis kanału (podkategorii wbudowanej) w panelu menu: klucz pod-menu => klucz opisu. */
    private const SUB_DESC = ['allegro' => 'nav.d.sub.allegro', 'erli' => 'nav.d.sub.erli', 'vonhalsky' => 'nav.d.sub.vonhalsky'];

    /** Menu bez emotikon i symboli obrazkowych (także w nazwach od wtyczek i własnych nazwach z edytora). */
    public static function plain(string $text): string
    {
        $text = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{2194}-\x{2199}\x{21A9}\x{21AA}'
            . '\x{25A0}-\x{25FF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', $text);
        return trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }

    /** Uzupełnia grupę ('section') i opis ('desc') pozycji wbudowanych oraz czyści etykiety z emotikon. */
    private static function describe(array $groups): array
    {
        $fill = static function (array $item, bool $child): array {
            $item['label'] = self::plain((string) ($item['label'] ?? ''));
            [$section, $desc] = self::META[(string) ($item['href'] ?? '')] ?? [null, null];
            if ($desc !== null && (string) ($item['desc'] ?? '') === '') {
                $item['desc'] = htmlspecialchars(t($desc), ENT_QUOTES, 'UTF-8');
            }
            if (!$child && $section !== null && (string) ($item['section'] ?? '') === '') {
                $item['section'] = htmlspecialchars(t($section), ENT_QUOTES, 'UTF-8');
            }
            $item['desc'] = self::plain((string) ($item['desc'] ?? ''));
            return $item;
        };
        foreach ($groups as $gk => $group) {
            $groups[$gk]['label'] = self::plain((string) ($group['label'] ?? ''));
            foreach ($group['items'] ?? [] as $i => $item) {
                $item = $fill($item, false);
                foreach ($item['children'] ?? [] as $ci => $c) { $item['children'][$ci] = $fill($c, true); }
                if (isset($item['children']) && (string) ($item['desc'] ?? '') === '' && isset(self::SUB_DESC[(string) ($item['key'] ?? '')])) {
                    $item['desc'] = htmlspecialchars(t(self::SUB_DESC[(string) $item['key']]), ENT_QUOTES, 'UTF-8');
                }
                $groups[$gk]['items'][$i] = $item;
            }
        }
        return $groups;
    }

    /**
     * Opis kanału w panelu menu: własny opis albo pierwsze pozycje kanału („Pakowanie, Zwroty”).
     * Opis jest już escapowany (jak etykiety).
     */
    public static function subDesc(array $sub): string
    {
        $desc = self::plain((string) ($sub['desc'] ?? ''));
        if ($desc !== '') { return $desc; }
        $labels = array_map(static fn(array $c): string => self::plain((string) ($c['label'] ?? '')), array_slice($sub['children'] ?? [], 0, 3));
        return implode(', ', array_filter($labels)) . (count($sub['children'] ?? []) > 3 ? '…' : '');
    }

    /**
     * Układa pozycje jednej zakładki w sekcje panelu: luźne pozycje według ich grupy
     * ('section', kolejność pierwszego wystąpienia), podkategoria = osobna sekcja z jej nazwą.
     * @return list<array{title:string,items:list<array<string,mixed>>}>
     */
    public static function sections(array $items): array
    {
        $out = [];
        $byTitle = [];
        foreach ($items as $item) {
            if (isset($item['children'])) {
                $out[] = ['title' => self::plain((string) ($item['label'] ?? '')), 'items' => $item['children']];
                continue;
            }
            $title = self::plain((string) ($item['section'] ?? ''));
            if (!isset($byTitle[$title])) {
                $byTitle[$title] = count($out);
                $out[] = ['title' => $title, 'items' => []];
            }
            $out[$byTitle[$title]]['items'][] = $item;
        }
        return $out;
    }

    /**
     * Rozkłada sekcje panelu na 1-3 kolumny o możliwie równej wysokości (nagłówek liczy się jak
     * pozycja). Kolejność zostaje; długą sekcję (np. Allegro) dzieli na części - nagłówek tylko
     * nad pierwszą. Wybiera najmniej kolumn, przy których najwyższa ma najwyżej $maxRows wierszy.
     * @param list<array{title:string,items:list<array<string,mixed>>}> $sections
     * @return list<list<array{title:string,items:list<array<string,mixed>>}>>
     */
    public static function columns(array $sections, int $maxRows = 10): array
    {
        $chunks = [];
        foreach ($sections as $sec) {
            $n = count($sec['items']);
            $parts = $n > 9 ? (int) ceil($n / 8) : 1;
            $size = (int) ceil($n / $parts);
            foreach (array_chunk($sec['items'], max(1, $size)) as $i => $items) {
                $chunks[] = ['title' => $i === 0 ? $sec['title'] : '', 'items' => $items];
            }
        }
        $h = array_map(static fn(array $c): int => count($c['items']) + ($c['title'] !== '' ? 1 : 0), $chunks);
        $n = count($chunks);
        $best = [$chunks];
        for ($cols = 1; $cols <= min(3, max(1, $n)); ++$cols) {
            // Najlepszy podział na $cols ciągłych kawałków (mało sekcji - wystarczy przejrzeć wszystkie).
            $bestMax = PHP_INT_MAX; $bestCuts = [];
            $cutsList = $cols === 1 ? [[]] : [];
            if ($cols === 2) { for ($a = 1; $a < $n; ++$a) { $cutsList[] = [$a]; } }
            if ($cols === 3) { for ($a = 1; $a < $n; ++$a) { for ($b = $a + 1; $b < $n; ++$b) { $cutsList[] = [$a, $b]; } } }
            foreach ($cutsList as $cuts) {
                $edges = array_merge([0], $cuts, [$n]);
                $max = 0;
                for ($k = 0; $k < count($edges) - 1; ++$k) {
                    $max = max($max, array_sum(array_slice($h, $edges[$k], $edges[$k + 1] - $edges[$k])));
                }
                if ($max < $bestMax) { $bestMax = $max; $bestCuts = $edges; }
            }
            if ($bestCuts === []) { continue; }
            $best = [];
            for ($k = 0; $k < count($bestCuts) - 1; ++$k) {
                $best[] = array_slice($chunks, $bestCuts[$k], $bestCuts[$k + 1] - $bestCuts[$k]);
            }
            if ($bestMax <= $maxRows) { break; }
        }
        return $best;
    }

    /**
     * Zostawia tylko pozycje, które $visible(href) dopuszcza. Pod-menu bez widocznych
     * pozycji i puste grupy znikają.
     */
    public static function filter(array $groups, callable $visible): array
    {
        foreach ($groups as $gk => $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (!empty($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], static fn($c) => $visible((string) $c['href'])));
                    if ($item['children']) { $items[] = $item; }
                } elseif (isset($item['href']) && $visible((string) $item['href'])) {
                    $items[] = $item;
                }
            }
            if ($items) { $groups[$gk]['items'] = $items; } else { unset($groups[$gk]); }
        }
        return $groups;
    }

    /** Klucz pozycji w zapisanej kolejności. */
    public static function key(array $item): string
    {
        return (string) ($item['key'] ?? $item['href'] ?? '');
    }

    /** Ustawia grupy, pozycje i pozycje pod-menu wg zapisanej kolejności (JSON z settings). */
    public static function applyOrder(array $groups, ?string $json): array
    {
        $order = $json ? json_decode($json, true) : null;
        if (!is_array($order)) { return $groups; }

        $groups = self::sortByKeys($groups, $order['groups'] ?? null, static fn($k, $g) => (string) $k, true);
        foreach ($groups as $gk => $group) {
            $groups[$gk]['items'] = self::sortByKeys($group['items'], $order['items'][$gk] ?? null, static fn($k, $i) => self::key($i));
            foreach ($groups[$gk]['items'] as $ik => $item) {
                if (!empty($item['children'])) {
                    $groups[$gk]['items'][$ik]['children'] = self::sortByKeys(
                        $item['children'], $order['items'][self::key($item)] ?? null, static fn($k, $c) => self::key($c)
                    );
                }
            }
        }
        return $groups;
    }

    /**
     * Czyści kolejność przesłaną z edytora: tylko znane klucze, bez duplikatów.
     * @param array<string,mixed> $posted
     * @return array{groups:list<string>,items:array<string,list<string>>}
     */
    public static function sanitizeOrder(array $posted, array $groups): array
    {
        $pick = static function (mixed $list, array $known): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $k) {
                if (is_string($k) && isset($known[$k]) && !in_array($k, $out, true)) { $out[] = $k; }
            }
            return $out;
        };
        $clean = ['groups' => $pick($posted['groups'] ?? null, $groups), 'items' => []];
        foreach ($groups as $gk => $group) {
            $known = [];
            foreach ($group['items'] as $item) {
                $known[self::key($item)] = true;
                if (!empty($item['children'])) {
                    $childKeys = array_fill_keys(array_map(self::key(...), $item['children']), true);
                    $clean['items'][self::key($item)] = $pick($posted['items'][self::key($item)] ?? null, $childKeys);
                }
            }
            $clean['items'][$gk] = $pick($posted['items'][$gk] ?? null, $known);
        }
        return $clean;
    }

    /**
     * Układa menu wg zapisanego ustawienia: format v2 (zakładki, podkategorie, ukryte) albo
     * starszy format samej kolejności. $editor = widok edytora: ukryte pozycje zostają
     * (z flagą 'hidden'), a puste zakładki i podkategorie nie znikają.
     * Grupy i pod-menu dostają 'name' (własna nazwa, surowy tekst) i 'default' (nazwa domyślna).
     */
    public static function applyLayout(array $groups, ?string $json, bool $editor = false): array
    {
        $layout = $json ? json_decode($json, true) : null;
        if (!is_array($layout) || ($layout['v'] ?? null) !== 2 || !is_array($layout['groups'] ?? null)) {
            return self::applyOrder($groups, $json);
        }
        [$leaves, $home, $groupLabels, $subs] = self::catalog($groups);
        $hidden = array_fill_keys(array_filter((array) ($layout['hidden'] ?? []), 'is_string'), true);
        $used = [];
        $out = [];

        // Pozycja z katalogu (tylko te, które ten użytkownik widzi), każda najwyżej raz.
        $leaf = static function (mixed $k) use (&$used, $leaves, $hidden, $editor): ?array {
            if (!is_string($k) || !isset($leaves[$k]) || isset($used[$k])) { return null; }
            $used[$k] = true;
            if (isset($hidden[$k]) && !in_array($k, self::NOT_HIDEABLE, true)) {
                if (!$editor) { return null; }
                return $leaves[$k] + ['hidden' => true];
            }
            return $leaves[$k];
        };

        foreach ($layout['groups'] as $g) {
            $gk = is_array($g) ? (string) ($g['key'] ?? '') : '';
            if ($gk === '' || isset($out[$gk]) || (!isset($groupLabels[$gk]) && !preg_match(self::CUSTOM_KEY, $gk))) { continue; }
            $items = [];
            foreach ((array) ($g['items'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $sk = (string) ($entry['key'] ?? '');
                    if (isset($used['sub:' . $sk]) || (!isset($subs[$sk]) && !preg_match(self::CUSTOM_KEY, $sk))) { continue; }
                    $used['sub:' . $sk] = true;
                    $children = [];
                    foreach ((array) ($entry['children'] ?? []) as $ck) {
                        if (($c = $leaf($ck)) !== null) { $children[] = $c; }
                    }
                    $items[] = self::named(['key' => $sk, 'children' => $children], $entry['name'] ?? '', $subs[$sk]['label'] ?? 'Podkategoria');
                } elseif (($it = $leaf($entry)) !== null) {
                    $items[] = $it;
                }
            }
            $out[$gk] = self::named(['items' => $items], $g['name'] ?? '', $groupLabels[$gk] ?? 'Nowa zakładka');
        }

        // Pozostałe pozycje (nowe w kodzie albo nieujęte w układzie) - na domyślne miejsce.
        foreach ($leaves as $k => $item) {
            if (isset($used[$k])) { continue; }
            [$gk, $sk] = $home[$k];
            if (!isset($out[$gk])) { $out[$gk] = self::named(['items' => []], '', $groupLabels[$gk]); }
            if ($sk === null) { $out[$gk]['items'][] = $item; continue; }
            $placed = false;
            foreach ($out as $ogk => $og) {
                foreach ($og['items'] as $i => $oi) {
                    if (isset($oi['children']) && ($oi['key'] ?? null) === $sk) {
                        $out[$ogk]['items'][$i]['children'][] = $item;
                        $placed = true;
                        break 2;
                    }
                }
            }
            if (!$placed) {
                $out[$gk]['items'][] = self::named(['key' => $sk, 'children' => [$item]], '', $subs[$sk]['label']);
            }
        }

        if ($editor) { return $out; }
        foreach ($out as $gk => $g) {
            $g['items'] = array_values(array_filter($g['items'], static fn(array $i): bool => !isset($i['children']) || $i['children'] !== []));
            if ($g['items']) { $out[$gk] = $g; } else { unset($out[$gk]); }
        }
        return $out;
    }

    /**
     * Czyści układ przesłany z edytora: tylko znane pozycje (każda raz), klucze zakładek
     * i podkategorii wbudowane albo custom_…, nazwy przycięte do 40 znaków.
     * @param array<string,mixed> $posted
     * @return array{v:int,groups:list<array<string,mixed>>,hidden:list<string>}
     */
    public static function sanitizeLayout(array $posted, array $groups): array
    {
        [$leaves, , $groupLabels, $subs] = self::catalog($groups);
        $name = static function (mixed $v): string {
            $v = is_string($v) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)) : '';
            return mb_substr($v, 0, 40);
        };
        $used = [];
        $leafKey = static function (mixed $k) use (&$used, $leaves): ?string {
            if (!is_string($k) || !isset($leaves[$k]) || isset($used[$k])) { return null; }
            $used[$k] = true;
            return $k;
        };
        $clean = ['v' => 2, 'groups' => [], 'hidden' => []];
        foreach (array_slice((array) ($posted['groups'] ?? []), 0, 40) as $g) {
            $gk = is_array($g) && is_string($g['key'] ?? null) ? $g['key'] : '';
            if (isset($used['group:' . $gk]) || (!isset($groupLabels[$gk]) && !preg_match(self::CUSTOM_KEY, $gk))) { continue; }
            $used['group:' . $gk] = true;
            $items = [];
            foreach (array_slice((array) ($g['items'] ?? []), 0, 500) as $entry) {
                if (is_array($entry)) {
                    $sk = is_string($entry['key'] ?? null) ? $entry['key'] : '';
                    if (isset($used['sub:' . $sk]) || (!isset($subs[$sk]) && !preg_match(self::CUSTOM_KEY, $sk))) { continue; }
                    $used['sub:' . $sk] = true;
                    $children = [];
                    foreach (array_slice((array) ($entry['children'] ?? []), 0, 500) as $ck) {
                        if (($c = $leafKey($ck)) !== null) { $children[] = $c; }
                    }
                    $items[] = ['key' => $sk, 'name' => $name($entry['name'] ?? ''), 'children' => $children];
                } elseif (($k = $leafKey($entry)) !== null) {
                    $items[] = $k;
                }
            }
            $clean['groups'][] = ['key' => $gk, 'name' => $name($g['name'] ?? ''), 'items' => $items];
        }
        foreach ((array) ($posted['hidden'] ?? []) as $k) {
            if (is_string($k) && isset($leaves[$k]) && !in_array($k, self::NOT_HIDEABLE, true) && !in_array($k, $clean['hidden'], true)) {
                $clean['hidden'][] = $k;
            }
        }
        return $clean;
    }

    /**
     * Rozkłada menu na: pozycje (klucz => pozycja), ich domyślne miejsce ([grupa, pod-menu|null]),
     * domyślne nazwy grup i wbudowane pod-menu (klucz => [label, group]).
     * @return array{0:array<string,array>,1:array<string,array{0:string,1:?string}>,2:array<string,string>,3:array<string,array{label:string,group:string}>}
     */
    private static function catalog(array $groups): array
    {
        $leaves = $home = $labels = $subs = [];
        foreach ($groups as $gk => $group) {
            $labels[(string) $gk] = (string) $group['label'];
            foreach ($group['items'] as $item) {
                if (isset($item['children'])) {
                    $sk = self::key($item);
                    $subs[$sk] = ['label' => (string) $item['label'], 'group' => (string) $gk];
                    foreach ($item['children'] as $c) {
                        $k = self::key($c);
                        if ($k !== '' && !isset($leaves[$k])) { $leaves[$k] = $c; $home[$k] = [(string) $gk, $sk]; }
                    }
                    continue;
                }
                $k = self::key($item);
                if ($k !== '' && !isset($leaves[$k])) { $leaves[$k] = $item; $home[$k] = [(string) $gk, null]; }
            }
        }
        return [$leaves, $home, $labels, $subs];
    }

    /** Ustawia 'label' (do paska; własna nazwa escapowana), 'name' i 'default' grupy/pod-menu. */
    private static function named(array $node, mixed $name, string $default): array
    {
        $name = is_string($name) ? trim($name) : '';
        return ['label' => $name !== '' ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : $default, 'name' => $name, 'default' => $default] + $node;
    }

    /** Stabilne sortowanie: najpierw wg zapisanej listy, reszta na końcu w domyślnej kolejności. */
    private static function sortByKeys(array $list, mixed $keys, callable $keyOf, bool $preserveKeys = false): array
    {
        if (!is_array($keys) || $keys === []) { return $list; }
        $pos = array_flip(array_values(array_filter($keys, 'is_string')));
        $rows = []; $i = 0;
        foreach ($list as $k => $v) {
            $key = $keyOf($k, $v);
            $rows[] = [$pos[$key] ?? (1000 + $i), $i, $k, $v];
            ++$i;
        }
        usort($rows, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach ($rows as [, , $k, $v]) {
            if ($preserveKeys) { $out[$k] = $v; } else { $out[] = $v; }
        }
        return $out;
    }
}

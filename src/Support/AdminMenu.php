<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Menu panelu: definicja pozycji + kolejność ustawiana przez administratora
 * (Konfiguracja → Kolejność menu, public/admin/menu_order.php).
 *
 * Kolejność trzymamy w settings pod kluczem NAV_ORDER jako JSON:
 *   {"groups": ["orders","catalog",...], "items": {"orders": ["index.php",...], "allegro": [...]}}
 * Klucz pozycji = href (albo 'key' dla pozycji z pod-menu, np. 'allegro').
 * Pozycje nieznane w zapisanej kolejności (np. nowo dodane w kodzie) trafiają na koniec
 * swojej grupy w domyślnym porządku - nowa funkcja nigdy nie zniknie z menu.
 * Kolejność nie zmienia uprawnień: pozycje tylko dla administratora nadal widzi tylko on.
 */
final class AdminMenu
{
    public const SETTING = 'NAV_ORDER';

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
                    ['href' => 'payu.php',       'label' => t('nav.payu'), 'desc' => ''],
                    ['href' => 'help.php',       'label' => t('nav.help'), 'desc' => ''],
                ],
            ],
        ];

        // GS1 to wtyczka - bez niej (wyłączonej albo usuniętej) strona znika z menu.
        if (!\Pase\Services\Gs1::available()) {
            $groups['catalog']['items'] = array_values(array_filter($groups['catalog']['items'], static fn(array $i): bool => ($i['href'] ?? '') !== 'gs1.php'));
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
            $groups['system']['items'][] = ['href' => 'users.php', 'label' => t('nav.users'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'server_usage.php', 'label' => t('nav.server_usage'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'backup.php', 'label' => t('nav.backup'), 'desc' => ''];
            $groups['system']['items'][] = ['href' => 'handover.php', 'label' => t('nav.handover'), 'desc' => ''];
        }

        // Strony rozszerzeń (Hooks::addPage) w wybranej grupie, wg roli z definicji strony.
        foreach (\Pase\Plugin\Hooks::pages() as $key => $page) {
            $role = (string) $page['role'];
            if (empty($page['menu']) || ($role === 'admin' && !$isAdmin) || ($role === 'editor' && !$canEdit)) {
                continue;
            }
            $group = isset($groups[$page['group']]) ? (string) $page['group'] : 'system';
            $groups[$group]['items'][] = ['href' => \Pase\Plugin\Hooks::pageUrl($key),
                'label' => htmlspecialchars((string) $page['title']), 'desc' => ''];
        }
        // Rozszerzenia mogą też przestawić, dodać albo usunąć pozycje menu.
        return \Pase\Plugin\Hooks::applyFilters('admin.menu', $groups, $canEdit, $isAdmin);
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

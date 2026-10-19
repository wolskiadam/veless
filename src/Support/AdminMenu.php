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
                    ['href' => 'erli.php', 'label' => t('nav.erli'), 'desc' => ''],
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
                    ['href' => 'payu.php',       'label' => t('nav.payu'), 'desc' => ''],
                    ['href' => 'help.php',       'label' => t('nav.help'), 'desc' => ''],
                ],
            ],
        ];

        // GS1 to wtyczka - bez niej (wyłączonej albo usuniętej) strona znika z menu.
        if (!\Pase\Services\Gs1::available()) {
            $groups['catalog']['items'] = array_values(array_filter($groups['catalog']['items'], static fn(array $i): bool => ($i['href'] ?? '') !== 'gs1.php'));
        }

        // ERLI to wtyczka - bez niej (wyłączonej albo usuniętej) zakładka znika z Marketplace.
        if (!\Pase\Services\Erli::available()) {
            $groups['marketplace']['items'] = array_values(array_filter($groups['marketplace']['items'], static fn(array $i): bool => ($i['href'] ?? '') !== 'erli.php'));
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

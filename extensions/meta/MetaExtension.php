<?php
declare(strict_types=1);

namespace PaseExt\Meta;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\ExtensionSettings;
use Pase\Plugin\Hooks;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;
use PDO;

/**
 * Rozmowy z Messengera (strona Facebook) i Instagram Direct w CRM.
 *
 *  - Zamówienia → Rozmowy FB i IG: lista rozmów z obu kanałów i rozmowa w dymkach (na telefonie najpierw lista),
 *  - pobieranie nowych wiadomości z crona co kilka minut i przyciskiem „Pobierz teraz”,
 *  - odpowiedź z CRM w ciągu 24 h od ostatniej wiadomości klienta (tak pozwala Meta),
 *  - „Utwórz zamówienie”: formularz Nowe zamówienie z imieniem i nazwiskiem klienta oraz telefonem, e-mailem, adresem
 *    i paczkomatem z zaznaczonych wiadomości; zamówienie zostaje połączone z rozmową (ikona na liście, sekcja na karcie).
 *
 * Opis i podłączenie aplikacji Meta: README.md w tym katalogu (to samo pokazuje strona „Jak podłączyć”).
 */
final class MetaExtension extends AbstractExtension
{
    public const AUTO_EVERY = ['0' => 'Tylko ręcznie (przycisk „Pobierz teraz”)', '2' => 'Co 2 minuty', '5' => 'Co 5 minut', '15' => 'Co 15 minut', '60' => 'Co godzinę'];
    private const AUTO_AT_KEY = 'EXT_META_AUTO_AT';
    private const LAST_KEY = 'EXT_META_LAST';
    private const PAGE_CACHE_KEY = 'EXT_META_PAGE_TOKEN';
    private const IG_REFRESH_KEY = 'EXT_META_IG_REFRESHED';
    private const BUSINESS_INBOX = 'https://business.facebook.com/latest/inbox/all';

    /** Logo kanałów (SVG 24x24, wypełnione). */
    private const LOGO = [
        'fb' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="#0866FF" d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.43 3.14 7.17.16.15.26.35.27.57l.05 1.78c.02.57.6.94 1.12.71l1.98-.87c.17-.08.36-.09.53-.04.91.25 1.88.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2z"/><path fill="#fff" d="M6 14.53l2.94-4.66c.47-.74 1.47-.93 2.17-.4l2.34 1.75c.21.16.51.16.72 0l3.16-2.4c.42-.32.97.18.69.63l-2.94 4.66c-.47.74-1.47.93-2.17.4l-2.34-1.75a.6.6 0 0 0-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63z"/></svg>',
        'ig' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><defs><linearGradient id="mt-ig-g" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FEDA75"/><stop offset=".35" stop-color="#FA7E1E"/><stop offset=".6" stop-color="#D62976"/><stop offset="1" stop-color="#4F5BD5"/></linearGradient></defs><rect x="2" y="2" width="20" height="20" rx="6" fill="url(#mt-ig-g)"/><rect x="6.5" y="6.5" width="11" height="11" rx="3.5" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="16.3" cy="7.7" r="1" fill="#fff"/></svg>',
    ];

    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'meta',
            name: 'Messenger i Instagram',
            version: '1.0.0',
            author: 'Veless',
            description: 'Rozmowy z Messengera (strona Facebook) i Instagram Direct w CRM: lista rozmów z obu kanałów, odpowiadanie w ciągu 24 h i „Utwórz zamówienie” prosto z rozmowy - z imieniem, telefonem, adresem i paczkomatem z wiadomości klienta.',
            icon: '💬',
            fields: [
                ['key' => 'help', 'label' => 'Jak podłączyć stronę Facebook i Instagram', 'type' => 'link', 'url' => Hooks::pageUrl('meta.instrukcja')],
                ['key' => 'page_token', 'label' => 'Token Meta (użytkownika systemowego albo strony Facebook)', 'type' => 'password', 'secret' => true,
                 'help' => 'Najlepiej token użytkownika systemowego z Business Managera z ważnością „Nigdy” - CRM sam weźmie z niego token strony. Zapisywany zaszyfrowany.'],
                ['key' => 'page_id', 'label' => 'ID strony Facebook (tylko gdy token ma dostęp do kilku stron)', 'type' => 'text',
                 'help' => 'Puste = pierwsza strona z tokenu. ID znajdziesz na stronie w Informacje → Przejrzystość strony.'],
                ['key' => 'do_fb', 'label' => 'Messenger: pobieraj rozmowy ze strony Facebook', 'type' => 'checkbox', 'default' => '1'],
                ['key' => 'do_ig', 'label' => 'Instagram Direct', 'type' => 'select', 'default' => 'page',
                 'options' => ['page' => 'Przez stronę Facebook (Instagram połączony ze stroną, ten sam token)', 'ig' => 'Osobny token Instagram (logowanie przez Instagram)', '0' => 'Wyłączony'],
                 'help' => 'Konto Instagram musi być firmowe albo twórcy, a w aplikacji Instagram włączony „Dostęp do wiadomości” dla połączonych narzędzi.'],
                ['key' => 'ig_token', 'label' => 'Token Instagram (tylko przy „Osobny token Instagram”)', 'type' => 'password', 'secret' => true,
                 'help' => 'Token z aplikacji Meta → Instagram → Konfiguracja API z logowaniem przez Instagram. CRM sam go przedłuża co tydzień.'],
                ['key' => 'auto_every', 'label' => 'Pobieraj nowe wiadomości automatycznie', 'type' => 'select', 'default' => '5', 'options' => self::AUTO_EVERY],
                ['key' => 'first_days', 'label' => 'Przy pierwszym pobraniu sięgnij wstecz', 'type' => 'select', 'default' => '30',
                 'options' => ['7' => '7 dni', '30' => '30 dni', '90' => '90 dni']],
                ['key' => 'api_version', 'label' => 'Wersja Graph API', 'type' => 'text', 'default' => MetaApi::VERSION,
                 'help' => 'Zostaw domyślną. Meta wycofuje stare wersje po około dwóch latach.'],
            ],
            connects: 'Meta (Facebook Messenger i Instagram) - rozmowy strony i konta Instagram',
        );
    }

    public function boot(): void
    {
        $inbox = fn(): Inbox => new Inbox($this->pdo());

        Hooks::addPage('rozmowy', ['title' => '💬 Rozmowy FB i IG', 'group' => 'orders', 'role' => 'viewer',
            'render' => fn(PDO $pdo, array $ctx): string => $this->inboxPage($inbox(), $ctx)]);

        Hooks::addPage('instrukcja', ['title' => '💬 Jak podłączyć Messenger i Instagram', 'menu' => false, 'role' => 'viewer',
            'render' => fn(PDO $pdo, array $ctx): string => self::helpPage($ctx)]);

        Hooks::addPage('pobierz', ['title' => 'Pobierz rozmowy', 'menu' => false, 'role' => 'editor',
            'render' => function (PDO $pdo, array $ctx): void {
                if ($ctx['post']) {
                    $r = $this->syncAll(new SettingsRepository($this->pdo()));
                    if ($r['parts'] === [] && $r['errors'] === []) {
                        flash('Najpierw wpisz token Meta w Konfiguracja → Wtyczki → Messenger i Instagram → ⚙ Ustawienia.', 'err');
                    } else {
                        if ($r['parts'] !== []) {
                            flash('Pobrano: ' . implode('; ', $r['parts']) . '.');
                        }
                        if ($r['errors'] !== []) {
                            flash(implode(' ', $r['errors']), 'err');
                        }
                    }
                }
                $c = (string) ($_POST['c'] ?? '');
                header('Location: ' . Hooks::pageUrl('meta.rozmowy', preg_match('/^[a-f0-9]{40}$/', $c) ? ['c' => $c] : []));
                exit;
            }]);

        Hooks::addPage('wyslij', ['title' => 'Odpowiedz', 'menu' => false, 'role' => 'editor',
            'render' => function (PDO $pdo, array $ctx) use ($inbox): void {
                $box = $inbox();
                $conv = $box->get((string) ($_POST['c'] ?? ''));
                if ($ctx['post'] && $conv !== null) {
                    try {
                        $api = $this->apiFor((string) $conv['platform']) ?? throw new \RuntimeException('Brak tokenu Meta w ustawieniach wtyczki.');
                        $box->reply($api, $conv, (string) ($_POST['text'] ?? ''), (string) $ctx['user']);
                        flash('Wysłano odpowiedź.');
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'err');
                        $_SESSION['ext_meta_draft'][(string) $conv['conv_key']] = mb_substr((string) ($_POST['text'] ?? ''), 0, 2000);
                    }
                }
                header('Location: ' . Hooks::pageUrl('meta.rozmowy', $conv !== null ? ['c' => $conv['conv_key']] : []) . '#mt-end');
                exit;
            }]);

        // Zdjęcie z wiadomości: tylko po zalogowaniu, z katalogu poza WWW.
        Hooks::addPage('plik', ['title' => 'Zdjęcie', 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function () use ($inbox): void {
                $inbox();
                $name = (string) ($_GET['f'] ?? '');
                $path = Inbox::filePath($name);
                if ($path === null) {
                    http_response_code(404);
                    echo 'Nie ma takiego pliku.';
                    return;
                }
                header('Content-Type: ' . Inbox::mimeOf($name));
                header('Content-Length: ' . filesize($path));
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, max-age=86400');
                readfile($path);
            }]);

        // ---- Praca w tle
        if ((string) $this->setting('auto_every', '5') !== '0' && $this->configured()) {
            Hooks::addAction('cron.tick', fn(int $now) => $this->autoSync(new SettingsRepository($this->pdo()), $now));
        }

        // ---- Lista zamówień: ikona kanału przy zamówieniu z rozmowy
        $listed = [];
        Hooks::addAction('orders.rows', static function (array $rows) use ($inbox, &$listed): void {
            $listed = $inbox()->forOrders(array_column($rows, 'woo_order_id'));
        });
        Hooks::addFilter('orders.indicators', static function (array $ind, array $row) use (&$listed): array {
            $c = $listed[(int) $row['woo_order_id']] ?? null;
            if ($c !== null) {
                $ind['x_meta'] = ['state' => 'info', 'literal' => true,
                    'label' => 'Zamówienie z rozmowy: ' . Inbox::PLATFORMS[(string) $c['platform']] . ' - ' . self::who($c),
                    'href' => Hooks::pageUrl('meta.rozmowy', ['c' => $c['conv_key']]),
                    'icon' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1.1-4.2A8 8 0 0 1 3 11.5 8.6 8.6 0 0 1 12 3a8.6 8.6 0 0 1 9 8.5z"/>'];
            }
            return $ind;
        });

        // ---- Karta zamówienia: rozmowa, z której powstało zamówienie
        Hooks::addFilter('order_view.sections', static function (array $sections) use ($inbox): array {
            $sections[] = ['id' => 'meta', 'title' => '💬 Rozmowa', 'col' => 'right',
                'render' => static fn(array $row): string => self::orderSection($inbox(), (int) $row['woo_order_id'])];
            return $sections;
        });

        // ---- Nowe zamówienie z rozmowy (?meta=KLUCZ&mm[]=wiadomości)
        Hooks::addFilter('order_new.form', static function (array $form, array $query) use ($inbox): array {
            $conv = self::convFromQuery($inbox(), $query);
            if ($conv === null) {
                return $form;
            }
            $box = $inbox();
            $picked = $box->messagesByKeys((string) $conv['conv_key'], array_map('strval', (array) ($query['mm'] ?? [])));
            $source = $picked !== [] ? $picked : array_slice(array_values(array_filter($box->messages((string) $conv['conv_key']), static fn(array $m): bool => (int) $m['from_peer'] === 1)), -30);
            foreach (OrderDraft::form($conv, $source, $picked !== []) as $k => $v) {
                if (trim((string) ($form[$k] ?? '')) === '') {
                    $form[$k] = $v;
                }
            }
            $form['ext_meta_conv'] = (string) $conv['conv_key'];
            return $form;
        });
        Hooks::addAction('order_new.top', static function (array $form) use ($inbox): void {
            $conv = $inbox()->get((string) ($form['ext_meta_conv'] ?? ''));
            if ($conv !== null) {
                echo '<p class="mt-from" style="color:#2f7a45;font-size:13px;margin:8px 0 0">' . self::LOGO[(string) $conv['platform']] . ' Dane z rozmowy z <a href="'
                    . self::h(Hooks::pageUrl('meta.rozmowy', ['c' => $conv['conv_key']])) . '">' . self::h(self::who($conv)) . '</a> ('
                    . self::h(Inbox::PLATFORMS[(string) $conv['platform']]) . '). Sprawdź je i uzupełnij resztę. Zamówienie będzie połączone z rozmową.</p>';
            }
        });
        Hooks::addAction('order_new.fields', static function (array $form): void {
            $key = (string) ($form['ext_meta_conv'] ?? '');
            if (preg_match('/^[a-f0-9]{40}$/', $key)) {
                echo '<input type="hidden" name="ext_meta_conv" value="' . self::h($key) . '">';
            }
        });
        Hooks::addAction('order_new.created', static function (int $id, array $posted, string $user) use ($inbox): void {
            $key = (string) ($posted['ext_meta_conv'] ?? '');
            if (preg_match('/^[a-f0-9]{40}$/', $key)) {
                $inbox()->linkOrder($id, $key, $user);
            }
        });
    }

    // ---------------------------------------------------------------- połączenie

    private function configured(): bool
    {
        return trim((string) $this->setting('page_token', '')) !== ''
            || ((string) $this->setting('do_ig', 'page') === 'ig' && trim((string) $this->setting('ig_token', '')) !== '');
    }

    private function version(): string
    {
        $v = trim((string) $this->setting('api_version', MetaApi::VERSION));
        return preg_match('/^v\d{1,3}\.\d$/', $v) ? $v : MetaApi::VERSION;
    }

    /**
     * Token strony: wpisany wprost albo wzięty z tokenu użytkownika (systemowego) przez /me/accounts.
     * Wynik jest zapamiętany (zaszyfrowany) dla danego tokenu i ID strony, żeby cron nie pytał o niego co minutę.
     */
    private function pageApi(): ?MetaApi
    {
        $token = trim((string) $this->setting('page_token', ''));
        if ($token === '') {
            return null;
        }
        $pageId = preg_replace('/\D/', '', (string) $this->setting('page_id', '')) ?? '';
        $store = new SettingsRepository($this->pdo());
        $sig = hash('sha256', $token . '|' . $pageId);
        $cached = json_decode((string) $store->get(self::PAGE_CACHE_KEY, ''), true);
        if (is_array($cached) && ($cached['sig'] ?? '') === $sig && ($cached['token'] ?? '') !== '') {
            return MetaApi::forPage((string) $cached['token'], $this->version());
        }
        $pages = MetaApi::forPage($token, $this->version())->pages();
        $use = $token;
        if ($pages !== null) {
            if ($pages === []) {
                throw new MetaApiException('Token Meta nie ma dostępu do żadnej strony Facebook. Przypisz stronę użytkownikowi systemowemu (pełna kontrola) i wygeneruj token jeszcze raz.', 403);
            }
            $page = $pages[0];
            foreach ($pages as $p) {
                if ($pageId !== '' && $p['id'] === $pageId) {
                    $page = $p;
                }
            }
            if ($pageId !== '' && $page['id'] !== $pageId) {
                throw new MetaApiException('Token Meta nie ma dostępu do strony o ID ' . $pageId . '. Dostępne: ' . implode(', ', array_map(static fn(array $p): string => $p['name'] . ' (' . $p['id'] . ')', $pages)) . '.', 403);
            }
            $use = $page['access_token'];
        }
        $store->setMany([self::PAGE_CACHE_KEY => (string) json_encode(['sig' => $sig, 'token' => $use])]);
        return MetaApi::forPage($use, $this->version());
    }

    private function igApi(): ?MetaApi
    {
        $token = trim((string) $this->setting('ig_token', ''));
        return $token !== '' ? MetaApi::forInstagram($token, $this->version()) : null;
    }

    /** API do odpowiedzi w rozmowie z danego kanału. */
    private function apiFor(string $platform): ?MetaApi
    {
        return $platform === 'ig' && (string) $this->setting('do_ig', 'page') === 'ig' ? $this->igApi() : $this->pageApi();
    }

    /**
     * Pobiera nowe wiadomości ze wszystkich włączonych kanałów. Błąd jednego kanału nie zatrzymuje drugiego.
     * @return array{parts:list<string>,errors:list<string>,new:int}
     */
    public function syncAll(SettingsRepository $store, ?int $now = null): array
    {
        $inbox = new Inbox($this->pdo());
        $days = (int) $this->setting('first_days', '30');
        $out = ['parts' => [], 'errors' => [], 'new' => 0];
        $jobs = [];
        if ((string) $this->setting('do_fb', '1') === '1' && trim((string) $this->setting('page_token', '')) !== '') {
            $jobs[] = ['fb', 'messenger', fn(): ?MetaApi => $this->pageApi()];
        }
        $ig = (string) $this->setting('do_ig', 'page');
        if ($ig === 'page' && trim((string) $this->setting('page_token', '')) !== '') {
            $jobs[] = ['ig', 'instagram', fn(): ?MetaApi => $this->pageApi()];
        } elseif ($ig === 'ig' && trim((string) $this->setting('ig_token', '')) !== '') {
            $jobs[] = ['ig', 'instagram', fn(): ?MetaApi => $this->igApi()];
        }
        foreach ($jobs as [$platform, $graph, $api]) {
            $label = Inbox::PLATFORMS[$platform];
            try {
                $client = $api();
                if ($client === null) {
                    continue;
                }
                $r = $inbox->sync($client, $platform, $graph, $store, $days, $now);
                $out['new'] += $r['new'];
                $out['parts'][] = $label . ($r['account'] !== '' ? ' (' . $r['account'] . ')' : '') . ': ' . self::count($r['new'], 'nowa wiadomość', 'nowe wiadomości', 'nowych wiadomości');
            } catch (MetaApiException $e) {
                if ($e->metaCode === 190 || $e->status === 401) {
                    $store->deleteMany([self::PAGE_CACHE_KEY]);
                }
                // Instagram przez stronę bez połączonego konta: czytelna podpowiedź zamiast surowego błędu.
                $msg = $platform === 'ig' && $ig === 'page' && in_array($e->metaCode, [3, 10, 100, 200, 230], true)
                    ? 'Instagram: Meta nie dała dostępu do wiadomości (' . $e->getMessage() . ') Sprawdź, czy konto Instagram jest połączone ze stroną i ma włączony dostęp do wiadomości.'
                    : $label . ': ' . $e->getMessage();
                $out['errors'][] = $msg;
            } catch (\Throwable $e) {
                $out['errors'][] = $label . ': ' . $e->getMessage();
            }
        }
        $store->setMany([self::LAST_KEY => (string) json_encode(['at' => $now ?? time()] + $out, JSON_UNESCAPED_UNICODE)]);
        return $out;
    }

    /** Cron co minutę: pobieranie co tyle minut, ile ustawiono, i przedłużanie tokenu Instagram co tydzień. */
    public function autoSync(SettingsRepository $store, ?int $now = null): ?array
    {
        $now ??= time();
        $this->refreshIgToken($store, $now);
        $every = (int) $this->setting('auto_every', '5');
        if ($every <= 0 || !$this->configured() || $now - (int) $store->get(self::AUTO_AT_KEY, '0') < $every * 60 - 5) {
            return null;
        }
        // Znacznik przed pobieraniem: błąd Meta nie może sprawić, że cron będzie pytał co minutę.
        $store->setMany([self::AUTO_AT_KEY => (string) $now]);
        $r = $this->syncAll($store, $now);
        if ($r['errors'] !== []) {
            Logger::warn('Messenger i Instagram: automatyczne pobieranie - ' . implode(' | ', $r['errors']));
        }
        return $r;
    }

    private function refreshIgToken(SettingsRepository $store, int $now): void
    {
        $api = (string) $this->setting('do_ig', 'page') === 'ig' ? $this->igApi() : null;
        if ($api === null || $now - (int) $store->get(self::IG_REFRESH_KEY, '0') < 7 * 86400) {
            return;
        }
        $store->setMany([self::IG_REFRESH_KEY => (string) $now]);
        try {
            $r = $api->refresh();
            if ($r['token'] !== '') {
                ExtensionSettings::save($this->pdo(), $this->manifest(), ['ig_token' => $r['token']] + ExtensionSettings::get('meta', $this->pdo()));
            }
        } catch (\Throwable $e) {
            Logger::warn('Messenger i Instagram: nie udało się przedłużyć tokenu Instagram - ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- strona rozmów

    private function inboxPage(Inbox $box, array $ctx): string
    {
        $f = (string) ($_GET['f'] ?? '');
        $f = isset(Inbox::PLATFORMS[$f]) ? $f : '';
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $conv = $box->get((string) ($_GET['c'] ?? ''));
        if ($conv !== null && (int) $conv['unread'] > 0) {
            $box->markSeen((string) $conv['conv_key']);
            $conv['unread'] = 0;
        }
        $list = $box->list($f, $q);
        $html = self::style();

        if (!$this->configured() && $list === []) {
            return $html . '<div class="card mt-empty"><h3>Połącz stronę Facebook i Instagram</h3>'
                . '<p>Tu zobaczysz rozmowy z Messengera i Instagram Direct, odpowiesz na nie i jednym kliknięciem zrobisz z rozmowy zamówienie.</p>'
                . '<p>Potrzebny jest token z aplikacji Meta. <a href="' . self::h(Hooks::pageUrl('meta.instrukcja')) . '">Jak go zdobyć - krok po kroku</a>.</p>'
                . ($ctx['isAdmin'] ? '<p><a class="btn" href="plugins.php#pl-cfg-extension-meta">Wpisz token w ustawieniach wtyczki</a></p>' : '<p class="mt-muted">Token wpisuje administrator w Konfiguracja → Wtyczki.</p>')
                . '</div>';
        }

        $last = json_decode((string) (new SettingsRepository($this->pdo()))->get(self::LAST_KEY, ''), true);
        $side = '<div class="mt-side card"><div class="mt-tools">'
            . '<div class="mt-tabs">' . self::tab('', 'Wszystkie', $f, $q) . self::tab('fb', 'Messenger', $f, $q) . self::tab('ig', 'Instagram', $f, $q) . '</div>'
            . '<form method="get" action="ext.php" class="mt-search"><input type="hidden" name="page" value="meta.rozmowy">'
            . ($f !== '' ? '<input type="hidden" name="f" value="' . self::h($f) . '">' : '')
            . '<input type="search" name="q" value="' . self::h($q) . '" placeholder="Szukaj osoby albo słowa"></form>';
        if ($ctx['canEdit']) {
            $side .= '<form method="post" action="' . self::h(Hooks::pageUrl('meta.pobierz')) . '" class="mt-sync"><input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">'
                . ($conv !== null ? '<input type="hidden" name="c" value="' . self::h($conv['conv_key']) . '">' : '')
                . '<button class="btn secondary" type="submit">↻ Pobierz teraz</button>'
                . (is_array($last) && isset($last['at']) ? '<span class="mt-muted">ostatnio ' . self::h(self::when(Inbox::now((int) $last['at']))) . '</span>' : '')
                . '</form>';
        }
        $side .= '</div><div class="mt-list">';
        if ($list === []) {
            $side .= '<p class="mt-muted mt-pad">' . ($q !== '' ? 'Nic nie znaleziono.' : 'Brak rozmów. Kliknij „Pobierz teraz”.') . '</p>';
        }
        foreach ($list as $c) {
            $active = $conv !== null && $c['conv_key'] === $conv['conv_key'];
            $side .= '<a class="mt-row' . ($active ? ' on' : '') . ((int) $c['unread'] > 0 ? ' unread' : '') . '" href="' . self::h(Hooks::pageUrl('meta.rozmowy', array_filter(['c' => $c['conv_key'], 'f' => $f, 'q' => $q]))) . '">'
                . self::avatar($c) . '<span class="mt-row-main"><span class="mt-row-top"><b>' . self::h(self::who($c)) . '</b><small>' . self::h(self::when((string) $c['updated_at'])) . '</small></span>'
                . '<span class="mt-row-text">' . ((int) $c['last_from_peer'] === 0 && (string) $c['last_text'] !== '' ? '<span class="mt-muted">Ty: </span>' : '') . self::h((string) $c['last_text']) . '</span></span>'
                . ((int) $c['unread'] > 0 ? '<span class="mt-badge">' . (int) $c['unread'] . '</span>' : '') . '</a>';
        }
        $side .= '</div></div>';

        $main = '<div class="mt-main card">' . ($conv !== null ? $this->thread($box, $conv, $ctx, $f, $q)
            : '<div class="mt-placeholder"><p>Wybierz rozmowę z listy.</p>' . (is_array($last) && ($last['errors'] ?? []) !== [] ? '<p class="mt-err">' . self::h(implode(' ', (array) $last['errors'])) . '</p>' : '') . '</div>') . '</div>';
        return $html . '<div class="mt-wrap' . ($conv !== null ? ' has-conv' : '') . '">' . $side . $main . '</div>' . self::script();
    }

    private function thread(Inbox $box, array $conv, array $ctx, string $f, string $q): string
    {
        $key = (string) $conv['conv_key'];
        $platform = (string) $conv['platform'];
        $orders = $box->ordersOf($key);
        $back = Hooks::pageUrl('meta.rozmowy', array_filter(['f' => $f, 'q' => $q]));

        $head = '<div class="mt-head"><a class="mt-back" href="' . self::h($back) . '" aria-label="Wróć do listy">←</a>' . self::avatar($conv)
            . '<div class="mt-head-main"><b>' . self::h(self::who($conv)) . '</b><span class="mt-muted">' . self::LOGO[$platform] . ' ' . self::h(Inbox::PLATFORMS[$platform])
            . ((string) $conv['peer_username'] !== '' && (string) $conv['peer_name'] !== '' ? ' · @' . self::h((string) $conv['peer_username']) : '') . '</span></div>'
            . ($ctx['canEdit'] ? '<button class="btn" type="submit" form="mt-order" title="Nowe zamówienie z danymi z rozmowy">+ <span class="mt-wide">Utwórz </span>zamówienie</button>' : '')
            . '</div>';
        if ($orders !== []) {
            $head .= '<div class="mt-orders"><span class="mt-muted">Zamówienia z tej rozmowy:</span>';
            foreach ($orders as $o) {
                $head .= '<a class="mt-chip" href="order_view.php?id=' . (int) $o['woo_order_id'] . '">' . self::h($o['pase_number'] !== null ? '#' . (int) $o['pase_number'] : (string) $o['order_number'])
                    . ' <small>' . number_format((float) $o['total'], 2, ',', ' ') . ' ' . self::h((string) ($o['currency'] ?: 'PLN')) . '</small></a>';
            }
            $head .= '</div>';
        }

        $msgs = $box->messages($key);
        $body = '<form method="get" action="order_new.php" id="mt-order"><input type="hidden" name="meta" value="' . self::h($key) . '"></form>';
        if ($ctx['canEdit'] && $msgs !== []) {
            $body .= '<p class="mt-hint">Zaznacz ☐ wiadomości z adresem i tym, co klient zamawia - CRM przepisze z nich dane do zamówienia. Bez zaznaczenia szuka telefonu i adresu we wszystkich wiadomościach klienta.</p>';
        }
        $body .= '<div class="mt-msgs" id="mt-msgs">';
        $day = '';
        foreach ($msgs as $m) {
            $d = self::fmt((string) $m['created_at'], 'Y-m-d');
            if ($d !== $day) {
                $day = $d;
                $body .= '<div class="mt-day">' . self::h(self::dayLabel($d)) . '</div>';
            }
            $mine = (int) $m['from_peer'] === 0;
            $body .= '<div class="mt-msg ' . ($mine ? 'me' : 'them') . '">'
                . ($ctx['canEdit'] ? '<label class="mt-pick" title="Użyj w zamówieniu"><input type="checkbox" name="mm[]" value="' . self::h($m['msg_key']) . '" form="mt-order"></label>' : '')
                . '<div class="mt-bubble">' . self::attachmentsHtml((string) $m['attachments'])
                . ((string) $m['body'] !== '' ? '<div class="mt-text">' . nl2br(self::h((string) $m['body'])) . '</div>' : '')
                . '<small>' . self::h(self::fmt((string) $m['created_at'], 'H:i')) . ($mine && (string) ($m['sent_by'] ?? '') !== '' ? ' · ' . self::h((string) $m['sent_by']) : '') . '</small></div></div>';
        }
        if ($msgs === []) {
            $body .= '<p class="mt-muted mt-pad">Brak wiadomości w CRM. Kliknij „Pobierz teraz”.</p>';
        }
        $body .= '<div id="mt-end"></div></div>';

        $foot = '';
        if ($ctx['canEdit']) {
            $until = Inbox::replyUntil($conv);
            if (Inbox::canReply($conv)) {
                $draft = (string) ($_SESSION['ext_meta_draft'][$key] ?? '');
                unset($_SESSION['ext_meta_draft'][$key]);
                $foot = '<form method="post" action="' . self::h(Hooks::pageUrl('meta.wyslij')) . '" class="mt-reply"><input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">'
                    . '<input type="hidden" name="c" value="' . self::h($key) . '">'
                    . '<textarea name="text" rows="2" maxlength="2000" placeholder="Napisz odpowiedź…" required>' . self::h($draft) . '</textarea>'
                    . '<button class="btn" type="submit">Wyślij</button></form>'
                    . '<p class="mt-muted mt-small">Odpowiedź możliwa do ' . self::h(self::when(Inbox::now((int) $until))) . ' (24 h od ostatniej wiadomości klienta).</p>';
            } else {
                $foot = '<p class="mt-closed">' . ($until !== null ? 'Minęły 24 godziny od ostatniej wiadomości klienta - Meta nie pozwala już odpowiedzieć z CRM.' : 'Klient jeszcze nic nie napisał w tej rozmowie.')
                    . ' <a href="' . self::BUSINESS_INBOX . '" target="_blank" rel="noopener">Odpowiedz w Meta Business Suite ↗</a></p>';
            }
        }
        return $head . $body . '<div class="mt-foot">' . $foot . '</div>';
    }

    private static function attachmentsHtml(string $json): string
    {
        $out = '';
        foreach (json_decode($json, true) ?: [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            if (($a['type'] ?? '') === 'image' && ($a['file'] ?? '') !== '') {
                $src = Hooks::pageUrl('meta.plik', ['f' => $a['file']]);
                $out .= '<a href="' . self::h($src) . '" target="_blank" rel="noopener"><img class="mt-img" loading="lazy" alt="Zdjęcie" src="' . self::h($src) . '"></a>';
            } elseif (($a['url'] ?? '') !== '' && preg_match('~^https://~', (string) $a['url'])) {
                $out .= '<a class="mt-file" href="' . self::h((string) $a['url']) . '" target="_blank" rel="noopener noreferrer">'
                    . (($a['type'] ?? '') === 'video' ? '🎬 Film' : (($a['type'] ?? '') === 'image' ? '📷 Zdjęcie' : '📎 ' . self::h((string) (($a['name'] ?? '') ?: 'Załącznik')))) . ' ↗</a>';
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- karta zamówienia

    private static function orderSection(Inbox $box, int $orderId): string
    {
        $c = $box->forOrders([$orderId])[$orderId] ?? null;
        if ($c === null) {
            return '';
        }
        $msgs = array_slice($box->messages((string) $c['conv_key']), -4);
        $html = '<p style="margin:0 0 8px">' . self::LOGO[(string) $c['platform']] . ' ' . self::h(Inbox::PLATFORMS[(string) $c['platform']]) . ': <b>' . self::h(self::who($c)) . '</b></p>';
        foreach ($msgs as $m) {
            $html .= '<div style="font-size:13px;margin:4px 0;padding:6px 10px;border-radius:10px;max-width:90%;' . ((int) $m['from_peer'] === 1 ? 'background:var(--bg-2,#f1efe9)' : 'background:#e7f0ff;margin-left:auto') . '">'
                . self::h(mb_strimwidth(Inbox::preview($m), 0, 160, '…')) . ' <small style="color:var(--ink-3)">' . self::h(self::when((string) $m['created_at'])) . '</small></div>';
        }
        return $html . '<p style="margin:8px 0 0"><a href="' . self::h(Hooks::pageUrl('meta.rozmowy', ['c' => $c['conv_key']])) . '">Otwórz rozmowę →</a></p>';
    }

    private static function convFromQuery(Inbox $box, array $query): ?array
    {
        $key = (string) ($query['meta'] ?? '');
        return preg_match('/^[a-f0-9]{40}$/', $key) ? $box->get($key) : null;
    }

    // ---------------------------------------------------------------- instrukcja

    private static function helpPage(array $ctx): string
    {
        $md = (string) @file_get_contents(__DIR__ . '/README.md');
        $start = strpos($md, '## Jak podłączyć');
        $end = $start !== false ? strpos($md, "\n## ", $start + 5) : false;
        $part = $start !== false ? substr($md, $start, $end !== false ? $end - $start : null) : '';
        return self::style() . '<p><a href="' . self::h(Hooks::pageUrl('meta.rozmowy')) . '">← Rozmowy FB i IG</a></p><div class="card mt-help">'
            . self::markdown($part) . ($ctx['isAdmin'] ? '<p><a class="btn" href="plugins.php#pl-cfg-extension-meta">Ustawienia wtyczki</a></p>' : '') . '</div>';
    }

    /** Prosty Markdown instrukcji: nagłówki, listy numerowane i punktowane, **pogrubienie**, `kod`, [link](url). */
    private static function markdown(string $md): string
    {
        $inline = static function (string $s): string {
            $s = self::h($s);
            $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s) ?? $s;
            $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s) ?? $s;
            return preg_replace('/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/u', '<a href="$2" target="_blank" rel="noopener">$1</a>', $s) ?? $s;
        };
        $out = '';
        $list = '';
        foreach (preg_split('/\R/u', $md) ?: [] as $line) {
            $t = rtrim($line);
            $kind = preg_match('/^\d+\.\s+/', $t) ? 'ol' : (preg_match('/^\s*[-*]\s+/', $t) ? 'ul' : '');
            if ($list !== '' && $kind !== $list && !preg_match('/^\s{2,}\S/', $t)) {
                $out .= '</' . $list . '>';
                $list = '';
            }
            if ($t === '') {
                continue;
            }
            if (preg_match('/^(#{2,4})\s+(.*)$/u', $t, $m)) {
                $lvl = strlen($m[1]) + 1;
                $out .= '<h' . $lvl . '>' . $inline($m[2]) . '</h' . $lvl . '>';
            } elseif ($kind !== '') {
                if ($list === '') {
                    $out .= '<' . $kind . '>';
                    $list = $kind;
                }
                $out .= '<li>' . $inline((string) preg_replace('/^(\d+\.|\s*[-*])\s+/', '', $t)) . '</li>';
            } elseif ($list !== '' && preg_match('/^\s{2,}(\S.*)$/u', $t, $m)) {
                $out = preg_replace('/<\/li>$/', '<br>' . $inline($m[1]) . '</li>', $out) ?? $out;
            } elseif (str_starts_with($t, '> ')) {
                $out .= '<p class="mt-note">' . $inline(substr($t, 2)) . '</p>';
            } else {
                $out .= '<p>' . $inline($t) . '</p>';
            }
        }
        return $out . ($list !== '' ? '</' . $list . '>' : '');
    }

    // ---------------------------------------------------------------- pomocnicze

    public static function who(array $c): string
    {
        $name = trim((string) $c['peer_name']);
        $user = trim((string) $c['peer_username']);
        return $name !== '' ? $name : ($user !== '' ? '@' . $user : 'Klient');
    }

    private static function avatar(array $c): string
    {
        $who = self::who($c);
        $letters = '';
        foreach (array_slice(preg_split('/\s+/u', ltrim($who, '@')) ?: [], 0, 2) as $w) {
            $letters .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        $hue = hexdec(substr(sha1($who), 0, 2)) * 360 / 255;
        return '<span class="mt-av" style="background:hsl(' . (int) $hue . ',45%,52%)">' . self::h($letters ?: '?') . '<i>' . self::LOGO[(string) $c['platform']] . '</i></span>';
    }

    private static function tab(string $key, string $label, string $current, string $q): string
    {
        return '<a class="' . ($key === $current ? 'on' : '') . '" href="' . self::h(Hooks::pageUrl('meta.rozmowy', array_filter(['f' => $key, 'q' => $q]))) . '">' . self::h($label) . '</a>';
    }

    /** Czas z bazy (UTC, jak cały CRM) po polsku: „14:05”, „03.11 14:05”, „03.11.2025”. */
    private static function when(string $dt): string
    {
        if (self::fmt($dt, 'Y') === '') {
            return '';
        }
        $now = self::fmt(Inbox::now(), 'Y-m-d');
        if (self::fmt($dt, 'Y-m-d') === $now) {
            return self::fmt($dt, 'H:i');
        }
        return self::fmt($dt, self::fmt($dt, 'Y') === substr($now, 0, 4) ? 'd.m H:i' : 'd.m.Y');
    }

    /** Czas zapisany w UTC w strefie Europe/Warsaw. */
    private static function fmt(string $dt, string $format): string
    {
        try {
            return $dt === '' ? '' : (new \DateTimeImmutable($dt, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format($format);
        } catch (\Exception) {
            return '';
        }
    }

    private static function dayLabel(string $d): string
    {
        $today = self::fmt(Inbox::now(), 'Y-m-d');
        return match ($d) {
            $today => 'Dzisiaj',
            self::fmt(Inbox::now(time() - 86400), 'Y-m-d') => 'Wczoraj',
            default => implode('.', array_reverse(explode('-', $d))),
        };
    }

    private static function count(int $n, string $one, string $few, string $many): string
    {
        $form = $n === 1 ? $one : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? $few : $many);
        return $n . ' ' . $form;
    }

    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function script(): string
    {
        return <<<'JS'
<script>
(() => {
    const box = document.getElementById('mt-msgs');
    if (box) { box.scrollTop = box.scrollHeight; }
    // Nowe wiadomości: odświeżenie co 2 minuty, gdy nic nie jest wpisane ani zaznaczone.
    setInterval(() => {
        const ta = document.querySelector('.mt-reply textarea');
        if (document.visibilityState === 'visible' && !(ta && ta.value.trim()) && !document.querySelector('.mt-pick input:checked')) { location.reload(); }
    }, 120000);
    const ta = document.querySelector('.mt-reply textarea');
    if (ta) { ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); ta.form.requestSubmit(); } }); }
})();
</script>
JS;
    }

    private static function style(): string
    {
        return '<style>
.mt-wrap{display:grid;grid-template-columns:minmax(260px,340px) minmax(0,1fr);gap:14px;align-items:stretch}
.mt-wrap .card{margin:0}
.mt-side{display:flex;flex-direction:column;padding:0;overflow:hidden;max-height:calc(100vh - 150px);min-height:420px}
.mt-tools{padding:12px;display:flex;flex-direction:column;gap:8px;border-bottom:1px solid var(--line,#e5e2da)}
.mt-tabs{display:flex;gap:4px;flex-wrap:wrap}
.mt-tabs a{padding:4px 10px;border-radius:999px;font-size:13px;color:var(--ink-2);text-decoration:none;border:1px solid var(--line,#e5e2da)}
.mt-tabs a.on{background:var(--ink,#222);color:#fff;border-color:transparent}
.mt-search{margin:0}.mt-search input{width:100%;box-sizing:border-box}
.mt-sync{display:flex;gap:8px;align-items:center;margin:0;flex-wrap:wrap}.mt-sync .btn{padding:5px 10px;font-size:13px}
.mt-list{overflow-y:auto;flex:1}
.mt-row{display:flex;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--line,#e5e2da);color:inherit;text-decoration:none}
.mt-row:hover{background:var(--bg-2,rgba(0,0,0,.03))}
.mt-row.on{background:var(--bg-2,rgba(0,0,0,.06))}
.mt-row-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.mt-row-top{display:flex;gap:8px;justify-content:space-between;align-items:baseline}
.mt-row-top b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.mt-row-top small{color:var(--ink-3);white-space:nowrap;flex:none}
.mt-row-text{font-size:13px;color:var(--ink-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-row.unread .mt-row-top b,.mt-row.unread .mt-row-text{font-weight:700;color:var(--ink,#222)}
.mt-badge{flex:none;min-width:20px;height:20px;border-radius:10px;background:#0866FF;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 6px;box-sizing:border-box}
.mt-av{position:relative;flex:none;width:38px;height:38px;border-radius:50%;color:#fff;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center}
.mt-av i{position:absolute;right:-3px;bottom:-3px;width:18px;height:18px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center}
.mt-av i svg{width:14px;height:14px}
.mt-main{display:flex;flex-direction:column;padding:0;overflow:hidden;max-height:calc(100vh - 150px);min-height:420px}
.mt-placeholder{margin:auto;text-align:center;color:var(--ink-3);padding:20px}
.mt-head{display:flex;gap:10px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line,#e5e2da)}
.mt-head-main{flex:1;min-width:0;display:flex;flex-direction:column}
.mt-head-main .mt-muted{display:flex;gap:4px;align-items:center;font-size:12px}
.mt-back{display:none;font-size:22px;text-decoration:none;color:inherit;padding:0 6px 0 0}
.mt-orders{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:8px 14px;border-bottom:1px solid var(--line,#e5e2da);font-size:13px}
.mt-chip{padding:3px 10px;border-radius:999px;background:var(--bg-2,#f1efe9);text-decoration:none;color:inherit;font-weight:600}
.mt-chip small{color:var(--ink-3);font-weight:400}
.mt-hint{margin:0;padding:8px 14px;font-size:12px;color:var(--ink-3);border-bottom:1px solid var(--line,#e5e2da)}
.mt-msgs{flex:1;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;gap:6px}
.mt-day{align-self:center;font-size:12px;color:var(--ink-3);margin:8px 0 2px}
.mt-msg{display:flex;gap:6px;align-items:flex-end;max-width:80%}
.mt-msg.them{align-self:flex-start}
.mt-msg.me{align-self:flex-end;flex-direction:row-reverse}
.mt-bubble{padding:8px 12px;border-radius:16px;background:var(--bg-2,#f1efe9);overflow-wrap:anywhere}
.mt-msg.me .mt-bubble{background:#0866FF;color:#fff}
.mt-msg.me .mt-bubble a{color:#fff}
.mt-bubble small{display:block;font-size:11px;opacity:.65;margin-top:2px}
.mt-msg.me .mt-bubble small{text-align:right}
.mt-img{display:block;max-width:240px;max-height:240px;border-radius:10px;margin:2px 0}
.mt-file{display:inline-block;margin:2px 0}
.mt-pick{display:flex;align-items:center;padding-bottom:6px;cursor:pointer}
.mt-pick input{width:16px;height:16px;margin:0}
.mt-foot{border-top:1px solid var(--line,#e5e2da);padding:10px 14px}
.mt-foot:empty{display:none}
.mt-reply{display:flex;gap:8px;align-items:flex-end;margin:0}
.mt-reply textarea{flex:1;resize:vertical;min-height:42px;box-sizing:border-box}
.mt-closed{margin:0;font-size:13px;color:var(--ink-2)}
.mt-muted{color:var(--ink-3)}.mt-small{font-size:12px;margin:4px 0 0}.mt-pad{padding:12px}
.mt-err{color:#b3261e}
.mt-empty{max-width:640px}
.mt-help{max-width:820px}.mt-help li{margin:4px 0}.mt-help code{background:var(--bg-2,#f1efe9);padding:1px 5px;border-radius:4px}
.mt-note{background:#fff8e6;border:1px solid #f0dca4;border-radius:8px;padding:8px 12px}
@media (max-width:800px){
 .mt-wrap{grid-template-columns:1fr}
 .mt-wrap.has-conv .mt-side{display:none}
 .mt-wrap:not(.has-conv) .mt-main{display:none}
 .mt-side,.mt-main{max-height:none;min-height:0}
 .mt-main{height:calc(100dvh - 120px)}
 .mt-back{display:block}
 .mt-msg{max-width:92%}
 .mt-head .btn{padding:6px 10px;font-size:13px}
 .mt-wide,.mt-hint{display:none}
}
</style>';
    }
}

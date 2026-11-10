<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use PDO;

/**
 * Księgowość: księgi rachunkowe spółki w CRM, z danymi odrębnymi od CRM (tabele ext_ksg_* albo osobna baza).
 *
 * Etap 1: lata obrotowe, plan kont (wzór dla spółki z o.o.), dokumenty z dekretacją Wn/Ma, księgowanie z numerem
 * w dzienniku, storno, obroty i salda, zapisy na koncie, dziennik, własne uprawnienia, historia zmian i skrzynka
 * zdarzeń zamówień. Etap 2a: kontrahenci z kontami rozrachunkowymi, pozycje VAT w dokumentach, rejestry VAT. Kolejne etapy (faktury i KSeF, paragony, deklaracje, kadry) - README.md.
 *
 * Połączenie z CRM: tylko hak automation.event (do skrzynki) i klasa Api. Moduł nie czyta i nie pisze tabel CRM
 * (poza listą użytkowników przy nadawaniu dostępu - część wspólnego logowania).
 */
final class KsiegowoscExtension extends AbstractExtension
{
    /** Pozycje menu: id strony => tytuł. */
    private const MENU = ['pulpit' => 'Pulpit księgowości', 'dokumenty' => 'Dokumenty', 'dziennik' => 'Dziennik', 'konta' => 'Plan kont', 'obroty' => 'Obroty i salda',
        'kontrahenci' => 'Kontrahenci', 'vat' => 'Rejestry VAT'];

    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'ksiegowosc',
            name: 'Księgowość',
            version: '0.2.0',
            author: 'Veless',
            description: 'Pełne księgi rachunkowe spółki: plan kont, dokumenty z dekretacją Wn/Ma, dziennik, storno, obroty i salda. Dane w osobnych tabelach (albo osobnej bazie), własne uprawnienia. Docelowo także faktury do KSeF, paragony, deklaracje i kadry.',
            icon: '📒',
            fields: [
                ['key' => 'firma', 'label' => 'Dane firmy i zdarzenia z CRM', 'type' => 'link', 'url' => Hooks::pageUrl('ksiegowosc.firma')],
                ['key' => 'dostep', 'label' => 'Dostęp do księgowości', 'type' => 'link', 'url' => Hooks::pageUrl('ksiegowosc.dostep'),
                 'help' => 'Księgowość ma własne uprawnienia, niezależne od ról CRM.'],
            ],
        );
    }

    private function db(): Db
    {
        return Db::connect($this->pdo());
    }

    public function boot(): void
    {
        // Strona księgowości: rola CRM „viewer” wpuszcza do ext.php, a właściwy poziom sprawdza Access.
        $page = function (string $id, string $title, string $need, callable $render, bool $menu = false, bool $raw = false): void {
            Hooks::addPage($id, ['title' => $title, 'group' => 'orders', 'role' => 'viewer', 'menu' => $menu, 'raw' => $raw,
                'render' => function (PDO $pdo, array $ctx) use ($need, $render, $raw): mixed {
                    $db = $this->db();
                    $level = (new Access($db))->level($ctx['userId'] !== null ? (int) $ctx['userId'] : null, (bool) $ctx['isAdmin']);
                    if (!Access::allows($level, $need)) {
                        if ($raw) {
                            http_response_code(403);
                            return '';
                        }
                        return Ui::denied();
                    }
                    return $render(new Pages($db, $ctx, $level, $pdo));
                }]);
        };

        foreach (self::MENU as $id => $title) {
            $page($id, $title, Access::VIEW, match ($id) {
                'pulpit' => static fn(Pages $p): string => $p->dashboard(),
                'dokumenty' => static fn(Pages $p): string => $p->docs(),
                'dziennik' => static fn(Pages $p): string => $p->journal(),
                'konta' => static fn(Pages $p): string => $p->accounts(),
                'obroty' => static fn(Pages $p): string => $p->turnover(),
                'kontrahenci' => static fn(Pages $p): string => $p->contractors(),
                'vat' => static fn(Pages $p): string => $p->vatRegisters(),
            }, true);
        }
        $page('kontrahent', 'Kontrahent', Access::VIEW, static fn(Pages $p): string => $p->contractor());
        $page('kontrahent_zapisz', 'Kontrahent', Access::BOOK, static fn(Pages $p): string => $p->contractorSave());
        $page('kontrahent_status', 'Kontrahent', Access::BOOK, static fn(Pages $p) => $p->contractorStatus());
        $page('vat_csv', 'Rejestr VAT', Access::VIEW, static fn(Pages $p) => $p->vatCsv(), false, true);
        $page('dokument', 'Dokument', Access::VIEW, static fn(Pages $p): string => $p->doc());
        $page('dokument_nowy', 'Nowy dokument', Access::BOOK, static fn(Pages $p): string => $p->docForm());
        $page('dokument_edytuj', 'Popraw dokument', Access::BOOK, static fn(Pages $p): string => $p->docForm());
        $page('dokument_zapisz', 'Dokument', Access::BOOK, static fn(Pages $p): string => $p->docSave());
        $page('dokument_ksieguj', 'Dokument', Access::BOOK, static fn(Pages $p) => $p->docPost());
        $page('dokument_usun', 'Dokument', Access::BOOK, static fn(Pages $p) => $p->docDelete());
        $page('dokument_storno', 'Dokument', Access::BOOK, static fn(Pages $p) => $p->docReverse());
        $page('konto', 'Konto', Access::VIEW, static fn(Pages $p): string => $p->account());
        $page('konto_zapisz', 'Konto', Access::MANAGE, static fn(Pages $p): string => $p->accountSave());
        $page('konto_status', 'Konto', Access::MANAGE, static fn(Pages $p) => $p->accountStatus());
        $page('konto_usun', 'Konto', Access::MANAGE, static fn(Pages $p) => $p->accountDelete());
        $page('obroty_csv', 'Obroty i salda', Access::VIEW, static fn(Pages $p) => $p->turnoverCsv(), false, true);
        $page('lata', 'Lata obrotowe', Access::VIEW, static fn(Pages $p): string => $p->years());
        $page('rok_zapisz', 'Lata obrotowe', Access::MANAGE, static fn(Pages $p) => $p->yearSave());
        $page('rok_blokada', 'Lata obrotowe', Access::MANAGE, static fn(Pages $p) => $p->yearLock());
        $page('firma', 'Dane firmy', Access::MANAGE, static fn(Pages $p): string => $p->companyPage());
        $page('firma_zapisz', 'Dane firmy', Access::MANAGE, static fn(Pages $p) => $p->companySave());
        $page('dostep', 'Dostęp do księgowości', Access::MANAGE, static fn(Pages $p): string => $p->accessPage());
        $page('dostep_zapisz', 'Dostęp do księgowości', Access::MANAGE, static fn(Pages $p) => $p->accessSave());
        $page('zmiany', 'Historia zmian księgowości', Access::MANAGE, static fn(Pages $p): string => $p->history());
        $page('skrzynka', 'Zdarzenia z CRM', Access::MANAGE, static fn(Pages $p): string => $p->inbox());

        // Własna grupa „Księgowość” w menu, widoczna tylko dla osób z dostępem.
        Hooks::addFilter('admin.menu', function (array $groups, bool $canEdit = false, bool $isAdmin = false): array {
            $mine = [];
            foreach ($groups as $gk => $g) {
                foreach ($g['items'] ?? [] as $i => $item) {
                    if (str_contains((string) ($item['href'] ?? ''), 'page=ksiegowosc.')) {
                        $mine[] = $item;
                        unset($groups[$gk]['items'][$i]);
                    }
                }
                if (isset($groups[$gk]['items'])) {
                    $groups[$gk]['items'] = array_values($groups[$gk]['items']);
                    if ($groups[$gk]['items'] === []) {
                        unset($groups[$gk]);
                    }
                }
            }
            if ($mine === [] || !$this->menuVisible($isAdmin)) {
                return $groups;
            }
            $out = [];
            $placed = false;
            foreach ($groups as $gk => $g) {
                if (!$placed && in_array($gk, ['config', 'system'], true)) {
                    $out['ksiegowosc'] = ['label' => 'Księgowość', 'items' => $mine];
                    $placed = true;
                }
                $out[$gk] = $g;
            }
            return $placed ? $out : $out + ['ksiegowosc' => ['label' => 'Księgowość', 'items' => $mine]];
        });

        // Jedyne wejście danych z CRM: kopia zamówienia przy zdarzeniu, gdy skrzynka jest włączona (zakładka Firma).
        Hooks::addAction('automation.event', function (string $event, int $orderId, array $payload): void {
            if (!isset(Inbox::EVENTS[$event])) {
                return;
            }
            $db = $this->db();
            if ($db->meta('inbox', '0') === '1') {
                (new Inbox($db))->record($event, $orderId, $payload);
            }
        });
    }

    private function menuVisible(bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        try {
            $uid = function_exists('currentUserId') ? currentUserId() : null;
            return (new Access($this->db()))->level($uid, false) !== Access::NONE;
        } catch (\Throwable) {
            return false;
        }
    }
}

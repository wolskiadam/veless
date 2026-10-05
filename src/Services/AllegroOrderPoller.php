<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationRepository;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroClient;
use PasePlugin\Allegro\AllegroPlugin;

/**
 * Wykrywanie nowych zamówień Allegro przez Order Events API (GET /order/events).
 *
 * To rekomendowany przez Allegro sposób śledzenia zamówień: kursor po id zdarzenia,
 * bez rejestrowania publicznego webhooka i weryfikacji podpisów. Reagujemy na
 * READY_FOR_PROCESSING - moment, od którego Allegro zaleca rozpoczęcie realizacji
 * (płatność potwierdzona).
 *
 * Logika mieszka TUTAJ, a nie w skrypcie CLI, bo wołają ją trzy miejsca:
 * Scheduler (przy każdym przebiegu workera - czyli przy jednym wpisie w cronie),
 * cli/poll_allegro_orders.php (osobny wpis, zostawiony dla zgodności) oraz strona
 * diagnostyczna w panelu. Wszystkie ścieżki są bezpieczne przy jednoczesnym
 * działaniu: kursor zdarzeń jest wspólny, a klucz dedup w kolejce nie dopuści
 * do podwójnego importu.
 */
final class AllegroOrderPoller
{
    /** Kursor: id ostatniego przetworzonego zdarzenia (tabela settings). */
    public const LAST_EVENT_SETTING = 'ALLEGRO_LAST_ORDER_EVENT_ID';

    /** Typy zdarzeń, które zamieniamy na zamówienie w CRM. */
    private const RELEVANT_EVENT_TYPES = ['READY_FOR_PROCESSING'];

    /**
     * Kody, którymi Allegro odpowiada na kursor wskazujący zdarzenie spoza okna
     * retencji. Bez reakcji na nie pobieranie potrafi stanąć NA ZAWSZE: każde
     * kolejne zapytanie dostaje ten sam błąd, kursor nigdy się nie przesuwa,
     * a w panelu nie widać nic poza ciszą.
     */
    private const CURSOR_ERROR_STATUSES = [400, 404, 422];

    /** @param array<string,mixed> $allegroConfig sekcja 'allegro' z config/config.php */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly Queue $queue,
        private readonly array $allegroConfig
    ) {}

    /**
     * Czy konto Allegro jest w ogóle połączone (jest token w tabeli integrations).
     *
     * Bez tego Scheduler waliłby w API co kilka minut na każdym systemie, gdzie
     * Allegro nigdy nie zostało podłączone, i zaśmiecał log ostrzeżeniami.
     */
    public function isConnected(): bool
    {
        return ($this->integration()['access_token'] ?? '') !== '';
    }

    /** Wiersz integracji Allegro (tokeny, expires_at) albo pusta tablica. */
    public function integration(): array
    {
        if ($this->allegroConfig === []) {
            return [];
        }
        try {
            $row = (new IntegrationRepository($this->pdo))->find('allegro');
        } catch (\Throwable $e) {
            return []; // np. tabela jeszcze nie istnieje (świeża instalacja)
        }
        if ($row === null) {
            return [];
        }
        $row['access_token']  = trim((string) ($row['access_token'] ?? ''));
        $row['refresh_token'] = trim((string) ($row['refresh_token'] ?? ''));
        return $row;
    }

    /** Aktualny kursor zdarzeń ('' = jeszcze nic nie pobraliśmy). */
    public function cursor(): string
    {
        return (string) ($this->settings->get(self::LAST_EVENT_SETTING, '') ?? '');
    }

    /** Kasuje kursor - następne pobranie ruszy od początku okna retencji Allegro. */
    public function resetCursor(): void
    {
        $this->settings->setMany([self::LAST_EVENT_SETTING => '']);
    }

    /**
     * Podgląd BEZ skutków ubocznych: niczego nie kolejkuje i nie rusza kursora.
     *
     * $fromStart pomija kursor i pyta o CAŁE dostępne okno zdarzeń. Tak właśnie
     * woła to strona diagnostyczna: pytanie od kursora zwracałoby zero za każdym
     * razem, gdy pobieranie przed chwilą się udało, a wtedy podgląd nie odpowiada
     * na pytanie, które użytkownik naprawdę zadaje - „co Allegro w ogóle ma?".
     *
     * @return array{ok:bool,events:array<int,array<string,mixed>>,message:string,status:int}
     */
    public function peek(int $limit = 100, bool $fromStart = false): array
    {
        if (!$this->isConnected()) {
            return ['ok' => false, 'events' => [], 'message' => 'Konto Allegro nie jest połączone.', 'status' => 0];
        }
        $cursor = $fromStart ? '' : $this->cursor();
        return $this->client()->orderEvents($cursor !== '' ? $cursor : null, $limit);
    }

    /**
     * Pobiera zdarzenia od ostatniego kursora i kolejkuje nowe zamówienia.
     * @return int ile zamówień trafiło do kolejki
     */
    public function poll(): int
    {
        return $this->pollDetailed()['queued'];
    }

    /**
     * To samo co poll(), ale ze szczegółami dla panelu.
     *
     * `relevant` to liczba zdarzeń typu READY_FOR_PROCESSING, a `queued` - ile z nich
     * faktycznie trafiło do kolejki. Różnica między nimi to zamówienia, które system
     * już zna (zadziałał dedup). Bez rozbicia na te dwie liczby „0 nowych zamówień"
     * znaczyło jednocześnie „nie było nic do wzięcia" i „wszystko już mamy".
     *
     * @return array{queued:int,events:int,relevant:int,ok:bool,message:string,status:int,cursorReset:bool}
     */
    public function pollDetailed(): array
    {
        $blank = ['queued' => 0, 'events' => 0, 'relevant' => 0, 'ok' => false, 'message' => '', 'status' => 0, 'cursorReset' => false];

        if (!$this->isConnected()) {
            return array_merge($blank, ['message' => 'Konto Allegro nie jest połączone.']);
        }

        $client = $this->client();
        $cursor = $this->cursor();
        $result = $client->orderEvents($cursor !== '' ? $cursor : null);
        $cursorReset = false;

        // Zakleszczony kursor: Allegro nie zna już zdarzenia, od którego każemy mu
        // zacząć. Kasujemy kursor i pobieramy od początku dostępnego okna - dedup
        // w kolejce sprawi, że już zaimportowane zamówienia nie zdublują się.
        if (!$result['ok'] && $cursor !== '' && in_array($result['status'], self::CURSOR_ERROR_STATUSES, true)) {
            Logger::warn("Allegro: kursor zdarzeń nieaktualny (kod {$result['status']}) - zaczynam od początku okna");
            $this->resetCursor();
            $cursorReset = true;
            $result = $client->orderEvents(null);
        }

        if (!$result['ok']) {
            Logger::warn('Allegro: nie udało się pobrać zdarzeń zamówień', ['message' => $result['message']]);
            return array_merge($blank, [
                'message'     => $result['message'],
                'status'      => $result['status'],
                'cursorReset' => $cursorReset,
            ]);
        }

        $events   = $result['events'];
        $queued   = 0;
        $revived  = 0;
        $relevant = 0;

        foreach ($events as $event) {
            $eventId = (string) ($event['id'] ?? '');
            $type    = (string) ($event['type'] ?? '');
            $orderId = (string) ($event['order']['checkoutForm']['id'] ?? '');

            if (in_array($type, self::RELEVANT_EVENT_TYPES, true) && $orderId !== '') {
                $relevant++;
                $order = $client->checkoutForm($orderId);

                // Nie przesuwaj kursora poza zamówienie, którego NIE udało się pobrać.
                // Wcześniej kursor szedł do przodu po każdym zdarzeniu, także po takim,
                // przy którym pobranie szczegółów padło (chwilowe 401, timeout) - takie
                // zamówienie znikało BEZPOWROTNIE: strumień zdarzeń idzie tylko naprzód,
                // więc nikt już nigdy do niego nie wracał. Przerywamy pętlę, a następny
                // przebieg zacznie dokładnie od tego zdarzenia.
                if ($order === null) {
                    Logger::warn("Allegro: nie udało się pobrać zamówienia {$orderId} - kursor zostaje, spróbuję ponownie");
                    break;
                }

                $dedupKey = "allegro.order.new:{$orderId}";
                if ($this->queue->enqueue(
                    jobType: 'allegro.order.new',
                    payload: $order,
                    dedupKey: $dedupKey
                )) {
                    $queued++;
                } elseif ($this->queue->reviveFailed($dedupKey, $order)) {
                    // Zamówienie było już w kolejce, ale jego zadanie padło - puszczamy je jeszcze raz.
                    $queued++;
                    $revived++;
                }
            }

            // Kursor przesuwamy DOPIERO po pełnej obsłudze zdarzenia - także przy typach,
            // których nie przetwarzamy (inaczej odpytywalibyśmy o nie w kółko).
            if ($eventId !== '') {
                $this->settings->setMany([self::LAST_EVENT_SETTING => $eventId]);
            }
        }

        if ($events !== []) {
            Logger::info('Allegro: zdarzeń ' . count($events) . ", z tego do realizacji: {$relevant}, nowych w kolejce: {$queued}");
        }

        return [
            'queued'      => $queued,
            'revived'     => $revived,
            'events'      => count($events),
            'relevant'    => $relevant,
            'ok'          => true,
            'message'     => '',
            'status'      => $result['status'],
            'cursorReset' => $cursorReset,
        ];
    }

    private function client(): AllegroClient
    {
        return AllegroPlugin::makeClient($this->pdo, $this->allegroConfig);
    }
}

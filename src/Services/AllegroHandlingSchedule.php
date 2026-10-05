<?php
declare(strict_types=1);

namespace Pase\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroClient;

/**
 * Harmonogram czasu wysyłki ofert Allegro (Marketplace → Harmonogram czasu wysyłki).
 *
 * Dla każdego dnia tygodnia ustawiasz czas wysyłki (albo „bez zmian"). Worker (cron co minutę)
 * woła tick(): od ustalonej godziny danego dnia system przestawia czas wysyłki wszystkich
 * aktywnych ofert (albo tylko ofert z wybranym cennikiem) na wartość z harmonogramu.
 * Zmienia tylko oferty, które mają inny czas; przy dużej liczbie ofert robi to partiami
 * (MAX_PER_TICK na przebieg) i kończy w kolejnych minutach.
 */
final class AllegroHandlingSchedule
{
    public const CONFIG_KEY = 'ALLEGRO_HANDLING_SCHEDULE';
    public const STATE_KEY  = 'ALLEGRO_HANDLING_SCHEDULE_STATE';
    private const MAX_PER_TICK = 100;

    public const DAYS = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'];

    public function __construct(private readonly PDO $pdo, private readonly AllegroClient $client) {}

    /** @return array{enabled:bool,time:string,days:array<int,string>,times:array<int,string>,rate:string} */
    public static function config(SettingsRepository $s): array
    {
        $c = json_decode((string) ($s->get(self::CONFIG_KEY, '') ?? ''), true);
        $c = is_array($c) ? $c : [];
        $days = [];
        foreach (self::DAYS as $n => $_) {
            $v = (string) ($c['days'][$n] ?? $c['days'][(string) $n] ?? '');
            $days[$n] = isset(AllegroOfferOperations::HANDLING_TIMES[$v]) ? $v : '';
        }
        $valid = static fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $t) === 1;
        $time = $valid($c['time'] ?? '') ? (string) $c['time'] : '00:05';
        // Godzina zmiany osobno dla każdego dnia (starszy zapis: jedna wspólna godzina).
        $times = [];
        foreach (self::DAYS as $n => $_) {
            $t = $c['times'][$n] ?? $c['times'][(string) $n] ?? '';
            $times[$n] = $valid($t) ? (string) $t : $time;
        }
        return ['enabled' => !empty($c['enabled']), 'time' => $time, 'days' => $days, 'times' => $times, 'rate' => (string) ($c['rate'] ?? '')];
    }

    /** @return array<string,mixed> stan ostatniego przebiegu (do panelu) */
    public static function state(SettingsRepository $s): array
    {
        $st = json_decode((string) ($s->get(self::STATE_KEY, '') ?? ''), true);
        return is_array($st) ? $st : [];
    }

    /**
     * Który czas obowiązuje teraz: dzień liczymy od godziny zmiany (przed nią obowiązuje dzień poprzedni).
     * @return array{target:string,date:string,day:int}
     */
    public static function current(array $cfg, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
        $today = (int) $now->format('N');
        // Szukamy wstecz ostatniej zmiany, której godzina już minęła (dziś, wczoraj, ... tydzień temu).
        // „Bez zmian" w danym dniu = dalej obowiązuje ostatnia wcześniejsza zmiana.
        for ($i = 0; $i <= 7; $i++) {
            $date = $now->modify("-{$i} days");
            $d = (int) $date->format('N');
            if ($cfg['days'][$d] === '') {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $cfg['times'][$d] ?? $cfg['time']));
            $at = $date->setTime($h, $m);
            if ($at <= $now) {
                return ['target' => $cfg['days'][$d], 'date' => $at->format('Y-m-d H:i'), 'day' => $today];
            }
        }
        return ['target' => '', 'date' => $now->format('Y-m-d'), 'day' => $today];
    }

    /** Najbliższa zaplanowana zmiana (do panelu). @return array{target:string,at:DateTimeImmutable}|null */
    public static function next(array $cfg, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
        $cur = self::current($cfg, $now)['target'];
        for ($i = 0; $i <= 7; $i++) {
            $date = $now->modify("+{$i} days");
            $d = (int) $date->format('N');
            if ($cfg['days'][$d] === '') {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $cfg['times'][$d] ?? $cfg['time']));
            $at = $date->setTime($h, $m);
            if ($at > $now && !AllegroOfferOperations::sameHandling($cfg['days'][$d], $cur)) {
                return ['target' => $cfg['days'][$d], 'at' => $at];
            }
        }
        return null;
    }

    /**
     * Przebieg workera. $force = uruchom teraz (przycisk w panelu), także gdy dziś już zrobione.
     * @return array{changed:int,left:int,message:string}
     */
    public function tick(bool $force = false): array
    {
        $s = new SettingsRepository($this->pdo);
        $cfg = self::config($s);
        if (!$cfg['enabled'] && !$force) {
            return ['changed' => 0, 'left' => 0, 'message' => 'Harmonogram wyłączony.'];
        }
        $cur = self::current($cfg);
        if ($cur['target'] === '') {
            return ['changed' => 0, 'left' => 0, 'message' => 'Brak ustawionego czasu w harmonogramie.'];
        }
        $key = $cur['date'] . ':' . $cur['target'] . ':' . $cfg['rate'];
        $state = self::state($s);
        if (!$force && ($state['done'] ?? '') === $key) {
            return ['changed' => 0, 'left' => 0, 'message' => 'Już ustawione.'];
        }
        // Prosta blokada - dwa przebiegi naraz nie zmieniają tych samych ofert.
        if (!$force && ($state['lock'] ?? 0) > time() - 300) {
            return ['changed' => 0, 'left' => 0, 'message' => 'Trwa poprzedni przebieg.'];
        }
        $state['lock'] = time();
        $s->setMany([self::STATE_KEY => json_encode($state)]);

        $ops = new AllegroOfferOperations($this->pdo, $this->client);
        $list = $ops->allOffers('ACTIVE');
        if (!$list['ok']) {
            $state['lock'] = 0;
            $state['last_error'] = $list['message'];
            $s->setMany([self::STATE_KEY => json_encode($state)]);
            return ['changed' => 0, 'left' => 0, 'message' => 'Allegro: ' . $list['message']];
        }
        $offers = array_filter($list['offers'], static fn($o) => $cfg['rate'] === ''
            || (string) ($o['delivery']['shippingRates']['id'] ?? '') === $cfg['rate']);
        $details = $ops->details(array_map(static fn($o) => (string) $o['id'], $offers));

        $todo = [];
        foreach ($offers as $o) {
            $id = (string) $o['id'];
            // Przy „Uruchom teraz" ustawiamy wszystkim (ktoś mógł zmienić czas ręcznie na Allegro).
            if ($force || !AllegroOfferOperations::sameHandling($details[$id]['handling_time'] ?? null, $cur['target'])) {
                $todo[] = $id;
            }
        }
        $changed = 0;
        $errors = [];
        foreach (array_slice($todo, 0, self::MAX_PER_TICK) as $id) {
            $r = $this->client->patchOffer($id, ['delivery' => ['handlingTime' => $cur['target']]]);
            if ($r['ok']) {
                $ops->storeDetails($id, ['handling_time' => $cur['target']]);
                $changed++;
            } else {
                $errors[] = "{$id}: {$r['message']}";
                // Oferta, której nie da się zmienić, nie może blokować harmonogramu w nieskończoność.
                $ops->storeDetails($id, ['handling_time' => $cur['target']]);
            }
        }
        $left = max(0, count($todo) - self::MAX_PER_TICK);

        $state = self::state($s);
        $state['lock'] = 0;
        $state['last_run'] = time();
        $state['last_target'] = $cur['target'];
        $state['last_changed'] = ($force || ($state['progress_key'] ?? '') !== $key ? 0 : (int) ($state['last_changed'] ?? 0)) + $changed;
        $state['progress_key'] = $key;
        $state['last_errors'] = array_slice($errors, 0, 10);
        unset($state['last_error']);
        if ($left === 0) {
            $state['done'] = $key;
        }
        $s->setMany([self::STATE_KEY => json_encode($state, JSON_UNESCAPED_UNICODE)]);

        $label = AllegroOfferOperations::handlingLabel($cur['target']);
        if ($changed > 0 || $errors) {
            Logger::info("Harmonogram czasu wysyłki: ustawiono „{$label}” w {$changed} ofertach" . ($left ? ", zostało {$left}" : '') . ($errors ? ', błędy: ' . count($errors) : ''));
        }
        return ['changed' => $changed, 'left' => $left,
                'message' => "Czas wysyłki „{$label}”: zmieniono {$changed} ofert" . ($left ? ", pozostałe {$left} w kolejnych minutach" : '') . ($errors ? '. Błędy: ' . implode(' · ', array_slice($errors, 0, 5)) : '.')];
    }
}

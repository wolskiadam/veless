<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Terminy z zamówienia Allegro (checkout-form: delivery.time), te same co w panelu sprzedawcy:
 *  - „Wyślij do” - delivery.time.dispatch.to: do kiedy wysłać, żeby paczka dotarła w przewidywanym czasie,
 *  - „Przewidywany czas dostawy” - delivery.time.guaranteed (gwarantowana dostawa) albo delivery.time.from/to
 *    (czas, który kupujący widział w ofercie).
 * Allegro podaje czasy w UTC - pokazujemy je w czasie polskim.
 */
final class AllegroDeliveryTime
{
    private const DAYS = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
    private const MONTHS = [1 => 'sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

    /** Zamówienie już wysłane / anulowane po stronie Allegro - termin wysyłki przestaje być ważny. */
    private const DONE_FULFILLMENT = ['SENT', 'PICKED_UP', 'CANCELLED', 'RETURNED'];

    /**
     * @return array{dispatch_from: ?\DateTimeImmutable, dispatch_to: ?\DateTimeImmutable,
     *               delivery_from: ?\DateTimeImmutable, delivery_to: ?\DateTimeImmutable, guaranteed: bool}|null
     *         null - zamówienie bez terminów (nie z Allegro albo Allegro ich nie podało)
     */
    public static function fromPayload(array $payload): ?array
    {
        $time = $payload['delivery']['time'] ?? null;
        if (!is_array($time)) {
            return null;
        }
        $guaranteed = is_array($time['guaranteed'] ?? null) && (!empty($time['guaranteed']['from']) || !empty($time['guaranteed']['to']));
        $window = $guaranteed ? $time['guaranteed'] : $time;
        $out = [
            'dispatch_from' => self::parse($time['dispatch']['from'] ?? null),
            'dispatch_to'   => self::parse($time['dispatch']['to'] ?? null),
            'delivery_from' => self::parse($window['from'] ?? null),
            'delivery_to'   => self::parse($window['to'] ?? null),
            'guaranteed'    => $guaranteed,
        ];
        return $out['dispatch_to'] === null && $out['delivery_from'] === null && $out['delivery_to'] === null ? null : $out;
    }

    /** Czy termin wysyłki jeszcze obowiązuje: brak aktywnej przesyłki i Allegro nie oznaczyło zamówienia jako wysłane. */
    public static function awaitingDispatch(array $payload, array $shipments = []): bool
    {
        if (in_array(strtoupper((string) ($payload['fulfillment']['status'] ?? '')), self::DONE_FULFILLMENT, true)
            || strtoupper((string) ($payload['status'] ?? '')) === 'CANCELLED') {
            return false;
        }
        foreach ($shipments as $sh) {
            if (!in_array((string) ($sh['status'] ?? ''), ['cancelled', 'canceled', 'failed'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pilność terminu wysyłki: overdue (minął), today (mija dziś), soon (jutro), ok (później).
     */
    public static function urgency(\DateTimeImmutable $deadline, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(self::tz());
        $deadline = $deadline->setTimezone(self::tz());
        if ($deadline < $now) {
            return 'overdue';
        }
        $days = (int) $now->setTime(0, 0)->diff($deadline->setTime(0, 0))->format('%a');
        return $days === 0 ? 'today' : ($days === 1 ? 'soon' : 'ok');
    }

    /** „pon., 26 paź 2026, 23:59” - jak w panelu Allegro. */
    public static function formatDateTime(\DateTimeImmutable $d): string
    {
        return self::formatDate($d) . ', ' . $d->setTimezone(self::tz())->format('H:i');
    }

    /** „wt., 27 paź 2026”. */
    public static function formatDate(\DateTimeImmutable $d): string
    {
        $d = $d->setTimezone(self::tz());
        return self::DAYS[(int) $d->format('w')] . ', ' . (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y');
    }

    /** Przewidywana dostawa: jeden dzień albo zakres „wt., 27 paź 2026 – śr., 28 paź 2026”. */
    public static function formatDelivery(array $t): string
    {
        $from = $t['delivery_from'] ?? null;
        $to = $t['delivery_to'] ?? null;
        if ($from === null || $to === null) {
            return ($from ?? $to) !== null ? self::formatDate($from ?? $to) : '';
        }
        $a = self::formatDate($from);
        $b = self::formatDate($to);
        return $a === $b ? $a : $a . ' – ' . $b;
    }

    /** Krótko na listę zamówień: „26.10 23:59”. */
    public static function formatShort(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(self::tz())->format('d.m H:i');
    }

    private static function parse(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function tz(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Warsaw');
    }
}

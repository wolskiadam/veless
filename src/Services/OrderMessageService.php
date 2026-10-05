<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\OrderMessageRepository;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;

/**
 * Logika wątku wiadomości zamówienia: dodanie wiadomości + powiadomienie e-mail.
 * Używane przez panel (staff) i publiczną stronę klienta (client).
 */
final class OrderMessageService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OrderMessageRepository $messages,
        private readonly SettingsRepository $settings
    ) {}

    /**
     * Wiadomość od KLIENTA (publiczna strona). Powiadamia administratora e-mailem.
     */
    public function fromClient(array $order, string $body, ?string $authorName, ?array $file): int
    {
        [$path, $name] = $this->maybeStore($file);
        $id = $this->messages->add(
            (int) $order['woo_order_id'], 'client', $body,
            $authorName ?: ($order['customer_name'] ?? null), $path, $name
        );
        $this->notifyAdmin($order, $body);
        return $id;
    }

    /**
     * Wiadomość od OBSŁUGI (panel). Wysyła e-mail do klienta z treścią + linkiem do wątku.
     * $invoice (PDF faktury z wFirma, InvoicePdf) idzie jako załącznik e-maila, a w wątku - gdy
     * operator nie dołączył własnego pliku - także jako załącznik wiadomości (klient pobierze go ze strony zamówienia).
     * $emailClient=false: treść poszła już innym kanałem (Centrum wiadomości Allegro), tylko zapis w wątku.
     * @param array{name:string,mime:string,content:string}|null $invoice
     */
    public function fromStaff(array $order, string $body, string $staffName, ?array $file, ?array $invoice = null, bool $emailClient = true): int
    {
        [$path, $name] = $this->maybeStore($file);
        if ($path === null && $invoice !== null) {
            [$path, $name] = Attachments::storeContent($invoice['content'], $invoice['name']);
        }
        $id = $this->messages->add(
            (int) $order['woo_order_id'], 'staff', $body, $staffName, $path, $name
        );
        if ($emailClient) {
            $this->notifyClient($order, $body, $invoice !== null ? [$invoice] : []);
        }
        return $id;
    }

    /** @return array{0:?string,1:?string} [path, name] */
    private function maybeStore(?array $file): array
    {
        if ($file === null) {
            return [null, null];
        }
        $stored = Attachments::store($file); // może rzucić RuntimeException
        return $stored ?? [null, null];
    }

    /** @param array<int,array{name:string,mime:string,content:string}> $attachments */
    private function notifyClient(array $order, string $body, array $attachments = []): void
    {
        $to = (string) ($order['customer_email'] ?? '');
        if ($to === '') {
            return;
        }
        // E-mail do klienta w JEGO języku (z kraju zamówienia), nie w języku panelu.
        $prevLocale = \Pase\Support\I18n::locale();
        \Pase\Support\I18n::setLocale($this->clientLocale($order));

        $orderLbl = $this->orderLabel($order);
        $link = $this->clientLink($order);
        $html = '<p>' . \Pase\Support\I18n::t('email.client.intro', ['order' => htmlspecialchars($orderLbl)]) . '</p>'
            . '<blockquote style="border-left:3px solid #ddd;padding-left:12px;color:#444">'
            . nl2br(htmlspecialchars($body)) . '</blockquote>'
            . ($link ? '<p><a href="' . htmlspecialchars($link) . '">' . \Pase\Support\I18n::t('email.client.open') . '</a></p>' : '');
        $subject = \Pase\Support\I18n::t('email.client.subject', ['order' => $orderLbl]);

        \Pase\Support\I18n::setLocale($prevLocale); // przywróć język panelu
        $this->send($to, $subject, $html, $order, 'order_message', $attachments);
    }

    /** Język klienta z kraju zamówienia (billing.country); fallback na język domyślny. */
    private function clientLocale(array $order): string
    {
        $payload = is_array($order['payload'] ?? null)
            ? $order['payload']
            : (json_decode((string) ($order['payload'] ?? '{}'), true) ?: []);
        $country = strtolower((string) ($payload['billing']['country'] ?? ''));
        $map = ['gb' => 'en', 'us' => 'en', 'ie' => 'en', 'de' => 'de', 'at' => 'de', 'ua' => 'uk'];
        $code = $map[$country] ?? \Pase\Support\I18n::DEFAULT;
        return \Pase\Support\I18n::isAvailable($code) ? $code : \Pase\Support\I18n::DEFAULT;
    }

    private function notifyAdmin(array $order, string $body): void
    {
        $to = (string) ($this->settings->get('MAIL_ADMIN_NOTIFY', '')
            ?: ($this->mailConfig($order)['from_email'] ?? '') ?: '');
        if ($to === '') {
            return;
        }
        $html = '<p>Klient napisał wiadomość do zamówienia <strong>'
            . htmlspecialchars($this->orderLabel($order)) . '</strong>:</p>'
            . '<blockquote style="border-left:3px solid #ddd;padding-left:12px;color:#444">'
            . nl2br(htmlspecialchars($body)) . '</blockquote>';
        $this->send($to, 'Klient: nowa wiadomość — zamówienie ' . $this->orderLabel($order), $html, $order, 'client_message');
    }

    private function send(string $to, string $subject, string $html, array $order, string $type, array $attachments = []): void
    {
        try {
            $mailer = new Mailer($this->mailConfig($order));
            if (!$mailer->isConfigured()) {
                return;
            }
            [$ok, $msg] = $mailer->send($to, $subject, $html, ['type' => $type, 'order_id' => $order['woo_order_id'] ?? null], $attachments);
            if (!$ok) {
                Logger::warn("Wiadomość: powiadomienie e-mail do {$to} nieudane: {$msg}");
            }
        } catch (\Throwable $e) {
            Logger::warn('Wiadomość: błąd powiadomienia e-mail: ' . $e->getMessage());
        }
    }

    /** Publiczny link do strony zamówienia klienta (token). Null gdy brak bazowego URL. */
    public function clientLink(array $order): ?string
    {
        // Adres strony zamówienia w domenie sklepu, z którego jest zamówienie (albo domyślny w CRM).
        return (new ClientLinks($this->pdo))->forOrder($order);
    }

    private function orderLabel(array $order): string
    {
        if (!empty($order['pase_number'])) {
            return '#' . $order['pase_number'];
        }
        return (string) ($order['order_number'] ?? $order['woo_order_id'] ?? '');
    }

    /** Konto nadawcy przypisane do sklepu, z którego jest zamówienie (Konfiguracja → E-mail). */
    private function mailConfig(array $order): array
    {
        return (new MailAccounts($this->pdo))->mailerConfig(isset($order['woo_order_id']) ? (int) $order['woo_order_id'] : null);
    }
}

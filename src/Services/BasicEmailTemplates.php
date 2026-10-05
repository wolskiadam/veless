<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Podstawowe szablony e-mail (przeniesione z BaseLinkera): realizacja, gotowe do wysłania, faktura, prośba o ocenę.
 * Wspólne dla wszystkich sklepów - logo ({{shop_logo}}), nazwa sklepu ({{shop_name}}), konto nadawcy i link do opinii ({{review_link}})
 * biorą się z konta e-mail sklepu, z którego jest zamówienie. Dodawane raz (flaga w settings), więc usunięty
 * albo zmieniony szablon nie wraca.
 */
final class BasicEmailTemplates
{
    public const FLAG = 'EMAIL_TPL_BASIC_SEEDED';

    private const LOGO = "<p>{{shop_logo}}</p>\n";

    private const FOOTER = "<p><em>Wiadomość generowana automatycznie, prosimy nie odpowiadać.<br>\n"
        . "Preferowany kontakt za pośrednictwem formularza dostępnego na stronie zamówienia:<br>\n"
        . "{{order_link}}</em></p>\n"
        . "<p>Pozdrawiamy<br>\n{{shop_name}}</p>";

    /** @return array<int,array{key:string,name:string,subject:string,body:string}> */
    public static function templates(): array
    {
        return [
            [
                'key' => 'basic_processing', 'name' => 'Realizujemy Twoje zamówienie', 'subject' => 'Realizujemy Twoje zamówienie',
                'body' => self::LOGO . "<h2>Zamówienie jest w trakcie realizacji.</h2>\n"
                    . "<p>Zamówienie dotyczy produktów:</p>\n<p>{{product_list}}</p>\n"
                    . "<p>Wszystkie informacje związane z zamówieniem, w tym postęp w jego realizacji, można sprawdzić pod adresem:<br>\n"
                    . "{{order_link}}</p>\n" . self::FOOTER,
            ],
            [
                'key' => 'basic_ready', 'name' => 'Twoje zamówienie jest gotowe do wysłania', 'subject' => 'Twoje zamówienie jest gotowe do wysłania',
                'body' => self::LOGO . "<h2>Spakowaliśmy Twoje zamówienie.</h2>\n"
                    . "<p>Dotyczy produktów:</p>\n<p>{{product_list}}</p>\n"
                    . "<p>Już niedługo przekażemy Twoją paczkę do wysyłki.<br>\n"
                    . "O wszystkich etapach dostawy będziesz informowany przez wybranego przez siebie przewoźnika.</p>\n"
                    . "<p>Numer przesyłki: {{waybill}}<br>\nŚledzenie przesyłki: {{tracking_link}}</p>\n" . self::FOOTER,
            ],
            [
                'key' => 'basic_invoice', 'name' => 'Faktura', 'subject' => 'Faktura została wystawiona',
                'body' => self::LOGO . "<h2>Faktura do Twojego zamówienia została wystawiona.</h2>\n" . self::FOOTER,
            ],
            [
                'key' => 'basic_review', 'name' => 'Oceń nas', 'subject' => 'Zamówienie dostarczone',
                'body' => self::LOGO . "<h2>Czy wszystko w porządku?</h2>\n"
                    . "<p>Wygląda na to, że Twoja przesyłka została doręczona. Mamy nadzieję, że spełniliśmy Twoje oczekiwania. "
                    . "Jeśli tak, będziemy wdzięczni, jeśli ocenisz nas na 5 gwiazdek na Allegro, jak również na Google. "
                    . "Możesz to zrobić tutaj: {{review_link}}<br>\nTwoja opinia bardzo nam pomoże w dalszym rozwoju.</p>\n" . self::FOOTER,
            ],
        ];
    }

    /** Dodaje brakujące podstawowe szablony, jeden raz na instalację. Zwraca liczbę dodanych. */
    public static function seedOnce(PDO $pdo): int
    {
        try {
            $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $st->execute([self::FLAG]);
            if ($st->fetchColumn() !== false) {
                return 0;
            }
            $exists = $pdo->prepare('SELECT COUNT(*) FROM email_templates WHERE tpl_key = ? OR name = ?');
            $ins = $pdo->prepare('INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES (?, ?, ?, ?, 1)');
            $added = 0;
            foreach (self::templates() as $t) {
                $exists->execute([$t['key'], $t['name']]);
                if ((int) $exists->fetchColumn() === 0) {
                    $ins->execute([$t['key'], $t['name'], $t['subject'], $t['body']]);
                    ++$added;
                }
            }
            try {
                // Faktura jedzie jako załącznik PDF z wFirma (kolumna z migracji; starsza baza - bez tego).
                $pdo->exec("UPDATE email_templates SET attach_invoice = 1 WHERE tpl_key = 'basic_invoice'");
            } catch (\Throwable) {
            }
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([self::FLAG, date('Y-m-d H:i:s')]);
            return $added;
        } catch (\Throwable $e) {
            return 0; // brak tabel (świeża instalacja w trakcie migracji) - spróbuje przy następnym uruchomieniu
        }
    }
}

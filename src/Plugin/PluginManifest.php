<?php
declare(strict_types=1);

namespace Pase\Plugin;

/**
 * Manifest wtyczki integracji - metadane + deklaracja pól konfiguracji.
 *
 * Pola konfiguracji ($fields) sterują formularzem w panelu (integration_edit.php):
 * każda wtyczka SAMA deklaruje, jakich danych potrzebuje (klucze API, URL, itp.).
 * Dzięki temu rdzeń nie zna pól konkretnej integracji - to klucz do modelu „wtyczkowego".
 *
 * Pole konfiguracji: [
 *   'key'      => 'consumer_key',         // klucz w config JSON
 *   'label'    => 'Consumer key',
 *   'type'     => 'text|password|url|select|checkbox|textarea',
 *   'required' => true,
 *   'secret'   => true,                   // maskuj w UI, nie nadpisuj pustym
 *   'options'  => ['prod'=>'Produkcja'],  // dla type=select
 *   'help'     => 'Skąd wziąć ten klucz...',
 *   'default'  => '',
 * ]
 */
final class PluginManifest
{
    /**
     * @param string   $type         unikalny klucz typu (np. 'woocommerce'); [a-z0-9_]
     * @param string   $name         nazwa wyświetlana (np. 'WooCommerce')
     * @param string   $version      wersja wtyczki (semver)
     * @param string   $author       autor
     * @param string[] $capabilities lista zdolności (Capability::*)
     * @param array<int,array<string,mixed>> $fields pola konfiguracji
     * @param string   $color        kolor akcentu w UI (hex)
     * @param string   $icon         emoji/ikona w UI
     * @param bool     $multiple     czy można dodać wiele kont tego typu
     * @param string   $logo         logo kanału jako znacznik <svg> (kwadrat, np. viewBox 0 0 24 24) - pokazywane
     *                               przy źródle na liście zamówień; puste = neutralna ikona (Services\OrderChannelIcons)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $version = '1.0.0',
        public readonly string $author = '',
        public readonly array $capabilities = [],
        public readonly array $fields = [],
        public readonly string $color = '#667eea',
        public readonly string $icon = '🔌',
        public readonly bool $multiple = true,
        public readonly string $description = '',
        // Kategoria w galerii dodawania integracji (Capability::CAT_*). Pusta = wywnioskuj ze zdolności.
        public readonly string $category = '',
        public readonly string $logo = ''
    ) {}

    /**
     * Ikona do HTML: logo z manifestu (znacznik <svg>) w rozmiarze $size, a bez logo - emoji z $icon.
     * Logo to kod wtyczki (nie dane od użytkownika), wstawiane jak w Services\OrderChannelIcons::sourceLogo().
     */
    public function iconHtml(string $size = '1.25em'): string
    {
        $logo = trim($this->logo);
        if ($logo !== '' && str_starts_with($logo, '<svg')) {
            $style = 'width:' . $size . ';height:' . $size . ';display:inline-block;vertical-align:-0.25em;flex-shrink:0';
            return '<span class="plugin-logo" style="' . htmlspecialchars($style) . '">'
                . preg_replace('/^<svg\b/', '<svg width="100%" height="100%"', $logo, 1) . '</span>';
        }
        return htmlspecialchars($this->icon);
    }

    public function hasCapability(string $cap): bool
    {
        return in_array($cap, $this->capabilities, true);
    }

    /** Kategoria do galerii: jawnie podana w manifeście albo wyprowadzona ze zdolności. */
    public function categoryKey(): string
    {
        return $this->category !== '' ? $this->category : Capability::categoryFromCapabilities($this->capabilities);
    }

    /** Klucze pól oznaczonych jako sekret (do maskowania / zachowania przy edycji). */
    public function secretKeys(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            if (!empty($f['secret']) && !empty($f['key'])) {
                $out[] = $f['key'];
            }
        }
        return $out;
    }
}

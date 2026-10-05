<?php
declare(strict_types=1);

namespace PaseExt\Opisdemo;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;

/** Rozszerzenie testowe (tests/allegro_offer_hooks_http.php): haki formularza oferty Allegro. */
final class OpisdemoExtension extends AbstractExtension
{
    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(type: 'opisdemo', name: 'Opis demo');
    }

    public function boot(): void
    {
        Hooks::addAction('allegro_offer.description_tools', function (array $ctx): void {
            echo '<button type="button" id="opisDemo" data-product="' . (int) $ctx['product_id'] . '" data-csrf="' . htmlspecialchars($ctx['csrf']) . '">'
                . htmlspecialchars((string) $ctx['product']['name']) . '</button>';
        });
        Hooks::addFilter('allegro_offer.ext_data', function (array $ext, ?array $posted, array $product, array $saved): array {
            if ($posted === null) {
                if (!isset($ext['opisdemo']) && isset($saved['stary_klucz'])) {
                    $ext['opisdemo'] = ['tekst' => (string) $saved['stary_klucz']];
                }
                return $ext;
            }
            if (isset($posted['opisdemo']['tekst'])) {
                $ext['opisdemo'] = ['tekst' => mb_substr(strip_tags((string) $posted['opisdemo']['tekst']), 0, 50)];
            }
            return $ext;
        });
        Hooks::addAction('allegro_offers.operations', function (array $ctx): void {
            echo '<button type="button" data-opisdemo>Opis demo</button>';
        });
    }
}

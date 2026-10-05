<?php
declare(strict_types=1);

namespace Pase\Plugin;

/**
 * Manifest wtyczki rozszerzającej. Pola ustawień ($fields) mają ten sam format co
 * w PluginManifest (text, textarea, select, checkbox, url, password) - formularz
 * pokazuje strona Konfiguracja → Wtyczki, a wartości czyta AbstractExtension::setting().
 */
final class ExtensionManifest
{
    /**
     * @param string $type    unikalny klucz rozszerzenia = nazwa katalogu w extensions/ ([a-z0-9_])
     * @param array<int,array<string,mixed>> $fields pola ustawień
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $version = '1.0.0',
        public readonly string $author = '',
        public readonly string $description = '',
        public readonly string $icon = '🧩',
        public readonly array $fields = []
    ) {}
}

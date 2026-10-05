<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Plugin\Contract\IntegrationPlugin;

/**
 * Wygodna baza dla wtyczek: implementuje withConfig() (klon ze związanym configiem)
 * i udostępnia $this->config oraz helper cfg(). Autor wtyczki dopisuje tylko
 * manifest(), testConnection() i metody zdolności.
 */
abstract class AbstractPlugin implements IntegrationPlugin
{
    /** @var array<string,mixed> */
    protected array $config = [];

    public function withConfig(array $config): static
    {
        $clone = clone $this;
        $clone->config = $config;
        return $clone;
    }

    /** Wartość pola konfiguracji z domyślną. */
    protected function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}

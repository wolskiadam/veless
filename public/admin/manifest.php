<?php
declare(strict_types=1);

/**
 * Manifest aplikacji panelu: „Do ekranu początkowego” na iPhonie / „Zainstaluj” w Chrome otwiera panel
 * jak osobną aplikację, a tylko tak iPhone pozwala na powiadomienia push (Services\WebPush).
 * Bez logowania - przeglądarka pobiera manifest bez ciasteczek.
 */

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'name'             => 'Veless',
    'short_name'       => 'Veless',
    'id'               => './',
    'start_url'        => 'index.php',
    'scope'            => './',
    'display'          => 'standalone',
    'background_color' => '#f6f5f2',
    'theme_color'      => '#9c6b2e',
    'icons'            => [
        ['src' => 'assets/app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'assets/app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => 'assets/app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

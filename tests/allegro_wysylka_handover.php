<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// „Wysyłam z Allegro": sposób nadania (AllegroWysylkaPlugin::applyHandover) - bez sieci.
define('PASE_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require PASE_ROOT . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; return; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        $parts = explode('\\', substr($class, 11));
        $parts[0] = strtolower($parts[0]);
        $f = PASE_ROOT . '/integrations/' . implode('/', $parts) . '.php';
        if (is_file($f)) { require $f; }
    }
});
use PasePlugin\Allegrowysylka\AllegroWysylkaPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$input = ['deliveryMethodId' => 'one-box', 'sender' => ['name' => 'Drwalew', 'city' => 'Drwalew'],
          'receiver' => ['point' => 'AL051SBE'], 'packages' => [['type' => 'PACKAGE']]];

// Allegro One Box: nadanie w automacie bez flagi i bez punktu nadawcy.
$r = AllegroWysylkaPlugin::applyHandover($input, [], 'ALLEGRO', 'point');
check($r['ok'], 'Allegro One Box: nadanie w punkcie dozwolone');
check(!isset($r['input']['additionalServices']), 'Allegro One Box: bez dodatkowych usług');
check($r['input']['receiver']['point'] === 'AL051SBE', 'punkt odbiorcy zostaje');

// Allegro (np. International przez InPost) z usługą sendingAtPoint w propozycji.
$r = AllegroWysylkaPlugin::applyHandover($input, [['additionalServices' => [['id' => 'sendingAtPoint']]]], 'ALLEGRO', 'point');
check($r['ok'] && ($r['input']['additionalServices'] ?? []) === ['sendingAtPoint'], 'ALLEGRO z sendingAtPoint: usługa dodana');

// Podjazd kuriera dla Allegro One: bez flagi, bez punktu nadawcy.
$r = AllegroWysylkaPlugin::applyHandover($input, [], 'ALLEGRO', 'courier');
check($r['ok'] && !isset($r['input']['additionalServices']), 'Allegro One: podjazd kuriera');

// InPost bez zmian: sendingAtPoint.
$r = AllegroWysylkaPlugin::applyHandover($input, [], 'INPOST', 'point');
check(($r['input']['additionalServices'] ?? []) === ['sendingAtPoint'], 'InPost: sendingAtPoint');

// Poczta Polska: sendAtApm.
$r = AllegroWysylkaPlugin::applyHandover($input, [['additionalServices' => ['sendAtApm']]], 'POCZTA_POLSKA', 'point');
check(($r['input']['additionalServices'] ?? []) === ['sendAtApm'], 'Poczta Polska: sendAtApm');

// Inny przewoźnik bez punktu nadawcy i bez usługi: dalej komunikat.
$r = AllegroWysylkaPlugin::applyHandover($input, [], 'DPD', 'point');
check(!$r['ok'] && str_contains($r['message'], 'DPD'), 'DPD bez punktu: komunikat zostaje');

echo "Wszystkie testy przeszły ($checks).\n";

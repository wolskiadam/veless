<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Powody statusu oferty Allegro (validation z GET /sale/product-offers/{id}): czytelne teksty, bez powtórzeń,
// z rodzajem poprawki (producent / osoba odpowiedzialna / informacje o bezpieczeństwie). Bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroOfferOperations;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(AllegroOfferOperations::offerIssues([]) === [], 'no validation field -> no issues');
check(AllegroOfferOperations::offerIssues(['validation' => ['errors' => [], 'warnings' => []]]) === [], 'empty validation -> no issues');

$offer = ['validation' => [
    'errors' => [
        ['code' => 'MISSING_RESPONSIBLE_PRODUCER', 'message' => 'Responsible producer is required.', 'path' => 'productSet[0].responsibleProducer',
         'userMessage' => 'Brak wymaganych danych producenta'],
        ['code' => 'MISSING_RESPONSIBLE_PRODUCER', 'message' => 'Responsible producer is required.', 'path' => 'productSet[1].responsibleProducer',
         'userMessage' => 'Brak wymaganych danych producenta'],
        ['code' => 'MISSING_SAFETY_INFORMATION', 'message' => 'Safety information is required.', 'path' => 'productSet[0].safetyInformation',
         'userMessage' => 'Brak wymaganych inf. o bezpieczeństwie'],
        ['code' => 'RESPONSIBLE_PERSON_REQUIRED', 'message' => 'x', 'path' => 'productSet[0].responsiblePerson', 'userMessage' => 'Brak osoby odpowiedzialnej'],
        ['code' => 'INVALID_PRICE', 'message' => 'Price too low.', 'path' => 'sellingMode.price', 'userMessage' => ''],
        ['code' => 'SOMETHING', 'message' => '', 'path' => null],
        'garbage',
    ],
    'warnings' => [
        ['code' => 'W1', 'message' => 'Add more photos', 'userMessage' => 'Dodaj więcej zdjęć', 'path' => 'images'],
    ],
]];
$issues = AllegroOfferOperations::offerIssues($offer);
check(count($issues) === 6, 'duplicate producer error per product shown once, garbage skipped');
check($issues[0] === ['level' => 'error', 'text' => 'Brak wymaganych danych producenta', 'fix' => 'producer'], 'producer error uses Polish userMessage and producer fix');
check($issues[1] === ['level' => 'error', 'text' => 'Brak wymaganych inf. o bezpieczeństwie', 'fix' => 'safety'], 'safety information error');
check($issues[2]['fix'] === 'person', 'responsible person error');
check($issues[3] === ['level' => 'error', 'text' => 'Price too low.', 'fix' => null], 'falls back to message when userMessage empty');
check($issues[4]['text'] === 'SOMETHING', 'falls back to code when no text');
check($issues[5] === ['level' => 'warning', 'text' => 'Dodaj więcej zdjęć', 'fix' => null], 'warnings listed after errors');

// --- informacje o bezpieczeństwie (productSet[].safetyInformation) ---
check(AllegroOfferOperations::safetyPayload(['safety_mode' => 'none']) === [['type' => 'NO_SAFETY_INFORMATION'], ''], 'safety: no information declaration');
[$si, $err] = AllegroOfferOperations::safetyPayload(['safety_mode' => 'text', 'safety_text' => "  <b>Nie zostawiaj</b> palącej się świecy.\r\n\r\n\r\n\r\nZawiera alfa-pinen.  "]);
check($si === ['type' => 'TEXT', 'description' => "Nie zostawiaj palącej się świecy.\n\nZawiera alfa-pinen."] && $err === '', 'safety: text without HTML, normalised new lines');
check(AllegroOfferOperations::safetyPayload(['safety_text' => '   '])[0] === null, 'safety: empty text rejected');
[$si, $err] = AllegroOfferOperations::safetyPayload(['safety_text' => str_repeat('ą', 5001)]);
check($si === null && str_contains($err, '5000'), 'safety: over 5000 characters rejected');
check(AllegroOfferOperations::safetyPayload(['safety_text' => str_repeat('ą', 5000)])[0] !== null, 'safety: exactly 5000 characters accepted');

echo "All {$checks} checks passed\n";

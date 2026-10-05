<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Allegro Ads: import raportu CSV z panelu Ads, statystyki kampanii, koszty z rozliczeń, budżet. Bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroAds;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(AllegroAds::number('1 234,56 zł') === 1234.56, 'Polish money');
check(AllegroAds::number("2\u{00A0}000") === 2000.0, 'non-breaking space thousands');
check(AllegroAds::number('1,234.50') === 1234.5, 'English thousands');
check(AllegroAds::number('12,5%') === 12.5, 'percent');
check(AllegroAds::number('-') === 0.0 && AllegroAds::number('abc') === 0.0, 'empty and junk are zero');
check(AllegroAds::date('01.09.2026') === '2026-09-01' && AllegroAds::date('2026-09-02 00:00') === '2026-09-02', 'dates');
check(AllegroAds::date('31.02.2026') === null, 'invalid date rejected');

// Nagłówek: kolumny pochodne (CTR, średni CPC, ROAS) nie mogą przejąć kliknięć ani kosztu.
$map = AllegroAds::mapHeader(['Kampania', 'Status kampanii', 'Wyświetlenia', 'CTR', 'Kliknięcia', 'Średni CPC', 'Koszt', 'Wartość sprzedaży', 'Liczba sprzedanych sztuk', 'ROAS']);
check($map === ['campaign' => 0, 'impressions' => 2, 'clicks' => 4, 'cost' => 6, 'sales_value' => 7, 'sold_items' => 8], 'header mapped without derived columns');

$csv = "\xEF\xBB\xBFRaport kampanii Allegro Ads;;;;;\n"
     . "Data;Kampania;Wyświetlenia;Kliknięcia;CTR;Koszt;Wartość sprzedaży;Liczba sprzedanych sztuk\n"
     . "01.09.2026;Świece sojowe;10 000;120;1,2%;60,00 zł;480,00 zł;12\n"
     . "02.09.2026;Świece sojowe;8 000;80;1,0%;40,00 zł;0,00 zł;0\n"
     . "01.09.2026;Woski zapachowe;2 000;50;2,5%;25,50 zł;20,00 zł;1\n"
     . "30.08.2026;Woski zapachowe;1 000;10;1,0%;5,00 zł;0,00 zł;0\n"
     . "Suma;;21 000;260;;130,50 zł;500,00 zł;13\n";
$p = AllegroAds::parseCsv($csv);
check($p['errors'] === [] && count($p['rows']) === 4, 'CSV parsed, title and total rows skipped');
check($p['rows'][0] === ['day' => '2026-09-01', 'campaign' => 'Świece sojowe', 'ad_group' => '', 'impressions' => 10000, 'clicks' => 120, 'cost' => 60.0, 'sales_value' => 480.0, 'sold_items' => 12], 'row values');
check(AllegroAds::parseCsv("foo,bar\n1,2\n")['errors'] !== [], 'unknown file rejected');
$cp1250 = "Kampania;Klikni\xEAcia;Koszt\n\x8Cwiece;3;1,50\n"; // Windows-1250
check(AllegroAds::parseCsv($cp1250)['rows'][0]['campaign'] === 'Świece', 'Windows-1250 export');
$comma = "Campaign,Impressions,Clicks,Cost,Sales value\n\"Candles, soy\",100,5,\"2.50\",10\n";
check(AllegroAds::parseCsv($comma)['rows'][0]['campaign'] === 'Candles, soy', 'comma CSV with quotes');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
AllegroAds::migrate($pdo);
AllegroAds::migrate($pdo);
$ads = new AllegroAds($pdo);
$ads->import('wrzesien.csv', '2026-09-01', '2026-09-30', $p['rows']);
$stats = $ads->campaignStats('2026-09-01', '2026-09-30');
check(count($stats) === 2 && $stats[0]['campaign'] === 'Świece sojowe', 'campaigns grouped, sorted by cost');
check($stats[0]['cost'] === 100.0 && $stats[0]['clicks'] === 200 && abs($stats[0]['roas'] - 4.8) < 1e-9, 'campaign sums and ROAS');
check($stats[1]['cost'] === 25.5, 'row from other month excluded');
$ads->import('wrzesien2.csv', '2026-09-01', '2026-09-30', $p['rows']);
check(count($ads->imports()) === 1 && $ads->campaignStats('2026-09-01', '2026-09-30')[0]['cost'] === 100.0, 're-import of same period replaces');
$noDay = AllegroAds::parseCsv("Kampania;Koszt;Kliknięcia\nA;10;1\n")['rows'];
$id = $ads->import('bez-dat.csv', '2026-08-01', '2026-08-31', $noDay);
$aug = $ads->campaignStats('2026-08-01', '2026-08-31');
check(array_column($aug, 'cost', 'campaign') === ['A' => 10.0, 'Woski zapachowe' => 5.0], 'undated rows count in import period, dated ones by day');
$ads->deleteImport($id);
check(array_column($ads->campaignStats('2026-08-01', '2026-08-31'), 'cost', 'campaign') === ['Woski zapachowe' => 5.0], 'import deleted');
$t = AllegroAds::totals($stats);
check($t['cost'] === 125.5 && $t['sales_value'] === 500.0 && $t['ctr'] !== null, 'totals');

$types = [['id' => 'SUC', 'description' => 'Prowizja od sprzedaży'], ['id' => 'ADS', 'description' => 'Opłata za kampanię Allegro Ads'], ['id' => 'NSP', 'description' => 'Reklama ofert sponsorowanych']];
check(AllegroAds::guessAdsTypes($types) === ['ADS', 'NSP'], 'ads billing types guessed');

$b = AllegroAds::summarizeBilling([
    ['occurredAt' => '2026-09-01T22:30:00.000Z', 'type' => ['id' => 'ADS', 'name' => 'Allegro Ads'], 'offer' => ['id' => '1', 'name' => 'Świeca'], 'value' => ['amount' => '-12.30', 'currency' => 'PLN']],
    ['occurredAt' => '2026-09-02T08:00:00.000Z', 'type' => ['id' => 'ADS', 'name' => 'Allegro Ads'], 'value' => ['amount' => '-7.70', 'currency' => 'PLN']],
    ['occurredAt' => '2026-09-02T09:00:00.000Z', 'type' => ['id' => 'ADS', 'name' => 'Allegro Ads'], 'offer' => ['id' => '1', 'name' => 'Świeca'], 'value' => ['amount' => '2.00', 'currency' => 'PLN']],
]);
check($b['total'] === 18.0, 'billing cost total (refund subtracted)');
check(array_keys($b['byDay']) === ['2026-09-02'] && abs($b['byDay']['2026-09-02'] - 18.0) < 1e-9, 'days in Warsaw time');
check(abs($b['byOffer'][0]['cost'] - 10.3) < 1e-9, 'cost per offer');

$u = AllegroAds::budgetUsage(300, 150, 10, 30);
check($u['percent'] === 50.0 && $u['forecast'] === 450.0 && $u['level'] === 'warn', 'forecast over budget warns');
check(AllegroAds::budgetUsage(300, 310, 20, 30)['level'] === 'over', 'over budget');
check(AllegroAds::budgetUsage(300, 50, 15, 30)['level'] === 'ok', 'on track');
check(AllegroAds::budgetUsage(0, 50, 15, 30)['level'] === 'none', 'no budget');

echo "\n$checks checks passed\n";

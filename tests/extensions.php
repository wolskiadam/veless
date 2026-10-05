<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wtyczki rozszerzające: haki (Pase\Plugin\Hooks), rejestr extensions/ i instalator ZIP rozpoznający rodzaj wtyczki.
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/crm-extensions-' . bin2hex(random_bytes(6));
define('PASE_ROOT', $temp);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});

use Pase\Automation\Catalog;
use Pase\Plugin\ExtensionRegistry;
use Pase\Plugin\ExtensionSettings;
use Pase\Plugin\Hooks;
use Pase\Plugin\PluginInstaller;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

foreach (['extensions', 'integrations', 'storage/logs'] as $d) { mkdir("$temp/$d", 0700, true); }
try {
    // ---------------- Hooks: kolejność, priorytety, odporność na błędy
    ExtensionRegistry::useDir("$temp/extensions");
    Hooks::addFilter('t.list', static fn(array $v): array => [...$v, 'b']);
    Hooks::addFilter('t.list', static fn(array $v): array => [...$v, 'a'], 5);
    Hooks::addFilter('t.list', static fn(array $v): array => throw new RuntimeException('zepsuty filtr'));
    Hooks::addFilter('t.list', static fn(array $v): string => 'zły typ');
    Hooks::addFilter('t.list', static fn(array $v, string $x): array => [...$v, $x], 20);
    check(Hooks::applyFilters('t.list', [], 'c') === ['a', 'b', 'c'], 'Filters run by priority, broken and wrong-type filters are skipped');
    check(Hooks::applyFilters('t.none', 7) === 7, 'Filter without callbacks returns the value');

    $seen = [];
    Hooks::addAction('t.act', static function (int $id) use (&$seen): void { $seen[] = $id; });
    Hooks::addAction('t.act', static fn() => throw new LogicException('zepsuta akcja'));
    Hooks::addAction('t.act', static function (int $id) use (&$seen): void { $seen[] = $id * 10; });
    Hooks::doAction('t.act', 4);
    check($seen === [4, 40], 'Actions keep running after one fails');

    Hooks::addAction('t.html', static function (): void { echo '<b>A</b>'; });
    Hooks::addAction('t.html', static function (): void { echo '<i>half'; throw new RuntimeException('x'); });
    Hooks::addAction('t.html', static fn(): string => '<u>C</u>');
    check(Hooks::render('t.html') === '<b>A</b><u>C</u>', 'render() collects output and drops a failed hook');
    check(Hooks::safe('t', static fn(int $a) => $a + 1, 1) === 2 && Hooks::safe('t', static fn() => throw new RuntimeException('y')) === null, 'safe() returns the result or null');
    Hooks::reset();
    check(Hooks::applyFilters('t.list', []) === [], 'reset() clears hooks');

    // ---------------- Rejestr: przykładowe rozszerzenie z examples/
    exec('cp -r ' . escapeshellarg("$root/examples/extensions/przyklad") . ' ' . escapeshellarg("$temp/extensions/przyklad"));
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    \Pase\Support\Runtime::setPdo($pdo);
    ExtensionRegistry::reset();
    check(array_keys(ExtensionRegistry::all()) === ['przyklad'], 'Example extension boots from extensions/');
    $cols = Hooks::applyFilters('orders.columns', ['number' => [], 'status' => [], 'client' => []], 'active');
    check(array_keys($cols) === ['number', 'status', 'x_items', 'client'], 'Column is inserted after the chosen core column');
    $row = ['woo_order_id' => 7, 'payload' => json_encode(['line_items' => [['quantity' => 2], ['quantity' => 3]]])];
    check(($cols['x_items']['cell'])($row) === '5', 'Column cell counts items');
    check(isset(Catalog::actions()['x_przyklad_note']['run']) && Catalog::isActionReady('x_przyklad_note')
        && isset(Catalog::actions()['set_status']), 'Automation action from the extension is in the catalog next to core ones');
    check(isset(Hooks::pages()['przyklad.notatki']) && Hooks::pages()['przyklad.notatki']['group'] === 'orders'
        && Hooks::pages()['przyklad.zapisz']['menu'] === false, 'Extension pages are registered with their owner');
    check(Hooks::packingAction('ext_przyklad_note') !== null && Hooks::packingAction('ext_nope') === null, 'Packing action is registered with ext_ prefix');
    check(str_contains(Hooks::assetTags('packing'), 'PackExt') && str_contains(Hooks::assetTags('admin'), 'x_note'), 'Assets are inlined for packing and admin');

    // Ustawienia: wyłączenie kolumny w panelu działa od następnego żądania.
    $m = ExtensionRegistry::all()['przyklad']->manifest();
    ExtensionSettings::save($pdo, $m, ['column_after' => 'nope']);
    check(ExtensionSettings::get('przyklad') === ['items_column' => '0', 'column_after' => ''], 'Settings keep only manifest fields and valid options');
    ExtensionRegistry::reset();
    check(!isset(Hooks::applyFilters('orders.columns', ['number' => []])['x_items']), 'Unchecked setting removes the column');

    // Automatyzacja: RuleEngine uruchamia akcję rozszerzenia.
    $run = Catalog::actions()['x_przyklad_note']['run'];
    $run(7, ['text' => 'Zadzwonić do klienta'], [], $pdo);
    check((new \PaseExt\Przyklad\Notes($pdo))->get(7)['note'] === 'Zadzwonić do klienta', 'Automation action writes the note');
    $order = Hooks::applyFilters('packing.order', ['id' => 7, 'items' => []], []);
    check(($order['ext']['przyklad']['note'] ?? null) === 'Zadzwonić do klienta', 'packing.order adds extension data');

    // Wyłączone rozszerzenie (.disabled) nie startuje; nazwa katalogu musi zgadzać się z manifestem.
    touch("$temp/extensions/przyklad/.disabled");
    ExtensionRegistry::reset();
    check(ExtensionRegistry::all() === [] && Hooks::pages() === [] && !isset(Catalog::actions()['x_przyklad_note']), 'Disabled extension adds nothing');
    check(ExtensionRegistry::installed()[0]['enabled'] === false && ExtensionRegistry::installed()[0]['manifest']->name !== '', 'Disabled extension is still listed');
    unlink("$temp/extensions/przyklad/.disabled");
    mkdir("$temp/extensions/zla");
    file_put_contents("$temp/extensions/zla/register.php", '<?php return "Brak\\\\Klasy";');
    ExtensionRegistry::reset();
    check(array_keys(ExtensionRegistry::all()) === ['przyklad'], 'Broken extension is skipped, others still boot');
    exec('rm -rf ' . escapeshellarg("$temp/extensions/zla"));

    // ---------------- Instalator: rodzaj z register.php, ZIP trafia do właściwego katalogu
    check(PluginInstaller::kindOf("$temp/extensions/przyklad") === 'extension' && PluginInstaller::kindOf("$root/integrations/gs1") === 'integration', 'Installer tells extensions from integrations');
    if (class_exists(ZipArchive::class)) {
        exec('rm -rf ' . escapeshellarg("$temp/extensions/przyklad"));
        ExtensionRegistry::reset();
        $zipFile = "$temp/przyklad.zip";
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/examples/extensions/przyklad", FilesystemIterator::SKIP_DOTS)) as $f) {
            $zip->addFile($f->getPathname(), 'przyklad/' . substr($f->getPathname(), strlen("$root/examples/extensions/przyklad/")));
        }
        $zip->close();
        $res = PluginInstaller::installFromZip(['name' => 'przyklad.zip', 'tmp_name' => $zipFile, 'error' => UPLOAD_ERR_OK]);
        check($res['ok'] && ($res['kind'] ?? '') === 'extension' && is_file("$temp/extensions/przyklad/register.php") && !is_dir("$temp/integrations/przyklad"), 'ZIP with an extension installs into extensions/');
        check(PluginInstaller::modifiedSinceInstall() === [], 'Freshly installed plugin matches its fingerprint');
        check(PluginInstaller::setEnabled('przyklad', false, 'extension')['ok'] && is_file("$temp/extensions/przyklad/.disabled"), 'Extension can be disabled');
        check(PluginInstaller::modifiedSinceInstall() === [], 'Disabling does not count as a modification');
        file_put_contents("$temp/extensions/przyklad/shell.php", '<?php // podrzucony plik');
        $mod = PluginInstaller::modifiedSinceInstall();
        check(count($mod) === 1 && $mod[0]['slug'] === 'przyklad', 'File dropped into a plugin outside the panel is detected');
        unlink("$temp/extensions/przyklad/shell.php");
        check(!PluginInstaller::setEnabled('przyklad', true)['ok'], 'Integration toggle does not touch extensions');
        check(PluginInstaller::uninstall('przyklad', 'extension')['ok'] && !is_dir("$temp/extensions/przyklad"), 'Extension can be removed');
        check(!str_contains((string) @file_get_contents(PluginInstaller::fingerprintFile()), 'przyklad'), 'Uninstall forgets the fingerprint');
        $envVars = new ReflectionProperty(\Pase\Support\Env::class, 'vars');
        $envBefore = $envVars->getValue();
        $envVars->setValue(null, ['PLUGIN_UPLOAD' => '0'] + $envBefore);
        $res = PluginInstaller::installFromZip(['name' => 'przyklad.zip', 'tmp_name' => $zipFile, 'error' => UPLOAD_ERR_OK]);
        check(!$res['ok'] && !is_dir("$temp/extensions/przyklad"), 'PLUGIN_UPLOAD=0 blocks ZIP installs');
        $envVars->setValue(null, $envBefore);
    }
    echo "extensions: {$checks} checks passed\n";
} finally {
    exec('rm -rf ' . escapeshellarg($temp));
}

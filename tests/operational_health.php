<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/Services/OperationalHealth.php';
use Pase\Services\OperationalHealth as Health;
$now = 100000;
$tests = [
    Health::issues($now, 0, $now) === [],
    count(Health::issues(0, 0, $now)) === 1,
    count(Health::issues($now - 601, 0, $now)) === 1,
    count(Health::issues($now + 61, 1, $now)) === 2,
    count(Health::issues($now, 1, $now)) === 1,
    !Health::shouldNotify('', '', 0, $now),
    Health::shouldNotify('fault', '', 0, $now),
    !Health::shouldNotify('fault', 'fault', $now - 601, $now),
    Health::shouldNotify('fault', 'fault', $now - 21600, $now),
    Health::shouldNotify('', 'fault', $now - 601, $now),
    !Health::shouldNotify('new fault', 'fault', $now - 10, $now),
    Health::shouldNotify('fault', '', $now - 600, $now),
];
foreach ($tests as $index => $passed) {
    if (!$passed) { throw new RuntimeException('Failed check ' . $index); }
}
echo 'PASS: ' . count($tests) . " operational health checks\n";

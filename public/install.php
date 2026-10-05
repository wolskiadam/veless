<?php
declare(strict_types=1);

// No HTTP installation, including ?force=1. Use the trusted CLI installer.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'Instalator webowy jest wyłączony. Użyj php cli/install.php przez SSH.';

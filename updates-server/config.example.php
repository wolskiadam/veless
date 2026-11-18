<?php
// Skopiuj jako config.php i uzupełnij.
return [
    // Hasło do listy instalacji: index.php?action=installs&key=<to hasło>. Długi losowy ciąg.
    'admin_key' => '',
    // Katalog z paczkami veless-<wersja>.zip (wgrywane przez FTP albo przez workflow „Paczka wydania”).
    'releases_dir' => __DIR__ . '/releases',
    // Katalog na listę instalacji (musi być zapisywalny dla PHP, zablokowany z internetu).
    'data_dir' => __DIR__ . '/data',
];

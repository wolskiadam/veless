<?php
declare(strict_types=1);

/**
 * Połączenie PDO dla panelu admina.
 * Czyta dane bazy z tego samego .env co reszta PASE (przez config/config.php),
 * więc nie duplikujemy poświadczeń. Udostępnia globalne $pdo - tak jak
 * db.php w sds-generator, żeby strony panelu były pisane w tej samej konwencji.
 */

$config = require dirname(__DIR__, 2) . '/config/config.php';
$pdo    = (require PASE_ROOT . '/config/database.php')($config['db']);

// Reszta panelu korzysta z globalnego $pdo (wzorzec sds-generator).

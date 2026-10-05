<?php
/**
 * Skrót wejścia: /pase/ -> panel admina (/pase/public/admin/index.php).
 * Ścieżka względna, więc działa niezależnie od nazwy katalogu/domeny.
 * Sam panel (auth.php) i tak przekieruje dalej na login.php, jeśli trzeba się zalogować.
 */
header('Location: public/admin/index.php');
exit;

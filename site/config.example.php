<?php
// Skopiuj ten plik jako config.php i uzupełnij. config.php nie trafia do repozytorium.
return [
    // Długi, losowy ciąg (np. wynik: php -r "echo bin2hex(random_bytes(32));").
    // Podpisuje linki do pobrania i wypisu oraz skrót adresu IP w zapisach.
    'secret' => 'ZMIEN-MNIE',

    // Skąd pobierać paczkę: plik na tym serwerze (pierwszeństwo) albo adres URL,
    // np. https://github.com/<konto>/<repo>/archive/refs/heads/main.zip
    'download_file' => '',
    'download_name' => 'veless.zip',
    'download_url'  => '',

    // Ile godzin działa link do pobrania po podaniu e-maila.
    'link_hours' => 24,

    // Opcjonalnie: powiadomienie o każdym nowym zapisie na ten adres (funkcja mail() serwera).
    'notify_email' => '',
    // Opcjonalnie: wyślij zapisanej osobie e-mail z linkiem do pobrania (nadawca poniżej).
    'send_link_email' => false,
    'mail_from' => '',

    // Klucz do eksportu listy adresów: export.php?key=...
    'export_key' => 'ZMIEN-MNIE-TEZ',

    // Ile zapisów z jednego adresu IP w ciągu godziny.
    'rate_limit' => 10,

    // Adres strony (do linków w e-mailach), np. https://veless.pl
    'site_url' => '',
];

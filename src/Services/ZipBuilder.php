<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Składa archiwum ZIP w pamięci.
 *
 * Używa rozszerzenia ZipArchive, gdy jest dostępne, a gdy go nie ma - własnej
 * implementacji "store" (bez kompresji). Powód: ext-zip bywa wyłączone na shared
 * hostingu, a reszta PASE też nie zakłada niczego ponad PDO/curl/json. Pliki,
 * które tędy idą (agent druku), to kilkadziesiąt kilobajtów tekstu, więc brak
 * kompresji nic nie kosztuje.
 */
final class ZipBuilder
{
    /**
     * @param array<string,string> $files nazwa w archiwum => absolutna ścieżka na dysku
     * @return string zawartość pliku ZIP
     */
    public static function build(array $files): string
    {
        return class_exists(\ZipArchive::class)
            ? self::withExtension($files)
            : self::storeOnly($files);
    }

    /** @param array<string,string> $files */
    private static function withExtension(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pase-zip-');
        if ($tmp === false) {
            return self::storeOnly($files);
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return self::storeOnly($files);
        }

        foreach ($files as $nameInZip => $sourcePath) {
            $zip->addFile($sourcePath, $nameInZip);
        }
        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }

    /**
     * Minimalny ZIP bez kompresji (metoda 0 "stored") - sam format kontenera.
     * Każdy plik dostaje nagłówek lokalny, na końcu idzie centralny katalog
     * i rekord EOCD. Flaga 0x0800 oznacza nazwy w UTF-8.
     *
     * @param array<string,string> $files
     */
    private static function storeOnly(array $files): string
    {
        $entries = '';
        $central = '';
        $offset  = 0;
        $count   = 0;

        foreach ($files as $nameInZip => $sourcePath) {
            $data = @file_get_contents($sourcePath);
            if ($data === false) {
                continue;
            }

            $name  = str_replace('\\', '/', $nameInZip);
            $crc   = crc32($data);
            $size  = strlen($data);
            [$dosTime, $dosDate] = self::dosTimestamp((int) (@filemtime($sourcePath) ?: time()));

            $localHeader = pack('V', 0x04034b50)   // sygnatura nagłówka lokalnego
                . pack('v', 20)                     // wymagana wersja
                . pack('v', 0x0800)                 // flagi: nazwa w UTF-8
                . pack('v', 0)                      // metoda: 0 = bez kompresji
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $size)                  // rozmiar spakowany
                . pack('V', $size)                  // rozmiar oryginalny
                . pack('v', strlen($name))
                . pack('v', 0);                     // brak pola extra

            $entries .= $localHeader . $name . $data;

            $central .= pack('V', 0x02014b50)       // sygnatura wpisu katalogu
                . pack('v', 20)                     // wersja twórcy
                . pack('v', 20)                     // wymagana wersja
                . pack('v', 0x0800)
                . pack('v', 0)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', strlen($name))
                . pack('v', 0)                      // extra
                . pack('v', 0)                      // komentarz
                . pack('v', 0)                      // numer dysku
                . pack('v', 0)                      // atrybuty wewnętrzne
                . pack('V', 0)                      // atrybuty zewnętrzne
                . pack('V', $offset)                // pozycja nagłówka lokalnego
                . $name;

            $offset = strlen($entries);
            $count++;
        }

        $eocd = pack('V', 0x06054b50)
            . pack('v', 0)                          // numer tego dysku
            . pack('v', 0)                          // dysk z początkiem katalogu
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', strlen($central))
            . pack('V', strlen($entries))
            . pack('v', 0);                         // komentarz archiwum

        return $entries . $central . $eocd;
    }

    /** Czas modyfikacji w formacie MS-DOS, którego wymaga format ZIP. */
    private static function dosTimestamp(int $timestamp): array
    {
        $parts = getdate($timestamp);
        if ($parts['year'] < 1980) {
            // Format DOS liczy lata od 1980 - wcześniejsza data nie da się zapisać.
            $parts = getdate(mktime(0, 0, 0, 1, 1, 1980));
        }

        $time = ($parts['hours'] << 11) | ($parts['minutes'] << 5) | (int) ($parts['seconds'] / 2);
        $date = (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'];

        return [$time, $date];
    }
}

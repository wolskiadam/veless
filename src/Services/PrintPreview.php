<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Liczy, jak duży będzie wydruk pliku na drukarce etykiet — ta sama arytmetyka,
 * którą wykonuje agent (agent/print_agent.py, funkcja target_width_mm).
 *
 * Reguła: PDF niesie swój prawdziwy rozmiar strony, obrazek rastrowy nie. Dlatego
 * PDF mieszczący się na taśmie drukujemy 1:1, szerszy zmniejszamy do szerokości
 * taśmy, a obrazek zawsze skalujemy do taśmy.
 *
 * Rozmiar strony PDF-a czytamy regexem z /MediaBox zamiast biblioteką — projekt
 * nie ma zależności composera i nie chcemy ich dokładać dla jednej liczby.
 * Gdy PDF trzyma strukturę w strumieniach obiektów (ObjStm), MediaBox bywa
 * nieczytelny — wtedy uczciwie zwracamy null i podgląd to zaznacza.
 */
final class PrintPreview
{
    /** Domyślne, gdy agent jeszcze się nie zameldował. */
    public const DEFAULT_DPI = 203;
    public const DEFAULT_LABEL_WIDTH_MM = 101.6;

    /**
     * @return array{width:float,height:float}|null rozmiar strony/obrazu w mm
     *         (null dla obrazka - nie niesie rozmiaru fizycznego)
     */
    public static function sourceSizeMm(string $path): ?array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return self::pdfPageSizeMm($path);
        }

        return null;   // obrazek: rozmiar tylko w pikselach, patrz sourcePixels()
    }

    /** @return array{width:int,height:int}|null wymiary obrazka w pikselach */
    public static function sourcePixels(string $path): ?array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * Rozmiar wydruku na taśmie.
     *
     * @param array{width:float,height:float}|null $sourceMm    rozmiar źródła (PDF)
     * @param array{width:int,height:int}|null     $sourcePx    wymiary obrazka
     * @return array{width:float,height:float,fills:bool,scaled:bool}|null
     */
    public static function printedSizeMm(
        float $labelWidthMm,
        ?array $sourceMm,
        ?array $sourcePx
    ): ?array {
        if ($sourceMm !== null && $sourceMm['width'] > 0) {
            $targetWidth = min($sourceMm['width'], $labelWidthMm);
            $ratio       = $sourceMm['height'] / $sourceMm['width'];
            return [
                'width'  => $targetWidth,
                'height' => $targetWidth * $ratio,
                'fills'  => abs($targetWidth - $labelWidthMm) < 0.6,
                'scaled' => $sourceMm['width'] > $labelWidthMm,
            ];
        }

        if ($sourcePx !== null && $sourcePx['width'] > 0) {
            // Obrazek zawsze rozciągamy do szerokości taśmy - nie ma innej podstawy.
            $ratio = $sourcePx['height'] / $sourcePx['width'];
            return [
                'width'  => $labelWidthMm,
                'height' => $labelWidthMm * $ratio,
                'fills'  => true,
                'scaled' => true,
            ];
        }

        return null;
    }

    /**
     * Szerokość strony PDF z /MediaBox. Bierzemy PIERWSZE wystąpienie: to
     * MediaBox katalogu stron albo pierwszej strony, a biblioteka wydruków
     * trzyma etykiety jednostronicowe.
     *
     * @return array{width:float,height:float}|null
     */
    private static function pdfPageSizeMm(string $path): ?array
    {
        // Czytamy początek pliku - MediaBox siedzi w strukturze dokumentu,
        // nie w strumieniach z grafiką, więc nie ma po co wczytywać całości.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 512 * 1024);
        fclose($handle);

        if (!preg_match('/MediaBox\s*\[\s*([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)/', $head, $m)) {
            return null;
        }

        // MediaBox to [llx lly urx ury] w punktach PostScript (1/72 cala).
        $widthPt  = abs((float) $m[3] - (float) $m[1]);
        $heightPt = abs((float) $m[4] - (float) $m[2]);
        if ($widthPt <= 0 || $heightPt <= 0) {
            return null;
        }

        return [
            'width'  => $widthPt * 25.4 / 72,
            'height' => $heightPt * 25.4 / 72,
        ];
    }
}

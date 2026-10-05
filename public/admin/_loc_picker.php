<?php
declare(strict_types=1);

/**
 * Wybór lokalizacji w magazynie z wyszukiwarką (zamiast zwykłego <select>, który przy
 * setkach półek jest nieużywalny). Pole to ukryty <input> z tą samą nazwą co dawny select,
 * więc zapis po stronie serwera się nie zmienia; przycisk obok otwiera listę z polem
 * „Szukaj” (kod, opis, bez myślników: „r1a” = R1-A), strzałki + Enter, Esc zamyka.
 *
 * Użycie:
 *   require_once __DIR__ . '/_loc_picker.php';
 *   echo locPickerData($locations);                     // raz na stronę (lista + skrypt)
 *   echo locPicker('location_id', $cur, ['' => '— brak —']);
 *
 * Opcje: form (atrybut form="" ukrytego pola), submit (wybór wysyła formularz),
 * required (bez wyboru formularz się nie wyśle - patrz locPickerChosen w JS), placeholder,
 * counts (pokazuj liczbę produktów przy kodzie), wide (kod + opis na przycisku).
 */

/** Słownik lokalizacji po id (ustawiany przez locPickerData, czytany przez locPicker). */
function locPickerIndex(?array $locations = null): array
{
    static $byId = [];
    if ($locations !== null) {
        $byId = array_column($locations, null, 'id');
    }
    return $byId;
}

/** @param list<array{id:int,code:string,label:string,note?:?string,products?:int}> $locations */
function locPickerData(array $locations): string
{
    locPickerIndex($locations);
    $data = array_map(static fn(array $l) => ['id' => (int) $l['id'], 'code' => (string) $l['code'], 'label' => (string) $l['label'],
        'note' => (string) ($l['note'] ?? ''), 'n' => (int) ($l['products'] ?? 0)], $locations);
    $v = @filemtime(__DIR__ . '/assets/loc-picker.js') ?: 1;
    return '<script>window.CRM_LOCATIONS = ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";</script>\n"
        . '<script src="assets/loc-picker.js?v=' . $v . '" defer></script>' . "\n";
}

/**
 * @param array<string,string> $extras  wartości spoza słownika pokazywane na górze listy (np. '' => '— brak —')
 * @param array{form?:string,submit?:bool,required?:bool,placeholder?:string,counts?:bool,wide?:bool,id?:string} $opt
 */
function locPicker(string $name, ?string $value, array $extras = [], array $opt = []): string
{
    $value = (string) ($value ?? '');
    $text = null;
    if (array_key_exists($value, $extras) && !($opt['required'] ?? false)) {
        $text = $extras[$value];
    } elseif ($value !== '' && ctype_digit($value)) {
        // Napis na przycisku liczony też bez JS, żeby strona od razu pokazywała bieżącą półkę.
        $l = locPickerIndex()[(int) $value] ?? null;
        if ($l !== null) {
            $text = $l['code'] . (($opt['wide'] ?? false) ? ' · ' . $l['label'] : '');
        }
    }
    $h = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $attrs = ' data-extras="' . $h(json_encode(array_map(null, array_map('strval', array_keys($extras)), array_values($extras)), JSON_UNESCAPED_UNICODE)) . '"';
    foreach (['submit', 'required', 'counts', 'wide'] as $flag) {
        if ($opt[$flag] ?? false) {
            $attrs .= ' data-' . $flag . '="1"';
        }
    }
    $placeholder = $opt['placeholder'] ?? 'wybierz…';
    return '<span class="loc-picker"' . $attrs . '>'
        . '<input type="hidden" name="' . $h($name) . '" value="' . $h($value) . '"'
        . (isset($opt['form']) ? ' form="' . $h($opt['form']) . '"' : '')
        . (isset($opt['id']) ? ' id="' . $h($opt['id']) . '"' : '') . '>'
        . '<button type="button" class="loc-picker-btn' . ($text === null ? ' is-empty' : '') . '" aria-haspopup="listbox" data-placeholder="' . $h($placeholder) . '">'
        . '<span class="loc-picker-text">' . $h($text ?? $placeholder) . '</span><span class="loc-picker-caret" aria-hidden="true">▾</span></button>'
        . '</span>';
}

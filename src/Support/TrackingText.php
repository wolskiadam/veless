<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Opis zdarzenia przesyłki (etap u przewoźnika) do pokazania ludziom.
 *
 * Część przewoźników podaje opisy po angielsku - np. Allegro One w API śledzenia Allegro zwraca
 * „Parcel has been dispatched to its destination point" mimo nagłówka Accept-Language: pl-PL.
 * Tłumaczymy je na polski przy wyświetlaniu (dotyczy też historii zapisanej wcześniej); w bazie
 * zostaje oryginał. Strona klienta po angielsku dostaje opis bez zmian.
 *
 * Kolejność: znane zdanie (wzorce poniżej, z dopiskiem po dwukropku/nawiasie przeniesionym bez zmian)
 * -> nieznane zdanie po angielsku: ogólny etap z kodu (W drodze, Doręczona...) -> oryginał.
 */
final class TrackingText
{
    /** Kod etapu (jak w śledzeniu Allegro) -> ogólny opis, gdy angielskiego zdania nie znamy. */
    private const CODE_PL = [
        'PENDING'               => 'Przesyłka przygotowana do nadania',
        'IN_TRANSIT'            => 'Przesyłka w drodze',
        'RELEASED_FOR_DELIVERY' => 'Przesyłka wydana do doręczenia',
        'AVAILABLE_FOR_PICKUP'  => 'Przesyłka czeka na odbiór',
        'NOTICE_LEFT'           => 'Awizo',
        'DELIVERED'             => 'Przesyłka doręczona',
        'RETURNED'              => 'Przesyłka zwrócona do nadawcy',
        'ISSUE'                 => 'Problem z doręczeniem',
    ];

    private const LOCKER = '(?:the\s+)?(?:parcel\s+locker|one\s*box|locker|parcel\s+machine|automat)';
    private const POINT  = '(?:the\s+)?(?:pick-?up\s+point|drop-?off\s+point|collection\s+point|one\s*punkt|point)';
    private const SORT   = '(?:the\s+)?(?:sorting|logistics|distribution)\s+(?:center|centre|hub|facility)';
    private const DEPOT  = '(?:the\s+)?(?:local\s+|courier\s+)?(?:depot|branch|warehouse|terminal|hub)';
    private const PERSON = '(?:the\s+)?(?:recipient|receiver|customer|buyer|addressee)';

    /**
     * Wzorce orzeczenia (po „Parcel has been" / „Shipment was" / bez podmiotu) -> polski opis.
     * Bardziej szczegółowe przed ogólnymi.
     * @return array<int,array{0:string,1:string}>
     */
    private static function rules(): array
    {
        $L = self::LOCKER; $P = self::POINT; $S = self::SORT; $D = self::DEPOT; $R = self::PERSON;
        return [
            ['(?:dispatched|sent|forwarded)\s+to\s+(?:its|the)\s+destination(?:\s+(?:point|pick-?up\s+point|parcel\s+locker|branch|depot))?', 'Przesyłka wysłana do punktu docelowego'],
            ['on\s+(?:its|the)\s+way\s+to\s+(?:its\s+|the\s+)?destination(?:\s+point)?', 'Przesyłka w drodze do punktu docelowego'],
            ['(?:dispatched|sent|forwarded|on\s+(?:its|the)\s+way)\s+to\s+' . $S, 'Przesyłka w drodze do sortowni'],
            ['(?:accepted|received|registered|scanned|processed|sorted)\s+(?:at|in|by)\s+' . $S, 'Przesyłka przyjęta w sortowni'],
            ['(?:arrived|delivered)\s+(?:at|in|to)\s+' . $S, 'Przesyłka dotarła do sortowni'],
            ['(?:dispatched|sent|departed|left)\s+(?:from\s+)?' . $S, 'Przesyłka wysłana z sortowni'],
            ['(?:accepted|received|arrived|registered|scanned)\s+(?:at|in)\s+(?:the\s+)?destination\s+(?:depot|branch|warehouse|terminal|hub)', 'Przesyłka dotarła do oddziału docelowego'],
            ['(?:accepted|received|arrived|registered|scanned)\s+(?:at|in)\s+' . $D, 'Przesyłka przyjęta w oddziale'],
            ['(?:dispatched|sent|departed|left)\s+(?:from\s+)?' . $D, 'Przesyłka wysłana z oddziału'],
            ['(?:picked\s+up|collected|taken|received)\s+from\s+' . $L . '\s+by\s+(?:the\s+)?courier', 'Kurier odebrał przesyłkę z automatu paczkowego'],
            ['(?:picked\s+up|collected|taken|received)\s+from\s+' . $P . '\s+by\s+(?:the\s+)?courier', 'Kurier odebrał przesyłkę z punktu'],
            ['(?:picked\s+up|collected|taken|received)\s+(?:from\s+(?:the\s+)?sender\s+)?by\s+(?:the\s+)?courier', 'Przesyłka odebrana przez kuriera'],
            ['(?:picked\s+up|collected)\s+from\s+(?:the\s+)?sender', 'Przesyłka odebrana od nadawcy'],
            ['handed\s+(?:over\s+)?to\s+(?:the\s+)?courier\s+for\s+delivery', 'Przesyłka wydana do doręczenia'],
            ['handed\s+(?:over\s+)?to\s+(?:the\s+)?(?:courier|carrier)', 'Przesyłka przekazana przewoźnikowi'],
            ['(?:picked\s+up|collected|received)\s+by\s+' . $R, 'Przesyłka odebrana przez odbiorcę'],
            ['(?:dropped\s+off|posted|sent|dispatched|deposited|placed)\s+(?:at|in)\s+' . $L . '\s+by\s+(?:the\s+)?sender', 'Przesyłka nadana w automacie paczkowym'],
            ['(?:dropped\s+off|posted|deposited)\s+(?:at|in)\s+' . $L, 'Przesyłka nadana w automacie paczkowym'],
            ['(?:dropped\s+off|posted|deposited)\s+(?:at|in)\s+' . $P, 'Przesyłka nadana w punkcie'],
            ['(?:placed|delivered|put|stored|waiting)\s+(?:at|in|to)\s+' . $L . '(?:\s+for\s+pick-?up)?', 'Przesyłka czeka na odbiór w automacie paczkowym'],
            ['(?:delivered|placed|stored|waiting)\s+(?:at|in|to)\s+' . $P . '(?:\s+for\s+pick-?up)?', 'Przesyłka czeka na odbiór w punkcie'],
            ['(?:ready|available|waiting)\s+(?:for|to\s+be)\s+(?:pick-?up|collection|collected|picked\s+up)\s+(?:at|in|from)\s+' . $L, 'Przesyłka czeka na odbiór w automacie paczkowym'],
            ['(?:ready|available|waiting)\s+(?:for|to\s+be)\s+(?:pick-?up|collection|collected|picked\s+up)\s+(?:at|in|from)\s+' . $P, 'Przesyłka czeka na odbiór w punkcie'],
            ['(?:ready|available|waiting)\s+(?:for|to\s+be)\s+(?:pick-?up|collection|collected|picked\s+up)', 'Przesyłka czeka na odbiór'],
            ['pick-?up\s+(?:time|period|deadline)\s+(?:has\s+)?(?:expired|elapsed|passed|ended)', 'Minął czas na odbiór przesyłki'],
            ['pick-?up\s+(?:time|period|deadline)\s+(?:has\s+been\s+)?extended', 'Wydłużono czas na odbiór przesyłki'],
            ['(?:out\s+for\s+delivery|released\s+for\s+delivery|with\s+(?:the\s+)?courier\s+for\s+delivery|on\s+(?:its|the)\s+way\s+to\s+(?:the\s+)?' . $R . ')', 'Przesyłka wydana do doręczenia'],
            ['(?:being\s+returned|on\s+(?:its|the)\s+way\s+back)\s+to\s+(?:the\s+)?sender', 'Przesyłka wraca do nadawcy'],
            ['(?:returned|sent\s+back|return(?:ing)?)\s+to\s+(?:the\s+)?sender', 'Przesyłka zwrócona do nadawcy'],
            ['(?:refused|rejected)(?:\s+by\s+' . $R . ')?', 'Odbiorca odmówił przyjęcia przesyłki'],
            ['(?:delivery\s+attempt\s+(?:failed|was\s+unsuccessful|unsuccessful)|unsuccessful\s+delivery(?:\s+attempt)?|failed\s+delivery(?:\s+attempt)?|could\s+not\s+be\s+delivered|not\s+delivered|undelivered)', 'Nieudana próba doręczenia'],
            ['(?:notice\s+(?:has\s+been\s+)?left|advice\s+notice(?:\s+left)?|delivery\s+notice\s+left)', 'Awizo'],
            ['redirected(?:\s+to\s+.+)?', 'Przesyłka przekierowana'],
            ['delayed', 'Przesyłka opóźniona'],
            ['(?:cancell?ed)', 'Przesyłka anulowana'],
            ['lost', 'Przesyłka zaginiona'],
            ['damaged', 'Przesyłka uszkodzona'],
            ['delivered(?:\s+to\s+' . $R . ')?', 'Przesyłka doręczona'],
            ['(?:in\s+transit|on\s+(?:its|the)\s+way)', 'Przesyłka w drodze'],
            ['(?:label\s+(?:has\s+been\s+)?(?:created|generated|printed)|(?:created|registered|prepared)(?:\s+by\s+(?:the\s+)?sender)?|awaiting\s+(?:dispatch|posting|drop-?off|pick-?up\s+by\s+(?:the\s+)?courier)|ready\s+(?:to\s+be\s+|for\s+)?(?:sent|dispatched|shipped|dispatch|shipping))', 'Przesyłka przygotowana przez nadawcę'],
            ['(?:dispatched|sent|shipped|posted)(?:\s+by\s+(?:the\s+)?sender)?', 'Przesyłka nadana'],
        ];
    }

    /**
     * Opis do wyświetlenia. $locale = język strony ('pl' tłumaczy, inny zostawia oryginał).
     * Pusty opis -> pusty wynik (wywołujący decyduje, czy pokazać kod).
     */
    public static function display(string $desc, string $code = '', string $locale = 'pl'): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $desc));
        if ($text === '' || $locale !== 'pl') {
            return $text;
        }
        $pl = self::translate($text);
        if ($pl !== null) {
            return $pl;
        }
        if (self::looksEnglish($text) && isset(self::CODE_PL[strtoupper($code)])) {
            return self::CODE_PL[strtoupper($code)];
        }
        return $text;
    }

    /** Polski odpowiednik znanego angielskiego zdania (z dopiskiem, np. miejscowością) albo null. */
    public static function translate(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace('’', "'", $text)));
        $subject = '(?:(?:the|your)\s+)?(?:parcel|shipment|package|item|consignment)\s+';
        $aux = '(?:(?:has|have|had)\s+been\s+|was\s+|were\s+|is\s+|has\s+)?';
        foreach (self::rules() as [$pred, $pl]) {
            $re = '/^(?:' . $subject . ')?' . $aux . '(?:' . $pred . ')(?<rest>\s*[,:;(\[–—-].*)?\s*\.?$/iu';
            if (preg_match($re, $text, $m)) {
                $rest = trim((string) ($m['rest'] ?? ''));
                $rest = rtrim($rest, '.');
                if ($rest === '') {
                    return $pl;
                }
                return $pl . (preg_match('/^[,:;]/u', $rest) ? '' : ' ') . $rest;
            }
        }
        return null;
    }

    private static function looksEnglish(string $text): bool
    {
        return !preg_match('/[ąćęłńóśźż]/iu', $text)
            && (bool) preg_match('/\b(?:parcel|shipment|package|has\s+been|delivered|delivery|courier|pick-?up|sorting|transit|dispatched|returned|sender|recipient|received|accepted|the)\b/i', $text);
    }
}

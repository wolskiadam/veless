<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Opisy zdarzeń przesyłki po polsku (Allegro One i inni przewoźnicy podają je po angielsku).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\TrackingText;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$cases = [
    // Allegro One (zrzut ekranu ze strony klienta)
    ['Parcel has been dispatched', 'IN_TRANSIT', 'Przesyłka nadana'],
    ['Parcel has been picked up from parcel locker by courier', 'IN_TRANSIT', 'Kurier odebrał przesyłkę z automatu paczkowego'],
    ['Parcel has been accepted at the sorting center', 'IN_TRANSIT', 'Przesyłka przyjęta w sortowni'],
    ['Parcel has been dispatched to its destination point', 'IN_TRANSIT', 'Przesyłka wysłana do punktu docelowego'],
    // inne typowe
    ['Parcel has been sent from the sorting center.', 'IN_TRANSIT', 'Przesyłka wysłana z sortowni'],
    ['Parcel is ready for pickup', 'AVAILABLE_FOR_PICKUP', 'Przesyłka czeka na odbiór'],
    ['Parcel has been placed in the parcel locker', 'AVAILABLE_FOR_PICKUP', 'Przesyłka czeka na odbiór w automacie paczkowym'],
    ['Parcel has been delivered to the pickup point', 'AVAILABLE_FOR_PICKUP', 'Przesyłka czeka na odbiór w punkcie'],
    ['Parcel has been picked up by the recipient', 'DELIVERED', 'Przesyłka odebrana przez odbiorcę'],
    ['Delivered', 'DELIVERED', 'Przesyłka doręczona'],
    ['Shipment is out for delivery', 'RELEASED_FOR_DELIVERY', 'Przesyłka wydana do doręczenia'],
    ['Parcel is being returned to the sender', 'RETURNED', 'Przesyłka wraca do nadawcy'],
    ['Parcel has been returned to sender', 'RETURNED', 'Przesyłka zwrócona do nadawcy'],
    ['Pickup time has expired', 'ISSUE', 'Minął czas na odbiór przesyłki'],
    ['Parcel has been dropped off at the parcel locker', 'IN_TRANSIT', 'Przesyłka nadana w automacie paczkowym'],
    ['Shipment has been created', 'PENDING', 'Przesyłka przygotowana przez nadawcę'],
    ['In transit', 'IN_TRANSIT', 'Przesyłka w drodze'],
    // dopisek (miejscowość) zostaje
    ['Parcel has been accepted at the sorting center: Warszawa', 'IN_TRANSIT', 'Przesyłka przyjęta w sortowni: Warszawa'],
    ['Parcel has been accepted at the sorting center (Stryków)', 'IN_TRANSIT', 'Przesyłka przyjęta w sortowni (Stryków)'],
    // nieznane angielskie zdanie -> ogólny etap z kodu
    ['Parcel has been teleported to Mars', 'IN_TRANSIT', 'Przesyłka w drodze'],
    // nieznane, bez kodu -> oryginał
    ['Parcel has been teleported to Mars', '', 'Parcel has been teleported to Mars'],
    // polskie opisy (InPost, ORLEN) bez zmian
    ['Przyjęta w sortowni', 'IN_TRANSIT', 'Przyjęta w sortowni'],
    ['W transporcie do punktu odbioru', 'IN_TRANSIT', 'W transporcie do punktu odbioru'],
    ['Odebrana przez klienta', 'DELIVERED', 'Odebrana przez klienta'],
    ['', 'IN_TRANSIT', ''],
];
foreach ($cases as [$in, $code, $want]) {
    $got = TrackingText::display($in, $code);
    check($got === $want, "„{$in}” -> „{$want}” (jest: „{$got}”)");
}

// strona klienta po angielsku: oryginał
check(TrackingText::display('Parcel has been dispatched to its destination point', 'IN_TRANSIT', 'en') === 'Parcel has been dispatched to its destination point', 'EN: bez tłumaczenia');

echo "\n{$checks} checks passed\n";

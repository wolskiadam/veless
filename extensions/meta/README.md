# Messenger i Instagram (rozszerzenie `meta`)

Rozmowy z Messengera (strona Facebook) i Instagram Direct w CRM, z tworzeniem zamówienia prosto z rozmowy.

- **Zamówienia → Rozmowy FB i IG**: lista rozmów z obu kanałów (zakładki Wszystkie / Messenger / Instagram, wyszukiwanie
  po osobie i treści, licznik nieprzeczytanych) i rozmowa w dymkach ze zdjęciami. Na telefonie najpierw lista, potem rozmowa.
- **Pobieranie**: cron co 2/5/15/60 minut (ustawienie) i przycisk **↻ Pobierz teraz**. Przy pierwszym pobraniu CRM sięga
  7/30/90 dni wstecz. Strona rozmów sama odświeża się co 2 minuty, gdy nic nie piszesz.
- **Odpowiedź z CRM** w ciągu 24 h od ostatniej wiadomości klienta (zasada Meta). Później pole jest zablokowane, z linkiem
  do Meta Business Suite.
- **+ Utwórz zamówienie**: otwiera Nowe zamówienie z imieniem i nazwiskiem klienta. Z zaznaczonych wiadomości (☐ przy dymku)
  CRM bierze telefon, e-mail, ulicę, kod pocztowy, miasto i kod paczkomatu (wtedy dostawa „InPost Paczkomat”), a ich treść
  wpisuje w „Uwagi do zamówienia”. Bez zaznaczenia szuka danych we wszystkich wiadomościach klienta. Wypełnia tylko puste pola.
- Zamówienie zostaje **połączone z rozmową**: ikona dymka przy statusie na liście zamówień (link do rozmowy), sekcja
  „💬 Rozmowa” na karcie zamówienia z ostatnimi wiadomościami, a w rozmowie lista zamówień z niej.

Ograniczenia Meta: z Instagrama API oddaje treść tylko **20 ostatnich wiadomości** rozmowy, dlatego CRM zapisuje każdą pobraną
wiadomość u siebie (historia rośnie od chwili podłączenia). Rozmowy z folderu „Prośby” nieaktywne 30 dni Meta pomija.
Zdjęcia z wiadomości są pobierane do `storage/uploads/meta/` (linki Meta wygasają) i pokazywane tylko po zalogowaniu.

## Jak podłączyć

### 1. Przygotuj konta

1. Konto Instagram musi być **firmowe albo twórcy** i **połączone ze stroną Facebook**
   (Meta Business Suite → Ustawienia → Konta → Konta na Instagramie).
2. W aplikacji Instagram włącz dostęp do wiadomości dla narzędzi: **Ustawienia → Wiadomości i odpowiedzi na relacje →
   Narzędzia do obsługi wiadomości → Zezwalaj na dostęp do wiadomości**.
3. Strona i konto Instagram powinny być w Twoim **portfolio biznesowym** na [business.facebook.com](https://business.facebook.com).

### 2. Utwórz aplikację Meta

1. Wejdź na [developers.facebook.com/apps](https://developers.facebook.com/apps) i kliknij **Utwórz aplikację**.
2. Wybierz przypadki użycia **„Angażuj klientów na Messengerze”** oraz **„Zarządzaj wiadomościami i treściami na Instagramie”**.
   Gdy pyta o typ aplikacji, wybierz **Firma** i połącz ją ze swoim portfolio biznesowym.
3. Aplikacja może zostać w trybie **programowania**: do własnej strony i własnego Instagrama zwykle to wystarcza.
   Jeśli CRM pokaże błąd uprawnień, Meta wymaga przeglądu aplikacji (App Review) i weryfikacji firmy.

### 3. Wygeneruj token, który nie wygasa

1. Otwórz [ustawienia firmy](https://business.facebook.com/settings) → **Użytkownicy → Użytkownicy systemowi → Dodaj**,
   rola **Administrator**.
2. **Przypisz zasoby**: stronę Facebook (pełna kontrola), konto Instagram i aplikację z kroku 2.
3. Kliknij **Wygeneruj token**: wybierz aplikację, ważność **Nigdy** i uprawnienia `pages_show_list`, `pages_messaging`,
   `pages_manage_metadata`, `pages_read_engagement`, `business_management`, `instagram_basic`, `instagram_manage_messages`.
4. Skopiuj token - Meta pokazuje go tylko raz.

### 4. Wpisz token w CRM

1. **Konfiguracja → Wtyczki → Messenger i Instagram → ⚙ Ustawienia**: wklej token w pole „Token Meta”.
   CRM sam weźmie z niego token strony. Gdy token ma dostęp do kilku stron, wpisz ID strony.
2. Instagram zostaw na **„Przez stronę Facebook”**.
3. Otwórz **Zamówienia → Rozmowy FB i IG** i kliknij **↻ Pobierz teraz**.

> Token strony Facebook (np. z Graph API Explorer) też zadziała, ale zwykły token wygasa po godzinie albo 60 dniach. Osobny token Instagram (logowanie przez Instagram) jest potrzebny tylko wtedy, gdy Instagram nie jest połączony ze stroną: aplikacja Meta → Instagram → Konfiguracja API z logowaniem przez Instagram → Wygeneruj token, a w CRM „Osobny token Instagram”. CRM przedłuża go sam co tydzień.

## Technicznie

- Graph API (`graph.facebook.com`, a przy tokenie Instagram `graph.instagram.com`), wersja z ustawień (domyślnie `v26.0`).
  Token idzie w nagłówku `Authorization`, nie w adresie, więc nie trafia do logów.
- `GET /me/accounts` - token strony z tokenu użytkownika systemowego (zapamiętany zaszyfrowany w `settings`),
  `GET /me/conversations?platform=messenger|instagram` z ostatnimi wiadomościami, `POST /me/messages` z `messaging_type=RESPONSE`.
- Tabele: `ext_meta_conversations`, `ext_meta_messages`, `ext_meta_orders` (zamówienie → rozmowa).
- Haki: `cron.tick`, `orders.rows` + `orders.indicators`, `order_view.sections`, `order_new.form` / `.top` / `.fields` / `.created`.
- Test: `php tests/meta_http.php` (prawdziwe strony panelu z udawanym serwerem Graph API).

Później (etap 2): webhooki Meta zamiast pobierania co kilka minut (wymagają publicznego adresu i przeglądu aplikacji).

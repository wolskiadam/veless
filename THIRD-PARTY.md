# Biblioteki zewnętrzne

PHP nie używa bibliotek z Composera (`composer.json` wymaga tylko PHP i rozszerzeń), więc
aktualizacje bezpieczeństwa PHP i rozszerzeń są po stronie hostingu. Poniżej wszystko, co
pochodzi z zewnątrz. Akcje GitHub i zależności Pythona agenta aktualizuje Dependabot
(`.github/dependabot.yml`); resztę trzeba sprawdzać ręcznie przy wydaniach.

## Pliki wgrane do repozytorium

| Plik | Biblioteka | Wersja | Licencja | Gdzie sprawdzać wydania |
|---|---|---|---|---|
| `public/pack/assets/zxing.min.js` | @zxing/library | 0.21.3 | MIT | https://github.com/zxing-js/library/releases |
| `public/admin/assets/qrcodegen.js` | QR Code generator (Nayuki), wersja TypeScript skompilowana | bez numeru wersji | MIT | https://www.nayuki.io/page/qr-code-generator-library |

## Ładowane z CDN (z sumą SRI)

| Strona | Biblioteka | Wersja |
|---|---|---|
| `public/admin/email_templates.php` | Quill (`quill.js`, `quill.snow.css`) | 2.0.3 |
| `public/admin/order_view.php` | SortableJS (`Sortable.min.js`) | 1.15.2 |

Atrybut `integrity` sprawia, że przeglądarka odrzuci plik, jeśli CDN poda coś innego niż ta wersja.
Przy zmianie wersji policz nową sumę: `npm pack quill@X` i
`openssl dgst -sha384 -binary package/dist/quill.js | openssl base64 -A`.

## Agent drukarki (Python)

`agent-app/requirements.txt` (PySide6, requests, pypdfium2, Pillow, pywin32) - aktualizuje Dependabot.

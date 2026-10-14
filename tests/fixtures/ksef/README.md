# Schemat FA(3) do testów wtyczki KSeF

Oficjalne pliki XSD Ministerstwa Finansów, bez zmian, z repozytorium dokumentacji KSeF 2.0
(https://github.com/CIRFMF/ksef-docs, katalog `faktury/schemy/FA`):

- `schemat_FA(3)_v1-0E.xsd` - faktura FA(3), przestrzeń `http://crd.gov.pl/wzor/2025/06/25/13775/`,
- `bazowe/StrukturyDanych_v10-0E.xsd`, `bazowe/ElementarneTypyDanych_v10-0E.xsd`, `bazowe/KodyKrajow_v10-0E.xsd`.

`tests/ksef.php` kopiuje je do katalogu tymczasowego i podmienia adres importu (`http://crd.gov.pl/...`) na plik lokalny,
żeby walidacja działała bez internetu.

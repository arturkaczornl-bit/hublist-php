# Hublist PHP

Lekka, responsywna strona PHP łącząca publiczne listy hubów Direct Connect. Aplikacja pobiera dane z kilku serwisów, normalizuje XML/JSON, scala wpisy o tym samym adresie i udostępnia wyszukiwarkę bez bazy danych ani Composera.

## Wymagania

- PHP 8.1 lub nowszy
- rozszerzenia PHP `cURL`, `DOM` i `JSON`
- rozszerzenie PHP `bz2` jest opcjonalne; umożliwia pobieranie `dchublist.ru`
- katalog tymczasowy PHP dostępny do zapisu, aby włączać 15-minutowy cache

## Uruchomienie na hostingu

1. Wgraj `index.php` do katalogu strony obsługującego PHP (np. `public_html`).
2. Upewnij się, że hosting ma wymagane rozszerzenia i obsługuje połączenia HTTPS wychodzące.
3. Otwórz adres strony w przeglądarce. Lista odświeża się automatycznie co 15 minut.

Nie są wymagane baza danych, Composer ani zadanie cron. Cache jest zapisywany w katalogu tymczasowym serwera, poza katalogiem publicznej strony. Gdy źródło chwilowo nie odpowiada, aplikacja może wyświetlić jego ostatnią kopię z maksymalnie 24 godzin.

## Źródła

Weryfikacja dostępności: 3 października 2026. Używane są publiczne feedy HTTPS:

- [Team Elite](https://www.te-home.net/?do=hublist&get=hublist.xml) — XML
- [dchublist.org](https://dchublist.org/hublist.xml) — XML
- [Public DC Hublist / PWiAM](https://hublist.pwiam.com/hublist.json) — JSON
- [dchublist.biz](https://dchublist.biz/?do=hublist&get=hublist.xml) — XML
- [dchublists.com](https://dchublists.com/?do=hublist&get=hublist.xml) — XML
- [dchublist.ru](https://dchublist.ru/hublist.xml.bz2) — BZip2 i XML po rozpakowaniu; wymaga rozszerzenia `bz2`

To zestaw znanych i sprawdzonych źródeł, a nie gwarancja znalezienia każdej istniejącej hublisty. Nieaktualne lub niedziałające listy nie są automatycznie włączane. Wpisy i status „online” pochodzą od dostawców; strona nie testuje niezależnie każdego huba.

## Uwagi

- Dane z feedów zewnętrznych są traktowane jako niezaufane i kodowane przed wyświetleniem.
- Lista hubów jest publiczna. Kliknięcie adresu może uruchomić klienta Direct Connect.
- Instalacja rozszerzenia `bz2` jest opcjonalna; pozostałe źródła działają bez niego.

## Licencja

MIT — zobacz plik [LICENSE](LICENSE).

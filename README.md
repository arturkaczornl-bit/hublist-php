# Hublist PHP

Niezależna hublista Direct Connect w PHP i MySQL. Użytkownicy mogą zgłaszać huby, administrator je moderuje, a własny pinger sprawdza zatwierdzone wpisy. Aplikacja ma też edytowalny katalog klientów DC i oprogramowania serwerowego.

## Wymagania

- PHP 8.1 lub nowszy z `PDO MySQL`, `cURL`, `DOM`, `JSON` i `OpenSSL`;
- MySQL 5.7+ lub MariaDB 10.3+;
- hosting Apache z obsługą `.htaccess` (zalecane);
- dostęp do cron oraz połączeń wychodzących HTTPS i TCP;
- dla ADC/ADCS rozszerzenie PHP Hash z algorytmem `tiger192,3`; bez niego pinger jawnie zgłosi brak obsługi tego algorytmu.

Composer ani rozszerzenie BZip2 nie są wymagane.

## Instalacja

1. Wgraj pliki aplikacji do katalogu strony WWW.
2. Utwórz pustą bazę MySQL i osobnego użytkownika z prawami do tworzenia tabel oraz zapisu.
3. Otwórz `https://twoja-domena/setup.php`, podaj dane bazy, nazwę administratora i mocne hasło (co najmniej 14 znaków).
4. Zaloguj się przez `admin.php`, sprawdź wpisy importowane do moderacji i zatwierdź te, które mają być publiczne.
5. Po instalacji usuń `setup.php` z serwera. Zachowaj `config.php` poza publicznym repozytorium; `.htaccess` blokuje bezpośredni dostęp do tego pliku na Apache.

Instalator próbuje zaimportować huby z kilku publicznych źródeł. Zewnętrzne listy mogą być niekompletne lub niedostępne; zaimportowane pozycje zawsze trafiają do kolejki oczekującej, nigdy nie są automatycznie publikowane. Nie są tworzone fikcyjne huby. Możesz też dodawać je ręcznie z panelu.

## Panel i funkcje

- `admin.php` — logowanie, moderacja zgłoszeń, dodawanie, edycja i usuwanie hubów oraz pozycji katalogu, konfiguracja nicka pingera i zmiana hasła administratora;
- `index.php` — wyszukiwanie i filtrowanie, do 30 hubów na stronę, szczegóły, port, kraj, status TLS, ping, uptime i dostępne statystyki;
- publiczny formularz zgłoszenia — zgłoszenie pozostaje ukryte do zatwierdzenia; strona pokazuje maksymalnie pięć najnowszych oczekujących;
- katalog download — linki klientów Direct Connect i serwerów są edytowalne w panelu i prowadzą do wskazanych stron HTTPS;
- `imports.php` — ręczny import dostępnych feedów do moderacji;
- `pinger.php` — skrypt CLI, loguje się pod skonfigurowanym nickiem (bot jest widoczny na hubie), sprawdza NMDC/ADC oraz TLS i zapisuje historię. Huby wymagające hasła lub odrzucające pingera nie będą omijane; ich stan będzie pokazany jako błąd.

Pinger nie gwarantuje danych, których hub nie udostępnia. Brak statystyki pozostaje pusty, nie jest zgadywany. Pinger wymaga publicznego adresu IP huba i blokuje adresy prywatne/lokalne.

## Cron — uruchamianie co 48 minut

Wyrażenie `*/48 * * * *` **nie** oznacza równych odstępów 48 minut w cron. Aby zachować odstęp, dodaj poniższe pięć wpisów (zastąp ścieżkę do PHP i pliku):

```cron
0 0,4,8,12,16,20 * * * /usr/bin/php /sciezka/do/strony/pinger.php --limit=500
48 0,4,8,12,16,20 * * * /usr/bin/php /sciezka/do/strony/pinger.php --limit=500
36 1,5,9,13,17,21 * * * /usr/bin/php /sciezka/do/strony/pinger.php --limit=500
24 2,6,10,14,18,22 * * * /usr/bin/php /sciezka/do/strony/pinger.php --limit=500
12 3,7,11,15,19,23 * * * /usr/bin/php /sciezka/do/strony/pinger.php --limit=500
```

Cron używa strefy czasowej serwera. Ścieżkę do PHP CLI oraz katalogu strony sprawdź w panelu hostingu. `--limit=500` ogranicza maksymalną liczbę hubów w jednym przebiegu; sam przebieg ma dodatkowy limit 240 sekund, więc przy dużej liczbie hubów reszta zostanie sprawdzona w kolejnych uruchomieniach.

## Bezpieczeństwo i diagnostyka

- Zmień hasło administratora po instalacji i używaj HTTPS dla całej strony.
- Nie umieszczaj `config.php` w repozytorium ani nie publikuj jego zawartości.
- Jeśli instalator nie może połączyć się z bazą, sprawdź nazwę bazy/użytkownika, uprawnienia i czy hosting zezwala na połączenia PDO MySQL. Po naprawieniu konfiguracji można ponowić instalację.
- Jeśli pinger nie działa, sprawdź log wyjściowy zadania cron, rozszerzenia OpenSSL/Hash oraz limity połączeń wychodzących hostingu.
- Testy lokalnych parserów i protokołu NMDC uruchomisz poleceniem `php pinger_protocols.php`.

## Licencja

MIT — zobacz plik [LICENSE](LICENSE).

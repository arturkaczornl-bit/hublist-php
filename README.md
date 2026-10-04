# Hublist.pl — polska hublista Direct Connect

Polska hublista Direct Connect pod domenę `hublist.pl`, napisana w PHP i MySQL. Użytkownicy mogą zgłaszać huby, administrator je moderuje, a własny pinger sprawdza zatwierdzone wpisy. Aplikacja ma też edytowalną Pobieralnię klientów DC i oprogramowania serwerowego.

## Wymagania

- PHP 8.1 lub nowszy z `PDO MySQL`, `cURL`, `DOM`, `JSON`, `OpenSSL` i `BZip2`;
- MySQL 5.7+ lub MariaDB 10.3+;
- hosting Apache z obsługą `.htaccess` (zalecane);
- dostęp do cron oraz połączeń wychodzących HTTPS i TCP;
- dla ADC/ADCS rozszerzenie PHP Hash z algorytmem `tiger192,3`; bez niego pinger jawnie zgłosi brak obsługi tego algorytmu.

Composer nie jest wymagany. Rozszerzenie BZip2 jest potrzebne do pobierania skompresowanego feedu `.bz2`.

## Statystyki odwiedzin i blokowanie IP

Panel administratora (`admin.php?tab=visitors`) pokazuje sesje z aktywnością w ciągu ostatnich 5 minut, ich adresy IP i aktualnie przeglądane strony. Aktywna karta odświeża heartbeat co minutę. Historię można przeglądać stronami po 100 wpisów lub filtrować po IP; wpisy starsze niż 30 dni są automatycznie usuwane. Adresy IP są widoczne wyłącznie dla administratora. Ban blokuje dokładny adres IPv4 lub IPv6 na publicznych stronach serwisu i pozostaje aktywny do ręcznego odblokowania; panel administracyjny pozostaje dostępny dla administratora. Blokada może dotknąć wiele osób za wspólnym adresem IP. Pomiar bazuje na sesyjnych plikach cookie i adresie połączenia widzianym przez serwer; liczba sesji nie jest liczbą zweryfikowanych osób. Informacja o tych danych znajduje się w regulaminie.

## Instalacja

1. Wgraj pliki aplikacji do katalogu strony WWW.
2. Utwórz pustą bazę MySQL i osobnego użytkownika z prawami do tworzenia tabel oraz zapisu.
3. Skieruj domenę `hublist.pl` na hosting, włącz HTTPS i otwórz `https://hublist.pl/setup.php`; podaj dane bazy, nazwę administratora i mocne hasło (co najmniej 14 znaków).
4. Zaloguj się przez `admin.php`, sprawdź wpisy importowane do moderacji i zatwierdź te, które mają być publiczne.
5. Po instalacji usuń `setup.php` z serwera. Zachowaj `config.php` poza publicznym repozytorium; `.htaccess` blokuje bezpośredni dostęp do tego pliku na Apache.

Jeśli instalacja przerwała się, a `setup.php` informuje, że znaleziono `config.php`, nie oznacza to, że baza lub tabele zostały utworzone. Wykonaj kopię `config.php` poza katalogiem publicznym, sprawdź w panelu hostingu konfigurację bazy i usuń wyłącznie ten plik konfiguracyjny, aby ponowić instalację. Nie usuwaj bazy danych ani innych plików aplikacji. Nie przesyłaj `config.php` nikomu.

Instalator próbuje zaimportować huby z kilku publicznych źródeł. Zewnętrzne listy mogą być niekompletne lub niedostępne; zaimportowane pozycje zawsze trafiają do kolejki oczekującej, nigdy nie są automatycznie publikowane. Nie są tworzone fikcyjne huby. Możesz też dodawać je ręcznie z panelu.

## Panel i funkcje

- `admin.php` — logowanie, moderacja zgłoszeń, zarządzanie hubami (edycja, usuwanie pojedyncze lub wszystkich, pingowanie pojedyncze lub wszystkich przez kolejkę), Pobieralnią, pozycjami menu, ustawieniami pingera, statystykami aktywnych sesji, historią odwiedzin i blokowaniem adresów IP;
- `index.php` — strona główna z podsumowaniem katalogu, feedem XML, ostatnimi zgłoszeniami i linkami do poszczególnych działów;
- `search.php` — osobna wyszukiwarka hubów z filtrami adresu/IP/portu, protokołu, kraju, statusu, serwera, liczby użytkowników, share i TLS; sortowanie po użytkownikach, share, dostępności, pingu, czasie sprawdzenia lub nazwie;
- `stats.php` — publiczny ranking dostępności hubów w ostatnich 30 dniach z liczbą pomiarów i czasem ostatniego sprawdzenia przez pingera;
- `visitor_ping.php` — odświeżanie aktywności sesji odwiedzającego bez dopisywania kolejnego wejścia do historii;

W zakładce **Menu** administrator może zmieniać etykietę, adres, kolejność i widoczność wbudowanych pozycji, dodawać własne odnośniki oraz usuwać własne pozycje. Dozwolone są lokalne strony PHP i zewnętrzne adresy HTTPS. Zmiany są od razu używane w głównym menu nagłówka.
- `hub.php?id=...` — publiczne szczegóły zatwierdzonego huba: opis i temat odczytany przez pinger, adres/port, protokół, kraj, ikona ustawiana przez administratora, liczba użytkowników, share, ping, TLS, dostępność z 30 dni oraz czas ostatniego sprawdzenia;
- `hub_owner.php?id=...` i `hub_ping.php` — prywatne, ręczne pingowanie przez kod właściciela; po zgłoszeniu kod jest pokazany jednorazowo, przechowywany w bazie wyłącznie jako skrót i działa dla oczekujących oraz zatwierdzonych hubów. Do huba można wysłać jeden pomiar co 5 minut, a z jednego adresu IP jeden pomiar na minutę. Administrator może wygenerować nowy kod w zakładce zarządzania hubami. Kod należy przesyłać i wpisywać wyłącznie przez HTTPS;
- `hublist.xml` i `hublist.xml.bz2` — pobieralny feed zatwierdzonych hubów, do użycia w ustawieniach klienta DC. Warianty bez mod_rewrite są dostępne przez `feed.php?format=xml` i `feed.php?format=bz2`;
- `about.php`, `faq.php` i `rules.php` — informacja o serwisie, przewodnik po Direct Connect i zasady katalogu;
- `add_hub.php` — osobna strona formularza zgłoszenia huba; zgłoszenie pozostaje ukryte do zatwierdzenia, strona główna pokazuje maksymalnie pięć najnowszych oczekujących;
- `download.php` — publiczna Pobieralnia z kategoriami, opisami i linkami zarządzanymi z panelu; administrator może edytować nazwy/opisy kategorii, dodawać nowe kategorie i pozycje, zmieniać linki oraz usuwać wpisy. Kliknięcia przycisków pobierania są liczone i widoczne publicznie oraz w administracji. Licznik odzwierciedla kliknięcia/przekierowania, nie potwierdza zakończenia pobrania z zewnętrznej strony;
- `imports.php` — ręczny import dostępnych feedów do moderacji;
- `pinger.php` — skrypt CLI korzysta z profilu i odstępu pingowania skonfigurowanych w administracji. Nick, opis, wersja klienta DC i e-mail są przekazywane hubom przez NMDC/ADC; typ łącza jest wysyłany w profilu NMDC. Bot jest widoczny na hubie. Pinger sprawdza NMDC/ADC oraz TLS i zapisuje historię. Jeśli kraj nie został ustawiony ręcznie, geolokalizuje publiczny adres IP przez usługę `ipwho.is` i zapisuje kod kraju oraz użyty adres IP. Oznacza to przekazanie adresu IP hosta usłudze geolokalizacyjnej; lokalizacja IP jest przybliżona. Huby wymagające hasła lub odrzucające pingera nie będą omijane; ich stan będzie pokazany jako błąd. Pinger nie obsługuje haseł hubów.

Pinger nie gwarantuje danych, których hub nie udostępnia. Brak statystyki pozostaje pusty, nie jest zgadywany. Pinger wymaga publicznego adresu IP huba i blokuje adresy prywatne/lokalne.

## Feed dla klientów Direct Connect

Na stronie głównej Hublist.pl są dostępne bezpośrednie adresy:

- `https://hublist.pl/hublist.xml` — XML zgodny ze strukturą używaną przez popularne klienty DC;
- `https://hublist.pl/hublist.xml.bz2` — ten sam feed skompresowany BZip2.

Wklej pełny URL jako adres listy hubów w ustawieniach klienta. Jeśli hosting nie ma włączonego mod_rewrite, użyj `https://hublist.pl/feed.php?format=xml` albo `https://hublist.pl/feed.php?format=bz2`. Feed obejmuje wyłącznie zatwierdzone huby.

## Katalog oprogramowania

Katalog zawiera linki do klientów, serwerów hubów, skryptów i narzędzi, m.in. AirDC++, DC++, EiskaltDC++, FlylinkDC++, Jucy, ShakesPeer, ncdc, ApexDC++, TorrentDC++, FearDC, StrongDC++, Open Direct Connect, Verlihub, ADCH++, µHub (uhub), Luadch, Luadch-ng, go-dcpp, YnHub, PtokaX, Octopus DC-Linker i repozytoriów Lua. Zawiera też wpisy historycznych hubsoftów z [archiwalnej listy cstrike.ro](https://www.cstrike.ro/download_dchub_servers.php), w tym ADCHub, Admi Hub, Black DC, DC Galaxy Hub, DCH Pro, Dev Direct Connect, Digital Hub, Direct Connect Hub, Dot Net Hub, Drakes DcPhantom, DSHub, HexHub, LatHack, NAAF DC Hub, Nitro, ODCH Console, Open DC, RDC, SBSoft EA Hub, SDCH, TamilHub Server, Underground DC Hub, V-HuB, X-Hub, XS Hub, Yabba, Yadch, YHub, Zefir HUBsoft++ i ZpoC Room Server. Dopisano również nazwy historyczne przekazane przez administratora: Aquila DC, Verigio — Virtual Network Hub i Hub-Link; nie znaleziono dla nich potwierdzonych plików na wskazanej liście. To katalog informacyjny, nie kopia plików ani gwarantowany spis wszystkich wydań. Wpisy archiwalne nie linkują bezpośrednio do starych plików wykonywalnych; przed pobraniem z zewnętrznego archiwum sprawdź autora, aktualność, licencję, kod i bezpieczeństwo. ncdc jest utrzymywany pasywnie, a ApexDC++, TorrentDC++, StrongDC++ i YnHub są historyczne. Wpis Open Direct Connect ma charakter historyczny i nie wskazuje zweryfikowanego pliku do pobrania. PtokaX ma oficjalną stronę HTTP bez szyfrowania — zachowaj szczególną ostrożność przy pobieraniu z tego źródła.

## Cron i częstotliwość pingowania

W administracji ustaw odstęp (od 5 do 10080 minut) między sprawdzeniami tego samego huba. Aby taki odstęp był egzekwowany niezależnie od granic cron, uruchamiaj skrypt co minutę; za każdym razem zostaną wybrane wyłącznie huby, których termin już nadszedł:

```cron
* * * * * /usr/bin/php /sciezka/do/strony/pinger.php --limit=500
```

Zastąp ścieżkę do PHP i strony wartościami z hostingu. `--limit=500` ogranicza liczbę hubów w jednym przebiegu; sam przebieg ma limit 240 sekund, więc przy dużej liczbie hubów pozostałe zostaną sprawdzone przy następnych uruchomieniach. Panel strony nie może samodzielnie zmienić crona hostingu.

Automatyczny pinger sprawdza zatwierdzone huby i wymaga działającego zadania cron. Bez crona automatyczne ani administracyjne pomiary z kolejki nie będą wykonywane. W panelu administratora można dodać do kolejki jeden hub albo wszystkie huby (w tym oczekujące i odrzucone); pinger opróżnia kolejkę partiami przed zwykłymi pomiarami. Usuwanie pojedynczego wpisu kasuje także jego historię. Usunięcie wszystkich wymaga wpisania frazy potwierdzającej i kasuje wszystkie huby oraz ich historię. Właściciel może też uruchomić pojedynczy pomiar ręcznie w prywatnym panelu kodem wydanym po zgłoszeniu; pomiary ręczne również zapisują wynik i czas ostatniego sprawdzenia.

## Bezpieczeństwo i diagnostyka

- Zmień hasło administratora po instalacji i używaj HTTPS dla całej strony.
- Nie umieszczaj `config.php` w repozytorium ani nie publikuj jego zawartości.
- Jeśli instalator nie może połączyć się z bazą, sprawdź nazwę bazy/użytkownika, uprawnienia i czy hosting zezwala na połączenia PDO MySQL. Po naprawieniu konfiguracji można ponowić instalację.
- Jeśli pinger nie działa, sprawdź log wyjściowy zadania cron, rozszerzenia OpenSSL/Hash oraz limity połączeń wychodzących hostingu.
- Jeśli feed `.bz2` nie działa, upewnij się, że rozszerzenie `bz2` jest aktywne również w PHP używanym przez serwer WWW (nie tylko PHP CLI).
- Testy lokalnych parserów, XML i protokołu NMDC uruchomisz poleceniem `php pinger_protocols.php`.

## Licencja

MIT — zobacz plik [LICENSE](LICENSE).

Kod źródłowy jest udostępniony na warunkach licencji MIT. Odrębne prawa do oryginalnych treści redakcyjnych, logo i projektu graficznego Hublist.pl nie zmieniają licencji ani praw do komponentów osób trzecich.

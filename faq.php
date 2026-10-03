<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
page_start('FAQ — Direct Connect');
?>
<main>
    <section class="hero">
        <h1>FAQ — najczęstsze pytania</h1>
        <p>Podstawy sieci Direct Connect, hubów, udostępniania plików i feedu tej hublisty.</p>
    </section>
    <section class="panel">
        <h2>Co to jest Direct Connect (DC)?</h2>
        <p>Direct Connect to sieć P2P (peer-to-peer). Użytkownicy łączą się z hubem, który umożliwia im odnajdywanie się i rozmowę. Pliki są zazwyczaj przesyłane bezpośrednio między klientami, a nie przez serwer hublisty.</p>
    </section>
    <section class="panel">
        <h2>Czym jest hub i po co jest hublista?</h2>
        <p>Hub to serwer Direct Connect skupiający użytkowników i określający własne zasady. Hublista to katalog adresów hubów; nie jest to hub. Z tej strony można połączyć się z widocznym publicznym wpisem lub zaimportować listę do klienta za pomocą <a href="hublist.xml">XML</a> albo <a href="hublist.xml.bz2">BZip2</a>.</p>
    </section>
    <section class="panel">
        <h2>Jak połączyć się z hubem?</h2>
        <ol>
            <li>Zainstaluj klienta Direct Connect z <a href="download.php?category=client">katalogu programów</a>.</li>
            <li>Dodaj feed XML w ustawieniach list hubów albo kliknij adres huba, jeśli przeglądarka pozwala otworzyć go w kliencie DC.</li>
            <li>W razie potrzeby ustaw nick, skonfiguruj sieć/firewall zgodnie z instrukcją klienta i zaakceptuj zasady huba.</li>
        </ol>
        <p>Huba ADC/ADCS i NMDC/NMDCS mogą obsługiwać różne funkcje. Upewnij się, że klient wspiera protokół wybranego wpisu.</p>
    </section>
    <section class="panel">
        <h2>Co oznacza share, slot i aktywny/pasywny tryb?</h2>
        <p><strong>Share</strong> to pliki i katalogi udostępnione przez użytkownika w kliencie. Hub może ustalać minimalną wielkość lub wymagany rodzaj udostępnienia. Udostępniaj wyłącznie pliki, do których masz prawa lub które wolno Ci rozpowszechniać.</p>
        <p><strong>Slot</strong> to limit równoczesnych transferów wysyłanych przez klienta. Ustawienia limitów i wymaganej liczby slotów zależą od huba i klienta. Większa liczba slotów nie oznacza automatycznie większej szybkości ani bezpieczeństwa.</p>
        <p>W trybie aktywnym inni klienci mogą łączyć się z Tobą; może być potrzebna poprawna konfiguracja routera, NAT i zapory. W trybie pasywnym połączenia przychodzące mogą być ograniczone, dlatego nie wszystkie transfery będą możliwe.</p>
    </section>
    <section class="panel">
        <h2>Co to jest adres, domena i port?</h2>
        <p><strong>Domena</strong> to czytelna nazwa, która wskazuje serwer. <strong>Port</strong> to numer punktu komunikacji usługi, np. `411` dla wielu hubów NMDC albo `1511` dla ADC, lecz operator może wybrać inny. Do połączenia używaj pełnego adresu i portu pokazanych w szczegółach huba.</p>
        <p>Port huba nie jest tym samym co port transferów klienta. Pytania o konfigurację portów klienta kieruj do dokumentacji programu i administratora konkretnego huba.</p>
    </section>
    <section class="panel">
        <h2>Jak dodać hub do tej listy?</h2>
        <p>Użyj formularza <a href="index.php#zglos-hub">„Zgłoś własny hub”</a>. Wpis pozostaje oczekujący do czasu sprawdzenia przez administratora. Podaj prawidłowy protokół, publiczny adres i port oraz informacje, które można publikować.</p>
    </section>
    <section class="panel">
        <h2>Jak dodać feed w kliencie?</h2>
        <p>Otwórz sekcję hublist/list hubów w ustawieniach klienta i dodaj adres XML tej strony: <a href="hublist.xml">hublist.xml</a>. Jeżeli klient obsługuje listy skompresowane BZip2, spróbuj <a href="hublist.xml.bz2">hublist.xml.bz2</a>. Sposób konfiguracji różni się pomiędzy klientami.</p>
    </section>
    <section class="panel">
        <h2>Czemu nie widzę huba lub jego statystyk?</h2>
        <p>Nowe zgłoszenia wymagają zatwierdzenia. Pinger nie ma dostępu do wszystkich informacji: niektóre huby wymagają konta, nie udostępniają statystyk albo blokują bota. Pinger nie próbuje omijać logowania ani zasad huba.</p>
    </section>
    <section class="panel">
        <h2>Jak bezpiecznie udostępniać?</h2>
        <p>Skonfiguruj w kliencie wyłącznie wybrane katalogi, sprawdź, co rzeczywiście jest udostępnione, i usuń z nich dokumenty prywatne, dane logowania, kopie zapasowe oraz pliki systemowe. Nie publikuj cudzych danych ani treści, do których rozpowszechniania nie masz uprawnień. Przestrzegaj prawa i regulaminu huba.</p>
    </section>
</main>
<?php page_end(); ?>

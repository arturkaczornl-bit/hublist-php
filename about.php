<?php
declare(strict_types=1);

require __DIR__ . '/common.php';
page_start('O serwisie Hublist');
?>
<main>
    <section class="hero">
        <h1>O serwisie Hublist</h1>
        <p>Niezależny katalog publicznych hubów sieci Direct Connect oraz źródło feedu XML dla klientów DC.</p>
    </section>
    <section class="panel">
        <h2>Co to jest hublista?</h2>
        <p>Hublista to katalog adresów serwerów hubów Direct Connect. Klient DC może pobrać XML tej strony i wyświetlić wpisy w swojej zakładce listy hubów. Lista pokazuje wyłącznie wpisy zatwierdzone przez administratora.</p>
        <p>Hublista nie jest sama w sobie siecią P2P, klientem ani serwerem huba. Nie przechowuje plików użytkowników i nie pośredniczy w ich pobieraniu.</p>
    </section>
    <section class="panel">
        <h2>Po co powstał serwis?</h2>
        <p>Celem jest zebranie w jednym miejscu informacji o hubach Direct Connect, umożliwienie ich zgłaszania i ręcznej weryfikacji, obserwowanie dostępności oraz udostępnienie ustandaryzowanego feedu XML. Dodatkowy katalog wskazuje klientów, oprogramowanie serwerowe, skrypty i narzędzia.</p>
        <p>Pinger sprawdza zatwierdzone huby jako widoczny użytkownik. Wyniki i statystyki zależą od odpowiedzi danego serwera. Status online nie gwarantuje, że hub spełni konkretne wymagania użytkownika.</p>
    </section>
    <section class="panel">
        <h2>Dodawanie i używanie listy</h2>
        <ol>
            <li>Wybierz klienta DC w <a href="download.php?category=client">katalogu klientów</a> i zainstaluj go ze strony projektu.</li>
            <li>Otwórz ustawienia list hubów w kliencie i dodaj URL feedu XML: <a href="hublist.xml">hublist.xml</a>. Wiele klientów potrafi również pobierać skompresowany feed: <a href="hublist.xml.bz2">hublist.xml.bz2</a>.</li>
            <li>Wybierz hub i połącz się jego adresem. Przestrzegaj zasad danego huba.</li>
        </ol>
        <p>Administratorzy hubów mogą zgłosić wpis przez <a href="index.php#zglos-hub">formularz zgłoszenia</a>. Zgłoszenie będzie widoczne publicznie dopiero po moderacji.</p>
    </section>
</main>
<?php page_end(); ?>

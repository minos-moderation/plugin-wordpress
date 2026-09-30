# Minos — moderacja komentarzy (WordPress)

Wtyczka WordPress, która wysyła każdy nowy komentarz do automatycznej oceny w usłudze
Minos (brama Wergiliusz) i stosuje odesłany werdykt: publikuje komentarz, publikuje go
z zamaskowanymi fragmentami albo zostawia do ręcznej moderacji. Wtyczka niczego nie ocenia
sama i nigdy nie zgaduje werdyktu.

Licencja: GPL-2.0 lub nowsza (plik `LICENSE`).

## Wymagania

- WordPress 6.0 lub nowszy, PHP 7.4 lub nowszy.
- Klucz API w postaci `wgb2b_…` i sekret webhooka — oba wydaje operator usługi Minos.
  Sekret jest pokazywany tylko raz, przy wydaniu klucza; zapisz go od razu.
- Strona dostępna publicznie przez **HTTPS**, pod nazwą domeny. Brama wysyła werdykty
  wyłącznie na adres `https`, na zarejestrowaną nazwę hosta o publicznym adresie IP.

## Instalacja

1. Pobierz paczkę `minos-moderation.zip` z wydania wtyczki. Paczka zawiera katalog
   `vendor/` z biblioteką klienta — kopia samego repozytorium bez niego nie zadziała.
2. W panelu WordPressa: **Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer**, wskaż plik
   zip i kliknij **Zainstaluj**, a potem **Włącz**.
3. Przejdź do **Ustawienia → Minos**, wpisz klucz API i sekret webhooka, sprawdź pozostałe
   ustawienia i zaznacz **Moderacja włączona**.
4. Przekaż operatorowi **adres webhooka** widoczny na stronie ustawień (patrz niżej).

Do czasu włączenia moderacji i zapisania klucza oraz sekretu wtyczka nic nie robi:
komentarze działają tak jak bez niej.

**Wyłączenie moderacji** (odznaczenie **Moderacja włączona**) zatrzymuje wtyczkę
całkowicie: nie wstrzymuje ani nie wysyła nowych komentarzy, nie ponawia wysyłek, nie
stosuje trybu „Gdy brak werdyktu”, a adres webhooka odpowiada HTTP 404, więc werdykty nie
są przyjmowane. Komentarze, które czekały na werdykt, zostają w kolejce moderacji
WordPressa do decyzji człowieka.

## Adres webhooka

Werdykty przychodzą na adres:

```
https://twoja-domena.pl/wp-json/minos/v1/webhook
```

Dokładny adres Twojej strony jest pokazany w **Ustawienia → Minos** (na stronach bez
przyjaznych odnośników ma postać `…/?rest_route=/minos/v1/webhook`). Operator zapisuje go
przy Twoim kluczu — brama nigdy nie wysyła werdyktu pod adres podany w samym zapytaniu.

Jeśli używasz wtyczki bezpieczeństwa, która blokuje REST API dla niezalogowanych, dopuść
trasę `minos/v1/webhook`. Ta trasa nie wymaga logowania: każde doręczenie jest podpisane
sekretem webhooka, a wtyczka odrzuca (HTTP 401) wszystko, czego podpis się nie zgadza albo
różni się od zegara serwera o więcej niż 5 minut.

## Ustawienia

| Ustawienie | Domyślnie | Znaczenie |
|---|---|---|
| Moderacja włączona | wyłączona | Włącza wstrzymywanie i wysyłanie komentarzy. Działa dopiero z zapisanym kluczem i sekretem. Po wyłączeniu wtyczka nic nie robi (patrz wyżej). |
| Adres bramy | `https://gateway.wergiliusz.app` | Zmieniaj tylko na polecenie operatora. Wymagane `https://` (`http://` tylko dla `localhost` — do testów z atrapą bramy). |
| Klucz API | — | `wgb2b_…`. Po zapisaniu strona pokazuje tylko jego początek; puste pole przy zapisie zostawia zapisany klucz. |
| Sekret webhooka | — | Służy wyłącznie do sprawdzania podpisu werdyktów i nigdy nie jest wysyłany. Strona pokazuje tylko jego początek. |
| Profil oceny | `forum_adult` | `forum_adult` — forum dla dorosłych; `forum_teen` — forum z udziałem nastolatków. Klucz musi mieć dostęp do wybranego profilu. |
| Gdy brak werdyktu | fail-closed | Co zrobić z komentarzem bez werdyktu: **fail-open** — opublikować, **fail-closed** — zostawić do ręcznej moderacji. Patrz niżej. |
| Czas oczekiwania na werdykt | 20 min (najmniej 20) | Po tym czasie komentarz bez werdyktu trafia do trybu „Gdy brak werdyktu”. Brama próbuje doręczyć werdykt przez 15 minut, stąd najmniej 20 minut. Werdykt, który nadejdzie później, jest stosowany do komentarza opublikowanego przy fail-open (patrz niżej). |
| Komentarz ocenzurowany | opublikuj wersję zamaskowaną | Opublikować tekst z fragmentami zastąpionymi znakami `█` albo zostawić komentarz do ręcznej moderacji. Oryginał zostaje zachowany w danych komentarza. Komentarz dłuższy niż 3000 znaków zawsze zostaje do moderacji. |
| Komentarz zablokowany | zostaw do ręcznej moderacji | Zostawić do moderacji albo oznaczyć jako spam. |
| Pokazuj informację | włączona | Informacja pod formularzem komentarza (RODO), patrz niżej. |
| Treść informacji | tekst domyślny | Zwykły tekst; puste pole oznacza tekst domyślny. |

## Jak to działa

1. Czytelnik wysyła komentarz. WordPress stosuje najpierw własne reguły (ręczne
   zatwierdzanie, słowa kluczowe, liczba odnośników, filtry antyspamowe), a wtyczka — jako
   ostatnia — **wstrzymuje** komentarz i od razu wysyła go do oceny (co wydłuża wysłanie
   komentarza najwyżej o 10 sekund).
2. Brama przyjmuje komentarz (HTTP 202) i odsyła werdykt później, zwykle w ciągu kilku
   sekund, najpóźniej w ciągu 15 minut, na adres webhooka.
3. Wtyczka sprawdza podpis i stosuje werdykt:
   - **bezpieczne** — publikuje komentarz;
   - **ocenzurowane** — publikuje wersję z zamaskowanymi fragmentami albo zostawia
     komentarz do moderacji (ustawienie); do moderacji trafia też wtedy, gdy brama nie
     odesłała wersji zamaskowanej, gdy moderator zdążył zmienić treść albo gdy WordPress
     nie zapisał wersji zamaskowanej (wtedy w kolumnie **Minos** pojawia się informacja
     o błędzie zapisu) — oryginał nigdy nie jest publikowany zamiast wersji zamaskowanej;
   - **zablokowane** — zostawia do moderacji albo oznacza jako spam (ustawienie);
   - **nieocenione** — tryb „Gdy brak werdyktu”.
4. Werdykt i kategorie (np. `wulgaryzmy`, `spam`) widać w kolumnie **Minos** na liście
   komentarzy.

**Komentarze dłuższe niż 3000 znaków** brama ocenia na podstawie pierwszych 3000 znaków,
więc żaden werdykt nie obejmuje całości. Dlatego werdykt „bezpieczne” traktowany jest jak
„nieocenione” (tryb „Gdy brak werdyktu”: przy fail-closed komentarz zostaje do moderacji
z informacją „wpis dłuższy niż 3000 znaków — oceniono początek”, przy fail-open zostaje
opublikowany), „ocenzurowane” zawsze zostawia komentarz do moderacji, a „zablokowane”
działa jak zwykle.

Zasady, które obowiązują zawsze:

- **Wtyczka nie publikuje komentarza, który WordPress sam by wstrzymał.** Jeśli ustawienia
  dyskusji wymagają ręcznego zatwierdzania, komentarz zostaje w moderacji także po
  werdykcie „bezpieczne” — Minos dodaje kontrolę, nigdy jej nie usuwa.
- **Decyzja człowieka wygrywa.** Jeśli moderator zatwierdził, usunął albo oznaczył
  komentarz jako spam, zanim przyszedł werdykt, wtyczka tylko zapisuje werdykt.
- **Spóźniony werdykt.** Jeśli przy fail-open komentarz został opublikowany bez werdyktu
  (minął czas oczekiwania), a werdykt nadejdzie później, wtyczka go stosuje: „zablokowane”
  przenosi komentarz do moderacji, „ocenzurowane” działa zgodnie z ustawieniem. Nie robi
  tego, jeśli w międzyczasie ktoś zmienił status komentarza albo go edytował.
- **Nie są wysyłane:** komentarze użytkowników, którzy mogą moderować komentarze
  (administratorzy, redaktorzy), komentarze oznaczone już przez WordPressa lub inną
  wtyczkę jako spam albo przeniesione do kosza, a także pingbacki, trackbacki i inne typy
  komentarzy (np. recenzje produktów).
- **Powiadomienia e-mail:** moderator nie dostaje wiadomości „komentarz czeka na
  moderację” o komentarzu, który czeka tylko na werdykt. Wiadomość przychodzi dopiero
  wtedy, gdy po werdykcie komentarz zostaje w moderacji; autor wpisu dostaje jedno
  powiadomienie o opublikowanym komentarzu, tak jak bez wtyczki.
- **Zamaskowany tekst** jest publikowany jako zwykły tekst — bez formatowania
  i odnośników z oryginału.

## Fail-open i fail-closed

Komentarz nie dostaje werdyktu, gdy brama odpowie „nieocenione”, gdy werdykt nie nadejdzie
w czasie oczekiwania albo gdy brama odrzuci komentarz z powodu błędu konfiguracji (np. zły
klucz). Wtedy:

- **fail-closed** (domyślnie) — komentarz zostaje do ręcznej moderacji;
- **fail-open** — komentarz zostaje opublikowany (o ile WordPress sam by go opublikował).

Gdy brama jest chwilowo przeciążona lub niedostępna (HTTP 429 lub 503, brak odpowiedzi),
komentarz czeka, a wtyczka ponawia wysyłkę po czasie wskazanym przez bramę albo po rosnącej
przerwie (1, 2, 4… minuty), aż do upływu czasu oczekiwania.

Ponowienia, limit czasu i powiadomienia e-mail działają przez WP-Cron (co 5 minut). Na
stronach o bardzo małym ruchu albo z wyłączonym WP-Cron (`DISABLE_WP_CRON`) ustaw
systemowe zadanie cron wywołujące `wp-cron.php` — inaczej mogą się opóźniać.

## Co jest wysyłane do bramy, a co nigdy

Wysyłane są wyłącznie:

- identyfikator komentarza w postaci `wp:<numer>` (sam numer, bez treści);
- tekst komentarza bez znaczników HTML, razem z tekstem atrybutów `title` i `alt`
  (czytelnik też go widzi) — **pierwsze 3000 znaków**: dłuższy komentarz jest oceniany na
  podstawie pierwszych 3000 znaków, a dalsza część nie jest oceniana (patrz „Jak to
  działa”);
- wybrany profil oceny;
- sygnały antyspamowe: liczba odnośników w komentarzu, domeny tych odnośników (najwyżej
  10, np. `example.com.pl`, tylko z treści komentarza) i to, czy to pierwszy zatwierdzony
  komentarz autora.

**Nigdy nie są wysyłane:** imię lub pseudonim autora, adres e-mail, adres IP,
identyfikator użytkownika, adres strony autora ani dane przeglądarki. Adres e-mail służy
tylko do lokalnego sprawdzenia „czy to pierwszy komentarz” i nie opuszcza serwera.

Klucz API trafia wyłącznie do bramy (nagłówek `X-Gateway-Key`). Klucz i sekret nigdy nie
pojawiają się w dzienniku ani w komunikatach — strona ustawień pokazuje tylko ich
początek.

## Informacja pod formularzem (RODO)

Pod formularzem komentarza wtyczka pokazuje informację (domyślnie):

> Komentarze są automatycznie sprawdzane przez usługę Minos. Aby ocenić komentarz,
> przesyłamy do niej wyłącznie jego treść — bez imienia, adresu e-mail i adresu IP. Treść
> nie jest tam przechowywana po zakończeniu oceny.

Tekst można zmienić albo wyłączyć w ustawieniach. Nie jest pokazywany osobom, których
komentarze nie są wysyłane (moderatorom). Uzupełnij też politykę prywatności strony
o informację o automatycznej ocenie komentarzy.

## Sygnał wsparcia

Gdy komentarz wskazuje na możliwe samookaleczenie, brama dołącza sygnał wsparcia —
niezależnie od werdyktu (komentarz może zostać opublikowany). Wtyczka zaznacza go
w kolumnie **Minos** i pokazuje powiadomienie na liście komentarzy. **Wtyczka nie może
skontaktować się z autorem** (brama nie otrzymuje żadnych danych kontaktowych) — to Ty
decydujesz, czy odpowiedzieć, np. z informacją o telefonie zaufania 116 123 (dorośli) lub
116 111 (dzieci i młodzież).

## Dziennik i błędy

Strona **Ustawienia → Minos** pokazuje ostatnie błędy: czas, kod HTTP, kod błędu bramy
i numer komentarza — nigdy treść. Gdy brama odrzuca komentarze z powodu konfiguracji
(np. `brak_klucza`, `brak_webhooka`, `profil_niedozwolony`), w panelu pojawia się
komunikat z podpowiedzią; znika po pierwszym przyjętym komentarzu.

## Wyłączenie i usunięcie

- **Wyłączenie moderacji** w ustawieniach zatrzymuje wtyczkę (patrz „Instalacja”).
- **Dezaktywacja** zatrzymuje zadania WP-Cron. Komentarze czekające na werdykt zostają
  w moderacji, ustawienia zostają zachowane.
- **Usunięcie** wtyczki kasuje jej ustawienia (w tym klucz i sekret), dziennik, zadania
  i wszystkie dane `_minos_*` przy komentarzach. Komentarze zostają: opublikowana wersja
  zamaskowana pozostaje zamaskowana (oryginał znika razem z danymi wtyczki), a czekające —
  w moderacji.

## Dla programistów

Budowanie, testy i praca z atrapą bramy: [`docs/development.md`](docs/development.md).

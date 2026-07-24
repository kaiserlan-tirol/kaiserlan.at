# Pizzabestellungs-Modul — Umsetzungsplan (build-with-codex)

## Ziel

Registrierte User können während eines Zeitfensters Pizzen bestellen. Jede Pizza
reduziert sofort das Catering-Guthaben (`deductUserCredit`) mit Notiz
`Pizza: {name}`. Erreichbar in **zwei Kontexten**: Selbstbedienung (eingeloggter
User, Site-Layout) und Kassa-Terminal (gescannter User, Kiosk-Layout). Same look
and feel wie die normale Catering-Bestellung (`order.html.twig`).

## Gefällte Entscheidungen (verbindlich)

1. **Kontext**: beide — eine `userId`-fähige Pizza-Seite, erreichbar aus der Kassa
   (gescannter User) und aus dem Profil/Catering-Dashboard (eingeloggter User).
2. **Persistenz**: kein neues Entity, keine Migration. Settings-Blob.
3. **Buchung**: **deduction-only** über `CateringService::deductUserCredit(user, betrag, null, note)`.
   Kein `CateringOrder`. Pro Pizza-Zeile eine Transaktion (Betrag = Menge × Preis),
   sofortige Guthaben-Reduzierung.
4. **Editierbar**: eine "Bestellung" pro User im Fenster, editierbar via
   **Storno + Neu** beim erneuten Absenden. Menge 0 = stornieren.
5. **Guthaben-Deckung**: **keine** Prüfung (verhält sich wie normale offene
   Bestellung; negatives Guthaben wird später abgerechnet).
6. **Fenster**: `offen ab` = bestehendes Setting **`lan.party.start`**;
   `möglich bis` = neues Setting **`pizza.order_open_until`** (DateTimeLocal).
   Server-seitig hart durchgesetzt **und** Button nur im offenen Fenster sichtbar.
7. **Wahrheitsquelle für die Auswahl**: **nur der Ledger** (`UserTransaction`,
   Kategorie `catering`). Die aktuelle Auswahl = Netto der nicht-stornierten
   `Pizza:`-Transaktionen im Fenster. Keine per-User-Settings.
8. **Admin**: **generische Settings-UI wiederverwenden** — `pizza.list` (TEXTAREA)
   und `pizza.order_open_until` (DateTimeLocal) in der Registry registrieren →
   erscheinen automatisch als Gruppe „pizza“ unter _Einstellungen_. Keine eigene
   Admin-Seite, kein Sidenav-Eintrag.
9. **Button-Label**: Wochentag fix „Samstag“, Uhrzeit dynamisch aus
   `pizza.order_open_until`: `Pizzabestellung für Samstag, bis {HH}:00`.
10. **Preisformat** in `pizza.list`: Euro mit Dezimal (`8,50` oder `8.50`),
    Parser rechnet in Cent um. Fehlerhafte Zeilen werden beim Parsen übersprungen.

## Settings (registrieren in `SettingService::TEXT_BLOCK_KEYS`)

```php
'pizza.list' => [
    self::TB_DESCRIPTION => 'Pizzen, je Zeile: name;beschreibung;preis (Preis in Euro, z. B. 8,50)',
    self::TB_TYPE => SettingType::TEXTAREA,
],
'pizza.order_open_until' => [
    self::TB_DESCRIPTION => 'Pizzabestellung möglich bis (Deadline, Samstag)',
    self::TB_TYPE => SettingType::DateTimeLocal,
],
```

`offen ab` = bereits vorhandenes `lan.party.start` (kein neues Feld).

## Neuer Service: `src/Service/PizzaService.php`

Abhängigkeiten: `CateringService`, `SettingService`, `UserTransactionRepository`.

- `getPizzas(): array`
  Parst `pizza.list`. Je nicht-leerer Zeile `name;description;price` →
  `['name' => string, 'description' => string, 'price' => int /*Cent*/]`.
  Preis-Parsing: `,`→`.`, `(float)`, `round(*100)`. Zeilen ohne genau 3 Teile
  oder mit nicht-numerischem Preis werden **übersprungen** (kein Fehler).
  Beschreibung darf leer sein. Rückgabe indexstabil (Index = Zeilennummer der
  gültigen Zeilen).

- `getDeadline(): ?\DateTimeImmutable`
  `pizza.order_open_until` parsen (`new \DateTimeImmutable($value)`), sonst `null`.

- `getOpenFrom(): ?\DateTimeImmutable`
  `lan.party.start` parsen, sonst `null`.

- `isOrderingOpen(): bool`
  `true`, wenn `getOpenFrom()` und `getDeadline()` gesetzt, `getPizzas()` nicht
  leer und `now ∈ [openFrom, deadline]`.

- `getCurrentSelection(User $user): array`
  Rekonstruiert die aktuelle Auswahl aus dem Ledger im Fenster
  `[openFrom, deadline]`:
  - Hole alle `UserTransaction` des Users mit `category = catering` und
    `createdAt` im Fenster (siehe Repository unten).
  - Deduktionen: `description` beginnt mit `Pizza: ` → parse `(name, qty)`.
  - Stornos: `description` beginnt mit `Storno Pizza: ` → parse `(name, qty)`.
  - Netto pro Name: `qty_net = Σ deduktion.qty − Σ storno.qty`; Betrag-Netto
    (negativ = noch offen) = `Σ betrag` der zugehörigen Transaktionen.
  - Rückgabe: pro Name `['qty' => qty_net, 'owed' => amount_net]` für `qty_net > 0`.
  - Notiz-Parsing: Suffix ` (×N)` optional; fehlt es → `qty = 1`. Name = Rest nach
    Prefix.

- `bookOrder(User $user, array $qtyByIndex): void`
  - Guard: wenn `!isOrderingOpen()` → nichts buchen, Exception werfen
    (Controller fängt und zeigt Flash-Fehler).
  - **Storno**: für jeden aktuell live gebuchten Namen (`getCurrentSelection`)
    `CateringService::addUserCredit(user, |owed|, "Storno Pizza: {name}"
[+ " (×{qty})" wenn qty>1])`. Reversiert exakt den im Ledger stehenden Betrag
    (preis-drift-sicher).
  - **Neu buchen**: für jeden Index mit `qty > 0` (Pizza aus `getPizzas()[index]`):
    `CateringService::deductUserCredit(user, qty*price, null, "Pizza: {name}"
[+ " (×{qty})" wenn qty>1])`.
  - Reihenfolge: erst komplett stornieren, dann neu buchen (Netto = neue Auswahl).

## Repository: `src/Repository/UserTransactionRepository.php`

Neue Methode (Rekonstruktion darf nicht das 20er-Limit von
`getUserTransactionHistory` treffen):

```php
public function findCateringPizzaTransactions(
    UuidInterface $user, \DateTimeImmutable $from, \DateTimeImmutable $to
): array
```

Query: `user = :user AND category = 'catering' AND createdAt BETWEEN :from AND :to
AND (description LIKE 'Pizza:%' OR description LIKE 'Storno Pizza:%')`,
sortiert nach `createdAt ASC`.
(Alternativ akzeptabel: `findByUser` + Filter in PHP, falls einfacher.)

## Twig-Extension: `src/Twig/PizzaExtension.php`

Muster wie `EnumExtension`/`ContentExtension` (`getFunctions()` → `TwigFunction`).
Backed by `PizzaService`, damit die Buttons in bestehenden Templates ohne
Controller-Änderung sichtbar geschaltet werden:

- `pizza_ordering_open(): bool` → `PizzaService::isOrderingOpen()`
- `pizza_deadline(): ?\DateTimeImmutable` → `PizzaService::getDeadline()`

## Controller

### Selbstbedienung — `src/Controller/Site/CateringController.php` (neue Action)

```php
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
#[Route(path: '/pizza', name: '_pizza', methods: ['GET', 'POST'])]  // catering_pizza
public function pizza(Request $request): Response
```

- User = `getUser()->getUser()`.
- Guard: `!PizzaService::isOrderingOpen()` → Flash-Warnung + Redirect `catering_credit`.
- POST: `cart = $request->request->all('cart')` (index→menge) →
  `PizzaService::bookOrder(user, cart)` → Flash success → Redirect `catering_credit`.
- GET/Render: `pizzas`, `selection` (aus `getCurrentSelection`, gemappt auf Index),
  `action = path('catering_pizza')`, `deadline`. Template `site/catering/pizza.html.twig`.

### Kassa — `src/Controller/Site/CateringKassaController.php` (neue Action)

```php
#[Route('/pizza/{userId}', name: 'pizza', methods: ['GET', 'POST'])]  // catering_kassa_pizza
public function pizza(Request $request, string $userId): Response
```

- User = gescannter User (UUID-Auflösung wie in `products()`), kein `IsGranted`
  (Kiosk, wie die übrigen Kassa-Routen).
- Guard/POST wie oben; Redirect nach POST → `catering_kassa_products` mit `userId`.
- Template `site/catering/kassa/pizza.html.twig`.

## Templates

- **`templates/site/catering/_pizza_form.html.twig`** (neues Partial, geteilt)
  Params: `pizzas`, `selection` (index→menge), `action` (Form-URL), `deadline`,
  optional `current_credit`. Aufbau analog `order.html.twig`: Karten je Pizza,
  Number-Input `name="cart[{{ loop.index0 }}]"` mit `value` aus `selection`,
  Gesamtbetrag-Anzeige (JS wie in `order.html.twig`), Submit.
  Hinweistext mit Deadline: „… bis Samstag, {{ deadline|date('H') }}:00“.

- **`templates/site/catering/pizza.html.twig`** (neu) — `extends siteBase`,
  `{% include '_pizza_form' %}`, Überschrift „Pizza bestellen“.

- **`templates/site/catering/kassa/pizza.html.twig`** (neu) — `extends kassa/base`,
  User-Header (nickname + Guthaben) + Zurück-Button zu `catering_kassa_products`,
  `{% include '_pizza_form' %}`.

- **`templates/site/catering/kassa/products.html.twig`** (Button ergänzen)
  Nach dem User-Info-Header:

  ```twig
  {% if pizza_ordering_open() %}
    <a href="{{ path('catering_kassa_pizza', {userId: user.uuid}) }}" class="btn btn-primary btn-block" style="border-radius: 8px;">
      <i class="fas fa-pizza-slice"></i>
      Pizzabestellung für Samstag, bis {{ pizza_deadline()|date('H') }}:00
    </a>
  {% endif %}
  ```

- **`templates/site/catering/credit.html.twig`** (Button ergänzen, „Catering Dashboard“)
  Im oberen Action-Bereich, analog, aber `href="{{ path('catering_pizza') }}"`.

## Nicht-Ziele / Annahmen

- Kein Aggregat-/Küchen-Überblick (die `Pizza:`-Transaktionen bzw. die
  Admin-Financial-Ansicht sind der Nachweis). Falls gewünscht: separates Ticket.
- Keine E-Mail (kein `CateringOrder`; `emailOrder` wäre ohnehin No-op).
- Pizzen sind nie kostenlos (keine Addon-/Flatrate-Logik) — voller Preis für alle,
  inkl. U18 (Guthaben wird wie bei allen reduziert).
- Pizza-Namen gelten als eindeutig (Identifikation über den Namen). Doppelte Namen
  werden zusammengeführt.
- Zwischenzeitlich aus `pizza.list` entfernte, aber bereits bestellte Pizzen werden
  beim nächsten Absenden storniert; im Formular nicht mehr als Zeile vorbefüllbar.

## Commits

Format `type: subject` (konventionell, **kein** Jira-Scope — das Projekt hat kein
commitlint/husky; siehe bisherige History `feat: …`). Kein `php-cs-fixer` (nicht
installiert). In logische Häppchen splitten:

1. `feat: add pizza settings and PizzaService`
   — `SettingService` (2 Keys), `src/Service/PizzaService.php`,
   `UserTransactionRepository::findCateringPizzaTransactions()`.
2. `feat: add pizza ordering pages for self-service and kassa`
   — `PizzaExtension`, `CateringController::pizza()`, `CateringKassaController::pizza()`,
   Templates `_pizza_form.html.twig`, `pizza.html.twig`, `kassa/pizza.html.twig`.
3. `feat: add pizza order button to kassa and catering dashboard`
   — Buttons in `products.html.twig` + `credit.html.twig`.

**Nur die in „Geänderte/neue Dateien“ gelisteten Pizza-Dateien** committen. Die
übrigen Arbeitskopie-Änderungen (`.ai/general/*`, `.github/copilot-instructions.md`,
`.ai/plans/2026-07-23-platzkarten-pdf.*`) sind unzusammenhängend und dürfen NICHT
mit ins Commit — gezielt `git add` der Pizza-Dateien (kein `git add -A`).

## Automatische Verifizierung (vor jedem Commit grün)

Läuft auf dem Host (PHP 8.4, `bin/console`); die DB kommt aus docker-compose.
Harmlose Sentry-Deprecation-Warnungen ignorieren.

```bash
php bin/console lint:twig templates/site/catering
php bin/console lint:container      # prüft Autowiring/Typen von PizzaService + PizzaExtension
php -l src/Service/PizzaService.php  # Syntax jeder geänderten/neuen PHP-Datei
```

Keine Tests (per `.ai/general/instructions.md`).

## Verifizierung (manuell, docker-compose)

1. Setting `lan.party.start` in der Vergangenheit, `pizza.order_open_until` in der
   Zukunft, `pizza.list` mit ein paar `name;desc;preis`-Zeilen (inkl. einer
   fehlerhaften → wird ignoriert).
2. `/catering/pizza`: Pizzen sichtbar, Preise korrekt in Cent umgerechnet.
   Bestellen → Guthaben um Summe reduziert, Transaktionen `Pizza: {name}` im Ledger.
3. Erneut `/catering/pizza`: Mengen vorbefüllt. Menge ändern/0 setzen → Storno-Buchungen
   `Storno Pizza: {name}` + neue Buchungen; Netto-Guthaben = neue Auswahl.
4. Kassa: QR/Nickname scannen → `products` → Button sichtbar → Pizza-Seite für den
   gescannten User → Buchung auf dessen Guthaben.
5. `pizza.order_open_until` in die Vergangenheit setzen → Button verschwindet in
   beiden Kontexten; direkter POST auf `/catering/pizza` wird abgelehnt (Flash-Fehler).

## Geänderte/neue Dateien

| Datei                                              | Änderung                          |
| -------------------------------------------------- | --------------------------------- |
| `src/Service/SettingService.php`                   | 2 Registry-Keys ergänzen          |
| `src/Service/PizzaService.php`                     | **neu**                           |
| `src/Repository/UserTransactionRepository.php`     | `findCateringPizzaTransactions()` |
| `src/Twig/PizzaExtension.php`                      | **neu**                           |
| `src/Controller/Site/CateringController.php`       | Action `pizza()`                  |
| `src/Controller/Site/CateringKassaController.php`  | Action `pizza()`                  |
| `templates/site/catering/_pizza_form.html.twig`    | **neu**                           |
| `templates/site/catering/pizza.html.twig`          | **neu**                           |
| `templates/site/catering/kassa/pizza.html.twig`    | **neu**                           |
| `templates/site/catering/kassa/products.html.twig` | Button                            |
| `templates/site/catering/credit.html.twig`         | Button                            |

Keine DB-Migration.

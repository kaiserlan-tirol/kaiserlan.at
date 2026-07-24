# Platzkarten für alle Teilnehmer

4 A6 Platzkarten pro A4 Seite. Design wie `./2026-07-23-platzkarten-pdf.png`.
PDFs gruppiert pro Block (= `sector`). Je ein mehrseitiges PDF für Block 1 bis N.

## Entscheidungen (Grill-Session 2026-07-23)

### Rendering
- **HTML-Druckansicht**, kein Server-PDF. Eigenständige HTML-Seite mit
  `@page { size: A4; margin: 10mm }` + `@media print`, Admin druckt via Browser
  „Als PDF speichern". Muster: `templates/api/catering/labels.html.twig` /
  `CateringApiController::generateLabels`.
- Kein neues Composer-Dependency.

### Route / Controller / Template
- Neue Action in `src/Controller/Admin/SeatmapController.php`, analog zu `/export`:
  `#[Route(path: '/platzkarten', name: '_platzkarten', methods: ['GET'])]`
  → `admin_seatmap_platzkarten`, optionaler Query-Param `?sector=X`
  (ohne Param = alle Blöcke).
- Eigenständiges Template `templates/admin/seatmap/platzkarten.html.twig`
  (kein Admin-Layout, volles `<!DOCTYPE html>`, wie die Catering-Seite).
- `<title>Platzkarten Block {{ sector }}</title>` → sinnvoller PDF-Dateiname-Vorschlag.
- Zugriff: `ROLE_ADMIN_SEATMAP` (bereits am Controller).

### Datenquelle & Sitz-Auswahl
- **Alle Sitze mit gesetztem Owner** (bezahlt UND unbezahlt), damit u18/Gruppen-
  Teilnehmer nicht fehlen. Query: `SeatRepository::findTakenSeats()`
  (sortiert bereits nach `sector`, dann `seatNumber`), bei gesetztem `?sector=`
  danach filtern.
- Owner → User via `SeatmapService::getSeatedUser($seats)` (Preloading, kein N+1).
- Sitze mit nicht-auflösbarem Owner (gelöschter IDM-User) werden übersprungen.

### Kartenfelder
- **Dynamisch:**
  - NAME = `getSeatedUser(seat).getNickname()` (z. B. `mathi1993`). `Seat.name` wird ignoriert.
  - Platz = `{{ seat.sector }}-{{ seat.seatNumber }}` (z. B. `5-97`).
- **Konfigurierbar (SettingService):**
  - `lan.platzkarten.text` (Typ `HTML`) — kompletter Information-/Infrastruktur-Block,
    Ausgabe mit `| raw`. Neuer Eintrag in der Settings-Map in
    `src/Service/SettingService.php`.
  - `lan.platzkarten.sponsors_image` (Typ `File`) — fertiger Sponsor-Streifen als Bild,
    Upload im Admin (wie `lan.seatmap.bg_image`). Fehlt das Bild → nichts rendern.
    Grund für statisch: in der DB liegen pro Sponsor **Banner**, nicht die sauberen
    Einzel-Logos (Feld heißt „logo", enthält aber Banner); Subway/Alerto/Huber/
    Alles EDV fehlen ohnehin im Repo.
- **Hardcoded im Template:** „Willkommen auf der", KaiserLAN-Logo
  (`asset('images/KaiserLAN-Logo/KaiserLAN_horizontal_black_1500px.png')` bzw. SVG),
  Labels „NAME:" / „Platz:".

### Block-Auswahl (UI)
- Dropdown in der bestehenden Button-Leiste in
  `templates/admin/seatmap/index.html.twig` (~Zeile 22, neben CSV-Export/Druckansicht).
- Einträge = distinct `sector` der belegten Sitze (neue kleine Repo-Methode
  `findDistinctSectors()`), sortiert, plus Eintrag „Alle Blöcke".
- Links öffnen `admin_seatmap_platzkarten?sector=X` mit `target="_blank"`.

### Layout
- A4 hochkant, 10 mm Rand ringsum → Druckfläche 190×277 mm.
- 2×2-Grid, 4 Karten à ~95×138,5 mm (Karte selbst im Hochformat wie Mockup).
- **Lückenlos**, dünner Hairline-Rahmen pro Karte als Schnittkante.
- „Alle Blöcke": jeder neue `sector` startet via `page-break-before: always`
  auf frischer A4-Seite; letzte Seite eines Blocks darf leere Zellen haben.

### Druckauslösung
- no-print-Leiste mit „Als PDF speichern"-Button (`window.print()`) + Kurzhinweisen
  (A4, 100 %/tatsächliche Größe, Hintergrundgrafiken/-farben aktiv). Kein Auto-Print.
- `-webkit-print-color-adjust: exact; print-color-adjust: exact;` für Logo, Rahmen,
  Sponsor-Bild.

## Voraussetzungen / manuell bereitzustellen
- Sponsor-Streifen als Bild erzeugen und unter `lan.platzkarten.sponsors_image` hochladen.
- `lan.platzkarten.text` mit dem Info-/Infra-Inhalt befüllen (inkl. IPs, „PW: lan").

## Risiken
- Inhaltsdichte: Logo + NAME + Platz + Info-Bullets + Infra + Sponsoren auf ~A6 ist
  eng; Schriftgrößen/Abstände am echten Inhalt feinjustieren.

## Betroffene Dateien
- `src/Controller/Admin/SeatmapController.php` (neue Action)
- `src/Repository/SeatRepository.php` (`findDistinctSectors()`)
- `src/Service/SettingService.php` (2 neue Settings)
- `templates/admin/seatmap/platzkarten.html.twig` (neu)
- `templates/admin/seatmap/index.html.twig` (Dropdown)

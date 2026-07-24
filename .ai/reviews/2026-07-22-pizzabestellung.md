# Code-Review — Pizzabestellungs-Modul (`feat/pizzabestellung` vs `develop`)

Review-Methode: 4 Finder-Dimensionen (Korrektheit, Security, Qualität, Plan-Konformität),
jeder Fund adversarial verifiziert. 10 Roh-Findings → **8 bestätigt** (3 Medium, 5 Low).
**Keine Critical/High.**

## Bestätigte Findings (nach Schweregrad)

| # | Sev | Datei | Thema | Entscheidung |
|---|-----|-------|-------|--------------|
| 1 | Medium | `PizzaService.php:134` | 0-/Negativ-Preis → uncaught `InvalidArgumentException` mitten in `bookOrder` (nach committeten Stornos) → HTTP 500 + inkonsistentes Ledger | **FIX** |
| 3 | Medium | `PizzaService.php:132` | (gleiche Ursache wie #1, Error-Handling-Sicht) | **FIX** (durch #1) |
| 2 | Medium | `PizzaService.php:116` | Kein Lock/Idempotenz um read-storno-rebook → paralleler/Doppel-POST doppelt belastet | **MITIGATE** (Submit-Disable) + Restrisiko dokumentiert |
| 4 | Low | `CateringController.php:126` | Doppelte Pizza-Namen vervielfachen die Bestellung bei jedem Re-Save | **FIX** (Namen deduplizieren) |
| 5 | Low | `PizzaService.php:131` | cart-Indizes nicht gegen gerenderte Liste validiert; Admin editiert `pizza.list` zwischen Render und Submit → falsche Pizza | **AKZEPTIERT** (sehr unwahrscheinlich) |
| 6 | Low | `CateringController.php:111` | Self-Service-POST ohne CSRF-Schutz | **FIX** (Token + Validierung; Kassa bleibt Kiosk) |
| 7 | Low | `CateringKassaController.php:263` | Prefill-Loop wortgleich in beiden Controllern dupliziert | **FIX** (`getSelectionByIndex`) |
| 8 | Low | `PizzaService.php:89` | Toter `$multiplier = 0;`-Init | **FIX** |

## Umgesetzte Fixes

1. **#1/#3** — `getPizzas()` überspringt Zeilen mit Preis ≤ 0 (wie fehlerhafte Zeilen). Entfernt Crash + Ledger-Inkonsistenz an der Wurzel.
2. **#4** — `getPizzas()` dedupliziert Namen (erster gewinnt, Folgezeilen mit gleichem Namen übersprungen). Damit ist die namensbasierte Rekonstruktion eindeutig.
3. **#7** — `PizzaService::getSelectionByIndex(User): array` (index⇒menge); beide Controller nutzen sie statt der duplizierten Schleife.
4. **#6** — CSRF-Token in `_pizza_form.html.twig`; `CateringController::pizza` validiert `isCsrfTokenValid('pizza_order', …)` beim POST. Kassa-Kiosk unverändert (konsistent mit bestehendem Kassa-Checkout, der ebenfalls kein CSRF hat).
5. **#8** — toter `$multiplier = 0;`-Init entfernt.
6. **#2** — Submit-Button wird beim Absenden deaktiviert (verhindert versehentlichen Doppel-Tap, der häufigste Auslöser).

## Bewusst nicht umgesetzt (Restrisiko, akzeptiert)

- **#2 (voll)** — Serverseitiges pessimistisches Locking über read-storno-rebook. `TransactionService::addManualCredit` öffnet je Aufruf eine eigene Transaktion (beginTransaction/commit), eine äußere Transaktion würde verschachteln; eine saubere Lösung bräuchte die Symfony-Lock-Komponente. Für dieses Feature überengineered; Effekt ist geringfügig und selbstheilend (nächste Bearbeitung korrigiert). Submit-Disable deckt den realistischen Fall ab.
- **#5** — Stabile Pizza-IDs im Formular gegen Live-Edit der Liste während einer offenen User-Session. Sehr unwahrscheinlich (Admin editiert die Liste, während ein User genau das Formular offen hat), Low.

## Positiv

- Index-Konsistenz (cart-Keys ↔ Pizza-Indizes ↔ Prefill) durchgängig korrekt.
- Storno/Neu-Vorzeichen und Betragsrekonstruktion aus dem Ledger korrekt.
- Twig-Ausgaben auto-escaped (kein `|raw`), Repository-Query parametrisiert (kein SQLi).
- Umfang exakt die 11 geplanten Dateien, keine Migration, Lints grün.

# AP-20261007-zeiterfassung-abgleich: Zeiterfassung – Auswertungen und Anzeigen abgleichen

| Feld | Wert |
|---|---|
| Status | abgeschlossen |
| Typ | Bugfix · Refactoring |
| Testläufe rot | 0 |
| Review-Runden | 2 |
| Commit | Release v3.0.12 |

## 1. Auftrag

Nutzer (2026-10-07): „Komplette Zeiterfassung mit allen Auswertungen und Anzeigen abgleichen und prüfen.
Regeln und Struktur berücksichtigen. Mögliche Besserungen aufzeigen und nach AGENTS.md planen.“

Anlass: In v3.0.11 fiel auf, dass die Jahresansicht der Wochenplanung „Sollzeit je Wochentag“ nicht
kannte, weil `list_users`/`list_users_basic` die Felder nicht lieferten – ein Abgleich aller
Soll-/Ist-/Saldo-Anzeigen ist nötig.

## 2. Plan

Erstellt nach `architekt.agent.md` (E-066: Rolle vom Leitstand mit vier parallelen Explore-Analysen
Backend/Cron, Desktop, Mobil, Tests/Doku ausgeführt). Alle Befunde „Hoch“/„Mittel“ wurden vom Leitstand an der
Fundstelle geprüft (E-072); widerlegte Meldungen stehen in Teil A.3.

### Teil A – Bestandsaufnahme

**A.1 Rechenstellen (Stand v3.0.11).** Soll/Ist laufen fast überall über die zentrale Tagesfunktion
(`Sollzeit::tagesSoll/istStundenEintrag` bzw. `bkTagesSoll/bkIstStundenEintrag`, E-041):

| Bereich | Stellen | Soll/Ist zentral? | Abweichungen (siehe A.2) |
|---|---|---|---|
| Backend | `ZeiterfassungActions` `save`, `adminAddEntry`, `computeGleitzeitSaldo`, `getJahreswechselData` | ja | B3, B4, B6, M1 |
| Monats-Mail | `cron_stundenauswertung_email.php` | ja | B2 |
| Stunden-Erinnerung | `cron_stunden_erinnerung.php` | – | B1 |
| Desktop Stundenerfassung | `renderSeDesktop`, `_renderSeWarnings`, `exportSeDesktopPDF` | ja | B5 |
| Desktop Zeitverwaltung | `zuRenderOverview/MonteurView/TagView`, `exportZeituebersichtPDF`, `renderWochenpruefung`, `exportStundenauswertungPDF` | ja | B5, B6, B7 |
| Desktop Auswertung → Mitarbeiter | `renderMitarbeiterContent`, CSV, Druck, `drawMitarbeiterCharts` | ja | B3, B4, B6 |
| Wochenplanung Jahr | `renderWochenplanungJahr` | ja (seit 3.0.11) | B5, N1 |
| Mobil | Erfassung, `calcGleitzeitSaldoMobile`, `zuMobileRender*`, Stundenauswertung, 2 PDFs | ja | B3, B4, B5, B6, M2 |
| Mobile-Light | Erfassung, `renderLightTagesInfo` | ja | N3 |

**Feiertage** werden an 7 Stellen eigenständig berechnet: `script.js` `getBavarianHolidays` +
`buildHolidayNameMap`, `mobile.html` `getBavarianHolidays` + `buildHolidayNameMapM`, `mobile_light.html`
`lGetHolidayKeys`, `ZeiterfassungActions::computeBavarianHolidays`, `cron_stundenauswertung_email.php`
`bayerischeFeiertage`. Bis auf den Cron beziehen alle die betrieblichen Feiertage (`custom_feiertage`) ein.
**Gleitzeitsaldo** existiert dreifach (Backend, Desktop, Mobil), rechnerisch gleich.

**A.2 Belegte Befunde**

| Nr | Schwere | Fundstelle | Befund | Vorschlag |
|---|---|---|---|---|
| B1 | Hoch | `cron_stunden_erinnerung.php:40-68`, `Dockerfile.app:117`, `bedienungsanleitung.html:238` | Die Stunden-Erinnerung liest `erinnerung_settings.json`, `wochenplanung.json`, `zeiterfassung.json`, `users.json` – seit der Datenbank-Umstellung schreibt die App diese Dateien nicht mehr (Daten in Tabellen `wochenplanung`, `zeiterfassung`, `erinnerung_settings`). Sie findet daher nie etwas, ignoriert den Aus-Schalter und schreibt per Docker-Cron alle 15 min in `erinnerung.log`. Die Anleitung beschreibt sie als funktionierend (E-081). | Als `bin/console`-Befehl auf die Datenbank umstellen, einmal täglich (Sperre je Tag), Aus-Schalter aus `erinnerung_settings`; Abwesenheiten zählen als gebucht, Tage ohne Tages-Soll werden übersprungen. |
| B2 | Hoch | `cron_stundenauswertung_email.php:85-87, 108-111, 223-242` | Monats-Mail kennt nur bayerische Feiertage. Ein betrieblicher Feiertag ohne Eintrag steht dort als −Tages-Soll, in Zeitübersicht/Gleitzeit dagegen neutral. Zudem zeigt die Mail Soll/Ist „netto“ (Feiertage aus beiden herausgerechnet), die Zeitübersicht „brutto“ (Feiertag in Soll und Ist) – gleiche Differenz, aber andere Soll-/Ist-Zahlen. | Feiertage zentral (`Feiertage`-Service inkl. `custom_feiertage`); Mail rechnet wie die Zeitübersicht (Soll = Σ Tages-Soll, Ist inkl. virtueller Feiertage). |
| B3 | Hoch | `script.js:23820-23823, 23887, 24066, 24082` (Tabelle, CSV, Druck, Diagramme) | Auswertung → Mitarbeiter: Soll = 12 Monate des Jahres, Ist = bisher gebucht → im laufenden Jahr z. B. im Oktober ≈ −400 h „+/-“. In derselben Zeile rechnet „Gleitzeitkonto“ nur bis zum laufenden Monat. | Einheitlicher Stichtag (F1) für Jahres-Soll und Saldo. |
| B4 | Mittel | `ZeiterfassungActions.php:1688-1717`, `script.js:15768-15776`, `mobile.html:6356-6366` | Gleitzeitsaldo: laufender Monat mit vollem Monats-Soll (Saldo mitten im Monat zu negativ); Startdatum nur monatsgenau (Tage vor dem Startdatum im Startmonat zählen mit; manuelle Buchungen dagegen taggenau). In allen drei Implementierungen gleich. | Saldo taggenau von `max(Jahresbeginn, Startdatum)` bis Stichtag (F1); eine Implementierung je Seite (PHP-Service, `sollzeit.js`). |
| B5 | Mittel | `script.js:16266, 17408, 17798, 19058, 19851`; `mobile.html:5432, 6766, 7088` | „Soll/Tag: X h“ zeigt `sollstundenTag ?? 8` – bei „Sollzeit je Wochentag“ falsch/irreführend. | Gemeinsamer Anzeigetext aus der Konfiguration (z. B. „Mo–Do 8 h · Fr 6 h“). |
| B6 | Mittel | 20 Stellen, u. a. `script.js:10660, 15825, 16051, 17401, 17790, 19108, 23821, 24101`; `mobile.html:4798, 5151, 6520, 7078`; `mobile_light.html:929, 970` | Urlaubsanspruch `urlaubstageProJahr[y] \|\| 30`: ein bewusst gesetzter Anspruch 0 wird als 30 angezeigt, und die Vorprüfungen beim Buchen (`script.js:16051`, `mobile.html:5151`, `mobile_light.html:929/970`) lassen bis 30 zu; das Backend (`urlaubLimitForYear`) verwendet korrekt 0 und lehnt ab. | Eine Funktion „Urlaubsanspruch(user, Jahr)“ (PHP + JS): 30 nur, wenn für das Jahr nichts hinterlegt ist. |
| B7 | Mittel | `script.js:17362, 17398-17401` | Stundenauswertung-PDF: virtuelle Feiertage, Urlaub und Gleitzeit immer für das **aktuelle** Jahr, auch wenn der Filterzeitraum in einem anderen Jahr liegt. | Jahr(e) aus dem Filterzeitraum ableiten. |
| M1 | Mittel | `ZeiterfassungActions.php:1460-1471` | Zeitraumbuchung durch Admin/Master (`admin_add_zeiterfassung`, z. B. Wochenplanung → Jahr) legt fest Mo–Fr an: Urlaub an Feiertagen verbraucht Urlaubstage, Samstag-Arbeitstage fehlen, Wochentage mit Soll 0 erhalten 0-h-Einträge. Die eigene Zeitraumbuchung (Desktop/Mobil) nutzt dagegen Tages-Soll > 0 und überspringt Feiertage. | Gleiche Regel wie Eigenbuchung: nur Tage mit Tages-Soll > 0 und ohne Feiertag. |
| M2 | Niedrig | `mobile.html:6476-6478` | Mobile Zeitübersicht: Karte „Werktage“ aus Pseudo-Konfiguration 8 h/5 Tage, ohne Feiertage. | Karte entfernen oder Mo–Fr ohne Feiertage zählen. |
| N1 | Niedrig | `script.js:18973`; `script.js:23818-23820, 23880-23882, 23969-23971, 17778-17779` u. a. | Toter Code: Einstellung `betriebliche_feiertage` existiert nicht (richtig: `custom_feiertage`); ungenutzte Variablen `sollTag/tageWoche/arbeitstage`. | Entfernen. |
| N2 | Niedrig | `script.js:17408, 19851`; `mobile.html:6766, 7088` | `${user.role}` ungeescaped in HTML (Wert serverseitig auf admin/master/normal begrenzt). | `esc()` gemäß frontend.instructions.md. |
| N3 | Niedrig | `mobile_light.html:392` | Platzhalter 8 h/5 Tage, bis die Konfiguration geladen ist. | Hinweis „lädt …“ statt Zahl. |

**Struktur/Tests (E-041, struktur.instructions.md):**

| Nr | Befund | Vorschlag |
|---|---|---|
| S1 | Feiertage 7-fach, Gleitzeitsaldo 3-fach dupliziert → Ursache von B2/B4 | `src/Services/Feiertage.php`, `src/Services/Gleitzeit.php`; JS-Gegenstücke `bkFeiertage`, `bkGleitzeitSaldo` in `public/sollzeit.js` (E-041 erweitert) |
| S2 | Kein Paritätstest PHP ↔ `sollzeit.js`; kein Test für Saldo, Monats-Mail-Zahlen, Admin-Zeitraum, Urlaubslimit, Benutzerlisten-Felder, Erinnerung | gemeinsame Fallliste `tests/fixtures/sollzeit/faelle.json`; PHPUnit + `node --test` (eingebaut, kein neues Paket) |
| S3 | Cron-Skripte im Projektwurzelverzeichnis; Vorlage: CLI-Befehle in `bin/console` | Erinnerung (B1) als `zeit:erinnerung`; Monats-Mail folgt in Stufe 2 |
| S4 | `ZeiterfassungActions.php` 1983 Zeilen mit Fachlogik | Saldo/Feiertage in Services (S1); weiteres Verschlanken Stufe 2 |

**A.3 Widerlegt bzw. abgestuft (E-072):** „Virtuelle Feiertage/Warnungen ohne betriebliche Feiertage“ (Desktop,
Mobil) – `getBavarianHolidays` liest `custom_feiertage` ein (`script.js:14889`, `mobile.html:6289`). „Unbekannte
Typen müssten Stunden liefern“ – G1-Regel meint alte String-Einträge in `ze_custom_typen`, umgesetzt
(`Sollzeit.php:47-50`). „Cron ignoriert eigene Typen“ – `ze_custom_typen` ist ein Array, wird gelesen.
„`substr`/`LIKE` nicht PostgreSQL-fähig“ – auf Textspalten portabel, PG-Gesamtlauf grün (nur Hinweis).
„Mobil-Saldo nur je Jahr“ – gewollt, Übertrag per Jahreswechsel-Buchung.

### Teil B – Plan

**Ziel & Abgrenzung:** Stufe 1 (dieses AP) behebt B1–B7, M1, M2, N1–N3 und vereinheitlicht dazu Feiertage und
Gleitzeitsaldo (S1) mit Tests (S2). **Nicht Teil:** Monats-Mail als `bin/console`-Befehl, weiteres Zerlegen von
`ZeiterfassungActions`, Offline-Cache-Revision der Wochenplanung, ZE003/ZE004 (offene Produktfrage aus
AP-20261004-anleitung-zeiterfassung), Sollzeit-Historie (G1 2026-10-04: rückwirkend). Diese Punkte bilden Stufe 2
(eigenes AP nach Bedarf). **Beifund außerhalb der Zeiterfassung:** `cron_material_erinnerung.php` liest ebenfalls noch
`erinnerung_settings.json`/`baukalkulation.json` (gleiche Ursache wie B1) → in CHANGELOG „Noch offene Punkte“ aufnehmen.

**Betroffene Dateien:**
- `src/Services/Feiertage.php` (neu): bayerische + betriebliche Feiertage je Jahr mit Namen.
- `src/Services/Gleitzeit.php` (neu): Saldo taggenau Start → Stichtag; `Sollzeit.php`: `urlaubsanspruch()`, `sollBeschreibung()`.
- `src/Handlers/ZeiterfassungActions.php`: Saldo/Feiertage an Services delegieren; `adminAddEntry` Tagesauswahl (M1).
- `cron_stundenauswertung_email.php`: Feiertage-Service, Rechnung wie Zeitübersicht (B2).
- `src/Console/Command/ZeitErinnerungCommand.php` (neu), `src/Console/Kernel.php`, `cron_stunden_erinnerung.php` (nur noch Weiterleitung an den Befehl, für bestehende NAS-Cronjobs), `src/Handlers/AdminActions.php` (`triggerErinnerung`), `Dockerfile.app` (täglicher Aufruf als `www-data`, E-065).
- `public/sollzeit.js`: `bkFeiertage`, `bkGleitzeitSaldo`, `bkUrlaubsanspruch`, `bkSollBeschreibung`.
- `public/script.js`, `public/mobile.html`, `public/mobile_light.html`: auf die Helfer umstellen (B3–B7, M2, N1–N3); eigene Feiertags-/Saldo-Kopien entfernen.
- Build: `public/script.min.js` (`npm.cmd run minify`), `?v=` in `index.html`/`mobile*.html`/`sw.js`, `CACHE_VERSION`.
- Tests: `tests/Unit/FeiertageTest.php`, `tests/Unit/GleitzeitTest.php`, `tests/Unit/SollzeitTest.php` (erweitert, liest Fallliste), `tests/fixtures/sollzeit/faelle.json`, `tests/js/sollzeit.test.mjs`, `tests/Api/ZeiterfassungAdminTest.php`, `tests/Api/StundenauswertungMailTest.php`, `tests/Api/ZeitErinnerungTest.php`, `tests/Api/SollzeitProfilTest.php` (Benutzerlisten-Felder).
- `package.json` (Skript `test:js`), `Makefile` (`test` ruft zusätzlich `test-js`), `.github/workflows/ci.yml` (Frontend-Job: `npm run test:js`).
- Doku: `public/bedienungsanleitung.html` (Gleitzeit-Stichtag/Startdatum, Monats-Mail, Admin-Zeitraum, Erinnerung, Soll-Anzeige), `docs/installation.md` (Cron-Befehl), `docs/entwicklung.md` (JS-Test), `CHANGELOG.md`.

**Struktur (copier-astral):** Services unter `src/Services`, CLI als `bin/console`-Befehl (Vorlage `cli.py`),
Tests unter `tests/Unit`/`tests/Api`. **Abweichung:** JS-Test mit `node --test` unter `tests/js/` – die Vorlage kennt
nur pytest; nötig für die Parität nach E-041, ohne neues Paket; wird in die Abbildungstabelle eingetragen.

**Berücksichtigte Erkenntnisse:** E-041 (zentrale Soll/Ist-Logik, jetzt auch Feiertage/Saldo), E-080 (Gutschrift
nur an Soll-Tagen), E-081 (Anleitung), E-082 (SQLite + PG), E-065 (`www-data`), E-063 (kein `@` – das alte
Erinnerungsskript nutzt `file_get_contents` ohne `@`, neuer Code ebenso), E-072, E-076.

**Schritte** (je Schritt einzeln prüfbar, Commit-Reihenfolge):
1. **Tester – rote Tests (Bugfix):** Monats-Mail mit betrieblichem Feiertag (B2); Saldo mit Startdatum Monatsmitte
   und Stichtag (B4); Admin-Zeitraum über Feiertag und Samstag-Arbeitstag (M1); Urlaubsanspruch 0 (B6, PHP-Seite);
   Erinnerung findet fehlende Buchung aus Datenbank und respektiert Aus-Schalter (B1).
2. **Backend-Services:** `Feiertage`, `Gleitzeit`, `Sollzeit::urlaubsanspruch/sollBeschreibung`; `ZeiterfassungActions`
   delegiert, `computeBavarianHolidays` entfällt; `adminAddEntry` wählt Tage über `tagesSoll > 0` und kein Feiertag.
3. **Monats-Mail:** Feiertage-Service, Soll/Ist wie Zeitübersicht; PDF-Spalten unverändert.
4. **Erinnerung:** Befehl `zeit:erinnerung [--datum=JJJJ-MM-TT]` liest `wochenplanung`, `zeiterfassung`,
   `erinnerung_settings`; Tagessperre in `DATA_DIR`; altes Skript leitet weiter; Dockerfile-Cron täglich.
5. **`sollzeit.js`:** neue Helfer + Fallliste; `node --test` grün; Paritätstest PHP liest dieselbe Fallliste.
6. **Desktop:** Saldo/Jahresauswertung/CSV/Druck/Diagramme auf Stichtag (B3, B4), Urlaubsanspruch (B6),
   Soll-Anzeige (B5), PDF-Jahr aus Filter (B7), toter Code + `esc(role)` (N1, N2).
7. **Mobil + Light:** gleiche Umstellungen, `calcGleitzeitSaldoMobile`/`getVirtuelleFeiertagEintraegeM`/
   `buildHolidayNameMapM` auf Helfer, Werktage-Karte (M2), Light-Platzhalter (N3).
8. **Build & Doku:** minify, Cache-Busting, `check-versions --strict`; Anleitung, Installation, Entwicklung, CHANGELOG.
9. **Gesamtläufe** SQLite + PostgreSQL (Leitstand), Review, Release (Patch-Version).

**Risiken:**
- Rückwirkend geänderte Anzeigewerte (Saldo, Jahres-+/-) – gewollt, im CHANGELOG/Anleitung erklären; bereits gebuchte
  Jahreswechsel-Überträge bleiben unverändert (manuelle Buchungen).
- SQLite/PostgreSQL: neue Abfragen (Erinnerung) nur mit Datumsbereichen/Parametern; keine SQLite-Datumsfunktionen.
- Erinnerung wird nach dem Fix erstmals wirksam → Tagessperre gegen 96 Läufe/Tag; Log-Inhalt ohne Kundendaten außer Benutzername/Baustellenname (wie bisher).
- Alte NAS-Installationen rufen `cron_stunden_erinnerung.php` direkt auf → Datei bleibt als Weiterleitung.
- Keine Schemaänderung → keine Migration/Fixture; Backup-Import unberührt.
- Frontend: drei Seiten laden `sollzeit.js` – Version in `sw.js`-Precache und allen `?v=` gemeinsam erhöhen.
- Rechte unverändert (`adminAddEntry`: `canSeeStundenauswertung`; Erinnerung-Trigger: `admin`).

**Abnahmekriterien:**
1. Für jeden Fall der Fallliste liefern PHP und `sollzeit.js` identische Tages-Soll-, Ist-, Feiertags- und Saldo-Werte.
2. Monats-Mail zeigt für einen Monat dieselben Soll-, Ist- und Differenzwerte je Mitarbeiter wie die Zeitübersicht – auch mit betrieblichem Feiertag und „Sollzeit je Wochentag“.
3. Gleitzeitsaldo ist in Backend (Jahreswechsel), Desktop und Mobil gleich, beginnt taggenau am Startdatum und endet am Stichtag (F1).
4. Auswertung → Mitarbeiter: Soll, Ist, +/- und Gleitzeit beziehen sich auf denselben Zeitraum.
5. Admin-Zeitraumbuchung erzeugt nur Einträge an Tagen mit Tages-Soll > 0 ohne Feiertag; Urlaubslimit zählt nur diese.
6. Urlaubsanspruch 0 wird überall als 0 angezeigt; ohne Eintrag für das Jahr 30.
7. Bei „Sollzeit je Wochentag“ zeigt keine Ansicht/kein PDF einen einzelnen „Soll/Tag“-Wert aus `sollstundenTag`.
8. `bin/console zeit:erinnerung --datum=…` meldet geplante, aber ungebuchte Mitarbeiter aus der Datenbank, zählt Urlaub/Krank/Feiertag als gebucht, läuft höchstens einmal je Tag und ist abschaltbar.
9. SQLite- und PostgreSQL-Gesamtlauf grün, `node --test` grün, PHPStan ohne neue Baseline, `check-versions --strict` grün; Anleitung beschreibt jede geänderte Anzeige.

**Offene Fragen (Empfehlung jeweils zuerst):**
- **F1 Stichtag** für Gleitzeitsaldo und Jahresauswertung: (a) **bis einschließlich heute** (empfohlen; einfach, gleiche
  Regel überall) · (b) bis gestern · (c) wie bisher bis Monatsende.
- **F2 Monats-Mail:** (a) **Soll/Ist wie Zeitübersicht** (Feiertag in Soll und Ist, empfohlen) · (b) netto wie bisher, nur betriebliche Feiertage ergänzen.
- **F3 Stunden-Erinnerung:** (a) **reparieren als `bin/console zeit:erinnerung`** (empfohlen) · (b) entfernen (Cron, Knopf, Anleitung).
- **F4 Umfang:** (a) **Stufe 1 wie geplant inkl. Vereinheitlichung S1** (empfohlen – B2/B4 sonst an 3 bzw. 7 Stellen
  einzeln zu korrigieren) · (b) nur Fehler B1–B7/M1 ohne Zusammenführung.

**Freigabe G1:** ☑ durch Nutzer am 2026-10-07 – alle Empfehlungen: F1a (Stichtag bis einschließlich heute),
F2a (Monats-Mail wie Zeitübersicht), F3a (Erinnerung als `bin/console zeit:erinnerung`), F4a (Stufe 1 inkl. S1).

### Teil C – Schnittstellen-Vertrag (Leitstand, verbindlich für Tester und Entwickler)

**PHP (`src/Services`, `declare(strict_types=1)`, vollständig typisiert):**
- `Feiertage::fuerJahr(int $jahr, array $customFeiertage = []): array` → `['JJJJ-MM-TT' => 'Name', …]` sortiert;
  bayerische Feiertage wie bisher (inkl. 15.08.) plus `custom_feiertage`-Einträge (`[['datum'=>…, 'name'=>…], …]`) des Jahres.
- `Feiertage::customAusEinstellungen(array $settings): array` → dekodiert `settings['custom_feiertage']` (JSON-String oder Array; ungültig → `[]`).
- `Gleitzeit::saldo(\PDO $db, string $username, array $zeitCfg, int $jahr, array $settings, ?\DateTimeImmutable $heute = null): float`
  – Zeitraum: von `max(01.01.$jahr, gleitzeit_startdatum)` bis `min(31.12.$jahr, $heute ?? heute)`, **taggenau**;
  Soll = Σ `Sollzeit::tagesSoll`; Ist = Σ `Sollzeit::istStundenEintrag` der Einträge im Zeitraum + virtuelle Feiertage
  (Feiertag im Zeitraum, Tages-Soll > 0, kein Eintrag an dem Tag); plus manuelle Buchungen des Jahres mit
  `startdatum ≤ datum ≤ Stichtag`; Startdatum nach Zeitraumende → 0.0; gerundet auf 2 Stellen.
- `Sollzeit::urlaubsanspruch(array $mitarbeiter, int $jahr): float` – `urlaubstageProJahr` (JSON-String oder Array);
  Wert für das Jahr vorhanden (auch 0) → dieser, sonst 30.
- `Stundenauswertung::monat(\PDO $db, array $settings, string $monat): array` (`$monat` = `JJJJ-MM`) → Liste je
  Mitarbeiter (ohne `role = admin`, ohne `showInZeitverwaltung = 0`, sortiert nach Name) mit
  `username, name, soll, ist, diff, arbeit, urlaub, krank`; Soll = Σ Tages-Soll aller Tage des Monats; Ist wie
  `Gleitzeit` (inkl. virtueller Feiertage) für den ganzen Monat; `urlaub`/`krank` = Anzahl Tage mit solchem Eintrag.
- `ZeitErinnerung::pruefe(\PDO $db, string $datum): array` → `['datum'=>…, 'fehlend'=>[['username'=>…, 'baustellen'=>[Namen]], …]]`;
  geprüft wird jeder Benutzer mit Wochenplanungs-Eintrag `typ = 'baustelle'` und `baustelleId` an `$datum`; übersprungen,
  wenn er an dem Tag **irgendeinen** Zeiterfassungs-Eintrag hat, wenn `$datum` Feiertag ist oder sein Tages-Soll 0 ist.
- Befehl `zeit:erinnerung [--datum=JJJJ-MM-TT] [--force]`: Standard Vortag; `stunden_aktiv = false` in `erinnerung_settings`
  → Meldung, Exit 0, nichts geprüft; Tagessperre `DATA_DIR/stunden_erinnerung_last.txt` (gleicher Prüftag → Exit 0 ohne
  Prüfung, außer `--force`/`--datum`); schreibt `DATA_DIR/stunden_erinnerung_result.json` und Zeilen in `erinnerung.log`; Exit 0.
- `admin_add_zeiterfassung` mit `datumBis`: nur Tage mit `tagesSoll > 0`, die kein Feiertag (`Feiertage::fuerJahr` inkl.
  custom) sind; ohne passenden Tag → 400 wie bisher.

**JS (`public/sollzeit.js`, global wie bisher):**
- `bkFeiertage(jahr, customFeiertage)` → `Map('JJJJ-MM-TT' → Name)`; `customFeiertage` als Array oder JSON-String.
- `bkVirtuelleFeiertage(entries, user, jahr, monat|null, customFeiertage)` → Einträge `{datum, typ:'feiertag', stunden, bemerkung, _virtual:true}`.
- `bkGleitzeitSaldo(entries, user, jahr, buchungen, {startdatum, customTypen, customFeiertage, heute})` – gleiche Regel wie PHP.
- `bkUrlaubsanspruch(user, jahr)` – gleiche Regel wie PHP.
- `bkSollBeschreibung(user)` → Wochentagsmodus: „Mo 8 h · Di 8 h · … “ (nur Tage > 0, gleiche Werte zusammengefasst
  zulässig, z. B. „Mo–Do 8 h · Fr 6 h“), Altmodus: „8 h/Tag (Mo–Fr)“; keine Soll-Tage → „kein Soll“.

**Gemeinsame Fallliste** `tests/fixtures/sollzeit/faelle.json`: Fälle für Tages-Soll, Ist je Typ, Feiertage eines Jahres
(Anzahl/Stichproben inkl. custom), Saldo (Einträge, Buchungen, Startdatum, `heute`) und Urlaubsanspruch – PHPUnit
und `node --test` prüfen dieselben Erwartungswerte.

## 3. Umsetzung

### Runde 1 – Backend

Geänderte/angelegte Dateien:
- `src/Services/Feiertage.php` – zentrale bayerische und betriebliche Feiertage inklusive Namen.
- `src/Services/Gleitzeit.php` – taggenauer Gleitzeitsaldo mit virtuellen Feiertagen und manuellen Buchungen.
- `src/Services/Stundenauswertung.php` – Monatsauswertung Soll/Ist wie Zeitübersicht.
- `src/Services/ZeitErinnerung.php` – Datenbankprüfung für geplante, fehlende Stundenbuchungen.
- `src/Services/Sollzeit.php` – `urlaubsanspruch()` mit 0 als gültigem Jahreswert.
- `src/Handlers/ZeiterfassungActions.php` – Jahreswechsel-Saldo an Service delegiert, alte Feiertags-/Saldo-Helfer entfernt, Admin-Zeitraumbuchung nach Solltag/Feiertag gefiltert.
- `cron_stundenauswertung_email.php` – Monats-Mail auf `Stundenauswertung::monat()` umgestellt, eigene Feiertagsberechnung entfernt.
- `src/Console/Command/ZeitErinnerungCommand.php` – neuer Befehl `zeit:erinnerung` mit `--datum`, `--force`, Tagessperre, Log und Ergebnisdatei.
- `src/Console/Kernel.php` – `zeit:erinnerung` registriert.
- `cron_stunden_erinnerung.php` – dünne Weiterleitung auf `bin/console zeit:erinnerung`.
- `src/Handlers/AdminActions.php` – manueller Erinnerungs-Trigger ruft `bin/console zeit:erinnerung --force` auf.
- `Dockerfile.app` – Cron-Zeile für Stunden-Erinnerung auf `bin/console zeit:erinnerung` umgestellt.

Prüfungen:
- `php vendor\bin\phpunit --filter "Feiertage|Gleitzeit|Stundenauswertung|ZeiterfassungAdmin|ZeitErinnerung|SollzeitProfil|SollzeitParity|SollzeitTest|Zeiterfassung"` → **GRÜN** (34 Tests, 403 Assertions).
- `php vendor\bin\phpstan analyse --memory-limit=2G` → **GRÜN** (keine Fehler).
- `php vendor\bin\php-cs-fixer check --diff` → **GRÜN**.

Abweichungen: keine.

### Runde 2 – sollzeit.js

Geänderte Dateien:
- `public/sollzeit.js` – globale JS-Helfer für Feiertage, virtuelle Feiertage, Gleitzeitsaldo, Urlaubsanspruch und Sollbeschreibung gemäß Vertrag ergänzt.
- `package.json` – `test:js` auf `node --test "tests/js/*.test.mjs"` ergänzt.
- `Makefile` – Target `test-js` ergänzt und `test` daran gekoppelt.
- `.github/workflows/ci.yml` – Frontend-Job führt nach `npm ci` die JS-Tests aus.
- `.github/instructions/struktur.instructions.md` – Struktur-Abweichung für JS-Helfer-Tests dokumentiert.
- `docs/entwicklung.md` – JS-Testaufrufe und gemeinsame Sollzeit-Fallliste dokumentiert.

Prüfungen:
- `npm.cmd run test:js` → **GRÜN** (12 Tests).
- `php vendor/bin/phpunit --filter "SollzeitParity|SollzeitTest|Feiertage"` → **GRÜN** (12 Tests, 82 Assertions).

Abweichungen: keine.

### Runde 3 – Desktop

Geänderte Dateien:
- `public/script.js` – Desktop-Umstellung auf die JS-Vertragshelfer aus `public/sollzeit.js`.

Funktionen/Zeilenbereiche je Befund:
- B3: `calcJahresSollIst`/Stichtagshelfer (`script.js:15690-15735`) ergänzt; `renderMitarbeiterContent`,
  `exportAuswertungMitarbeiterCSV`, `printAuswertungMitarbeiter`, `drawMitarbeiterCharts`
  (`script.js:23732-24056`) verwenden Soll/Ist nur bis Stichtag und kennzeichnen das laufende Jahr mit
  „(bis heute)“.
- B4: `calcGleitzeitSaldo` (`script.js:15676-15684`) delegiert an `bkGleitzeitSaldo` inkl. Startdatum,
  Custom-Typen, Custom-Feiertagen und lokalem Heute; `getGleitzeitStartMonth` entfernt.
- B5: Desktop-Sollanzeigen in `exportSeDesktopPDF`, `exportStundenauswertungPDF`, Zeitübersicht-Übersicht,
  Wochenplanung-Jahresdetail und `exportZeituebersichtPDF` (`script.js:16188-19782`) zeigen
  `bkSollBeschreibung(...)` escaped; „Tage/Wo“ aus den betroffenen Ausgaben entfernt.
- B6: Urlaubsanspruch-Anzeigen und Vorprüfung (`script.js:10660, 15767, 15993, 16941, 17339, 17726,
  19041, 19778, 23750-24021`) verwenden `bkUrlaubsanspruch(...)`; Eingabewerte behalten `0` als gültig.
- B7: `exportStundenauswertungPDF` (`script.js:17294-17345`) nutzt Bezugsjahr aus `filterTo`/`filterFrom`,
  virtuelle Feiertage über den Filter-Jahresbereich und Urlaub/Gleitzeit für das Bezugsjahr.
- N1/N2: `appSettings.betriebliche_feiertage` aus `renderWochenplanungJahr` entfernt; ungenutzte Soll-Variablen
  in den betroffenen Bereichen entfernt; Rollen in den bearbeiteten HTML-Ausgaben mit `esc()` geschützt.

Prüfungen:
- `node --check public\script.js` → **GRÜN**.
- `npm.cmd run test:js` → **GRÜN** (12 Tests).
- `rg "\|\| 30" public\script.js` → **keine Treffer**.
- `rg "betriebliche_feiertage" public\script.js` → **keine Treffer**.
- `rg "Soll/Tag: \$\{sollTag\}" public\script.js` → **keine Treffer**.

Abweichungen: `public/script.min.js`, Cache-Busting (`?v=`, `sw.js`) und Mobilseiten gemäß Auftrag noch nicht geändert.

### Runde 4 – Mobil, Build, Doku

Geänderte Dateien:
- `public/mobile.html` – Mobilseite auf `bkFeiertage`, `bkVirtuelleFeiertage`, `bkGleitzeitSaldo`,
  `bkUrlaubsanspruch` und `bkSollBeschreibung` umgestellt; Werktage-Karte entfernt; Rollenausgaben escaped;
  Jahres-Gesamtwerte in PDFs bis heute gekennzeichnet.
- `public/mobile_light.html` – Feiertags- und Urlaubslogik auf zentrale JS-Helfer umgestellt; Tages-Soll zeigt bis
  zum Laden „lädt …“ statt eines Platzhalterwerts.
- `public/script.min.js` – nach Desktop-/JS-Änderungen per `npm.cmd run minify` neu erzeugt.
- `public/index.html`, `public/mobile.html`, `public/mobile_light.html`, `public/sw.js` – Cache-Busting auf `v=231`
  und `CACHE_VERSION` auf `bk-es-v231` erhöht.
- `public/bedienungsanleitung.html` – geänderte Zeiterfassungsregeln für Gleitzeit, Jahreswerte, Monats-Mail,
  Admin-Zeitraumbuchung, Urlaubsanspruch, Soll-Anzeige und Stunden-Erinnerung beschrieben.
- `docs/installation.md` – Stunden-Erinnerungs-Cron auf `docker compose exec -u www-data app php bin/console
  zeit:erinnerung` umgestellt; Optionen `--datum`/`--force` dokumentiert.
- `CHANGELOG.md` – unveröffentlichte Änderungen und offene Punkte zur Zeiterfassung ergänzt.

Prüfungen:
- `npm.cmd run minify` → **GRÜN**.
- `npm.cmd run test:js` → **GRÜN** (12 Tests).
- `node scripts/check-versions.mjs --strict` → **GRÜN**.
- Inline-Skript-Kompilierung per `new Function` für `public/mobile.html` und `public/mobile_light.html` → **GRÜN**.
- `rg "\|\| 30" public\mobile.html public\mobile_light.html public\script.js` → **keine Treffer**.
- `rg "Soll/Tag: \$\{sollTag\}" public\mobile.html public\mobile_light.html public\script.js` → **keine Treffer**.
- `rg "getWorkingDaysInMonth\(y, m, \{ sollstundenTag: 8" public\mobile.html public\mobile_light.html`
  → **keine Treffer**.

Abweichungen: keine.

### Runde 5 – Review-Befunde

Geänderte Dateien:
- `src/Services/ZeitErinnerung.php` – Sichtbarkeitsfilter `showInZeitverwaltung != 0` aus der Benutzerabfrage entfernt; geprüft werden damit alle in der Wochenplanung auf Baustellen eingeplanten Benutzer gemäß Vertrag.
- `docs/installation.md` – Stunden-Erinnerungs-Cron korrigiert: Docker-Installationen nutzen die Container-Crontab aus `Dockerfile.app`; nur Installationen ohne Docker planen den Befehl täglich ein. Manueller Docker-Aufruf mit `--datum`/`--force` bleibt dokumentiert.

Geprüft:
- `public/bedienungsanleitung.html` – kein Widerspruch zur Vortagsprüfung und Wochenplanungslogik.

Prüfungen:
- `php vendor\bin\phpunit --filter "ZeitErinnerung"` → **GRÜN** (5 Tests, 102 Assertions).
- `php vendor\bin\phpstan analyse --memory-limit=2G` → **GRÜN** (keine Fehler).
- `php vendor\bin\php-cs-fixer check --diff` → **GRÜN**.

Abweichungen: keine.

## 4. Tests

### Runde 1 – Tester (rote Tests)

Geänderte/angelegte Dateien (E-070): `tests/Unit/FeiertageTest.php`, `tests/Unit/SollzeitTest.php`,
`tests/Unit/SollzeitParityTest.php`, `tests/fixtures/sollzeit/faelle.json`, `tests/Api/GleitzeitSaldoTest.php`,
`tests/Api/StundenauswertungMonatTest.php`, `tests/Api/ZeiterfassungAdminTest.php`,
`tests/Api/ZeitErinnerungTest.php`, `tests/Api/SollzeitProfilTest.php`.

Lauf:
`php vendor\bin\phpunit --filter "Feiertage|Gleitzeit|Stundenauswertung|ZeiterfassungAdmin|ZeitErinnerung|SollzeitProfil|SollzeitParity|SollzeitTest"`
→ **ROT** (28 Tests, 303 Assertions, 10 Errors, 7 Failures).
`php vendor\bin\php-cs-fixer check --diff` → **GRÜN**.

- `tests/Unit/FeiertageTest.php`: prüft bayerische Feiertage 2025 inkl. beweglicher Feiertage, Custom-Feiertage
  desselben Jahres und `customAusEinstellungen` für JSON-String/Array/ungültig. **ROT:** `App\Services\Feiertage`
  existiert noch nicht.
- `tests/Unit/SollzeitTest.php`: ergänzt `Sollzeit::urlaubsanspruch` für 0 bleibt 0, fehlendes Jahr → 30,
  JSON-String und Array. **ROT:** Methode `Sollzeit::urlaubsanspruch()` fehlt.
- `tests/fixtures/sollzeit/faelle.json` + `tests/Unit/SollzeitParityTest.php`: gemeinsame Fallliste für
  `tagesSoll`, `istStundenEintrag`, `feiertage`, `saldo`, `urlaubsanspruch`; PHPUnit prüft PHP-seitig bewusst nur
  `tagesSoll`/`ist`/`feiertage`/`urlaubsanspruch`. **ROT:** `Feiertage` und `urlaubsanspruch()` fehlen; bestehende
  `tagesSoll`/`ist`-Fälle sind grün. **Lücke:** Saldo-Fälle sind in der Fallliste abgelegt, aber ohne reine
  PHP-Funktion laut Vertrag nicht im Paritätstest; sie sind für den späteren JS-Test `bkGleitzeitSaldo` vorgesehen.
- `tests/Api/GleitzeitSaldoTest.php`: prüft `Gleitzeit::saldo` mit Startdatum Monatsmitte, Stichtag `$heute`,
  betrieblichem Feiertag, Wochentagsmodus und manuellen Buchungen außerhalb der Grenzen; zusätzlich API
  `get_jahreswechsel_data?year=2025`. **ROT:** `App\Services\Gleitzeit` fehlt; API liefert bei Start 2025-03-17
  `-336.0` statt taggenau `-320.0` (Startmonat wird monatsweise gerechnet).
- `tests/Api/StundenauswertungMonatTest.php`: prüft Monatsauswertung für betriebliche und gesetzliche Feiertage,
  Admin/`showInZeitverwaltung=false` sowie Urlaub/Krank. **ROT:** `App\Services\Stundenauswertung` fehlt.
- `tests/Api/ZeiterfassungAdminTest.php`: prüft `admin_add_zeiterfassung` über Karfreitag/Ostermontag, Samstag-Soll
  im Wochentagsmodus, Wochentage mit Soll 0 und Urlaubsanspruch 0. **ROT:** Zeitraum bucht 18.04./21.04. mit und
  bucht Mo–Fr statt Samstag; Urlaubsanspruch 0 ist bereits grün.
- `tests/Api/ZeitErinnerungTest.php`: prüft `bin/console zeit:erinnerung --datum=... --force` für fehlende Buchung,
  Urlaub als gebucht, Feiertag überspringen und `stunden_aktiv=false`. **ROT:** Befehl fehlt
  (`There are no commands defined in the "zeit" namespace.`). **Lücke:** Tagessperre ohne `--datum`/`--force` nicht
  getestet, weil sie ohne Uhr-/Tagesabhängigkeit nicht stabil reproduzierbar ist.
- `tests/Api/SollzeitProfilTest.php`: ergänzt Absicherung, dass `list_users`, `list_users_basic` und
  `get_sollstunden_extended` `sollzeitJeWochentag` sowie `sollstundenMo`…`sollstundenSo` liefern. **GRÜN**
  (Absicherung v3.0.11).

### Runde 2 – Tester (JS, rot)

Geänderte/angelegte Dateien (E-070): `tests/js/sollzeit.test.mjs`, `tests/js/package.json`,
`tests/fixtures/sollzeit/faelle.json`.

Läufe:
- `php vendor\bin\phpunit --filter SollzeitParity` → **GRÜN** (4 Tests, 15 Assertions); die ergänzten Saldo-Fälle
  brechen die PHP-Parität nicht.
- `node --test tests/js/` → **ROT** (12 Tests, 3 grün, 9 rot).

Ergebnis:
- **GRÜN:** gemeinsame Fälle für `bkTagesSoll`, Sonntag-ISO-Randfall im Wochentagsmodus,
  gemeinsame Fälle für `bkIstStundenEintrag`.
- **ROT:** `bkFeiertage`, `bkUrlaubsanspruch`, `bkGleitzeitSaldo`, `bkVirtuelleFeiertage`,
  `bkSollBeschreibung` – die vertraglich geplanten JS-Helfer fehlen in `public/sollzeit.js` noch.
- Fixture ergänzt um den Saldo-Fall „Wochentagsmodus und manuelle Buchungen beachten Datumsgrenzen“
  (vor Start/nach Stichtag ignorieren, Buchung am Stichtag zählt, Erwartung `-10` wie `GleitzeitSaldoTest`).

### Runde 3 – Tester (G2)

Geänderte/angelegte/gelöschte Dateien (E-070, nur `tests/` + Arbeitspaket):
- `tests/js/package.json` gelöscht; JS-Aufruf läuft über Root-`package.json` mit
  `node --test "tests/js/*.test.mjs"`.
- `tests/Api/ZeiterfassungAdminTest.php` ergänzt: Urlaubslimit bei Admin-Zeitraum zählt nur die tatsächlich
  angelegten Tage (Ostermontag + Soll-0-Tag werden übersprungen; Anspruch 3 bleibt grün) und normaler Benutzer
  erhält bei `admin_add_zeiterfassung` 403.
- `tests/Api/ZeitErinnerungTest.php` ergänzt: `zeit:erinnerung` ohne `--datum`/`--force` läuft beim zweiten Aufruf
  wegen `stunden_erinnerung_last.txt` nicht erneut; Ausgabe/`erinnerung.log` enthalten keine Test-Passwörter oder
  Test-Secrets.
- `tests/Api/GleitzeitSaldoTest.php` ergänzt: Saldo-Fälle der gemeinsamen Fallliste werden auch im PHP-Service
  geprüft; `get_jahreswechsel_data` und `Gleitzeit::saldo` liefern für das vergangene Jahr 2025 denselben Wert.
- `tests/fixtures/sollzeit/faelle.json` ergänzt: JS-Saldo-Fall „Jahreswechsel-Saldo vergangenes Jahr“ (gleiches
  Szenario wie PHP/API; Erwartung `-320`).

Abdeckung Abnahmekriterien 1–9:

| Kriterium | Test / Status |
|---|---|
| 1. Fallliste PHP ↔ JS identisch | `tests/Unit/SollzeitParityTest.php` (Tages-Soll, Ist, Feiertage, Urlaub), `tests/Api/GleitzeitSaldoTest::testSaldoFaelleAusGemeinsamerFalllisteInPhpService`, `tests/js/sollzeit.test.mjs` (inkl. Saldo) |
| 2. Monats-Mail wie Zeitübersicht | `tests/Api/StundenauswertungMonatTest.php` prüft den gemeinsam genutzten Monats-Service inkl. betrieblichem Feiertag und Wochentagsprofil |
| 3. Gleitzeitsaldo Backend/Desktop/Mobil | Backend/API automatisiert in `GleitzeitSaldoTest`; JS-Parität über Fallliste in `tests/js/sollzeit.test.mjs`; Desktop/Mobil-Anzeige selbst: Review/manuell |
| 4. Auswertung → Mitarbeiter gleicher Zeitraum | Review/manuell für Desktop-Tabelle/CSV/Druck/Diagramm; keine stabile automatisierte UI-Prüfung in G2 ergänzt |
| 5. Admin-Zeitraumbuchung | `tests/Api/ZeiterfassungAdminTest.php` prüft Feiertage, Wochentagsmodus/Soll-0-Tage, Samstag-Soll und Urlaubslimit nach angelegten Tagen |
| 6. Urlaubsanspruch 0 / Default 30 | `tests/Unit/SollzeitTest.php`, `tests/Unit/SollzeitParityTest.php`, `tests/js/sollzeit.test.mjs`, `ZeiterfassungAdminTest::testUrlaubsanspruchNullLehntUrlaubAb` |
| 7. Keine einzelne Soll/Tag-Anzeige bei Wochentagsmodus | `tests/js/sollzeit.test.mjs` prüft `bkSollBeschreibung`; konkrete Desktop/Mobil/PDF-Anzeigen: Review/manuell |
| 8. `zeit:erinnerung` | `tests/Api/ZeitErinnerungTest.php` prüft DB-Fund, Urlaub als gebucht, Feiertag übersprungen, Aus-Schalter, Tagessperre und keine Secrets in Ausgabe/Log |
| 9. Läufe/Qualität | G2-Auftragsläufe unten grün; SQLite-/PostgreSQL-Gesamtläufe und PHPStan gemäß E-032 nicht durch Tester-G2 ausgeführt |

Prüfungen (kein Gesamtlauf, E-032):
- `php vendor/bin/phpunit --filter "Feiertage|Gleitzeit|Stundenauswertung|ZeiterfassungAdmin|ZeitErinnerung|SollzeitProfil|SollzeitParity|SollzeitTest|Zeiterfassung|AppConsole|ConsoleUser"`
  → **GRÜN** (43 Tests, 514 Assertions).
- `npm.cmd run test:js` → **GRÜN** (12 Tests).
- `node scripts/check-versions.mjs --strict` → **GRÜN** (`CACHE_VERSION = bk-es-v231`, alle `?v=` konsistent).
- `php vendor/bin/php-cs-fixer check --diff` → **GRÜN** (0 fixbare Dateien).

Ergebnis: **GRÜN**. Keine Abweichungen.

### Runde 4 – Tester (Review-Befund)

Geänderte Dateien (E-071):
- `tests/Api/ZeitErinnerungTest.php` – ergänzt
  `testAusZeitverwaltungAusgeblendeterGeplanterMonteurOhneBuchungWirdGemeldet`: ein per
  `set_show_in_zeitverwaltung` ausgeblendeter, auf Baustelle eingeplanter Benutzer ohne Buchung wird von
  `bin/console zeit:erinnerung --datum=2025-03-18 --force` weiter als fehlend gemeldet.

Prüfungen:
- `php vendor/bin/phpunit --filter ZeitErinnerung` → **GRÜN** (6 Tests, 124 Assertions).
- `php vendor/bin/php-cs-fixer check --diff` → **GRÜN** (0 fixbare Dateien).

Ergebnis: **GRÜN**. Keine Abweichungen.

## 5. Review

### Runde 1

**Urteil:** `CHANGES_REQUESTED`

**Geprüfter Umfang:** `git status`/`git diff` abzüglich `src/Services/UpdatePruefung.php` (fremde Änderung, nicht bewertet).

**Befunde:**

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Major | `src/Services/ZeitErinnerung.php:20-22`, `src/Services/ZeitErinnerung.php:78` | Der verbindliche Vertrag für `ZeitErinnerung::pruefe()` sagt, geprüft werde jeder Benutzer mit Wochenplanungs-Eintrag `typ = baustelle`. Die Implementierung lädt aber nur Benutzer mit `showInZeitverwaltung != 0`; ein geplanter, ausgeblendeter Benutzer wird dadurch bei `$user === null` still übersprungen und trotz fehlender Buchung nicht gemeldet. | Sichtbarkeitsfilter aus der Erinnerungsprüfung entfernen oder die Ausnahme bewusst in Vertrag/Doku aufnehmen; zusätzlich einen Test für einen geplanten Benutzer mit `showInZeitverwaltung = 0` ergänzen. |
| Major | `docs/installation.md:253-254`, `src/Console/Command/ZeitErinnerungCommand.php:29` | Die Installationsanleitung empfiehlt `Mo-Fr 17:00` ohne `--datum`. Der Befehl prüft ohne Datum aber immer `yesterday`; damit prüft Montag den Sonntag, Dienstag den Montag usw. und der Freitag wird bei diesem Cron nie geprüft. Die Doku ist damit gegen den aktiven Code-Pfad falsch (E-081). | Cron-Empfehlung auf die Vortagslogik anpassen, z. B. täglich bzw. Di-Sa morgens ausführen, oder explizit `--datum` für den gewünschten Prüftag dokumentieren. |

**Lernpunkt-Kandidaten:**
- Planung/Tests: Sichtbarkeitsfilter (`showInZeitverwaltung`) für Erinnerungs-/Auswertungslogik im Vertrag ausdrücklich festlegen und mit Grenzfall testen. Keine bestehende E-Nr gefunden.
- Doku: E-081 bestätigt; Cron-Beispiele müssen gegen Default-Optionen des tatsächlich aufgerufenen Befehls geprüft werden.

**Prüfungen (lesend, kein PHPUnit-Gesamtlauf):**
- `php vendor\bin\phpstan analyse --memory-limit=2G --no-progress` → **GRÜN**.
- `php vendor\bin\phpunit --filter "Feiertage|Gleitzeit|Stundenauswertung|ZeiterfassungAdmin|ZeitErinnerung|SollzeitProfil|SollzeitParity|SollzeitTest|Zeiterfassung"` → **GRÜN** (38 Tests, 483 Assertions).
- `npm.cmd run test:js` → **GRÜN** (12 Tests).
- `node scripts\check-versions.mjs --strict` → **GRÜN**.

### Runde 2

**Urteil:** `APPROVE`

**Geprüfter Umfang:** Nur Behebung der zwei Runde-1-Major-Befunde in `src/Services/ZeitErinnerung.php`,
`docs/installation.md` und `tests/Api/ZeitErinnerungTest.php`.

**Befunde:**

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| – | – | Keine Blocker/Major-Befunde. | – |

**Prüfungen (lesend, kein PHPUnit-Gesamtlauf):**
- `git --no-pager diff -- src\Services\ZeitErinnerung.php docs\installation.md tests\Api\ZeitErinnerungTest.php` geprüft.
- `php vendor\bin\phpunit --filter ZeitErinnerung` → **GRÜN** (6 Tests, 124 Assertions).

## 6. Lernpunkte

- Gesamtläufe (Leitstand, E-082) auf dem Endstand: SQLite 492 Tests OK (2 übersprungen), PostgreSQL 17 492 Tests OK
  (3 übersprungen); `npm.cmd run test:js` 12 Tests OK; PHPStan ohne Fehler; `check-versions --strict` grün.
- Datensicherheit vor Release geprüft (Nutzerauftrag): keine Migration/Schemaänderung; keine geänderte oder neue
  schreibende SQL-Anweisung (INSERT/UPDATE/DELETE) auf Zeit- oder Gleitzeitbuchungen; neue Services nur lesend;
  Frontend-Speicherpfade unverändert. Einzige Verhaltensänderung beim Schreiben: Tagesauswahl **neuer**
  Admin-Zeitraumbuchungen. Bestehende Buchungen bleiben unverändert; nur berechnete Anzeigewerte (Saldo, Jahres-+/-,
  Monats-Mail) ändern sich.
- Neu: E-036 (gemeinsame Fallliste PHP/JS), E-083 (Datenquellen-Umstellung: Cron-Einstiegspunkte prüfen),
  E-084 (Schnittstellen-Vertrag bindend).
- E-041 zum 2. Mal (Monats-Mail ohne betriebliche Feiertage durch duplizierte Logik) → Regel in
  `frontend.instructions.md` und `php-backend.instructions.md` übernommen.
- E-072 zum 3. Mal: 5 Explore-Befunde „Hoch/Mittel“ vom Leitstand widerlegt (Teil A.3) – Regel bereits im Leitstand.
- E-081 zum 2. Mal (übernommene Doku-Cronzeit) – Regel bereits in AGENTS.md.
- Hinweis: `src/Services/UpdatePruefung.php` war während des AP im Arbeitsverzeichnis geändert (nicht von diesem AP,
  nicht dokumentiert) – nicht committet, an den Nutzer gemeldet.
# AP-20261119-testabdeckung-klicktests: Testabdeckung massiv ausbauen inkl. Playwright-Klicktests

| Feld | Wert |
|---|---|
| Status | **pausiert** – wartet auf stabilen Commit-Stand der parallelen Arbeit AP-20261119-sammel-aenderungen (s. Hinweis unten) |
| Typ | Feature |
| Testläufe rot | 1 (B6 G2 Erstlauf, Ursache: Fremdbearbeitung, s. u.) |
| Review-Runden | B0: 3 (2× CHANGES_REQUESTED, dann APPROVE); B6: 2 (1× CHANGES_REQUESTED, dann APPROVE) |
| Commit | – (Sammel-Commit erst nach B0+B6+B7, Nutzervorgabe 2026-11-19) |

## 0. Pause (2026-11-19, 22:04) – vor B6-G2-Abschluss und B7

**Grund:** Im selben Arbeitsverzeichnis läuft parallel eine zweite, unabhängige Sitzung am AP
`AP-20261119-sammel-aenderungen` (Commits `4e8997c`, `1418aca`, „Co-authored-by: Copilot“), zum
Zeitpunkt der Pause mit 45 unbestagten Änderungen im Arbeitsverzeichnis (u. a. `public/script.js`
mitten in Bearbeitung, neue Datei `tests/Api/RechteHaertungTest.php`). Das hat den ersten echten
B6-Klicktest-Lauf verfälscht:

- `scripts/clicktest.ps1` brach korrekt ab (`script.min.js` älter als das fremd bearbeitete `script.js`).
- `EndpunktInventarTest` (B0) ist ROT, weil `tests/Api/RechteHaertungTest.php` (fremd, neu, unbestagt)
  bereits 20 Endpunkte abdeckt, die in `tests/fixtures/endpunkt-luecken.json` noch als Lücke stehen –
  der Wächter funktioniert korrekt, der Auslöser ist aber nicht meine Arbeit.
- Playwright-Smoke-Fallback lief gegen den instabilen `script.js`-Zwischenstand: Chromium 3/3 grün,
  Firefox 2/3 (`navigation.spec.mjs` auf `firefox-tablet` fehlgeschlagen, Klick von `#sysHinweisBanner`
  verdeckt), WebKit 1/3 (`anmeldung.spec.mjs` auf `webkit-desktop`+`webkit-tablet`, `pageerror`
  „... due to access control checks“). **Diese drei Befunde sind NICHT verwertbar** (könnten echte
  Frontend-/Responsive-Bugs sein oder Artefakte der gleichzeitigen Fremdbearbeitung) – nach Freigabe
  durch den Nutzer entschieden: warten statt werten, Lauf wird komplett wiederholt.

**Nutzerentscheidung (2026-11-19):** Option 1 – warten, bis die parallele Sitzung einen stabilen
Commit-Stand erreicht hat. B6-G2 und B7 pausiert.

**To-do nach Fortsetzung (sobald die andere Sitzung committet hat):**
1. `git log`/`git status` neu prüfen, Ausgangslage gegen neuen HEAD verifizieren.
2. `tests/fixtures/endpunkt-luecken.json` gegen den neuen Stand aktualisieren (`EndpunktInventarTest`
   zeigt die Differenz direkt an – nur tatsächlich inzwischen getestete Einträge entfernen, nichts raten).
3. B6-Klicktest-Lauf sauber wiederholen (`npm run build` + `npm run minify`, dann `scripts/clicktest.ps1`
   komplett, alle 9 Projekte) gegen einen stabilen, committeten Frontend-Stand.
4. Firefox-tablet- und WebKit-Desktop/Tablet-Befunde neu bewerten: nur als echten Befund in §4/Befundliste
   übernehmen, wenn er auch gegen den stabilen Stand reproduziert.
5. B6 G2 im AP final eintragen (GRÜN), danach B7 (Workflow-Klicktests + Anleitungsabgleich) umsetzen.

## 1. Auftrag

Nutzerauftrag (2026-11-19), verbindliche Entscheidungen:

1. „100 % Testing" = pragmatisch: 100 % aller API-Endpunkte und Nutzer-Workflows durch Tests
   abgedeckt; Code-Coverage wird gemessen (PHPUnit-Coverage-Report) und schrittweise erhöht –
   KEIN hartes 100 %-Zeilen-Gate.
2. Klicktests mit Playwright in allen drei Browser-Engines: Chromium, Firefox, WebKit. Zusätzlich
   mehrere Bildschirmgrößen (Desktop, Tablet, Smartphone) je Browser, um
   Anzeigeprobleme/Responsive-Bugs aufzudecken.
3. Ausführungs-Trigger: Schnelle Tests (gezielte PHPUnit-Läufe + `npm.cmd run test:js`) laufen bei
   jeder Änderung wie bisher vor jedem Commit. Die Klicktests laufen NICHT bei jedem Commit,
   sondern nur auf ausdrückliche Aufforderung oder nach mehreren gesammelten Änderungen – als
   eigenes Script (z. B. `scripts/clicktest.ps1` bzw. Make-Target), das ein Mensch oder der
   Leitstand startet.
4. Zweck der Klicktests über reine Regression hinaus: (a) Bugs im Frontend aufdecken,
   (b) Anzeigeprobleme bei verschiedenen Bildschirmgrößen/Browsern sichtbar machen (Screenshots
   bei Fehlern), (c) Lücken/Fehler in `public/bedienungsanleitung.html` aufzeigen (getestete
   Workflows mit der Anleitung abgleichen), (d) daraus konkrete Frontend-Verbesserungen ableiten.
   Befunde müssen als Bericht für den Reviewer aufbereitet werden (z. B. JUnit/HTML-Report +
   Befundliste im Arbeitspaket), damit Tests dem Reviewer Fehler aufzeigen.
5. Alles als wiederverwendbare Scripts im Repo (Windows-tauglich, PowerShell; `npm.cmd`; `php`
   ggf. über absoluten WinGet-Pfad).

Auftrag an den Architekten für diesen Schritt:

- **Ist-Analyse:** vorhandene Tests (`tests/Unit`, `tests/Api`, `tests/js`), vorhandene Scripts
  (`scripts/`), `phpunit.xml.dist`, `package.json`. Inventar aller API-Endpunkte/Module
  (`src/`, `modules/*/`, `public/api.php`, `public/din1090_api.php`) gegen vorhandene
  Testabdeckung spiegeln – Lücken benennen. Coverage-Treiber (pcov/xdebug) unter Windows prüfen
  (installiert? aktivierbar? Alternative wenn nicht verfügbar).
- **Plan** mit Arbeitspaket-Bündeln (Reihenfolge, je Bündel betroffene Dateien), Risiken und
  Abnahmekriterien. Struktur nach `../copier-astral-main/template/` und
  `struktur.instructions.md` beachten (Make-Targets, CI-Jobs, Scripts).
- Alle Läufe müssen auf SQLite **und** PostgreSQL funktionieren; Klicktests gegen eine lokal
  gestartete Instanz (PHP-Built-in-Server o. ä.) laufen.
- Vor Arbeitsbeginn `.github/ki/erkenntnisse.md` lesen, insbesondere Kategorien „Tests“,
  „Werkzeuge/Umgebung“, „Struktur (Vorlage copier-astral)“ (u. a. E-030 bis E-036, E-050,
  E-060 bis E-064, E-089 bis E-091).
- Dieses AP ist nur die Planungsphase (Tor G1). Umsetzung erst nach Freigabe durch den Nutzer.

## 2. Plan

<!-- Architekt: Ziel & Abgrenzung · Betroffene Dateien · Struktur (Vorlage copier-astral) · Berücksichtigte Erkenntnisse (E-Nr) · Schritte · Risiken · Abnahmekriterien · Offene Fragen -->

### 2.1 Ist-Analyse (geprüft 2026-11-19)

**Tests heute:** `tests/Api` 33 Dateien (inkl. `ApiTestCase`), `tests/Unit` 18, `tests/js` 3
(`sollzeit`, `uebersicht-summen`, `verwaltung-nav`). Helfer: `tests/Support/TestServer.php`
(Built-in-Server je PHPUnit-Prozess, `BK_DATA_DIR` im Temp-Ordner, `BK_TEST_PHP_ARGS` wird an
Server **und** `runCli()` durchgereicht), `tests/Support/ApiClient.php` (`get/post/upload`),
`ApiTestCase` (Admin per `setup`, Benutzer anlegen). `phpunit.xml.dist`: Suiten `unit`/`api`,
`<source>` nur `src`. `package.json`: kein Playwright. Makefile hat `test-cov` (nur
`--coverage-text --coverage-html`), CI-Jobs ohne Coverage (`coverage: none`).

**Endpunkt-Abdeckung** (String-Abgleich Aufrufe `->get/post/upload('…')` in `tests/` gegen Routen):

| Bereich | gesamt | getestet | Lücken (ungetestete Aktionen) |
|---|---:|---:|---|
| AuthActions | 5 | 5 | – |
| UserActions | 17 | 7 | delete_user, list_users_basic, set_role, set_kuerzel, set_personalnummer, set_visibility, set_show_in_wochenplanung, admin_reset_password, set_subunternehmer, set_theme |
| DataActions | 10 | 8 | project_heartbeat, project_presence |
| SystemJobActions | 8 | 8 | – |
| EmailActions | 5 | 1 | send_vde, send_rechnung, send_din1090, test_smtp |
| BaustelleActions | 19 | 3 | search/view_archive, download_archive_excel/json/all, upload_archive_json/all, reimport_baustelle, repair_archiv_projekte, delete_archive, clear_archive, mark_ordered, archive_offenes_material, load_auswertung_archives, toggle_auswertung_flag, save_sub_sort_order |
| ZeiterfassungActions | 26 | 11 | load_all_zeiterfassung, diagnose_fehlbuchungen, get_zeiterfassung_historie, repair_bookings_bulk, set/get_sollstunden, set_sollstunden_tag, set_soll_tage_woche, get_sollstunden_extended, get/save_gleitzeitkonto_buchung(en), load/save_wochenpruefung, load/save_tagespruefung |
| PlanActions | 11 | 5 | load_wochenplanung, load_wochenplanung_display, get_wochenplan_cal_token, export_wochenplan_ical, archive_all_schnellnotizen, delete_schnellnotiz |
| TerminActions | 3 | 0 | load_termine, save_termin, delete_termin |
| DashboardActions | 5 | 0 | load_dashboard, save/delete_dashboard_item, update_dashboard_item_status, update_dashboard_sort |
| GruppenActions | 4 | 1 | load_gruppen, load_gruppen_for_user, delete_gruppe |
| CrudActions | 12 | 8 | export_kunde, dsgvo_delete_kunde, edit/delete_dienstleister |
| FileActions | 10 | 0 | alle (upload/list/download/delete/rename/move_file, save_generated_file, save_tagebuch_export, save_weekly_export, save_tagesbericht) |
| AdminActions | 21 | 10 | get/set_permissions, get/save_storage_paths, browse_directory, download_audit_log, erinnerung_log, trigger_erinnerung, trigger_material_erinnerung, trigger_backup_email, trigger_stundenauswertung_email |
| CatalogActions | 13 | 5 | datanorm_status/upload/clear, metallprofile_catalog, metallprofile_auto_update, hicad_list, hicad_import, material_top_used |
| AiActions | 1 | 0 | ai_scan_material |
| ExportActions | 4 | 1 | export_inform_csv, subunternehmer_report, generate_zugferd |
| OciActions | 6 | 0 | alle (oci_list/save/delete_lieferant, oci_prepare, oci_start, oci_hook) |
| **api.php Routen** | **180** | **73** | **107** |
| Vor-Routen `features`/`list_modules`/`module` | 3 | 1 | list_modules, module-Weiterleitung (400 ohne `module`/`sub`) |
| Modul aufmass (`action=module&module=…&sub=…`) | 8 | 0 | alle |
| Modul lager | 12 | 0 | alle |
| Modul vde0100 | 23 | 0 | alle |
| `din1090_api.php` (`sub`) | 32 | 2 | alle außer list_welders, save_welder |

Ungetestete Bereiche ohne Klassenbezug in Tests: `src/Core/*` (ModuleLoader, AbstractModule,
EventBus), `src/Services/{AuditService,BuchungValidator,LicenseService,MailService,PriceFilter}`,
`modules/*/…Database.php`. CLI (`bin/console`, 12 Befehle) und `cron_*.php` teils über
`App/Console*Test` abgedeckt – genaue Lücken liefert der Inventar-Wächter (Schritt B0.4).
Frontend: `index.html` lädt `script.min.js` + `dist/core.js` (Vite, gitignored → Build nötig);
`index.html`/`login.html` leiten bei `innerWidth ≤ 768` auf `mobile.html` um;
`mobile_light.html` (Stundenerfassung Light), `wochenplan_display.html`. Keine externen Assets.

**Coverage-Treiber (real geprüft):** PHP `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`,
8.2.31 **ZTS** VC++2019 (vs16) x64, `php.ini` im selben Ordner. `pcov` **nein**, `xdebug` **nein**,
keine `php_pcov.dll`/`php_xdebug.dll` in `ext`. `pdo_pgsql` auskommentiert, per
`-d extension=pdo_pgsql` ladbar (wie `test-pgsql.ps1`). Aktivierbar: `php_pcov-1.0.12-8.2-ts-vs16-x64.zip`
(downloads.php.net/~windows/pecl) bzw. Xdebug-Windows-DLL → `ext\`, Laden ad hoc per `-d extension=pcov`
ohne `php.ini`-Änderung. Alternativen: CI (`shivammathur/setup-php` `coverage: pcov`) oder Docker
`php:8.2-cli` + `pecl install pcov`. Weitere Umgebung: Node 24.14, npm 11.9, Docker 29.4,
**kein `make`, kein `pwsh`** (nur Windows PowerShell 5.1), kein Playwright/Browser installiert.
**Wichtig:** PHPUnit-Coverage misst nur den PHPUnit-Prozess. Die API-Tests laufen im
Built-in-Server (eigener Prozess) – ohne Zusatz zeigen alle `src/Handlers/*` ~0 %.

### 2.2 Ziel & Abgrenzung

Ziel: (1) Jede API-Aktion, jede Modul-/DIN-1090-Unteraktion und jeder CLI-Befehl hat mindestens
einen Test; ein Wächtertest verhindert neue ungetestete Endpunkte. (2) Zeilen-Coverage wird inkl.
Server-Prozess gemessen und berichtet (kein Gate). (3) Playwright-Klicktests in Chromium, Firefox,
WebKit × Desktop/Tablet/Smartphone, jeder Abschnitt der Bedienungsanleitung ist einem Klicktest
zugeordnet; Befunde als Bericht für Reviewer. (4) Alles per Script, Windows PowerShell 5.1-tauglich.

Nicht Teil: Beheben gefundener Fehler in Code/Frontend/Anleitung (→ Folge-APs aus der Befundliste,
siehe Frage F2); visuelle Pixel-Baselines (`toHaveScreenshot`); hartes Coverage-Gate; JS-Unit-Tests
für `script.js` (nur über Klicktests); Klicktests in Pre-Commit oder CI-Pflichtlauf; Umbau von
Produktivcode für Testbarkeit (Ausnahme nur mit Rückfrage, E-034); Änderung an `public/bedienungsanleitung.html`
(keine sichtbare Änderung in diesem AP, E-081 greift erst in den Folge-APs).

### 2.3 Struktur (Vorlage copier-astral)

| Vorlage | hier | Begründung/Abweichung |
|---|---|---|
| `make test-cov` (pytest-cov, `--cov-report=xml`, term-missing) | `make test-cov` + `scripts/test-cov.ps1` → `coverage/html`, `coverage/clover.xml`, Text-Zusammenfassung | gleicher Target-Name; `.ps1` wie `test-pgsql.ps1`, weil `make` unter Windows fehlt |
| CI `test`-Job ruft `make test-cov`, Codecov | eigener CI-Job `coverage` (PHP 8.3, pcov), Artefakt-Upload, kein Codecov | Codecov entfällt laut Struktur-Regel; Matrix bleibt schnell (`coverage: none`) |
| `[tool.coverage.run] source` | `<source>` in `phpunit.xml.dist` um `modules` erweitern | |
| Marker `slow` | `#[Group('slow')]` für lange neue API-Tests | |
| keine E2E-Vorlage | `tests/e2e/*.spec.mjs`, `playwright.config.mjs` (Wurzel, wie `vite.config.js`), `scripts/clicktest.ps1`, Make-Target `clicktest`, npm-Script `test:click` | Abweichung: Vorlage hat kein E2E; Name `clicktest` = Nutzervorgabe; Zeile in `struktur.instructions.md` nachtragen |
| `docs/contributing.md` | `docs/entwicklung.md` (Abschnitte Befehle + Tests) | |

### 2.4 Berücksichtigte Erkenntnisse

E-013/E-015 (anonyme Endpunkte, POST-only/405 je Aktion testen) · E-026/E-095 (Werkzeug-Fakten
real prüfen – hier pcov/Playwright) · E-030/E-031 (Windows-Dateisperren, `resetData`) · E-032
(Gesamtläufe asynchron mit Log) · E-034 (externe Abrufe nur über vorhandene Injektion, sonst
Fehlerpfad) · E-036 (keine neuen JS-Pakete außer `@playwright/test`) · E-050 · E-060/E-061
(`-Php`-Parameter, `npm.cmd`) · E-062 (`^` direkt in `composer.json`) · E-064 (Port belegt → Abbruch)
· E-067 (Prozess-/Pfadcode zusätzlich `php:8.2-cli` unter `/home/runner/…`) · E-070 (alle neuen
Testdateien in §3/§4) · E-071 (Tester schreibt nur `tests/`) · E-081 · E-087 (Scripts per
Create/Node, PS 5.1) · E-089 · E-090/E-092 (Tests dauerhaft, Gesamtläufe vor Release) · E-091/E-093
(Bündel, Fundstellen statt ganzer Dateien) · E-096 (IDs in Klicktest-Selektoren als String vergleichen).

### 2.5 Bündel, Dateien und Schritte (Commit-Reihenfolge)

Jedes Bündel = ein Durchlauf Entwickler → Reviewer (G3) → Tester (G2) (E-091/E-094). Bei B1–B5 schreibt
überwiegend der Tester (nur `tests/`); Infrastruktur (B0, B6) macht der Entwickler.

**Umfangs-/Ablaufänderung des Nutzers (2026-11-19, nach G1):** In diesem Durchlauf werden nur
**B0 → B6 → B7** umgesetzt. B1–B5 (API-Testbündel für die 107 Lücken aus 2.1) werden
**zurückgestellt** und als eigenes Folge-Arbeitspaket angelegt, sobald B0/B6/B7 fertig sind –
nichts geht verloren: `tests/fixtures/endpunkt-luecken.json` (aus B0) bleibt die Grundlage, der
Wächtertest `EndpunktInventarTest` sichert, dass die Liste bis dahin nur schrumpfen kann.
Außerdem **kein Commit je Bündel mehr**: alle Änderungen bleiben unbestagt/ungesammelt im
Arbeitsverzeichnis, bis B0+B6+B7 vollständig durchlaufen und grün sind (SQLite, bei Bedarf
PostgreSQL, `npm.cmd run test:js`); danach mehrere thematisch getrennte Conventional Commits mit
expliziten Pfaden in einem Rutsch. Kein Push ohne Rückfrage (unverändert).

**B0 – Coverage-Messung + Endpunkt-Wächter** (`test:`/`ci:`)
1. `composer.json`: `require-dev` `"phpunit/phpcov": "^10.0"` (zu PHPUnit 11; Version beim Einbau per `composer why-not` prüfen) + `composer.lock`.
2. `tests/Support/coverage-prepend.php`: nur aktiv, wenn `BK_COVERAGE_DIR` gesetzt und pcov/xdebug geladen; startet `SebastianBergmann\CodeCoverage` (Filter `src`, `modules`), schreibt je Anfrage/CLI-Lauf eine `.cov` per Shutdown-Funktion. Einbindung über `BK_TEST_PHP_ARGS` (`-d auto_prepend_file=…`), `TestServer` bleibt unverändert.
3. `scripts/test-cov.ps1` (`-Php`, `-Treiber pcov|xdebug`, `-PhpUnitArgs`): prüft Treiber ladbar (`-d extension=pcov`), sonst Abbruch mit Hinweis; PHPUnit mit `--coverage-php coverage/parts/phpunit.cov`, danach `vendor/bin/phpcov merge` → `coverage/html`, `coverage/clover.xml`, Textsumme. Makefile `test-cov` analog. `phpunit.xml.dist` `<source>` + `modules`.
4. `tests/Unit/EndpunktInventarTest.php` + `tests/fixtures/endpunkt-luecken.json`: liest Routen aus `public/api.php`, Vor-Routen, `dispatch()` der `modules/*/backend/Module.php`, `Din1090Actions`, Befehlsnamen aus `src/Console/Kernel.php`; sucht Aufrufe in `tests/`. Rot, wenn ein Endpunkt weder getestet noch in der Lückenliste steht **oder** ein Lückeneintrag inzwischen getestet ist (Liste darf nur schrumpfen, wie PHPStan-Baseline). Start: aktuelle 107 + 2 + 43 + 30 Einträge + CLI-Lücken.
5. `tests/Support/ApiClient.php`: Helfer `module(string $modul, string $sub, array $body = [])` und `din1090(string $sub, array $body = [])`, damit Wächter und Tests einheitlich erkennen.
6. `.github/workflows/ci.yml`: Job `coverage` (setup-php `coverage: pcov`, `make test-cov`, `actions/upload-artifact` `coverage/`), kein Schwellwert. `.dockerignore`: `coverage/`.
7. Erstmessung lokal; Zeilen-Coverage gesamt und je Ordner in §3 eintragen (Ausgangswert).

**B1–B5: zurückgestellt (2026-11-19) – Folge-AP nach Abschluss dieser Serie.** Pläne unverändert
stehen gelassen (Dateien/Umfang unten), damit beim Folge-AP nichts neu geplant werden muss.

**B1 – API: Benutzer, Stammdaten, Verwaltung** (`test:`) – `tests/Api/BenutzerVerwaltungTest.php`,
`StammdatenTest.php` (Dienstleister, `export_kunde`, `dsgvo_delete_kunde`), `GruppenTest.php`,
`AdminRechteTest.php` (permissions, storage_paths, `browse_directory` inkl. Pfadsperre/Traversal,
`download_audit_log`, `erinnerung_log`, `trigger_*` mit Rolle/405), `ProjektPraesenzTest.php`.

**B2 – API: Projekte, Archiv, Dateien, Kataloge, Export** – `ArchivTest.php` (16 Aktionen inkl.
Upload-Validierung/Zip-Slip), `DateienTest.php` (Traversal, fremde Projekte, Umbenennen/Verschieben),
`KatalogTest.php` (Datanorm-Status/Upload/Clear, Metallprofile, HiCAD, `material_top_used`),
`ExportTest.php` (`export_inform_csv`, `subunternehmer_report`, `generate_zugferd` – ZUGFeRD-XML prüfen).

**B3 – API: Zeiterfassung, Planung, Termine, Dashboard** – `ZeiterfassungVerwaltungTest.php`
(Soll-/Gleitzeit-/Prüf-Aktionen, Historie, Diagnose, Bulk-Reparatur; Parität mit
`tests/fixtures/sollzeit/faelle.json` wo Rechenregeln, E-036/E-041), `WochenplanungTest.php`
(inkl. iCal-Token: anonymer Abruf nur mit gültigem Token, E-013), `TermineTest.php`, `DashboardTest.php`,
Schnellnotizen-Rest in `SchnellnotizUpdateTest.php` ergänzen.

**B4 – API: Mail, extern, CLI/Cron** – `tests/bin/fake-smtp.php` + `tests/Support/FakeSmtp.php`
(lokaler SMTP-Empfänger, nur Tests, F6); `MailVersandTest.php` (`send_vde/rechnung/din1090`,
`test_smtp`, `trigger_backup_email`), `OciTest.php` (Lieferanten-CRUD, `oci_prepare/start`, `oci_hook`
CSRF-Ausnahme nur dort), `KiScanTest.php` (`ai_scan_material`: ohne Schlüssel definierter Fehler, Rechte),
CLI-/Cron-Lücken laut Wächter (`ConsoleBefehleTest.php`).

**B5 – Module + Lizenz** – `ModulSystemTest.php` (`list_modules`, `module` ohne Parameter 400,
unbekanntes Modul, Lizenz-Sperre über `license.json` im Testdatenordner, Dev-Modus alle frei),
`ModulAufmassTest.php`, `ModulLagerTest.php`, `ModulVde0100Test.php`, `Din1090Test.php`.
Ende B5: Lückenliste leer (oder nur begründete Einträge, Freigabe durch Nutzer); Coverage-Zweitmessung in §4.

Regel für B1–B5 je Endpunkt: Gutfall (bei externer Abhängigkeit: definierter Fehlerpfad
„nicht konfiguriert“), bei zustandsändernden Aktionen zusätzlich Rechte-Negativfall und GET→405
(E-015). Lange Tests `#[Group('slow')]`. Jedes Bündel gezielt `--filter` auf SQLite **und**
`scripts/test-pgsql.ps1 -PhpUnitArgs '--filter','…'`.

**B6 – Klicktest-Infrastruktur** (`test:`/`chore:`)
1. `package.json`: devDependency `@playwright/test` (exakte Version, Renovate pflegt), Script
   `"test:click": "playwright test"`; `package-lock.json`. `test:js`-Glob bleibt unberührt.
2. `playwright.config.mjs`: `testDir: tests/e2e`; 9 Projekte `{chromium,firefox,webkit}-{desktop,tablet,phone}`:
   Desktop 1366×768; Tablet 834×1194 (`hasTouch`, → `index.html`); Phone 390×844 (`hasTouch`,
   `isMobile` nur Chromium/WebKit – Firefox unterstützt `isMobile` nicht, → `mobile.html`).
   `screenshot: 'only-on-failure'`, `trace: 'retain-on-failure'`, `serviceWorkers: 'block'`,
   Reporter `list`, `html` (`playwright-report/`), `junit` (`test-results/junit.xml`), `json`.
   Tests per Projekt-Metadaten `ui: desktop|mobile` gefiltert.
3. `tests/e2e/fixtures.mjs`: worker-eigener PHP-Built-in-Server (freier Port, `127.0.0.1`, eigenes
   `BK_DATA_DIR` im Temp-Ordner, PHP-Pfad aus `BK_PHP`) – Grund: Built-in-Server ist unter Windows
   einprozessig (`PHP_CLI_SERVER_WORKERS` nur Linux) und Daten bleiben je Worker isoliert; Seed per
   API (`setup`, Admin, Monteur, Beispielkunde/-projekt, eindeutige Namen je Test). Auto-Prüfungen
   je Seite: keine `pageerror`/`console.error`, keine API-Antwort ≥ 500, kein horizontaler Überlauf
   (`scrollWidth > innerWidth`), alle externen Anfragen blockiert.
4. `scripts/clicktest.ps1` (PS 5.1): Parameter `-Php`, `-Db sqlite|pgsql` (pgsql: Wegwerf-Container
   wie `test-pgsql.ps1`, Port-Prüfung E-064, `--workers=1`), `-Project`, `-Grep`, `-Headed`, `-Install`
   (`npx.cmd playwright install chromium firefox webkit`). Ablauf: Voraussetzungen prüfen, `npm.cmd run build`
   (`dist/` gitignored), Abbruch mit Hinweis wenn `script.min.js` älter als `script.js` (keine stillen
   Änderungen an eingecheckten Dateien), Playwright starten, `scripts/clicktest-befunde.mjs` erzeugen,
   Aufräumen in `finally` (Server, Temp-Ordner, Container), Exitcode durchreichen. Weigert sich, wenn
   `BK_DATA_DIR` auf `data/` des Repos zeigt.
5. `scripts/clicktest-befunde.mjs`: JSON-Report → `test-results/befunde.md` (je Fehler: Kategorie
   Bug/Anzeige/Anleitung, Anleitungs-Anker, Browser × Viewport, erwartet/tatsächlich, Screenshot-/Trace-Pfad).
6. Makefile `clicktest` (Linux-Gegenstück, `npx playwright test`), `.gitignore`/`.dockerignore`:
   `/playwright-report/`, `/test-results/`. Nicht in `.pre-commit-config.yaml`, nicht in `ci.yml`.
7. Smoke-Specs: `tests/e2e/anmeldung.spec.mjs` (Login, Umleitung ≤ 768 px → `mobile.html`, Logout),
   `tests/e2e/navigation.spec.mjs` (jeder Hauptbereich öffnet ohne Fehler/Überlauf).
8. Doku: `docs/entwicklung.md` (Befehle-Tabelle `test-cov`, `clicktest`; Abschnitt Tests: Coverage,
   Wächter, Klicktests, Berichtspfade), `AGENTS.md` (Befehlszeile Klicktests „nur auf Aufforderung“),
   `.github/instructions/tests.instructions.md` (E2E-Regeln: eindeutige Testdaten, keine festen Waits,
   Locator nach Rolle/Text, IDs als String E-096), `.github/instructions/struktur.instructions.md` (Tabellenzeile E2E).

**B7 – Workflow-Klicktests + Anleitungsabgleich** (`test:`)
1. `tests/e2e/anleitung-abdeckung.json`: jeder Anker aus `public/bedienungsanleitung.html`
   (`section id` 1–17 und `h3 id`) → Spec-Datei(en). `tests/e2e/anleitung.spec.mjs` (nur ein Projekt)
   rot bei Anker ohne Zuordnung oder Zuordnung ohne Spec.
2. Specs je Kapitel (Desktop/Tablet): `projekte`, `kataloge`, `kunden-rechnungen`, `zeiterfassung`,
   `wochenplanung`, `termine`, `dashboard`, `auswertung`, `archiv`, `sicherung`, `ueberwachung`,
   `suche`, `module`, `einstellungen-rechte` (Monteur sieht Admin-Bereiche nicht). Mobile (Phone):
   `mobile-zeiterfassung`, `mobile-termine`, `mobile-notizen`, `mobile-light` (Stundenerfassung Light).
   Jeder Test trägt Annotation `anleitung: #anker` und prüft die in der Anleitung genannten
   Bedienelemente/Texte; Abweichung = Befund Kategorie „Anleitung“.
3. Erster Volllauf (9 Projekte) durch Leitstand; `befunde.md` als Befundliste in §4, Vorschläge für
   Folge-APs (Frontend-Bugs, Responsive, Anleitung) in §6. Tests, die echte Bugs zeigen, werden
   nicht abgeschwächt: `test.fail()` mit Verweis auf Folge-AP nur nach Nutzerfreigabe (F2).

### 2.6 Risiken

- **Coverage im Server-Prozess:** Prepend wirkt auf jede Anfrage → Lauf mit pcov ~1,5×, mit Xdebug 3–5×
  langsamer; viele `.cov`-Dateien (Merge-Zeit). `BK_TEST_PHP_ARGS` wird an Leerzeichen getrennt →
  Pfade ohne Leerzeichen nötig (Repo-Pfad ok). Coverage nur auf SQLite; PostgreSQL bleibt Funktionslauf.
- **pcov unter Windows:** DLL manuell in WinGet-`ext` (geht bei PHP-Update verloren) – Alternative CI/Docker (F1).
- **SQLite/PostgreSQL:** Module (`*Database.php`) und neue Pfade werden erstmals unter PostgreSQL
  getestet → echte Dialektfehler wahrscheinlich (Befund → Folge-AP, F2). E-064 bei jedem PG-Lauf.
- **Laufzeit:** Gesamtlauf wächst geschätzt SQLite ~2→4 min, PostgreSQL ~5→9 min (E-032 asynchron);
  Klicktest-Volllauf 9 Projekte ~15–30 min.
- **Windows-Sperren:** Datei-/Upload-Tests, `resetData` (E-030/E-031); Prozess-/Pfadcode (FakeSmtp,
  Prepend) zusätzlich unter `php:8.2-cli` (E-067).
- **Sicherheit:** neue Tests müssen Traversal (`browse_directory`, Dateien, Archiv-Upload/Zip-Slip),
  anonyme Endpunkte (iCal-Token, `get_logo`, `features`, `oci_hook`) und Rollen abdecken; Klicktest-Server
  nur `127.0.0.1`, nur Dummy-Daten, Berichte/Screenshots gitignored; nie gegen `data/`.
- **Migration + Fixture / Backup alter Versionen:** keine Schemaänderung → kein `make fixture`;
  `BackupFixtureImportTest` bleibt unverändert grün.
- **Cache-Busting/Version:** keine Änderung an `script.js`/`index.html`/`sw.js` → kein `?v=`; `package.json`
  `version` unverändert (`check-versions` grün). Release nur nach F7.
- **Modul-Lizenz/Feature-Flags:** Dev-Modus (ohne `license.json`) erlaubt alle Module – Sperrfall
  eigens testen; `feature_enabled('ocr'|…)` lokal evtl. aus → OCR-/KI-Pfade nur Fehlerpfad.
- **Playwright:** WebKit unter Windows ≠ Safari iOS; Firefox ohne `isMobile`; Service Worker blockiert
  (sonst Cache-Altstände); Browser-Download ~0,7 GB einmalig; Flakiness → nur `expect`-Wartebedingungen.
- **Werkzeuge:** kein `make`/`pwsh` lokal → `.ps1` muss PS 5.1 können, Dateien per Create/Node (E-087).

### 2.7 Abnahmekriterien

1. `scripts/test-cov.ps1 -Php <pfad>` mit pcov erzeugt `coverage/html/index.html` und `coverage/clover.xml`;
   `src/Handlers/AuthActions.php` hat > 0 % Zeilenabdeckung (Server-Prozess erfasst); ohne Treiber
   Exitcode ≠ 0 mit Hinweistext, kein Teilbericht. Gesamtwert vorher/nachher steht im AP.
2. CI-Job `coverage` lädt `coverage/` als Artefakt hoch und scheitert nicht an einem Prozentwert.
3. `EndpunktInventarTest`: neue Route in `api.php` ohne Test und ohne Lückeneintrag → rot; Lückeneintrag
   für inzwischen getestete Aktion → rot; nach B5 ist `endpunkt-luecken.json` leer bzw. enthält nur
   vom Nutzer freigegebene, begründete Einträge.
4. Je bisher ungetesteten Endpunkt (2.1) existiert ein Test für Gutfall bzw. definierten Fehlerpfad;
   zustandsändernde Aktionen zusätzlich Rollen-Negativfall und GET → 405.
5. Alle neuen PHPUnit-Tests grün auf SQLite und PostgreSQL (`scripts/test-pgsql.ps1`), `npm.cmd run test:js`
   grün, PHPStan ohne neue Baseline-Einträge, `php-cs-fixer check` grün.
6. `scripts/clicktest.ps1 -Php <pfad>` startet unter Windows PowerShell 5.1 lokale Instanz(en) auf
   `127.0.0.1`, führt 9 Projekte (3 Engines × Desktop/Tablet/Smartphone) aus, liefert
   `playwright-report/index.html`, `test-results/junit.xml`, `test-results/befunde.md`, Screenshot + Trace
   nur bei Fehlern, Exitcode ≠ 0 bei Fehler; hinterlässt keine Prozesse/Temp-Ordner/Container.
7. Klicktests laufen weder per Pre-Commit noch in `ci.yml`; `npm.cmd run test:js` führt keine E2E-Specs aus.
8. Jede Seite in jedem Projekt schlägt fehl bei JS-Fehler, API-Antwort ≥ 500 oder horizontalem Überlauf;
   Smartphone-Projekte landen auf `mobile.html`, Tablet/Desktop auf `index.html`.
9. Jeder Anker der Bedienungsanleitung ist in `anleitung-abdeckung.json` einer vorhandenen Spec zugeordnet
   (Wächter-Spec grün); `befunde.md` nennt je Befund Kategorie, Anker, Browser × Viewport, Screenshotpfad.
10. Erster Klicktest-Volllauf ist mit Befundliste im AP dokumentiert; Folge-APs sind vorgeschlagen.
11. Doku (`docs/entwicklung.md`, `AGENTS.md`, `tests.instructions.md`, `struktur.instructions.md`) beschreibt
    `test-cov`, Wächter und `clicktest` inkl. Trigger „nur auf Aufforderung“.

### 2.8 Offene Fragen an den Nutzer

- **F1 Coverage-Treiber:** pcov-DLL (`php_pcov-1.0.12-8.2-ts-vs16-x64`) in den WinGet-PHP-Ordner `ext` legen
  (Laden nur per `-d`, `php.ini` bleibt)? Oder Xdebug, oder lokal gar nicht und nur CI/Docker?
- **F2 Gefundene Fehler:** Backend-/Frontend-/Anleitungsfehler aus den neuen Tests in Folge-APs beheben
  (Vorschlag; Test kommt dann mit dem Fix) oder kleine Fixes direkt im jeweiligen Bündel?
- **F3 Klicktests auch auf PostgreSQL** (`-Db pgsql`, langsamer, 1 Worker) als Option genügt – Standard SQLite?
- **F4 CI:** optionaler Klicktest-Job nur per `workflow_dispatch` (manuell) – gewünscht oder ganz lokal?
- **F5 Viewports:** Desktop 1366×768, Tablet 834×1194, Smartphone 390×844 ok? Zusätzlich Grenzfall 768 px
  und Desktop 1920×1080?
- **F6 Fake-SMTP** nur unter `tests/` für Mail-Gutfälle ok?
- **F7 Release:** reine Test-/Tooling-Änderungen ohne Versionserhöhung und ohne Tag?
- **F8 Agent-Dateien:** Leitstand/Reviewer-Agent um „Klicktest auf Aufforderung + `befunde.md` lesen“ ergänzen?

**Antworten des Nutzers (2026-11-19):**

- F1: pcov-DLL lokal in den WinGet-PHP-`ext`-Ordner legen; `test-cov.ps1` prüft, ob pcov geladen ist,
  und bricht sonst mit klarer Anleitung ab (robust gegen PHP-Updates, kein `php.ini`-Zwang).
- F2: Gefundene Fehler NICHT im Testbündel mitfixen, sondern sauber in `befunde.md`/§6 dokumentieren
  und als separate Folge-Arbeitspakete vorschlagen.
- F3: Standard SQLite; PostgreSQL nur per Option `-Db pgsql`.
- F4: Klicktest-Job in `ci.yml` nur optional per `workflow_dispatch`, kein Pflichtlauf.
- F5: 3 Viewports – Desktop 1366×768, Tablet 834×1194, Smartphone 390×844 (kein zusätzlicher Grenzfall).
- F6: Fake-SMTP erlaubt, ausschließlich unter `tests/`.
- F7: Reine Test-/Tooling-Änderungen ohne Versionserhöhung/Tag; nur lokale Conventional Commits je Bündel.
- F8: `ki-leitstand.agent.md` und `reviewer.agent.md` um „Klicktest nur auf Aufforderung“ und
  `befunde.md`-Auswertung ergänzen (eigener `docs(ki)`-Commit).

**Freigabe G1:** ☒ durch Nutzer am 2026-11-19

## 3. Umsetzung

**B0 (Runde 1):** `composer.json`/`.lock` (`phpunit/phpcov: ^10.0`, Composer löste 10.0.0 auf – passt zu
phpunit 11.5.56/php-code-coverage 11.0.12, geprüft per `composer show -a`). `phpunit.xml.dist` `<source>`
um `modules` erweitert. Neu: `tests/Support/coverage-prepend.php` (CodeCoverage nur bei gesetztem
`BK_COVERAGE_DIR` + geladenem pcov/xdebug, schreibt je Prozess eine `.cov`), `scripts/test-cov.ps1`
(Treiberprüfung ohne Teilbericht, `--coverage-php` + `phpcov merge`), `Makefile` `test-cov` (Linux-Äquivalent),
`.github/workflows/ci.yml` Job `coverage` (setup-php `coverage: pcov`, `make test-cov`, Artefakt-Upload,
kein Schwellwert), `.dockerignore` `coverage/`. `tests/Support/ApiClient.php` um `module()`/`din1090()`
ergänzt. Neu: `tests/Unit/EndpunktInventarTest.php` + `tests/fixtures/endpunkt-luecken.json` (107 API- +
2 Vor-Routen- + 43 Modul- + 30 DIN1090-Lücken + 3 Cron-Skripte; alle 12 `bin/console`-Befehle bereits
getestet).

Abweichung vom Plan: Ist-Analyse-Tabelle nannte `add_dienstleister` als getestet – der Wächtertest fand es
tatsächlich ungetestet (kein Aufruf in `tests/Api`); in die Lückenliste aufgenommen statt Testdaten
nachträglich zu erfinden.

Erstmessung (`scripts/test-cov.ps1 -Php ...`, PHP 8.2.31 + PCOV 1.0.12, 648 Tests OK/4 skipped):
Gesamt **Lines 45.61 % (6272/13752)**, Methods 37.32 %, Classes 6.45 %. Je Ordner (Statements, clover.xml):
`src/Services` 87.1 %, `src/Auth.php` 83.1 %, `src/Database` 60.0 %, `src/Console` 65.0 %,
`src/Handlers` **40.75 %** (größter Hebel), `modules/{aufmass,din1090,lager,vde0100}` **0 %** (Module-System
noch ungetestet, s. B1 ff.).

### B0 (Runde 2 – Korrektur)
`tests/Support/coverage-prepend.php`: `@mkdir()` entfernt, stattdessen `is_dir()`-Race-sicherer
`if (!is_dir() && !mkdir(..) && !is_dir())`-Check mit `RuntimeException` bei echtem Fehlschlag (kein `@`).
`Makefile` `test-cov`: Treiberprüfung (pcov, sonst xdebug) vor `rm -rf coverage` ergänzt, bricht mit
Fehlermeldung ab statt Teilbericht. Geprüft: `phpunit --filter EndpunktInventarTest` grün (1/1),
`phpstan` ohne Befunde, `scripts/test-cov.ps1 --filter EndpunktInventarTest` lief fehlerfrei mit
PCOV 1.0.12 durch und erzeugte eine `.cov`-Datei.

Prüfungen: `phpstan analyse --memory-limit=2G` → keine Befunde. `phpunit --testsuite unit` → OK (348/3
skipped). Voller Lauf via `test-cov.ps1` → OK (648/4 skipped). Kein Frontend geändert → `check-versions.mjs`
nicht ausgeführt.

### B0 (Runde 3 – Korrektur)
`Makefile` `test-cov`: ermittelten Treiber (`pcov`/`xdebug`) in Shell-Variable `driver` gemerkt (ein
zusammenhängendes `\`-verkettetes Rezept, analog `test-pgsql`) und `-d extension=$$driver` sowohl am
Haupt-`phpunit`-Aufruf als auch in `BK_TEST_PHP_ARGS` ergänzt (Parität zu `scripts/test-cov.ps1`).
Geprüft (nicht `make test-cov` komplett, E-032): korrigierte Rezeptzeilen manuell mit
`--filter EndpunktInventarTest` nachgestellt – `phpunit.cov` (45.825 Byte) in `coverage/parts` entstanden,
Exit 0, Runtime-Zeile bestätigt "PHP 8.2.31 with PCOV 1.0.12". `phpstan` ohne neue Befunde, `phpunit
--testsuite unit` OK (348/3 skipped). Kein Frontend geändert → `check-versions.mjs` nicht ausgeführt.

### B6 (Runde 1)
Neu: `playwright.config.mjs` (9 Projekte chromium/firefox/webkit × desktop/tablet/phone, Metadaten `ui`,
Reporter list/html/junit/json), `tests/e2e/fixtures.mjs` (worker-eigener PHP-Server + Temp-`BK_DATA_DIR`,
`seed` per API, `page`-Auto-Prüfung), `tests/e2e/{anmeldung,navigation}.spec.mjs` (Smoke; Navigation auf
Sidebar/Action-Bar-Hauptpunkte begrenzt, volle Abdeckung folgt in B7), `scripts/clicktest.ps1`,
`scripts/clicktest-befunde.mjs`. Geändert: `package.json`/`.lock` (`@playwright/test` exakt 1.65.1,
`test:click`), `Makefile` (`clicktest`), `.gitignore`/`.dockerignore`, `docs/entwicklung.md`, `AGENTS.md`,
`.github/instructions/{tests,struktur}.instructions.md`, `.github/workflows/ci.yml` (Job `clicktest`
nur `workflow_dispatch`, Abweichung F4 laut §2.8).

Ergänzung ggü. Plantext: `fixtures.mjs` liest zusätzlich `BK_TEST_PHP_ARGS` (wie `TestServer.php`) für
`-Db pgsql` (pdo_pgsql fehlt sonst); "Failed to load resource" zählt nicht als `console.error`-Befund
(deckt sich mit Status-≥500-Prüfung, sonst Falschmeldung bei erwarteten 404 z. B. `whatsapp_api.php`).

Geprüft (chromium lokal installiert, firefox/webkit bewusst dem Tester überlassen, E-032):
`playwright test --list` → 18 Tests/9 Projekte. `clicktest.ps1` 3× in Folge → je 6/6 grün
(Worker-Isolation + echter `npm run build`/Altersprüfung bestätigt). `phpstan` ohne neue Befunde,
`phpunit --testsuite unit` OK (349/3 skipped), `npm run test:js` 43/43 OK, `check-versions.mjs --strict` OK.

## 4. Tests

<!-- Tester: GRÜN/ROT, neue Tests, Ergebnis SQLite/PostgreSQL (je Runde) -->

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| 2.7.3 Endpunkt-Inventar (neu ungetestet=>ROT, erledigte Luecke=>ROT) | tests/Unit/EndpunktInventarTest.php::testAlleEndpunkteGetestetOderInLueckenliste; ::testVergleicheMeldetNeueUndErledigteLuecken | OK (349/3 skipped) | n/a (B0 ohne Schemaaenderung) | OK (43/43) |
| G2 Pflichtlaeufe | php vendor/bin/php-cs-fixer check --diff tests/; php vendor/bin/phpunit --testsuite unit | phpunit OK + cs-fixer OK | n/a | siehe oben |
| B6 G2 Erstlauf Klicktests | scripts/clicktest.ps1; npx playwright test tests/e2e/anmeldung.spec.mjs tests/e2e/navigation.spec.mjs; phpunit --testsuite unit; npm run test:js | ROT: clicktest.ps1 Abbruch (script.min.js aelter als script.js), Smoke: Chromium 3/3 gruen, Firefox 2/3 (tablet nav FAIL), WebKit 1/3 (desktop+tablet anmeldung FAIL) | n/a (kein SQL/Schema-Thema) | Reports: html+junit ja (junit XML valide), befund.md nein; test:js OK, phpunit ROT (EndpunktInventar) |

## 5. Review

**Urteil (Runde 1, B0): CHANGES_REQUESTED**

`EndpunktInventarTest` grün nachvollzogen (1/1, isoliert ausgeführt), PHPStan auf den neuen/geänderten
Dateien sauber. Zwei Blocker-Punkte verhindern Freigabe: ein verbotener `@`-Operator in neuem Code
(E-063) und ein fehlender Treiber-Abbruch in `Makefile:test-cov` (Abnahme 1 nur für `scripts/test-cov.ps1`
nachgewiesen, für Linux/CI-Pfad nicht – per Test belegt, dass PHPUnit ohne Treiber "OK, but there were
issues!" meldet statt abzubrechen). Dazu eine Portabilitätslücke bei Pfaden mit Leerzeichen.

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Blocker | `tests/Support/coverage-prepend.php:65` | `@mkdir($bkCoverageDir, 0777, true);` verstößt gegen E-063 (kein `@`-Operator; globaler Error-Handler wirft trotzdem). | `@` entfernen, Rückgabewert prüfen und bei Fehlschlag (und weiterhin fehlendem Verzeichnis) sauber behandeln/loggen statt stumm zu unterdrücken. |
| Blocker | `Makefile:48-54` (`test-cov`-Target) | Anders als `scripts/test-cov.ps1` prüft das Target nicht, ob pcov/xdebug ladbar ist. Beleg (lokal nachgestellt): `php vendor/bin/phpunit --coverage-php …` ohne Treiber liefert "No code coverage driver available" / "OK, but there were issues!" statt Abbruch – `coverage/parts` bleibt leer bzw. `phpcov merge` arbeitet mit einem leeren/irreführenden Report. Abnahmekriterium 1 ("bricht sauber ab, kein Teilbericht") ist damit für den Linux/CI-Pfad nicht erfüllt; im Plantext (B0.3) war "Makefile `test-cov` analog" gefordert, die Abweichung ist in Abschnitt 3 nicht dokumentiert. | Vor dem `phpunit`-Aufruf im Makefile-Target denselben Treiber-Check wie in `test-cov.ps1` einbauen (z. B. `php -d extension=pcov -r "exit(extension_loaded('pcov')?0:1);" || { echo …; exit 1; }`), oder Abweichung bewusst als Hinweis im AP dokumentieren. |
| Major | `Makefile:52`, `scripts/test-cov.ps1` (Variable `BK_TEST_PHP_ARGS`) → `tests/Support/TestServer.php:95` (`preg_split('/\s+/', $extra)`) | Der absolute Prepend-Pfad (`$(CURDIR)/…` bzw. `$repoRoot\tests\Support\coverage-prepend.php`) wird roh in `BK_TEST_PHP_ARGS` geschrieben; `TestServer::phpCommand()` splittet diesen String an Whitespace ohne Quoting. Enthält der Repo-Pfad ein Leerzeichen (unter Windows üblich, z. B. Benutzerprofile mit Vor-/Nachname), zerreißt das die `-d auto_prepend_file=…`-Option und die Coverage-Messung für Built-in-Server/CLI-Kindprozesse schlägt still fehl – ohne Abbruch, also ein unbemerkter Teilbericht (Abnahme 1 erneut berührt). Bisherige `BK_TEST_PHP_ARGS`-Werte enthielten laut Doku nur Extension-Namen ohne Leerzeichen; diese Bündelung ist der erste Fall mit Dateipfad. | Pfad vor der Übergabe quoten oder `BK_TEST_PHP_ARGS` auf ein Array/anderen Übergabeweg umstellen; zumindest als bekannte Einschränkung im AP vermerken. |
| Hinweis | `tests/Unit/EndpunktInventarTest.php` (gesamt) | Erkennung "getestet" beruht auf Regex-Mustern (`->module(…)`, `->din1090(…)`, `runCli(…)`); ein Test, der dieselbe Aktion anders aufruft, würde als ungetestet gewertet und müsste manuell in die Lückenliste – aktuell kein Fehlverhalten beobachtet (Test lief grün), nur als bekannte Grenze vermerkt. | Keine Änderung nötig, ggf. im Docblock als Known Limitation ergänzen. |

Sonst geprüft und unauffällig: `composer.json`/`.lock` (`^10.0` direkt in composer.json, E-062 erfüllt;
`phpcov 10.0.0` passt zu `php-code-coverage ^11.0`/phpunit 11.5), `phpunit.xml.dist` (`modules` nur im
`<source>`-Filter, kein Einfluss auf normale Läufe), `.dockerignore`/CI-Job `coverage` (kein Schwellwert,
Artefakt-Upload, Struktur passt zu `struktur.instructions.md`), `ApiClient.php`-Erweiterung (reine
Helfer, kein Risiko). Keine Secrets/Kundendaten in den neuen Dateien, Umlaute/Encoding in den neuen
Dateien plausibel (E-087). Platzierung aller neuen Dateien unter `tests/`/`scripts/` korrekt (E-071).

**Lernpunkt-Kandidaten:**
- Kategorie Sicherheit/Code-Qualität: `@`-Operator in neu geschriebenem Code trotz bestehender Regel – E-063 existiert bereits, aber wird in Reviews offenbar nicht systematisch per Grep gegengeprüft. Vorschlag: Grep auf `@[a-zA-Z_(]` als Pflichtschritt vor jeder Tester-/Reviewer-Freigabe ergänzen (kein neues `E-Nr` nötig, Verstärkung von E-063).
- Kategorie Tests/Werkzeuge: "Treiber-Abbruch nur in einem von zwei gleichwertigen Scripts (ps1 vs. Makefile)" – noch keine passende `E-Nr`; Kandidat für neue Erkenntnis "Bei plattformgleichen Script-Paaren (ps1/Makefile) Abnahmekriterien für beide Varianten einzeln nachweisen, nicht nur für die getestete Plattform."

**Leitstand-Prüfung (E-072) zum Review Runde 1:** Befunde 1+2 an Fundstelle bestätigt, gehen an den
Entwickler. Befund 3 (`BK_TEST_PHP_ARGS`-Split an Leerzeichen) ist vorbestehendes, bereits in §2.6
Risiken dokumentiertes und akzeptiertes Verhalten (Repo-Pfad ohne Leerzeichen) – kein neuer Fehler
durch B0, keine Korrektur nötig, keine Runde dafür.

### B0 Runde 2

**Urteil: CHANGES_REQUESTED.** Punkt 1 (`tests/Support/coverage-prepend.php:65`) behoben: kein `@` mehr, race-sicheres Doppel-Check-Muster, wirft `RuntimeException` statt zu verschlucken – ok. Punkt 2 (`Makefile:47-52`, `test-cov`) weiterhin fehlerhaft: Der neue Treiber-Check (Z.47-49) prüft `pcov`/`xdebug` nur isoliert per eigenem `-d extension=…`-Aufruf, doch die eigentliche Zeile 52 (`$(PHP) vendor/bin/phpunit --coverage-php …`) übergibt dieses Flag nicht weiter. Lokal nachgestellt (pcov-DLL vorhanden, aber wie hier nicht standardmäßig geladen): Check meldet "ladbar", der reale Lauf liefert dennoch "No code coverage driver available"/"OK, but there were issues!", `coverage/parts` bleibt leer – keine Parität zu `scripts/test-cov.ps1` (dort wird `-d extension=$Treiber` auch am Haupt-`phpunit`-Aufruf gesetzt), B0.3-Anforderung "Makefile analog" weiterhin nicht erfüllt. Fix: ermittelten Treibernamen merken und als `-d extension=$$DRV` an die `phpunit`-Zeile anhängen (Beleg: Testlauf ohne Flag exit 1 ohne `.cov`-Datei, mit Flag exit 0 und `phpunit.cov` erzeugt).

### B0 Runde 3

**Urteil: APPROVE.** Geprüft nur `git diff -- Makefile` (E-094, schmale Korrekturrunde): Zeilen 48-63
merken den ermittelten Treiber jetzt in `driver` (Z.49/51) und verwenden ihn konsistent sowohl im
Haupt-Aufruf `$(PHP) -d extension=$$driver vendor/bin/phpunit --coverage-php …` (Z.61) als auch in
`BK_TEST_PHP_ARGS="-d extension=$$driver -d auto_prepend_file=…"` (Z.59/60) – Parität zu
`scripts/test-cov.ps1` (`-d "extension=$Treiber"` an Haupt- und `$env:BK_TEST_PHP_ARGS` gleichermaßen)
hergestellt, Blocker aus Runde 2 behoben. Rezept bleibt ein zusammenhängender `\`-verketteter Block
(Syntax lokal mit `bash -n` gegen eine mit Beispielwerten aufgelöste Kopie geprüft, exit 0); Dev-Beleg
(`phpunit.cov` entstanden, Exit 0, PCOV-Runtime-Zeile) im AP-Text Runde 3 plausibel und deckungsgleich
mit der Diff-Logik. Keine neuen Blocker/Major gefunden.

Kurzer Stat-Blick auf das übrige B0 (`composer.json/.lock`, `phpunit.xml.dist`, `tests/Support/ApiClient.php`,
`.github/workflows/ci.yml`, `.dockerignore`) bestätigt unveränderten Umfang gegenüber Runde 1 (bereits
APPROVE-fähig); `tests/Support/coverage-prepend.php` enthält weiterhin die in Runde 2 korrigierte
race-sichere `mkdir`-Behandlung ohne `@` (E-063 erfüllt). Damit ist B0 insgesamt **APPROVE**.

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Hinweis | `Makefile:62-63` | `phpcov merge` läuft auch wenn der `phpunit`-Aufruf fehlschlägt (`status` wird erst danach per `exit $$status` ausgewertet); bei komplettem Fehlschlag ohne jede `.cov`-Datei meldet `phpcov merge` vermutlich einen eigenen Fehler, der die eigentliche Ursache verdeckt. Kein Blocker, da `scripts/test-cov.ps1` mit seinem expliziten `-not $covParts`-Check hier robuster ist, aber keine Verschlechterung durch diese Korrekturrunde. | Optional: wie im PowerShell-Pendant vor `phpcov merge` prüfen, ob `coverage/parts/*.cov` existiert, und sonst mit klarer Meldung abbrechen (kann in Folge-AP nachgezogen werden). |

**Lernpunkt-Kandidaten:** keine neuen; E-094 (schmale Korrekturrunde) hat die Prüfzeit in Runde 3 wie
vorgesehen auf den gemeldeten Befund begrenzt.

### B6 (Runde 2 – Korrektur)

Fix zu E-094 (2. Auftreten E-098): `Makefile`-Rezeptzeile `npx playwright test` zu
`BK_PHP=$(PHP) npx playwright test` erweitert – analog `$env:BK_PHP = $Php` in `clicktest.ps1:64`.
`-Db pgsql` bleibt laut Plan (§2 Z.234) bewusst nur in `clicktest.ps1`, nicht im Linux-Target.
Geprüft (nicht `make clicktest` komplett, E-032): `BK_PHP` per Env gesetzt, `node -e` bestätigt
`process.env.BK_PHP` = gesetzter PHP-Pfad (simuliert `fixtures.mjs:90`), danach
`npx.cmd playwright test --project=chromium-desktop --list` → 2/2 Tests gelistet, exit 0.
`phpstan` ohne neue Befunde, `phpunit --testsuite unit` OK (349/3 skipped). Kein Frontend geändert
→ `check-versions.mjs` nicht ausgeführt.

### B6 Review

**Urteil: CHANGES_REQUESTED**

Geprüft: `git diff`/neue Dateien für alle gelisteten B6-Pfade, `npx.cmd playwright test --list`
(18 Tests/9 Projekte, lädt fehlerfrei – kein Gesamtlauf, E-032). Grundstruktur solide: 9 Projekte
exakt wie gefordert, `isMobile` korrekt nur bei chromium/webkit im Phone-Projekt, Reporter
list/html/junit/json vorhanden; `@playwright/test` exakt `1.65.1` gepinnt, keine weiteren direkten
Pakete (nur transitive `playwright`/`playwright-core`, E-036 erfüllt); `.gitignore`/`.dockerignore`
enthalten `/playwright-report/` und `/test-results/`; `ci.yml`-Job `clicktest` läuft nur bei
`workflow_dispatch` (F4 wie dokumentiert); `clicktest.ps1` ist PS-5.1-kompatibel (kein `??`/`?.`),
weigert sich bei `BK_DATA_DIR` = Produktiv-`data/`, prüft Port vor Postgres-Containerstart (E-064)
und räumt in `finally` auf; `fixtures.mjs` isoliert Worker sauber (eigener Port + Temp-`BK_DATA_DIR`),
vergibt eindeutige Seed-Namen je Test und blockiert externe Requests; `BK_TEST_PHP_ARGS` konsistent
zu `tests/Support/TestServer.php` benannt; Doku (`AGENTS.md`, `docs/entwicklung.md`,
`tests.instructions.md`, `struktur.instructions.md`) stellt den reinen Aufforderungs-Charakter klar.
Ein Blocker-naher Befund verhindert die Freigabe:

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Major | `Makefile:66-75` (`clicktest`-Target); vgl. `.github/workflows/ci.yml:216` | Zweites Auftreten von **E-098**: Der CI-Aufruf `make clicktest PHP="$(command -v php)"` übergibt `$(PHP)`, doch das `clicktest`-Target liest die Variable nirgends und setzt `BK_PHP` nicht, bevor `npx playwright test` läuft. `tests/e2e/fixtures.mjs:95` fällt dann auf `process.env.BK_PHP \|\| 'php'` zurück. In der CI funktioniert das nur zufällig, weil `setup-php` die gewünschte Version bereits als `php` vorn ins PATH legt; unter Linux mit mehreren PHP-Versionen oder bei künftiger Matrix-Erweiterung des `clicktest`-Jobs würde `make clicktest PHP=/pfad/zu/anderem/php` stillschweigend die falsche Version verwenden – ohne die Prüfung/den Abbruch, den `scripts/clicktest.ps1:66` (`$env:BK_PHP = $Php`) für Windows bereits korrekt implementiert. Keine 1:1-Parität zwischen den Script-Paaren, exakt das in E-098 beschriebene Muster (dort: `test-cov`). | Vor `npx playwright test` `export BK_PHP="$(PHP)"` (bzw. `BK_PHP=$(PHP) npx playwright test`) ergänzen, analog zu `$env:BK_PHP = $Php` in `clicktest.ps1`. |
| Minor | `scripts/clicktest.ps1:55-58` | Die Alterungsprüfung vergleicht `LastWriteTime` von `public/script.min.js` vs. `public/script.js`. Nach einem frischen `git clone`/Checkout können beide Dateien nahezu gleiche bzw. checkout-abhängige Zeitstempel erhalten (Git versioniert keine mtimes) – die Prüfung kann sowohl fälschlich durchrutschen als auch fälschlich abbrechen. Der bereits vorhandene, robustere Ansatz in `ci.yml` („script.min.js aktuell?“: `npm run minify` + `git diff --exit-code`) wird hier nicht wiederverwendet. | Falls praktikabel dieselbe inhaltsbasierte Prüfung (`npm run minify` + Diff) statt reinem mtime-Vergleich nutzen, oder als bekannte Einschränkung dokumentieren. |
| Hinweis | `tests/e2e/fixtures.mjs:227` | Der externe-Request-Filter prüft nur `^https?://`; Requests mit anderem Schema (`ws://`, `blob:`, …) werden implizit durchgelassen (`route.continue()`), ohne dass dies kommentiert ist. Aktuell unkritisch (App nutzt kein WebSocket/externe Nicht-HTTP-Requests in den Smoke-Specs). | Kommentar ergänzen, dass Nicht-HTTP(S)-Schemata bewusst durchgelassen werden, oder Schema-Liste erweitern, falls künftige Specs WebSockets nutzen. |

**Lernpunkt-Kandidaten:**
- Kategorie Tests/Werkzeuge: Der Major-Befund ist ein zweites, unabhängiges Auftreten des in `B0`
  dokumentierten Musters – `E-098` existiert bereits und sollte um dieses Auftreten
  (`Makefile`/`clicktest.ps1`, AP-20261119-testabdeckung-klicktests B6) ergänzt werden, da zwei
  Treffer innerhalb desselben AP auf eine systematische Lücke (fehlender Parität-Check bei
  ps1/Makefile-Paaren) statt auf Zufall hindeuten.

### B6 Runde 2

**Urteil: APPROVE.** Geprüft nur `git diff -- Makefile` (E-094, schmale Korrekturrunde):
`clicktest`-Rezeptzeile setzt jetzt `BK_PHP=$(PHP) npx playwright test` (vor dem Aufruf), damit greift
`fixtures.mjs` nicht mehr auf den `'php'`-Fallback zurück – Parität zu `$env:BK_PHP = $Php` in
`scripts/clicktest.ps1:64` hergestellt, Major aus B6-Runde-1 behoben. Kurzer `git diff --stat` gegen
alle B6-Pfade bestätigt unveränderten Umfang sonst (nur Makefile + bereits bekannte, nicht
B6-spezifische Diffs in ci.yml/docs/AGENTS.md/package.json/.gitignore/.dockerignore/instructions
aus vorherigen Runden; `playwright.config.mjs`/`fixtures.mjs`/`*.spec.mjs`/`clicktest.ps1`/
`clicktest-befunde.mjs` zeigen keinen Diff = unverändert seit Runde 1, da als neue Dateien bereits
getrackt). Keine neuen Blocker/Major. Die beiden dokumentierten Minor/Hinweis-Punkte aus
B6-Runde-1 (mtime-Check, Schema-Filter) bleiben unangetastet offen, sind für diese enge
Korrekturrunde nicht im Scope. Damit ist B6 insgesamt **APPROVE**.

**Lernpunkt-Kandidaten:** keine neuen; E-094 hat die Prüfzeit in Runde 2 wie vorgesehen auf den
gemeldeten Befund begrenzt.

## 6. Lernpunkte

<!-- Leitstand: neue Einträge in erkenntnisse.md (E-Nr) und ggf. übernommene Regeln -->

**B0:** E-098 (neu) – Windows-`.ps1` und Linux-`Makefile`-Äquivalente für dieselbe Aufgabe müssen
jeden Schritt 1:1 abgleichen; `Makefile test-cov` prüfte den Coverage-Treiber isoliert, reichte das
Ergebnis aber nicht an den eigentlichen PHPUnit-Aufruf weiter (2 Review-Runden). `@mkdir`-Verstoß
(Runde 1) ist bereits durch E-063 abgedeckt, keine neue Regel nötig.


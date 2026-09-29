# AP-20260929-sicherheit: Logo-Pfad (get_logo) und Einstellungs-Leck (check)

| Feld | Wert |
|---|---|
| Status | abgeschlossen |
| Typ | Sicherheit |
| Testläufe rot | 0 (Runde 0 bewusst rot) |
| Review-Runden | 1 |
| Commit | – |

## 1. Auftrag

Aus der Planung von AP-20260929-branding (Nebenbefunde), vom Nutzer als vorrangig freigegeben:

1. `firma_logo_url` ist über `save_settings` und über eingespielte Sicherungen frei setzbar; `get_logo` liest diesen
   Pfad **ohne Anmeldung** und liefert die Datei aus (beliebige Server-Dateien, z. B. die Datenbank). PDF/ZUGFeRD betten
   die Datei unter diesem Pfad ein.
2. `check` liefert Einstellungen auch an nicht angemeldete Aufrufer (Firmendaten, IBAN, SMTP-Host/-Benutzer).

Ziel: beide Lücken minimal und ohne Funktionsverlust schließen. Die neue Logo-Speicherung (Tabelle) bleibt Teil von
AP-20260929-branding. Ablauf wie Bugfix: zuerst Tests, die die Lücken zeigen (rot).

## 2. Plan

### 2.1 Bestand

| Stelle | Heute | Befund |
|---|---|---|
| `AdminActions::uploadLogo()` (`src/Handlers/AdminActions.php` Z. 63–129) | schreibt `DATA_DIR . 'firma_logo.png'` (GD) bzw. `firma_logo.{png,jpg,gif,webp}` (ohne GD) und setzt `firma_logo_url = 'data/firma_logo.<ext>'` | Einzige legitime Werte: `data/firma_logo.{png,jpg,gif,webp}`. Das „Logo-Verzeichnis“ ist `DATA_DIR` – dort liegen aber auch `database.sqlite`, `secret.key`, `systemadmin-passwort.txt` → „liegt in DATA_DIR“ allein genügt **nicht**, der Dateiname muss fest sein. |
| `AdminActions::getLogo()` (Z. 132–155), öffentlich | `realpath(__DIR__ . '/../../' . firma_logo_url)`, liefert jede existierende Datei, MIME notfalls `svg`/`octet-stream` | Lücke 1; ignoriert zudem `BK_DATA_DIR` (liest immer `APP_ROOT/data`). |
| `AdminActions::saveSettingsAction()` (Z. 37–60) | übernimmt `firma_logo_url` als beliebigen String | Einstiegspunkt der Manipulation (neben Sicherungs-Import über `TableCopier`/Legacy-Import). |
| `BelegPdfService::render…` (`src/Services/BelegPdfService.php` Z. 96–117) | eigener `realpath(APP_ROOT/ + ltrim(url,'/'))`, Base64 ins PDF | gleiche Lücke (Datei landet im PDF). |
| `ZugferdService` (`src/Services/ZugferdService.php` Z. 223–241) | wie oben, MIME-Map inkl. `svg` | gleiche Lücke. |
| vde0100 (`modules/vde0100/backend/Module.php` Z. 1384–1399) | `realpath(__DIR__ . '/../../../' . url)` = **Elternordner des Projekts**, `@mime_content_type` | gleiche Lücke (relativ zu anderem Ordner); legitimes Logo fehlt dort heute immer. |
| `AuthActions::check()` (`src/Handlers/AuthActions.php` Z. 14–75) | Z. 69 `settings` = `Auth::publicSettings(loadSettings())` **immer**, Z. 73 `license` (inkl. `customer`) immer | Lücke 2. |
| `Auth::publicSettings()` (`src/Auth.php` Z. 162–170) | leert nur `SECRET_SETTINGS` (`smtp_pass`, `gemini_api_key`) + `*_gesetzt` | Firmendaten, IBAN/BIC, `smtp_host`/`smtp_user`/`smtp_from_*` gehen an jeden. |

**Wer liest `check` ohne Anmeldung?**
- `login.html` (Z. 236–249): nur `loggedIn`, `offline`, `mustChangePassword`, `username`, `needSetup`.
- `index.html` (Z. 43–45): nur `loggedIn` → Umleitung.
- `mobile.html` `checkAuth()` (Z. 2337), `mobile_light.html` `checkAuth()` (Z. 437), `script.js` `init()` (Z. 275): lesen `settings` **erst nach** `if (j.loggedIn)` bzw. leiten vorher um.
- `wochenplan_display.html`: ruft `check` nicht auf (nur `load_wochenplanung_display`).
- `sw.js` (Z. 109–128): cached `check` nicht; Offline-Ersatzantwort enthält keine `settings`.
- Docker-Healthcheck (`docker-compose.yml` Z. 48, `docker-compose.minimal.yml` Z. 36): `curl -f` → braucht nur HTTP 200.
- `src/frontend/core/auth.ts` (inaktiv): `data.settings ?? {}` – verträgt leeres Objekt.

→ Ohne Anmeldung wird **keine einzige Einstellung** gebraucht.

### 2.2 Ziel & Abgrenzung

**Ziel:**
1. Server liest ein Logo nur noch aus `DATA_DIR/firma_logo.{png,jpg,gif,webp}` mit Bild-MIME – egal, was in `firma_logo_url` steht (Pflicht, weil Sicherungen die Einstellung mitbringen). `save_settings` lehnt andere Werte ab.
2. `check` ohne Anmeldung liefert keine Einstellungen und keinen Lizenznehmer mehr; Statuscode und übrige Felder bleiben.

**Nicht Teil:**
- Logo in der Datenbank, `BrandingService`, ETag/versionierte URL, Cache-Header, Upload-Härtung (Pixelgrenze, Neukodierung) → AP-20260929-branding.
- Frontend-Änderungen (`script.js`, `din1090.js`, `imgToBase64`), Cache-Busting.
- Welche Einstellungen **angemeldete** Nicht-Admins über `check` sehen (z. B. `smtp_host`, IBAN für Monteure) → offene Frage 2.
- Bestehende `@unlink` in `saveSettingsAction`/`uploadLogo` (nicht angefasste Zeilen).

### 2.3 Betroffene Dateien

| Datei | Grund |
|---|---|
| `src/Services/FirmenLogo.php` (neu, ~50 Zeilen) | Zentrale Hilfsfunktion: `isValidSetting(string): bool`, `resolve(array $settings, ?string $dataDir = null): ?array{path,mime}`, `dataUri(array $settings): string`. AP-branding ersetzt/übernimmt sie später. |
| `src/Handlers/AdminActions.php` | `getLogo()` über `FirmenLogo::resolve()`, sonst 404; `nosniff`-Header. `saveSettingsAction()`: ungültiger `firma_logo_url` → 400 vor dem Speichern. |
| `src/Auth.php` | `publicSettings()`: `firma_logo_url` → `''`, wenn `!FirmenLogo::isValidSetting()` (Browser bekommt nie einen fremden Pfad/URL aus einer Sicherung). |
| `src/Services/BelegPdfService.php`, `src/Services/ZugferdService.php`, `modules/vde0100/backend/Module.php` | eigener Pfad-Block → `FirmenLogo::dataUri($settings)`; HTML-`<img>` nur, wenn nicht leer (Stil je Datei unverändert). |
| `src/Handlers/AuthActions.php` | `check()`: `settings` und `license.customer`/`license.expires` nur bei Anmeldung. |
| `tests/Unit/FirmenLogoTest.php` (neu), `tests/Api/LogoTest.php` (neu), `tests/Api/AuthTest.php` (ergänzen) | siehe 2.8 |
| `CHANGELOG.md` | „Unveröffentlicht → Sicherheit“: zwei Einträge; Hinweis „SVG-Altlogos werden nicht mehr ausgeliefert, bitte als PNG neu hochladen“. |

Keine Migration, keine Fixture, keine Frontend-Datei, kein `VERSION`-Wechsel (Abschnitt „Unveröffentlicht“ existiert), kein Cache-Busting.

### 2.4 Struktur (Vorlage copier-astral)

Hilfsklasse unter `src/Services/` (≙ `src/<paket>/`), Handler bleiben dünn; Tests getrennt `tests/Unit` (reine Pfadprüfung mit Temp-Verzeichnis) und `tests/Api` (HTTP) ≙ `tests/` der Vorlage. Commits `test:`/`fix(security):` → git-cliff. Keine Abweichung.

### 2.5 Berücksichtigte Erkenntnisse

- **E-010** – `publicSettings()` bleibt der einzige Filter für die Browser-Ausgabe; `SECRET_SETTINGS` unverändert.
- **E-030** – Unit-/Api-Tests mit Temp-Dateien: vor dem Löschen `gc_collect_cycles()` (SQLite).
- **E-063** – kein `@` im neuen/angefassten Code (vde0100 `@mime_content_type` entfällt durch Helfer).
- **E-050** – Struktur s. 2.4.

### 2.6 Lösung (Kernentscheidungen)

1. **Whitelist statt „irgendwo in DATA_DIR“:** `isValidSetting()` akzeptiert nur `''` oder `^/?data/firma_logo\.(png|jpe?g|gif|webp)$`. `resolve()`:
   - Wert muss gültig und nicht leer sein → Dateiname = `'firma_logo.' . ext` (aus dem Regex-Treffer, **nie** aus dem Rohwert zusammengesetzt).
   - `$dir = realpath($dataDir ?? DATA_DIR)`, `$real = realpath($dir . '/' . $name)`; verlangt `$real !== false`, `is_file`, `dirname($real) === $dir` und `basename($real) === $name` (schließt Symlinks aus dem Verzeichnis heraus aus).
   - MIME per `finfo` ∈ {`image/png`,`image/jpeg`,`image/gif`,`image/webp`}; ohne `finfo` MIME aus der (bereits geprüften) Endung. **Kein SVG.**
   - sonst `null`. `dataUri()` = `''` oder `data:<mime>;base64,…`.
   - Liest `DATA_DIR` (behebt nebenbei: `BK_DATA_DIR` wird beachtet, vde0100-Pfad stimmt).
2. **`getLogo()`:** `resolve()` → `null` ⇒ 404 ohne Body; sonst `Content-Type` = geprüfte MIME, `X-Content-Type-Options: nosniff`, übrige Header wie heute.
3. **`saveSettingsAction()`:** Ist `firma_logo_url` im Body und `!isValidSetting(trim(...))` ⇒ `jsonOut(['error' => 'Ungültiger Logo-Pfad.'], 400)` **vor** `saveSettings()`. Frontend sendet den Schlüssel nur als `''` (`deleteFirmenlogo()` → `updateSetting`), sonst per Einzel-Schlüssel – kein Kollateralschaden.
4. **Sicherungs-Import:** keine Änderung am Import; Absicherung beim Lesen (1.–2., `publicSettings()`) greift für jeden Weg, über den die Einstellung hereinkommt.
5. **`check()`:** `$loggedIn = !empty($_SESSION['authenticated'])` einmal bestimmen;
   `'settings' => $loggedIn ? Auth::publicSettings(...) : new \stdClass()` (JSON `{}` statt `[]`, Typ bleibt Objekt);
   `'license' => $loggedIn ? LicenseService::getPublicInfo() : ['tier' => …, 'customer' => null, 'expires' => null]` (Form bleibt). `loadSettings()` wird ohne Anmeldung dann nicht mehr aufgerufen – Healthcheck liefert weiter 200.

**Felder von `check` ohne Anmeldung (danach):** `loggedIn` (false), `version`, `needSetup`, `mustChangePassword` (false), `username`/`role`/`kuerzel`/`dienstleisterId` (null), `permissions` ([]), `visibility` ('all'), `isSubunternehmer` (false), `stundenKategorie` (''), `modules` ([]), `settings` (**{} leer**), `license` (**nur `tier`**, `customer`/`expires` null).

### 2.7 Schritte (Commit-Reihenfolge)

1. **`test(security)`: rote Tests** – `tests/Api/LogoTest.php` (Fälle L1–L5) und Ergänzung `tests/Api/AuthTest.php` (C1–C3). *Prüfbar:* L1, L2 (BK_DATA_DIR-Fehler), L4, C1 rot; L3, C2, C3 grün.
2. **`fix(security)`: `FirmenLogo`** + `tests/Unit/FirmenLogoTest.php` (U1–U6). *Prüfbar:* `--testsuite unit` grün.
3. **`fix(security)`: `get_logo`/`save_settings`/`publicSettings` über `FirmenLogo`.** *Prüfbar:* L1–L5 grün.
4. **`fix(security)`: PDF-Logo über `FirmenLogo::dataUri()`** in `BelegPdfService`, `ZugferdService`, vde0100. *Prüfbar:* `RechnungenTest` und übrige Api-Tests grün; Code-Review: kein `realpath(... firma_logo_url)` mehr (`grep firma_logo_url src modules` zeigt nur Standardwert, `FirmenLogo`, `AdminActions`).
5. **`fix(security)`: `check` ohne Anmeldung ohne Einstellungen.** *Prüfbar:* C1–C3 grün, bestehende `AuthTest` grün.
6. **`docs`: `CHANGELOG.md`.** Abschluss: PHPUnit auf SQLite **und** PostgreSQL, PHPStan ohne neue Baseline-Einträge, `php-cs-fixer check`.

### 2.8 Testfälle

Kleines PNG im Test aus Base64-Konstante in eine Temp-Datei `logo.png` schreiben (keine Binär-Fixture). Manipulation wie aus einer Sicherung: Einstellung direkt per `$this->server->db()` setzen (`SELECT data …` → JSON ändern → `UPDATE settings SET data = ? WHERE id = 1`, portabel). **Keine** Ziele unter `APP_ROOT/data` verwenden (dort kann eine echte Entwickler-DB liegen – Fehlermeldungen würden Inhalte ausgeben).

| Nr | Datei | Fall | Erwartung |
|---|---|---|---|
| L1 | Api/LogoTest | `firma_logo_url` per DB auf `VERSION`, `public/api.php`, `data/../VERSION`, `public/planning-dashboard-logo.png` (echtes Bild außerhalb), `/etc/passwd`-artig absolut; `get_logo` ohne Anmeldung | je 404; Body enthält weder VERSION-Inhalt noch `<?php` |
| L2 | Api/LogoTest | Admin `upload_logo` (PNG) → `get_logo` mit **frischem, nicht angemeldetem** Client | 200, `Content-Type: image/png`, Body beginnt mit `\x89PNG`, `X-Content-Type-Options: nosniff` |
| L3 | Api/LogoTest | Nach Upload `firma_logo_url = ''` speichern | `get_logo` 404 |
| L4 | Api/LogoTest | `save_settings` mit `firma_logo_url` = `../VERSION`, `data/database.sqlite`, `https://example.org/x.png` | 400; `load_settings` zeigt alten Wert. `data/firma_logo.png` und `''` → 200 |
| L5 | Api/LogoTest | Textdatei als `BK_DATA_DIR/firma_logo.png` + Einstellung `data/firma_logo.png` | `get_logo` 404 (MIME-Prüfung) |
| C1 | Api/AuthTest | Admin speichert `firma_name`, `firma_iban`, `smtp_host`, `smtp_user`; frischer Client `check` | 200, `loggedIn` false, `settings` leer, Body enthält keinen der Werte, `license.customer` null |
| C2 | Api/AuthTest | vor Setup `check` (Healthcheck-Fall) | 200, `needSetup` true, `version` gesetzt (bestehender Test bleibt) |
| C3 | Api/AuthTest | angemeldeter Admin **und** Monteur `check` | `settings` enthält die gespeicherten Firmendaten/`smtp_host` wie bisher, Schlüsselmenge = `load_settings`; `smtp_pass` weiter leer |
| U1–U6 | Unit/FirmenLogoTest | Temp-Verzeichnis als `$dataDir`: gültiges PNG → Pfad+MIME; `data/database.sqlite` (Datei existiert) → null; `../x`, `data/firma_logo.svg`, `data/firma_logo.png.php` → null; Textdatei als `firma_logo.png` → null; fehlende Datei → null; `isValidSetting('')` true | wie angegeben |

### 2.9 Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| SQLite/PostgreSQL | Kein neues SQL im Produktcode; Test-Update auf `settings` portabel. Api-Tests auf beiden Treibern. |
| Migration + Fixture | Entfällt (kein Schema). |
| Import alter Sicherungen | Werte `data/firma_logo.{png,jpg,gif,webp}` bleiben gültig. Andere Altwerte (z. B. SVG-Logos aus sehr alten Ständen, Fremdpfade) → Logo fehlt auf Belegen, Rückfall Firmenname wie ohne Logo; CHANGELOG-Hinweis „neu hochladen“. Logo-Datei ist ohnehin nicht Teil der Sicherung. |
| Rechte/Sicherheit | `get_logo` bleibt öffentlich (Login-/Belegnutzung), liefert aber nur noch die eine feste Datei mit Bild-MIME + `nosniff`. Symlink im Datenverzeichnis per `dirname(realpath)`-Vergleich abgefangen. |
| Funktionsverlust `check` | Alle Seiten lesen `settings` nur nach Anmeldung (2.1); Healthcheck braucht nur 200. `{}` statt Objekt mit Werten – `j.settings \|\| {}` überall. |
| Verhalten ändert sich positiv | vde0100-Protokoll zeigt künftig das Logo; `get_logo` beachtet `BK_DATA_DIR`. Im CHANGELOG erwähnen. |
| Cache/Version | Keine Frontend-Datei geändert → kein Cache-Busting. Browser-Cache des Logos (1 Tag) unverändert. |
| Modul-Lizenz/Feature-Flag | vde0100 nur Code-Tausch im bestehenden Block, keine Lizenzprüfung berührt. |

### 2.10 Abnahmekriterien

1. `get_logo` liefert ausschließlich `DATA_DIR/firma_logo.{png,jpg,gif,webp}` mit Bild-MIME; für jeden anderen `firma_logo_url`-Wert 404 ohne Dateiinhalt (L1, L5, U1–U6).
2. Ein per `upload_logo` hochgeladenes Logo wird ohne Anmeldung als `image/png` ausgeliefert, auch mit `BK_DATA_DIR` (L2); Löschen wirkt (L3).
3. `save_settings` weist ungültige `firma_logo_url` mit 400 ab, ohne etwas zu speichern (L4).
4. `load_settings`/`check` geben `firma_logo_url` nur als `''` oder `data/firma_logo.<ext>` aus.
5. Beleg-PDF, ZUGFeRD und VDE-Protokoll betten nur ein über `FirmenLogo` geprüftes Logo ein; kein eigener Dateizugriff auf `firma_logo_url` mehr im Code.
6. `check` ohne Anmeldung: HTTP 200, Felder laut 2.6, `settings` leer, keine Firmendaten/IBAN/SMTP-Werte im Body (C1, C2).
7. `check` angemeldet: Antwort unverändert (C3).
8. Alle bestehenden Tests grün auf SQLite und PostgreSQL; PHPStan ohne neue Baseline-Einträge; kein `@` im geänderten Code.

### 2.11 Offene Fragen

1. **Lizenznehmer:** `license.customer`/`expires` ohne Anmeldung ebenfalls ausblenden (Vorschlag: ja, `tier` bleibt)?
2. **Angemeldete Nicht-Admins** sehen über `check` weiter IBAN, `smtp_host`/`smtp_user` usw. Soll das ein Folge-AP werden (Filter je Rolle), oder ist es gewollt?
3. **SVG-Altlogos:** Einverstanden, dass sie nicht mehr ausgeliefert werden (Neu-Upload als PNG nötig)?

**Freigabe G1:** ☑ durch Nutzer am 2026-09-29 (Sicherheits-AP vorrangig, Umfang minimal).

## 3. Umsetzung

2026-09-29, Entwickler. Entscheidungen offene Fragen: 1 = ja (`customer`/`expires` ohne Anmeldung `null`), 2 = Folge-AP, 3 = SVG entfällt.

| Datei | Änderung |
|---|---|
| `src/Services/FirmenLogo.php` (neu) | `isValidSetting()` (Regex `\A/?data/firma_logo\.(png\|jpe?g\|gif\|webp)\z`), `resolve()` (fester Dateiname aus Regex-Treffer, `realpath` + `dirname`/`basename`-Vergleich, MIME per `finfo` ∈ Bild-MIMEs, ohne `finfo` aus Endung), `dataUri()` |
| `src/Handlers/AdminActions.php` | `getLogo()` nur über `FirmenLogo::resolve()`, sonst 404; `X-Content-Type-Options: nosniff`. `saveSettingsAction()`: ungültiger `firma_logo_url` (auch Nicht-String) → 400 vor dem Laden/Speichern |
| `src/Auth.php` | `publicSettings()`: ungültiger `firma_logo_url` → `''` |
| `src/Services/BelegPdfService.php`, `src/Services/ZugferdService.php`, `modules/vde0100/backend/Module.php` | eigener Pfad-/MIME-Block → `FirmenLogo::dataUri()`; `@mime_content_type` (vde0100) entfällt |
| `src/Handlers/AuthActions.php` | `check()`: `$loggedIn` einmal bestimmt; ohne Anmeldung `settings` = `{}` (`stdClass`), `license.customer`/`expires` = `null`; `loadSettings()` wird dann nicht aufgerufen |
| `CHANGELOG.md` | „Unveröffentlicht → Sicherheit“: zwei Einträge inkl. SVG-Hinweis |

Prüfungen (SQLite, Windows):
- `tests/Unit/FirmenLogoTest.php` 23/23 (1 übersprungen: U7 Symlink unter Windows), `tests/Api/LogoTest.php` 15/15, `tests/Api/AuthTest.php` 18/18 – grün.
- `--testsuite unit` 37/37 grün, `tests/Api/RechnungenTest.php` 4/4 grün.
- PHPStan: `[OK] No errors`; keine Baseline-Einträge betrafen die geänderten Stellen → Baseline unverändert.
- `grep firma_logo_url src modules`: nur Standardwert, `publicSettings`, `FirmenLogo`, `AdminActions` (Validierung, Löschen, Upload).

Abweichungen/Hinweise:
- Keine Abweichung vom Plan; Tests unverändert, kein Widerspruch zum Plan gefunden.
- `jpeg` ist laut Tests gültig (`firma_logo.jpeg`), `uploadLogo()` schreibt aber nur `.jpg` – unkritisch.
- Ausstehend: Lauf gegen PostgreSQL und Gesamtlauf `phpunit` (Tester), `php-cs-fixer` (nur `tests/`, nicht geändert).

## 4. Tests

### Runde 0 (Reproduktion) – 2026-09-29, SQLite, Produktivcode unverändert

Neue/geänderte Tests: `tests/Api/LogoTest.php` (neu, 15 Fälle), `tests/Api/AuthTest.php` (+3), `tests/Unit/FirmenLogoTest.php` (neu, 23 Fälle). `php-cs-fixer check`: sauber.

| Nr | Test | Ergebnis | Grund |
|---|---|---|---|
| L1 | `LogoTest::testGetLogoLiefertKeineFremdenDateien` – `VERSION`, `public/api.php`, `data/../VERSION`, `public/planning-dashboard-logo.png` | **rot** (4) | HTTP 200 mit Dateiinhalt (u. a. `api.php` als `text/x-php`, 29 KB) |
| L1 | dto. – absoluter Pfad | grün | Pfad wird heute an `APP_ROOT` angehängt → existiert nicht |
| L2 | `testHochgeladenesLogoWirdOhneAnmeldungAusgeliefert` | **rot** | 404 – `get_logo` liest `APP_ROOT/data` statt `BK_DATA_DIR` |
| L3 | `testGeloeschtesLogoLiefert404` | grün | – |
| L4 | `testSaveSettingsLehntUngueltigenLogoPfadAb` – `../VERSION`, `data/database.sqlite`, externe URL | **rot** (3) | `save_settings` → 200 statt 400 |
| L4 | `testSaveSettingsAkzeptiertGueltigenLogoPfadUndLeerwert` | grün | – |
| L5 | `testTextdateiUnterLogonamenWirdNichtAusgeliefert` | grün | nur wegen des `BK_DATA_DIR`-Fehlers (Datei wird gar nicht gesucht); aussagekräftig erst nach L2-Fix |
| L6 | `testFremderLogoPfadWirdAnDenBrowserLeerAusgegeben` (Abnahmekriterium 4, zusätzlich) – 3 Werte | **rot** (3) | `load_settings`/`check` geben den Fremdwert aus |
| C1 | `AuthTest::testCheckOhneAnmeldungLiefertKeineEinstellungenUndKeinenLizenznehmer` | **rot** | `settings` nicht leer (Firmendaten, IBAN, SMTP); Lizenznehmer sichtbar |
| C2 | `testCheckVorEinrichtungLiefert200` | grün | – |
| C3 | `testCheckAngemeldetLiefertEinstellungenWieBisher` (Admin + Monteur, inkl. Lizenz) | grün | – |
| U1–U7 | `FirmenLogoTest` (22 Fälle + Symlink) | **rot** (22 Error) | `App\Services\FirmenLogo` existiert noch nicht; U7 (Symlink) unter Windows übersprungen |

Bestehende `AuthTest`-Fälle: 15/15 grün.

Hinweis Testumgebung: Leere 404-Antworten von `get_logo` erreichen den Client, bevor der Server die SQLite-Datei schließt;
`TestServer::resetData()` scheitert dann still (`mkdir(): File exists`), der Folgetest sieht alte Daten (`setup` → 403).
`LogoTest::tearDown()` schickt deshalb einen Folgerequest. `TestServer` selbst unverändert – ggf. eigenes Thema.

Ausstehend: Lauf gegen PostgreSQL (nach Umsetzung, zusammen mit Gesamtlauf).

### Runde 1 – 2026-09-29 (Gesamtlauf, Leitstand) – **GRÜN**

- SQLite: 148 Tests, 1155 Assertions, 2 übersprungen – OK (1:36 min).
- PostgreSQL 17 (Wegwerf-Container): 148 Tests, 1154 Assertions, 3 übersprungen – OK (5:17 min).
- Zusätzliche Testdatei des Entwicklers: `tests/Unit/BelegPdfLogoTest.php` (Beleg-PDF bettet kein fremdes Logo ein).

## 5. Review

### Runde 1 – 2026-09-29, Reviewer

Geprüft: `git diff` (CHANGELOG, `Auth.php`, `AdminActions.php`, `AuthActions.php`, `BelegPdfService.php`,
`ZugferdService.php`, vde0100 `Module.php`, `AuthTest.php`) und neue Dateien `FirmenLogo.php`, `LogoTest.php`,
`FirmenLogoTest.php`, `BelegPdfLogoTest.php`. Fremde gestagte Änderungen (`BackupArchive.php`, `BackupImportTest.php`)
nicht bewertet. PHPStan: `[OK] No errors`, Baseline unverändert. PHPUnit nicht gestartet (läuft parallel).

**Urteil: APPROVE** (keine Blocker/Major).

Geprüft ohne Befund:
- **Pfadprüfung:** Regex `\A…\z` ohne `i`-Flag schließt Nullbyte, `php://`/`file://`, UNC, Backslash, NTFS-ADS
  (`:stream`), 8.3-Kurznamen, `..`, Groß-/Kleinschreibung und nachgestellte Zeichen aus. Dateiname entsteht nur aus dem
  Regex-Treffer. `realpath` für Verzeichnis und Datei → konsistent auch unter Windows (liefert echte Schreibweise;
  abweichende Schreibweise auf der Platte ⇒ `basename`-Vergleich schlägt fehl ⇒ `null`, also sicher). Symlink/Junction
  nach außen oder auf `database.sqlite` ⇒ `dirname`/`basename`-Vergleich greift; Hardlink auf DB ⇒ `finfo` ≠ Bild.
- **MIME/Header:** `finfo` gegen feste Bild-Liste, kein SVG; `Content-Type` = geprüfte MIME, `nosniff` gesetzt.
- **Kein Fremdpfad mehr:** `grep firma_logo` in `src`, `modules`, `public/**/*.php`, `cron_*.php`, `scripts`: nur
  Standardwert, `publicSettings`, `FirmenLogo`, `AdminActions` (Validierung/Löschen/Upload mit festem Namen). `deploy/`
  ist per `.gitignore` Build-Ausgabe.
- **`check` ohne Anmeldung:** `settings` = `{}`, `license` nur `tier`; `loadSettings()` nicht aufgerufen.
  `username` kann ohne `authenticated` nicht gesetzt sein (nur `AuthActions.php` Z. 104/154).
- **Frontend:** `login.html` (Z. 236–249), `index.html` (Z. 43), `mobile.html` (Z. 2339), `mobile_light.html` (Z. 439),
  `script.js` (Z. 277) lesen `settings` erst nach `loggedIn`; `license.customer` wird im Frontend nirgends gelesen
  (`dist/core.js` hat Fallback); `sw.js` unberührt; `wochenplan_display.html` ruft `check` nicht auf.
- **Speichern:** Validierung nach `requireRole('admin')`, vor `loadSettings`; Fehlermeldung ohne Interna; Nicht-Strings
  ⇒ 400. Altwerte aus Historie immer `data/firma_logo.<ext>` ⇒ Import alter Sicherungen bleibt gültig.
- **SQL/DB:** kein neues SQL im Produktcode; Test-SQL (`SELECT`/`UPDATE settings … WHERE id = 1`) portabel.
- **E-063:** kein neues `@`; entfallenes `@mime_content_type`. **E-030:** `gc_collect_cycles()` in beiden neuen Tests.
- **CHANGELOG:** unter „Unveröffentlicht → Sicherheit“, inkl. SVG-Hinweis.

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Minor | AP Abschnitt 4 / `tests/Unit/BelegPdfLogoTest.php`:1 | Neuer Test (verweist auf dieses AP) ist weder in 2.3/2.8 geplant noch in Abschnitt 3/4 dokumentiert. | In Abschnitt 4 aufführen (Fall, Ergebnis). |
| Minor | `tests/Unit/BelegPdfLogoTest.php`:27 | Abnahmekriterium 5 nur negativ und nur für `BelegPdfService` getestet; kein Test, dass ein **gültiges** Logo (unter `BK_DATA_DIR`) im Beleg-/ZUGFeRD-HTML landet; `ZugferdService`/vde0100 ungetestet. Ein Tippfehler in `dataUri`-Einbindung bliebe unentdeckt. | Api-Test: `upload_logo` → Beleg-HTML/PDF-Erzeugung enthält `data:image/png;base64`. Mind. für Beleg; ZUGFeRD/VDE optional. |
| Minor | AP Abschnitt 4 | Abnahmekriterium 8 (Gesamtlauf SQLite **und** PostgreSQL nach Umsetzung) noch nicht belegt – Abschnitt 4 enthält nur Runde 0. | Tester-Runde 1 mit beiden Treibern nachtragen, vor Commit. |
| Hinweis | `src/Services/FirmenLogo.php`:42 | `finfo->file()` bzw. `file_get_contents()` (Z. 57) erzeugen bei unlesbarer Datei eine Warnung, die der globale Handler in eine Exception wandelt (Grund von E-063) ⇒ 500 statt 404 bzw. PDF-Abbruch; der Zweig `$data === false` ist damit praktisch tot. | Optional `is_readable($real)` in die Bedingung Z. 38 aufnehmen. |
| Hinweis | `src/Handlers/AdminActions.php`:142 | TOCTOU zwischen `resolve()` und `readfile()` (Z. 148): nur mit Schreibzugriff auf `DATA_DIR` ausnutzbar (dann ohnehin kompromittiert); gleichzeitiger Neu-Upload kann eine abgeschnittene Antwort liefern. | Keine Maßnahme; ggf. in AP-branding (Logo aus DB) erledigt. |
| Hinweis | `CHANGELOG.md`:83 | „das VDE-Protokoll zeigt das Logo **wieder**“ – laut 2.1 fehlte es dort immer (falscher Basisordner). | „zeigt das Logo jetzt“. |
| Hinweis | `src/Services/FirmenLogo.php`:10 | `/data/firma_logo.png` gilt als gültig, `script.js` Z. 10864 bildet nur `data/…` auf `get_logo` ab ⇒ Vorschau kaputt. Wird vom Upload nie erzeugt. | In AP-branding mitnehmen oder `/?` streichen. |
| Hinweis | `tests/Api/LogoTest.php`:21 | `tearDown` umgeht ein Rennen in `TestServer::resetData()` (leere 404-Antwort vor Schließen der SQLite-Datei; `mkdir(): File exists` wird still geschluckt). Betrifft jeden künftigen Test mit Leer-Antworten. | Eigenes AP: `resetData()` laut scheitern lassen bzw. dort warten. |

**Lernpunkt-Kandidaten:**
- **Tests:** `TestServer::resetData()` scheitert still, wenn der Server die SQLite-Datei nach einer Antwort ohne Body
  noch hält ⇒ Folgetest sieht Altdaten. Regel: „Test-Infrastruktur darf Aufräumfehler nicht verschlucken“ bzw.
  „nach leerer Antwort Folgerequest vor Reset“. Verwandt mit **E-030**, aber anderer Mechanismus – neue E-Nr.
- **Sicherheit:** Einstellungen, die als Dateipfad dienen, nie als Pfad verwenden, sondern auf feste Namen im
  Datenverzeichnis abbilden (Whitelist + `realpath`-Verzeichnisvergleich); gilt auch für Werte aus Sicherungen.
  Noch keine E-Nr.
- **Sicherheit:** Öffentliche Endpunkte (`check`, `get_logo`) bei jedem AP auf ungefilterte Ausgabe prüfen
  (Checkliste „ohne Anmeldung erreichbar“). Noch keine E-Nr.
- **Planung:** Vom Tester zusätzlich angelegte Testdateien im AP dokumentieren (Befund 1). Noch keine E-Nr.

## 6. Lernpunkte

E-012, E-013, E-031, E-032, E-064, E-070 in `erkenntnisse.md`. Folge-APs: IBAN/SMTP für angemeldete Nicht-Admins in `check`;
`TestServer::resetData()` Fehler nicht verschlucken. Minor-Befunde Review: CHANGELOG-Wortlaut korrigiert, Testdatei dokumentiert.

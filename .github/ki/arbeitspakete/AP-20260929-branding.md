# AP-20260929-branding: Grundfarben und Logo je Instanz

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

„Gibt es eine Möglichkeit, in Verwaltung → Allgemein die Grundfarben des Frontends sowie das Logo
für die jeweilige Instanz zu ändern (nur Admin darf das)?“

Erst Machbarkeit/Bestand prüfen, dann Lösung planen. Nur die Rolle Admin darf ändern.

## 2. Plan

### 2.1 Bestand (Machbarkeit)

**Machbar: ja.** Das Firmenlogo gibt es schon, Farbeinstellungen gibt es noch nicht.

| Thema | Heutiger Stand | Befund |
|---|---|---|
| Einstellungen | `settings` (eine Zeile, `id=1`, JSON in `data`); `Auth::loadSettings()` (Standardwerte + gespeicherte Werte), `Auth::saveSettings()`, `AdminActions::saveSettingsAction()` (`requireRole('admin')`, nur bekannte Schlüssel) | Neue Schlüssel = neuer Standardwert in `loadSettings()`, **keine Migration** nötig. Die `settings`-Tabelle kommt über die generische Tabellenkopie in Sicherungen mit. |
| Logo-Upload | `upload_logo` (nur Admin): PNG/JPEG/GIF/WebP, max. 2 MB, SVG absichtlich abgelehnt, mit GD auf PNG ≤ 600 px umgewandelt → `DATA_DIR/firma_logo.png`, Einstellung `firma_logo_url = 'data/firma_logo.png'` | Datei liegt in `data/` und ist **nicht in Sicherungen** (`BackupWriter` packt nur JSON + SQLite + Manifest). Bei PostgreSQL mit mehreren App-Containern nicht geteilt. |
| Logo-Auslieferung | `get_logo` **ohne Anmeldung** liest `realpath(APP_ROOT . '/' . firma_logo_url)` | **Sicherheitslücke:** `firma_logo_url` ist per `save_settings` frei setzbar (und kommt über jede eingespielte Sicherung mit) → Lesen beliebiger Dateien ohne Anmeldung (z. B. `data/database.sqlite`, Schlüsseldatei). Ignoriert zudem `BK_DATA_DIR`. `Cache-Control: max-age=86400` ohne Version → nach einem Wechsel bis zu 1 Tag altes Logo. |
| Logo-Nutzung Server | `BelegPdfService`, `ZugferdService`, `modules/vde0100/backend/Module.php` lesen den Pfad jeweils selbst | Gleiche Pfadlücke (Datei wird als Base64 ins PDF eingebettet). vde0100 löst `__DIR__/../../../` auf das **Elternverzeichnis** des Projekts auf → Logo fehlt dort heute immer. `@`-Operatoren. |
| Logo-Nutzung Browser | `script.js`: Rechnung/Angebot/Tagesbericht/Bautagebuch über `imgToBase64(s.firma_logo_url)` / `get_logo`; `din1090.js` ebenso | `deleteFirmenlogo()` weist `_imgB64Cache` (const) neu zu → TypeError, Ansicht aktualisiert sich nicht. Cache nach URL → nach neuem Upload altes Logo. Hinweistext nennt „SVG“, der Server lehnt SVG aber ab. |
| Logo in der Oberfläche | Kopfzeile `index.html`: festes `planning-dashboard-logo(-light).png`; `login.html`: festes `planning-dashboard-logo.png` + „Baukalkulation“; `mobile.html`: Wasserzeichen fest, **Tagesbericht-PDF mit festem `logo_es.png`** statt Firmenlogo; Favicons/`manifest.json`-Icons statisch | Firmenlogo erscheint heute nur auf Belegen. |
| Farben | `style.css` `:root` (`--primary`/`--blue` `#00B4D8`, `--blue-hover`, `--blue-light`, `--teal-dark`, `--teal-gradient`, `--yellow`…), eigene Blöcke für `body.dark-theme` und `@media print`; `login.html`, `mobile.html`, `mobile_light.html` haben eigene `:root`-Blöcke; `meta theme-color` + `manifest.json` `theme_color` fest | Große Teile der Oberfläche hängen an CSS-Variablen → per nachgeladenem Stylesheet überschreibbar. **~97 feste `#00B4D8`/`#0096B7` in `script.js`** (Druck/PDF-Vorlagen, ~8 Modal-Kopfzeilen mit Verlauf) folgen nicht. |
| Kontrast | Weißer Text auf `#00B4D8` = **ca. 2,5 : 1** | Schon der Standard erfüllt WCAG 3 : 1 nicht → eine harte Kontrastsperre würde den Standard selbst sperren. |
| Nebenbefund | `check` liefert `publicSettings()` auch **ohne Anmeldung** (Firmendaten, IBAN, SMTP-Host/-Benutzer …) | Nicht Teil dieses AP (eigenes AP vorschlagen). Hier nicht ausweiten: Login-Seite bekommt Branding **nicht** über `check`. |

### 2.2 Ziel & Abgrenzung

**Ziel:** Ein Admin stellt unter Verwaltung → Allgemein pro Instanz ein:
- **Primärfarbe** und **Akzentfarbe** (Hex, leer = Standard). Daraus werden die CSS-Variablen der Desktop-, Mobil-, Mobil-Light- und Login-Seite abgeleitet (hell, dunkel, Druck).
- **Firmenlogo** (bestehender Upload, gehärtet) wird in der Datenbank gespeichert (kommt so mit in Sicherungen, gilt bei PostgreSQL für alle Container) und kann per Schalter zusätzlich in **Kopfzeile und Anmeldeseite** angezeigt werden.
- „Standard wiederherstellen“ pro Farbe und für alles.
- Nur die Rolle `admin` darf ändern (serverseitig), `master` sieht die Einstellungen weiter nur lesend.

**Nicht Teil:**
- Feste Farben in Druck-/PDF-Vorlagen (Rechnung, Angebot, Exporte, Charts) – Belege behalten ihr Layout. (Modal-Kopfzeilen siehe offene Frage 4.)
- Favicons, PWA-Icons, `manifest.json` (`theme_color`, Name) – statische Dateien; nur `meta theme-color` wird zur Laufzeit gesetzt.
- Hintergrund-, Text-, Grau-, Fehler-/Erfolgsfarben, Schriften, eigenes CSS.
- SVG-Logos (siehe Risiken).
- Wasserzeichen in `mobile.html`, `bedienungsanleitung.html`, `wochenplan_display`, Vue-Frontend `src/frontend/` (inaktiv).
- Behebung des `check`-Lecks (eigenes AP).

### 2.3 Betroffene Dateien

| Datei | Grund |
|---|---|
| `migrations/2026092900xxxx_create_firmenlogo_table.php` (neu, `phinx create CreateFirmenlogoTable`) | Tabelle `firmenlogo` (`id INTEGER PRIMARY KEY` = 1, `mime TEXT NOT NULL`, `data TEXT NOT NULL` Base64, `sha256 TEXT NOT NULL`, `updatedAt INTEGER NOT NULL`); SQLite-DDL, `PgsqlDialect` übersetzt |
| `tests/fixtures/backups/v<neu>.zip` (neu) | E-020: Fixture nach Migration |
| `src/Services/BrandingService.php` (neu) | Einzige Stelle für: Farbe prüfen/normalisieren, abgeleitete Farben, Kontrast, CSS erzeugen; Logo lesen/speichern/löschen, Übernahme der Altdatei `data/firma_logo.*`, Data-URI für PDFs |
| `src/Auth.php` | Standardwerte `theme_primary` `''`, `theme_accent` `''`, `branding_logo_app` `false`; `publicSettings()` normalisiert `firma_logo_url` auf `api.php?action=get_logo[&v=<8 hex>]` oder `''` |
| `src/Handlers/AdminActions.php` | `saveSettingsAction`: Farben validieren (400 bei Fehler), `firma_logo_url` nur noch leeren (= Logo löschen); `uploadLogo`: Upload-Fehler/Pixelgrenze prüfen, immer PNG neu kodieren, in DB speichern, Audit; `getLogo`: aus Service, ETag/304, `no-cache`, `nosniff`; neu `brandingCss()` |
| `public/api.php` | Route `branding_css` (öffentlich, GET) |
| `src/Services/BelegPdfService.php`, `src/Services/ZugferdService.php`, `modules/vde0100/backend/Module.php` | Logo über `BrandingService::logoDataUri()` statt eigenem Pfad (schließt Lücke, behebt vde0100-Pfad) |
| `src/Backup/BackupWriter.php` | Vor dem Snapshot Altdatei-Übernahme anstoßen (eine Zeile), damit Alt-Logos in die erste neue Sicherung kommen |
| `public/style.css` | Klasse für eigenes Logo in der Kopfzeile (Dunkelmodus: heller Hintergrund-Chip) |
| `public/index.html`, `public/login.html`, `public/mobile.html`, `public/mobile_light.html` | `<link rel="stylesheet" href="api.php?action=branding_css">` **nach** dem letzten Stylesheet/Inline-`<style>`; `<img class="brand-logo-custom" src="api.php?action=get_logo" loading="lazy" hidden>` neben dem Standardlogo (index, login) |
| `public/script.js` | Karte „Erscheinungsbild“ in `renderAllgemeinSettings()` (nur `currentRole === 'admin'`), Farbwähler + Standard-Knöpfe + Kontrasthinweis + Logo-Schalter, Stylesheet nach Speichern neu laden, `meta theme-color` setzen; `deleteFirmenlogo()`-Fehler beheben; Hinweistext ohne SVG, `accept="image/png,image/jpeg,image/gif,image/webp"` |
| `public/mobile.html` | Tagesbericht-PDF: Firmenlogo (`appSettings.firma_logo_url`) statt festem `logo_es.png`, Rückfall `logo_es.png` |
| `public/script.min.js`, `public/sw.js`, `public/index.html` | Cache-Busting (`npm run minify`, `?v=`, `CACHE_VERSION`) |
| `VERSION`, `package.json`, `public/manifest.json`, `CHANGELOG.md` | Minor-Version (`npm run version:sync`), Änderungseintrag inkl. Sicherheitsfix |
| `tests/Unit/BrandingServiceTest.php`, `tests/Api/BrandingTest.php` (neu) | siehe Abnahmekriterien |

Nicht anfassen: `deploy/` (generiert), `legacy_baseline`/`LegacySchema.php`, `src/frontend/`.

### 2.4 Struktur (Vorlage copier-astral)

- Fachlogik als Service unter `src/Services/` (≙ `src/<paket>/`), Handler bleibt dünn; Tests getrennt in `tests/Unit` (reine Farbberechnung) und `tests/Api` (HTTP-Verhalten) ≙ `tests/` der Vorlage.
- Schema per neuer Phinx-Migration; keine neuen Root-Skripte, kein CLI-Befehl nötig.
- Doku: `CHANGELOG.md` (Conventional Commits `feat:`/`sec:` → git-cliff). Keine Abweichung von der Vorlage.

### 2.5 Berücksichtigte Erkenntnisse

- **E-001** – Upsert `ON CONFLICT(id) DO UPDATE SET mime = excluded.mime, …` (nur `excluded.*`, bei Bezug auf die Zieltabelle qualifizieren).
- **E-010** – Branding ist kein Geheimnis → nicht in `SECRET_SETTINGS`; aber `publicSettings()` bleibt Filter für die Browser-Ausgabe.
- **E-020** – Nach der Migration Fixture neu erzeugen und einchecken.
- **E-030** – Tests mit SQLite-Dateien unter Windows: `gc_collect_cycles()` vor Löschen.
- **E-063** – Kein `@` in angefasstem Code (`@unlink`, `@imagecreatefromstring`, `@mime_content_type` ersetzen).
- **E-040** – Vite unberührt; keine neuen Dateien unter `src/frontend/`.
- **E-050** – Struktur nach Vorlage (s. 2.4).

### 2.6 Lösung (Kernentscheidungen)

1. **Logo in der Datenbank** (Tabelle `firmenlogo`, eine Zeile, Base64 in `TEXT`) statt in `data/`: kommt ohne Änderung am Sicherungsformat über `TableCopier` in Sicherung und Import, funktioniert gleich auf SQLite/PostgreSQL und bei mehreren Containern. Alternative „Datei zusätzlich ins ZIP“ verworfen: ändert Format 2 (Manifest-Prüfsummen), Import schreibt außerhalb der Transaktion.
2. **Altbestand:** Liegt keine Zeile vor, aber `DATA_DIR/firma_logo.{png,jpg,gif,webp}` (feste Namensliste), übernimmt `BrandingService` die Datei einmalig in die Tabelle (idempotent, Datei bleibt liegen). Beim Löschen werden Zeile **und** Altdateien entfernt.
3. **`firma_logo_url` ist nur noch Kennzeichen**, vom Server gesetzt (`api.php?action=get_logo&v=<sha8>`). `save_settings` akzeptiert dafür nur `''`. `publicSettings()` gibt nur die zwei erlaubten Formen aus (Altwert `data/firma_logo.*` → `api.php?action=get_logo`), alles andere → `''`. Der Server liest **nie** mehr einen Pfad aus den Einstellungen. Die versionierte URL löst auch das Cache-Problem in `imgToBase64()` – ohne weitere Änderungen an `script.js`/`din1090.js`.
4. **Upload:** `UPLOAD_ERR_OK` + `is_uploaded_file`, Größe ≤ 2 MB, MIME per `finfo` (Whitelist PNG/JPEG/GIF/WebP), `getimagesize()` ≤ 4000 × 4000 px vor dem Dekodieren (Dekompressionsbombe), **immer** mit GD neu als PNG kodieren (entfernt Metadaten/Polyglot-Inhalte), max. 600 px breit. Ohne GD nur PNG/JPEG unverändert. **SVG bleibt verboten:** aktive Inhalte (Script, externe Referenzen) → XSS beim direkten Aufruf von `get_logo` (gleiche Origin) und Risiken im PDF-Renderer; sicheres Bereinigen wäre eine eigene Bibliothek.
5. **Farben:** Schlüssel `theme_primary`, `theme_accent`; gültig nur `''` oder `^#[0-9a-f]{6}$` (Groß-/Kleinschreibung egal, gespeichert klein). Abgeleitet in PHP: `--primary`/`--blue` = P, `--blue-hover` = P 15 % dunkler, `--teal-dark` = 30 % dunkler, `--blue-light`/`--bg-light` = P 12 % auf Weiß (dunkel: 20 % auf `#1C1C1E`), `--teal-gradient`; Akzent analog für `--yellow`, `--yellow-hover`, `--yellow-light`. Ausgabe als `:root{…}`, `body.dark-theme{…}`, `@media print{body,body.dark-theme{…}}`.
6. **Auslieferung `branding_css`** (öffentlich, weil Login-Seite): `Content-Type: text/css`, `Cache-Control: no-cache`, ETag. Werte werden **bei der Ausgabe erneut** gegen das Hex-Muster geprüft (Einstellungen können aus fremden Sicherungen stammen → sonst CSS-Injektion). Ist `branding_logo_app` an und ein Logo vorhanden, enthält das CSS zusätzlich Regeln, die das Standardlogo aus- und `.brand-logo-custom` einblenden (mit `!important`, weil `style.css` die Light-Variante so erzwingt). Kein JavaScript nötig, kein Flackern, keine Nutzung von `check` auf der Login-Seite.
7. **Kontrast:** Die Oberfläche zeigt das Kontrastverhältnis Weiß/Primärfarbe live und warnt unter 3 : 1; der Server sperrt nicht (Standard liegt selbst bei ~2,5 : 1). Siehe offene Frage 3.
8. **Rechte:** `save_settings`, `upload_logo` bleiben `Auth::requireRole('admin')`; `get_logo`, `branding_css` sind öffentlich und geben nur Logo bzw. Farben preis. Änderungen landen im Audit-Log.

### 2.7 Schritte (Commit-Reihenfolge)

1. **`feat(db)`: Migration `CreateFirmenlogoTable`** + neue Backup-Fixture (`php tests/bin/build-backup-fixture.php`). *Prüfbar:* `bin/console db:status` zeigt sie ausgeführt; `MigrationTest`/`BackupFixtureImportTest` grün auf SQLite und PostgreSQL.
2. **`feat`: `BrandingService` – Farbteil** (`normalizeColor`, Ableitungen, `contrastRatio`, `css(array $settings)`) + `tests/Unit/BrandingServiceTest.php`. *Prüfbar:* Unit-Tests grün.
3. **`feat`: `BrandingService` – Logoteil** (`logo()`, `saveLogo()`, `deleteLogo()`, `logoDataUri()`, Altdatei-Übernahme mit fester Namensliste relativ zu `DATA_DIR`).
4. **`sec`: Logo-Endpunkte härten** – `uploadLogo`/`getLogo`/`saveSettingsAction` (nur `''` für `firma_logo_url`) und `Auth::publicSettings()`-Normalisierung; `@` entfernen; Audit bei Upload/Löschen. *Prüfbar:* Api-Tests zu Pfadmanipulation, SVG, Nicht-Admin.
5. **`fix`: PDF-Logo über Service** in `BelegPdfService`, `ZugferdService`, vde0100-Modul. *Prüfbar:* bestehende Rechnungs-/Beleg-Tests grün; PDF enthält Logo auch im VDE-Protokoll.
6. **`feat`: Farbeinstellungen + `branding_css`** – Standardwerte in `Auth::loadSettings()`, Validierung in `saveSettingsAction`, Route, Handler. *Prüfbar:* Api-Tests Farben.
7. **`feat`: Sicherung** – Übernahme-Aufruf in `BackupWriter::writeDir()`; Api-Test Rundlauf (Sicherung mit Logo + Farben → frische Instanz → gleiches Logo, gleiches CSS) auf beiden Treibern.
8. **`feat(ui)`: Stylesheet/Logo in Seiten** – `<link>` in `index.html`, `login.html`, `mobile.html`, `mobile_light.html` (nach eigenen Styles), `.brand-logo-custom` in Kopfzeile/Login, Dunkelmodus-Chip in `style.css`.
9. **`feat(ui)`: Verwaltung → Allgemein „Erscheinungsbild“** in `script.js` (nur Admin): Farbwähler, Hex-Feld, „Standard“ je Farbe, „Alles zurücksetzen“, Kontrastanzeige, Logo-Schalter; nach Speichern `branding_css` mit Zeitstempel neu laden und `meta theme-color` setzen; `deleteFirmenlogo()`-Fehler und Hinweistext korrigieren.
10. **`fix(mobile)`: Tagesbericht-PDF mit Firmenlogo** statt `logo_es.png` (Rückfall bleibt).
11. **`chore`: Version/Cache** – `npm.cmd run minify`, `?v=` in `index.html` + `sw.js`, `CACHE_VERSION` erhöhen, `VERSION` (Minor) + `npm.cmd run version:sync`, `CHANGELOG.md`; `node scripts/check-versions.mjs --strict`, PHPStan ohne neue Baseline-Einträge, PHPUnit auf SQLite und PostgreSQL.

### 2.8 Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| SQLite/PostgreSQL | Nur Prepared Statements, Upsert per `ON CONFLICT(id)`, keine JSON-/Datumsfunktionen, camelCase `updatedAt` (Dialect quotet). Base64 als `TEXT` (kein `BLOB`/`bytea`-Unterschied im `TableCopier`). Größe ≤ ~2,7 MB. Tests auf beiden Treibern. |
| Migration + Fixture | E-020: Fixture im selben Commit. |
| Import alter Sicherungen | Alte Sicherungen haben keine Tabelle `firmenlogo` → wird nicht kopiert, vorhandenes Logo bleibt; alter `firma_logo_url`-Wert wird normalisiert. Logos alter Sicherungen waren nie enthalten → kein Verlust gegenüber heute. Test mit allen `tests/fixtures/backups/v*.zip`. |
| Import fremder/manipulierter Sicherungen | Farben und `firma_logo_url` werden bei jeder Ausgabe erneut geprüft; Server liest keinen Pfad aus Einstellungen. |
| Sicherheit Upload | MIME per `finfo`, Pixelgrenze, Neukodierung, kein SVG, `nosniff`, keine Dateinamen aus Eingaben. |
| Öffentliche Endpunkte | Nur Logo und Farben (für Login-Seite nötig). `check`-Leck bleibt bestehen → eigenes AP. |
| Cache/Service Worker | `api.php` ist im SW „network-first“ → Offline zeigt letztes Branding; HTML-Änderungen → `CACHE_VERSION` erhöhen; `get_logo` mit ETag + versionierter URL. |
| Teilwirkung der Farben | ~97 feste Farbwerte in `script.js` bleiben (v. a. Druck/PDF). Admin-Hinweis in der Karte: „wirkt auf die Oberfläche, nicht auf Belege“. |
| Lesbarkeit | Kontrastwarnung; Standard nur einen Klick entfernt; eigenes Logo im Dunkelmodus auf hellem Chip. |
| Modul-Lizenz/Feature-Flag | Kernfunktion ohne Modul/Flag (siehe offene Frage 5). |
| `deploy/` | Nicht von Hand ändern; wird über `make deploy` erzeugt. |

### 2.9 Abnahmekriterien

1. Admin setzt Primärfarbe `#aa3300` → `save_settings` 200; `GET branding_css` (ohne Anmeldung) liefert `text/css` mit `--primary:#aa3300` und `--blue:#aa3300` in `:root`, `body.dark-theme` und `@media print`, plus abgeleitete `--blue-hover`/`--blue-light`.
2. Werte wie `red`, `#12345`, `#12345g`, `#123456;}body{x:y}` → 400, gespeicherter Wert unverändert. `#AA3300` wird als `#aa3300` gespeichert.
3. Ist in `settings` ein ungültiger Farbwert (z. B. per Sicherung) gespeichert, enthält `branding_css` für diese Farbe **keine** Regel.
4. Farben leer (Standard / „Zurücksetzen“) → `branding_css` enthält keine Farbvariablen; Oberfläche sieht aus wie vorher.
5. Rolle `master` bzw. `normal`: `save_settings` mit `theme_primary` und `upload_logo` → 403; nicht angemeldet → 401.
6. `upload_logo` mit PNG/JPEG → 200, `url` = `api.php?action=get_logo&v=<8 hex>`; `get_logo` liefert `image/png` mit `ETag`, bei `If-None-Match` 304. SVG, als `.png` umbenannte Textdatei, > 2 MB oder > 4000 px → 400.
7. `save_settings` mit `firma_logo_url` = `../../VERSION` oder `data/database.sqlite` → wird nicht übernommen bzw. 400; `get_logo` liefert niemals andere Inhalte als das gespeicherte Logo (sonst 404).
8. `save_settings` mit `firma_logo_url` = `''` → Logo gelöscht, `get_logo` 404, `firma_logo_url` in `load_settings` leer.
9. Altinstallation mit `data/firma_logo.png` und ohne Tabellenzeile: `get_logo` liefert die Datei; danach enthält die Tabelle das Logo; neue Sicherung enthält es.
10. Sicherung einer Instanz mit Logo + Farben in eine frische Instanz einspielen (SQLite **und** PostgreSQL) → gleiches Logo (gleicher SHA-256), gleiches `branding_css`. Alle Fixtures `v*.zip` bleiben importierbar; vorhandenes Logo bleibt bei Import alter Sicherungen erhalten.
11. Rechnungs-/Beleg-PDF (`render_beleg_pdf`), ZUGFeRD und VDE-0100-Protokoll enthalten das Logo als Data-URI; ohne Logo Firmenname-Rückfall wie bisher.
12. `branding_logo_app = true` + Logo vorhanden → `branding_css` blendet `.brand-logo-custom` ein und das Standardlogo aus (Kopfzeile hell/dunkel, Login-Seite); `false` oder kein Logo → Standardlogo wie bisher.
13. `index.html`, `login.html`, `mobile.html`, `mobile_light.html` binden `api.php?action=branding_css` nach ihren eigenen Styles ein. `check-versions --strict` grün; `CACHE_VERSION` erhöht.
14. Verwaltung → Allgemein: Karte „Erscheinungsbild“ nur für Admin sichtbar; Kontrast < 3 : 1 zeigt Warnung; nach Speichern wirkt die Farbe ohne Neuladen der Seite.
15. PHPStan ohne neue Baseline-Einträge; kein `@` im geänderten Code.

### 2.10 Offene Fragen

1. **Welche Farben?** Vorschlag: nur Primär- und Akzentfarbe. Soll zusätzlich z. B. der Login-Hintergrund (heute dunkler Verlauf `#1a1a2e…`) einstellbar sein?
2. **Logo in der Oberfläche:** Soll das Firmenlogo per Schalter (Standard: aus) das „Planning Dashboard“-Logo in Kopfzeile und Login **ersetzen**, oder daneben stehen? Bleibt der Titel „Baukalkulation“ auf der Login-Seite?
3. **Kontrast:** Nur Warnung (Vorschlag) oder harte Sperre? Eine Sperre bei < 3 : 1 würde auch die heutige Standardfarbe verbieten; möglich wäre eine Sperre nur für extreme Werte (< 1,5 : 1, z. B. fast Weiß).
4. **Feste Farben:** Sollen die ~8 Modal-Kopfzeilen mit festem Verlauf `#00B4D8→#0096B7` in `script.js` auf Variablen umgestellt werden (kleiner Zusatzschritt), oder bleibt es bei „nur CSS-Variablen“? Belege/PDFs bleiben in jedem Fall unverändert – einverstanden?
5. **Lizenz:** Soll Branding an eine Lizenz/Edition gebunden sein (`LicenseService`) oder für alle frei?
6. **`check`-Leck:** Einverstanden, das Ausliefern der Einstellungen an nicht angemeldete Nutzer als eigenes Sicherheits-AP direkt danach zu planen?

**Freigabe G1:** ☑ durch Nutzer am 2026-09-29 – nach AP-20260929-sicherheit (Logo-Pfad-Lücke wird dort geschlossen). Übrige Fragen: Vorschlag des Architekten.

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

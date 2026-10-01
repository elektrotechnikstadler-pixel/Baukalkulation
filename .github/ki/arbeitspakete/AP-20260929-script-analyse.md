# AP-20260929-script-analyse: Prüfung, ob public/script.js verschlankt werden kann

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Refactoring |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

„Prüfung der Script-Datei, ob diese verschlankt werden sollte/kann.“

Gemeint ist `public/script.js` (zentrale Frontend-Datei). Ergebnis zunächst: Analyse mit Empfehlung
(Größe, Aufteilungsmöglichkeiten, toter Code, Doppelungen, Risiken) – Umsetzung erst nach Entscheidung des Nutzers.

## 2. Plan

### 2.1 Ist-Analyse (Fakten, Stand 2026-09-29, v2.10.99)

**Größe und Aufbau `public/script.js`**

| Kennzahl | Wert | Beleg |
|---|---|---|
| Zeilen | 23.251 | Dateiende Z. 23.251 (Start-IIFE ab Z. 23.239) |
| Dateigröße | > 1 MB; v2.10.70 waren es 1079 KB (min. 808 KB) bei deutlich weniger Zeilen – aktuelle Messung in Schritt A1 | CHANGELOG.md v2.10.70 |
| Top-Level-Funktionen | 778 (`^(async )?function`) | grep |
| Top-Level-`let/const/var` | 140 (globaler Zustand, z. B. `appData`, `selectedId`, `currentUser`) | grep |
| Top-Level-Seiteneffekte | ~15 (Error-Handler, SW-Registrierung, `addEventListener`, Start-IIFE) | grep Z. 19, 36, 143, 149, 192, 198, 2178, 2238, 2275, 2278, 7469, 7617, 7629, 15214, 16167, 18114, 20324, 23239 |
| Direkte `fetch('api.php?action=…')` | 228, jeweils mit eigenem JSON-/Fehler-Boilerplate | grep |
| Abschnitte (`// ====`-Köpfe) | ≈ 85 | grep |

**Grobe Funktionsbereiche (Zeilenbereiche, gerundet)**

| Bereich | Zeilen | Umfang |
|---|---|---|
| Kern: Error-Handler, Debounce, Icons, Datenmodell, Init, Laden/Speichern, 3-Wege-Merge, Offline-Sync | 1–1.330 | ~1.330 |
| Baustellen-CRUD, Sidebar, Drag&Drop, Detail-View, Schwärzen/Kundenansicht | 1.331–2.785 | ~1.450 |
| Gesamtübersicht, Material, KI-Scan (projekt/global), Arbeitszeit, Positionsarchiv, Pauschalen, Abschläge, Bautagebuch, Summen | 2.786–5.022 | ~2.240 |
| Materialkatalog, Datanorm, Metallzuschlag, HiCAD, OCI-Punchout | 5.023–6.140 | ~1.120 |
| Excel-/PDF-Export und -Import, Gewinn-Modal | 6.141–7.366 | ~1.230 |
| Helfer, Baustellen-Dropdown, Backup, Auth/Idle-Logout, Rechte, Theme | 7.367–7.952 | ~590 |
| Stundenkatalog, Fehlendes/Offenes Material, Archivieren, Archiv, NAS-Exporte, Voll-Download | 7.953–9.797 | ~1.850 |
| Notification, Benutzer-/Gruppenverwaltung, Einstellungen, Jahreswechsel, Feiertage, SMTP, OCI-Admin, Modul-Loader | 9.798–12.140 | ~2.340 |
| Terminplanung, Rechnungen & Angebote (inkl. E-Mail, PDF, ZUGFeRD) | 12.141–14.166 | ~2.030 |
| Dateien/Fotos, Feiertage/Werktage, Globale Suche | 14.167–14.922 | ~760 |
| Stundenerfassung, Stundenauswertung, Zeitübersicht, Wochenprüfung | 14.923–17.540 | ~2.620 |
| Wochenplanung (inkl. Jahresansicht), Stundenabgleich, PDF Zeitübersicht | 17.541–19.395 | ~1.850 |
| WhatsApp, Header-Menüs, Schnellnotizen, Tagesbericht, Kundenstamm | 19.396–21.377 | ~1.980 |
| Dashboard, Passwort/Speicherpfade, Auswertung, Start | 21.378–23.251 | ~1.870 |

**Auslieferung und Cache-Busting**

- `index.html` Z. 1008–1013 lädt klassisch `lib/xlsx`, `lib/chart`, `script.min.js?v=217` und zusätzlich
  `<script type="module" src="dist/core.js?v=100">`; `sw.js` precacht beide (`CACHE_VERSION = bk-es-v216`).
- `script.min.js` = `terser public/script.js --compress passes=2 --mangle` (package.json `minify`); CI-Job
  `frontend` prüft per `git diff`, dass `script.min.js` zu `script.js` passt. Top-Level-Namen werden nicht
  gemangelt (kein `--toplevel`) → `onclick="…"`-Strings funktionieren.
- `check-versions.mjs` liest `public/script.js` (Modul-`?v=` gegen `mobile.html`); `Dockerfile.app` Stufe 1
  kopiert `public/script.js` explizit. Beides muss bei einer Aufteilung mitgezogen werden.
- Modul-JS (`public/modules/<name>/<name>.js`) wird zur Laufzeit von `loadOptionalModule()` (Z. 12.039) als
  klassisches `<script>` nachgeladen und greift auf Globals aus `script.js` zu (`canDo`, `renderSidebar`,
  `showNotification`, `esc` …). **Folge: `script.js` muss klassisches Skript mit globalem Scope bleiben; ES-Module
  würden Modul-JS und hunderte `onclick`-Strings (index.html + in `script.js` erzeugtes HTML) brechen.**
- `mobile.html` (8.759 Z.) und `mobile_light.html` laden **kein** externes Skript; ihr gesamter Code ist inline
  (`mobile.html` Z. 1.896–8.757, 269 Funktionen).

**Verhältnis zum Vite-/Vue-Frontend `src/frontend/`**

- `vite.config.js` baut 16 Entries nach `public/dist/` (gitignored, im Docker-Image frisch gebaut). Referenziert
  wird nur `dist/core.js` (index.html + sw.js). `scripts/sync-manifest.mjs` sagt dagegen „keine HTML-Datei
  referenziert mehr dist“ – **Widerspruch zur tatsächlichen index.html**.
- `core.js` („Companion-Modus“ seit v2.9.9) macht einen zweiten `api.php?action=check`, schreibt
  `window.currentUser`, `window.enabledModules`, `window.bkLicense` … – **kein Code in `public/` liest diese Werte**
  (grep leer; `script.js` nutzt eigene `let`-Bindungen). Ergebnis: wirkungslos, kostet einen Request.
- Die 15 übrigen Bundles (Legacy-Adapter/Feature-Wrapper) werden von keiner Seite geladen. `.vue`/`.ts`-Dateien
  und `__tests__/*.spec.ts` sind nicht im Build-Graph; `vue`/`vitest` fehlen in `package.json` → nicht bau-/testbar.

**Toter Code (Stichprobe, ~40 Namen geprüft in `public/**`, `modules/**`, inkl. `onclick`-Strings)**

- Belegt tot bzw. wirkungslos:
  - `showDin1090()` (Z. 2.789–2.814): bricht ohne `renderDin1090` ab; nach dem Laden überschreibt
    `public/modules/din1090/din1090.js` Z. 1.674 `window.showDin1090`. ~26 Zeilen.
  - `const bOpts_unused` (Z. 12.344).
  - `dist/core.js` samt Vite-Entries (siehe oben).
- Stichprobe sonst ohne Treffer: alle übrigen geprüften Funktionen (u. a. `preloadMetallprofileCatalogToDb`,
  `matDrop`, `_kigResolveBs`, `repairArchivProjekte`, `renderMzInfoBar`, `closeRenameModalOverlay`) haben
  Aufrufer, teils nur über `onclick`-Strings. **Einschätzung: echter toter Code ist gering (< 2 %)** – verlässliche
  Zahl nur per automatischer Inventur (Schritt A1).

**Doppelungen**

- Innerhalb `script.js`:
  - 15 `show…()`-View-Umschalter mit je 15–20 `hide(...)`-Zeilen und Button-Klassen (z. B. Z. 1.567, 2.120,
    2.803, 5.043, 21.307) → ~300 Zeilen, Listen teils uneinheitlich.
  - Modul-Export-Blöcke VDE/DIN/Aufmaß/Lager doppelt (Z. ~9.451–9.520 und ~9.717–9.786) → ~70 Zeilen.
  - 228 `fetch`-Aufrufe mit wiederholtem `method/headers/JSON.stringify/r.json()/showNotification`-Muster.
- Mit `mobile.html` (gleichnamige Funktionen, Verhalten teils abweichend): u. a. `canDo`, `applyTheme`,
  `applyPermissions`, `getSubprojects`, `hasSubprojects`, `generateProjektNr`, `generateUnterprojektNr`,
  `saveData`, `loadData`, `saveDataDebounced`, Merge-Logik (`_mergeCanonNorm`, `_mergeCanonStr`, `_mergeById`,
  `_isNextIdKey`, `_mergeSectionLabel`, `_diffMergeConflicts`, `_buildMergedData`), `ico`, `esc`, `fmt`,
  `getBaustelle`, `matVk`, `confirmDelete`, `showToast`, KI-Scan (`openKiScanModal`, `_kiScan*` 6×),
  Metallzuschlag (`isCableItem`, `parseCableSpec`, `calcMetallzuschlag`, `fetchMetallzuschlag`),
  Feiertage/Soll (`getBavarianHolidays`, `dateToKey`, `getWorkingDaysInMonth`, `calcSollMonat`,
  `getUserArbeitstage`), `newZeitUuid`, `getMonthName`, `setAppVersionFooter` → **≥ 40 Namen**.
- Mit Modul-JS: Module definieren eigene `esc()`/`notify()` in ihrer IIFE (bewusste Kapselung, kein Handlungsbedarf).

**Nebenbefunde (nicht Teil dieses AP, als eigene Tickets vorschlagen)**

- `lager.js` Z. 96 und `din1090.js` setzen `window.selectedId = null` – `selectedId` ist eine `let`-Bindung,
  kein `window`-Property → Zurücksetzen wirkt nicht.
- Kaputte Umlaute in Abschnittsköpfen (`PASSWORT —NDERN`, `ZUR—CKSETZEN`, Z. ~22.268/22.326).
- Kopf „GESAMTÜBERSICHT“ (Z. 2.787) steht über `showDin1090()`.

### 2.2 Ziel & Abgrenzung

Ziel: `script.js` wartbarer und kleiner machen, ohne das Laufzeitverhalten zu ändern. Umsetzung in Stufen,
jede Stufe einzeln freigebbar.

Nicht Teil:
- Umstieg auf ES-Module, Vue oder TypeScript; Umbau der `onclick`-Architektur.
- Zusammenführen von `mobile.html`/`mobile_light.html` mit dem Desktop (nur optional Stufe C, eigene Freigabe).
- Fachliche Änderungen, Backend, Datenbank.
- Behebung der Nebenbefunde.

### 2.3 Empfohlene Stufen

| Stufe | Inhalt | Nutzen | Aufwand | Risiko |
|---|---|---|---|---|
| **0 – Absicherung** | Statischer Frontend-Check + Asset-Test (siehe 2.6) | Voraussetzung für alles Weitere | S | gering |
| **A – Aufräumen** | Totes entfernen (`dist/core.js`-Einbindung, `showDin1090`, `bOpts_unused`, Funde aus Inventur); interne Doppelungen per Helfer (`showMainView(viewId, btnId)`, Modul-Export-Blöcke als eine Funktion) | −1 Request pro Seitenaufruf, ~400–500 Zeilen weniger, einheitliche View-Umschaltung | S–M | gering–mittel (View-Listen) |
| **B – Aufteilen** | `script.js` in ~14 Bereichsdateien (Tabelle 2.1) zerlegen; Auslieferung **weiter als ein** `script.min.js` (Terser verkettet die Dateien in fester Reihenfolge) | Übersicht, kleinere Diffs/Reviews, gezieltere KI-Bearbeitung; keine Änderung an Ladeweg, SW, Cache-Busting-Logik | M | mittel (Reihenfolge, Build-Kette) |
| **C – Optional** | (1) Vite-/Vue-Altlasten entfernen oder archivieren; (2) gemeinsame Helfer mit `mobile.html` als eigene Datei `public/js/shared.js` (Merge, Feiertage, Metallzuschlag, `esc/fmt/ico`); (3) `api()`-Fetch-Helfer schrittweise; (4) Browser-Smoke (Playwright) | weniger Doppelpflege Desktop/Mobil, schlankeres Build | M–L | mittel–hoch (Offline-PWA mobil, Build-Umbau) |

Empfehlung: **0 → A → B** umsetzen; C erst nach Nutzerentscheidung (offene Fragen 1–3).
Realistische Einsparung A: ~2 % Zeilen; der Hauptnutzen liegt in B (Wartbarkeit), nicht in Bytes.
Bytes über die Leitung sinken spürbar nur durch Gzip/Brotli am Server bzw. C(2)/C(3).

### 2.4 Betroffene Dateien

| Datei | Grund | Stufe |
|---|---|---|
| `scripts/check-frontend.mjs` (neu) | Statischer Check: Syntax, Funktionsinventar, unaufgelöste Handler, doppelte Top-Level-Namen | 0 |
| `tests/Api/FrontendAssetsTest.php` (neu) | Alle `<script src>`/`<link href>` aus `index.html` und `PRECACHE_URLS` aus `sw.js` existieren und werden ausgeliefert | 0 |
| `Makefile` | Target `test-frontend`, in `verify` aufnehmen | 0 |
| `.github/workflows/ci.yml` | Job `frontend`: `node scripts/check-frontend.mjs` | 0 |
| `.pre-commit-config.yaml` | Hook analog `check-versions` | 0 |
| `public/script.js` | Entfernen/Helfer (A); danach ersetzt durch `public/js/*.js` (B) | A, B |
| `public/index.html`, `public/sw.js` | `dist/core.js` entfernen; `?v=`/`CACHE_VERSION` erhöhen | A, B |
| `scripts/check-versions.mjs` | `dist/core.js` aus `ASSETS`; Modul-Versionen aus `public/js/*.js` statt `script.js` lesen | A, B |
| `scripts/sync-manifest.mjs`, `vite.config.js` | Kommentar/Entries an Realität anpassen (nur wenn C(1) nicht gewählt: `core` bleibt gebaut, aber ungenutzt) | A |
| `scripts/build-script.mjs` (neu) | Verkettet `public/js/*.js` in fester Reihenfolge per Terser-API → `script.min.js` (+ optional Source-Map) | B |
| `package.json` | `minify` → `node scripts/build-script.mjs` | B |
| `Dockerfile.app` | `COPY public/script.js` → `COPY public/js/` | B |
| `docs/entwicklung.md`, `.github/instructions/frontend.instructions.md`, `AGENTS.md` (falls nötig) | Neue Dateiaufteilung, Befehle, Regel „neue Funktion in passende Bereichsdatei“ | B |
| `CHANGELOG.md`, `VERSION` (+ `version:sync`) | Release-Eintrag | A, B |
| `deploy/` | entfällt (Release über GitHub, E-077) | – |

### 2.5 Struktur (Vorlage copier-astral)

- Vorlage kennt kein Browser-Frontend; Webroot `public/` ist bleibende Besonderheit (struktur.instructions.md).
- Tests: Vorlage `tests/` mit Suiten → `tests/Api/FrontendAssetsTest.php` in bestehender Suite `api`;
  statischer Check analog zu Lint/Type-Check der Vorlage als `scripts/check-frontend.mjs` neben
  `check-versions.mjs` (vorhandene Node-Skript-Konvention; kein neues Root-Skript, kein Python-Werkzeug).
- Makefile: Vorlage bündelt Prüfungen in `verify`; neues Target `test-frontend` (Namensschema wie
  `test-unit`/`test-api`) und Aufnahme in `verify`. CI: Schritt im bestehenden Job `frontend`.
- Bereichsdateien: `public/js/NN-<bereich>.js` (zweistellige Präfixe = Ladereihenfolge, wie nummerierte
  Migrationen). Abweichung von `src/`-Konvention begründet: Quellen bleiben im Webroot wie heute `script.js`
  (Debugging unminifiziert möglich); `src/frontend/` ist laut Instructions gesperrt.

### 2.6 Minimale Absicherung (keine Frontend-Tests vorhanden)

1. `scripts/check-frontend.mjs` (Node, ohne neue Abhängigkeit; Parser über vorhandenes `terser`):
   - jede Quelldatei parst fehlerfrei;
   - Inventar: Menge aller Top-Level-Funktionsnamen → `--snapshot` schreibt `tests/fixtures/frontend/functions.txt`,
     Standardlauf vergleicht (fehlende Namen = Fehler, neue = Hinweis) – sichert Stufe B „nichts verloren“;
   - doppelte Top-Level-Funktionsnamen über alle Bereichsdateien = Fehler (stilles Überschreiben);
   - alle Bezeichner aus `on*="name(` in `index.html` und in Template-Strings sowie `onClickFn`-Argumente von
     `loadOptionalModule` sind definiert (Allowlist für Modul-Globals `showLager`, `showAufmass`, `vde0100Modul` …);
   - Ausgabe „Funktionen ohne Referenz“ als Kandidatenliste für Stufe A (nur Bericht, kein Fehler).
2. `tests/Api/FrontendAssetsTest.php`: Built-in-Server liefert `index.html`, jede darin referenzierte
   Skript-/CSS-Datei und jede `PRECACHE_URLS`-Datei mit 200 (außer gitignored `dist/`, solange vorhanden).
3. Manueller Smoke-Durchlauf je Stufe (Checkliste im Abschnitt Tests): Login, Baustelle öffnen/speichern,
   Material/Arbeitszeit, Rechnungen, Stundenerfassung, Wochenplanung, Einstellungen, ein Modul (Lager/DIN),
   Offline-Neuladen (SW) – kein `#jsErrorBanner`, keine Konsolenfehler.
4. Optional (C4): Playwright-Headless-Smoke als eigener CI-Job – nur nach Freigabe (neue devDependency).

### 2.7 Schritte (Commit-Reihenfolge)

**Stufe 0**
1. `test: statischer Frontend-Check` – `scripts/check-frontend.mjs` + Snapshot `functions.txt` (778 Namen);
   `make test-frontend`, `verify`, CI-Job `frontend`, pre-commit. Prüfbar: grün auf aktuellem Stand; künstlich
   gelöschte Funktion → rot.
2. `test: Frontend-Assets im Api-Test` – `FrontendAssetsTest.php`. Prüfbar: grün; umbenannte Datei → rot.

**Stufe A**
3. `refactor: dist/core.js nicht mehr laden` – Zeile aus `index.html`, Eintrag aus `sw.js`, `ASSETS` in
   `check-versions.mjs`; Kommentar `sync-manifest.mjs`/`init.js` korrigieren; `CACHE_VERSION` erhöhen.
   Prüfbar: Netzwerk-Tab zeigt nur noch einen `action=check`.
4. `refactor: toten Code entfernen` – `showDin1090` (script.js), `bOpts_unused`, weitere von Schritt 1
   gemeldete und manuell bestätigte Kandidaten (je Kandidat grep inkl. `onclick`-Strings, Modul-JS, mobile).
   Snapshot aktualisieren. Prüfbar: check-frontend grün, DIN-1090-Button öffnet Ansicht.
5. `refactor: View-Umschaltung über showMainView()` – eine Liste aller Haupt-Views, 15 `show…()` nutzen sie.
   Prüfbar: jede Hauptansicht einzeln öffnen, nur sie ist sichtbar, Button aktiv.
6. `refactor: Modul-Export-Blöcke zusammenführen` – gemeinsame Funktion für NAS-Wochenexport und Voll-Download.
   Prüfbar: beide Excel-Dateien enthalten dieselben Modul-Sheets wie vorher.
7. `chore: minify, ?v=, CACHE_VERSION, VERSION, CHANGELOG` – `check-versions --strict` grün.

**Stufe B** (rein mechanisch, keine inhaltliche Änderung im selben Commit)
8. `build: script.min.js aus mehreren Quellen` – `scripts/build-script.mjs` mit fester Dateiliste; zunächst
   nur `public/script.js` als Eingabe. Prüfbar: `script.min.js` byte-gleich zum bisherigen Terser-Ergebnis.
9. `refactor: script.js in Bereichsdateien aufteilen` – reines Verschieben entlang Tabelle 2.1 nach
   `public/js/00-kern.js` … `public/js/90-start.js` (Error-Handler zuerst, Start-IIFE zuletzt); `public/script.js`
   entfällt. Prüfbar: Verkettung aller Dateien == alte `script.js` (Textvergleich), check-frontend-Snapshot
   unverändert, `script.min.js` identisch oder funktionsgleich.
10. `build: Folgeanpassungen` – `check-versions.mjs`, `Dockerfile.app`, CI-Diff-Prüfung auf `public/js/**`.
11. `docs: Frontend-Aufteilung` – `docs/entwicklung.md`, `frontend.instructions.md` (neue Regel: Änderungen in
    `public/js/*.js`, danach `npm run minify`), ggf. `AGENTS.md`.
12. `chore: Version/Cache-Busting/CHANGELOG`.

### 2.8 Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| SQLite/PostgreSQL, Migration + Fixture, Backup-Import | nicht betroffen (reines Frontend, keine Schemaänderung) |
| Klassischer globaler Scope geht verloren (ES-Module, IIFE-Wrapper) | Stufe B verkettet nur; keine Wrapper; Terser ohne `--toplevel` |
| Ladereihenfolge/TDZ: Top-Level-Code nutzt `let`/`const` aus späterer Datei | Nur ~15 Top-Level-Seiteneffekte; Verkettung erhält Originalreihenfolge 1:1; Textvergleich in Schritt 9 |
| Windows: `npm run` expandiert keine Globs | Dateiliste explizit in `build-script.mjs` statt Glob |
| Cache-Busting/Service Worker liefert alte Datei | `?v=` in index.html + sw.js, `CACHE_VERSION` erhöhen, `check-versions --strict`; Auslieferung bleibt eine Datei |
| `showMainView()` blendet bisher bewusst sichtbare View aus | Manueller Smoke je Ansicht; Listen vorher/nachher im Review vergleichen |
| Entfernen von `dist/core.js` bricht etwas | grep belegt: keine Leser der gesetzten Globals; Rollback = eine Zeile |
| Rechte/Sicherheit | keine Änderung an `canDo`/Rechteprüfungen; Umbauten ändern kein `innerHTML`-Escaping |
| Modul-Lizenz/Feature-Flag | `loadOptionalModule`/`applyModuleSettings` nur verschoben, nicht geändert; Smoke mit aktiviertem Modul |
| Image ohne neue Dateien | `Dockerfile.app`/`.dockerignore` prüfen; Release über GitHub (E-077) |

### 2.9 Berücksichtigte Erkenntnisse

- E-040: `publicDir: false` in `vite.config.js` bleibt (auch bei C1 nur Entries/Build entfernen, nicht Vite-Konfig umbiegen).
- E-050: Struktur/Make-Targets/CI nach copier-astral (Abschnitt 2.5).
- E-061: npm unter Windows als `npm.cmd` (Build-/Check-Befehle in Doku und Schritten).

### 2.10 Abnahmekriterien

1. `node scripts/check-frontend.mjs` und `make test-frontend` sind grün; eine gelöschte oder doppelt definierte
   Top-Level-Funktion bzw. ein unbekannter `onclick`-Handler lässt beide rot werden.
2. `FrontendAssetsTest` grün: jede in `index.html` und `sw.js` referenzierte Datei liefert 200.
3. Nach Stufe A: Seitenaufruf erzeugt genau einen `api.php?action=check`; `dist/core.js` wird weder geladen noch
   precacht; `script.js` hat nachweislich weniger Zeilen (Zahl im CHANGELOG).
4. Jede Hauptansicht (Sidebar/Header) öffnet sich, alle anderen Hauptansichten sind ausgeblendet, der zugehörige
   Button ist aktiv; DIN 1090, Lager, Aufmaß, VDE 0100 funktionieren bei aktivem Modul.
5. NAS-Wochenexport und Voll-Download enthalten dieselben Sheets wie vor der Änderung.
6. Nach Stufe B: `public/script.js` existiert nicht mehr; `public/js/*.js` verkettet ergibt den Stand vor der
   Aufteilung; Funktions-Snapshot unverändert; `script.min.js` wird nur aus `public/js/*.js` erzeugt und die
   CI-Prüfung „script.min.js aktuell?“ greift auf diese Quellen.
7. `node scripts/check-versions.mjs --strict` grün; `?v=` in index.html/sw.js und `CACHE_VERSION` erhöht.
8. Manueller Smoke (2.6 Nr. 3) ohne `#jsErrorBanner` und ohne Konsolenfehler, auch nach Offline-Neuladen.
9. `php vendor/bin/phpunit` und PHPStan bleiben grün (keine neuen Baseline-Einträge).

### 2.11 Offene Fragen an den Nutzer

1. **Umfang:** Sollen Stufe 0 + A + B umgesetzt werden, oder zunächst nur 0 + A?
2. **Vite/Vue (C1):** Das Vite-Build liefert nur noch das wirkungslose `core.js`. Soll `src/frontend/`,
   `modules/*/frontend/`, `vite.config.js`, `sync-manifest.mjs` und die Vite-Stufe im `Dockerfile.app` entfernt
   werden (git-Historie bleibt), oder bleibt der Build als „Backup“ bestehen?
3. **Mobile (C2):** Sollen gemeinsame Helfer (≥ 40 gleichnamige Funktionen) später als `public/js/shared.js`
   auch von `mobile.html` genutzt werden? Das berührt die Offline-PWA mobil und braucht eigene Freigabe.
4. **Ablageort Quellen (B):** `public/js/` (im Webroot, unminifiziert abrufbar wie heute `script.js`) oder außerhalb
   des Webroots (z. B. `resources/js/`), sodass nur `script.min.js` ausgeliefert wird?
5. **Source-Map:** Soll `script.min.js` eine Source-Map bekommen (bessere Fehlermeldungen im `#jsErrorBanner`,
   legt Quellcode offen – bei `public/js/` ohnehin der Fall)?
6. **Browser-Smoke (C4):** Playwright als neue devDependency + CI-Job gewünscht, oder genügen statischer Check,
   Asset-Test und manuelle Checkliste?

**Freigabe G1:** ☑ durch Nutzer am 2026-09-29 – Stufe 0 + A; Stufe B später. Übrige Fragen: Vorschlag des Architekten.

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

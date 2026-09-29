# AP-20260929-menuebaum: Menübaum neu gliedern

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

Menübaum anders darstellen:

- Gesamtübersicht
- Dashboard
- Überkategorie **„Kataloge“**: Material-Katalog, Stunden-Katalog, Pauschalen verwalten, Dienstleister
- Offenes Material
- Archiv
- Überkategorie **„Belege“**: Angebot, Rechnung, Lieferschein
- Überkategorie **„Module“**: DIN EN 1090 (falls aktiviert), VDE 0100 (falls aktiviert), Aufmaß (falls aktiviert), Lager (falls aktiviert)
- Schnellnotizen

Gilt für die Desktop-Oberfläche; Auswirkungen auf `mobile.html` prüfen.

## 2. Plan

### Ziel & Abgrenzung

Der Block „Menü“ der Desktop-Seitenleiste (`public/index.html`, `#secMenu`) wird in die gewünschte
Reihenfolge gebracht; „Kataloge“, „Belege“ und „Module“ werden aufklappbare Untergruppen. Der
Auf-/Zu-Zustand wird pro Gruppe im Browser gemerkt. Sichtbarkeit bleibt an Rechte (`canDo`) und
Modul-Schalter (`appSettings.modul_*`) gebunden; eine Gruppe ohne sichtbaren Eintrag verschwindet.

**Nicht Teil:**
- Neuer Belegtyp **Lieferschein** (existiert nicht: `rechnungen.typ` kennt nur `rechnung|angebot`,
  `CrudActions.php` L331) → siehe offene Frage 1.
- Header-Dropdowns „Zeitverwaltung“ (Stundenerfassung, Wochenplanung, Zeitübersicht,
  Stundenabgleich, Stundenauswertung, Terminplanung) und „Verwaltung“ (Excel, Benutzer,
  Speicherpfade, Allgemein/Einstellungen, Dark Mode, Passwort, Abmelden) bleiben unverändert.
- Projektliste (`#secProjekte`), Backend, Datenbank, Rechte-Definitionen, Lizenzprüfung im Backend.
- `mobile.html` / `mobile_light.html` (eigene Oberfläche, eigenes Inline-JS, lädt `script.js`
  nicht; Aktionsleiste über `mobile_btn_order`) – keine Änderung nötig.
- Inaktives Vue-Frontend `src/frontend/` (referenziert `btnAngeboteSub` nur dort, bleibt unberührt).
- `deploy/` (generiert).

### Ist-Zustand (Analyse)

- Seitenleiste: [public/index.html](../../../public/index.html#L119-L157), eine flache Liste
  `.sidebar-nav-section` im aufklappbaren Abschnitt „Menü“ (`toggleSidebarSection('secMenu')`).
- Auf-/Zuklappen + Merken existiert bereits: `toggleSidebarSection(id)` / `restoreSidebarSections()`
  ([script.js](../../../public/script.js#L1682-L1702)), Speicher `localStorage.collapsedSidebarSecs`,
  Pfeil-ID wird als `'arrow' + id.replace('sec','')` abgeleitet. CSS: `.sidebar-collapse-*`
  ([style.css](../../../public/style.css#L1166-L1203)).
- Sichtbarkeit wird an drei Stellen gesetzt: `checkAuth` (online + offline,
  [script.js](../../../public/script.js#L298-L383)), `applyModuleSettings()`
  ([script.js](../../../public/script.js#L11987-L12037)), `applyPermissions()`
  ([script.js](../../../public/script.js#L7904-L7950)).
- Module: `applyModuleSettings()` → `loadOptionalModule()` prüft per `HEAD`, ob
  `public/modules/<name>/<name>.js` existiert, lädt JS/CSS und hängt – falls nicht statisch
  vorhanden – einen Button an `.sidebar-nav-section` an. Statisch vorhanden ist nur `btnVde0100`;
  `btnDin1090`, `btnAufmass`, `btnLager` entstehen dynamisch. Bedingungen:
  DIN 1090 `modul_din1090 !== false && canReadDin1090`, Aufmaß `modul_aufmass === true && canReadAufmass`,
  Lager `modul_lager === true && canReadLager`, VDE `modul_vde0100 === true && canReadVde0100`.
  Modul-JS setzt `active` über `.sidebar-overview-btn` + eigene Button-ID – Klasse und IDs müssen bleiben.
- Lizenz: nur im Backend (`ModuleLoader::userHasAccess` → `LicenseService::isModuleAllowed`).
  Die `check`-Antwort liefert `license` ohne Modulliste → Frontend kennt die Lizenz nicht (offene Frage 5).
- Befunde (werden mit behoben, weil „Rechte respektieren“ Auftrag ist):
  - `applyModuleSettings()` blendet `btnSchnellnotizenSidebar` und `btnAuswertung` allein nach
    Modul-Schalter ein – überschreibt die Rechteprüfung aus `checkAuth` (Button sichtbar für
    Nutzer ohne Recht; Backend sperrt trotzdem).
  - Deaktiviert man DIN 1090/Aufmaß/Lager in den Einstellungen, bleibt der Button bis zum Neuladen sichtbar.

### Abbildung alt → neu

| Heute (Menü) | Neu | Sichtbar wenn (unverändert, außer markiert) |
|---|---|---|
| Gesamtübersicht `btnUebersicht` | Gesamtübersicht | immer |
| 📋 Dashboard `btnDashboard` | Dashboard (Position 2) | `modul_dashboard === true` && `canReadDashboard` |
| Material-Katalog `btnMaterialKatalog` | Kataloge › Material-Katalog | immer |
| Stunden-Katalog `btnStundenKatalog` | Kataloge › Stunden-Katalog | immer |
| Pauschalen verwalten `btnPauschalenView` | Kataloge › Pauschalen verwalten | `modul_pauschalen !== false` |
| Dienstleister `btnDienstleister` | Kataloge › Dienstleister | `canManageDienstleister` && `modul_dienstleister !== false` |
| Kundenstamm `btnKundenstamm` | **Vorschlag:** Kataloge › Kundenstamm (Stammdaten wie Dienstleister) | immer |
| Offenes Material `btnOffenesMaterial` | Offenes Material | `canSeeOffenesMaterial` |
| Archiv `btnArchiv` | Archiv | immer |
| Rechnungen & Angebote `btnRechnungen` (Gesamtliste) | entfällt als Eintrag; wird Gruppenkopf „Belege“ (nur auf/zu) | – |
| ↳ Angebote `btnAngeboteSub` | Belege › Angebot (Badge bleibt) | `modul_rechnungen !== false` && (`canSeeAngebote`‖`canManageAngebote`) |
| ↳ Rechnungen `btnRechnungenSub` | Belege › Rechnung (Badge bleibt) | `modul_rechnungen !== false` && (`canSeeRechnungen`‖`canManageRechnungen`) |
| – | Belege › Lieferschein | **entfällt vorerst** (offene Frage 1) |
| DIN EN 1090 (dynamisch) | Module › DIN EN 1090 (statischer Button) | wie heute + Modul-JS vorhanden |
| ⚡ VDE 0100 `btnVde0100` | Module › VDE 0100 | wie heute |
| Aufmaß (dynamisch) | Module › Aufmaß (statischer Button) | wie heute + Modul-JS vorhanden |
| Lager (dynamisch) | Module › Lager (statischer Button) | wie heute + Modul-JS vorhanden |
| Auswertung `btnAuswertung` | **Vorschlag:** oberste Ebene direkt nach Dashboard | **fix:** `canSeeAuswertung` && `modul_auswertung !== false` |
| WhatsApp `btnWhatsApp` | **Vorschlag:** oberste Ebene direkt vor Schnellnotizen | admin/master |
| Schnellnotizen `btnSchnellnotizenSidebar` | Schnellnotizen (letzter Eintrag, Badge bleibt) | **fix:** admin/master && `modul_schnellnotizen !== false` |
| Header „Zeitverwaltung“ / „Verwaltung“ (inkl. Einstellungen, Wochenplanung, doppeltes „Schnellnotizen“) | bleiben im Header | unverändert |

Neue Reihenfolge: Gesamtübersicht · Dashboard · (Auswertung) · **Kataloge ▾** · Offenes Material ·
Archiv · **Belege ▾** · **Module ▾** · (WhatsApp) · Schnellnotizen.

### Betroffene Dateien

| Datei | Grund |
|---|---|
| `public/index.html` | Menü-Markup neu ordnen, drei Gruppen (`secNavKataloge`, `secNavBelege`, `secNavModule`) mit Kopf-Button + Body; statische Buttons `btnDin1090`, `btnAufmass`, `btnLager`; `btnRechnungen` entfernen; `?v=` für `script.min.js` und `style.css` erhöhen |
| `public/script.js` | Gruppen-Sichtbarkeit (`updateSidebarGroups()`), Modul-Buttons ein-/ausblenden, Rechte-Fix in `applyModuleSettings()`, Referenzen auf `btnRechnungen` entfernen, `aria-expanded` in `toggleSidebarSection()` |
| `public/script.min.js` | generiert per `npm.cmd run minify` |
| `public/style.css` | Stil für Gruppenkopf (dezenter als Abschnittskopf) und Einrückung der Gruppeneinträge, Dark-Theme-Variante |
| `public/sw.js` | `?v=` in `PRECACHE_URLS` + `CACHE_VERSION` erhöhen |
| `public/bedienungsanleitung.html` | Satz zur Seitenleiste (L88) an neue Gliederung anpassen |
| `CHANGELOG.md` | Eintrag unter „Unveröffentlicht“ |

### Struktur (Vorlage copier-astral)

Reine Frontend-Änderung innerhalb bestehender Dateien; keine neuen Ordner, Make-Targets, CLI-Befehle
oder CI-Jobs. Prüfung über bestehendes `make check-versions` (≙ Vorlagen-Target-Stil). Keine Abweichung.

### Berücksichtigte Erkenntnisse

- **E-040** – `vite.config.js` nicht anfassen (`publicDir: false`).
- **E-050** – Struktur geprüft, keine Änderung nötig.
- **E-061** – `npm.cmd run minify` / `npm.cmd run version:sync` unter Windows.
- frontend.instructions.md – `script.min.js` nicht von Hand, `?v=` + `CACHE_VERSION` erhöhen,
  Beschriftungen per `textContent`/statischem Markup (keine Nutzerdaten per `innerHTML`).

### Schritte

1. **Markup** (`index.html`): Einträge in `#secMenu .sidebar-nav-section` in neue Reihenfolge
   bringen. Pro Gruppe:
   `<div class="sidebar-nav-group" id="navGroupKataloge">` mit
   `<button class="sidebar-group-toggle" onclick="toggleSidebarSection('secNavKataloge')" aria-expanded="true" aria-controls="secNavKataloge"><span class="collapse-arrow" id="arrowNavKataloge">▾</span> Kataloge</button>`
   und `<div class="sidebar-collapse-body" id="secNavKataloge">…Buttons…</div>`; analog Belege/Module.
   Button-IDs, `onclick` und Klasse `sidebar-overview-btn` unverändert übernehmen. `btnRechnungen`
   entfernen; Sub-Buttons „Angebot“ (`btnAngeboteSub`) vor „Rechnung“ (`btnRechnungenSub`), Inline-
   Einrückung durch CSS ersetzen. Statische, versteckte Buttons `btnDin1090` (🏗️ DIN EN 1090),
   `btnVde0100`, `btnAufmass` (📏 Aufmaß), `btnLager` (📦 Lager) in die Gruppe Module.
   *Prüfbar:* Seite lädt ohne Konsolenfehler, Reihenfolge wie Tabelle.
2. **CSS** (`style.css`): `.sidebar-group-toggle` (links bündig, normale Schrift, Pfeil),
   `.sidebar-nav-group .sidebar-collapse-body .sidebar-overview-btn { padding-left: … }`, Dark-Theme-Regel.
   *Prüfbar:* hell/dunkel lesbar, Einträge eingerückt.
3. **Gruppen-Sichtbarkeit** (`script.js`): `updateSidebarGroups()` blendet jedes `.sidebar-nav-group`
   aus, wenn kein enthaltener `.sidebar-overview-btn` sichtbar ist (`style.display !== 'none'`).
   Aufruf am Ende von `applyModuleSettings()`, `applyPermissions()` und nach dem Einblenden eines
   Modul-Buttons in `loadOptionalModule()`.
   *Prüfbar:* Nutzer ohne Beleg-Rechte sieht keine Gruppe „Belege“; alle Module aus → keine Gruppe „Module“.
4. **Modul-Buttons** (`applyModuleSettings()` / `loadOptionalModule()`): für DIN 1090, Aufmaß, Lager,
   VDE einheitlich: Bedingung falsch → Button `display:none`; wahr → `loadOptionalModule()`, das den
   Button erst nach erfolgreichem `HEAD` (oder wenn Script schon geladen) einblendet und `onclick`
   setzt. Fallback-Anhängen fremder Buttons zielt auf `#secNavModule` statt `.sidebar-nav-section`.
   `?v=` der Modul-Dateien **nicht** ändern (sonst Drift gegenüber `mobile.html`, check-versions B).
   *Prüfbar:* Modul in Einstellungen aus → Button sofort weg; Modul-Datei fehlt → Button bleibt verborgen.
5. **Rechte-Fix + Aufräumen** (`script.js`): in `applyModuleSettings()` `btnSchnellnotizenSidebar` nur
   für admin/master, `btnAuswertung` nur mit `canSeeAuswertung` einblenden; `toggle('btnRechnungen', …)`
   ersetzen durch Schalter auf die beiden Sub-Buttons (Rechte wie in `applyPermissions()`); alle
   Referenzen auf `btnRechnungen` in `checkAuth`, `applyPermissions()`, `showRechnungenView()` entfernen
   (dort bleibt `active` für den Sub-Button). *Prüfbar:* `grep btnRechnungen\b` in `public/` leer.
6. **Zustand merken**: `toggleSidebarSection()` setzt zusätzlich `aria-expanded` am auslösenden
   Kopf; `restoreSidebarSections()` ebenso. Speicher bleibt `collapsedSidebarSecs` (Default: offen).
   *Prüfbar:* Gruppe zuklappen, Seite neu laden → bleibt zu.
7. **Doku**: `bedienungsanleitung.html` L88, `CHANGELOG.md` („Unveröffentlicht“ → „Geändert“).
8. **Build & Versionen**: `npm.cmd run minify`; `script.min.js?v=217→218`, `style.css?v=144→145` in
   `index.html` **und** `sw.js`; `CACHE_VERSION bk-es-v216→v217`; `node scripts/check-versions.mjs --strict` grün.
   `VERSION` nur nach Antwort auf Frage 6.
9. Commit: `feat(ui): Menübaum der Seitenleiste in Gruppen gliedern`.

### Risiken

- **SQLite/PostgreSQL, Migration, Fixture, Backup-Import:** nicht betroffen (keine Schema-/Backend-Änderung).
- **Rechte/Sicherheit:** Menü ist nur Komfort, Backend prüft weiterhin (`Auth::canDo`, `ModuleLoader`).
  Rechte-Fix in Schritt 5 ändert Sichtbarkeit für Nutzer ohne Recht (gewollt). Offline-Zweig von
  `checkAuth` nutzt `localStorage.bk_user` – `updateSidebarGroups()` läuft über `applyPermissions()` auch dort.
- **Reihenfolge der Aufrufe:** `applyModuleSettings()` läuft vor `loadData()`/`applyPermissions()`;
  Modul-Buttons erscheinen asynchron → Gruppen-Update muss nach jedem Einblenden laufen (Schritt 3/4).
- **Modul-JS** entfernt `active` über `.sidebar-overview-btn` und setzt es per ID – Klasse/IDs bleiben, sonst bricht die Markierung.
- **Cache-Busting:** ohne `?v=`/`CACHE_VERSION`-Erhöhung sehen Nutzer altes Menü mit neuem JS (fehlende IDs).
- **Lizenz/Feature-Flag:** Frontend zeigt Modul-Button auch ohne Lizenz (heutiges Verhalten; Klick liefert Backend-Fehler) → Frage 5.
- **Aufgeklappte Gruppe mit aktivem Eintrag zugeklappt:** aktiver Eintrag unsichtbar; akzeptiert (kein Auto-Aufklappen, kleinste Lösung).

### Abnahmekriterien

1. Admin mit allen Modulen an sieht im Block „Menü“ genau: Gesamtübersicht, Dashboard, [Auswertung],
   Kataloge ▾ (Material-Katalog, Stunden-Katalog, Pauschalen verwalten, Dienstleister, [Kundenstamm]),
   Offenes Material, Archiv, Belege ▾ (Angebot, Rechnung), Module ▾ (DIN EN 1090, VDE 0100, Aufmaß, Lager),
   [WhatsApp], Schnellnotizen – in dieser Reihenfolge.
2. Klick auf einen Gruppenkopf klappt nur diese Gruppe auf/zu; Pfeil dreht sich; `aria-expanded` wechselt.
3. Zustand jeder Gruppe übersteht Neuladen und Ab-/Anmelden im selben Browser; neue Browser starten aufgeklappt.
4. Jeder Eintrag öffnet dieselbe Ansicht/dasselbe Modal wie vorher und wird als `active` markiert
   (Angebot → `showRechnungenView('angebot')`, Rechnung → `showRechnungenView('rechnung')`).
5. Modul in „Allgemein“ aus → Eintrag verschwindet ohne Neuladen; alle vier aus → Gruppe „Module“ unsichtbar.
   Modul an, aber Recht `canRead<Modul>` fehlt → Eintrag unsichtbar.
6. Nutzer ohne Angebots- und Rechnungsrechte oder `modul_rechnungen = false` → keine Gruppe „Belege“;
   nur ein Recht → nur der passende Eintrag.
7. Rolle `normal` sieht Schnellnotizen/WhatsApp nicht; ohne `canSeeAuswertung` keine Auswertung – auch nach Speichern einer Einstellung.
8. Badges (Angebote/Rechnungen-Anzahl, Schnellnotizen, WhatsApp) erscheinen weiterhin am jeweiligen Eintrag.
9. Header-Dropdowns, Projektliste und `mobile.html` verhalten sich unverändert.
10. `node scripts/check-versions.mjs --strict` grün; `script.min.js` aus `script.js` erzeugt; PHPUnit unverändert grün.

### Offene Fragen

1. **Lieferschein:** Gibt es heute nicht als Beleg. Vorschlag: im Menü weglassen und als eigenes
   Arbeitspaket (Belegtyp, PDF, Nummernkreis, Rechte) planen. Alternativ deaktivierter Platzhalter?
2. **Nicht im Wunschbaum:** Kundenstamm → Kataloge, Auswertung → nach Dashboard, WhatsApp → vor
   Schnellnotizen – einverstanden?
3. **Gesamtliste „Rechnungen & Angebote“** (beide Typen in einer Ansicht) entfällt im Menü. Ok, oder
   zusätzlicher Eintrag „Alle Belege“ bzw. Klick auf Gruppenkopf öffnet die Gesamtliste?
4. Gruppen standardmäßig **aufgeklappt** – ok?
5. **Lizenz im Menü:** Soll `check` zusätzlich die freigeschalteten Module liefern (kleine Backend-
   Änderung + API-Test), damit unlizenzierte Module ausgeblendet werden? Sonst eigenes AP.
6. **Version:** `VERSION` in diesem AP erhöhen (z. B. 2.11.0) oder erst beim Release?
7. Doppelter Eintrag „Schnellnotizen“ im Header-Dropdown „Verwaltung“ belassen?

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

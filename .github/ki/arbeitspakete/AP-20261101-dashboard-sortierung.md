# AP-20261101-dashboard-sortierung: Dashboard – Anordnung wählbar & Verschieben reparieren

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Bugfix · Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Dashboard:
> - Die Anordnung der Aufgaben, Termine, … soll angepasst werden Können (nach Datum, A-Z, …)
> - Die Verschiebung der Zeilen Funktioniert nicht wie sie soll

## 2. Plan

### Ziel & Abgrenzung
- **Ziel 1 (Bugfix):** Das manuelle Verschieben einer Dashboard-Zeile (↑/↓) speichert eine konsistente, dauerhafte Reihenfolge, die nach dem Neuladen erhalten bleibt.
- **Ziel 2 (Feature):** Je Rubrik (Aufgaben, Notizen, Erinnerungen, Termine) ist eine Sortierung wählbar: **manuell** (Standard, entspricht ↑/↓), **Fälligkeit/Datum**, **A–Z (Titel)**. Die gewählte Sortierung wird je Nutzer gemerkt (localStorage).
- **Nicht Teil:** Historie/Archiv (→ AP-20261101-dashboard-historie), „Termin aus Eintrag erzeugen“ (→ AP-20261101-dashboard-termin-aus-eintrag), echtes HTML5-Drag&Drop (bleibt ↑/↓-Buttons; nur optional als offene Frage).

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `public/script.js` | `moveDashboardItem()` (≈23040–23079) Reihenfolge-Berechnung reparieren; `renderDashboard()` (≈22620–22701) Sortier-Umschalter je Rubrik ergänzen; `renderDashboardItem()` unverändert. |
| `src/Handlers/DashboardActions.php` | `updateSort()` (≈238–287) auf lückenlose Neuvergabe der `sortPos` je Pool umstellen; `load()`-Sortierung bleibt `sortPos ASC, erstelltAm DESC`. |
| `public/style.css` | Styles für Sortier-Auswahl (Select/Segment) im Rubrik-Header. |
| `public/bedienungsanleitung.html` | Sichtbare Änderung (E-081): Sortierauswahl und korrigiertes Verschieben dokumentieren. |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting nach Änderung an `script.js` (`npm run minify`, `?v=` + `CACHE_VERSION` erhöhen). |
| `tests/js/*.test.mjs` bzw. `tests/Api/` | Tests der Sortier-/Reorder-Logik (siehe Abnahme). |

### Struktur (Vorlage copier-astral)
- Reine Frontend-/Handler-Änderung ohne neue Struktur. CLI/CI/Doku-Gliederung unverändert (E-050). Keine neue Migration nötig (`sortPos` existiert).

### Berücksichtigte Erkenntnisse
- **E-081** Anleitung mitpflegen. **E-082/E-090** beide DB grün + Tests dauerhaft. **E-088** `update_dashboard_sort`-Vertrag bleibt kompatibel (Feld `items:[{id,sortPos}]`) – alte Clients (v3.x) senden weiter Einzeltausch, der Server muss das weiter akzeptieren. **E-072** Subagenten-Befunde an der Fundstelle geprüft.

### Bug-Ursache (belegt)
`public/script.js` `moveDashboardItem()` (≈23058–23068) berechnet `newA`/`newB`, verwendet sie aber **nie**; stattdessen werden nur die bestehenden `sortPos` getauscht. Bei gleichem/fehlendem `sortPos` (Fallback `|| idx`) entstehen Kollisionen; die Sekundärsortierung `erstelltAm DESC` im Backend (`DashboardActions::load`) „korrigiert“ die Anzeige dann scheinbar zurück. Zusätzlich wird im Statusfilter (`singleColMode` für `offen`/`erledigt`) typübergreifend getauscht, was die Typ-Spalten inkonsistent macht.

### Schritte
1. **Backend `updateSort()` robuster:** Weiterhin `items:[{id,sortPos}]` akzeptieren (Abwärtskompatibilität, E-088), aber nach dem Update optional eine lückenlose Neunummerierung je Ersteller/Pool nicht erzwingen – stattdessen Frontend liefert vollständige, lückenlose `sortPos`-Liste des betroffenen Pools. Rechte-/Ersteller-Prüfung je Eintrag beibehalten.
2. **Frontend Reorder-Fix:** In `moveDashboardItem()` den betroffenen Pool (Typ-Spalte bzw. Statusansicht) nehmen, Element um eine Position tauschen, dann **alle** Pool-Elemente neu von 0..n durchnummerieren und diese vollständige Liste an `update_dashboard_sort` senden. Tote Variablen `newA`/`newB` entfernen.
3. **Statusfilter-Konflikt entschärfen:** In `offen`/`erledigt`-Ansicht Verschieben nur innerhalb desselben `typ` erlauben oder ↑/↓ dort ausblenden (Entscheidung: innerhalb Typ sortieren, damit Typ-Spalten konsistent bleiben).
4. **Sortier-Umschalter je Rubrik:** In `renderDashboard()` pro Spalte ein `<select>` (manuell / Fälligkeit / A–Z) ergänzen; Auswahl je Rubrik in `localStorage` (`dashSort.<typ>`). Bei „manuell“ gilt `sortPos`; bei „Fälligkeit“ nach `faelligAm` (leer ans Ende); bei „A–Z“ nach `titel` (locale, `de`).
5. **↑/↓ nur bei „manuell“ aktiv:** Bei aktiver Datums-/A–Z-Sortierung die Verschiebe-Buttons deaktivieren/ausblenden (sonst widersprüchlich).
6. **Anleitung** `public/bedienungsanleitung.html` ergänzen (Sortierauswahl, Hinweis „Verschieben nur im Modus manuell“).
7. **Cache-Busting:** `npm run minify`, `?v=` in `index.html`/`sw.js` + `CACHE_VERSION` erhöhen (`make check-versions` grün).
8. **Tests** ergänzen (siehe Abnahme).

### Risiken
- **Alte Clients (E-088):** v3.x-Frontend sendet nur 2 Items an `update_dashboard_sort`; Server muss das weiter korrekt verarbeiten → Backend nimmt beliebige Teillisten entgegen (keine Pflicht zur Vollliste).
- **SQLite/PostgreSQL:** Nur `UPDATE … SET sortPos=? WHERE id=?`, keine Dialekt-Besonderheit; dennoch beide Läufe grün (E-082).
- **Migration/Backup:** Keine Migration, keine Fixture-Neuerzeugung nötig.
- **Rechte:** Ersteller-/`canManage`-Prüfung je Eintrag in `updateSort()` erhalten (kein Fremdeintrag umsortierbar).
- **Cache-Busting:** `script.js` geändert → Minify + Versions-Bump zwingend, sonst sehen Nutzer alten Stand.

### Abnahmekriterien
1. Zwei Aufgaben vertauschen (↑/↓); nach `load_dashboard` bleibt die neue Reihenfolge bestehen (keine Rücksprünge).
2. Nach mehreren Verschiebungen sind die `sortPos` im betroffenen Pool lückenlos und eindeutig (0..n).
3. Sortierung „A–Z“ zeigt Einträge nach Titel; „Fälligkeit“ nach `faelligAm` aufsteigend (leere zuletzt); „manuell“ zeigt `sortPos`-Reihenfolge.
4. Bei „A–Z“/„Fälligkeit“ sind ↑/↓ deaktiviert.
5. Ein Nutzer ohne `canManageDashboard` kann fremde Einträge nicht umsortieren (403/übersprungen).
6. Alter Client-Aufruf mit nur 2 Items funktioniert weiterhin (Charakterisierungstest).

### Offene Fragen
- Soll die gewählte Sortierung **pro Nutzer serverseitig** gespeichert werden (statt nur localStorage)? 
- Ist echtes Drag&Drop (statt ↑/↓) gewünscht, oder genügen die Pfeil-Buttons?

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

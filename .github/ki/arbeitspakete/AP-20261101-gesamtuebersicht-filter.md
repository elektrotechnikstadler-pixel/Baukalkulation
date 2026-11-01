# AP-20261101-gesamtuebersicht-filter: Gesamtübersicht – Summen bei aktivem Filter

| Feld | Wert |
|---|---|
| Status | abgeschlossen (lokal committet, Release nach B3) |
| Typ | Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 2 |
| Commit | fb8ca84 (Bündel B1) |

## 1. Auftrag

> Gesamtübersicht:
> wird in der Gesamtübersicht gefiltert, wird trotzdem in der Zeile Gesamt alle Gesamtsummen angezeigt und nicht der gefilterten Positionen

## 2. Plan

### Ziel & Abgrenzung
- **Ziel:** Bei aktivem Filter in der Gesamtübersicht zeigt die „Gesamt“-Zeile (und die zugehörigen KPI-Karten) die Summe **nur der sichtbaren/gefilterten** Zeilen.
- **Nicht Teil:** Umbau der Filterlogik auf Array-Filterung statt DOM-Filter (nur so weit wie nötig), neue Spalten/Export.

### Bug-Ursache (belegt)
`public/script.js`, `renderUebersicht()` (ab ≈3017): Die Gesamtwerte `totalMat/totalAz/totalP/grand/totalAbschlag/grandOffen/totalGewinn` werden einmalig aus **allen** `rows` berechnet (≈3100–3130) und fest in die Fußzeile (≈3260–3268) sowie die KPI-Karten (≈3090–3165) geschrieben. `filterUebersichtRows()` (≈3276) blendet nur `<tr>`-Elemente per `style.display` aus und berechnet die Summen **nicht neu**. Es existiert kein gefiltertes Datenarray.

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `public/script.js` | `filterUebersichtRows()` (≈3276) muss nach dem Ein-/Ausblenden die Summen der sichtbaren Zeilen neu berechnen und Fußzeile + KPI-Karten aktualisieren; ggf. gemeinsame Hilfsfunktion `recalcUebersichtSummen()`. Beachtet `isSub`/`excluded`-Logik wie im Initial-Render. |
| `public/bedienungsanleitung.html` | Falls Verhalten dokumentiert ist, anpassen (E-081). |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/js/` | Summenberechnung aus gefilterter Zeilenmenge (reine Rechenlogik). |

### Struktur (Vorlage copier-astral)
- Reiner Frontend-Bugfix; keine Struktur/Migration (E-050).

### Berücksichtigte Erkenntnisse
- **E-081** Anleitung. **E-082/E-090** Tests dauerhaft (hier JS-Test der Rechenlogik). **E-036** gemeinsame Fallliste nur falls PHP-Pendant betroffen – hier reine JS-Anzeige, daher JS-Test genügt. **E-072** Fundstellen geprüft.

### Schritte
1. **Summen-Neuberechnung:** Hilfsfunktion, die über die aktuell **sichtbaren** `#ovBody tr.ov-row` (bzw. das gefilterte Datenmodell) iteriert und `totalMat/totalAz/totalP/grand/totalAbschlag/grandOffen/totalGewinn` aufsummiert; dabei dieselbe `isSub`/`excluded`-Regel wie im Initial-Render anwenden.
2. **Fußzeile + KPI aktualisieren:** Fußzellen und KPI-Karten nach jedem Filtervorgang neu befüllen (gleiche Formatierung `fmt`, gleiche Klassen `price-sensitive` etc.).
3. **Leerer Filter:** Bei leerem Filter wieder die Gesamtsumme aller Zeilen (Ausgangszustand).
4. **Zusammenspiel mit Sortierung:** Sicherstellen, dass `sortUebersichtRows()` die Summen nicht verfälscht (Summe ist sortierunabhängig).
5. **Anleitung** prüfen/ergänzen.
6. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
7. **Tests** (siehe Abnahme).

### Risiken
- **Datenbasis:** Filter arbeitet heute auf DOM-Textinhalt. Für exakte Summen ist es robuster, parallel das Datenarray (`rows`) nach demselben Kriterium zu filtern und daraus zu summieren, statt Beträge aus dem DOM zu parsen. Entscheidung im Plan: aus dem Datenmodell summieren (keine Lokalisierungs-/Parsing-Fehler).
- **Unterzeilen (`isSub`) / ausgeschlossene (`excluded`):** Müssen in der Neuberechnung identisch behandelt werden wie beim Initial-Render, sonst Abweichung.
- **SQLite/PostgreSQL:** Keine DB-Änderung.
- **Cache-Busting:** `script.js` geändert → Versions-Bump.

### Abnahmekriterien
1. Ohne Filter: „Gesamt“-Zeile = Summe aller (nicht ausgeschlossenen) Projekte (unverändert).
2. Mit Filter, der eine Teilmenge zeigt: „Gesamt“-Zeile = Summe nur der sichtbaren Zeilen (Material, Arbeit, Pauschalen, Gesamt, Abschlag, Offen, Gewinn).
3. KPI-Karten oberhalb spiegeln dieselbe gefilterte Summe.
4. Filter leeren → Summen wieder vollständig.
5. Sortieren bei aktivem Filter ändert die Summen nicht.

### Offene Fragen
- Sollen die KPI-Karten oberhalb **ebenfalls** auf die gefilterte Menge umschalten (angenommen: ja), oder bewusst immer die Gesamtsumme zeigen?

**Entscheidung Nutzer (2026-11-01):** KPI-Karten werden ebenfalls auf die gefilterte Menge umgerechnet.

**Festlegung Leitstand (B1):** Summen als reine Funktion `bkUebersichtSummen(rows, sichtbareIds)` in neuer Datei `public/uebersicht-summen.js` (Muster `verwaltung-nav.js`, Einbindung in `index.html` + `sw.js`), testbar per `node:vm`. Regel: Sichtbare Oberprojekte und eigenständige Baustellen (nicht `excluded`) zählen mit ihren aggregierten Werten. Sichtbare Unterprojekte zählen nur, wenn ihr Oberprojekt **nicht** sichtbar ist (keine Doppelzählung). KPI „Baustellen“ = Anzahl sichtbarer Zeilen.

**Freigabe G1:** ☑ durch Nutzer am 2026-11-01 (Bündel B1)

## 3. Umsetzung
- `public/uebersicht-summen.js` (neu): reine Funktion `bkUebersichtSummen(rows, sichtbareIds)`.
- `public/script.js`: `renderUebersicht()`/neue `_ovAktualisiereSummen()` nutzen die Funktion für
  Fußzeile + KPI-Karten nach Filter/Sortierung.
- `public/index.html`, `public/sw.js`: Einbindung `uebersicht-summen.js?v=1` + Cache-Busting
  (`script.min.js?v=247`, `CACHE_VERSION=bk-es-v250`).
- `public/script.min.js`: neu minifiziert (`npm run minify`).
- Keine PHP-Änderung (reiner Frontend-Bugfix, wie geplant).
- Prüfungen (B1, Runde 2): `php vendor/bin/phpstan analyse --memory-limit=2G` → 0 Befunde;
  `npm.cmd run test:js` → 27/27 grün (inkl. `uebersicht-summen.test.mjs`);
  `node scripts/check-versions.mjs --strict` → OK; `node -e "new Function(...script.js)"` → Syntax OK.
- Abweichung: keine.
- Modell laut Aufruf (E-097): Entwickler-Agent, `entwickler.agent.md` → Claude Sonnet 5 (Selbstauskunft „GPT-5.1“ unzuverlässig, nicht überprüfbar). Runde 1 brach mit 402 ab; der Rest wurde gezielt nachdelegiert.

**Korrekturrunde 1 (E-094):** Befund A: `bkUebersichtSummen` nutzt String-Set + `String(id)`-Vergleich,
`filterUebersichtRows` sammelt `tr.dataset.id` ungecastet. Befund B: `updateSchnellnotiz` liest `id`
zusätzlich aus `$_GET['id']` (Reihenfolge unverändert). Cache: `?v=2`/`?v=248`/`v251`; phpstan/test:js (27/27)/
phpunit unit (346 OK)/check-versions grün. Modell laut Aufruf: Entwickler-Agent (Claude Sonnet 5).

## 4. Tests
- Lauf: `npm.cmd run test:js` -> **GRUEN** (29/29).
- Matrix AK1 -> `tests/js/uebersicht-summen.test.mjs::ohne Filter summiert nur Oberprojekte ohne excluded` (JS).
- Matrix AK2+AK3 -> `...::Filter auf Teilmenge ...`, `...::Parent + Unterprojekt sichtbar vermeidet Doppelzaehlung`, `...::gemischte ID-Typen ...`, `...::sichtbare Zahlen-IDs ...` (JS).
- Matrix AK4 -> `...::leerer Filter liefert 0-Summen und anzahl 0` (JS).
- Matrix AK5 -> `...::Reihenfolge der rows ist fuer Summen egal` (JS).
- DB-Ergebnis: SQLite n/a, PostgreSQL n/a (reiner Frontend-Test).
- Modell laut Aufruf (E-097): Tester-Agent, `tester.agent.md` → GPT-5.3-Codex.

## 5. Review

**Sammelreview B1** (Reviewer, Modell aus dem Aufruf: GPT-5.3-Codex – GPT-5.5 nicht verfügbar, E-097; eingetragen vom Leitstand, da der Reviewer Runde 1 nicht ins AP schrieb, E-072)

Runde 1: **CHANGES_REQUESTED**

| Schwere | Datei:Zeile | Problem | Entscheidung Leitstand |
|---|---|---|---|
| Major | `public/uebersicht-summen.js`, `public/script.js` `filterUebersichtRows` | Typstrenger ID-Vergleich → Doppelzählung bei String-`parentId` | berechtigt → Korrekturrunde 1 (A) |
| Major | `src/Handlers/PlanActions.php` `updateSchnellnotiz` | GET mit `?id=` liefert 404 statt 405 (E-015) | berechtigt, als Minor eingestuft (GET ändert nichts) → Korrekturrunde 1 (B) |
| Major | Tests AP4/AP5 fehlen | E-090 | entfällt in dieser Runde: Tests nach `APPROVE` (E-094) |

Runde 2 (nur Korrekturen A/B, E-094): **APPROVE** – A und B behoben, Reihenfolge 404 → 403 → 405 eingehalten, Cache-Busting konsistent.

## 6. Lernpunkte

- E-096 (ID-Typen normalisieren), E-097 (Modell aus dem Aufruf, Rest nach Abbruch delegieren); Lernprotokoll 2026-11-01 „B1“.

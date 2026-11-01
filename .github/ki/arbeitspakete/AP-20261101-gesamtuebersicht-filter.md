# AP-20261101-gesamtuebersicht-filter: Gesamtübersicht – Summen bei aktivem Filter

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

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

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

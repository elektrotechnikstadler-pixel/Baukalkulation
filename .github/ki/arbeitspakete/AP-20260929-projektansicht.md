# AP-20260929-projektansicht: Übersichtlichkeit in Projekten + Umlaute bei Katalogimport

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature + Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

1. „Die Übersichtlichkeit in den jeweiligen Projekten verbessern: Sind zu viele Materialpositionen bzw.
   Arbeitszeitpositionen im jeweiligen Projekt, wird es unübersichtlich. Wie kann man das übersichtlicher
   gestalten? Vorschläge.“
2. **Bug:** „Bei vom Katalog importiertem Material werden ä, ö, ü nicht richtig angezeigt.“

Bugfix nach Ablauf: zuerst reproduzierender Test (Tester), dann Fix.

## 2. Plan

### 2.1 Ziel & Abgrenzung

- **Teil A (Bug, zuerst):** Artikel aus dem Katalog „Aus Katalog“ (Datanorm) zeigen ä/ö/ü/ß korrekt –
  unabhängig davon, ob die Datanorm-Dateien in CP850 (DOS), Windows-1252 oder UTF-8 (mit/ohne BOM) vorliegen.
- **Teil B (Übersicht):** Minimalpaket für die Projekt-Detailansicht (Desktop) bei vielen Material-/Arbeitszeitpositionen;
  weitere Vorschläge nur als Katalog mit Aufwand, Umsetzung nach Auswahl durch den Nutzer.
- **Nicht Teil:** Reparatur bereits gespeicherter kaputter Positionen (nur nach Rückfrage, eigenes AP), Mobile-Ansicht,
  Tabs/Umbau der Seite, Inline-Edit, virtuelles Scrollen, die zerstörten Zeichen in `public/script.js` (Nebenbefund 2.3),
  OCI-Punchout, HiCAD-/Metallprofil-Katalog, Schema-/API-Änderungen für Teil B.

### 2.2 Ist-Analyse Teil A – Umlaut-Bug

**Importweg:** Upload `datanorm.001/.wrg/datpreis.001` → `datanorm_upload` (Datei unverändert gespeichert,
[CatalogActions.php](../../../src/Handlers/CatalogActions.php#L217)) → „Datanorm indexieren“ → `datanorm_reindex`
schreibt `data/datanorm_index.tsv` ([CatalogActions.php](../../../src/Handlers/CatalogActions.php#L27)) →
Picker „Aus Katalog“ ruft `datanorm_search` ([CatalogActions.php](../../../src/Handlers/CatalogActions.php#L133),
[script.js](../../../public/script.js#L5909)) → `insertFromKatalog()` übernimmt `bezeichnung` unverändert in
`b.material` ([script.js](../../../public/script.js#L6091)) → gespeichert im JSON-Blob `baustellen.data`.
Gleiche Suche nutzen [mobile.html](../../../public/mobile.html#L3377), Aufmaß- und Lager-Modul.

**Ursache (Beleg):** Beim Indexieren wird jede Zeile fest als ISO-8859-1 interpretiert:
[CatalogActions.php](../../../src/Handlers/CatalogActions.php#L72) (Warengruppen `.wrg`) und
[CatalogActions.php](../../../src/Handlers/CatalogActions.php#L107) (Artikel `.001`):
`mb_convert_encoding(…, 'UTF-8', 'ISO-8859-1')`. Nur Windows-1252/Latin-1-Dateien werden richtig.

| Tatsächliche Kodierung | Ergebnis heute | Sichtbar als |
|---|---|---|
| CP850/CP437 (DOS-Zeichensatz, Datanorm 4) | ä=0x84→U+0084, ö=0x94→U+0094, ü=0x81→U+0081, ß=0xE1→„á“ | Umlaute fehlen/Kästchen, ß wird „á“ |
| UTF-8 ohne BOM | „für“ → „fÃ¼r“ | typische „Ã“-Zeichen |
| UTF-8 mit BOM | wie oben + erster Datensatz geht verloren (`$p[0]` = BOM+„A“ ≠ „A“) | – |
| Windows-1252 | korrekt | – |

Ausgeschlossen (geprüft): HiCAD-CSV `Materialbibliothek_Uebersicht.csv` enthält keine Umlaute; Frontend-`esc()`
escaped einfach, kein doppeltes Escaping ([script.js](../../../public/script.js#L7378)); API sendet
`charset=utf-8`; `importMaterialExcel()` liest nur `.xlsx/.xls`; lokaler Materialkatalog wird manuell gepflegt.

### 2.3 Nebenbefunde (nicht Teil dieses AP)

- `public/script.js` enthält aus einer früheren Fehlkodierung „—“ statt ä/ö/ü/×/… in UI-Texten, z. B.
  [script.js](../../../public/script.js#L7346) „Bl—tter“, [script.js](../../../public/script.js#L13204) „Gel—scht“,
  [script.js](../../../public/script.js#L6302) Dateinamen-Regex `[^a-zA-Z0-9———————_-]` (Umlaute in Projektnamen
  werden in Exportdateinamen zu „_“). Betrifft nicht die Katalogdaten.
- OCI-Hook ([OciActions.php](../../../src/Handlers/OciActions.php#L265)): sendet ein Lieferant ISO-8859-1, liefert
  `json_encode` `false` → Übernahme bricht still ab (kein Umlautfehler, aber verwandtes Risiko).

### 2.4 Ist-Analyse Teil B – Projekt-Detailansicht

- Desktop `renderDetail()` ([script.js](../../../public/script.js#L2335)): eine lange Seite aus Abschnittskarten
  (Kopf mit Summen-Badges, Zusammenfassung, Material, Arbeitszeit, Pauschalen, Abschläge, Angebote/Rechnungen,
  Fehlendes Material, Bautagebuch …). Abschnitte sind zuklappbar, Zustand global in `localStorage`
  ([script.js](../../../public/script.js#L134)).
- **Material** `renderMatRows()` ([script.js](../../../public/script.js#L4082)): 9 Spalten, jede Zeile vollständig als
  Eingabefelder; manuelle Kategorien mit auf-/zuklappbarem Kopf, Anzahl und Zwischensumme; Zuklapp-Zustand
  `_collapsedMatCats` nur im Speicher ([script.js](../../../public/script.js#L4075)); Textfilter
  `filterMatRows()` ([script.js](../../../public/script.js#L4043)) ohne Trefferzahl/Summe; keine Sortierung,
  Reihenfolge = Einfügereihenfolge (neue oben); EK-Spalten umschaltbar ([script.js](../../../public/script.js#L2137)).
- **Arbeitszeit** `renderAzRows()` ([script.js](../../../public/script.js#L4281)): flache Liste, fest nach Datum
  absteigend ([script.js](../../../public/script.js#L4289)), keine Gruppen/Zwischensummen, pro Zeile ein Select mit
  allen Stundenkategorien; Textfilter `filterAzRows()` ([script.js](../../../public/script.js#L4332)); Fußzeile mit
  Gesamt-/Produktivstunden.
- **Mobile** ([mobile.html](../../../public/mobile.html#L3189), [mobile.html](../../../public/mobile.html#L3805)):
  Material nach Kategorie als feste Überschriften, Arbeitszeit flach und unsortiert, keine Suche.

### 2.5 Vorschläge Teil B (Katalog)

| Nr | Vorschlag | Nutzen | Aufwand |
|---|---|---|---|
| V1 | Arbeitszeit gruppieren: umschaltbar **Monat / Mitarbeiter / keine**; Gruppenkopf auf-/zuklappbar mit Anzahl, Σ h und Σ € (Muster wie Material-Kategorien); aktueller Monat offen, ältere zu | größter Effekt bei vielen Stundenbuchungen | S–M |
| V2 | Material: Zuklapp-Zustand der Kategorien je Baustelle in `localStorage` merken + Schalter „Alle auf/zu“ | Kategorien bleiben nach Neuladen geordnet | S |
| V3 | Treffer-/Summenzeile beim Filtern (Material + Arbeitszeit): „12 von 240 Positionen · 1.234,56 €“ bzw. „… · 38,5 h“ | Filter wird auswertbar | S |
| V4 | Sprungleiste (sticky) unter dem Kopf mit Abschnitten + Anzahl (Material 240 · Arbeitszeit 95 · …) | schnelle Navigation statt Scrollen | S |
| V5 | Schnellfilter-Chips: „nur meine“ (`erstelltVon`), Zeitraum (Woche/Monat), „nur unproduktiv“, „nur Import“ | häufige Sichten mit einem Klick | S–M |
| V6 | Kompaktmodus (geringere Zeilenhöhe) + Spaltenauswahl (erweitert EK-Umschalter) | mehr Zeilen pro Bildschirm | S / M |
| V7 | Sortierung per Spaltenkopf (Bezeichnung, Anzahl, Gesamt, Datum) – nur Ansicht | Suchen großer Posten | M |
| V8 | Material zusätzlich gruppieren nach Datum/Erfasser (neben Kategorie) | Nachvollziehen „wer hat wann“ | M |
| V9 | „Mehr anzeigen“ je Gruppe (erste 50 Zeilen) | schnelleres Rendern | S–M |
| V10 | Lesezeilen statt Eingabefelder, Bearbeiten per Klick | deutlich ruhiger + schneller | L |
| V11 | Tabs statt langer Seite | kürzere Seite | M–L (Module hängen Abschnitte ein) |
| V12 | Virtuelles Scrollen | nur bei >1000 Zeilen nötig; kollidiert mit Gruppen, Drag&Drop, DOM-Filter | L – nicht empfohlen |
| V13 | Mobile: Suche + zuklappbare Gruppen + Arbeitszeit nach Monat | Baustellen-Handy | M |
| – | „nur offene“ Positionen | Material/Arbeitszeit haben kein Status-/Abrechnungsfeld (nur „Fehlendes Material“) → offene Frage 3 | – |

**Empfehlung Minimalpaket (Schritt 1):** V1 + V2 + V3 + V4 – nur `public/script.js` (+ CSS), keine API-/DB-Änderung.

### 2.6 Betroffene Dateien

| Datei | Grund |
|---|---|
| `src/Handlers/CatalogActions.php` | Zeichensatz-Erkennung in `datanormReindex()` (Zeilen 72, 107), BOM entfernen, `encoding` in Antwort |
| `tests/Api/DatanormEncodingTest.php` (neu) | reproduzierender Test (Tester) |
| `public/script.js`, `public/script.min.js` (generiert) | V1–V4 |
| `public/style.css` | Stile Gruppenkopf/Sprungleiste (bestehende `.mat-cat-*`-Klassen wiederverwenden) |
| `public/index.html`, `public/sw.js` | `?v=` und `CACHE_VERSION` (Cache-Busting) |
| `CHANGELOG.md` | Hinweis „Datanorm nach Update neu indexieren“ + neue Ansicht |

### 2.7 Struktur (Vorlage copier-astral)

Keine neuen Ordner, Make-Targets oder CI-Jobs. Test in `tests/Api` (≙ `tests/` der Vorlage). Eine spätere
Datenreparatur käme als Befehl in `bin/console` (≙ `cli.py`), nicht als Root-Skript oder Migration (Daten, kein Schema).
`deploy/` wird nicht angefasst.

### 2.8 Berücksichtigte Erkenntnisse

E-030 (SQLite-Dateien unter Windows, Test-Aufräumen über `TestServer`), E-050 (Struktur), E-060/E-061 (`php`-Pfad,
`npm.cmd run minify`), E-063 (kein `@` im neuen Code – bestehender Code nutzt es, nicht nachahmen).

### 2.9 Schritte

**Teil A – Bugfix**

1. **Tester – reproduzierender Test** `tests/Api/DatanormEncodingTest.php` (`extends ApiTestCase`, `setupAdmin()`):
   Dateien per `$this->server->dataPath('datanorm/…')` schreiben (Bytes mit Hex-Escapes im Test erzeugen, keine
   Fixture-Datei → kein Umkodieren durch Editoren, keine Kundendaten). Satzaufbau wie Parser: `.001`
   `A;N;4711;Kabelschuh für Kupferleiter;größer Querschnitt;STK;0;1;1234;;1;01`, `.wrg` `S;1;01;Zubehör Ösen`.
   DataProvider: `cp850`, `windows-1252`, `utf-8`, `utf-8-bom` (BOM vor dem ersten `A`-Satz).
   Ablauf: `datanorm_reindex` → `datanorm_search?q=kabelschuh` und `?q=größer`. Erwartung: `bezeichnung` ===
   `Kabelschuh für Kupferleiter größer Querschnitt`, `warengruppe` === `Zubehör Ösen`.
   Rot vor Fix erwartet: `cp850`, `utf-8`, `utf-8-bom`; `windows-1252` grün (Charakterisierung). Rotlauf in §4 belegen.
2. **Fix** in `CatalogActions::datanormReindex()` (private Methoden in derselben Klasse, zwei Aufrufstellen):
   - Zeichensatz je Datei einmal aus einer Stichprobe bestimmen (erste ~256 KB, am letzten Zeilenende abschneiden):
     BOM `EF BB BF` → UTF-8 (BOM entfernen); gültiges UTF-8 mit Bytes ≥ 0x80 → UTF-8; sonst Häufigkeit
     CP850-Umlautbytes (0x81 0x84 0x8E 0x94 0x99 0x9A 0xE1) gegen Windows-1252-Umlautbytes
     (0xC4 0xD6 0xDC 0xDF 0xE4 0xF6 0xFC) → höhere gewinnt, Gleichstand → CP850 (Datanorm-Standard).
   - Je Zeile: bei UTF-8 unverändert, falls Zeile ungültiges UTF-8 → Windows-1252 als Rückfall; sonst
     `mb_convert_encoding($line, 'UTF-8', $erkannt)`.
   - Antwort ergänzt `encoding` (z. B. `{"ok":true,"count":…,"encoding":"CP850"}`); bestehende Felder unverändert.
   - Test aus Schritt 1 grün, `phpstan` ohne neue Baseline-Einträge. Commit gemeinsam: `fix(datanorm): Zeichensatz beim Indexieren erkennen`.
3. `CHANGELOG.md`: Hinweis „Nach dem Update Datanorm einmal neu indexieren; bereits übernommene Positionen bleiben unverändert.“

**Teil B – Minimalpaket** (jeweils: `npm.cmd run minify`, `?v=` in `index.html` + `sw.js`, `CACHE_VERSION`,
`node scripts/check-versions.mjs --strict`)

4. `feat(projekt): Arbeitszeit gruppieren` (V1): Umschalter Monat/Mitarbeiter/keine über dem Filter; Gruppenköpfe
   wie `mat-cat-header`; Σ h/Σ € je Gruppe (€ nur mit `price-sensitive`); Gruppierung + Zuklapp-Zustand je Baustelle
   in `localStorage`; aktiver Filter klappt alle Gruppen auf (Muster aus `filterMatRows()`); Drag&Drop der Zeilen bleibt.
5. `feat(projekt): Material-Gruppen merken, Filtersumme, Sprungleiste` (V2–V4): `_collapsedMatCats` je Baustelle
   persistieren + „Alle auf/zu“; Treffer-/Summenzeile unter beiden Filtern; sticky Sprungleiste mit Anzahl je Abschnitt,
   nur für sichtbare/aktivierte Abschnitte (`appSettings.bau_*`, Rechte).

### 2.10 Risiken

- **SQLite/PostgreSQL:** Teil A/B ändern kein SQL; API-Test läuft in beiden Matrizen (nur Login-Tabellen).
- **Migration/Fixture/Backup:** keine Schemaänderung → keine Migration, keine neue Fixture; `datanorm_index.tsv`
  ist nicht Teil der Sicherung (Laufzeitdatei) – prüfen, sonst Neuindexierung nach Import nötig.
- **Fehlerkennung:** kurze Dateien ohne Umlaute sind egal (ASCII); gemischte Dateien → Rückfall je Zeile.
  `CP850` muss in `mb_list_encodings()` des Docker-Images vorhanden sein (im Umsetzungsschritt prüfen, sonst `iconv`).
- **Alte Daten:** bereits übernommene Positionen bleiben fehlerhaft, bis der Nutzer über Reparatur entscheidet.
- **Rechte/Sicherheit:** `datanorm_reindex` bleibt admin/master; neue UI-Texte/Gruppennamen (Mitarbeiterkürzel,
  Kategorien) nur über `esc()`/`textContent`; Summen nur mit `canSeePrices`.
- **Cache-Busting:** ohne `?v=`/`CACHE_VERSION` sehen Nutzer altes JS (Service Worker).
- **Modul-Lizenz/Feature-Flag:** nicht betroffen; Sprungleiste darf keine Abschnitte deaktivierter Module zeigen.
- **Leistung:** Gruppierung darf das Rendern nicht verlangsamen (zugeklappte Gruppen rendern keine Zeilen).

### 2.11 Abnahmekriterien

Teil A
1. Datanorm-Dateien in CP850, Windows-1252, UTF-8 und UTF-8 mit BOM: nach „Datanorm indexieren“ liefert die Suche
   Bezeichnung und Warengruppe mit korrekten ä/ö/ü/Ä/Ö/Ü/ß.
2. Suche mit Umlaut im Suchbegriff (`größer`) findet den Artikel.
3. Bei UTF-8 mit BOM wird auch der erste Artikel indexiert (`count` stimmt).
4. `datanorm_reindex` meldet den erkannten Zeichensatz im Feld `encoding`.
5. „Aus Katalog“ → Einfügen übernimmt die korrekte Bezeichnung in die Materialposition (manuell im Browser).
6. Alle bestehenden Tests grün auf SQLite und PostgreSQL; PHPStan ohne neue Baseline-Einträge.

Teil B (manuelle Prüfliste, keine JS-Testumgebung vorhanden)
7. Arbeitszeit lässt sich nach Monat bzw. Mitarbeiter gruppieren; jede Gruppe zeigt Anzahl, Σ h und (mit Preisrecht) Σ €;
   Summe aller Gruppen = Fußzeilensumme.
8. Gruppierung und Zuklapp-Zustände (Material + Arbeitszeit) bleiben nach Neuladen je Baustelle erhalten.
9. Aktiver Filter zeigt Treffer auch in zugeklappten Gruppen und die Zeile „x von y Positionen · Summe“.
10. Sprungleiste springt zu jedem sichtbaren Abschnitt; ohne Preisrecht keine Beträge in neuen Elementen.
11. Bearbeiten, Löschen, Archivieren und Drag&Drop von Positionen funktionieren unverändert.
12. `check-versions --strict` grün; nach Update lädt der Browser die neue `script.min.js`.

### 2.12 Offene Fragen

1. Wie sieht das falsche Zeichen aus – „Ã¤“ (→ UTF-8-Datei) oder fehlend/Kästchen/„á“ statt ß (→ CP850)? Welcher
   Großhändler/Datanorm-Version? Gerne eine anonymisierte Beispielzeile (Hex) – bestimmt die Rückfallregel.
2. Bereits übernommene Positionen reparieren? Vorschlag: `bin/console data:repair-encoding --dry-run` (eigenes AP) für
   `baustellen.data` (Material, Fehlendes Material), `material_katalog`, Lager/Aufmaß, Belegpositionen – nur nach Sicherung.
3. Was bedeutet „nur offene“ Positionen – noch nicht abgerechnet (kein Feld vorhanden) oder nur „Fehlendes Material“?
4. Minimalpaket V1–V4 so freigeben? Arbeitszeit-Standardgruppierung Monat oder Mitarbeiter? Mobile (V13) später?
5. Nebenbefunde (zerstörte Zeichen in `script.js`, OCI mit ISO-8859-1) als eigene APs anlegen?

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

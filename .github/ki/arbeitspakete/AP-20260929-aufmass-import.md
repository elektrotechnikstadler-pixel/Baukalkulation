# AP-20260929-aufmass-import: Aufmaß aus Datei-Upload (z. B. Magicplan)

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Modul |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

„Aufmaßmodul: Möglichkeit, über Upload z. B. eine Magicplan-Datei hochzuladen und daraus ein Aufmaß
zu erstellen, z. B. für Schreinerei – ist dies möglich? Sinnvolle Vorschläge liefern.“

Ergebnis zunächst: Machbarkeit, geeignete Importformate, Vorschläge mit Aufwand/Nutzen – Umsetzung nach Entscheidung.

## 2. Plan

### 2.1 Ist-Analyse Aufmaß-Modul

- **Schema** (`modules/aufmass/AufmassDatabase.php`, per `migrate()` im Modul, nicht per Phinx; Spalten
  snake_case – Altbestand, wird hier nicht geändert):
  - `aufmass` (id, `baustelle_id` → Baustelle optional, titel, status `entwurf|geprueft|uebernommen`, erstellt/geprüft/übernommen, notiz)
  - `aufmass_abschnitt` (id, aufmass_id, name, sortier) – frei benannte Gliederung, passt 1:1 auf „Raum“
  - `aufmass_position` (bezeichnung, **formel** (Freitext), **menge** REAL, **einheit** Freitext, einzelpreis, ek,
    `ref_typ`/`ref_id` (z. B. `lager`), ausgewaehlt, sortier, notiz)
- **Rechenweg:** Die Menge berechnet das Frontend (`parseFormel()` in `public/modules/aufmass/aufmass.js`,
  Whitelist `0-9 , . + - * / ( )`); das Backend speichert `formel` und `menge` unverändert. Keine Geometrie,
  keine Raumhöhe, keine Öffnungen im Modell – Abzüge müssen als Formel ausgedrückt werden
  (z. B. `(4,12+3,5)*2*2,5-0,885*2,01`), was nachvollziehbar bleibt.
- **Schreibweg:** `save` (Recht `canWriteAufmass`) ersetzt Abschnitte/Positionen komplett in einer Transaktion;
  Temp-IDs < 0 für neue Abschnitte werden serverseitig gemappt. Der Editor hält den Stand in `state.current`.
- **Vorhandene Bausteine:** SheetJS (`public/lib/xlsx.full.min.js`, nur `index.html`, nicht mobil) liest
  bereits XLSX im Browser (`importMaterialExcel()` in `public/script.js`); Upload-Muster mit Größen-/MIME-Prüfung
  in `fotoOcr()`; Modul-Schalter `modul_aufmass` (Default `false`), Lizenz-Tier `professional`.
- **Tests:** Für das Aufmaß-Modul gibt es noch keine API-Tests.

### 2.2 Recherche Magicplan (Stand 2026-09-29)

| Export | maschinenlesbar? | Inhalt laut Hersteller | Eignung |
|---|---|---|---|
| Report-/Sketch-PDF, JPG, PNG, SVG | nein (Grafik) | Plan mit Maßen | nur als Anhang |
| DXF | teilweise | Geometrie; „dimensions will not be included“ | aufwendig (Polylinien → Räume rekonstruieren) |
| **Statistik-Export CSV** | **ja** | „rooms, objects, surfaces, measurements“ (Fläche, Umfang, Wohnfläche) | **bestes Dateiformat** – Spalten nicht öffentlich dokumentiert |
| **Estimate-Export XLS** | **ja** | Kostenaufstellung mit Mengen und Materialangaben | gut, wenn der Betrieb den Magicplan-Estimator nutzt |
| IFC / OBJ / USDZ | IFC ja (STEP-Text) | 3D-Modell, Räume | kein ausgereifter PHP-Parser – hoher Aufwand |
| Plan-Exchange-XML (`mp`) | ja | vollständiger Plan; Bezug über Custom-Export-Button bzw. Project-Files-API | detailliert, aber Aufbau im öffentlichen Doku-Auszug nicht beschrieben |
| ESX | – | Xactimate®-Format (US-Versicherungsschäden) | nicht relevant |
| REST-API + Webhook | ja | Webhook liefert Datei-URLs (XML, PDF, DXF, IFC …), 60 min gültig; API benötigt Report- oder PRO-Abo | Aufwand L, braucht öffentlich erreichbaren Endpunkt |

Quellen (abgerufen 2026-09-29):
- https://help.magicplan.app/export-formats
- https://help.magicplan.app/export-statistics
- https://help.magicplan.app/how-to-generate-an-api-key-in-the-magicplan-cloud
- https://apidocs.magicplan.app/guide/basic-concepts/plan-exchange-xml-format
- https://apidocs.magicplan.app/guide/advanced-integrations/custom-export-button-integration/webhook-documentation-project-updated
- https://www.magicplan.app/ (ESX/Xactimate)
- https://github.com/PHPOffice/PhpSpreadsheet/security/advisories (28 Advisories, u. a. Speicher-/CPU-DoS beim Lesen)

**Alternativen** (nicht einzeln recherchiert, daher ohne Quellen): Andere Aufmaß-Apps und Laser-Messgeräte-Apps
exportieren üblicherweise CSV/XLSX → durch Vorschlag A mit abgedeckt. Der deutsche Austauschstandard für Mengenermittlung
(REB/GAEB) wäre ein eigenes Arbeitspaket.

**Machbarkeit:** Ja. Die Zieltabellen reichen aus (Raum → Abschnitt, Maß → Formel/Menge/Einheit); **keine
Schemaänderung nötig**. Magicplan liefert Raumdaten maschinenlesbar als Statistik-CSV oder Estimate-XLS;
die genaue Spaltenstruktur ist nicht öffentlich → Beispieldatei erforderlich.

### 2.3 Vorschläge

| | Vorschlag | Nutzen | Aufwand | Risiko |
|---|---|---|---|---|
| **A** | **Generischer CSV/XLSX-Import mit Spaltenzuordnung**, Parsing im Browser (SheetJS vorhanden), Zuordnung/Validierung im Backend als Vorschau; mit Voreinstellungen „Magicplan Statistik“/„Magicplan Estimate“, sobald Beispieldateien vorliegen | hoch – jede App/Excel-Liste, auch Magicplan | **M** | niedrig: kein Server-Upload, keine neue Composer-Bibliothek, kein Schema |
| B | Magicplan Plan-XML: Räume, Wandlängen, Öffnungen → Abschnitt je Raum, Positionen Boden/Decke/Wand mit Öffnungsabzug als Formel | sehr hoch für Maler/Boden/Trockenbau, mittel für Schreiner | **L** | hoch: Format nicht öffentlich dokumentiert, XML-Sicherheit, Einheiten (m/ft), Abzugsregeln |
| C | Schreinerei-Vorlagen auf Basis von A/B: je Raum Sockelleiste lfm (Umfang − Türbreiten), Türen/Zargen Stk mit Maß, Fensterbänke Stk/lfm, Boden-/Deckenverkleidung m² | hoch für Schreiner | **S** (nach A) / **M** (mit B) | mittel: Regeln fachlich abzustimmen |
| D | Magicplan-API/Webhook (Custom-Export-Button → Aufmaß automatisch anlegen) | Komfort, kein Dateihandling | **L** | hoch: öffentlicher Endpunkt, API-Key (`SecretBox`, E-010/E-011), SSRF beim Datei-Download, Abo nötig |

**Empfehlung:** Zuerst **A** umsetzen (dieses Arbeitspaket). Danach C als Voreinstellungen auf A. B nur, wenn
Beispieldateien zeigen, dass CSV/XLS keine Öffnungsmaße enthalten; D nur auf ausdrücklichen Wunsch.

### 2.4 Ziel & Abgrenzung (Vorschlag A)

**Ziel:** Im Aufmaß-Editor eine CSV/XLSX-Datei wählen, Spalten zuordnen, Vorschau prüfen und die erkannten
Abschnitte/Positionen an den Entwurf **anhängen**; Speichern über den bestehenden `save`-Weg.

**Nicht Teil:** B, C, D; Import von Preisen (`einzelpreis`/`ek` – Preise kommen weiter aus Katalog/Lager);
Speichern von Zuordnungsprofilen in der DB (nur `localStorage`); mobile Ansichten (`mobile.html` lädt kein SheetJS);
Schemaänderung; Umbenennung der snake_case-Spalten; Server-Upload der Rohdatei.

### 2.5 Betroffene Dateien

| Datei | Grund |
|---|---|
| `modules/aufmass/backend/ImportMapper.php` (neu) | Reine Zuordnungslogik ohne DB: Zeilen + Spaltenzuordnung → Abschnittsnamen, Positionen, übersprungene Zeilen; Zahl-/Formel-Normalisierung; Limits. Einzeln unit-testbar |
| `modules/aufmass/backend/Module.php` | Neue Aktion `import_vorschau` (Recht `canWriteAufmass`), `require_once` des Mappers, keine DB-Schreibzugriffe |
| `public/modules/aufmass/aufmass.js` | Button „Import CSV/XLSX“ (nur Entwurf + `canWriteAufmass` + `XLSX` vorhanden), Dialog mit Kopfzeile/Spaltenzuordnung/Vorschau, Übernahme in `state.current` mit `_nextTmpId()`, Abschnitte gleichen Namens wiederverwenden |
| `public/modules/aufmass/aufmass.css` | Stile für Import-Dialog/Vorschautabelle |
| `public/script.js`, `public/mobile.html` | `?v=` von `modules/aufmass/aufmass.js|css` erhöhen (Cache-Busting) |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Folge von `script.js`-Änderung: minify, `?v=`, `CACHE_VERSION` |
| `public/lib/xlsx.full.min.js` | Nur falls Version < 0.20.2 (liest jetzt fremde Dateien; bekannte Lücken CVE-2023-30533, CVE-2024-22363) – eigener `sec:`-Commit |
| `modules/aufmass/module.json` | `version` 0.1.0 → 0.2.0 |
| `tests/Unit/AufmassImportMapperTest.php` (neu) | Mapper-Regeln |
| `tests/Api/AufmassImportTest.php` (neu) | Rechte, Modul-Schalter, Vorschau-Ergebnis, Limits |
| `CHANGELOG.md` | Eintrag |

### 2.6 Struktur (Vorlage copier-astral)

Keine neuen Ordner, Make-Targets, CLI-Befehle oder CI-Jobs. Tests nach Vorlage `tests/` → `tests/Unit` + `tests/Api`
(Testsuites bestehen). Modulcode bleibt nach Projektbesonderheit in `modules/aufmass/` (nicht per PSR-4 geladen →
`require_once` wie `AufmassDatabase.php`). Keine neue Composer-Abhängigkeit (Vorlage: Abhängigkeiten schlank halten).

### 2.7 Berücksichtigte Erkenntnisse

- E-001/SQL-Regeln: nicht betroffen (keine neuen Abfragen), `save` bleibt unverändert.
- E-010/E-011: nur für D relevant (API-Key).
- E-020: entfällt – keine Migration, daher keine neue Fixture.
- E-030: API-Tests mit SQLite unter Windows.
- E-050: Struktur wie oben geprüft.
- E-063: kein `@` im neuen Code (bestehender `fotoOcr()` bleibt unberührt).

### 2.8 Schritte

0. **SheetJS-Version prüfen**; bei < 0.20.2 aktualisieren und `sw.js`-Cache anheben (`sec:`-Commit). Prüfbar: Versionskommentar/`XLSX.version`.
1. **`ImportMapper`** anlegen (`final class`, vollständig typisiert): Eingabe `zeilen` (Liste von Listen aus
   string|int|float|null), `kopfzeile` (Index oder −1), `spalten` (Map Feld → Spaltenindex für `bezeichnung`*,
   `menge`, `formel`, `einheit`, `abschnitt`, `notiz`; `menge` oder `formel` Pflicht), `dateiname`.
   Regeln: Zahlen „12,5“, „1.234,5“, „1,234.5“, „12.5“ korrekt; Formel nur bei Whitelist-Treffer übernehmen
   (sonst leer), Menge aus Formel **im PHP-Parser ohne `eval`** (kleiner Rekursiv-Abstieg für `+ - * / ( )`)
   oder aus Mengenspalte; leere Zeilen still überspringen; Zeilen ohne Bezeichnung/gültige Menge → `uebersprungen`
   mit Zeilennummer und Grund; Texte trimmen und kürzen (Bezeichnung 500, Einheit 20, Abschnitt 200 Zeichen);
   Position-`notiz` = „Import: <Dateiname> Z. <n>“ (+ ggf. Notizspalte). Limits: max. 2000 Datenzeilen, 30 Spalten.
   → Unit-Test (Schritt 5) grün.
2. **Aktion `import_vorschau`** in `Module::dispatch()`: `requirePerm('canWriteAufmass')`, Body validieren
   (Typen, Limits → 400 mit escaped Meldung), Mapper aufrufen, `jsonOut(['ok'=>true,'abschnitte'=>[...],'positionen'=>[...],'uebersprungen'=>[...]])`. Kein DB-Schreiben, kein Audit.
3. **Frontend**: Button + Dialog in `aufmass.js`; Datei-Check vor dem Lesen (Endung `.csv/.xlsx/.xls`, ≤ 5 MB);
   `XLSX.read` → erstes oder gewähltes Blatt → `sheet_to_json(header:1, raw:true)`; Kopfzeilen-/Spaltenauswahl mit
   automatischem Vorschlag über Spaltennamen (Bezeichnung/Raum/Menge/Einheit/Fläche …); letzte Zuordnung in
   `localStorage`; Vorschau (erste 20 Zeilen + Liste übersprungener Zeilen); „Übernehmen“ hängt an, setzt `dirty`,
   rendert neu. Ausgabe nur über `esc()`.
4. **Cache-Busting**: `?v=` in `script.js`/`mobile.html`, `npm.cmd run minify`, `index.html`/`sw.js` anheben,
   `module.json`-Version; `node scripts/check-versions.mjs --strict` grün.
5. **Tests**: Unit (Mapper-Regeln aus Schritt 1) und API (siehe Abnahme) auf SQLite und PostgreSQL.
6. **CHANGELOG.md** ergänzen.
7. *(nach Beispieldatei)* Voreinstellungen „Magicplan Statistik (CSV)“ / „Magicplan Estimate (XLS)“ als feste
   Spaltenzuordnung im Frontend + Unit-Test mit anonymisierter Fixture unter `tests/fixtures/aufmass/`.

Commit-Reihenfolge: (0 `sec:`), 1+5-Unit `feat:`, 2+5-API `feat:`, 3+4 `feat:`, 6 `docs:`, 7 `feat:`.

### 2.9 Risiken

- **SQLite/PostgreSQL:** keine neuen SQL-Befehle; `save` unverändert → gering. Tests trotzdem auf beiden Treibern.
- **Migration/Fixture/Backup-Import:** entfällt (kein Schema). Importierte Positionen sind normale Zeilen → Backups unverändert.
- **Sicherheit Upload:** Die Rohdatei verlässt den Browser nicht → kein Pfad-/ZIP-Slip-/XXE-Risiko am Server.
  Server sieht nur JSON → Limits für Zeilen/Spalten/Zeichen gegen DoS; `post_max_size` begrenzt den Body.
  Im Browser: Größenlimit vor `XLSX.read` (ZIP-Bombe in XLSX), aktuelle SheetJS-Version. Keine Formelauswertung per
  `eval`/`Function` im Backend; Frontend-`parseFormel()` bleibt Whitelist-gesichert.
  Für spätere Server-Uploads (B): XML nur mit `LIBXML_NONET`, ohne `LIBXML_NOENT`, `<!DOCTYPE` ablehnen; ZIP-Einträge
  zählen und entpackte Größe begrenzen; `finfo`-MIME + Endung; `PhpSpreadsheet` wegen Advisory-Historie meiden.
- **Rechte:** Vorschau nur mit `canWriteAufmass`; Speichern weiterhin über `save`. Gesperrte Aufmaße: Button ausgeblendet, `save` lehnt ohnehin ab.
- **Cache-Busting/Version:** Modul-JS wird über `?v=` in `script.js` und `mobile.html` geladen – vergessen = alter Stand im Browser/Service-Worker.
- **Lizenz/Feature-Flag:** unverändert; Aktion läuft durch die bestehende Prüfung von `modul_aufmass` und Lizenz (`professional`).
- **Fachlich:** Einheiten (m vs. ft) und Dezimaltrennzeichen je Export-Sprache; CSV-Trennzeichen `;` vs. `,` (SheetJS-Erkennung mit Beispieldatei prüfen).

### 2.10 Abnahmekriterien (Vorschlag A)

1. Ohne Anmeldung → 401; ohne `canWriteAufmass` → 403; `modul_aufmass = false` → 403 für `import_vorschau`.
2. Gültige Zeilen mit Spalten Raum/Bezeichnung/Menge/Einheit → Antwort enthält je eindeutigem Raum einen Abschnittsnamen
   und je Zeile eine Position mit korrekter Menge; Kopfzeile wird nicht als Position übernommen.
3. „12,5“ → 12.5, „1.234,5“ → 1234.5, „1,234.5“ → 1234.5; Formel „2,5*4-0,885*2,01“ → Menge 8,221 (±0,001) und Formel unverändert.
4. Formel mit unerlaubten Zeichen (z. B. `alert(1)`) → Formel leer, Position nur übernommen, wenn eine gültige Mengenspalte existiert; sonst in `uebersprungen`.
5. Zeilen ohne Bezeichnung oder ohne gültige Menge erscheinen in `uebersprungen` mit Zeilennummer; leere Zeilen erscheinen nirgends.
6. > 2000 Datenzeilen oder > 30 Spalten oder fehlende Pflichtzuordnung → 400 mit verständlicher Meldung.
7. Die Aktion schreibt nichts: Anzahl Aufmaße/Positionen in der DB vor und nach dem Aufruf gleich.
8. Positionen enthalten `notiz` mit Dateiname und Zeilennummer; Texte sind auf die Maximallängen gekürzt.
9. UI: Button nur im Entwurf mit Schreibrecht; Datei > 5 MB oder falsche Endung → Meldung ohne Änderung; nach „Übernehmen“
   sind Positionen angehängt (bestehende bleiben), Abschnitte gleichen Namens werden wiederverwendet; nach Speichern und Neuladen vorhanden.
10. Bezeichnung `<img src=x onerror=alert(1)>` wird in Vorschau und Editor als Text angezeigt.
11. PHPUnit grün auf SQLite und PostgreSQL; PHPStan ohne neue Baseline-Einträge; `check-versions --strict` grün.

### 2.11 Offene Fragen

1. **Beispieldateien:** Bitte je einen anonymisierten Magicplan-Export „Statistik (CSV)“ und – falls genutzt – „Estimate (XLS)“ bereitstellen (metrisch, deutsche Oberfläche). Ohne sie entfällt Schritt 7.
2. Welches Magicplan-Abo liegt vor (API erst ab Report/PRO)? Wird der Magicplan-Estimator mit eigener Artikelliste genutzt?
3. Soll ein Import ein **neues** Aufmaß anlegen (Titel = Dateiname) oder nur an das geöffnete anhängen (Plan: anhängen)?
4. Schreinerei (C): Welche Positionen werden standardmäßig gebraucht (Sockelleiste, Zargen, Fensterbänke, Fronten …) und gelten Abzugsregeln (Öffnungen ab welcher Größe)?
5. Einverstanden, Preise beim Import auszulassen und die Zuordnung nur im Browser (`localStorage`) zu merken?

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

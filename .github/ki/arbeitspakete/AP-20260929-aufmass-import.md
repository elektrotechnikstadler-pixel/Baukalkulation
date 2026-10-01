# AP-20260929-aufmass-import: Aufmaß aus Datei-Upload (z. B. Magicplan)

| Feld | Wert |
|---|---|
| Status | zurückgestellt (Nutzer 2026-10-01; offen im CHANGELOG) |
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
die Struktur ist nicht öffentlich dokumentiert → anhand der Beispieldatei analysiert (2.2a).

### 2.2a Analyse Beispieldatei `tests/fixtures/aufmass/magicplan-statistik.csv`

UTF-8 mit BOM, Trennzeichen Komma, Dezimalpunkt, Zeilen enden meist mit überzähligem Komma (leere letzte Zelle).
Inhalt nur Raumnamen, Maße, Erstellungsdatum – keine personenbezogenen Daten → als Fixture zulässig, **unverändert lassen**.

| Überschrift (Zelle 1) | Kopfzeile | Inhalt | Verwendung |
|---|---|---|---|
| `ATTRIBUTE DES PLANS` | keine, Zeilen `Schlüssel,Wert` | Summen: Etagen 6, Räume 8, Fenster 12, Wandfläche mit/ohne Öffnungen 496.73/429.66 | Zusammenfassung, Plausibilität |
| `ATTRIBUTE DER ETAGEN` | dieselbe Zeile ab Zelle 2 | 6 Etagen: Flächen, Umfänge, Raumhöhe `2.76 m`, `Dicke der Auβenwände` (griech. **β**) `0.25 m`, Innenwände `0.12 m` | Etagenliste; Wanddicken für C |
| `ATTRIBUTE DER RÄUME` | dieselbe Zeile | Etagen-Zwischenzeilen (nur Zelle 1 gefüllt), dann Räume: Bodenfläche ohne Wände, Volumen, Boden-/Deckenumfang, Wandfläche mit/ohne Öffnungen, Türflächen, Fensteroberflächen, Raumhöhe `2.39 m` | **Quelle aller Flächen/Umfänge** |
| `WANDEIGENSCHAFTEN` | dieselbe Zeile | Etagen-Zwischenzeilen; je Zeile Raum, `Wand n`, Symbol, Oberfläche, Oberfläche ohne Öffnungen (nur Typ Wand), Breite, Höhe, Anmerkung, **Typ** (`Wand`/`Tür`/`Fenster`/leer = Möbel/Gerät), Entfernung zum Boden | **Quelle der Öffnungen** (Stück, B × H, Brüstung) |
| `ANZAHL DER OBJEKTE` | dieselbe Zeile; Spalten mischen Räume und Etagen-Zwischensummen, 17 Überschriften/18 Werte, Zeile `Fenster,Flügelfenster` doppelt | Zählmatrix | **ignorieren** (redundant, nicht eindeutig) |
| `Attribute des Objekts` (Kleinschreibung) | **nächste** Zeile, beginnt mit leerer Zelle | Kategorie, Etage, Raum, Symbol, Anmerkung, Breite, Entfernung zum Boden, Höhe, Tiefe (mit ` m`); **Leerzeilen zwischen Kategorien innerhalb des Abschnitts** | nur Option „Möbel/Geräte“ |

**Befunde (an den Zahlen nachgerechnet):**
1. **Leerzeilen sind keine Abschnittsgrenze** (Objekt-Abschnitt) → Abschnitte nur über bekannte Überschriften erkennen, Leerzeilen überspringen.
2. **Raumnamen nicht eindeutig:** „Küche“ in 5 Etagen, 2× „Badezimmer“ in `3. Untergeschoss`; im Wandabschnitt beginnt der
   zweite Raum wieder bei `Wand 0`. Zuordnung: Wandzeilen je Etage in Reihenfolge zu Rauminstanzen gruppieren – neue Instanz,
   wenn der Raumname wechselt **oder** eine Zeile mit Typ `Wand` eine Wandnummer ≤ der letzten Wandnummer der laufenden
   Instanz hat; k-te Instanz von (Etage, Name) ↔ k-te Raumzeile gleichen Namens dieser Etage.
   Probe Σ „ohne Öffnungen“: Bad 1 (Wand 0–6) 27.29, Bad 2 (Wand 0–4) 12.07, EG-Küche 43.62 = Raumwerte ✓.
3. **Raumwerte maßgeblich, Wandzeilen unvollständig:** EG-Küche Türflächen 9.48, dort gelistet nur 4.48 + 1.74 = 6.22;
   der Rest (3.26 m² an Wand 4) ist die gemeinsame `Öffnung` (1.43 m), die nur beim Wohnzimmer steht. Ebenso Bad 1 ↔ Bad 2.
   → Flächen aus `ATTRIBUTE DER RÄUME`; Öffnungs-Stückliste aus `WANDEIGENSCHAFTEN` (gemeinsame Öffnungen einmal, beim listenden Raum).
4. **Bodenumfang ist bereits um Türbreiten gekürzt** (inkl. gemeinsamer Öffnungen): EG-Küche 23.88 − 19.55 = 4.33 = 2.10 + 0.80 + 1.43;
   1. Stock 16.55 − 13.62 = 2.93 = 2.00 + 0.93. → Sockelleiste = Bodenumfang **ohne weiteren Abzug** (sonst doppelt).
5. **Klassifizierung über `Typ`, nicht `Symbol`** (Symbolnamen katalog-/sprachabhängig). Symbol `Öffnung` mit Typ `Tür` = Durchgang
   ohne Türblatt (für C: keine Zarge). Wandmontierte Möbel/Geräte (Typ leer) mindern die Wandfläche nicht.
6. **Zahlen:** Dezimalpunkt, teils Suffix ` m`; leere Zelle = kein Wert; Einheiten in der Kopfzeile (`: m²`, `: m³`, `: m`).
   Andere Exporte (`;`, Dezimalkomma, `ft`) sind nicht belegt.
7. **Überschriften/Spaltennamen normalisieren:** BOM entfernen, trimmen, `β`→`ß`, Groß-/Kleinschreibung ignorieren, Einheitensuffix abtrennen;
   Spalten über Namen statt Position zuordnen.
8. **Rundung:** 57.11 − 9.48 − 4.02 = 43.61 ≠ 43.62 (Magicplan rundet je Wert) → Abweichungen ≤ 0,02 tolerieren.

**Sollwerte der Fixture** (Grundlage der Abnahme 2.10):

| Etage / Raum | Wände | Boden m² | Wand ohne Öffn. m² | Bodenumfang m | Türen | Durchgänge | Fenster (B × H, Brüstung) |
|---|---|---|---|---|---|---|---|
| Erdgeschoss / Küche | 6 | 32.76 | 43.62 | 19.55 | 2 (2.10×2.13, 0.80×2.16) | 0 | 2 (2.07×0.99 / 1.08; 1.49×1.32 / 0.84) |
| Erdgeschoss / Wohnzimmer | 6 | 14.29 | 28.07 | 14.59 | 0 | 1 (1.43×2.08) | 4 (1.70×1.25, 1.62×1.25, 0.77×1.35, 1.89×1.08) |
| 1. Stock / Küche | 4 | 17.11 | 33.68 | 13.62 | 1 (0.93×2.17) | 1 (2.00×2.14) | 1 (2.35×1.16) |
| 2. Stock / Küche | 9 | 41.04 | 95.93 | 26.40 | 2 | 0 | 2 |
| 3. Stock / Küche | 6 | 12.84 | 32.14 | 13.62 | 1 | 0 | 0 |
| 4. Stock / Küche | 17 | 52.90 | 156.85 | 33.07 | 4 | 3 | 2 |
| 3. Untergeschoss / Badezimmer (1.) | 7 | 8.62 | 27.29 | 11.43 | 0 | 1 (0.84×2.00) | 1 (0.91×1.05) |
| 3. Untergeschoss / Badezimmer (2.) | 5 | 2.97 | 12.07 | 4.50 | 2 (0.80×2.00, 0.75×1.95) | 0 | 0 |
| **Summe** | 60 | | | | **12** | **6** | **12** (= Planwert) |

Wandabschnitt zusätzlich: 7 Möbel/Geräte-Zeilen (Typ leer). Objekt-Abschnitt: 51 Möbel + 6 Elektrogeräte = 57 (dort Türen/Fenster doppelt → ignorieren).

### 2.3 Vorschläge

| | Vorschlag | Nutzen | Aufwand | Risiko |
|---|---|---|---|---|
| **A** | **Import mit Vorschau** – (A1) **Magicplan-Statistik-Parser** im Backend (Voreinstellung, Abschnittserkennung, Etage→Raum-Kontext, Wandzeilen-Zuordnung) und (A2) **generischer CSV/XLSX-Import mit Spaltenzuordnung** (Parsing im Browser per SheetJS, Zuordnung im Backend) | hoch – Magicplan direkt, jede andere App/Excel-Liste über A2 | **M** | niedrig: Rohdatei wird nicht gespeichert, keine neue Composer-Bibliothek, kein Schema |
| B | Magicplan Plan-XML: Räume, Wandlängen, Öffnungen → Abschnitt je Raum, Positionen Boden/Decke/Wand mit Öffnungsabzug als Formel | sehr hoch für Maler/Boden/Trockenbau, mittel für Schreiner | **L** | hoch: Format nicht öffentlich dokumentiert, XML-Sicherheit, Einheiten (m/ft), Abzugsregeln |
| C | Schreinerei-Vorlagen auf Basis von A/B: je Raum Sockelleiste lfm (Umfang − Türbreiten), Türen/Zargen Stk mit Maß, Fensterbänke Stk/lfm, Boden-/Deckenverkleidung m² | hoch für Schreiner | **S** (nach A) / **M** (mit B) | mittel: Regeln fachlich abzustimmen |
| D | Magicplan-API/Webhook (Custom-Export-Button → Aufmaß automatisch anlegen) | Komfort, kein Dateihandling | **L** | hoch: öffentlicher Endpunkt, API-Key (`SecretBox`, E-010/E-011), SSRF beim Datei-Download, Abo nötig |

**Empfehlung:** Zuerst **A** umsetzen (dieses Arbeitspaket). Danach C als Voreinstellungen auf A. B entfällt vorerst:
die Statistik-CSV enthält Öffnungsmaße (B × H, Brüstung) je Raum; D nur auf ausdrücklichen Wunsch.

### 2.3a Entscheidung: Magicplan-Parser im Backend (PHP)

| Kriterium | Browser (JS) | Backend (PHP) – **gewählt** |
|---|---|---|
| Testbarkeit mit Fixture | keine JS-Tests im Projekt → Parser ungetestet | PHPUnit-Unit-Test direkt gegen die Fixture + API-Test |
| Sicherheit | Datei bleibt lokal | Nur CSV-**Text** als JSON-Feld, im Speicher verarbeitet, nicht gespeichert/geloggt; Limits 1 MB / 20 000 Zeilen, UTF-8-Pflicht; kein `multipart`, keine Temp-Datei am Server |
| Abhängigkeiten | keine (Text) | keine (`fgetcsv` im PHP-Kern) |
| Wiederverwendung | nur Editor | C-Regeln im selben Service; später API/Webhook (D) nutzbar |

Passt zum bisherigen Plan: `import_vorschau` war bereits eine Backend-Vorschau ohne DB-Schreiben; neu ist das Feld
`format` (`magicplan_statistik` | `tabelle`). Speichern bleibt beim bestehenden `save`. Der generische Weg (A2) bleibt
wie geplant (SheetJS im Browser → Zeilen → `ImportMapper`), weil XLSX binär ist und SheetJS vorhanden ist.
Die Abgrenzung „kein Server-Upload der Rohdatei“ wird präzisiert: Der CSV-Text wird übertragen, aber nicht abgelegt.

### 2.3b Mapping Magicplan → Aufmaß (A1)

**Abschnitt je Raum:** `<Etage> – <Raum>` in Export-Reihenfolge; ab der zweiten gleichnamigen Instanz einer Etage
Suffix ` (2)`, ` (3)` … – nötig, weil der Editor gleichnamige Abschnitte beim Anhängen zusammenführt (Schritt 5).

**Positionen je Raum** (Zahlen in Formel/Bezeichnung mit Dezimalkomma wie im Editor; Menge = Formelergebnis, 3 Nachkommastellen):

| Nr | Bezeichnung (Beispiel EG-Küche) | Einheit | Formel → Menge | Standard |
|---|---|---|---|---|
| 1 | Bodenfläche | m² | `32,76` → 32.76 | an |
| 2 | Deckenfläche (= Bodenfläche) | m² | `32,76` → 32.76 | an |
| 3 | Wandfläche netto (ohne Öffnungen) | m² | `43,62` → 43.62 | an (Option `wand = netto`) |
| 3′ | Wandfläche brutto abzgl. Türen/Fenster | m² | `57,11-9,48-4,02` → 43.61 | statt 3 bei `wand = brutto` |
| 4 | Bodenumfang (Türbreiten bereits abgezogen) | lfm | `19,55` → 19.55 | an – **kein** weiterer Türabzug (Befund 4) |
| 5 | Tür <Symbol> B × H (z. B. „Tür Klapptür 0,80 × 2,16 m“) | Stk. | `1` | an (Option `oeffnungen`) |
| 6 | Durchgang B × H („Durchgang 1,43 × 2,08 m“) | Stk. | `1` | an (Option `oeffnungen`) |
| 7 | Fenster <Symbol> B × H, Brüstung („Fenster Flügelfenster 2,07 × 0,99 m, Brüstung 1,08 m“) | Stk. | `1` | an (Option `oeffnungen`) |
| 8 | Möbel/Gerät <Symbol> B × H × T | Stk. | `1` | **aus** (Option `moebel`); Quelle Objekt-Abschnitt, Zuordnung Etage + Raum, bei mehrdeutigem Namen erste Instanz + Hinweis |

Je Öffnung eine Position (kein Zusammenfassen gleicher Maße – Einzelmaße sind für Schreiner nötig).
Notiz je Position: `Import <Dateiname>: <Etage>/<Raum>`; bei Pos. 3/3′ zusätzlich `Raumhöhe 2,39 m`.
Preise bleiben leer; `ausgewaehlt` wie beim manuellen Anlegen.

**C (nach A, Option `schreinerei`, ergänzt je Raum):**
- Sockelleiste lfm = Bodenumfang (ersetzt Bezeichnung von Pos. 4).
- Türzarge je **Tür** (nicht Durchgang) Stk. mit lichtem Maß B × H; Notiz „Wanddicke Etage: innen 0,12 m / außen 0,25 m“.
- Fensterbank je Fenster Stk., Länge = Fensterbreite (Formel `2,07`; Überstand → offene Frage).
- Laibung je Fenster/Durchgang lfm = `2*H+B` (z. B. `2*0,99+2,07` → 4.05).

### 2.4 Ziel & Abgrenzung (Vorschlag A)

**Ziel:** Im Aufmaß-Editor eine Datei wählen; eine Magicplan-Statistik-CSV wird automatisch erkannt (erste Zeile
`ATTRIBUTE DES PLANS`) und serverseitig in Räume/Öffnungen zerlegt (A1), andere CSV/XLSX-Dateien über Spaltenzuordnung (A2).
Vorschau mit Optionen prüfen, Abschnitte/Positionen an den Entwurf **anhängen**; Speichern über den bestehenden `save`-Weg.

**Nicht Teil:** B, D; C erst nach Abnahme von A (Schritt 9, eigener Commit); Magicplan-Estimate-XLS (keine Beispieldatei);
Abschnitt `ANZAHL DER OBJEKTE`; Import von Preisen (`einzelpreis`/`ek`); Speichern von Zuordnungsprofilen in der DB
(nur `localStorage`); mobile Ansichten; Schemaänderung; Umbenennung der snake_case-Spalten; Ablage der Rohdatei am Server;
Imperiale Einheiten (`ft`) und nicht belegte Sprachvarianten (werden mit klarer Meldung abgelehnt bzw. als Hinweis gemeldet).

### 2.5 Betroffene Dateien

| Datei | Grund |
|---|---|
| `modules/aufmass/backend/ImportMapper.php` (neu) | A2: reine Zuordnungslogik ohne DB: Zeilen + Spaltenzuordnung → Abschnittsnamen, Positionen, übersprungene Zeilen; Zahl-/Formel-Normalisierung (`zahl()` öffentlich, von A1 mitgenutzt); Limits |
| `modules/aufmass/backend/MagicplanStatistikParser.php` (neu) | A1: reine Funktion Text → Struktur (Plan, Etagen, Räume mit Wänden/Öffnungen, Objekte, Hinweise); keine DB, keine Dateizugriffe |
| `modules/aufmass/backend/MagicplanAufmassMapper.php` (neu) | A1/C: Struktur + Optionen → Abschnitte/Positionen im selben Ausgabeformat wie `ImportMapper` (Mapping 2.3b) |
| `modules/aufmass/backend/Module.php` | Neue Aktion `import_vorschau` (Recht `canWriteAufmass`) mit `format`, `require_once` der drei Klassen, keine DB-Schreibzugriffe |
| `public/modules/aufmass/aufmass.js` | Button „Import“ (nur Entwurf + `canWriteAufmass`), Magicplan-Erkennung per `FileReader.readAsText`, Optionen-Checkboxen, A2-Dialog (nur wenn `XLSX` vorhanden), Vorschau, Übernahme in `state.current` mit `_nextTmpId()`, Abschnitte gleichen Namens wiederverwenden |
| `public/modules/aufmass/aufmass.css` | Stile für Import-Dialog/Vorschautabelle |
| `public/script.js`, `public/mobile.html` | `?v=` von `modules/aufmass/aufmass.js|css` erhöhen (Cache-Busting) |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Folge von `script.js`-Änderung: minify, `?v=`, `CACHE_VERSION` |
| `public/lib/xlsx.full.min.js` | Nur falls Version < 0.20.2 (liest jetzt fremde Dateien; bekannte Lücken CVE-2023-30533, CVE-2024-22363) – eigener `sec:`-Commit |
| `modules/aufmass/module.json` | `version` 0.1.0 → 0.2.0 |
| `tests/Unit/AufmassImportMapperTest.php` (neu) | A2-Regeln |
| `tests/Unit/AufmassMagicplanParserTest.php` (neu) | Parser gegen Fixture (Sollwerte 2.2a) + synthetische Randfälle als Inline-Strings |
| `tests/Unit/AufmassMagicplanMapperTest.php` (neu) | Abschnitte/Positionen/Optionen gegen Fixture |
| `tests/Api/AufmassImportTest.php` (neu) | Rechte, Modul-Schalter, beide Formate, Limits, kein DB-Schreiben |
| `tests/fixtures/aufmass/magicplan-statistik.csv` | vorhanden, nur lesen |
| `CHANGELOG.md` | Eintrag |

### 2.6 Struktur (Vorlage copier-astral)

Keine neuen Ordner, Make-Targets, CLI-Befehle oder CI-Jobs. Tests nach Vorlage `tests/` → `tests/Unit` + `tests/Api`
(Testsuites bestehen). Modulcode bleibt nach Projektbesonderheit in `modules/aufmass/` (nicht per PSR-4 geladen →
`require_once` wie `AufmassDatabase.php`; Unit-Tests binden die Klassen ebenso per `require_once` ein). Fixture unter
`tests/fixtures/<bereich>/` wie vorhandene Fixtures. Keine neue Composer-Abhängigkeit (Vorlage: Abhängigkeiten schlank halten).

### 2.7 Berücksichtigte Erkenntnisse

- E-001/SQL-Regeln: nicht betroffen (keine neuen Abfragen), `save` bleibt unverändert.
- E-010/E-011: nur für D relevant (API-Key).
- E-020: entfällt – keine Migration, daher keine neue Fixture.
- E-030: API-Tests mit SQLite unter Windows.
- E-050: Struktur wie oben geprüft.
- E-063: kein `@` im neuen Code (bestehender `fotoOcr()` bleibt unberührt). Der globale Error-Handler in `api.php` wirft
  auch bei Deprecations → `fgetcsv()` immer mit explizitem `escape: ''` aufrufen (PHP 8.4 meldet sonst den Default als veraltet).
- E-070: neue Testdateien in Abschnitt 3/4 aufführen.

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
   `zahl()` und die Formelauswertung als `public static` anbieten – A1 nutzt sie mit (kein zweiter Zahlen-/Formelparser).
   → Unit-Test (Abnahme 2–6, 8) grün.
2. **`MagicplanStatistikParser::parse(string $text): array`** (`final class`, vollständig typisiert, ohne Zustand):
   - Vorprüfung: `strlen` ≤ 1 MB, gültiges UTF-8 (`preg_match('//u')`), BOM entfernen, CRLF/LF; erste nicht leere Zeile muss
     `ATTRIBUTE DES PLANS` sein und `ATTRIBUTE DER RÄUME` muss vorkommen – sonst `\InvalidArgumentException` mit Nutzertext.
   - Einlesen über `php://temp` + `fgetcsv($fh, 0, $trenner, '"', '')` (Anführungszeichen mit Komma/Zeilenumbruch in `Anmerkung`
     bleiben eine Zelle), max. 20 000 Zeilen; Trennzeichen `,`, alternativ `;`, wenn die Zeile `ATTRIBUTE DER RÄUME` mehr `;` als `,` enthält.
   - Zustandsautomat über normalisierte Überschriften (Befund 7); Leerzeilen überspringen; unbekannte Abschnitte ignorieren.
   - Spaltenzuordnung über normalisierte Kopfnamen; Pflichtspalten der Räume (Bodenfläche ohne Wände, Bodenumfang, Deckenumfang,
     Wandfläche mit/ohne Öffnungen, Türflächen, Fensteroberflächen, Raumhöhe) und Wände (Wand, Symbol, Breite, Höhe, Typ, Oberfläche ohne Öffnungen)
     fehlen → Exception; Einheit `ft` in Kopf/Wert → Exception „nur metrische Exporte“.
   - Etagen-Zwischenzeile = nur Zelle 1 gefüllt; Zahlen über `ImportMapper::zahl()` nach Abtrennen von ` m`/` m²`.
   - Wandzeilen → Rauminstanzen nach Befund 2; Öffnungsart aus `Typ` (`Tür` + Symbol `Öffnung` → `durchgang`); Typ leer → verworfen.
   - Plausibilität als `hinweise[]` (kein Abbruch): Σ Wand „ohne Öffnungen“ je Raum vs. Raumwert (Toleranz 0,05), Wandgruppe ohne
     passenden Raum, Raumanzahl ≠ Planwert `Räume`, Fensteranzahl ≠ Planwert `Fenster`.
   - Ausgabe: `plan` (Schlüssel → Wert), `etagen[]`, `raeume[]` (etage, name, instanz, Flächen/Umfänge/Höhe, `waende[]`,
     `oeffnungen[]` mit art/symbol/breite/hoehe/bruestung/wand), `objekte[]`, `hinweise[]`.
   → Unit-Test gegen Fixture (Abnahme M1–M5, M10–M11) grün.
3. **`MagicplanAufmassMapper::abbilden(array $plan, array $optionen, string $dateiname): array`** nach 2.3b; Optionen mit
   Defaults `wand = netto`, `decke`, `sockel`, `oeffnungen` = an, `moebel` = aus; unbekannte Optionen ignorieren.
   Ausgabe wie `ImportMapper` plus `hinweise` und `zusammenfassung` (etagen, raeume, tueren, durchgaenge, fenster).
   → Unit-Test (M6–M9) grün.
4. **Aktion `import_vorschau`** in `Module::dispatch()`: `requirePerm('canWriteAufmass')`; `format` Pflicht
   (`magicplan_statistik` → Felder `text` (string ≤ 1 MB), `dateiname`, `optionen`; `tabelle` → Felder aus Schritt 1);
   Typfehler/Limits/Exceptions → 400 mit escaped Meldung; Ergebnis per `jsonOut(['ok'=>true, ...])`. Kein DB-Schreiben,
   kein Audit, Text nicht loggen.
5. **Frontend** (`aufmass.js`): Button „Import“; Datei-Check vor dem Lesen (Endung `.csv/.xlsx/.xls`; CSV ≤ 1 MB, XLSX ≤ 5 MB).
   CSV per `FileReader.readAsText(file, 'utf-8')`; erste Zeile (ohne BOM) `ATTRIBUTE DES PLANS` → Magicplan-Dialog: Optionen
   (Wandfläche netto/brutto, Decke, Sockel, Türen/Fenster, Möbel), Vorschau je Abschnitt mit Zusammenfassung und Hinweisen,
   Optionsänderung ruft Vorschau erneut ab. Sonst A2-Dialog (nur mit `XLSX`): Blatt/Kopfzeile/Spaltenzuordnung mit Vorschlag
   über Spaltennamen, letzte Zuordnung in `localStorage`. „Übernehmen“ hängt an, verwendet gleichnamige Abschnitte wieder,
   setzt `dirty`, rendert neu. Ausgabe nur über `esc()`.
6. **Cache-Busting**: `?v=` in `script.js`/`mobile.html`, `npm.cmd run minify`, `index.html`/`sw.js` anheben,
   `module.json`-Version; `node scripts/check-versions.mjs --strict` grün.
7. **Tests**: Unit (Schritte 1–3) und API (Abnahme 1–8, M12) auf SQLite und PostgreSQL.
8. **CHANGELOG.md** ergänzen.
9. **C – Schreinerei** (nach Abnahme A): Option `schreinerei` im Mapper nach 2.3b, Checkbox im Dialog, Unit-Test (C1–C3).

Commit-Reihenfolge: (0 `sec:`), 1 `feat:` (+Unit), 2 `feat:` (+Unit), 3 `feat:` (+Unit), 4 `feat:` (+API), 5+6 `feat:`, 8 `docs:`, 9 `feat:`.

### 2.9 Risiken

- **SQLite/PostgreSQL:** keine neuen SQL-Befehle; `save` unverändert → gering. Tests trotzdem auf beiden Treibern.
- **Migration/Fixture/Backup-Import:** entfällt (kein Schema). Importierte Positionen sind normale Zeilen → Backups unverändert.
- **Sicherheit Upload:** XLSX verlässt den Browser nicht; von Magicplan-CSV geht nur Text im JSON-Body an den Server,
  wird im Speicher geparst und nicht abgelegt → kein Pfad-/ZIP-Slip-/XXE-Risiko. Limits (1 MB Text, 20 000 Zeilen,
  2000 Tabellenzeilen, 30 Spalten, Feldlängen) gegen DoS; `post_max_size` begrenzt zusätzlich. `fgetcsv` statt eigener
  Regex-Zerlegung (kein Backtracking-Risiko). Texte aus der Datei (Raum-/Symbolnamen) nur escaped ausgeben.
  Im Browser: Größenlimit vor `XLSX.read` (ZIP-Bombe in XLSX), aktuelle SheetJS-Version. Keine Formelauswertung per
  `eval`/`Function` im Backend; Frontend-`parseFormel()` bleibt Whitelist-gesichert.
  Für spätere Server-Uploads (B): XML nur mit `LIBXML_NONET`, ohne `LIBXML_NOENT`, `<!DOCTYPE` ablehnen; ZIP-Einträge
  zählen und entpackte Größe begrenzen; `finfo`-MIME + Endung; `PhpSpreadsheet` wegen Advisory-Historie meiden.
- **Rechte:** Vorschau nur mit `canWriteAufmass`; Speichern weiterhin über `save`. Gesperrte Aufmaße: Button ausgeblendet, `save` lehnt ohnehin ab.
- **Cache-Busting/Version:** Modul-JS wird über `?v=` in `script.js` und `mobile.html` geladen – vergessen = alter Stand im Browser/Service-Worker.
- **Lizenz/Feature-Flag:** unverändert; Aktion läuft durch die bestehende Prüfung von `modul_aufmass` und Lizenz (`professional`).
- **Fachlich:** Einheiten (m vs. ft) und Dezimaltrennzeichen je Export-Sprache; CSV-Trennzeichen `;` vs. `,` (SheetJS-Erkennung für A2).
- **Formatänderung Magicplan:** Kopfnamen/Überschriften können sich mit App-Versionen ändern → Zuordnung über Namen,
  klare Fehlermeldung bei fehlender Pflichtspalte, Fixture fixiert den heutigen Stand.
- **Zuordnung gleichnamiger Räume** beruht auf Reihenfolge + Wandnummer-Neustart (Befund 2) → Plausibilitätshinweis bei
  Σ-Abweichung macht Fehlzuordnungen sichtbar statt still falsche Mengen zu liefern.
- **Doppelte Abzüge:** Bodenumfang ist bereits türbereinigt (Befund 4); Türflächen enthalten gemeinsame Öffnungen (Befund 3) –
  Mapper darf nichts nachträglich abziehen.

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

**Magicplan-Statistik (A1) – Sollwerte aus der Fixture (Tabelle 2.2a):**

- **M1** Parser liefert 6 Etagen in Reihenfolge (Erdgeschoss, 1.–4. Stock, 3. Untergeschoss), 8 Räume, `plan` Etagen 6 / Räume 8 / Fenster 12;
  Raumhöhe EG-Küche 2.39 als Zahl; `hinweise` leer.
- **M2** EG/Küche: Boden 32.76, Volumen 78.35, Bodenumfang 19.55, Deckenumfang 23.88, Wand mit 57.11 / ohne 43.62, Türflächen 9.48,
  Fensterflächen 4.02; 6 Wände; Türen 2 (2.10×2.13, 0.80×2.16), Fenster 2 (2.07×0.99 Brüstung 1.08; 1.49×1.32 Brüstung 0.84); kein Möbel als Öffnung.
- **M3** EG/Wohnzimmer: genau 4 Fenster (1.70×1.25, 1.62×1.25, 0.77×1.35, 1.89×1.08), 1 Durchgang 1.43×2.08, 0 Türen.
- **M4** 3. Untergeschoss: zwei getrennte Räume „Badezimmer“ – 1.: 7 Wände, Boden 8.62, 1 Fenster 0.91×1.05, 1 Durchgang 0.84×2.00, 0 Türen;
  2.: 5 Wände, Boden 2.97, 2 Türen (0.80×2.00, 0.75×1.95), 0 Fenster; Σ Wand ohne Öffnungen 27.29 bzw. 12.07.
- **M5** Summen über alle Räume: Fenster 12, Türen 12, Durchgänge 6, Wände 60; 4. Stock/Küche 17 Wände, 4 Türen, 3 Durchgänge, 2 Fenster.
- **M6** Mapper (Standardoptionen): 8 Abschnitte `Erdgeschoss – Küche`, `Erdgeschoss – Wohnzimmer`, `1. Stock – Küche` … `3. Untergeschoss – Badezimmer`,
  `3. Untergeschoss – Badezimmer (2)`; 62 Positionen (8 × 4 Flächen/Umfang + 30 Öffnungen); EG-Küche: Bodenfläche 32.76 m², Deckenfläche 32.76 m²,
  Wandfläche netto 43.62 m², Bodenumfang 19.55 lfm, 2 Tür- und 2 Fensterpositionen à 1 Stk. mit Maßen in der Bezeichnung.
- **M7** `wand = brutto`: EG-Küche Formel `57,11-9,48-4,02`, Menge 43.61 (±0,001).
- **M8** Bodenumfang wird nicht erneut um Türbreiten gekürzt (EG-Küche 19.55, nicht 16.65).
- **M9** Möbel: Standard keine Möbel-/Geräte-Position; `moebel = an` → 57 zusätzliche Positionen; `oeffnungen = aus` → keine Stk.-Positionen für Türen/Fenster.
- **M10** Robustheit (synthetische Inline-Texte): ohne BOM, CRLF, `Auβenwände`/`Außenwände`, `Attribute des Objekts`/`ATTRIBUTE DES OBJEKTS` gleich erkannt;
  Anmerkung `"Nische, 2 m"` oder mit Zeilenumbruch verschiebt keine Spalten; `ANZAHL DER OBJEKTE` beeinflusst das Ergebnis nicht.
- **M11** Fehler/Hinweise: Text ohne `ATTRIBUTE DES PLANS`/`ATTRIBUTE DER RÄUME`, ungültiges UTF-8, > 1 MB, fehlende Pflichtspalte, Einheit `ft`
  → Exception bzw. 400 mit verständlicher Meldung; manipulierte Wandfläche eines Raums → Hinweis zur Σ-Abweichung, Ergebnis trotzdem geliefert.
- **M12** API: `import_vorschau` mit `format = magicplan_statistik` und Fixture-Text → 200, `zusammenfassung` {etagen 6, raeume 8, tueren 12,
  durchgaenge 6, fenster 12}, 62 Positionen; Rechte/Modul-Schalter wie Kriterium 1; DB unverändert (Kriterium 7); unbekanntes `format` → 400.
- **M13** UI: Fixture wählen → Magicplan-Dialog ohne Spaltenzuordnung, 8 Abschnitte in der Vorschau; „Übernehmen“ legt 8 Abschnitte an
  (beide Badezimmer getrennt), bestehende Positionen bleiben; nach Speichern und Neuladen vorhanden.

**C (Schreinerei, nach A):**
- **C1** EG-Küche mit `schreinerei`: Sockelleiste 19.55 lfm; Türzargen 2 (2,10 × 2,13; 0,80 × 2,16); EG-Wohnzimmer: keine Zarge für den Durchgang.
- **C2** Fensterbänke EG-Küche 2 Stk. mit Längen 2,07 und 1,49; Laibung Fenster 2,07 × 0,99 → Formel `2*0,99+2,07`, Menge 4.05.
- **C3** Ohne Option `schreinerei` bleibt das Ergebnis identisch zu M6.

### 2.11 Offene Fragen

Erledigt durch Beispieldatei/Freigabe: Statistik-CSV liegt vor; Import **hängt an** das geöffnete Aufmaß an; Preise werden
nicht importiert; A2-Zuordnung nur in `localStorage`; Estimate-XLS entfällt bis eine Beispieldatei vorliegt.

Noch offen (Vorschlag des Architekten in Klammern, gilt ohne Rückmeldung):
1. Wandfläche standardmäßig **netto** (Magicplan-Wert) oder brutto mit Abzugsformel? (netto; brutto per Option)
2. C: Fensterbank-Länge mit Überstand je Seite (z. B. + 2 × 0,05 m)? (ohne Überstand, im Editor anpassbar)
3. C: Zarge auch für Glasschiebetüren? (ja, jede Tür außer Durchgang)
4. Möbel-Option: alle Objekte aus dem Objekt-Abschnitt oder nur wandmontierte? (alle, standardmäßig aus)

**Freigabe G1:** ☑ durch Nutzer am 2026-09-29 – Vorschlag A, danach C. Beispieldatei (Magicplan-Statistik-CSV)
liegt unter `tests/fixtures/aufmass/magicplan-statistik.csv`; Plan vor der Umsetzung daran anpassen (mehrteiliger Report
mit Abschnitten wie „ATTRIBUTE DER RÄUME“, „WANDEIGENSCHAFTEN“). Übrige Fragen: Vorschlag des Architekten.

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

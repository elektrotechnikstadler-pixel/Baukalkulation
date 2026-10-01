# AP-20260929-branchen: Weitere Branchen bei der Erstinstallation

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

„Für die Erstinstallation zu Metallbau, Elektrotechnik zusätzlich Schreinerei, Zimmerei und
Heizung/Sanitär hinzufügen.“

## 2. Plan

### 2.1 Ist-Analyse (Mechanismus heute)

- **Nur Frontend.** `maybeAskBrancheOnboarding()` in `public/script.js` (≈ Z. 12083) läuft nach
  dem Login, wenn `currentRole === 'admin'` und `appSettings.branche_initialized` falsch ist.
  Abfrage per `prompt()` mit Freitext `"metallbau"`/`"elektrotechnik"`, Whitelist hart codiert.
- **Wirkung:** Speichert per `save_settings` `branche`, `branche_initialized = true` und vier
  Modul-Schalter:
  - Metallbau: `modul_metall_erfassung` und `modul_din1090` ein, `modul_datanorm` und `modul_kupfer_del` aus.
  - Elektrotechnik: umgekehrt.
  - `modul_aufmass`, `modul_lager` und `modul_vde0100` bleiben unverändert.
- **Keine Kataloge, Texte, Belegvorlagen oder Einheiten:** Stunden-, Material- und
  Pauschalkatalog (`stunden_katalog`, `material_katalog`, `pauschalen`) werden nirgends
  vorbefüllt. Die Tabellen werden nur über den Voll-Save in `DataService::saveAllData()` beschrieben.
  `Materialbibliothek_Uebersicht.csv` ist eine Stahlprofil-Bibliothek (Inventor/AISC) und hat
  keinen Bezug zur Branchenwahl. `install.sh`, `install.ps1`, `migrate.php` und `AuthActions::setup()`
  kennen keine Branche.
- **Backend:**
  - `Auth::loadSettings()` enthält die Defaults `branche => ''` und `branche_initialized => false`.
  - `AdminActions::saveSettingsAction()` übernimmt `branche` als beliebigen String ohne Whitelist.
- **Einstellungen:** Select „Standard-Branche“ (≈ Z. 10758) mit zwei Optionen und Checkbox
  „Branchenauswahl wurde initial durchgeführt“. Eine Änderung dort setzt **keine** Module.
  Hinweis: Dem Block fehlt das öffnende `<div>`, es gibt ein überzähliges `</div>` (Layoutfehler).
- **Mehrfachauswahl:** nicht möglich (`branche` ist ein einzelner String).
- **mobile.html:** kennt keine Branche.

### 2.2 Ziel & Abgrenzung

**Ziel:** Bei der Erstinstallation stehen zusätzlich **Schreinerei**, **Zimmerei** und
**Heizung/Sanitär** zur Wahl. Jede Branche setzt passende Modul-Voreinstellungen wie die bestehenden.

- **Teil A (Pflicht, klein):** drei Branchen in Onboarding und Einstellungen samt Modul-Presets.
- **Teil B (optional, nur nach Freigabe von Frage F1):** Beim Onboarding auf Wunsch
  **Startkataloge** (Stunden, Material, Pauschalen) für die gewählte Branche einspielen. Das
  geschieht nur in leere Tabellen.

**Nicht Teil:**
- Mehrfachauswahl von Branchen (siehe F3).
- Neue Spalte „Materialgruppe“. Das Schema hat keine; dafür wäre eine Migration nötig.
- Branchen-Texte und Belegvorlagen.
- Startdaten für Metallbau/Elektrotechnik (siehe F4).
- Umbau des `prompt()` zu einem Dialog.
- Vorlagen/Setup in `install.sh`/`.ps1`.
- Änderungen an `mobile.html`.
- Serverseitige Whitelist für `branche` in `save_settings`.

### 2.3 Betroffene Dateien

| Datei | Teil | Grund |
|---|---|---|
| `public/script.js` | A (+B) | Zentrale Konstante `BRANCHEN` (id, Label, Modul-Preset) für Onboarding und Einstellungs-Select. Onboarding nimmt Nummer oder id an. B: Rückfrage „Startkataloge übernehmen?“, Aufruf von `branche_startdaten`, danach `loadData()`. |
| `public/script.min.js` | A | generiert (`npm.cmd run minify`) |
| `public/index.html`, `public/sw.js` | A | `?v=` und `CACHE_VERSION` erhöhen |
| `VERSION` (+ `npm.cmd run version:sync`), `CHANGELOG.md` | A | Minor-Release (feat) |
| `src/Services/BranchenStartdaten.php` (neu) | B | Lädt und prüft die Startdaten. Spielt sie in einer Transaktion nur in leere Tabellen ein, danach `DataService::bumpRev()`. |
| `src/Services/Branchen/{schreinerei,zimmerei,heizung_sanitaer}.json` (neu) | B | Startdaten als UTF-8-JSON ohne BOM. Unter `src/` per `.htaccess` nicht öffentlich. |
| `src/Handlers/CatalogActions.php` | B | Neue Aktion `brancheStartdaten()` mit `Auth::requireRole('admin')`, nur POST |
| `public/api.php` | B | Route `'branche_startdaten' => ['App\\Handlers\\CatalogActions', 'brancheStartdaten']` |
| `tests/Unit/BranchenStartdatenTest.php` (neu) | B | Gültigkeit, Schema und UTF-8 der JSON-Dateien |
| `tests/Api/BranchenStartdatenTest.php` (neu) | B | Verhalten der Aktion (SQLite + PostgreSQL) |

**Keine Migration, keine neue Backup-Fixture:** Das Schema bleibt unverändert, Einstellungen
liegen im bestehenden JSON in `settings.data`.

### 2.4 Struktur (Vorlage copier-astral)

- Die Vorlage legt Paketdaten neben dem Code in `src/<paket>/` ab. Entsprechend liegen die
  Startdaten in `src/Services/Branchen/*.json` neben dem Service. Es gibt kein neues
  Top-Level-Verzeichnis und kein Root-Skript.
- Tests folgen der Aufteilung `tests/Unit` und `tests/Api`. Es gibt keine neuen Make-Targets,
  CI-Jobs oder CLI-Befehle.
- Die Doku braucht nur einen CHANGELOG-Eintrag. `docs/` bleibt unverändert, weil die
  Branchenwahl dort bisher nicht beschrieben ist.
- Kein `deploy/`-Spiegel mehr; Auslieferung über GitHub-Release (E-077).

### 2.5 Berücksichtigte Erkenntnisse

- **E-020:** Es gibt keine Migration, daher keine neue Fixture. Das wird im Review ausdrücklich bestätigt.
- **E-030:** Die API-Tests unter Windows räumen SQLite-Dateien erst nach `gc_collect_cycles()` auf.
- **E-050:** Die Struktur richtet sich nach der Vorlage (siehe 2.4).
- **E-060 / E-061:** `php` wird über den absoluten Pfad aufgerufen, `npm.cmd run minify` und `npm.cmd run version:sync` unter Windows.
- **E-063:** Kein `@`. Beim JSON-Laden `json_decode(..., flags: JSON_THROW_ON_ERROR)` und `is_file()` prüfen.
- **SQL-Regeln aus `docs/entwicklung.md`:** Es gibt nur einfache `INSERT` mit Prepared Statements und
  `SELECT COUNT(*)`. Kein `INSERT OR …` und kein `ON CONFLICT`, deshalb ist E-001 nicht berührt.

### 2.6 Schritte

**Teil A – Branchen und Modul-Presets**

1. **`BRANCHEN`-Konstante** in `public/script.js` anlegen: `[{id, label, preset}]`. Die
   bestehenden Presets für Metallbau und Elektrotechnik werden **unverändert** übernommen.
   Neue Einträge (Vorschlag, siehe F2):

   | id | Label | metall_erfassung | din1090 | datanorm | kupfer_del | aufmass | lager |
   |---|---|---|---|---|---|---|---|
   | `schreinerei` | Schreinerei | false | false | true | false | true | – |
   | `zimmerei` | Zimmerei | false | false | true | false | true | – |
   | `heizung_sanitaer` | Heizung/Sanitär | false | false | true | false | – | true |

   „–“ bedeutet: Der Schalter wird nicht gesetzt und der Default bleibt. `modul_vde0100` wird nie gesetzt.

   *Prüfbar:* Die Konstante enthält fünf Einträge, die ids sind reines ASCII.

2. **Onboarding** `maybeAskBrancheOnboarding()` auf `BRANCHEN` umstellen:
   - Der Prompt zeigt eine nummerierte Liste (`1 = Metallbau … 5 = Heizung/Sanitär`).
   - Er akzeptiert die Nummer oder die id, ohne Beachtung der Groß-/Kleinschreibung.
   - Die Fehlermeldung nennt die gültigen Werte.
   - `updates` entsteht aus `{branche, branche_initialized: true, ...preset}`.

   *Prüfbar:* manuell mit frischer Installation.

3. **Einstellungen:** Den Select „Standard-Branche“ aus `BRANCHEN` erzeugen, Labels mit `esc()`.
   Verhalten wie bisher: Es wird nur `branche` gesetzt, kein Preset (optional: Layoutfehler
   beheben, siehe F5).

   *Prüfbar:* Der Select zeigt fünf Optionen und die gespeicherte ist vorausgewählt.

4. **Release-Pflege:**
   - `npm.cmd run minify` ausführen.
   - `?v=` in `index.html` und `sw.js` erhöhen, ebenso `CACHE_VERSION`.
   - `VERSION` als Minor-Version erhöhen, dann `npm.cmd run version:sync`.
   - `CHANGELOG.md` ergänzen.
   - `node scripts/check-versions.mjs --strict` muss grün sein.

   Commit: `feat(onboarding): Branchen Schreinerei, Zimmerei, Heizung/Sanitär`.

**Teil B – Startkataloge (nur nach Freigabe F1)**

5. **JSON-Dateien** `src/Services/Branchen/<id>.json` anlegen, UTF-8 ohne BOM. Struktur:
   ```json
   {
     "stundenKatalog":  [{ "kategorie": "Schreinergeselle", "preis": 62.00, "fixkosten": 0 }],
     "materialKatalog": [{ "bezeichnung": "Spanplatte melaminbeschichtet 19 mm", "einheit": "m²", "ek": 0, "aufschlag": 20, "artikelNr": "" }],
     "pauschalen":      [{ "name": "An- und Abfahrt", "preis": 0 }]
   }
   ```
   Beträge werden in Euro angegeben, `aufschlag` in Prozent. Die Umrechnung übernimmt
   `money_to_cents()` wie in `DataService`. Inhalte stehen in 2.8. Es gibt keine Kunden- oder Echtdaten.

6. **Service** `App\Services\BranchenStartdaten`:
   - `const IDS = ['schreinerei','zimmerei','heizung_sanitaer']` dient als Whitelist. Der Dateiname
     entsteht nur aus dieser Konstante, nie aus der Eingabe, damit kein Path-Traversal möglich ist.
   - `laden(string $id): array` wirft bei unbekannter id.
   - `einspielen(\PDO $db, string $id, string $user): array` arbeitet in einer Transaktion:
     - Je Tabelle wird `SELECT COUNT(*)` geprüft. Nur wenn sie leer ist, folgt `INSERT` mit
       explizitem `id` ab 1. Das funktioniert auf PostgreSQL, weil die Spalte `GENERATED BY DEFAULT AS IDENTITY` ist.
     - Danach `DataService::bumpRev()`.
     - Rückgabe: `['stundenKatalog' => n, 'materialKatalog' => n, 'pauschalen' => n, 'rev' => r]`,
       übersprungene Tabellen mit n = 0.

7. **Aktion** `CatalogActions::brancheStartdaten()`:
   - Prüft `Auth::requireRole('admin')`.
   - Validiert `branche` gegen `IDS`, sonst 400 mit escapter Meldung.
   - Schreibt `AuditService::log('branche_startdaten', …)` und antwortet mit `jsonOut(['ok' => true, ...counts])`.
   - Route in `public/api.php` eintragen.

8. **Tests:**
   - Unit: Jede JSON-Datei ist gültig und hat kein BOM. Pflichtfelder und Typen stimmen, Preise ≥ 0,
     Einheiten sind nicht leer. Umlaute bleiben erhalten (z. B. `Heizkörper`).
   - API: Die Fälle aus 2.8 werden auf SQLite und PostgreSQL geprüft.

   Commit: `feat(katalog): Branchen-Startdaten für Schreinerei, Zimmerei, Heizung/Sanitär`.

9. **Onboarding-Anbindung:**
   - Nach erfolgreichem `save_settings` folgt, nur bei Branchen mit Startdaten, die Rückfrage
     `confirm('Startkataloge für <Label> übernehmen? Vorhandene Kataloge bleiben unverändert.')`.
   - Bei Ja: POST `branche_startdaten`, danach `await loadData()` und eine Meldung mit den Anzahlen.
   - Danach Release-Pflege wie in Schritt 4. Liegt Teil B im selben Release, zusammen mit Schritt 4 ausführen.

### 2.7 Risiken

- **SQLite/PostgreSQL:**
  - Es werden nur Standard-SQL und Prepared Statements verwendet.
  - Explizite ids in Identity-Spalten sind auf PostgreSQL erlaubt. Die Sequenz spielt keine Rolle,
    weil `DataService` ids immer explizit setzt.
  - Die Texte stehen in `TEXT`-Spalten, Umlaute sind unkritisch.
- **Migration und Fixture:** Es gibt keine Migration. Alte Backups sind nicht betroffen, weil
  unbekannte oder neue `branche`-Werte nur ein String in `settings.data` sind.
- **Datenverlust:** Teil B schreibt nur in leere Tabellen und überschreibt nie etwas.
  - Ein veralteter Client mit leeren Katalogen kann die neuen Daten nicht löschen. Der
    Leer-Payload-Guard in `saveAllData()` verhindert das, und `bumpRev()` erzwingt vorher einen Reload.
- **Sicherheit:**
  - Die Aktion ist nur für Admins und nur per POST erreichbar.
  - Die Whitelist verhindert Path-Traversal.
  - Labels und Katalogtexte im Frontend werden mit `esc()` bzw. `textContent` eingefügt.
- **Fachliche Preise:** Richtwerte für Stundensätze können in Angebote gelangen. Deshalb gibt es
  Frage F2: Standard ist 0 €, oder Richtwerte nur nach Freigabe. Material-EK wird immer mit 0 € eingespielt.
- **Lizenz und Feature-Flag:**
  - Das Preset setzt `modul_aufmass` bzw. `modul_lager` auf true.
  - Bei einer `license.json` ohne diese Module bleibt das Modul trotzdem gesperrt (`LicenseService`).
    Der Schalter ist dann wirkungslos, aber harmlos.
  - Die Frontend-Guards (`canDo`, Modul-Datei vorhanden) greifen weiterhin.
- **Cache-Busting:** Ohne Anhebung von `?v=` und `CACHE_VERSION` behalten Clients die alte Branchenliste.
- **Encoding:** `script.js` ist bereits UTF-8 (Emojis). Die JSON-Dateien müssen ohne BOM
  gespeichert werden, das prüft der Unit-Test.

### 2.8 Startdaten-Vorschlag (Teil B)

Alle Stundensätze sind **Richtwerte** (Verrechnungssatz netto €/h, Stand 2026, regional
unterschiedlich) und bleiben bis zur Freigabe in F2 auf 0 €. Material wird mit EK 0 € und 20 %
Aufschlag eingespielt, Pauschalen mit 0 €.

**Schreinerei**
- **Stunden:**
  - Schreinermeister 72
  - Schreinergeselle 62
  - Helfer 45
  - Auszubildender 30
  - Montage vor Ort 62
  - Maschinenstunde CNC 85
- **Material:**
  - Spanplatte melaminbeschichtet 19 mm (m²)
  - MDF-Platte 19 mm (m²)
  - Multiplexplatte Birke 18 mm (m²)
  - Leimholzplatte Buche 27 mm (m²)
  - Massivholz Eiche (m³)
  - Kantenumleimer ABS 2 mm (m)
  - Topfscharnier (Stk)
  - Schubkastenführung Vollauszug (Paar)
  - Möbelgriff (Stk)
  - Holzleim (kg)
  - Lack/Öl (l)
  - Beschläge/Kleinteile (psch)
- **Pauschalen:**
  - An- und Abfahrt
  - Aufmaß vor Ort
  - Entsorgung Verpackung/Altmöbel
  - Verbrauchsmaterial

**Zimmerei**
- **Stunden:**
  - Zimmerermeister 70
  - Zimmerergeselle 60
  - Helfer 45
  - Auszubildender 30
  - Abbund CNC (Maschinenstunde) 95
  - Kran mit Bediener 110
- **Material:**
  - KVH Nadelholz C24 (m³)
  - BSH GL24h (m³)
  - Dachlatte 40/60 (m)
  - Konterlatte 30/50 (m)
  - OSB/3-Platte 18 mm (m²)
  - Holzfaserdämmplatte (m²)
  - Zwischensparrendämmung Mineralwolle (m²)
  - Unterdeckbahn (m²)
  - Dampfbremsfolie (m²)
  - Holzbauschraube (Stk)
  - Balkenschuh/Winkelverbinder (Stk)
  - Holzschutzmittel (l)
- **Pauschalen:**
  - Baustelleneinrichtung
  - An- und Abfahrt
  - Autokran-Einsatz
  - Entsorgung Verschnitt

**Heizung/Sanitär**
- **Stunden:**
  - SHK-Meister 82
  - Anlagenmechaniker SHK 68
  - Kundendienstmonteur 75
  - Helfer 48
  - Auszubildender 32
  - Notdienst/Wochenende 110
- **Material:**
  - Kupferrohr 15 mm (m)
  - Kupferrohr 22 mm (m)
  - Mehrschichtverbundrohr 16 mm (m)
  - Pressfitting Bogen 15 mm (Stk)
  - Kugelhahn ½" (Stk)
  - HT-Rohr DN 50 (m)
  - KG-Rohr DN 110 (m)
  - Rohrdämmung 15 mm (m)
  - Heizkörper Typ 22 (Stk)
  - Thermostatkopf (Stk)
  - Rohrschelle (Stk)
  - Dichtmittel (psch)
- **Pauschalen:**
  - Anfahrt Kundendienst
  - Anlage entleeren/befüllen
  - Druck-/Dichtheitsprüfung
  - Inbetriebnahme/Einregulierung
  - Entsorgung Altgerät

### 2.9 Abnahmekriterien

**Teil A**
1. **Onboarding:** In einer frischen Installation fragt das Onboarding den Admin nach fünf
   Branchen in der Reihenfolge Metallbau, Elektrotechnik, Schreinerei, Zimmerei, Heizung/Sanitär.
   Eingabe `4` oder `zimmerei` speichert `branche = 'zimmerei'` und `branche_initialized = true`.
   Danach hat `load_settings` die Presets aus Schritt 1. Ungültige Eingaben speichern nichts und
   zeigen die gültigen Werte.
2. **Bestehende Presets:** Metallbau und Elektrotechnik setzen exakt dieselben Schalter wie vor der Änderung.
3. **Einstellungen:** Der Select zeigt fünf Branchen mit korrekten Umlauten („Heizung/Sanitär“) und
   speichert die id.
4. **Nicht-Admins:** Sie sehen kein Onboarding. Ist `branche_initialized` bereits true, erscheint es nicht erneut.
5. **Versionen:** `check-versions --strict` ist grün, und die neue `?v=` wird ausgeliefert.

**Teil B**

6. **Einspielen:** `POST branche_startdaten {branche:'schreinerei'}` als Admin auf eine leere DB
   füllt alle drei Kataloge mit den Anzahlen aus der JSON-Datei. `load_data` liefert die Texte
   mit Umlauten unverändert, und `rev` ist um 1 gestiegen.
7. **Kein Überschreiben:** Ein zweiter Aufruf, oder ein Aufruf bei gefülltem Katalog, ändert keine
   Zeile. Die Antwort meldet für den betroffenen Katalog 0. Das gilt je Tabelle.
8. **Rechte und Eingaben:**
   - Nicht-Admin → 403.
   - Unbekannte Branche oder `../x` → 400.
   - GET → abgelehnt.
9. **Datenbanken:** Die Kriterien 6–8 gelten gleich auf SQLite und PostgreSQL.
10. **Sicherung:** Eine Sicherung nach dem Einspielen enthält die Katalogzeilen und lässt sich wieder importieren.

### 2.10 Offene Fragen

- **F1:** Teil B umsetzen, also Startkataloge beim Onboarding anbieten? Wollen Sie eigene
  Startkataloge liefern (z. B. CSV/Excel je Branche), oder sollen die Listen aus 2.8 genommen werden?
- **F2:** Modul-Presets wie in Schritt 1 in Ordnung?
  - Aufmaß für Schreinerei und Zimmerei, Lager für Heizung/Sanitär, Datanorm für alle drei.
  - Kupfer-DEL für Heizung/Sanitär aus? DEL betrifft Kabel; Kupferrohr wird anders bepreist.
  - Stundensätze aus 2.8 als Richtwerte einspielen oder mit 0 €?
- **F3:** Reicht eine einzelne Branche, oder ist Mehrfachauswahl gewünscht (z. B. Schreinerei +
  Zimmerei)? Mehrfachauswahl würde Einstellungsformat, Preset-Logik und Katalog-Zusammenführung
  ändern und wäre ein eigenes Arbeitspaket.
- **F4:** Sollen Metallbau und Elektrotechnik ebenfalls Startkataloge bekommen? Soll Elektrotechnik
  zusätzlich `modul_vde0100` einschalten? Das ändert heutiges Verhalten und ist deshalb nicht eingeplant.
- **F5:** Darf beim Anpassen des Selects der fehlende `<div>`-Wrapper des Blocks „Standard-Branche“
  gleich mit behoben werden? Er liegt in derselben Stelle und ist ein Layoutfehler.

**Freigabe G1:** ☑ durch Nutzer am 2026-09-29 – nur Teil A; Layout-Fix (F5) mit beheben. Übrige Fragen: Vorschlag des Architekten.

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte

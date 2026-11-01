# AP-20261101-preiserhoehung-stunden-pauschalen: Preiserhöhung ab Tag X für Stundenkategorien & Pauschalen

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Stundenkatalog:
> Je Stundenkategorie soll eine Preiserhöhung eingetragen werden können, welche ab Tag X in Kraft tritt. Alle Vergangenen Buchungen werden noch mit dem alten Preis angezeigt/ verrechnet
>
> Pauschalen:
> Je Pauschale soll das gleiche wie in den Stundenkatalog möglich sein.

## 2. Plan

### Ziel & Abgrenzung
- **Ziel:** Je Stundenkategorie und je Pauschale lässt sich eine **zukünftige Preiserhöhung mit Gültigkeitsdatum (gültig ab)** hinterlegen. Ab diesem Tag erzeugte/berechnete Buchungen verwenden den neuen Preis; **vergangene Buchungen behalten den alten Preis** in Anzeige und Verrechnung.
- **Abgrenzung nach heutiger Preisermittlung (belegt):**
  - **Stunden:** Buchungen speichern den Preis bereits als **Snapshot** in `arbeitszeit[].stundenpreis` (ZeiterfassungActions `ensureProjectBookingInData` ≈595–624; Auswertung/Export/Vue lesen den Snapshot). Vergangene Stundenbuchungen bleiben also ohnehin stabil. Nötig ist nur, dass **neue** Buchungen ab Tag X automatisch den erhöhten Preis als Snapshot erhalten.
  - **Pauschalen:** **kein** Snapshot – `pauschalen.preis` wird **live** über `id` aufgelöst (`script.js` ≈7408, 4991, 14398, 14430; ExportActions ≈90–105; Archiv ≈35–39). Eine Preisänderung würde heute auch vergangene Pauschalenbuchungen rückwirkend ändern. Hier ist eine datumsabhängige Preisermittlung zwingend.
- **Nicht Teil:** Materialkatalog-Preise, Rückwirkende Neubewertung bereits erfasster Snapshots, Mehrwährung.

### Lösungsansatz (gemeinsames Preis-Historienmodell)
Eine gemeinsame, datumsbasierte Preisermittlung „Preis gültig am Datum D“ für Stunden **und** Pauschalen:
- Neue Tabellen `stunden_katalog_preise` und `pauschalen_preise` mit `(katalogId/pauschaleId, preis, gueltigAb, erstelltAm, erstelltVon)`. Der aktuelle `preis` in `stunden_katalog`/`pauschalen` bleibt als „aktuell gültiger“ Wert bestehen (Abwärtskompatibilität, Backup, alte Clients).
- **Preis am Datum D** = jüngster Eintrag mit `gueltigAb <= D`; existiert keiner, Fallback = `preis` der Basistabelle.
- **Stunden:** `ensureProjectBookingInData()`/`zieheBuchungNach()` ermitteln den Snapshot-Preis künftig über „Preis gültig am Buchungsdatum“ statt pauschal `stunden_katalog.preis`.
- **Pauschalen:** Verrechnung liest Preis datumsabhängig (Buchungsdatum der Pauschale) – zentrale Helper im Frontend (`pauschalePreisAm(id, datum)`) und Backend (ExportActions, Archiv).

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `migrations/2026110102xxxx_create_preis_historie.php` | Neue Tabellen `stunden_katalog_preise`, `pauschalen_preise` (SQLite-DDL, Phinx; Dialekt übersetzt). Timestamp > `20261101000000`. |
| `src/DataService.php` | Laden/Speichern der Preis-Historie mit Katalog/Pauschalen (≈31–34, 237–241); in `appData` ausliefern. |
| `src/Handlers/CatalogActions.php` / `DataActions.php` | Endpunkte zum Eintragen/Ändern/Löschen einer geplanten Preiserhöhung (gültig ab) je Stundenkategorie und Pauschale (POST, Rechteprüfung). |
| `src/Handlers/ZeiterfassungActions.php` | Snapshot-Preisermittlung in `ensureProjectBookingInData()`/`zieheBuchungNach()`/`folgeKategorie()` auf „Preis gültig am Datum“ umstellen. |
| `src/Handlers/ExportActions.php` | Pauschalen-/Stundenpreis im Export datumsabhängig (≈37–105). |
| `src/Handlers/BaustelleActions.php` | Archiv-Snapshot der Pauschalen datumsabhängig (≈35–39); Projektauswertung Stunden nutzt weiter gespeicherten Snapshot (≈758, bleibt). |
| `public/script.js` | Katalog-/Pauschalen-UI: Preiserhöhung (gültig ab) erfassen/anzeigen; Pauschalen-Verrechnung datumsabhängig (≈7408, 4991, 14398, 14430, 23136, 24322, 24593) über gemeinsamen Helper. |
| `src/frontend/modules/auswertung/AuswertungView.vue` | Stundenauswertung nutzt gespeicherten Snapshot (≈125, bleibt) – prüfen. |
| `src/Backup/BackupArchive.php`, `Importer.php` | Preis-Historientabellen in Backup/Import aufnehmen (OPTIONAL_KEYS/Skip-Liste ≈16/22–26). |
| `public/mobile.html` | Pauschalen-Verrechnung mobil datumsabhängig (falls dort gerechnet wird). |
| `public/bedienungsanleitung.html` | Sichtbare Funktion dokumentieren (E-081). |
| `tests/fixtures/backups/` | Neue Backup-Fixture nach Migration (E-020). |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/Unit/`, `tests/Api/`, `tests/js/` | Preis-am-Datum-Logik (PHP+JS Parität). |

### Struktur (Vorlage copier-astral)
- Schemaänderung nur per neuer Phinx-Migration; `legacy_baseline`/`LegacySchema.php` unberührt. Rechenregel mit PHP- und JS-Implementierung → gemeinsame Fallliste (E-036-analog). Keine neuen Root-Skripte.

### Berücksichtigte Erkenntnisse
- **E-020** nach Migration Backup-Fixture neu. **E-036** Rechenregel PHP+JS über gemeinsame Fallliste prüfen. **E-086/E-083** alle Schreib-/Lese-Pfade desselben Preises per grep inventarisieren (Zeiterfassung, Export, Archiv, Auswertung, Frontend, Mobil, Cron) und über zentrale Funktion führen. **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-088** API additiv; Bedeutung bestehender Felder (`stundenpreis`-Snapshot, `pauschalen.preis`) unverändert. **E-001** `ON CONFLICT`-Spalten qualifizieren. **E-015** Preiserhöhung nur per POST. **E-014** keine Internas in Ausgaben.

### Schritte
1. **Migration** Preis-Historientabellen anlegen (Indizes auf `(katalogId, gueltigAb)` bzw. `(pauschaleId, gueltigAb)`).
2. **Backend-Helper** `preisAm(katalogId, datum)` / `pauschalePreisAm(pauschaleId, datum)` zentral (eine Stelle), inkl. Fallback auf Basispreis.
3. **Verwaltungs-Endpunkte** (POST) zum Hinterlegen/Ändern/Löschen einer geplanten Erhöhung je Kategorie/Pauschale; Rechteprüfung analog Katalogpflege.
4. **Stunden-Snapshot** in `ensureProjectBookingInData()`/`zieheBuchungNach()` auf `preisAm(..., buchungsdatum)` umstellen; Verhalten für vorhandene Snapshots unverändert (nur neue/zu aktualisierende Buchungen).
5. **Pauschalen-Verrechnung** überall über `pauschalePreisAm(id, entry.datum)` statt `pauschalen.preis` (Frontend-Helper + Backend Export/Archiv/Mobil). Buchungen ohne Datum → Basispreis (offene Frage).
6. **UI** im Stundenkatalog und bei Pauschalen: Feld „Preiserhöhung ab <Datum> auf <Betrag>“ erfassen/anzeigen; bestehende Preishistorie listen.
7. **Backup/Import** um Historientabellen erweitern; alte Sicherungen bleiben importierbar (Tabellen leer → Fallback Basispreis).
8. **Backup-Fixture** neu erzeugen (E-020).
9. **Anleitung** ergänzen.
10. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
11. **Tests** PHP+JS Parität (siehe Abnahme).

### Risiken
- **Pauschalen rückwirkend:** Heute live gerechnet – nach Umstellung ändert sich die Verrechnung bestehender Pauschalenbuchungen **nur**, wenn eine künftige `gueltigAb`-Erhöhung existiert. Ohne Erhöhung bleibt der Basispreis → Verhalten unverändert. Buchungen **ohne Datum** müssen definiert behandelt werden (offene Frage).
- **Snapshot vs. live Divergenz:** Stunden sind Snapshot, Pauschalen live → zwei Mechanismen; Doku/Tests müssen beide klar trennen.
- **Alle Schreib-/Lese-Pfade (E-086/E-083):** Zeiterfassung (Desktop+Mobil, Admin-Nachtrag, Reparatur, Altimport), Export, Archiv, Projektauswertung, Vue-Auswertung, Cron (`cron_stundenauswertung_email.php`) per grep inventarisieren und zentral führen – sonst inkonsistente Preise.
- **Migration SQLite/PostgreSQL:** DDL in beiden Dialekten; `ON CONFLICT` qualifizieren (E-001); PostgreSQL grün (E-082).
- **Backup-Import alter Sicherungen (E-020):** Historientabellen fehlen in Alt-Backups → Fallback auf Basispreis; neue Fixture einchecken.
- **Rückwärtskompatibilität (E-088):** Alte Clients kennen keine Historie und lesen `pauschalen.preis` live – nach Update auf Mischbetrieb achten; Basispreis bleibt der „aktuell gültige“ Wert.
- **Cache-Busting:** `script.js`/`mobile.html` → Versions-Bump.

### Abnahmekriterien
1. Für eine Stundenkategorie lässt sich „Preis X ab Datum D“ hinterlegen; eine **neue** Zeiterfassung mit Datum ≥ D erhält Preis X als Snapshot, eine mit Datum < D den alten Preis.
2. Eine vor D erfasste Stundenbuchung bleibt in Auswertung/Export unverändert (alter Snapshot).
3. Für eine Pauschale lässt sich „Preis Y ab D“ hinterlegen; eine Pauschalenbuchung mit Datum ≥ D wird mit Y, eine mit Datum < D mit altem Preis verrechnet (Frontend, Export, Archiv, Mobil identisch).
4. Ohne hinterlegte Erhöhung bleibt alles wie bisher (Basispreis).
5. PHP- und JS-Preisermittlung liefern für die gemeinsame Fallliste identische Ergebnisse.
6. Import einer Alt-Backup-Fixture ohne Historientabellen gelingt; Preise = Basispreis; neue Fixture eingecheckt.
7. Preiserhöhung-Endpunkte nur per POST und mit korrektem Recht (403 sonst).

### Offene Fragen
- Dürfen auch **rückwirkende** `gueltigAb`-Daten (in der Vergangenheit) eingetragen werden, oder nur zukünftige? (Rückwirkend würde bestehende Pauschalen-Verrechnung ändern.)
- Wie werden **Pauschalenbuchungen ohne Datum** bepreist (Basispreis / Preis am Erfassungszeitpunkt)?
- Soll eine hinterlegte Erhöhung bei Erreichen von Tag X den **Basispreis** `pauschalen.preis`/`stunden_katalog.preis` automatisch fortschreiben (für alte Clients), oder bleibt die Historie die alleinige Quelle?
- Mehrere gestaffelte Erhöhungen je Kategorie/Pauschale zulässig (Verlauf), oder nur eine „nächste“?

**Entscheidung Nutzer (2026-11-01):** `gueltigAb` nur heute oder in der Zukunft (keine rückwirkenden Erhöhungen); Pauschalenbuchungen ohne Datum werden mit dem alten Preis (vor der ersten Erhöhung) bewertet.

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

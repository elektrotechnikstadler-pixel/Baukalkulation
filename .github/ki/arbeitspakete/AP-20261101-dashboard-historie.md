# AP-20261101-dashboard-historie: Dashboard – Historie je Rubrik mit Jahresarchiv

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Dashboard:
> - In der jewiligen Rubrik soll eine Historie mitlaufen (unten im Dashbaord eigeklappt, in welcher gefiltert werden kann. Die Historie wird je Jahr archiviert.

## 2. Plan

### Ziel & Abgrenzung
- **Ziel:** Je Rubrik (Aufgaben, Notizen, Erinnerungen) läuft unten im Dashboard eine **eingeklappte Historie** mit. Sie enthält erledigte/archivierte Einträge, ist **filterbar** (Text, Jahr) und wird **je Jahr archiviert** (Gruppierung/Umschaltung nach Kalenderjahr).
- **Nicht Teil:** Termin-Historie (Termine liegen separat), automatisches Löschen von Daten, Sortierumschalter (→ dashboard-sortierung).

### Offene Grundsatzfrage (Datenmodell)
Heute hat `dashboard_items` nur `status ∈ {offen, erledigt}` und `erstelltAm`; es gibt **kein** Archiv-/Erledigt-Zeitstempel und keinen Archiv-Status. „Je Jahr archiviert“ erfordert ein belastbares Datum. Zwei Varianten:
- **Variante A (klein, bevorzugt):** Historie = Einträge mit `status='erledigt'`, Jahr abgeleitet aus einem neuen Feld `erledigtAm` (Zeitpunkt der Erledigung). Keine Löschung, nur Anzeige/Filter. Minimaler Eingriff.
- **Variante B (groß):** Zusätzlicher Status `archiviert` + `archiviertAm`, manuelles/automatisches Jahres-Archiv. Mehr UI/Logik.
→ Als offene Frage an den Nutzer; Plan unten beschreibt **Variante A**.

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `migrations/2026110101xxxx_add_erledigtam_to_dashboard_items.php` | Neue Spalte `erledigtAm TEXT DEFAULT ''` (SQLite-DDL, Phinx; Dialekt übersetzt). Timestamp > bestehender `20261101000000`-Migration. |
| `src/Handlers/DashboardActions.php` | `updateStatus()`/`saveItem()` setzen `erledigtAm` beim Wechsel auf `erledigt` (und leeren bei Rücknahme); `rowToItem()` liefert Feld mit. |
| `public/script.js` | `renderDashboard()` je Rubrik einen eingeklappten Historie-Block (details/summary) mit Text- und Jahr-Filter; erledigte Einträge aus der aktiven Liste in die Historie verschieben. |
| `public/mobile.html` | Parität im mobilen Dashboard (optional, ggf. offene Frage). |
| `public/style.css` | Styles für Historie-Block/Filter. |
| `public/bedienungsanleitung.html` | Sichtbare Funktion dokumentieren (E-081). |
| `tests/fixtures/backups/` | Neue Backup-Fixture nach Migration (E-020, `make fixture`). |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/Api/` + `tests/js/` | Status→erledigtAm, Jahresgruppierung/Filter. |

### Struktur (Vorlage copier-astral)
- Schemaänderung ausschließlich per neuer Phinx-Migration (Vorgabe); `legacy_baseline`/`LegacySchema.php` unberührt. Keine neuen Root-Skripte/CLI.

### Berücksichtigte Erkenntnisse
- **E-020** nach Migration Backup-Fixture neu erzeugen und einchecken. **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-088** neues Feld `erledigtAm` additiv – alte Clients ignorieren es; Bedeutung bestehender Felder unverändert. **E-001** falls `ON CONFLICT`, Spalten qualifizieren (hier nur `UPDATE`). **E-087** Dateien nur per Edit/Create/Node schreiben.

### Schritte
1. **Migration** `erledigtAm` zu `dashboard_items` ergänzen (SQLite-DDL, `down()` wirft Exception; nur vorwärts).
2. **Backend:** In `updateStatus()` und `saveItem()` beim Setzen von `status='erledigt'` `erledigtAm = date('Y-m-d H:i:s')` setzen, beim Zurücksetzen auf `offen` leeren. `rowToItem()` erweitern.
3. **Frontend Historie-Block:** In `renderDashboard()` je Rubrik unten ein `<details>` „Historie“ (eingeklappt) rendern, das `status='erledigt'`-Einträge dieser Rubrik enthält; aktive Liste zeigt nur `offen`.
4. **Jahr-Archiv & Filter:** Historie nach Jahr (aus `erledigtAm`, Fallback `erstelltAm`) gruppieren; Jahr-Auswahl (Dropdown) + Textfilter.
5. **Backup-Fixture** nach Migration via `make fixture` neu erzeugen und einchecken (E-020/E-022-Hinweis: Version beachten).
6. **Anleitung** ergänzen.
7. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
8. **Tests** (siehe Abnahme), inkl. Import einer alten Fixture ohne `erledigtAm`.

### Risiken
- **Migration SQLite/PostgreSQL:** `ALTER TABLE … ADD COLUMN` muss in beiden Dialekten laufen (Dialect-Übersetzung); PostgreSQL-Lauf grün (E-082).
- **Backup-Import alter Sicherungen:** Bestehende `tests/fixtures/backups/v*.zip` ohne `erledigtAm` müssen importierbar bleiben (Spalte bekommt Default `''`). Neue Fixture erzeugen (E-020).
- **Rückwärtskompatibilität (E-088):** Alte Clients senden kein `erledigtAm`; Server leitet es aus dem Statuswechsel ab, nicht aus dem Client.
- **Datenbestand:** Für Alt-Einträge mit `status='erledigt'` ist `erledigtAm` leer → Jahr aus `erstelltAm` ableiten (Fallback dokumentieren).
- **Cache-Busting:** `script.js`/ggf. `mobile.html` → Versions-Bump.

### Abnahmekriterien
1. Nach Migration existiert Spalte `erledigtAm` (SQLite + PostgreSQL).
2. Einen Eintrag auf „erledigt“ setzen → er verschwindet aus der aktiven Rubrik und erscheint in der eingeklappten Historie derselben Rubrik; `erledigtAm` ist gesetzt.
3. „erledigt“ zurücknehmen → Eintrag wieder aktiv, `erledigtAm` leer.
4. Historie ist standardmäßig eingeklappt, per Text und Jahr filterbar; Gruppierung nach Kalenderjahr.
5. Alt-Eintrag ohne `erledigtAm` erscheint unter dem Jahr aus `erstelltAm`.
6. Import einer Alt-Backup-Fixture ohne `erledigtAm` gelingt; neue Fixture ist eingecheckt.

### Offene Fragen
- **Variante A oder B?** Genügt „Historie = erledigte Einträge nach Jahr“ (A), oder braucht es einen echten Archiv-Status mit manuellem/automatischem Jahresabschluss (B)?
- Soll die Historie auch im **mobilen** Dashboard erscheinen?
- Sollen Termine eine eigene Historie bekommen (liegen in separater Tabelle, hier bewusst ausgeklammert)?

**Entscheidung Nutzer (2026-11-01):** Variante A (erledigte Einträge über `erledigtAm`, gruppiert nach Jahr); Historie auch im mobilen Dashboard.

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

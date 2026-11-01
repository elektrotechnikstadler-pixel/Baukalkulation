# AP-20261101-dashboard-termin-aus-eintrag: Dashboard – Termin aus Aufgabe/Notiz/Erinnerung erstellen

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Dashboard:
> - Ist eine Aufgabe, Notiz oder Erinnerung erstellt, soll daraus ein Termin erstellt werden können.

## 2. Plan

### Ziel & Abgrenzung
- **Ziel:** Jede Dashboard-Zeile (Typ aufgabe/notiz/erinnerung) erhält eine Aktion „Als Termin“. Sie öffnet das bestehende Termin-Formular, vorbefüllt aus dem Eintrag (Titel, Beschreibung, Farbe, Fälligkeit→Datum, Zuweisung). Nach Speichern entsteht ein Termin in Tabelle `termine`; der Ursprungseintrag wird über `linkTyp='termin'`/`linkId` verknüpft (optional sichtbar).
- **Nicht Teil:** Sortierung/Verschieben (→ dashboard-sortierung), Historie (→ dashboard-historie), Termin-Serien/Wiederholung über das vorhandene Maß hinaus.

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `public/script.js` | `renderDashboardItem()` (≈22734–22784) Button „Als Termin“; neue Funktion, die `openTerminFormModal()` (≈13073) mit Vorbefüllung aus dem Item aufruft; nach Termin-Save optional `linkTyp/linkId` am Item setzen via `save_dashboard_item`. |
| `src/Handlers/DashboardActions.php` | `saveItem()` erlaubt bereits `linkTyp` inkl. `'termin'` (≈105) – prüfen/bestätigen; ggf. `rowToItem` unverändert. |
| `src/Handlers/TerminActions.php` | Keine Änderung nötig (bestehender `saveTermin`), nur falls Rückverknüpfung gewünscht. |
| `public/mobile.html` | Gleiche Aktion im mobilen Dashboard (`renderDashboardMobile()` ≈8561) für Parität. |
| `public/bedienungsanleitung.html` | Sichtbare Funktion dokumentieren (E-081). |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/Api/` | Ablauf Eintrag→Termin (Rechte, Felder). |

### Struktur (Vorlage copier-astral)
- Wiederverwendung bestehender Endpunkte (`save_termin`, `save_dashboard_item`); keine neue Struktur/Migration (E-050).

### Berücksichtigte Erkenntnisse
- **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-015** zustandsändernde Aktion nur per POST (Termin-Save ist POST). **E-088** keine Bedeutungsänderung bestehender Felder; `linkTyp='termin'` ist bereits erlaubter Wert. **E-013/E-014** keine Internas in Ausgaben.

### Schritte
1. **Frontend-Button:** In `renderDashboardItem()` Aktion „📅 Als Termin“ ergänzen (nur bei `canManageTermine`).
2. **Vorbefüllung:** Neue Funktion `terminAusDashboard(itemId)` sammelt `titel/beschreibung/farbe/faelligAm/zugewiesen_an` und öffnet `openTerminFormModal(null)` mit vorbelegten Feldern (Datum = `faelligAm` falls gesetzt, sonst heute).
3. **Rückverknüpfung (optional):** Nach erfolgreichem `save_termin` das Item per `save_dashboard_item` mit `linkTyp='termin'`, `linkId=<neueTerminId>` aktualisieren; in `renderDashboardItem()` einen Hinweis/Link „→ Termin“ anzeigen.
4. **Mobile-Parität:** Gleiche Aktion im mobilen Dashboard-Render ergänzen.
5. **Anleitung** ergänzen.
6. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
7. **Tests** (siehe Abnahme).

### Risiken
- **Rechte:** `save_termin` verlangt `canManageTermine` (403 sonst). Button nur anzeigen, wenn Recht vorhanden; Backend-Prüfung unverändert.
- **Zuweisung-Mapping:** `dashboard_items.zugewiesen_an` ist ein String (z. B. `alle`, `gruppe:<id>`, `<user>`), `termine.zugewiesen` ist ein JSON-Array von Usernamen → sauberes Mapping (Gruppen/`alle` ggf. leer lassen). Offene Frage dazu unten.
- **SQLite/PostgreSQL:** Keine neue SQL-Logik; beide Läufe grün (E-082).
- **Backup/Migration:** Keine Migration; keine Fixture-Neuerzeugung.
- **Cache-Busting:** `script.js`/`mobile.html` geändert → Versions-Bump.

### Abnahmekriterien
1. Button „Als Termin“ erscheint je Eintrag nur mit `canManageTermine`.
2. Klick öffnet Termin-Formular mit übernommenem Titel/Beschreibung/Farbe und Datum = Fälligkeit (sonst heute).
3. Nach Speichern existiert ein `termine`-Datensatz mit den Werten; er erscheint in der Terminliste/Dashboard-Terminspalte.
4. Optional: Ursprungseintrag zeigt Verknüpfung `→ Termin`.
5. Nutzer ohne `canManageTermine` erhält 403 bei direktem `save_termin`.
6. Mobile zeigt dieselbe Aktion.

### Offene Fragen
- Wie soll `zugewiesen_an` (String, inkl. `gruppe:<id>`/`alle`) auf das Termin-Feld `zugewiesen` (User-Array) abgebildet werden – Gruppen auflösen oder nur echte Benutzer übernehmen?
- Soll der Ursprungseintrag nach Terminerstellung automatisch auf `erledigt` gesetzt werden?

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

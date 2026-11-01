# AP-20261101-uebersicht: Arbeitspakete vom 2026-11-01

Sammlung der am 2026-11-01 geplanten Arbeitspakete aus dem Nutzerauftrag (Dashboard, Gesamtübersicht,
Kundenstamm, Stundenkatalog/Pauschalen, Mobile, Schnellnotizen, Stundenerfassung). Status aller: **geplant**.

## Übersichtstabelle

| # | AP-Datei | Fachbereich | Typ | Aufwand | Abhängigkeiten | Reihenfolge |
|---|---|---|---|---|---|---|
| 1 | `AP-20261101-gesamtuebersicht-filter.md` | Gesamtübersicht | Bugfix | S | – | 1 |
| 2 | `AP-20261101-kundenstamm-detail.md` | Kundenstamm | Feature+Bugfix | S–M | – | 2 |
| 3 | `AP-20261101-dashboard-sortierung.md` | Dashboard | Bugfix+Feature | M | – | 3 |
| 4 | `AP-20261101-notizen-bearbeiten.md` | Schnellnotizen | Feature | S–M | – | 4 |
| 5 | `AP-20261101-stundenerfassung-bearbeiten.md` | Stundenerfassung | Feature | M | nutzt best. Endpunkt; Beachtung AP-20261011 (E-085/E-086) | 5 |
| 6 | `AP-20261101-mobile-termine-filter.md` | Mobile | Feature | M | – | 6 |
| 7 | `AP-20261101-dashboard-termin-aus-eintrag.md` | Dashboard | Feature | M | sinnvoll nach #3 (gleiche Render-Funktionen) | 7 |
| 8 | `AP-20261101-dashboard-historie.md` | Dashboard | Feature | M–L | Migration + Fixture; nach #3/#7 (gleiche Render-Funktionen) | 8 |
| 9 | `AP-20261101-preiserhoehung-stunden-pauschalen.md` | Stundenkatalog + Pauschalen | Feature | L | Migration + Fixture; E-086/E-083 (alle Preis-Pfade) | 9 |

Aufwand: S = klein (Frontend/kleiner Fix), M = mittel (mehrere Dateien/UI+Backend), L = groß (Migration, mehrere Schreibpfade, PHP+JS-Parität).

## Umsetzung in Bündeln (E-091, E-092)

| Bündel | APs | Gemeinsame Dateien | Durchlauf |
|---|---|---|---|
| **B1 Kleinkorrekturen** | #1, #2, #4, #5 | `script.js` (Übersicht, Kunden, Schnellnotizen, Stundenerfassung), `BaustelleActions`, `PlanActions`, Zeiterfassung | Repro-Tests (#1, #2) → Entwickler → Reviewer → Tester → lokaler Commit |
| **B2 Dashboard + Mobile** | #3, #6, #7, #8 | Dashboard-Render in `script.js` + `mobile.html`, `dashboard_items` (Migration #8) | Repro-Test (#3) → Entwickler → Reviewer → Tester → lokaler Commit |
| **B3 Preiserhöhung** | #9 | Stundenkatalog, Pauschalen, Preisberechnung PHP + JS, Migration | Entwickler → Reviewer → Tester → lokaler Commit |
| **Release** | alle | – | Gesamtläufe SQLite + PostgreSQL + `test:js` einmal, dann **ein** Release |

Je Bündel einmal Minify/Cache-Busting und Pflege der Bedienungsanleitung. Subagenten bekommen die Fundstellen aus den
APs und einen `E-Nr`-Auszug; der Reviewer prüft `git diff` des Bündels.

## Empfohlene Reihenfolge (Begründung)
1. **Gesamtübersicht-Filter** – isolierter Frontend-Bugfix, schnell, kein Risiko.
2. **Kundenstamm-Detail** – kleiner Feature+Bugfix, additive API-Erweiterung.
3. **Dashboard-Sortierung** – Bugfix Verschieben + Sortiermodi; Basis für weitere Dashboard-APs.
4. **Notizen bearbeiten** – neuer einfacher Update-Endpunkt.
5. **Stundenerfassung bearbeiten** – wiederverwendet bestehenden Endpunkt; Vorsicht Projektbuchungs-Abgleich.
6. **Mobile Termine/Filter** – reine Frontend-Erweiterung in mobile.html.
7. **Dashboard Termin-aus-Eintrag** – baut auf Dashboard-Render (#3).
8. **Dashboard Historie** – erste Migration dieser Serie (Fixture), nach den anderen Dashboard-APs.
9. **Preiserhöhung Stunden/Pauschalen** – größtes AP (Migration, PHP+JS-Parität, viele Preis-Pfade) zuletzt.

> Hinweis Migrationen: Bereits vorhanden ist `migrations/20261101000000_*`. Neue Migrationen (#8, #9) brauchen
> **spätere** Zeitstempel und nach Ausführung `make fixture` + eingecheckte Backup-Fixture (E-020).

## Querschnittsregeln (für alle APs)
- **E-081** `public/bedienungsanleitung.html` je sichtbarer Änderung im selben Bündel pflegen.
- **E-090/E-092** Je Bündel gezielte PHPUnit-Läufe + `npm.cmd run test:js` vor dem lokalen Commit; Gesamtläufe SQLite + PostgreSQL einmal vor dem Release; Tests dauerhaft je Abnahmekriterium.
- **Cache-Busting** bei `script.js`-Änderung: `npm run minify`, `?v=` in `index.html`/`sw.js` + `CACHE_VERSION` erhöhen (`make check-versions`). `mobile.html` wird nur über `sw.js` `CACHE_VERSION` invalidiert.
- **E-088** API-Antworten/Schreibpfade additiv; Bedeutung bestehender Felder nicht ändern; gegen Vorversions-Clients (script.js/mobile.html) prüfen.
- **E-087** Dateien nur per Edit/Create/Node (`utf8`) schreiben – nie PowerShell 5.1.
- **E-092** Ein Release (Patch-Version + Tag) nach dem letzten Bündel.

## Entscheidungen des Nutzers (2026-11-01)

| AP | Frage | Entscheidung |
|---|---|---|
| #1 | KPI-Karten mitfiltern? | **Ja** – KPI-Karten und Zeile „Gesamt“ zeigen die gefilterte Menge |
| #2 | Alt-Archive ohne `kundeId` | **Ausblenden** in der Kundendetailansicht |
| #4 | Wer darf Schnellnotizen bearbeiten? | **Alle**, die die Notiz sehen |
| #5 | Wer darf Arbeitszeiten am Desktop bearbeiten? | **Wie Mobil** – nur Auswertungs-Berechtigte (`canSeeStundenauswertung`), kein neuer Eigentümer-Pfad |
| #8 | Historie-Variante | **Variante A** – erledigte Einträge (`erledigtAm`) gruppiert nach Jahr |
| #8 | Historie mobil? | **Ja**, auch im mobilen Dashboard |
| #9 | Rückwirkendes `gueltigAb`? | **Nein** – nur heute oder in der Zukunft |
| #9 | Pauschalen ohne Buchungsdatum | **Alter Preis** (Preis vor der ersten Erhöhung) |

## Gesammelte offene Fragen an den Nutzer

Die oben entschiedenen Fragen sind erledigt; die übrigen Fragen bleiben offen. Bis zur Klärung gilt jeweils der Vorschlag im AP.

### Dashboard-Sortierung (#3)
- Sortierauswahl pro Nutzer **serverseitig** speichern oder nur localStorage?
- Echtes Drag&Drop gewünscht oder genügen ↑/↓-Buttons?

### Dashboard Termin-aus-Eintrag (#7)
- Wie `zugewiesen_an` (String, inkl. `gruppe:<id>`/`alle`) auf Termin-Feld `zugewiesen` (User-Array) abbilden?
- Ursprungseintrag nach Terminerstellung automatisch auf „erledigt“ setzen?

### Dashboard Historie (#8)
- **Variante A** (Historie = erledigte Einträge nach Jahr, Feld `erledigtAm`) oder **Variante B** (echter Archiv-Status mit Jahresabschluss)?
- Historie auch im **mobilen** Dashboard?
- Eigene Termin-Historie gewünscht (Termine liegen separat)?

### Gesamtübersicht-Filter (#1)
- Sollen die **KPI-Karten** oberhalb ebenfalls auf die gefilterte Menge umschalten (Annahme: ja)?

### Kundenstamm-Detail (#2)
- Umgang mit **Alt-Archiven ohne gespeicherte `kundeId`**: ausblenden oder unter „ohne Kundenzuordnung“?

### Preiserhöhung Stunden/Pauschalen (#9)
- **Rückwirkende** `gueltigAb`-Daten erlaubt oder nur zukünftige?
- Bepreisung von **Pauschalenbuchungen ohne Datum** (Basispreis / Preis am Erfassungszeitpunkt)?
- Soll bei Erreichen von Tag X der **Basispreis** automatisch fortgeschrieben werden (für alte Clients), oder bleibt die Historie alleinige Quelle?
- Mehrere **gestaffelte** Erhöhungen je Kategorie/Pauschale zulässig?

### Mobile Termine/Filter (#6)
- Dashboard-Terminblock: volle Aktionen (öffnen/bearbeiten) oder reine Vorschau?
- Filterwert bei Verlassen/Wiederöffnen der Material-/Arbeitszeit-Ansicht behalten?

### Schnellnotizen bearbeiten (#4)
- Darf der **Ersteller** editieren oder nur **admin/master** (wie bei Archivieren/Löschen)?
- Sind **archivierte** Schnellnotizen bearbeitbar?
- Welche Felder editierbar – nur `text` oder auch Zuweisung/Baustellenbezug?

### Stundenerfassung bearbeiten (#5)
- Soll ein **normaler Nutzer seine eigenen** Einträge bearbeiten dürfen (dann reicht `canSeeStundenauswertung` nicht → eigene Eigentümerprüfung/Endpunkt), oder bleibt Bearbeiten auf Auswertungs-Berechtigte beschränkt (wie Mobil)?
- Auch **Uhrzeiten/Projekt** auf dem Desktop editierbar (erweiterte Payload + Abgleich beider Projekte) oder nur Datum/Typ/Stunden/Bemerkung?

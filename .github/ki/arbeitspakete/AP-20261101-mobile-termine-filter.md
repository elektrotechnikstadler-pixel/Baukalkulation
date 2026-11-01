# AP-20261101-mobile-termine-filter: Mobile – Termine im Dashboard & Filter bei Material/Arbeitszeit

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Mobile (nicht mobile-light)
> - auf der Mobile-Seite sind aktuell Termine nicht vorhanden, dies auch im Dashboard aufnehmen.
> - in den Baustellen/ Projekten soll auch bei Material bzw. Arbeitszeit gefiltert werden können.

## 2. Plan

### Ziel & Abgrenzung
- **Ziel 1 (Termine im Dashboard):** Die Mobile-Seite hat bereits eine eigene Termin-View (`viewTermine`, `loadMobileTermine`/`renderTermineMobile`), aber Termine erscheinen **nicht im mobilen Dashboard**. Ziel: Termine (heute/zukünftig) als eigene Rubrik im mobilen Dashboard (`renderDashboardMobile`) anzeigen – analog Desktop-Dashboard-Terminspalte.
- **Ziel 2 (Filter):** In der mobilen Projekt-/Baustellenansicht erhalten **Material** (`renderMaterial`) und **Arbeitszeit** (`renderArbeitszeit`) je ein Filter-/Suchfeld, das die angezeigten Positionen filtert.
- **Nicht Teil:** `mobile_light.html` (ausdrücklich ausgenommen), neue Termin-Backend-Funktionen (bestehen), Filter-Persistenz über Sitzung hinaus.

### Befund (belegt)
- Termine mobil existieren: `mobile.html` `viewTermine` (≈1390–1400), `loadMobileTermine()` (≈8312), `renderTermineMobile()` (≈8328), API `load_termine`. Das Dashboard (`viewDashboard` ≈1402–1418, `renderDashboardMobile()` ≈8561, API `load_dashboard`) bindet Termine **nicht** ein.
- Material: `renderMaterial()` (≈3203–3256) gruppiert nur nach Kategorie, **kein** Filterfeld. Arbeitszeit: `renderArbeitszeit()` (≈3852–3880), Ausgabe „Alle Einträge“ (≈3861), **kein** Filter. Das vorhandene `mobileSearchInput` (≈1175) filtert nur die Baustellenliste.

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `public/mobile.html` | `renderDashboardMobile()` um Termin-Rubrik erweitern (nutzt `load_termine`/`mobileTermine`, heutige+zukünftige, Sortierung nach Datum/Zeit analog Desktop `_dashboardTermineRowsHtml`); `renderMaterial()` und `renderArbeitszeit()` je ein Filterfeld + gefilterte Iteration. |
| `public/style.css` bzw. inline-CSS in `mobile.html` | Styling Filterfeld/Termin-Karten im Dashboard. |
| `public/bedienungsanleitung.html` | Sichtbare Änderungen dokumentieren (E-081). |
| `public/sw.js` | `CACHE_VERSION` erhöhen (mobile.html wird über SW-Cache invalidiert; kein eigener `?v=`-Stempel). |
| `tests/Api/` / `tests/js/` | Termin-Einbindung Dashboard (API vorhanden) und Filterlogik (reine JS-Funktion). |

### Struktur (Vorlage copier-astral)
- Reine Frontend-Erweiterung in bestehender `mobile.html`; Wiederverwendung bestehender Endpunkte. Keine Migration/neue Struktur (E-050).

### Berücksichtigte Erkenntnisse
- **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-088** `mobile.html`-Client gegen bestehende `load_termine`/`load_dashboard`-Antworten; keine Serveränderung. **E-086** Parität Desktop/Mobil beachten (gleiche Datenquelle/Rechte). **E-084** keine stillen Zusatzfilter – Termin-Rechte (`canSeeOwnTermine`/`canSeeAllTermine`) respektieren. **E-087** Datei nur per Edit/Create/Node schreiben (mobile.html Umlaute).

### Schritte
1. **Termine im Dashboard:** In `renderDashboardMobile()` eine Rubrik „📅 Termine“ ergänzen, die `mobileTermine` (bzw. `load_termine`) lädt, auf `datum >= heute` filtert und nach `datum`/`zeitVon` sortiert (analog Desktop `_dashboardTermineRowsHtml`). Nur anzeigen, wenn Termin-Modul/-Recht aktiv (`canDo`-Prüfung wie bei `showTermineMobile`).
2. **Material-Filter:** In `renderMaterial()` ein Suchfeld oberhalb der Kategorien; Filter auf `(b.material||[])` nach Bezeichnung/Kategorie **vor** der Gruppierung; Empty-State bei 0 Treffern.
3. **Arbeitszeit-Filter:** In `renderArbeitszeit()` ein Suchfeld; Filter auf `(b.arbeitszeit||[])` nach Beschreibung/Datum/Ersteller, dann Ausgabe.
4. **Konsistenz:** Filterzustand je Ansicht in einer Variable halten; beim Neuöffnen zurücksetzen (offene Frage Persistenz).
5. **Anleitung** ergänzen.
6. **Cache-Busting:** `CACHE_VERSION` in `sw.js` erhöhen (`make check-versions` grün).
7. **Tests** (siehe Abnahme).

### Risiken
- **Rechte/Module:** Termin-Button/-Rubrik nur bei vorhandenem Recht/Modul (sonst leere/fehlende Anzeige) – `applyPermissions()`/`canDo` nutzen; keine stillen Zusatzfilter (E-084).
- **Doppelte Terminanzeige:** Termine existieren schon als eigene View; im Dashboard nur als kompakte Vorschau, um Redundanz/Verwirrung zu vermeiden.
- **Cache (SW):** `mobile.html` hat keinen eigenen `?v=`; Invalidierung läuft über `CACHE_VERSION` in `sw.js` – diese zwingend erhöhen, sonst alter Stand.
- **SQLite/PostgreSQL:** Keine DB-Änderung; Gesamtläufe trotzdem grün (E-082).
- **Offline-Daten:** `mobile.html` hat Offline-Sync; Filter arbeiten rein lokal auf geladenen Daten → unkritisch.

### Abnahmekriterien
1. Im mobilen Dashboard erscheint eine Termin-Rubrik mit heutigen/zukünftigen Terminen, nach Datum/Zeit sortiert.
2. Ohne Termin-Recht/-Modul erscheint die Rubrik nicht (bzw. leer), ohne Fehler.
3. In der mobilen Material-Ansicht filtert ein Suchfeld die Positionen nach Bezeichnung/Kategorie; Treffer/Empty-State korrekt.
4. In der mobilen Arbeitszeit-Ansicht filtert ein Suchfeld die Einträge nach Beschreibung/Datum/Ersteller.
5. Filter leeren zeigt wieder alle Positionen.
6. `mobile_light.html` bleibt unverändert.

### Offene Fragen
- Sollen im Dashboard-Terminblock dieselben Felder/Klick-Aktionen wie in der Termin-View angeboten werden (öffnen/bearbeiten), oder nur eine reine Vorschau?
- Soll der Filterwert beim Verlassen/Wiederöffnen der Material-/Arbeitszeit-Ansicht erhalten bleiben?

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

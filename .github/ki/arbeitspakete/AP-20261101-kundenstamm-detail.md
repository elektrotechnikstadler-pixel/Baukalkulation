# AP-20261101-kundenstamm-detail: Kundenstamm – Detailansicht per Zeilenklick & Archiv je Kunde

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature · Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Kundenstamm:
> - Beim Klick auf den Kunden (die Zeile) soll die Detailansicht des Kunden geöffnet werden. Im Archiv der Detailansicht sollen nur die archivierten Projekte des jeweiligen Kunden angezeigt werden und nicht alle.

## 2. Plan

### Ziel & Abgrenzung
- **Ziel 1 (Feature):** Klick auf die gesamte Kundenzeile öffnet die Kunden-Detailansicht (`openKundeForm`). Aktions-Buttons (Bearbeiten/Löschen/Telefon/E-Mail) lösen den Zeilen-Klick nicht mit aus.
- **Ziel 2 (Bugfix):** Im Abschnitt „Archivierte Projekte“ der Detailansicht werden **nur die archivierten Projekte des jeweiligen Kunden** gezeigt, nicht alle.
- **Nicht Teil:** Neugestaltung der Detailansicht, Archiv-Reimport-Funktion selbst.

### Bug-Ursache (belegt)
`public/script.js`, `openKundeForm(id)` (≈23082–23220): Das Archiv wird über `fetch('api.php?action=list_archive')` global geladen; ein vorhandener Filter (`j.archives.filter(... return false ...)`) wird **nicht verwendet**, stattdessen werden via `allArch = j.archives || []` **alle** Archive gerendert (≈23179–23201). Ursache liegt auch im Backend: `src/Handlers/BaustelleActions.php` `listArchive()` (≈102–125) liefert pro Archiv **kein `kundeId`**, sodass das Frontend gar nicht kundenbezogen filtern kann. Datenmodell: `baustellen.kundeId → kunden.id` (LegacySchema, Index `idx_bau_kunde`).

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `src/Handlers/BaustelleActions.php` | `listArchive()` um `kundeId` je Archiv ergänzen (aus `$snap['baustelle']['kundeId']`). |
| `public/script.js` | `renderKundenListe()` (≈22514–22567): Zeilen-`onclick` auf `openKundeForm(k.id)`, Buttons mit `event.stopPropagation()`. `openKundeForm()` (≈23179–23201): Archive auf `a.kundeId === kunde.id` filtern, `allArch` durch gefilterte Liste ersetzen. |
| `public/style.css` | Cursor/Hover für klickbare Zeile. |
| `public/bedienungsanleitung.html` | Sichtbare Änderung dokumentieren (E-081). |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/Api/` | `list_archive` liefert `kundeId`; Rückwärtskompatibilität. |

### Struktur (Vorlage copier-astral)
- Erweiterung bestehender API-Antwort + Frontend; keine Migration/neue Struktur (E-050).

### Berücksichtigte Erkenntnisse
- **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-088** `kundeId` additiv zur `list_archive`-Antwort – alte Clients ignorieren es, Bedeutung bestehender Felder unverändert. **E-013** `list_archive` auf ungefilterte/sensible Ausgabe prüfen (nur Metadaten, kein Mehr an Daten). **E-072** Fundstellen geprüft.

### Schritte
1. **Backend:** In `listArchive()` je Eintrag `'kundeId' => isset($snap['baustelle']['kundeId']) ? (int)… : null` ergänzen. Keine zusätzlichen sensiblen Felder ausliefern (E-013).
2. **Frontend Zeilenklick:** `<tr>` in `renderKundenListe()` mit `onclick="openKundeForm(${k.id})"` und `style="cursor:pointer"`; alle Aktions-Buttons/Links um `event.stopPropagation()` ergänzen (Bearbeiten, Löschen, Telefon, E-Mail).
3. **Frontend Archiv-Filter:** In `openKundeForm()` die geladene Archivliste per `a.kundeId === kunde.id` filtern; Überschrift/Anzahl aus gefilterter Liste; Platzhalter `#kundeArchivProjekte` entsprechend füllen.
4. **Alt-Archive ohne kundeId:** Fallback definieren (nicht anzeigen oder unter „ohne Kundenzuordnung“) – siehe offene Frage.
5. **Anleitung** ergänzen.
6. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
7. **Tests** (siehe Abnahme).

### Risiken
- **Alt-Archive ohne `kundeId`:** Snapshots älterer Archive enthalten evtl. kein `kundeId` → `null`; dann würden sie je nach Filter verschwinden. Verhalten bewusst festlegen (offene Frage).
- **Backup-Import alter Sicherungen:** Keine Schemaänderung; `list_archive` liest Snapshots on the fly – alte Snapshots bleiben lesbar.
- **Rechte/Sicherheit (E-013):** `list_archive` nicht um sensible Felder erweitern; nur `kundeId` (Metadatum).
- **SQLite/PostgreSQL:** Keine DB-Änderung; beide Läufe grün (E-082).
- **UX:** Buttons dürfen Zeilen-Klick nicht doppelt auslösen → `stopPropagation` zwingend.
- **Cache-Busting:** `script.js` geändert → Versions-Bump.

### Abnahmekriterien
1. Klick auf eine Kundenzeile (nicht auf Button) öffnet die Detailansicht des richtigen Kunden.
2. Klick auf Bearbeiten/Löschen/Telefon/E-Mail löst **nicht** zusätzlich die Detailansicht aus.
3. `list_archive` liefert je Archiv ein `kundeId`.
4. Im Kunden-Detail erscheinen unter „Archivierte Projekte“ nur Archive mit `kundeId === kunde.id`; Anzahl stimmt.
5. Ein anderer Kunde zeigt seine eigenen (bzw. keine) Archive.
6. Alt-Archiv ohne `kundeId` wird gemäß festgelegtem Fallback behandelt.

### Offene Fragen
- Wie sollen **Alt-Archive ohne gespeicherte `kundeId`** behandelt werden – ausblenden oder unter „ohne Kundenzuordnung“ zeigen?

**Entscheidung Nutzer (2026-11-01):** Alt-Archive ohne `kundeId` werden in der Kundendetailansicht ausgeblendet.

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

# AP-20261101-stundenerfassung-bearbeiten: Erfasste Arbeitszeiten auf dem Desktop bearbeiten

| Feld | Wert |
|---|---|
| Status | abgeschlossen (lokal committet, Release nach B3) |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 2 |
| Commit | fb8ca84 (Bündel B1) |

## 1. Auftrag

> Stundenerfassung:
> Erfasste Arbeitszeiten sollen bearbeitet werden können (Desktop nicht möglich, muss bei Änderung neu angelegt werden/ Bei mobil ist es möglich)

## 2. Plan

### Ziel & Abgrenzung
- **Ziel:** In der Desktop-Stundenerfassung/-auswertung lässt sich ein bestehender Zeiteintrag **bearbeiten** (Datum, Typ, Stunden, Bemerkung), statt löschen + neu anlegen – durch **Wiederverwendung des vorhandenen Backend-Endpunkts** `admin_edit_zeiterfassung`, den der Mobile-Pfad bereits nutzt.
- **Befund (belegt):** Backend hat `adminEditEntry()` (Action `admin_edit_zeiterfassung`, Recht `canSeeStundenauswertung`). Mobile nutzt ihn via `saMobileEditEntry()` (mobile.html ≈7040) und führt danach `projektstandNachZeitAbgleichMobile()` aus. Desktop (`renderSeDesktop()`) bietet nur Neu-Erfassen (`save_zeiterfassung`) und Löschen (`admin_delete_zeiterfassung`) – **kein** Bearbeiten.
- **Nicht Teil:** Neuer Backend-Endpunkt (bestehender wird genutzt), Änderung der Soll-/Ist-Regeln, Projektwechsel-UI über das vorhandene Maß hinaus.

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `public/script.js` | In `renderSeDesktop()` je Zeile einen „Bearbeiten“-Button neben Löschen; neue `editSeDesktopEntry(username, entryId)` (Modal mit Datum/Typ/Stunden/Bemerkung) → POST `admin_edit_zeiterfassung`; danach Projektstand-Abgleich (`projektstandNachZeitAbgleich` bzw. Desktop-Pendant) und `renderSeDesktop()`. |
| `src/Handlers/ZeiterfassungActions.php` | Voraussichtlich **keine** Änderung; `adminEditEntry()` behält Altwerte bei fehlenden Feldern (Projekt/Uhrzeiten) und führt zentralen Abgleich. Nur falls Desktop-spezifischer Bedarf auffällt. |
| `public/bedienungsanleitung.html` | Sichtbare Funktion dokumentieren (E-081); bestehende Aussage „Desktop: löschen + neu“ korrigieren. |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/Api/` | Desktop-Edit nutzt denselben Endpunkt; Projektbuchungs-Abgleich nach Edit. |

### Struktur (Vorlage copier-astral)
- Wiederverwendung des bestehenden Endpunkts, keine neue API/Migration/Struktur (E-050).

### Berücksichtigte Erkenntnisse
- **E-085** Nach serverseitig geänderten Projektdaten (`dataRev`/`zeitRev`) korrekt mergen, nicht blind übernehmen – Desktop-Abgleich wie bei Mobil/Projektbuchung. **E-086** alle Schreibpfage derselben Daten zentral führen (`ensureProjectBookingInData`/`zieheBuchungNach`/`folgeKategorie`); zwischengespeicherte Status nach dem Edit neu bewerten. **E-088** API-Vertrag unverändert (gleicher Endpunkt, gleiche Felder) – unvollständige Payload darf Projekt/Uhrzeiten nicht auf `null` setzen. **E-041** Soll/Ist nur über zentrale Services. **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-015** Edit nur per POST.

### Schritte
1. **UI-Button:** In `renderSeDesktop()` je bestehender Zeile „Bearbeiten“ ergänzen, sichtbar nur mit `canSeeStundenauswertung` (gleiche Bedingung wie Mobil; Backend prüft ohnehin).
2. **Edit-Funktion:** `editSeDesktopEntry(username, entryId)` öffnet ein Modal, vorbefüllt aus dem vorhandenen Eintrag; sendet **nur** `username, entryId, datum, typ, stunden, bemerkung` an `admin_edit_zeiterfassung` (Projekt/Uhrzeiten bewusst weglassen → Server behält Altwerte, E-088/AP-20261011 B5).
3. **Abgleich nach Edit:** Antwort verarbeiten wie der Mobile-Pfad (`projektstandNachZeitAbgleich…`), Projektstand/Revision korrekt mergen (E-085); danach `renderSeDesktop()` und ggf. Projektansicht aktualisieren.
4. **Rechte:** Button nur bei Berechtigung; Backend-403 unverändert.
5. **Anleitung** korrigieren/ergänzen (Desktop jetzt editierbar).
6. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
7. **Tests** (siehe Abnahme), inkl. Projektbuchungs-Abgleich und „unvollständige Payload überschreibt nicht“.

### Risiken
- **Projektbuchungs-Abgleich (E-086):** Nach Stunden-/Datumsänderung muss die verknüpfte Projektbuchung zentral nachgeführt werden (`ensureProjectBookingInData`/`zieheBuchungNach`). Der bestehende Endpunkt tut das – Desktop darf keinen parallelen Pfad bauen.
- **Unvollständige Payload (E-088, AP-20261011 B5):** `baustelleId`/Uhrzeiten/Pause nicht mitsenden, damit sie nicht genullt werden; Server behält Altwerte.
- **Revision/Merge (E-085):** Desktop muss nach dem Edit den Projektstand wie Mobil behandeln (409/Auto-Merge vermeiden) – nur ohne ungespeicherte lokale Änderungen neu laden.
- **Rechtemodell:** `admin_edit_zeiterfassung` verlangt `canSeeStundenauswertung` – d. h. ein normaler Nutzer ohne dieses Recht kann **eigene** Einträge nicht editieren. Das weicht ggf. vom Nutzerwunsch ab → **offene Frage**.
- **SQLite/PostgreSQL:** Keine DB-Änderung; beide Läufe grün (E-082).
- **Cache-Busting:** `script.js` geändert → Versions-Bump.

### Abnahmekriterien
1. In der Desktop-Stundenauswertung/-erfassung erscheint je Eintrag ein „Bearbeiten“-Button (mit Berechtigung).
2. Bearbeiten ändert Datum/Typ/Stunden/Bemerkung; nach Reload sind die Werte gespeichert.
3. Projekt und Uhrzeiten des Eintrags bleiben unverändert, wenn sie nicht mitgesendet werden (keine Nullung).
4. Die verknüpfte Projektbuchung wird nach Stunden-/Datumsänderung korrekt nachgeführt (Abgleich, E-086); zwischengespeicherte Warnungen/Status neu bewertet.
5. Nutzer ohne `canSeeStundenauswertung` erhält 403 (Backend) bzw. sieht keinen Button.
6. Verhalten entspricht dem bereits funktionierenden Mobile-Pfad (gleicher Endpunkt).

### Offene Fragen
- Soll ein **normaler Nutzer seine eigenen** Zeiteinträge bearbeiten dürfen? Dann reicht `canSeeStundenauswertung` nicht – es bräuchte eine eigene Rechte-/Eigentümerprüfung (ggf. separater Endpunkt). Oder bleibt Bearbeiten wie bei Mobil auf Auswertungs-Berechtigte beschränkt?
- Sollen auch **Uhrzeiten/Projekt** auf dem Desktop editierbar sein (dann erweiterte Payload + Abgleich beider Projekte), oder nur Datum/Typ/Stunden/Bemerkung wie bei Mobil?

**Entscheidung Nutzer (2026-11-01):** Rechte wie Mobil – nur Auswertungs-Berechtigte (`canSeeStundenauswertung`); kein eigener Eigentümer-Pfad.

**Plankorrektur Leitstand (2026-11-01, E-072, vom Nutzer bestätigt):** Die Annahme „Desktop kann nicht bearbeiten“ stimmt nur für die **eigene Stundenerfassung**. Die Desktop-Stundenauswertung bearbeitet bereits (`saEditEntry` → `openSaEntryModal`, script.js ≈17159). Mobil bearbeitet eigene Einträge per Zeilenklick (`showStundenerfassungSheet(id)` + `saveStundenerfassung()` mit `editingSeId`, mobile.html ≈5107–5300) über `save_zeiterfassung` (fullSnapshot).
**Neuer Umfang (ersetzt Schritte 1–3 und die Rechtefrage):** Die Desktop-Stundenerfassung (`renderSeDesktop()` ≈15801, `addStundenerfassungDesktop()` ≈15941, `saveSeDesktopEntries()` ≈16140) bekommt „Bearbeiten“ für dieselben Einträge wie Löschen (`canEdit`: letzte 5 Tage, nicht virtuell). Bearbeiten lädt den Eintrag ins vorhandene Erfassungsformular (Bearbeiten-Modus, Abbrechen). Speichern ändert den Eintrag an Ort und Stelle (gleiche `id`/`clientUuid`) mit denselben Validierungen wie Neu (Überschneidung, Urlaubslimit, ArbZG, Gleitzeit), ohne den bearbeiteten Eintrag selbst mitzuzählen; Rollback bei Fehler. Kein neuer Endpunkt, kein `admin_edit_zeiterfassung`; Mobil unverändert. Abnahmekriterien 1–6 gelten sinngemäß für die eigene Erfassung (AK5: Einträge außerhalb `canEdit` ohne Button).

**Freigabe G1:** ☑ durch Nutzer am 2026-11-01 (Bündel B1)

## 3. Umsetzung
- `public/script.js`: `_resetSeDesktopForm()` (neue Hilfsfunktion, von `openStundenerfassungModal()`
  UND `cancelSeDesktopEdit()` genutzt); neue `editSeDesktop(id)` (lädt Eintrag ins Formular,
  nur innerhalb `canEdit`-Regel wie beim Löschen), `cancelSeDesktopEdit()`, `_saveSeDesktopEdit()`
  (gleiche Validierungen wie `addStundenerfassungDesktop()` – Überschneidung via
  `findSeDesktopOverlap`, Urlaubslimit, Gleitzeit max. 1/Tag, ArbZG-Hinweise – jeweils ohne den
  bearbeiteten Eintrag; `Object.assign` an Ort und Stelle, Rollback per Snapshot bei Fehler).
  Keine neue API, kein `admin_edit_zeiterfassung` (wie im Plan festgelegt).
- `public/index.html`: Formular-Buttons `seDesktopSubmitBtn`/`seDesktopCancelEditBtn`/
  `seDesktopEditHint` bereits vorhanden (Runde 1), keine weitere Änderung nötig.
- Cache-Busting: `script.min.js?v=247`, `CACHE_VERSION=bk-es-v250` (Minify nach den neuen Funktionen).
- Prüfungen (B1, Runde 2): `php vendor/bin/phpunit --filter "KundenArchivTest|Zeiterfassung|Kunden"`
  → 51/51 grün; `php vendor/bin/phpstan analyse --memory-limit=2G` → 0 Befunde;
  `npm.cmd run test:js` → 27/27 grün; `node scripts/check-versions.mjs --strict` → OK;
  `node -e "new Function(...script.js)"` → Syntax OK.
- Abweichung vom Plan: kein separater Endpunkt/Backend-Änderung nötig (wie im „Neuer Umfang“
  des Plans vermerkt); Datum-bis-Feld im Edit-Modus wird geleert und ausgeblendet statt genutzt
  (Bearbeiten betrifft immer nur den einen Eintrag, kein Datumsbereich).
- Modell laut Aufruf (E-097): Entwickler-Agent, `entwickler.agent.md` → Claude Sonnet 5 (Selbstauskunft „GPT-5.1“ unzuverlässig, nicht überprüfbar). Runde 1 brach mit 402 ab; der Rest wurde gezielt nachdelegiert.

## 4. Tests
- Lauf: `php vendor/bin/phpunit --filter "KundenArchivTest|SchnellnotizUpdateTest|ZeiterfassungBearbeiten|ZeiterfassungProjektbuchungTest"` -> **GRUEN** (SQLite) und via `scripts/test-pgsql.ps1` -> **GRUEN** (PostgreSQL).
- Matrix AK2+AK3 -> `tests/Api/ZeiterfassungBearbeitenTest.php::testDesktopFullSnapshotAktualisiertEintragUndBuchungOhneDoppelung` (gleiches `id`/`clientUuid`, Eintragsanzahl unveraendert, Werte geaendert, keine Doppelbuchung).
- Matrix AK4 -> `tests/Api/ZeiterfassungBearbeitenTest.php::testVeralteteBaseZeitRevLiefertNeedsMergeUndAendertNichts` (409 `needsMerge`, keine Aenderung).
- Referenz Abgleichsmuster -> `tests/Api/ZeiterfassungProjektbuchungTest.php::testGeaenderteStundenUndDatumWerdenNachgezogen` (SQLite/PG).
- Hinweis AK1/AK5 (Desktop-Button/canEdit-UI) bleiben Frontend-Thema; API-Vertrag ist abgesichert.
- Modell laut Aufruf (E-097): Tester-Agent, `tester.agent.md` → GPT-5.3-Codex.

## 5. Review

Siehe Sammelreview B1 in AP-20261101-gesamtuebersicht-filter.md (2 Runden, APPROVE).

## 6. Lernpunkte

Siehe AP-20261101-gesamtuebersicht-filter.md.

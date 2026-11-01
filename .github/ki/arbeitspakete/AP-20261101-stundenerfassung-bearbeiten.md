# AP-20261101-stundenerfassung-bearbeiten: Erfasste Arbeitszeiten auf dem Desktop bearbeiten

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

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

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte

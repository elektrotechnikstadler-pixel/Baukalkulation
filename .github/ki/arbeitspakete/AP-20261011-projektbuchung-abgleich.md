# AP-20261011-projektbuchung-abgleich: Zeiterfassung ↔ Projektbuchung – Lücken schließen

| Feld | Wert |
|---|---|
| Status | abgeschlossen |
| Typ | Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 3 |
| Commit | Release v3.0.15, Nachprüfung v3.0.16 |

## 1. Auftrag

Nutzer (2026-10-11): „Kann es sein, dass Zeiterfassungen zwar in der Stundenerfassung erfasst wurden und nie
auf ein Projekt gebucht werden, gibt es da Lücken oder hat es diese gegeben?“ – anschließend: „Bitte Plan
lt. AGENTS.md erstellen.“

Vorgaben des Nutzers zur Umsetzung (verbindlich):

- **R1:** Eine Zeiterfassung darf **nie** über eine Änderung der Stundenanzahl im Projekt verändert werden.
- **R2:** Nur das **Verschieben** einer Buchung in ein anderes Projekt ändert die Zeiterfassung (Projektbezug)
  und wird dort angezeigt.
- **F1:** Von Hand geänderte Projektbuchungen bleiben erhalten; die tatsächliche Zeitbuchung aus der
  Zeiterfassung steht als zweite Zeile in der Zelle „Import“, inkl. Datum der Zeitbuchung.
- **F2:** Ändert sich die Stundenkategorie eines Zeiteintrags, ziehen Kategorie und Preis der Projektbuchung nach.
- **F3:** Neuer Fehlercode **ZE013** mit Anzeige und Reparatur-Schaltfläche in der Stundenauswertung.
- **F4:** Zusätzlich „Abweichung bestätigen“. Wird eine Zeit im Projekt geändert, ändert das nie die
  Zeiterfassung; falls nötig wird diese separat in der Zeiterfassung geändert.

Anlass: Analyse nach v3.0.14. Die Lücken B1–B4 wurden mit einem temporären API-Test
(`tests/Api/ZzProbeLueckenTest.php`, wieder gelöscht) gegen den Stand `17eeda6` belegt.

## 2. Plan

Erstellt nach `architekt.agent.md` durch den Leitstand. Alle Befunde wurden an der Fundstelle gelesen;
B1–B4 per API-Test reproduziert, B5/B6 per Code-Lesung belegt (E-072).

### Teil A – Bestandsaufnahme

**A.1 Ablauf heute.** Die Projektbuchung (Auto-Import, Einstellung `auto_import_stunden`) entsteht nur in
`save_zeiterfassung` (`ZeiterfassungActions::save`, Z. 232–252) in einer Transaktion:
`reconcileAutoImport()` → `autoImportEntries()` → `applyBuchungsStatus()`. Andere Schreibpfade auf
`zeiterfassung` lassen die Projektbuchung unberührt oder gleichen nur teilweise ab. Kein zeitgesteuerter
Abgleich; `reconcile_bookings`/`diagnose_fehlbuchungen` nur auf Abruf.

Richtung Projekt → Zeiterfassung: Stunden-/Datumsänderungen im Projekt (`script.js:4434` `setAz`,
`mobile.html:3866` `saveArbeitszeit`) wirken **nicht** auf die Zeiterfassung (R1 erfüllt, aber ungetestet).
Verschieben (`script.js:2055-2085` → `BaustelleActions::movePosition`, Z. 945) hängt `zeiterfassung.baustelleId`
um und protokolliert `move` (R2 – mit Lücke B6).

**A.2 Belegte Befunde**

| Nr | Schwere | Fundstelle | Befund | Vorschlag |
|---|---|---|---|---|
| B1 | Hoch | `ZeiterfassungActions.php:1533` `adminAddEntry` | Admin-Nachtrag „Arbeit“ mit Projekt erzeugt **keine** Projektbuchung (Test: Projekt leer, `reconcile_bookings` 1 Orphan). Status bleibt `valid` → Stundenauswertung zeigt keine Warnung. | Nach dem Einfügen denselben Abgleich wie `save` (Transaktion). |
| B2 | Hoch | `ZeiterfassungActions.php:444` (`ensureProjectBookingInData` „exakt vorhanden“), `BuchungValidator.php:222` | Mitarbeiter ändert Stunden/Datum eines gebuchten Eintrags (ID bleibt, `mobile.html:5207-5218`) → Projekt behält **alte Werte** (Test: 8 h@02.03. statt 4 h@03.03.). Prüfung meldet nichts. | Werte nachziehen, außer die Buchung wurde von Hand geändert (F1); Abweichung = ZE013. |
| B3 | Hoch | `ZeiterfassungActions.php:1457` `adminEditEntry` | Admin-Bearbeitung fasst die Projektbuchung nicht an (Test: Wechsel A→B → A behält 8 h, B leer). | Nach dem Update denselben Abgleich (Transaktion), altes Projekt einbeziehen. |
| B4 | Mittel | `ZeiterfassungActions.php:538` (`$affectedBIds`), Aufrufer `save`, `adminDeleteEntry:1678` | Abgleich lädt nur Projekte, die **nach** der Änderung referenziert sind → Projektwechsel/Löschen des letzten Eintrags lässt die alte Buchung stehen (Test: Ghost ZE007 = Doppelbuchung). | Projekte des Vorher-Stands zusätzlich laden. |
| B5 | Hoch | `mobile.html:7002-7027` `saMobileEditEntry`, `ZeiterfassungActions.php:1468-1474` | Mobile Admin-Bearbeitung sendet weder `baustelleId` noch `von`/`bis`/`pause`; Backend setzt sie auf `null`/leer → **Projektzuordnung und Uhrzeiten gehen verloren**. | Backend: fehlende Felder = Altwert; Frontend sendet bekannte Werte mit. |
| B6 | Mittel | `BaustelleActions.php:945-1013` `movePosition`, `script.js:2069-2084` | Verschieben erhöht `zeitRev` des Mitarbeiters nicht → ein anderes Gerät speichert ohne Konflikt den alten Projektbezug zurück (Verschiebung verloren, mit B4-Fix Rückbuchung). Fehler der Kaskade nur `console.warn`, Projekt und Zeiterfassung laufen dann still auseinander (R2). | `zeitRev` erhöhen und zurückgeben; Fehler sichtbar melden; Desktop zieht `_zeitRev` nach. |
| B7 | Mittel | `mobile.html:3888` `delArbeitszeit` (Desktop: `script.js:4421/4804` mit `_blockAzImport`) | Mobil gelöschte Import-Buchungen kommen nicht auf die Sperrliste `azImportBlocked` → der nächste Auto-Import legt sie wieder an (Löschen wird still rückgängig gemacht). | Mobil wie Desktop sperren (gleiche Schlüssel wie `_azBookingKeys`). |

**A.3 Absichtlich nicht gebucht (unverändert):** Auto-Import aus, Benutzer ohne Kürzel (ZE006 sichtbar),
archivierte Projekte, Sperrliste `azImportBlocked` (bewusst gelöschte/archivierte Projektbuchung).

**A.4 Frühere Lücken (behoben, zur Einordnung):** v2.10.48 (nicht atomar), v2.10.49 (zweiter
Client-Schreibpfad), v2.10.83 RC-4 (unvollständiger Payload löschte Buchungen), v2.10.85 (Kürzel-Kollision,
Admin-Löschen entfernte alle verknüpften Buchungen). Altfälle aus B1–B6 können in Produktivdaten stecken;
B2-Altfälle findet derzeit kein Werkzeug – nach diesem AP erscheinen sie als ZE013.

### Teil B – Lösungsentwurf

**B.1 Datenmodell (ohne Schema-Änderung).** Jede verknüpfte Auto-Import-Buchung im Baustellen-JSON erhält
einen Spiegel des zuletzt abgeglichenen Zeiteintrags:

| Feld | Bedeutung |
|---|---|
| `zeitStunden`, `zeitDatum`, `zeitStundenKatId` | Werte des Zeiteintrags beim letzten Abgleich (immer aktuell gehalten) |

**B.2 Abgleichsregel je verknüpfter Buchung** (in `ensureProjectBookingInData`, nur aktive Projekte):

1. **Von Hand geändert?** `stunden ≠ zeitStunden` oder `datum ≠ zeitDatum` (Spiegel vorhanden). Ohne Spiegel
   (Altbestand) gilt eine Abweichung zum Zeiteintrag als Handänderung – konservativ, nichts wird überschrieben.
2. **Nicht von Hand geändert** → `stunden`/`datum` = Zeiteintrag.
3. **Kategorie (F2):** `stundenKatId = zeitStundenKatId` (nicht von Hand geändert) → Kategorie, `stundenpreis`,
   `fixkosten` aus dem Katalog des neuen Eintrags; sonst unverändert.
4. **Immer:** Spiegel = aktuelle Werte des Zeiteintrags.
5. **Nie** schreibt der Abgleich in die Zeiterfassung (R1).

**B.3 Anzeige (F1).** Zelle „Import“ (`script.js:4604`, mobil `mobile.html:3831-3833`) zeigt bei Import-Buchungen
eine zweite Zeile „Zeiterfassung: TT.MM.JJJJ“; weicht die Buchung ab, zusätzlich die Stunden der Zeiterfassung,
farblich hervorgehoben (z. B. „Zeiterfassung: 02.03.2026 · 8 h“). Die Daten liegen bereits auf der Buchung –
kein zusätzlicher Request. Werte per `esc()` (frontend.instructions.md).

**B.4 ZE013 (F3, F4).** „Projektbuchung weicht von der Zeiterfassung ab (Stunden/Datum).“ – `SEVERITY_WARNING`,
gesetzt in `BuchungValidator::classify` (verknüpfte Buchung vorhanden, Werte abweichend, Projekt aktiv,
nicht bestätigt). Zwei Schaltflächen in der Stundenauswertung (Admin/Master):

- **„Zeiterfassung übernehmen“** setzt `stunden`/`datum` (und Kategorie/Preis) der **Buchung** auf den
  Zeiteintrag – nie umgekehrt (R1).
- **„Abweichung bestätigen“** speichert auf der Buchung `abweichungBestaetigt = {stunden, datum, zeitStunden,
  zeitDatum}`. ZE013 entfällt, solange diese vier Werte unverändert sind; die zweite Zeile im Projekt bleibt.
  Ändert sich Zeiteintrag oder Buchung erneut, erscheint ZE013 wieder.

Keine der beiden Aktionen ändert die Zeiterfassung. Soll auch die Zeiterfassung angepasst werden, geschieht
das separat in der Zeiterfassung (R1). Archivierte Projekte: kein ZE013.

**B.5 Verschieben (R2, B6).** `movePosition` bleibt der einzige Weg, auf dem eine Projektänderung die
Zeiterfassung ändert: Projektbezug + `move`-Protokoll wie bisher, zusätzlich `zeitRev` erhöhen und
zurückgeben. Stunden/Datum/Spiegel der Buchung bleiben beim Verschieben unverändert.

### Teil C – Plan

**Ziel:** Jeder Schreibpfad auf Arbeits-Zeiteinträge hält die Projektbuchung atomar synchron (anlegen,
Werte nachziehen, entfernen); Handänderungen im Projekt bleiben erhalten und sind sichtbar; die Zeiterfassung
ändert sich vom Projekt aus nur durch Verschieben.

**Abgrenzung (nicht Teil):**
- Kein zeitgesteuerter Cron-Abgleich.
- Keine Rückwirkung Projekt → Zeiterfassung außer Verschieben (R1/R2).
- Archivierte Projekte bleiben eingefroren.
- Kein serverseitiger Umbau des Verschiebens (Projekt-Speichern + `move_position` bleiben zwei Requests).
- Legacy-Buchungen **ohne** Verknüpfung, die von Hand geändert wurden, behandelt der Abgleich wie bisher
  (Fuzzy-Abgleich ersetzt sie) – betrifft nur Daten vor v2.10.48.
- Keine Schema-Änderung, keine Migration, keine neuen Endpunkte.

**Betroffene Dateien**

| Datei | Änderung | Befund |
|---|---|---|
| `src/Handlers/ZeiterfassungActions.php` | Abgleich aus `save` in privates `syncProjektbuchungen()` herauslösen; Aufruf in `save`, `adminAddEntry`, `adminEditEntry`, `adminDeleteEntry` (Transaktion, `dataRev`); Regel B.2 in `ensureProjectBookingInData`; Vorher-Projekte in `reconcileAutoImport`; `adminEditEntry` übernimmt fehlende Felder aus `$old`; `repairBooking`/`repairBookingsBulk` behandeln ZE013 („übernehmen“) und neue Aktion „bestätigen“ (Parameter am bestehenden Endpunkt `repair_booking`); `reconcileBookings`/`diagnoseFehlbuchungen` melden ZE013 als reparierbar | B1–B5 |
| `src/Services/BuchungValidator.php` | `ZE013` im `KATALOG`; `classify` erkennt Abweichung der verknüpften Buchung; `loadProjekte` unverändert | B2 |
| `src/Handlers/BaustelleActions.php` | `movePosition`: `zeitRev` des `zeitUser` erhöhen, in Antwort liefern (Hilfsfunktion nicht duplizieren – vorhandene Logik aus `ZeiterfassungActions::bumpZeitRev` gemeinsam nutzbar machen) | B6 |
| `public/script.js` (+ `script.min.js` per `npm.cmd run minify`) | Zelle „Import“ zweite Zeile (B.3); `SA_FEHLER_KATALOG.ZE013`; Schaltflächen „Zeiterfassung übernehmen“/„Abweichung bestätigen“ für ZE013; Verschieben: Fehler sichtbar melden, `_zeitRev` nachziehen | F1, F3, F4, B6 |
| `public/mobile.html` | `renderArbeitszeit` zweite Zeile (B.3); `saMobileEditEntry` sendet `baustelleId`, `von`, `bis`, `pause`; `delArbeitszeit` setzt Sperrliste | F1, B5, B7 |
| `public/index.html`, `public/sw.js` | `?v=` und `CACHE_VERSION` erhöhen; `check-versions --strict` | – |
| `public/bedienungsanleitung.html` | Auto-Import (~Z. 170): Admin-Pfade buchen sofort, Werte ziehen nach, Handänderungen bleiben + zweite Zeile, nur Verschieben ändert die Zeiterfassung; Fehlercode-Tabelle (~Z. 227) um ZE013 (E-081) | alle |
| `tests/Api/ZeiterfassungTest.php`, `tests/Api/ZeiterfassungAdminTest.php`, ggf. `tests/Unit/…BuchungValidator…` | Reproduzierende Tests (rot → grün) | alle |
| `VERSION`, `package.json`, `public/manifest.json`, `CHANGELOG.md` | Patch-Version 3.0.15 (E-073), `npm.cmd run version:sync` | – |

**Struktur (copier-astral):** Keine neuen Dateien/Ordner, Make-Targets oder CLI-Befehle – keine Abweichung.

**Berücksichtigte Erkenntnisse:** E-032 (Gesamtläufe asynchron, nicht im Subagenten), E-036/E-041 (nicht
betroffen – keine Soll/Ist-Logik), E-070 (Testdateien im AP nennen), E-072 (Befunde an Fundstelle prüfen),
E-074 (neue Methoden mittesten), E-076 (Commit mit Pfaden), E-081 (Anleitung, Aussagen gegen UI-Pfad prüfen),
E-082 (SQLite + PostgreSQL vor Commit), E-084 (Vertrag bindend).

**Schritte**

1. **Tester (rot)** – je Abnahmekriterium ein API-Test (Liste unten), heute fehlschlagend; R1 als
   Regressionstest (heute grün, muss grün bleiben).
2. **Abgleich herauslösen:** Block aus `save` in `syncProjektbuchungen(username, kuerzel, kategorie, entries,
   written, vorherProjektIds, fullSnapshot)`; Verhalten von `save` unverändert (bestehende Tests grün).
3. **Vorher-Projekte (B4):** `reconcileAutoImport` nimmt zusätzliche Projekt-IDs; `save` übergibt die
   `baustelleId` aus `$oldRows`, Admin-Pfade die des Altwerts.
4. **Abgleichsregel B.2 (B2, F1, F2):** Spiegel schreiben, Werte/Kategorie nur ohne Handänderung nachziehen;
   Antwort von `save_zeiterfassung` um `autoUpdated` ergänzen.
5. **Admin-Pfade (B1, B3, B5):** Transaktion + `syncProjektbuchungen` mit den aktuellen Einträgen des
   Ziel-Benutzers (`entryId AS id` lesen – sonst gelten alle Verknüpfungen als verwaist, vgl. v2.10.85);
   `dataRev` zurückgeben; `adminEditEntry`: nicht gesendete Felder aus `$old`.
6. **ZE013 (F3, F4):** Katalog, `classify` (inkl. `abweichungBestaetigt`), `reconcile_bookings`/`diagnose_fehlbuchungen`,
   Aktionen „übernehmen“ und „bestätigen“ in `repair_booking`/`repair_bookings_bulk` – beide schreiben nur ins Projekt.
7. **Verschieben (B6):** `movePosition` erhöht `zeitRev`; Desktop meldet Fehler sichtbar und zieht `_zeitRev` nach.
8. **Frontend:** Zelle „Import“ Desktop + mobil, ZE013 in Stundenauswertung, `saMobileEditEntry`; minify,
   `?v=`, `CACHE_VERSION`, `check-versions --strict`.
9. **Doku/Version:** `bedienungsanleitung.html`, `CHANGELOG.md`, 3.0.15.
10. **Prüfungen:** PHPStan (keine neue Baseline), php-cs-fixer, Gesamtlauf SQLite + PostgreSQL (E-082).

**Risiken**

- **Sichtbare Korrekturen beim Rollout:** Das erste Speichern je Mitarbeiter zieht nicht von Hand geänderte
  Altbuchungen nach; Altbuchungen ohne Spiegel mit Abweichung erscheinen als ZE013 (gewollt). Vorher
  `reconcile_bookings` auf dem Live-System laufen lassen; Hinweis im CHANGELOG.
- **Fehlklassifikation Altbestand:** Ohne Spiegel ist „von Hand geändert“ nicht von „nie nachgezogen“
  unterscheidbar → beides wird ZE013 und per Reparatur entschieden (kein automatisches Überschreiben).
- **Laufzeit:** Admin-Pfade gleichen alle Einträge des Ziel-Benutzers ab – Batch-Laden aus v3.0.14 hält das
  bei einem Roundtrip je Projekt.
- **Optimistic Locking:** Admin-Pfade und `movePosition` erhöhen `zeitRev`/`dataRev`; Clients müssen die
  Werte nachziehen, sonst Selbstkonflikt (Desktop-Verschieben ausdrücklich in Schritt 7).
- **SQLite/PostgreSQL:** Nur bestehende Statement-Muster und Transaktionen; keine neuen SQL-Konstrukte.
- **Backups/Migration:** keine Schema-Änderung; neue JSON-Felder sind optional, alte Sicherungen bleiben
  importierbar (Fixtures unberührt).
- **Rechte:** unverändert (`canSeeStundenauswertung` für Admin-Pfade; Reparatur Admin/Master;
  `movePosition` wie bisher).
- **Cache-Busting:** `script.js` geändert → minify, `?v=`, `CACHE_VERSION`.

**Abnahmekriterien**

1. Admin-Nachtrag „Arbeit“ mit aktivem Projekt erzeugt sofort genau eine verknüpfte Projektbuchung.
2. Ändern Mitarbeiter oder Admin Stunden/Datum eines gebuchten Eintrags, zeigt die (nicht von Hand
   geänderte) Projektbuchung die neuen Werte; keine zweite Buchung.
3. Ändert sich die Stundenkategorie des Eintrags, übernimmt die Buchung Kategorie, Stundenpreis und Fixkosten.
4. **R1:** Wird die Buchung im Projekt von Hand geändert (z. B. 8 h → 6 h), bleibt der Zeiteintrag
   unverändert (8 h); auch nach erneutem Speichern der Zeiterfassung bleibt die Buchung bei 6 h und trägt
   `zeitStunden = 8`; der Eintrag erhält ZE013.
5. Reparatur „Zeiterfassung übernehmen“ setzt die Buchung auf 8 h; ZE013 verschwindet; der Zeiteintrag bleibt
   unverändert.
6. „Abweichung bestätigen“ lässt Buchung (6 h) und Zeiteintrag (8 h) unverändert, ZE013 verschwindet, die zweite
   Zeile bleibt; ändert sich danach der Zeiteintrag (z. B. 7 h), erscheint ZE013 wieder.
7. **R2:** Verschieben einer verknüpften Buchung ändert Projektbezug und `move`-Protokoll des Zeiteintrags,
   nicht Stunden/Datum; `zeitRev` steigt; ein nachfolgendes Speichern mit veraltetem `baseZeitRev` wird mit
   409 abgewiesen.
8. Projektwechsel (eigener oder Admin) entfernt die Buchung im alten und legt sie im neuen Projekt an – auch
   als einziger Eintrag auf dem alten Projekt. Admin-Löschen des letzten Eintrags entfernt die Buchung.
9. Mobile Admin-Bearbeitung ohne `baustelleId`/`von`/`bis`/`pause` lässt diese Werte unverändert.
10. Zelle „Import“ (Desktop, mobil) zeigt das Datum der Zeitbuchung, bei Abweichung zusätzlich deren Stunden.
11. Im Projekt gelöschte oder archivierte Import-Buchungen (Desktop **und** mobil) werden nicht neu gebucht und
    nicht als Fehler (ZE006/ZE013) gemeldet; archivierte Projekte und Sperrliste bleiben unangetastet.
12. Alle Tests grün auf SQLite und PostgreSQL; PHPStan ohne neue Baseline-Einträge; `check-versions --strict` grün.

**Offene Fragen an den Nutzer:** keine – F1–F4 am 2026-10-11 entschieden (siehe Abschnitt 1).

**Freigabe G1:** ☑ durch Nutzer am 2026-10-11 („starte“); B7 bei Freigabe auf Nachfrage ergänzt.

## 3. Umsetzung

### Runde 1 – Leitstand als Entwickler (E-066-Variante: direkte Umsetzung)

Geänderte Dateien:

- `src/Handlers/ZeiterfassungActions.php`: `syncProjektbuchungen()` (aus `save` herausgelöst; Reconcile →
  Import/Nachziehen → Status), `syncNachAdminAenderung()`, `resetBuchungsStatus()`, `aktualisiereBuchungsStatus()`;
  `ensureProjectBookingInData()` liefert `''|'created'|'updated'`, neue Helfer `katalogEintrag()` und
  `zieheBuchungNach()` (Regel B.2, Spiegel `zeitStunden/zeitDatum/zeitStundenKatId`); `reconcileAutoImport()`
  mit Vorher-Projekten; Admin-Pfade in Transaktion mit Abgleich und `dataRev`; `adminEditEntry` übernimmt
  fehlende Felder aus dem Altwert; `repairBooking()` mit `aktion` (`nachtragen|uebernehmen|bestaetigen`) und
  neuer `behandleAbweichung()`; `repairBookingsBulk()` bewertet den Status neu; `reconcileBookings()` liefert
  `abweichungen`, `diagnoseFehlbuchungen()` zählt `abweichend`; `bumpZeitRev()` jetzt `public static`.
- `src/Services/BuchungValidator.php`: `ZE013`, `findLinkedBooking()`, `weichtAb()` (inkl. `abweichungBestaetigt`).
- `src/Handlers/BaustelleActions.php`: `movePosition()` in Transaktion, erhöht und liefert `zeitRev`, feste
  Fehlermeldung statt Ausnahmetext (E-014).
- `public/script.js` (+ `script.min.js`): `_azZeitHinweis()` (Zelle „Import“), ZE013-Schaltflächen,
  `_saNachtragbar()` („Alle reparieren“ nur ZE006), `repairFehlbuchung(…, aktion)`, `_nachAdminZeitAenderung()`,
  Verschieben: `move_position` zuerst, bei Fehler Abbruch mit Meldung, Zeit-Cache/`_zeitRev` nur bei eigenem Eintrag.
- `public/mobile.html`: `azZeitHinweisMobile()`, `blockAzImportMobile()` in `delArbeitszeit`, `loadData()` nach
  Admin-Bearbeitung/-Löschung.
- `public/index.html`, `public/sw.js`: `script.min.js?v=232`, `CACHE_VERSION` `bk-es-v235`.
- `public/bedienungsanleitung.html`, `CHANGELOG.md`, `VERSION`/`package.json`/`public/manifest.json` (3.0.15).

Abweichungen vom Plan (E-084, gemeldet):

1. **B8 neu (Hoch):** „Jetzt importieren“ (`autoImport`, `auto_import_stunden`) war ein eigener Altpfad mit
   reinem Fuzzy-Abgleich: unverknüpfte Buchungen, Sperrliste und Archiv ignoriert → bei Handänderungen
   **Doppelbuchung**, gelöschte Buchungen kamen zurück. Umgestellt auf `syncProjektbuchungen()` (ohne Entfernen).
   Roter Test vorab bestätigt.
2. **Status nach Reparatur:** `repair_booking(s_bulk)` bewertete den gespeicherten Status nicht neu – die Warnung
   blieb bis zum nächsten Speichern des Mitarbeiters stehen. Für Abnahmekriterien 5/6 nötig, mitbehoben.
3. **`adminDeleteEntry`** erhöhte `zeitRev` nicht (gelöschter Eintrag per Full-Replace wiederherstellbar) – mitbehoben.
4. **Verschieben (B6):** Statt nur Fehler zu melden wird `move_position` **vor** dem lokalen Verschieben
   ausgeführt; bei Fehler bleibt alles unverändert. Zusätzlich gefunden: der Zeit-Cache wurde nur über die ID
   gesucht und konnte beim Verschieben fremder Buchungen einen **eigenen** Eintrag des Admins umhängen.
5. **B5 Frontend:** `saMobileEditEntry` sendet Projekt/Uhrzeiten bewusst **nicht**; der Server behält fehlende
   Felder (sicherer als veraltete Cache-Werte zu senden).
6. **Admin-Clients:** Da Admin-Pfade jetzt `dataRev` erhöhen, laden Desktop/Mobil danach den Projektstand neu
   (`loadData()`), damit kein veralteter Stand zurückgespeichert wird.

Ausgeführte Prüfungen: PHPStan gesamt ohne Fehler (keine neue Baseline), php-cs-fixer grün,
`check-versions --strict` grün, `node --check public/script.js`, Inline-Skripte `mobile.html` fehlerfrei.

### Runde 2 – Review-Befunde Runde 1

- R1-1: Verschieben übernimmt `zeitRev` nur bei lückenloser Folge (+1), sonst `_reloadZeitDesktop(false)`
  (neuer Parameter `render`).
- R1-2: Nach `save_zeiterfassung` wird der Projektstand neu geladen statt nur `dataRev` zu übernehmen
  (`_projektstandNachZeitAbgleich`, umbenannt aus `_nachAdminZeitAenderung`; mobil `loadData`). Dabei zusätzlich
  entfernt: der optimistische lokale Einfüge-Block für Import-Buchungen (eigene IDs, keine Spiegelfelder –
  hätte die Serverbuchung beim nächsten Speichern überschrieben).
- R1-3: `ensureProjectBooking()` mit Out-Parameter `$geschrieben`; Reparaturen erhöhen `dataRev` bei jedem Schreiben.
- R1-4: Statt nur zu dokumentieren: Altbuchungen ohne Spiegel werden mit dem Zeiteintrag **vor** der Änderung
  verglichen (`$vorherWerte` aus `save()`/`adminEditEntry()`); stimmen sie überein, folgen sie. Anleitung präzisiert.

### Runde 3 – Review-Befunde Runde 2

- R2-1: Neu laden nur ohne ungespeicherte lokale Änderungen (`_hatUngespeicherteProjektAenderungen()` bzw.
  `projektstandNachZeitAbgleichMobile()`: laufendes/hängendes Speichern, Konflikt, Offline-Queue, `appData` ≠
  `appDataBaseline`); sonst Revision alt lassen → 409 → Auto-Merge.
- R2-2: Kategorie des Vorher-Eintrags über `katalogEintrag()` auflösen (Standardkategorie); `adminEditEntry` liest
  `stundenKatId` separat (Audit-Inhalt unverändert).

## 4. Tests

### Runde 1 – rote Tests (Bugfix)

Neue Datei `tests/Api/ZeiterfassungProjektbuchungTest.php` (E-070), 14 Tests je Abnahmekriterium:
13 Tests vor der Umsetzung rot (Ursachen geprüft: fehlende Buchung, alte Werte, fehlender Spiegel/`autoUpdated`/
`zeitRev`/`abweichungen`); B8-Test nachträglich ergänzt und vor der Umstellung rot (2 statt 1 Buchung).
Testaufbau-Fehler (409 durch fehlendes `baseRev` beim Projekt-Speichern) im Test korrigiert, nicht im Code.

### Runde 2 – grün

- Neue Tests: 14/14 grün (416 Assertions).
- Verwandte Suiten (`Zeiterfassung|Baustelle|Buchung|Stunden|Data|Backup`): 313 Tests grün.

### Runde 3 – nach Review (G2)

- Neue Tests `testAltbuchungOhneSpiegelFolgtErsterAenderung`, `testAltbuchungMitAbweichungWirdNichtUeberschrieben`
  (erster vor dem Fix rot) und `testAltbuchungMitStandardkategorieFolgtKategoriewechsel` (ohne Fix nachweislich rot).
- `ZeiterfassungProjektbuchungTest`: 17/17 grün (510 Assertions); `npm.cmd run test:js` 12/12.
- **SQLite-Gesamtlauf:** 510 Tests, 3640 Assertions, 0 Fehler (2 übersprungen wie bisher).
- **PostgreSQL-Gesamtlauf:** 510 Tests, 3637 Assertions, 0 Fehler (3 übersprungen wie bisher).

## 5. Review

Reviewer: unabhängiger Code-Review-Agent nach `reviewer.agent.md`.

| Runde | Urteil | Befunde |
|---|---|---|
| 1 | CHANGES_REQUESTED | Major: `zeitRev` nach Verschieben blind übernommen (verdeckt Konflikt); Major: nach `save_zeiterfassung` nur `dataRev` übernommen → veralteter Projektstand überschreibt neue Buchungen; Minor: Reparatur ohne `dataRev` bei Nachziehen/Link; Minor (E-081): Altbuchungen ohne Spiegel folgen nicht |
| 2 | CHANGES_REQUESTED | Major: `loadData()` verwirft ungespeicherte lokale Projektänderungen; Minor: Kategorievergleich Altbuchung (Eintragskategorie vs. aufgelöste Buchungskategorie) |
| 3 | **APPROVE** | keine offenen Befunde |

Ohne Befund geprüft: R1 (kein Pfad schreibt Projektwerte in die Zeiterfassung), `jsonOut` nie in neuen `try`-Blöcken,
keine neuen SQL-Konstrukte (SQLite/PostgreSQL), Ausgaben in neuem HTML über `esc()`.

## 6. Lernpunkte

- **E-085** (Frontend, neu, 2× im selben AP → `frontend.instructions.md`): Revision nie ohne zugehörige Daten
  übernehmen; neu laden nur ohne ungespeicherte lokale Änderungen.
- **E-086** (Planung, 2. Auftreten der Ursache von E-083 → `php-backend.instructions.md`): bei Regeländerungen alle
  Schreibpfade inventarisieren und über eine zentrale Funktion führen.
- Offen für ein eigenes AP (nicht Teil dieses Auftrags): `auto_import_stunden` („Jetzt importieren“) ist nur per
  `Auth::requireAuth()` geschützt – jeder angemeldete Benutzer kann den Abgleich für alle Mitarbeiter auslösen.
  → in Runde 4 (3.0.16) behoben.

## 7. Nachprüfung auf Datenverlust (3.0.16)

Auftrag Nutzer (2026-10-11): „Was bewirkt ‚Jetzt importieren‘? Dies kann doch nur vom Admin gemacht werden? Bitte alle
Änderungen nochmal prüfen, damit keine Daten verloren gehen oder falsch behandelt werden.“

Vorgehen: Probetest mit 8 Szenarien gegen die API (temporär, gelöscht) und unabhängiges Datenverlust-Review über
`573b256..HEAD`; Befunde an der Fundstelle geprüft (E-072).

| Nr | Schwere | Befund | Behebung |
|---|---|---|---|
| D1 | Major | `ZeiterfassungActions.php` in 3.0.15 doppelt UTF-8-kodiert (263 Zeichen, Fehlermeldungen) | Rückwandlung cp1252→UTF-8, Zeilenvergleich mit `17eeda6`; Wächtertest `tests/Unit/QuelltextKodierungTest.php` (gegen HEAD rot) – E-087 |
| D2 | Major | Geänderte Standard-Stundenkategorie preiste alle Altbuchungen um | Kategorie folgt nur ausdrücklicher Wahl (`hatEigeneKategorie`), Spiegel bleibt sonst unverändert |
| D3 | Major | Handpreis im Projekt bei Kategoriewechsel/„übernehmen“ überschrieben | Preis-Spiegel `zeitStundenpreis/zeitFixkosten`, sonst Katalogvergleich (`preisHandGeaendert`) |
| D4 | Major | Verschieben: Projekt-Speichern getrennt vom Umhängen → Doppelbuchung/verlorene Handänderung bei Teilfehler oder Konflikt | `move_position` mit `srcBaustelleId` verschiebt serverseitig atomar; Client lädt neu, verschiebt nur ohne ungespeicherte Änderungen |
| D5 | Minor | „Jetzt importieren“ für alle Angemeldeten; verwarf ungespeicherte Änderungen | `requireRole('admin')`; `_projektstandNachZeitAbgleich` |
| D6 | Minor | Sperre `id:<user>:<eid>` blockierte neuen Eintrag mit wiederverwendeter ID | Sperre wird beim Abgleich entfernt, sobald der Eintrag gelöscht ist |
| D7 | Minor (Altproblem) | ID-Kollision beim Zusammenführen gleichzeitig angelegter Zeilen (auch nach serverseitigem Verschieben) | offen, eigenes AP |
| D8 | Minor | Server entfernt Sperren; Desktop-Merge behandelte `azImportBlocked` als Ganzes → lokale Sperre konnte verloren gehen | 3-Wege-Mengen-Merge `_mergeStringSet` (Desktop; Mobil führt nur ganze Projekte zusammen) |
| D9 | Minor | Verschieben einer verknüpften Buchung ohne `zeitUser` meldete Erfolg ohne Wirkung | Client verschiebt solche Altbuchungen lokal wie bisher; Server liefert mit `srcBaustelleId` 400 statt `ok` |
| D10 | Minor | Katalog-Preisänderung (`setStundenKat`) ließ Preis-Spiegel alt → Kategorie folgte nie mehr | Spiegel wird mitgezogen |
| D11 | Minor (Altproblem, nur PostgreSQL) | Keine exklusive Sperre der Revision bei gleichzeitigem Projekt-Speichern und Server-Abgleich/Verschieben (READ COMMITTED) | offen, eigenes AP (betrifft `DataService::saveAllData` und alle Abgleichspfade) |
| D12 | Hinweis (Altverhalten) | Verknüpfte Altbuchungen ohne `zeitUser` werden nur lokal verschoben; der nächste Abgleich macht das rückgängig | offen (selten); Zuordnung über `zeitEntryId` + Benutzer des Eintrags denkbar |
| D13 | Hinweis (Altverhalten) | Mobil führt Konflikte nur pro ganzer Baustelle zusammen; „Meine Version“ verwirft Server-Änderungen dieser Baustelle | offen, gemeinsam mit D7/D11 |

Review der Nachprüfung: unabhängiges Datenverlust-Review, 3 Runden (Befunde → Korrekturen → **APPROVE**).

Geprüft und unbedenklich (Probe + Review): Buchungen anderer Benutzer, manuelle Positionen, Altbuchungen bei mehrdeutigem
Kürzel, archivierte Projekte, Mitarbeiter ohne Kürzel, R1 (kein Pfad schreibt Projektwerte in die Zeiterfassung),
`adminEditEntry` (nicht gesendete Felder), Statusrücksetzung (kein weiterer Status außer valid/warning).

Tests: 9 neue Regressionstests in `ZeiterfassungProjektbuchungTest` (8 gegen HEAD nachweislich rot) + Wächtertest.
Gesamtläufe 3.0.16: SQLite 519 Tests / 3862 Assertions, PostgreSQL 519 / 3859 – jeweils 0 Fehler (2 bzw. 3 übersprungen wie bisher);
PHPStan ohne Fehler, php-cs-fixer, `check-versions --strict`, `test:js` grün.

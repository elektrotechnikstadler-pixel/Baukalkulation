# AP-20261004-anleitung-zeiterfassung: Bedienungsanleitung Zeiterfassung im Detail

| Feld | Wert |
|---|---|
| Status | abgeschlossen |
| Typ | Doku |
| Testläufe rot | 0 |
| Review-Runden | 2 |
| Commit | Release v3.0.10 |

## 1. Auftrag

Nutzer (2026-10-04): „Die Zeiterfassung mit allen Besonderheiten in der Bedienungsanleitung im Detail
ergänzen (Behandlung der einzelnen Buchungskategorien, Feiertage, Urlaub, Gleitzeit, …).
Bedienungsanleitung immer ergänzen, in Zukunft alles mit SQLite und PostgreSQL prüfen, danach committen
und pushen.“

## 2. Plan (Leitstand – reine Doku, Umfang vom Nutzer vorgegeben)

**Ziel:** Abschnitt 6 „Zeiterfassung“ in `public/bedienungsanleitung.html` vollständig und fachlich korrekt
(Stand 3.0.9) beschreiben. Kein Produktivcode.

**Inhalte (jeweils aus dem Code belegt, nichts erfinden):**
1. Erfassen: Desktop, Mobil, Mobil-Light; Tageseintrag, Von/Bis/Pause (erweiterte Zeiterfassung), Projektbezug,
   Zeiträume (Urlaub/Krank über mehrere Tage), Bearbeiten/Löschen, Massenlösch-Schutz.
2. Buchungskategorien mit Ist-Regel (Tabelle): Arbeit, Urlaub, Krank, Feiertag, Gleitzeit, Abwesend,
   Sonstiger Fehlgrund (inkl. Zeitspanne-Modus), eigene Typen (Verwaltung → Allgemein: „Arbeitszeit“, „Ist = Soll“).
3. Sollarbeitszeit: Soll/Tag + Arbeitstage, Checkbox „Sollzeit je Wochentag“ (Mo–So, 0 = frei), Vorbelegung,
   Startdatum, rückwirkende Wirkung, wer sie ändern darf.
4. Feiertage: gesetzliche (Bayern), eigene Feiertage, automatische Gutschrift nur an Soll-Tagen ohne Eintrag,
   Wirkung in Monats-Mail.
5. Urlaub: Jahresanspruch je Jahr, Limit-Prüfung beim Buchen, Anrechnung mit Tages-Soll.
6. Krank: Anrechnung, Anzeige.
7. Gleitzeit/Gleitzeitkonto: Saldo-Berechnung, Gleitzeittag, manuelle Buchungen, Jahreswechsel-Übertrag.
8. Prüfungen/Warnungen (Fehlbuchungs-Codes ZE…), Wochenprüfung, Anomalien.
9. Zeitverwaltung für Admin/Master: Übersicht, Monteur- und Tagesansicht, Sichtbarkeit (`showInZeitverwaltung`).
10. Auswertungen/Exporte: PDF, Excel/CSV, Jahresübersicht; Monats-Mail (Cron) und Stunden-Erinnerung.
11. Übergabe in Projekte (Auto-Import), Rechte.

**Weitere Dateien:** `public/sw.js` (`CACHE_VERSION` erhöhen, Anleitung ist im Precache); Versionsangabe im
Kopf der Anleitung aktualisieren.

**Abnahmekriterien:** Jede Aussage stimmt mit dem Code (Stand 3.0.9) überein; Inhaltsverzeichnis/Unterpunkte
navigierbar; Druckansicht funktioniert; `check-versions --strict` grün; keine Kundendaten/Secrets.

**Freigabe G1:** ☑ Auftrag des Nutzers vom 2026-10-04 (Umfang vom Nutzer vorgegeben, reine Doku).

## 3. Umsetzung

- **Punkt 1 – Erfassen/Bearbeiten:** Desktop „Zeitverwaltung → Stundenerfassung“, mobile „Stunden“/„+“ und Mobile-Light; Von/Bis/Pause über `erweiterte_zeiterfassung`, Sonstig-Modi, Zeitraum, Bearbeiten/Löschen und Schrumpfschutz. Quellen: `public/index.html` (Stundenerfassung-Modal), `public/mobile.html` (`showStundenerfassungFromList`, `showStundenerfassungSheet`, `saveStundenerfassung`), `public/mobile_light.html` (`saveEntry`), `public/script.js` (`toggleSeDesktopFields`, `addStundenerfassungDesktop`, `saveSeDesktopEntries`).
- **Punkt 2 – Kategorien/Ist-Regeln:** Tabelle folgt G1. Quelle: `public/sollzeit.js` (`bkIstStundenEintrag`), `src/Services/Sollzeit.php` (`istStundenEintrag`), `public/script.js` (`_SE_BUILTIN_TYPEN`, `isArbeitTyp`, `seTypIstGleichSoll`, `countsTowardIst`) und Verwaltung → Allgemein (`ze_custom_typen`).
- **Punkt 3 – Sollarbeitszeit:** Profilbedienweg, Erstvorbelegung, 0 als freier Tag, Admin-Recht und rückwirkende Wirkung. Quellen: `public/script.js` (`openUserProfileModal`, `toggleUserWeekdaySoll`, `saveUserProfile`), `src/Handlers/UserActions.php` (`getUserProfile`, `saveUserProfile`), `src/Services/Sollzeit.php` (`tagesSoll`), G1 in `AP-20261004-sollzeit-wochentage.md`.
- **Punkt 4 – Feiertage:** Eingebaute Bayern-Daten, betriebliche Feiertage, automatische virtuelle Gutschrift nur bei Tages-Soll > 0 ohne vorhandenen Eintrag, Monats-Mail. Quellen: `public/script.js` (`getBavarianHolidays`, `getVirtuelleFeiertagEintraege`, `renderFeiertageSection`), `src/Handlers/ZeiterfassungActions.php` (`computeBavarianHolidays`, `computeGleitzeitSaldo`), `cron_stundenauswertung_email.php`.
- **Punkt 5 – Urlaub:** Jahreskontingent, Limitprüfung, Bereich und Tages-Soll. Quellen: `public/script.js` (`addStundenerfassungDesktop`), `public/mobile.html` (`saveStundenerfassung`), `src/Handlers/ZeiterfassungActions.php` (`adminAddEntry`, `urlaubLimitForYear`), Mitarbeiterprofil und Jahreswechsel-Assistent in `public/script.js`.
- **Punkt 6 – Krank:** Tages-Soll-Anrechnung und Anzeige. Quellen: `public/sollzeit.js` (`bkIstStundenEintrag`), `src/Services/Sollzeit.php` (`istStundenEintrag`), `public/script.js` (`renderSeDesktop`, `zuRenderOverview`), `cron_stundenauswertung_email.php`.
- **Punkt 7 – Gleitzeit:** Monats-Ist minus Monatssoll ab Startdatum plus manuelle Buchungen; Gleitzeittyp selbst 0 h; Übertrag am 1. Januar. Quellen: `public/script.js` (`calcGleitzeitSaldo`, `openGleitzeitBuchungDialog`, Jahreswechsel-Assistent), `src/Handlers/ZeiterfassungActions.php` (`computeGleitzeitSaldo`, `save_gleitzeitkonto_buchung`), `src/Services/Sollzeit.php`; Beispielrechnung April 2026: 22 × 8 = 176 Soll; 17 × 8 + 6 = 142 Arbeit; Urlaub 2 × 8 = 16 und Feiertage 2 × 8 = 16; Ist 174, Monatsbeitrag 174 − 176 = −2 h.
- **Punkt 8 – Prüfungen:** Vollständige Kurzliste ZE001–ZE012 mit Schweregrad aus dem Katalog; Wochenprüfung und Anomalien getrennt erläutert. Quellen: `src/Services/BuchungValidator.php` (`KATALOG`), `public/script.js` (`renderWochenpruefung`, `zuRenderWochenpruefung`, `_computeZeitAnomalien`, `_renderSeWarnings`).
- **Punkt 9 – Zeitverwaltung:** Monatsübersicht, Monteur-/Tagesansicht, Berechtigungen und `showInZeitverwaltung`. Quellen: `public/index.html` (Zeitübersicht), `public/script.js` (`renderZeituebersicht`, `zuRenderOverview`, `zuRenderMonteurView`, `zuRenderTagView`), `src/Handlers/ZeiterfassungActions.php` (`getAllUsersExtended`).
- **Punkt 10 – Auswertung/Export/Cron:** PDF, CSV, Excel-Jahresdownload, Monats-PDF und Stunden-Erinnerung. Quellen: `public/script.js` (`exportSeDesktopPDF`, `exportZeituebersichtPDF`, `exportStundenauswertungPDF`, `exportAuswertungMitarbeiterCSV`, `saExportZeiterfassungExcel`), `cron_stundenauswertung_email.php`, `cron_stunden_erinnerung.php`.
- **Punkt 11 – Projekte/Rechte:** Auto-Import, manuelles Importieren, Rollen-/Berechtigungsgrenzen. Quellen: `public/script.js` (Verwaltung → Allgemein „Stunden Auto-Import“, `addStundenerfassungDesktop`), `src/Handlers/ZeiterfassungActions.php` (`save_zeiterfassung`, Admin-Aktionen), `src/Handlers/UserActions.php` (`saveUserProfile`), `src/Services/BuchungValidator.php` (ZE005/ZE006).
- **Weitere Änderungen:** Handbuchkopf, Beispiel in Abschnitt 1 und Fußzeile auf Version 3.0.10 gesetzt; `public/sw.js` nur `CACHE_VERSION` von `bk-es-v229` auf `bk-es-v230` erhöht.
- **Unsicherheiten/Abgrenzungen:** Der Feiertagscode enthält Mariä Himmelfahrt als bayernweiten festen Eintrag und differenziert nicht nach Gemeinde; die Anleitung beschreibt daher den im Programm hinterlegten Kalender, keine örtliche Rechtsprüfung. `cron_stunden_erinnerung.php` versendet selbst keine E-Mail, sondern protokolliert und speichert das Prüfergebnis; entsprechend ist es als Prüfung/Benachrichtigung, nicht als Mailversand beschrieben. ZE003 ist im Fehlerkatalog als Blocker definiert; die Anleitung gibt dessen Katalogbedeutung wieder.
- **Abweichungen vom Plan:** Keine Produktivlogik geändert, keine Tests geändert/angelegt. Die Umfangs- und Berechtigungsformulierungen bleiben auf die beobachteten UI-/API-Pfade begrenzt.

### Runde 2 – Umsetzung der Review-Befunde

- **Fehlbuchungscodes:** Die Tabelle nennt jetzt Bedeutung und tatsächliche Wirkung. `findBlockers()` erzwingt ZE010, ZE011, ZE002 und ZE001 (nur Arbeitseinträge mit Von/Bis); die Urlaubskontingentprüfung in `ZeiterfassungActions::save()` lehnt neue Überschreitungen separat ab, ohne ZE012-Code in der Antwort. `classify()` akzeptiert Arbeit ohne Projekt (ZE003) und prüft Projektbestand/Auto-Import für ZE005/ZE006. ZE004 erscheint als Auswertungsanomalie „Arbeitseintrag mit 0 h“, ohne ZE004-Code; ZE008 erscheint als >10-h-Anomalie, ebenfalls ohne ZE008-Code. ZE007 stammt aus der Projektprüfung; ZE009 und ZE003 werden derzeit nicht ausgelöst. Quellen: `src/Services/BuchungValidator.php` (`findBlockers()`, `classify()`, `KATALOG`), `src/Handlers/ZeiterfassungActions.php` (`save()`, Projektprüfung), `public/script.js` (`_renderSeWarnings()`, `_computeZeitAnomalien()`, `_computeFehlbuchungen()`, `_renderFehlbuchungBox()`).
- **Monats-Mail:** Feiertage werden nicht separat ausgewiesen; nur der bayerische Feiertagskalender mindert das Monatssoll, keine zusätzlichen betrieblichen Feiertage. Quelle: `cron_stundenauswertung_email.php` (`bayerischeFeiertage()`, Sollschleife, PDF-Spalten).
- **Sollzeit-Rechte:** Administratoren können Sollwerte im Mitarbeiterprofil ändern; Admin/Master zusätzlich in der mobilen Zeitübersicht über Aktion „Zeiten“ → Ansicht „Zeitübersicht“ → Tab „Übersicht“, Felder „Soll/Tag“ und „Tage/Wo“. Quelle: `public/mobile.html` (`btnZeituebersichtMobile`, `zuMobileRenderOverview()`), `src/Handlers/ZeiterfassungActions.php` (`setSollstundenTag()`), `src/Handlers/UserActions.php` (`saveUserProfile()`).
- **Abweichungen vom Plan:** Keine; nur Bedienungsanleitung und Abschnitt 3 dieses AP geändert.

## 4. Tests

- Reine Doku, keine neuen Tests. Gesamtläufe (Leitstand, E-082): PostgreSQL 17 per `scripts/test-pgsql.ps1`
  466 Tests OK (3 übersprungen); SQLite 466 Tests OK (2 übersprungen). `check-versions --strict` grün.

## 5. Review

**Urteil: CHANGES_REQUESTED**

| Schwere | Fundstelle | Falsche Aussage | Korrekte Aussage laut Code |
|---|---|---|---|
| Major | [public/bedienungsanleitung.html:215](../../../public/bedienungsanleitung.html#L215) | ZE003: Arbeit ohne Projekt sei ein Blocker und verhindere das Speichern. | Das Formular sendet `baustelleId: null` und der Speichervalidator blockiert diesen Fall nicht; `BuchungValidator::classify()` behandelt fehlende Projekt-ID als gültig ([public/script.js:15997](../../../public/script.js#L15997), [src/Handlers/ZeiterfassungActions.php:49](../../../src/Handlers/ZeiterfassungActions.php#L49), [src/Services/BuchungValidator.php:171](../../../src/Services/BuchungValidator.php#L171)). ZE003 steht nur im Katalog. |
| Minor | [public/bedienungsanleitung.html:194](../../../public/bedienungsanleitung.html#L194) | Die Monats-E-Mail weise Feiertage gesondert aus. | Das PDF hat keine Feiertagsspalte; es zieht bayerische Feiertage vom Soll ab. Betriebliche Feiertage werden im Cron nicht einbezogen ([cron_stundenauswertung_email.php:86](../../../cron_stundenauswertung_email.php#L86), [cron_stundenauswertung_email.php:111](../../../cron_stundenauswertung_email.php#L111), [cron_stundenauswertung_email.php:182](../../../cron_stundenauswertung_email.php#L182)). |
| Minor | [public/bedienungsanleitung.html:190](../../../public/bedienungsanleitung.html#L190) | Die Formulierung legt nahe, nur Administratoren dürften Sollzeit ändern. | Das Speichern des Mitarbeiterprofils ist admin-only; separate Sollzeit-Endpunkte erlauben auch `master` ([src/Handlers/UserActions.php:324](../../../src/Handlers/UserActions.php#L324), [src/Handlers/ZeiterfassungActions.php:1161](../../../src/Handlers/ZeiterfassungActions.php#L1161), [src/Handlers/ZeiterfassungActions.php:1163](../../../src/Handlers/ZeiterfassungActions.php#L1163)). |

**Lernpunkt-Kandidat:** Planung – Katalogdefinitionen gegen tatsächlich ausgeführte UI-/API-Pfade verifizieren; keine bestehende E-Nr.

### Runde 2

**Urteil: APPROVE**

Keine Befunde. Die drei Runde-1-Aussagen sind codegetreu korrigiert; in den geprüften Anpassungen entstand keine neue Falschaussage.

## 6. Lernpunkte

- E-081: Anleitung bei jeder sichtbaren Änderung ergänzen; Aussagen gegen aktive UI-/API-Pfade prüfen
  (Katalog versprach ZE003 als Speicher-Blocker).
- E-082: SQLite und PostgreSQL lokal vor jedem Commit; neues Skript `scripts/test-pgsql.ps1`.
- Offene Produktfrage: ZE003 (Arbeit ohne Projekt) und ZE004 (0 h) stehen als Blocker im Katalog, werden beim
  Speichern aber nicht erzwungen – Verhalten mit dem Nutzer klären.

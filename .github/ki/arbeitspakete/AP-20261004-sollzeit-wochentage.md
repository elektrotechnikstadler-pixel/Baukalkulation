# AP-20261004-sollzeit-wochentage: Zeiterfassung prüfen, Sollarbeitszeit je Wochentag

| Feld | Wert |
|---|---|
| Status | abgeschlossen |
| Typ | Feature |
| Testläufe rot | 1 |
| Review-Runden | 2 |
| Commit | Release v3.0.9 |

## 1. Auftrag

Nutzer (2026-10-04): „Zeiterfassungsprozess prüfen, zusätzlich ermöglichen, dass je User/Mitarbeiter die
Sollarbeitszeit nicht nur für jeden Tag gleich eingestellt werden kann, sondern per Checkbox erweitert
werden kann, damit die Sollarbeitszeit je Arbeitstag eingegeben werden kann (in Mitarbeiterdaten).“

Teil A – Prüfung: Zeiterfassungsprozess (Erfassung, Soll/Ist, Überstunden/Gleitzeit, Auswertung,
Erinnerungs- und Auswertungs-Cron, Mobil) auf Fehler und Lücken prüfen; Befunde im Plan auflisten.

Teil B – Feature: In den Mitarbeiterdaten Checkbox „Sollzeit je Wochentag“. Aus = wie bisher ein Wert
für alle Arbeitstage. An = je Wochentag (Mo–So) eigene Sollstunden. Alle Soll-Berechnungen nutzen den
Tageswert.

## 2. Plan

### Teil A – Bestand und Befunde

**Heutige Speicherung und Berechnung:** Die aktuelle tägliche Sollzeit steht als Stundenwert (`REAL`) in `users.sollstundenTag` (Standard 8); die Arbeitstage stehen als kommaseparierte ISO-Wochentage in `users.arbeitstage` (1=Mo, derzeit über die Mitarbeiterdaten nur Mo–Sa editierbar). `users.sollTageWoche` (REAL, Standard 5) dient als Fallback, wenn keine Arbeitstage vorliegen. `users.sollstunden` ist ein separates, älteres Feld und nicht die tägliche Sollzeit. Die Mitarbeiterdaten lädt/speichert `UserActions::getUserProfile/saveUserProfile`; `ZeiterfassungActions` liefert die erweiterten Mitarbeiterdaten sowie die eigene Konfiguration an die Zeiterfassung und die Auswertungen.

Soll ist mehrfach implementiert: Backend `loadUserZeitConfig/absenceStundenForDate` schreibt bei Urlaub/Krank Stunden in den Eintrag; `computeGleitzeitSaldo` berechnet den Jahreswechsel-Saldo. Desktop `public/script.js` berechnet Monats-/Wochen-Soll, Feiertagsgutschriften, Gleitzeit, Prüfungen und Berichte/PDFs. `public/mobile.html` enthält davon eigenständige Berechnungen für Erfassung, Übersicht, Auswertung und PDF. `public/mobile_light.html` zeigt Tages-Soll und berechnet Abwesenheitsgutschriften. `cron_stundenauswertung_email.php` berechnet Monatssoll und Abwesenheits-Ist selbst. `cron_stunden_erinnerung.php` prüft dagegen nur geplante Baustellen und vorhandene Einträge; sie berechnet keine Sollstunden.

| Schwere | Fundstelle Datei:Zeile | Belegter Befund | Vorschlag |
|---|---|---|---|
| Hinweis (Leitstand-korrigiert) | `public/script.js:14898, 14949, 14972`; `public/mobile.html:4787, 6316` | Ursprünglich als „Hoch“ gemeldet (Mo–Fr als Di–Sa) – **widerlegt**: `Date.getDay()` liefert Mo=1 … Sa=6 und stimmt damit für 1–6 mit ISO überein. Abweichend ist nur Sonntag (JS 0, ISO 7); heute nicht erreichbar, da Arbeitstage auf 1–6 begrenzt sind. Für Mo–So im neuen Modus relevant. | Gemeinsame Browserfunktion wandelt `getDay()` 0 → 7 und arbeitet nur mit ISO 1–7. |
| Hoch | `src/Handlers/ZeiterfassungActions.php:1717-1727`; `public/script.js:15405` | Jahreswechsel-Saldo im Backend summiert nur Einträge vom Typ `arbeit`, obwohl Urlaub/Krank beim Speichern bereits mit Sollstunden gutgeschrieben werden. Die Browserberechnung zählt Urlaub/Krank explizit zum Ist. Backend-Jahressaldo und Frontendsaldo weichen daher bei Abwesenheiten ab. | Abwesenheiten im Backend-Saldo mit der Sollzeit des jeweiligen Datums berücksichtigen; Backend und Browser anhand gleicher Tagesfälle testen. |
| Hoch | `cron_stundenauswertung_email.php:93, 128` | Der Monats-Cron lädt `stunden`, verwendet für Urlaub/Krank aber nur die Anzahl verschiedener Abwesenheitstage mal `sollstundenTag`. Der konkrete Tageswert und ob an diesem Datum Soll anfällt, bleiben unberücksichtigt; ein am arbeitsfreien Tag gespeicherter 0-Stunden-Eintrag wird im PDF trotzdem als voller Tag gutgeschrieben. | Für jeden Abwesenheitstag den Tageswert der zentralen Sollzeitfunktion verwenden und den Bericht aus diesen Tageswerten aufbauen. Feiertagsbehandlung des Berichts beibehalten bzw. durch Tests absichern. |
| Mittel | `public/mobile_light.html:395, 503-505, 1169` | Mobile-Light lädt nur `sollstundenTag`; Tages-Soll und Urlaub/Krank sind fest auf Mo–Fr begrenzt und ignorieren die geladene Mitarbeiter-Arbeitstagskonfiguration. Ein konfigurierter Samstag erhält dort z. B. kein Tages-Soll. | Mobile-Light dieselbe Datum-/Mitarbeiterfunktion wie die übrigen Clients verwenden lassen. |
| Mittel | `src/Handlers/ZeiterfassungActions.php:1182-1184`; `src/Handlers/UserActions.php:334-335`; `public/script.js:10726, 17920`; `public/mobile.html:6733` | Die Sollstunden-Setter wandeln Eingaben in `float` um, ohne den Bereich serverseitig zu prüfen. Die Profil-Speicherung klemmt stattdessen auf 0–24; mehrere Browser-Schreibpfade verwenden `parseFloat(value) || 8` und wandeln 0 in 8 um. Damit sind Validierung und Nullwertverhalten je Schreibpfad verschieden. | Eine serverseitige Validierung 0–24 für alle neuen Wochentagswerte (und konsistente bestehende Soll-Setter) festlegen; 0 im Wochentagsmodus verlustfrei als „kein Arbeitstag“ behandeln. Dezimalwerte wie bisher mit 0,5-h-Eingabeschritt erhalten. |
| Mittel | `public/script.js:10683, 10720`; `src/Handlers/UserActions.php:342`; `src/Handlers/ZeiterfassungActions.php:1663-1669` | Die Mitarbeiterdaten bieten und speichern Arbeitstage nur 1–6 (Mo–Sa); der backendseitige Parser des Jahressaldos verwirft ebenfalls 7, obwohl `loadUserZeitConfig` 7 als Sonntag akzeptiert. Die neue Funktion benötigt ausdrücklich Mo–So. | Sieben explizite Wochentagswerte als maßgebliche Tageskonfiguration einführen; in der Alt-Konfiguration „aus“ die bestehenden Arbeitstage unverändert weiterverwenden. |

**Rechte und Historie:** Die Mitarbeiterdaten-Endpunkte `getUserProfile/saveUserProfile` verlangen `admin`; die separaten Soll-Setter und erweiterten Zeiterfassungs-Endpunkte verlangen `admin` oder `master`. Normale Benutzer dürfen ihre eigene Zeiterfassung schreiben, aber nicht Mitarbeiter-Sollwerte ändern. Die gefundenen Sollwerte liegen als aktueller Stand in `users`; es wurde keine Gültig-ab-Konfiguration oder Sollzeit-Historientabelle gefunden. Salden und Berichte werden aus der aktuellen Mitarbeiterkonfiguration neu berechnet; gespeicherte Abwesenheitseinträge enthalten zusätzlich die bei ihrer Speicherung gutgeschriebenen Stunden. Eine Konfigurationsänderung kann daher vergangene Auswertungen verändern und alte Abwesenheitsstunden können davon abweichen.

### Teil B – Plan

**Ziel & Abgrenzung:** Optionaler Modus „Sollzeit je Wochentag“ an Mitarbeiterdaten. Ausgeschaltet bleibt `sollstundenTag` für die bisher konfigurierten Arbeitstage maßgeblich. Eingeschaltet gelten sieben eigene Tageswerte Mo–So; 0 bedeutet kein Arbeitstag. Tages-, Wochen-, Monats-, Jahres-/Gleitzeitsaldo-, Feiertags-, Abwesenheits-, Auswertungs-, Cron- und Mobilberechnungen verwenden den Tageswert des konkreten Datums. Keine Änderung am Erfassungsmodell für reguläre Arbeitsstunden, keine neue Fachmodul-Lizenz oder Feature-Flag, keine Änderung an `LegacySchema.php` oder der eingefrorenen Baseline und keine Änderung an der Erinnerungslogik, solange sie kein Soll berechnet.

**Betroffene Dateien und Struktur:**
- `migrations/<Zeitstempel>_add_weekday_sollzeit_to_users.php`: neue vorwärtsgerichtete Phinx-Migration; das Schema bleibt SQLite- und PostgreSQL-kompatibel.
- `src/Services/Sollzeit.php`: reine, typisierte Funktion „Sollzeit für Mitarbeiterkonfiguration und ISO-Datum“, inklusive Altmodus und Wochentagsmodus. Monat/Woche/Jahr werden durch Aufsummieren dieser Tagesfunktion berechnet.
- `src/Handlers/UserActions.php`, `src/Handlers/ZeiterfassungActions.php`: Mitarbeiterdaten laden/speichern und an alle relevanten APIs ausgeben; Validierung, Abwesenheitsgutschrift, Feiertage und Backend-Jahressaldo an die Funktion anbinden.
- `cron_stundenauswertung_email.php`: Monatssoll und Urlaub/Krank-Tagesgutschrift pro Datum aus derselben Backend-Funktion berechnen.
- `public/sollzeit.js` (neu), `public/script.js`, `public/index.html`, `public/mobile.html`, `public/mobile_light.html`: gemeinsame Browser-Entsprechung laden und Mitarbeiter-Maske sowie Desktop-, Mobil-, Bericht-, Prüfungs-, Feiertags- und Gleitzeitberechnungen darauf umstellen. Der neue Helfer wird vor den jeweiligen Inline-/Anwendungsskripten geladen.
- `public/sw.js`, generiertes `public/script.min.js`: Service-Worker-Präcache und Versionsparameter aktualisieren; `script.min.js` ausschließlich mit `npm.cmd run minify` erzeugen.
- `tests/Unit/SollzeitTest.php`, passende bestehende/neue `tests/Api/`-Tests sowie `tests/fixtures/backups/v*.zip` und `.expected.json`: Funktions-, API-, Berechtigungs- und Upgrade-/Importverhalten abdecken.

Die copier-astral-Vorlage ordnet Anwendungscode unter `src/`, Tests unter `tests/` und CLI/Build über vorhandene Tools ein. Der Plan folgt dieser Struktur (`src/Services`, `tests/Unit`, `tests/Api`, Root-`migrations/`); ein neues Modul oder Tooling ist nicht erforderlich. Die separaten HTML-Mobile-Clients sind eine bestehende Projektbesonderheit; sie verwenden denselben kleinen Browser-Helfer, statt jeweils eigene Sollformeln zu behalten.

**Speicherung:** Eine boolesche Spalte `sollzeitJeWochentag` und sieben `REAL`-Spalten (`sollstundenMo` bis `sollstundenSo`), jeweils mit sicheren Defaults, sind gegenüber einem JSON-Feld vorzuziehen: Werte bleiben typisiert, es braucht keine JSON-SQL-Funktionen, und Phinx-/Dialect-Übersetzung kann die einfache DDL auf SQLite und PostgreSQL anwenden. Bei einer erstmaligen Aktivierung im Altmodus die Tagesfelder im UI aus `sollstundenTag` und `arbeitstage` vorbelegen (Arbeitstag = bisheriger Wert, sonst 0); danach gespeicherte Tageswerte auch bei vorübergehend ausgeschaltetem Modus erhalten. Bestehende Backups werden durch Migration der Importkopie ergänzt; `LegacySchema.php` bleibt unverändert.

**Berücksichtigte Erkenntnisse:** E-001 (PostgreSQL-konforme SQL-Updates), E-020 (Backup-Fixture nach Schemaänderung), E-030 (SQLite-Dateien unter Windows), E-040 (Vite `publicDir: false` nicht ändern), E-050 (Struktur nach copier-astral), E-063 (kein `@`-Operator), E-070 (zusätzliche Tests im AP dokumentieren). Außerdem gelten `docs/entwicklung.md`, `migrationen.instructions.md`, `php-backend.instructions.md`, `frontend.instructions.md` und `tests.instructions.md`.

**Schritte (jeweils einzeln prüfbar):**
1. Reine Backend-Funktion samt Unit-Tests ergänzen: Altmodus mit den bestehenden Arbeitstagen, Wochentagsmodus Mo–So, Sonntag, 0/24, Dezimalwert und gültiges ISO-Datum; die Wochentagsnummerierung zentral auf ISO 1–7 festlegen.
2. Phinx-Migration mit Flag und sieben Stundenfeldern erstellen; Mitarbeiterprofil- und Soll-API um Lesen/Schreiben ergänzen. Server validiert numerisch und inklusiv 0–24; nur bestehende berechtigte Rollen können ändern. Bestehende Profile bleiben im Altmodus. SQLite-/PostgreSQL-Migration sowie Erhalt alter Backup-Imports prüfen und mit `make fixture` eine aktuelle Fixture erstellen.
3. Backend-Erfassung/Editieren von Urlaub/Krank, Feiertagsgutschriften und `computeGleitzeitSaldo` auf die zentrale Tagesfunktion umstellen. Alteinträge bei Berichten nicht stillschweigend umschreiben; Umgang damit gemäß Entscheidung zur Rückwirkung festlegen.
4. Monats-Cron auf dieselbe Funktion umstellen; Abwesenheiten je konkretem Datum statt als Tagesanzahl mal Pauschale berechnen. Testfälle für Arbeitstag, arbeitsfreien Tag und Feiertag ergänzen.
5. Gemeinsame Browserfunktion einbinden; Mitarbeiterdaten-Checkbox und sieben Stundenfelder ergänzen; bestehende Tages-/Wochen-/Monats-, Saldo-, Prüfungs-, Auswertungs-, PDF- und Mobilpfade einschließlich Mobile-Light schrittweise auf diese Funktion umstellen.
6. Bestehende API-Tests erweitern und neue Testfälle für Rechte, Grenzen, Import der `v2.10.99`-Fixture und neue Fixture ergänzen. PHPUnit gegen SQLite und PostgreSQL ausführen; `npm.cmd run minify`, Versionsparameter in `index.html`/`sw.js`, `CACHE_VERSION` erhöhen und `node scripts/check-versions.mjs --strict` prüfen.

**Risiken:** Migration und SQL müssen auf SQLite und PostgreSQL funktionieren; eingefrorene `LegacySchema.php` nicht ändern. Beim Backup-Import müssen alte Dateien ohne neue Spalten weiter importierbar sein; die generierte aktuelle Fixture ergänzen, alte Fixtures nicht ersetzen. `admin` darf Mitarbeiterdaten über das Profil pflegen; die bestehenden Soll-Setter erlauben `admin` und `master`; keine Rechte für normale Benutzer ergänzen. 0 darf im neuen Modus nicht durch Falsy-Fallbacks zu 8 werden; Grenzwerte und Dezimalrundung serverseitig absichern. Frontendänderungen erfordern Cache-Busting in `index.html` und `sw.js`, Erhöhung von `CACHE_VERSION` sowie den Build von `script.min.js`. Es handelt sich um Kernfunktionalität, daher kein Modul-Lizenz-/Feature-Flag-Risiko. Rückwirkende Saldenänderungen und gespeicherte alte Abwesenheitsgutschriften hängen von der noch offenen Historienentscheidung ab.

**Abnahmekriterien:**
- Im ausgeschalteten Modus liefert jeder bisherige Alt-Arbeitstag weiterhin `sollstundenTag`, jeder bisherige Nicht-Arbeitstag 0; alte Datensätze und Backups funktionieren ohne manuelle Nachpflege.
- Im eingeschalteten Modus wird für jedes Datum der passende Wert Mo–So verwendet; 0 ergibt an diesem Tag kein Soll und keine Abwesenheits-/Feiertagsgutschrift. Wochen- und Monatssummen entsprechen der Summe der enthaltenen Tageswerte.
- Derselbe Testdatensatz ergibt in Backend, Desktop, Standard-Mobilansicht, Mobile-Light, PDF/Übersicht und Monats-Cron identische Tages-/Periodenwerte; Feiertage und manuelle Gleitzeitbuchungen behalten die vereinbarte bisherige Neutralitätslogik.
- Werte 0 und 24 sowie die bisherige Dezimalgranularität werden akzeptiert; negative/nichtnumerische Werte und Werte über 24 werden serverseitig abgelehnt. Eine Eingabe 0 wird nicht zu 8 umgewandelt.
- Normale Benutzer können fremde Sollwerte weder lesen noch ändern; die vorhandenen Admin-/Master-Rechte bleiben erhalten.
- Die bestehende `v2.10.99`-Sicherung lässt sich importieren; aktuelle Fixture, SQLite- und PostgreSQL-Migration/Tests sowie `check-versions --strict` sind erfolgreich.

**Offene Fragen:**
- Wirkt die Änderung rückwirkend auf vergangene Zeiträume und Salden? Der aktuelle Code speichert keine Sollzeit-Historie/Gültig-ab-Werte; Auswertungen rechnen überwiegend mit dem heutigen Profilwert, während alte Abwesenheitseinträge ihre damaligen Stunden enthalten. **Empfehlung:** Wenn vergangene Lohn-/Gleitzeitsalden unverändert bleiben müssen, vor der Umsetzung eine Gültig-ab-Historie festlegen und den Sollwert je Datum daraus auflösen. Wenn Rückwirkung gewünscht ist, diese ausdrücklich freigeben und definieren, ob alte Urlaub-/Krankgutschriften neu berechnet oder als damals gespeicherter Wert beibehalten werden.
- Ist die empfohlene Speicherung als Flag plus sieben numerische Spalten freigegeben? Empfehlung siehe oben; sie ist portabel und hält die Tageswerte ohne JSON-SQL lesbar.

**Freigabe G1:** ☑ durch Nutzer am 2026-10-04, mit folgenden Entscheidungen:

- **Rückwirkend:** keine Gültig-ab-Historie. Alle Auswertungen (auch vergangene Monate, Gleitzeitsaldo) rechnen mit der aktuellen Mitarbeiterkonfiguration.
- **Teil-A-Befunde werden in diesem AP mit behoben** (Backend-Jahressaldo, Monats-Cron, Mobile-Light-Arbeitstage, einheitliche Validierung / 0 bleibt 0).
- **Speicherung freigegeben:** `sollzeitJeWochentag` + `sollstundenMo` … `sollstundenSo` in `users`.
- **Verbindliche Ist-Regel je Erfassungstyp** (gilt in Backend, Desktop, Mobil, Mobile-Light, PDF und Cron gleich):

  | Typ | Ist an diesem Tag |
  |---|---|
  | `arbeit` | erfasste Stunden |
  | `urlaub`, `krank` | Tages-Soll des Datums (aus aktueller Konfiguration; 0 an Tagen ohne Soll) |
  | `feiertag` (auch virtuell) | Tages-Soll des Datums |
  | `abwesend`, `gleitzeit`, `sonstig` | 0 h |
  | eigene Typen (`ze_custom_typen`, Verwaltung → Allgemein) | `isArbeit` aus → 0 h · `isArbeit` an → erfasste Stunden · `isArbeit` + `istGleichSoll` → Tages-Soll des Datums · alte String-Einträge → wie `isArbeit` an |

  Hinweis Leitstand: `computeGleitzeitSaldo` und der Monats-Cron zählen heute nur `arbeit`; eigene Arbeitstypen fehlen dort ebenfalls – mit beheben.

## 3. Umsetzung

### Runde 1 – Backend

- Neue `src/Services/Sollzeit.php`: pure Tages-Soll-Funktion für Altmodus und Wochentagsmodus (ISO 1–7), plus Ist-Auswertung nach der G1-Regeltabelle einschließlich JSON-Custom-Typen `ze_custom_typen`. Fehlendes/NULL-Alt-Soll verwendet 8 h; ein gespeicherter Wert 0 bleibt 0.
- Neue Migration `migrations/20261004000000_add_weekday_sollzeit_to_users.php`: `sollzeitJeWochentag` als Integer-Flag (Default 0) und `sollstundenMo` … `sollstundenSo` als Float-Felder (je Default 0). Null ist nicht nötig: Bei ausgeschaltetem Flag ignoriert der Altmodus diese Felder; bei aktivem Modus bedeutet der sichere Bestandswert 0 ausdrücklich kein Soll.
- `src/Handlers/UserActions.php`: Profil liefert/speichert Flag und sieben Tageswerte; Werte außerhalb 0–24 oder nichtnumerische Werte werden abgelehnt. Arbeitstage akzeptieren ISO 1–7. Profil-Recht bleibt `admin`.
- `src/Handlers/ZeiterfassungActions.php`: eigene und erweiterte Soll-APIs liefern die neuen Felder; der bestehende `admin`/`master`-Soll-Setter akzeptiert optional auch Flag und Wochentagswerte. Stundenwerte werden auf 0–24 geprüft und 0 bleibt erhalten. Abwesenheitsgutschriften, Admin-Buchungen und Gleitzeitsaldo verwenden die zentrale Tagesfunktion. Der Saldo bezieht Arbeit, Urlaub/Krank, Feiertag und Custom-Typen ein; virtuelle Feiertage werden ohne Eintrag nur bei Tages-Soll > 0 gutgeschrieben.
- `cron_stundenauswertung_email.php`: Monatssoll und Ist werden je Datum/Eintrag berechnet; Urlaub/Krank erhalten das konkrete Tages-Soll und Custom-Typen folgen der G1-Regel. Der bestehende Feiertagsabzug vom Monatssoll bleibt erhalten. Der Falsy-Fallback, der 0 zu 8 machte, ist entfernt.
- Backup-Import geprüft: `src/Backup/Importer.php` migriert die Sicherungs-DB-Kopie vor dem Kopieren; `TableCopier` übernimmt gemeinsame Quell-/Zielspalten. Alte Sicherungen ohne die neuen Felder bleiben importierbar. `src/Backup/BackupArchive.php` und die fremd gestagte Änderung daran wurden nicht angefasst; `LegacySchema.php` und Legacy-Baseline ebenfalls unverändert.

**API-Übergabe an die Frontend-Runde:** `get_user_profile` liefert die Felder innerhalb `profile`; `get_user_sollstunden` liefert sie flach; `get_sollstunden_extended` liefert sie je `users`-Eintrag. Namen: `sollzeitJeWochentag` (Boolean), `sollstundenMo`, `sollstundenDi`, `sollstundenMi`, `sollstundenDo`, `sollstundenFr`, `sollstundenSa`, `sollstundenSo` (Zahlen). Speichern erfolgt über `save_user_profile` mit denselben Feldnamen; `arbeitstage` bleibt kommasepariert und akzeptiert nun `1` bis `7`.

**Prüfungen:** PHP-Lint für alle fünf PHP-Dateien erfolgreich; Editor-Diagnostik ohne Befunde. `phpstan analyse --memory-limit=2G --no-progress` erfolgreich (`[OK] No errors`). Verhaltensprobe für ISO-Sonntag, Wochentagsmodus/0 und drei Custom-Typ-Fälle erfolgreich. `tests/bin/build-backup-fixture.php` lief unter Windows; `v3.0.8.zip` und `v3.0.8.expected.json` wurden erzeugt. Der gefilterte PHPUnit-Aufruf `--filter "Zeiterfassung|User|Migration|Backup"` gab Fortschrittspunkte, aber keine Abschlusszusammenfassung; Ergebnis daher nicht verifiziert.

**Abweichungen/offen:** Keine Frontend-Dateien und keine Tests geändert (Rundengrenze). SQLite-Schema wurde beim Fixture-Build migriert; Importtests der neuen Fixture sind wegen fehlender PHPUnit-Abschlussausgabe offen. PostgreSQL wurde in dieser Runde nicht ausgeführt. API-Integrationstests und Wiederholung des gefilterten Laufs folgen durch die Test-Rolle. Die Frontend-Runde muss Profileditor, eigene Soll-Konfiguration, Admin-Auswertung, Mobilansichten und alle Soll-/Ist-Pfade auf die oben genannten API-Felder und die G1-Regel umstellen. Beim erstmaligen Aktivieren sind Tageswerte aus `sollstundenTag`/`arbeitstage` vorzubelegen; beim Ausschalten gespeicherte Tageswerte erhalten.

### Runde 1 – Frontend Desktop

- `public/sollzeit.js`: globale Funktionen `bkTagesSoll(user, isoDatumOderDate)` und `bkIstStundenEintrag(user, eintrag, customTypen)` spiegeln `src/Services/Sollzeit.php`. ISO-Wochentage 1–7; JavaScript-Sonntag `0` wird ausschließlich auf ISO-`7` abgebildet. Altmodus übernimmt Arbeitstage und `sollTageWoche`, fehlendes/ungültiges Alt-Soll fällt auf 8 zurück, gespeicherte 0 bleibt 0. Ist-Regeln berücksichtigen aktuelle Tages-Sollwerte, Custom-Typen sowie die drei 0-Stunden-Typen.
- `public/index.html:1012`: Helper mit `?v=230` vor `script.min.js` geladen.
- `public/script.js`: Profilmaske `openUserProfileModal` (10650), Umschalten/Erstvorbelegung `toggleUserWeekdaySoll` (10732) und Speichern `saveUserProfile` (10754) um Flag und sieben Werte erweitert. Die Altmaske bleibt bei deaktiviertem Modus erhalten; Tageswerte werden beim Ausschalten weiter mitgesendet. Soll-Eingabe 0 bleibt in den Profil- und Desktop-Soll-Schreibpfaden erhalten.
- Tages-/Periodenfunktionen: `getWorkingDaysInMonth` (14938), `calcSollMonat` (14952), `getUserArbeitstage` (14963), `getVirtuelleFeiertagEintraege` (14994), `calcSollWoche` (15019) und `sumIstStunden` (15460) verwenden ISO-Tage und die zentralen Browserfunktionen. Virtuelle Feiertage werden nur bei Tages-Soll > 0 erzeugt und erhalten den Sollwert des konkreten Datums.
- Erfassung und Saldo: `_renderSeWarnings` (15484), `openStundenerfassungModal` (15619), `calcGleitzeitSaldo` (15770), `renderSeDesktop` (15801), `addStundenerfassungDesktop` (15941) und `exportSeDesktopPDF` (16246) rechnen mit der aktuellen Benutzerkonfiguration. Urlaub/Krank und `istGleichSoll` werden rückwirkend mit dem Datumssoll bewertet; `abwesend`, `gleitzeit` und `sonstig` ergeben 0.
- Prüfungen/Verwaltung: `renderWochenpruefung` (16499), `_computeZeitAnomalien` (16661), `zuRenderOverview` (17747), `zuRenderMonteurView` (17815) und `zuRenderTagView` (17892) verwenden dieselbe Tages-/Istregel, einschließlich Sonntag und virtueller Feiertage.
- Auswertungen/Exports: Mitarbeiterberichte `renderMitarbeiterContent` (23803), `exportAuswertungMitarbeiterCSV` (23876), `printAuswertungMitarbeiter` (23963) und `drawMitarbeiterCharts` (24056) sowie Zeiterfassungs-Excel/PDF und Jahresübersichten rechnen Urlaub/Krank, Custom-Typen und Feiertage anhand der aktuellen Konfiguration neu.
- Null-Fallbacks `parseFloat(value) || 8` in Soll-Schreibpfaden entfernt bzw. durch explizite Leer-/NaN-Behandlung ersetzt. Keine Testdateien angelegt.

**Prüfungen:** `node --check public/script.js` erfolgreich; `node --check public/sollzeit.js` erfolgreich; gezielte Node-Laufzeitprobe für Sonntag, Altmodus-0/Fallback, Wochentagsmodus, Urlaub und Custom-Typen erfolgreich; `node scripts/check-versions.mjs --strict` erfolgreich; `phpstan analyse --memory-limit=2G` erfolgreich (`[OK] No errors`); Editor-Diagnostik für `script.js`, `sollzeit.js` und `index.html` ohne Befunde. PHPUnit wurde gemäß Vorgabe „Keine Gesamttestläufe“ nicht ausgeführt.

### Runde 2b – Mobile + Build

- `public/mobile.html`: `sollzeit.js?v=230` vor dem Inline-Skript eingebunden. Die vollständige Antwort von `get_user_sollstunden` bleibt in `seMobileUser`; die `users`-Objekte aus `get_sollstunden_extended` werden unverändert gehalten. Tages-/Monatssoll, Ist-Summen, Gleitzeitsaldo, virtuelle Feiertage, Erfassung, Admin-Übersicht, Tagesdetails und PDF-Auswertungen verwenden `bkTagesSoll`/`bkIstStundenEintrag`. Urlaubs-/Krank-Zeiträume folgen den konfigurierten Soll-Tagen; Sonntag wird durch den Helper als ISO-7 behandelt. Soll-Setter bewahren 0.
- `public/mobile_light.html`: denselben Helper vor dem Inline-Skript eingebunden und vollständige flache API-Antwort im User-Objekt behalten. Tagesanzeige, Urlaubs-/Krankgutschriften und Tages-Ist nutzen die zentralen Funktionen; feste Mo–Fr-Gutschrift und -Zeitraumauswahl entfernt.
- `public/sw.js`: `sollzeit.js?v=230` in den Precache aufgenommen, `script.min.js?v=230` eingetragen und `CACHE_VERSION` von `bk-es-v228` auf `bk-es-v229` erhöht. `public/index.html` lädt das neu erzeugte `script.min.js?v=230`; `public/sollzeit.js` bleibt bei `?v=230`.
- `npm.cmd run minify` erfolgreich ausgeführt; `public/script.min.js` ist generiert. Keine Tests geschrieben oder geändert.

**Prüfungen:** `node --check public/sollzeit.js` erfolgreich; Inline-Skripte beider Mobilseiten mit UTF-8-Erhalt per `node --check` erfolgreich; `node scripts/check-versions.mjs --strict` erfolgreich. Laufzeitprobe für ISO-Sonntag, Samstagssoll, Abwesenheitsgutschrift, `sonstig` = 0 und gespeicherte 0 erfolgreich. `phpstan analyse --memory-limit=2G` erfolgreich (`[OK] No errors`). Unit-Suite: 271 Tests, 703 Assertions, 1 übersprungen, Lauf erfolgreich.

**Offen / Abweichung:** In den beiden Mobilseiten war keine eigenständige Wochen-Soll-Berechnung oder Wochen-Soll-Ausgabe vorhanden; in dieser Runde wurde deshalb keine neue Wochenansicht ergänzt. Diese Planlücke ist zur fachlichen Einordnung an Leitstand/Test übergeben. Weitere Abweichungen vom freigegebenen Mobile-/Build-Umfang bestehen nicht.

### Runde 2 – Review-Befunde

- `cron_stundenauswertung_email.php`: An Feiertagen werden `feiertag`, `urlaub`, `krank` und passende Custom-Typen mit `istGleichSoll` nicht zusätzlich zum Ist addiert, da Feiertage bereits aus dem Monatssoll ausgeschlossen sind. Erfasste Arbeitsstunden bleiben an Feiertagen enthalten; die Anzeigezähler für Urlaub/Krank bleiben unverändert.
- `public/script.js`: Die nur deklarierte und gesetzte, nirgends gelesene Variable `seDesktopArbeitstage` entfernt.
- `public/script.min.js` mit `npm.cmd run minify` neu erzeugt. Versionsparameter `?v=230` und `CACHE_VERSION=bk-es-v229` unverändert gelassen, da diese Werte in diesem AP noch unveröffentlicht sind.

**Prüfungen:** PHP-Lint für `cron_stundenauswertung_email.php` und `node --check public/script.js` erfolgreich; `npm.cmd run minify` erfolgreich; `node scripts/check-versions.mjs --strict` erfolgreich; `php vendor/bin/phpstan analyse --memory-limit=2G` erfolgreich (`[OK] No errors`); Unit-Suite erfolgreich (274 Tests, 728 Assertions, 1 übersprungen).

**Abweichungen/offen:** Keine Tests geschrieben oder geändert. Der separate PostgreSQL-Hinweis aus Review-Runde 1 wurde gemäß Begrenzung auf die zwei beauftragten Befunde nicht bearbeitet und bleibt offen.

## 4. Tests

### Runde 1 – Tester

- Neu: `tests/Unit/SollzeitTest.php` deckt Altmodus/Fallbacks, Soll je ISO-Wochentag, ungültige Datumswerte und die G1-Ist-Regeln einschließlich Custom- und unbekannter Typen ab.
- Neu: `tests/Api/SollzeitProfilTest.php` deckt Profil-Roundtrip (0, 24 und Dezimalwert), Ablehnung von -1/24.5/"abc" ohne Änderung, fehlende Admin-Rechte sowie den Jahreswechsel-Saldo 2025 mit Urlaub- und Abwesend-Eintrag ab.
- `arbeitstage` als PHP-Liste: keine entsprechende Aufrufstelle in `tests/` gefunden; die API speichert `arbeitstage` kommasepariert. Daher kein Listen-Test ergänzt.
- Backup-Fixtures: `BackupFixtureImportTest` entdeckt `v*.zip` per Glob; beide Fixtures `v2.10.99.zip` und `v3.0.8.zip` sowie ihre Expected-Dateien liegen vor. `tests/Api/BackupImportTest.php` wurde wegen fremder gestagter Änderungen nicht bearbeitet.
- Gezielte Unit-Filterläufe: `--filter SollzeitTest` grün (3 Tests, 25 Assertions).
- Gezielte API-Filterläufe auf SQLite: `--filter SollzeitProfilTest` grün (4 Tests, 53 Assertions); `--filter BackupFixtureImportTest` grün (2 Tests, 50 Assertions), somit wurden beide Fixture-Versionen tatsächlich importiert.
- Formatierung: `php vendor/bin/php-cs-fixer check --diff tests/` grün (0 von 37 Dateien zu ändern). Die beiden neuen Testdateien wurden zuvor gezielt formatiert.
- Befund: Der erste API-Lauf scheiterte nur an einer zu strikten Typ-Assertion (`0` wird aus JSON als Integer dekodiert, nicht als Float); die Assertion prüft nun numerische Gleichheit. Danach alle Filter grün.
- Gesamtlauf und PostgreSQL wurden gemäß Auftrag nicht ausgeführt.

## 5. Review

**Urteil:** CHANGES_REQUESTED

| Schwere | Datei:Zeile | Befund | Vorschlag |
|---|---|---|---|
| Major | [cron_stundenauswertung_email.php](../../../cron_stundenauswertung_email.php#L111), [cron_stundenauswertung_email.php](../../../cron_stundenauswertung_email.php#L124) | Das Monatssoll überspringt Feiertage, aber `Sollzeit::istStundenEintrag()` addiert einen echten `feiertag`-Eintrag als Tages-Soll zum Ist. Vor der Änderung ignorierte der Cron `feiertag`-Einträge; jetzt entsteht für jeden solchen Feiertag eine positive Differenz in Höhe des Tages-Solls. Anders als Desktop `calcGleitzeitSaldo`, wo Feiertags-Soll enthalten bleibt und durch echtes/virtuelles Feiertags-Ist neutralisiert wird. | Feiertags-Soll und -Ist im Cron wie im Desktop neutral behandeln; Regressionstest mit echtem Feiertag ergänzen. |
| Minor | [public/script.js](../../../public/script.js#L15614), [public/script.js](../../../public/script.js#L15661) | `seDesktopArbeitstage` wird nur deklariert und gesetzt, aber nicht gelesen; nach Umstellung auf `seDesktopUser` ist es toter Zustand. | Deklaration und Zuweisung entfernen. |
| Hinweis | [AP-20261004-sollzeit-wochentage.md](AP-20261004-sollzeit-wochentage.md#L154) | PostgreSQL wurde laut AP nicht ausgeführt. Die camelCase-Übersetzung ist laut `docs/entwicklung.md` und `PgsqlDialect` passend, aber der PostgreSQL-Abnahmepunkt bleibt unbestätigt. | PostgreSQL-Migration und gezielte Tests auf dem vorgesehenen Wegwerf-Container ausführen. |

**Lernpunkt-Kandidaten**

| Kategorie | Kandidat | E-Nr. |
|---|---|---|
| Planung / Tests | G1-Ist-Regel und Feiertagsabzug vom Monatssoll müssen gemeinsam als Netto-Regel festgelegt und mit echtem sowie virtuellem Feiertag getestet werden. | Keine; E-070 deckt nur die Dokumentation zusätzlicher Testdateien ab. |

### Runde 2

**Urteil:** APPROVE

Keine Befunde. Der Cron überspringt an Feiertagen Soll-gutschreibende Einträge (`feiertag`, `urlaub`, `krank` und passende Custom-Typen), während er erfasste Arbeitsstunden und die Urlaub-/Krankzähler beibehält. `seDesktopArbeitstage` ist aus `public/script.js` entfernt. Im geprüften Umfang ist keine neue Regression erkennbar.

## 6. Lernpunkte

- Gesamtlauf SQLite (Leitstand): 466 Tests OK, 2 übersprungen. PostgreSQL läuft in der CI (lokal kein Docker).
- E-041 (zentrale Soll-/Ist-Funktion Backend + Browser), E-080 (Gutschriften nur an Soll-Tagen).
- E-072 zum 2. Mal (Architekt-Befund „Hoch“ zu `getDay()` widerlegt) → Regel in `ki-leitstand.agent.md` übernommen.

# Lernprotokoll

Rohbefunde und Kennzahlen. Jeder gelernte Punkt wird als Eintrag in [erkenntnisse.md](erkenntnisse.md)
festgehalten. Tritt eine **Ursache zum zweiten Mal** auf, wird die Erkenntnis zusätzlich als Regel in die
passende `.md`-Datei übernommen (siehe `.github/instructions/ki-dateien.instructions.md`).

## Kennzahlen

| Zeitraum | Arbeitspakete | Ø Review-Runden | Erster Testlauf grün | Eskalationen | Zeilen Ebene 0–1 |
|---|---|---|---|---|---|
| – | 0 | – | – | 0 | – |

## Einträge

Kategorien: SQL · Sicherheit · Backup · Migration · Tests · Frontend · Struktur · Planung · Werkzeuge

| Datum | AP | Kategorie | Befund | Ursache | Anzahl | Erkenntnis (E-Nr) |
|---|---|---|---|---|---|---|
| 2026-09-29 | sicherheit | Sicherheit | Logo-Pfad aus Einstellung ungeprüft gelesen | Einstellungswert als Dateipfad | 1 | E-012 |
| 2026-09-29 | sicherheit | Sicherheit | `check` gab Einstellungen an Anonyme | fehlender Filter für nicht angemeldete Aufrufer | 1 | E-013 |
| 2026-09-29 | sicherheit | Tests | Folgetest sah alte Daten | `resetData()` verschluckt Fehler | 1 | E-031 |
| 2026-09-29 | sicherheit | Werkzeuge | Tester-Subagent ohne Rückgabe bei Gesamtlauf | langer synchroner Lauf | 1 | E-032 |
| 2026-09-29 | sicherheit | Werkzeuge | PG-Testskript lief trotz belegtem Port weiter (rechtzeitig abgebrochen) | fehlende Abbruchprüfung | 1 | E-064 |
| 2026-09-29 | sicherheit | Planung | Testdatei nicht im AP dokumentiert | – | 1 | E-070 |
| 2026-09-29 | kupferpreis | Planung | Tester legte Produktivcode an, Lauf brach ohne Bericht ab | Rollengrenze nur per Anweisung | 1 | E-071 |
| 2026-09-29 | kupferpreis | Werkzeuge | Tester/Entwickler ohne Rückgabe | lange Terminal-Läufe im Subagenten | 2 | E-032 |
| 2026-09-29 | kupferpreis | SQL | Fehlschlag konnte manuellen Wert überschreiben (Major) | Read-Modify-Write ohne Bedingung | 1 | E-003 |
| 2026-09-29 | kupferpreis | Sicherheit | Exception-Text in `fetchError`; `force` per GET | – | 1 | E-014, E-015 |
| 2026-09-29 | kupferpreis | Tests | Plausibilitätstest ersetzte alle Zeilen | – | 1 | E-033 |
| 2026-09-29 | kupferpreis | Werkzeuge | PostgreSQL-Lauf nicht möglich (Docker-Dienst beendet) | Umgebung | 1 | E-064 |
| 2026-10-01 | projektansicht | Werkzeuge | Tester-Lauf ohne Rückgabe (nur Generator angelegt) | zu großer Teilauftrag | 3 | E-032, E-072 |
| 2026-10-01 | projektansicht | Planung | Tester meldete Löschung, Reviewer schrieb nicht ins AP | Subagent-Angaben ungeprüft | 1 | E-072 |
| 2026-10-01 | projektansicht | Tests | Erkennung aus Stichprobe (Major) | fehlende Randfall-Tests | 1 | E-035 |
| 2026-10-01 | projektansicht | Frontend | Filtersumme/Zuklappzustand nach Inline-Änderung veraltet | – | 1 | – |
| 2026-10-01 | update S1 | Sicherheit | Update-Knopf sendete immer `force` (Major) | UI-Pfad nicht abgenommen | 1 | E-016 |
| 2026-10-01 | update S1 | Planung | Docker-Anleitung aktualisierte die App nicht (Major) | `build:` statt `image:` übersehen | 1 | – |
| 2026-10-01 | update S1 | Werkzeuge | CLI als root erzeugt root-eigene Cache-Datei | `exec` ohne `-u www-data` | 1 | E-065 |
| 2026-10-01 | update S1 | Tests | Zusatzmethode ungetestet | – | 1 | E-074 |
| 2026-10-01 | update S1 | Werkzeuge | Custom Agent „Tester“ nicht gefunden | Registry | 1 | E-066 |
| 2026-10-01 | update S2 | Sicherheit | Fremder Registry-Namensraum als Standard-Image (Blocker) | Platzhalter `local` | 1 | E-017 |
| 2026-10-01 | update S2 | Sicherheit | Symlink-Angriffe auf root-Sidecar (2× Major) | gemeinsames Austauschverzeichnis | 2 | E-018 |
| 2026-10-01 | update S2 | Planung | Polling nach Container-Tausch ohne Session, din1090_api ohne Wartung | Neustart/Einstiegspunkte nicht geplant | 1 | E-075 |
| 2026-10-01 | update S2 | Werkzeuge | Entwickler-Lauf ohne Bericht, Testdateien im Projektwurzelverzeichnis | großer Auftrag | 4 | E-032, E-072 |
| 2026-10-01 | release 3.0.8 | Struktur | `install.sh` nach `git clone` nicht ausführbar | Commit unter Windows mit Modus 100644 | 1 | E-078 |
| 2026-10-01 | release 3.0.8 | Struktur | 2. Instanz: Updater-Container-Name kollidiert | Installer setzte `UPDATER_NAME` nicht | 1 | E-079 |
| 2026-10-04 | sollzeit-wochentage | Planung | Architekt meldete „Hoch“-Befund (getDay-Versatz), widerlegt durch Leitstand | Subagent-Befund ungeprüft | 2 | E-072 |
| 2026-10-04 | sollzeit-wochentage | Planung | Monats-Mail: Feiertag doppelt als Ist (Major) | Soll und Ist aus unterschiedlicher Logik | 1 | E-080 |
| 2026-10-04 | sollzeit-wochentage | Tests | API-Test zu strikt auf JSON-Typ von 0 (1 roter Lauf) | – | 1 | – |
| 2026-10-04 | anleitung-zeiterfassung | Planung | Anleitung beschrieb ZE003 als Speicher-Blocker (Major) | nur Katalog statt aktivem Pfad geprüft | 1 | E-081 |
| 2026-10-04 | anleitung-zeiterfassung | Werkzeuge | PG-Testskript brach unter PowerShell 5.1 ab (`Stop` + stderr von docker) | NativeCommandError | 1 | E-082 |
| 2026-10-07 | zeiterfassung-abgleich | Planung | Stunden-Erinnerung seit DB-Umstellung wirkungslos, Anleitung beschrieb sie als aktiv (Hoch) | Cron-Einstiegspunkte bei Datenquellen-Umstellung nicht geprüft | 1 | E-083 |
| 2026-10-07 | zeiterfassung-abgleich | Frontend | Monats-Mail ohne betriebliche Feiertage; Wochenplanung ohne Wochentags-Soll (3.0.11) | duplizierte Feiertags-/Soll-Logik | 2 | E-041 → Instructions |
| 2026-10-07 | zeiterfassung-abgleich | Planung | 5 Explore-Befunde „Hoch/Mittel“ widerlegt (betriebliche Feiertage, Legacy-Typen, Cron-Typen) | Subagent-Befund ungeprüft | 3 | E-072 |
| 2026-10-07 | zeiterfassung-abgleich | Planung | Ungeplanter Benutzerfilter in der Erinnerung (Major) | Vertragsabweichung nicht gemeldet | 1 | E-084 |
| 2026-10-07 | zeiterfassung-abgleich | Planung | Übernommene Doku-Cronzeit passte nicht zur Vortagslogik (Major) | Doku-Aussage nicht gegen aktiven Pfad geprüft | 2 | E-081 |
| 2026-10-07 | zeiterfassung-abgleich | Tests | PHP↔JS-Parität ungetestet | fehlende JS-Testumgebung | 1 | E-036 |
| 2026-10-11 | projektbuchung-abgleich | Planung | 8 Schreibpfade ohne gemeinsamen Abgleich Zeiterfassung ↔ Projekt (Admin, „Jetzt importieren“, Mobil-Löschen, Reparatur, Verschieben; Hoch) | Schreib-/Einstiegspunkte nicht inventarisiert | 2 | E-086 → Instructions |
| 2026-10-11 | projektbuchung-abgleich | Frontend | Revision ohne zugehörige Daten übernommen (`dataRev`, `zeitRev`; 2× Major im Review) | blinde Übernahme serverseitiger Revisionen | 2 | E-085 → Instructions |
| 2026-10-11 | projektbuchung-abgleich | Tests | Testaufbau: Projekt-Speichern ohne `baseRev` → 409 | – | 1 | – |
| 2026-10-11 | projektbuchung-abgleich | Werkzeuge | Docker Desktop zwischen Läufen beendet, PG-Lauf neu gestartet | Umgebung | 1 | E-082 |
| 2026-10-11 | projektbuchung-abgleich (3.0.16) | Werkzeuge | Quelldatei doppelt UTF-8-kodiert veröffentlicht (Major) | PowerShell 5.1 `Get-Content -Raw` + `WriteAllText` beim Rot-Nachweis | 1 | E-087 |
| 2026-10-11 | projektbuchung-abgleich (3.0.16) | Planung | Datenverlust-Nachprüfung: Standardkategorie preiste Altbuchungen um, Handpreis überschrieben, Verschieben mit Doppelbuchungs-Risiko (3× Major) | Kategorie-/Preis-Folge und Zwei-Request-Verschieben nicht gegen Handänderungen und Teilfehler geplant | 1 | – (über E-086 abgedeckt) |
| 2026-10-12 | projektbuchung-abgleich (3.0.17) | Planung | Update laufender Installation: alte Clients überschrieben serverseitig geänderte Buchungen (Major) | Kompatibilität mit Clients der Vorversion nicht geprüft | 1 | E-088 |
| 2026-10-16 | datensicherung-nas T2 (3.0.20) | Backup | Planer wertete belegte Sicherungssperre (`gesperrt`) als Erfolg, Tageslauf `ok` ohne Sicherung (Blocker, Leitstand + Review, 1 roter Test) | Rückgabewert „nicht ausgeführt“ wie Erfolg behandelt | 1 | E-021 |
| 2026-10-16 | datensicherung-nas T2 (3.0.20) | Backup | Protokollfehler nach erfolgreichem Mailversand → `exit(1)`, keine Sperrdatei → doppelter Versand möglich (Major) | Nebenwirkung (Protokoll) im kritischen Pfad | 1 | E-022 |
| 2026-10-16 | datensicherung-nas T2 (3.0.20) | Planung | Laufende manuelle Aufträge ohne Abbruch-Erkennung (Major) | Crash-Recovery nur für `wartend` geplant | 1 | E-075 verwandt |
| 2026-11-01 | datensicherung-nas T3 | Backup | Outbox: erst senden, dann bedingt markieren → Doppelzustellung durch Planer und Watchdog möglich (Major R1/1, vom Leitstand unabhängig gefunden) | Claim vor langsamem Aufruf fehlte | 1 | E-003 (Erweiterung Kandidat) |
| 2026-11-01 | datensicherung-nas T3 | Planung | Zeitbudget nur je Eintrag statt je Empfänger; Watchdog im Healthcheck bis ≫ 5 s (Major R2/N1) | Budget an der äußeren statt an der innersten Schleife | 1 | – (Kandidat) |
| 2026-11-01 | datensicherung-nas T3 | Planung | Dauerbanner durch nie mehr versuchte Einträge > 7 Tage (Major R1/2) | UI-Hinweis aus nicht behebbarem Zustand abgeleitet | 1 | – (Kandidat) |
| 2026-11-01 | datensicherung-nas T3 | Werkzeuge | Leitstand-Hilfsskript per `Set-Content -Encoding ASCII` → `?` statt Umlauten im AP (vor dem Commit korrigiert) | PowerShell-5.1-Kodierung | 2 | E-087 → AGENTS.md |
| 2026-11-01 | datensicherung-nas T4 | Tests | `proc_close()` liefert auf PHP < 8.3 nach `proc_get_status()` den Wert −1 → jeder rclone-Aufruf „fehlgeschlagen“ (Major); lokal (Windows) und im 8.3-Image unsichtbar, im Container `php:8.2-cli` reproduziert | Prozess-Code nur auf der Entwicklungsplattform getestet | 1 | E-067 |
| 2026-11-01 | datensicherung-nas 3.0.23 | Tests | CI nach Release rot: NAS-Test legte Ordner neben dem Repository an, auf dem Runner unter `/home` (gesperrt); lokal und Docker unter `/app` grün → Folge-Patch 3.0.24 nach Rückfrage (Schleifenbremse) | Testpfad-Annahme nur auf der Entwicklungsplattform geprüft | 2 | E-067 → tests.instructions.md |
| 2026-11-01 | datensicherung-nas T4 | Sicherheit | Ausgelagerter Pfad als Vorfahr von `data/` bzw. Systempfad → Geheimnisse auf die NAS (Major) | admin-steuerbarer Quellpfad ohne Vorfahren-Sperre | 1 | E-026 |
| 2026-11-01 | datensicherung-nas T4 | Planung | Leitstand-Vorgabe `**/secret.key` griff in rclone nicht im Wurzelordner (Major) | Filtersemantik nicht gegen echtes Werkzeug geprüft | 1 | E-026 |
| 2026-11-01 | datensicherung-nas T4 | Planung | Manueller Lauf überschrieb/setzte den Tageslauf → automatischer Lauf entfiel (Major, 2 Runden) | Zustandsfelder auch aus Nicht-Tageslauf-Pfaden geschrieben | 2 | E-027 → php-backend.instructions.md |
| 2026-11-01 | B1 gesamtuebersicht-filter | Frontend | Unterprojekte bei String-`parentId` doppelt summiert (Major, Runde 1) | typstrenger ID-Vergleich DOM ↔ Daten | 1 | E-096 |
| 2026-11-01 | B1 stundenerfassung-bearbeiten | Planung | Plan nahm an, der Desktop könne gar nicht bearbeiten; die Stundenauswertung konnte es schon, nur die eigene Erfassung nicht – vor der Umsetzung mit dem Nutzer geklärt | Architekt-Befund nicht an der Fundstelle geprüft | 3 | E-072 |
| 2026-11-01 | B1 | Werkzeuge | Entwickler-Abbruch (402) mit halbfertigem Stand; GPT-5.5 nicht verfügbar; Modell-Selbstauskunft widersprüchlich; Reviewer schrieb Runde 1 nicht ins AP | Umgebung / Subagent-Zuverlässigkeit | 1 | E-097, E-072 |
| 2026-11-19 | testabdeckung-klicktests B0 | Sicherheit | `@mkdir()` im neuen Coverage-Prepend (Blocker, Runde 1) | `@`-Operator trotz Regel neu eingeführt | 1 | E-063 |
| 2026-11-19 | testabdeckung-klicktests B0 | Werkzeuge | Makefile-`test-cov` prüfte Coverage-Treiber nur isoliert, übergab das Flag nicht an den eigentlichen PHPUnit-Lauf → leere Coverage trotz grünem Check (Blocker, 2 Runden: Runde 2 Check fehlte ganz, Runde 3 Flag fehlte am Hauptaufruf) | Windows-`.ps1` und Linux-`Makefile`-Äquivalent nicht 1:1 abgeglichen | 1 | E-098 |
| 2026-11-19 | sammel-aenderungen B1 | Tests | Statische CSS-/HTML-Strukturtests koppeln an exakte Werte (45 %, 160px) und Deklarationsreihenfolge – brüchig bei kosmetischem Tuning (Hinweis Reviewer) | Testaussage an Implementierungsdetails statt an Struktur ausgerichtet | 1 | – (Kandidat) |
| 2026-11-19 | sammel-aenderungen B1 | Frontend | Eingeklappter Sidebar-Bereich behielt `flex:1`/`min-height` → Leerraum unter dem Toggle (Minor, Korrekturrunde 2) | Collapse-Zustand bei neuer Flex-/min-height-Konstruktion nicht mit abgenommen | 1 | – (Kandidat) |
| 2026-11-19 | sammel-aenderungen B2a | Sicherheit | Formular-IDOR `renderBelegPdf` und `generate_zugferd` ohne Sichtbarkeitsprüfung (2× Major R1); Typwechsel Angebot↔Rechnung per `save_rechnung` (Minor R2, vorbestehend) | Objektrechte am Request statt am gespeicherten Datensatz geprüft; nur die in der Nutzerfrage genannten Zugriffswege bedacht | 2 | E-099 |
| 2026-11-19 | sammel-aenderungen B2a | Sicherheit | Korrektur ersetzte in `kannSchnellnotizSehen()` einen Freigabezweig per `return false` → zugewiesene Notizen nicht mehr bearbeitbar (Minor R2, Regression) | Zusatzbedingung in eine „A oder B oder C“-Kette als Ersatz statt als weitere Bedingung eingefügt | 1 | – (Kandidat) |
| 2026-11-19 | sammel-aenderungen B2a | Werkzeuge | Commit-Nachricht über PowerShell-Here-String → `node -` gepiped: Umlaute als `?` committet (vor Push per `--amend` korrigiert) | PowerShell 5.1 kodiert Pipes zu nativen Programmen als ASCII | 3 | E-087 (bereits AGENTS.md; gilt auch für Pipes an `node -`) |
| 2026-11-26 | lexoffice-schnittstelle (Planung) | Planung | Nutzervorgabe: künftig alles tokensparend; Tests und Prüfläufe sollen keine Tokens mehr verbrauchen | Prüfläufe/Tests wurden von Subagenten gestartet, Logs gelesen und Tests einzeln angepasst; kein einzelner Prüfaufruf mit Kurzausgabe | 1 | E-037, E-100 |
| 2026-11-26 | lexoffice-schnittstelle (Planung) | Planung | Rechnungen ohne Leistungsdatum (Pflichtangabe § 14 UStG) erst bei Schnittstellenplanung bemerkt | Pflichtfelder externer Zielsysteme nicht gegen das eigene Datenmodell geprüft | 1 | – (Kandidat) |
| 2026-11-26 | lexoffice-schnittstelle (Planung) | Planung | Nutzerwunsch „Abschläge nach Lexware übertragen“: Abschlags- und Schlussrechnungen sind per API nur lesbar – vor dem Plan an der Doku geprüft, Lösung über Angebot + Rückspiegel | Schreibbarkeit je Objekt einer Fremd-API nicht selbstverständlich | 1 | E-095 (bestätigt: Fakten externer Werkzeuge vor dem Vertrag prüfen) |
| 2026-11-26 | lexoffice-schnittstelle (Planung) | Werkzeuge | Node-Hilfsskript per Here-String-Pipe mit Umlaut im Regex → Syntaxfehler, nichts geschrieben; mit ASCII-Escapes wiederholt | PowerShell 5.1 kodiert Pipes an `node -` als ASCII | 4 | E-087 (Hilfsskripte nur ASCII bzw. `\u`-Escapes) |
| 2026-11-26 | lexoffice-schnittstelle B1 | Sicherheit | Profil der Verbindungsprüfung blieb nach Schlüsselwechsel stehen → `modus_setzen` mit ungeprüftem Schlüssel möglich (Major R1) | abhängige Einstellung beim Ändern ihrer Grundlage nicht zurückgesetzt | 1 | – (Kandidat) |
| 2026-11-26 | lexoffice-schnittstelle B1 | Backup | `verbindung_testen` schrieb nach dem langsamen Aufruf den Gesamtstand der Einstellungen bzw. das Profil eines inzwischen ersetzten Schlüssels zurück (Minor R1 + Hinweis R2) | ungeprüftes Schreiben nach langsamem externem Aufruf | 2 | E-003 → php-backend.instructions.md |
| 2026-11-26 | lexoffice-schnittstelle B0 | Tests | `pruefen.mjs` wertete nur den Logtext aus – „OK, but there were issues!“ mit Exit 1 wäre GRÜN gewesen (Minor R1) | Exit-Code des Werkzeugs nicht ausgewertet | 1 | – (Kandidat) |
| 2026-11-26 | lexoffice-schnittstelle B1 | Werkzeuge | Docker Desktop lief nicht, PG-Lauf zunächst rot; gestartet, nur PG-Teil wiederholt | Umgebung | 1 | E-092 |

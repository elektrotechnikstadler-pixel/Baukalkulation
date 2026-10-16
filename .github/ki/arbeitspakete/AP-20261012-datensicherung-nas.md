# AP-20261012-datensicherung-nas: Datensicherung, NAS-Abgleich, Überwachung, Verwaltung gliedern, PostgreSQL-Bereich

| Feld | Wert |
|---|---|
| Status | in Umsetzung (T1 → 3.0.19, T2 → 3.0.20 abgeschlossen; T3–T5 offen, T3 auf Wunsch später) |
| Typ | Feature |
| Testläufe rot | T2: 1 (Sperre ≠ Erfolg, Leitstand-Befund) |
| Review-Runden | T1: 1 · T2: 2 |
| Commit | – |

Voraussetzung: AP-20261012-ki-modelle-tests ist abgeschlossen (Modelle je Rolle, Gesamtlauf inkl. `test:js`).

## 1. Auftrag

Wörtlich (2026-10-12, sinngemäß geglättet):

> Aktuell läuft ein manuell eingestellter Cron-Job, der einmal täglich ein Backup bzw. eine Synchronisation
> des Ordners `data` auf eine NAS macht. Diese Einstellungen sollen über **Verwaltung allgemein** möglich sein;
> dabei Verwaltung allgemein in **Menüpunkte gliedern** und übersichtlicher darstellen.
> Das Backup soll **überwacht** werden, bei fehlerhaften Versuchen wird eine **Fehler-Mail** verschickt.
> Empfehlungen planen und Fragen zur Umsetzung klären.
> Wenn möglich in Verwaltung allgemein auch die Erweiterung/Aktivierung auf **PostgreSQL** anzeigen und die Umsetzung ermöglichen.

## 2. Plan

### 2.1 Ist-Analyse (Stand 3.0.17)

| Bereich | Ist | Fundstelle |
|---|---|---|
| NAS-Abgleich | Läuft **außerhalb der App**: Crontab auf dem Docker-Host, `rsync` von `data/` auf eine per SMB/CIFS (Benutzer, Passwort, Pfad) eingehängte Freigabe. In der App kein rsync/SMB/NAS-Sync. | Nutzerangabe; grep `rsync|smb|cifs` in `src/` leer |
| Tägliche Serversicherung | Format-2-Ordner `BACKUP_DIR/JJJJ-MM-TT/` (manifest + JSON + SQLite-Snapshot, auch bei PostgreSQL). Wird beim **ersten Speichern des Tages** ausgelöst → an Tagen ohne Speichern keine Sicherung. Aufbewahrung fest 7 (`BACKUP_MAX`). Fehler nur `error_log()`. | `src/DataService.php` `createDailyBackup()`, `src/Handlers/DataActions.php` (Aufruf nach Speichern), `public/api.php` `BACKUP_MAX` |
| Inhalt Sicherung | Nur DB + JSON, **keine** Dateien (Uploads, Bautagebuch, Datanorm, Archiv, Exporte) | `src/Backup/BackupWriter.php` |
| Backup-Mail | `cron_backup_email.php` alle 15 min, Zyklus/Uhrzeit/Empfänger/Passwort/Max-MB aus `erinnerung_settings`; Fehler nur Log (`data/erinnerung.log`, `data/cron.log`) + Exit 1 | `cron_backup_email.php`, `Dockerfile.app` (Crontab) |
| Überwachung | **Keine**: kein Laufprotokoll, kein „letzter Erfolg“, keine Fehler-Mail, keine Anzeige | – |
| Mail | `MailService::send()` wirft `RuntimeException`; SMTP in `settings` (Passwort via `SecretBox`) | `src/Services/MailService.php`, `src/Auth.php` |
| Verwaltung allgemein | Eine lange Seite mit > 12 Karten, dynamisch in `renderAllgemeinSettings()`; daneben eigene Header-Punkte „Speicherpfade“, „Update & Systeminfo“ | `public/script.js` `showAllgemeinSettings()` / `renderAllgemeinSettings()`, `public/index.html` Header „Verwaltung“ |
| PostgreSQL | Technisch fertig: `db:transfer to-pgsql/to-sqlite`, `docker-compose.postgres.yml` (+ `db-backup` mit `pg_dump`). Treiber nur per Env `BK_DB_DRIVER`; Umstellung braucht Host (Secret-Datei, 2. Compose-Datei, Container neu). UI zeigt nur „DB-Treiber“ in Systeminfo. | `src/Database/ConnectionConfig.php`, `src/Console/Command/DbTransferCommand.php`, `docs/installation.md` |
| Geheimnis-Schlüssel | `data/secret.key` (oder Env `BK_SECRET_KEY`) – liegt im zu synchronisierenden Ordner | `src/Services/SecretBox.php` |
| Privilegierte Host-Aktionen | Nur über Updater-Sidecar mit Auftragsdatei `data/update/anforderung/` (E-018) | `src/Services/UpdateAuftrag.php`, `updater/updater.sh` |

### 2.2 Entscheidungen des Nutzers (Rückfragen 2026-10-12)

| # | Frage | Entscheidung |
|---|---|---|
| F1 | Wie läuft der Host-Job? | Host-Crontab, rsync auf SMB/CIFS-Freigabe mit Benutzer/Passwort/Pfad |
| F2 | Technik künftig | **rclone in der App** (SMB im Userspace, ohne Mount/Root), alles in Verwaltung allgemein, Container-Cron führt aus, Host-Cron entfällt |
| F3 | Aufbau auf der NAS | **Nur hinzufügen/aktualisieren**, auf der NAS wird nie gelöscht |
| F4 | Wann Fehler-Mail | Bei jedem Fehlschlag **und** bei Ausbleiben (> 26 h kein Erfolg), plus **Entwarnung**; für NAS-Sync, tägliche Serversicherung und Backup-Mail |
| F5 | Empfänger | Eigene Liste „Empfänger Systemmeldungen“ (Standard: E-Mail aller Admins) + roter Hinweis für Admins in der App |
| F6 | Gliederung | **Linke Unternavigation** mit Gruppen, Suchfeld, letzter Bereich gemerkt, mobil Dropdown |
| F7 | PostgreSQL | **Stufe 1 jetzt** (Status, Voraussetzungen, Testverbindung, Probe-Transfer, Befehls-Assistent); Stufe 2 (Umstellung per Knopf über Updater-Sidecar) = eigenes späteres AP |
| F8 | Serversicherung zeitgesteuert | **Ja**, per Container-Cron zu einstellbarer Uhrzeit (Speicher-Auslösung bleibt Rückfall), Aufbewahrung 7–90 Tage einstellbar; NAS-Sync direkt danach |
| F9 | `secret.key` auf NAS | **Nein**; Hinweis/Export in der UI zur separaten Aufbewahrung |
| F10 | „Bei jedem Fehlschlag“ genau | **Ein Tageslauf je Job mit bis zu 3 Wiederholungen (alle 30 min)**; erst wenn alle scheitern: Fehler-Mail für diesen Tageslauf; nächster Tag erneut Fehlschlag → erneut Mail; Erfolg → Entwarnung. Höchstens 1 Fehler-Mail je Job und Tag. |
| – | KI-Modelle | siehe AP-20261012-ki-modelle-tests |

### 2.3 Ziel & Abgrenzung

**Ziel:** Ein in der App eingestellter, protokollierter und überwachter täglicher Ablauf „Serversicherung → NAS-Abgleich“,
Fehler-/Ausbleiben-/Entwarnungs-Mails, übersichtlich gegliederte Verwaltung, PostgreSQL-Bereich (Stufe 1).

**Nicht Teil dieses AP:**
- PostgreSQL-Umstellung per Knopf (Stufe 2, Folge-AP `AP-…-pgsql-umstellung`).
- Umbau von `cron_material_erinnerung.php`, `zeit:erinnerung`, `cron_stundenauswertung_email.php` auf den neuen Planer (nur Backup-Mail wird protokolliert).
- Externe Überwachung außerhalb des Containers (siehe Empfehlung E6).
- Löschen auf der NAS / Aufräumen alter NAS-Stände (F3).

### 2.4 Lösungsentwurf

#### A. Verwaltung allgemein gliedern (rein Frontend, kein Verhaltenswechsel)

- `renderAllgemeinSettings()` wird in Bereichs-Renderer zerlegt; Layout: links Unternavigation, rechts Inhalt.
- Bereiche (IDs stabil, für Deep-Link `#verwaltung/<id>` und `localStorage` `bk.verwaltung.bereich`):

  | ID | Menüpunkt | Inhalt (bestehende Karten) |
  |---|---|---|
  | `firma` | Firma & Branding | Firmendaten, Logo, Branding |
  | `module` | Module & Lizenz | Module ein/aus, Lizenz |
  | `anmeldung` | Anmeldung & Startseiten | Automatische Abmeldung, Startseite, Mobile Startseite |
  | `nummern` | Kunden- & Personalnummern | Kundennummern, Personalnummern |
  | `baustellen` | Baustellen & Bereiche | Baustellen – Bereiche |
  | `zeiterfassung` | Zeiterfassung | Stunden Auto-Import, Erweiterte Zeiterfassung, Erfassungstypen, Wochenplan-Screen, Erinnerungen, Stundenauswertung |
  | `email` | E-Mail (SMTP) | SMTP-Einstellungen **+ neue Testmail** |
  | `datensicherung` | Datensicherung & NAS | **neu:** Serversicherung (Uhrzeit, Aufbewahrung), NAS-Abgleich; Backup-Mail (verschoben); Speicherpfade (verschoben); Hinweis `secret.key` |
  | `ueberwachung` | Überwachung & Systemmeldungen | **neu:** Status-Ampel je Job, Laufprotokoll, Empfänger, Schwellen |
  | `datenbank` | Datenbank (SQLite/PostgreSQL) | **neu:** siehe E |
  | `system` | System & Update | Update & Systeminfo (verschoben), Schnellnotizen-Verweis |

- Header-Punkte „Speicherpfade“ und „Update & Systeminfo“ bleiben als **Verknüpfung** auf den jeweiligen Bereich (gewohnter Weg bleibt).
- Suchfeld filtert über Karten-Titel und Feldbeschriftungen und springt in den Bereich.
- Mobil (< 768 px): Unternavigation wird `<select>`.
- Alle Element-IDs, `updateSetting(...)`-Aufrufe und API-Aktionen bleiben unverändert (E-088: alte Clients/Server-Pfade unberührt).
- Reine Hilfsfunktionen (Bereichszuordnung, Suche) in `public/verwaltung-nav.js` (testbar per `node:test`, Muster `sollzeit.js`), Einbindung mit `?v=`.

#### B. Job-Grundgerüst: Planer, Protokoll, zeitgesteuerte Serversicherung

- **Migration** `CreateSystemJobTables` (SQLite-Syntax, Dialekt übersetzt):
  - `system_einstellungen` (`id` = 1, `data` JSON) – eigene Tabelle statt `settings`, damit nichts über `check`/`load_settings` an Nicht-Admins gelangt (E-013).
  - `job_status` (`job` PK, `letzterLaufAm`, `letzterErfolgAm`, `letzterFehlerAm`, `fehlerCode`, `stoerungSeit`, `laeuftSeit`, `tageslaufDatum`, `versuche`).
  - `job_laeufe` (`id`, `job`, `gestartetAm`, `beendetAm`, `status` ok|fehler|abgebrochen|nicht_faellig, `fehlerCode`, `kennzahlen` JSON: Dateien/Bytes/Dauer); Aufbewahrung 90 Tage.
  - `job_auftraege` (`id` UUID, `job`, `status` wartend|laeuft|fertig|abgelaufen, `erstelltAm`, `ablaufAm`, `angefordertVon`, `payload` SecretBox-verschlüsselt, `ergebnis` JSON) – manuelle Anforderungen und Probe-Transfer; Claim/Abschluss nur per `UPDATE … WHERE id = ? AND status = ?` + `rowCount()` (E-003); Payload nie über Status-APIs, in `finally` geleert, abgelaufene Aufträge beim Planerstart bereinigt (Review R10).
  - `meldungen_outbox` (`id`, `schluessel` UNIQUE z. B. `nas:2026-10-12:fehler`, `typ` fehler|ausbleiben|entwarnung, `erstelltAm`, `gesendetAm`, `versuche`, `naechsterVersuchAm`) – Ereignis und Störungszustand in **einer** Transaktion schreiben, Zustellung getrennt mit Wiederholung/Backoff; UNIQUE verhindert Doppelmails bei parallelem Planer/Watchdog (Review R7).
  - **Restore-Vertrag (Review R12):** `job_status`, `job_laeufe`, `job_auftraege`, `meldungen_outbox` sind installationslokal → in `Importer::SKIP_TABLES`. `system_einstellungen` wird importiert, **Secrets darin aber verworfen**, wenn sie mit dem vorhandenen Schlüssel nicht entschlüsselbar sind (Hinweis „NAS-Passwort neu eingeben“).
  - Danach Backup-Fixture neu erzeugen (E-020); alte **und** neue Fixture importierbar; Restore in Installation mit anderem `secret.key` getestet.
- **Service** `App\Services\Jobs\JobProtokoll` (start/erfolg/fehler, nur feste Fehlercodes + Kurztext, Details per `error_log()` – E-014).
- **Service** `App\Services\Jobs\SystemEinstellungen` (laden/speichern mit Whitelist, Standardwerte, Secrets via `SecretBox`, Passwort nie zurückgeben – nur `passwortGesetzt: true`).
- **CLI** `bin/console system:planer` (jede Minute per Crontab): exklusive Sperre (`flock` auf `data/run/planer.lock`), führt fällige Jobs nacheinander aus:
  1. `sicherung` – Tagessicherung + Aufräumen nach `aufbewahrungTage`.
  2. `nas` – direkt nach der Sicherung (auch wenn diese scheiterte: Dateien abgleichen).
  3. `ueberwachung` – siehe D; danach Outbox zustellen.
  - Tageslauf-Logik (F10): erster Versuch zur eingestellten Uhrzeit, bei Fehler bis zu 3 Wiederholungen im Abstand von 30 min (`job_status.tageslaufDatum/versuche`); danach Tageslauf „fehlgeschlagen“.
  - Manuelle Anforderung aus der UI („Jetzt ausführen“) legt nur einen `job_auftraege`-Eintrag an (POST, Admin); der Planer übernimmt ihn innerhalb ≤ 1 min → kein langer Webrequest, keine Session nötig (E-075).
- **Tagessicherung robust (Review R3):** eine **gemeinsame Sicherungssperre** (`data/run/sicherung.lock`) für Planer, Speicher-Rückfall (`DataActions`), `backup:create` und Restore-Vorab-Sicherung; Schreiben in ein Staging-Verzeichnis `BACKUP_DIR/.tmp-<zufall>/` auf demselben Dateisystem, danach `BackupArchive::fromDir()`-Prüfung (Manifest + SHA-256), erst dann atomares `rename()` auf `JJJJ-MM-TT`. Ein vorhandener Tagesordner gilt nur nach erfolgreicher Prüfung als Erfolg, sonst Neuaufbau bzw. Störung. Fehler werfen jetzt eine Exception (statt stillem `return`); der Speicher-Rückfall fängt sie, protokolliert sie und lässt das Speichern weiterhin gelingen. Staging-Reste werden beim nächsten Lauf entfernt.
- Einzelbefehle `bin/console sicherung:erstellen --planmaessig`, `nas:abgleich`, `system:ueberwachung` für Tests/Host-Aufruf (Nicht-Docker-Installationen).
- **Crontab** in `Dockerfile.app`, exakt nach Muster der bestehenden Zeilen (Review R1, E-011 – ohne `/etc/baukalkulation.env` fiele der Planer bei PostgreSQL auf SQLite zurück):
  `* * * * * www-data . /etc/baukalkulation.env; php /var/www/html/bin/console system:planer >> /var/www/html/data/cron.log 2>&1`
  + Test, dass der Planer mit `BK_DB_DRIVER=pgsql` dieselbe DB nutzt wie der Webprozess.
- `createDailyBackup()` beim Speichern bleibt als Rückfall (F8); `BACKUP_MAX` wird durch Einstellung ersetzt (Standard 7, Grenzen 3–90). Alle Leser von `BACKUP_MAX` per grep umstellen (`DataActions`, `BackupCreateCommand`, `Console/Kernel`, `api.php`) – E-083/E-086.
- **Backup-Mail (Review R6):** `cron_backup_email.php` meldet über `JobProtokoll` getrennt `deaktiviert` / `nicht_faellig` / `bereits_erledigt` (kein Erfolg, kein Protokolleintrag außer Herzschlag), `gesendet` (nur nach erfolgreichem `MailService::send()` → `letzterErfolgAm`) und `fehler`. Ausbleiben-Frist = Zyklus (Tag/Woche/Monat) + Uhrzeit + Toleranz; deaktivierte Jobs werden nicht überwacht.

#### C. NAS-Abgleich mit rclone (F2, F3, F9)

- **Image:** `rclone` (≥ 1.60, SMB-Backend) in `Dockerfile.app`, Version beim Build prüfen; auch im `minimal`-Build (Größe dokumentieren).
- **Einstellungen** (`system_einstellungen.nas`): `aktiv`, `host`, `port` (445), `freigabe`, `pfad`, `benutzer`, `passwort` (SecretBox), `domaene` (optional), `bandbreiteKBs` (0 = frei).
- **Ausführung** `App\Services\Jobs\NasAbgleich`:
  - Remote „on the fly“: Ziel als **ein** Argument `:smb:<freigabe>/<pfad>/data` (ohne `:smb:` wäre es ein lokaler Containerpfad und würde fälschlich „gelingen“ – Review R4); Aufruf über `proc_open` mit **Argument-Array** (keine Shell). Freigabe/Pfad normalisieren: nur `[A-Za-z0-9 ._\-/]`, keine `..`, kein `:`/`,`/`{}` (keine Remote-/Connection-String-Einschleusung).
  - Zugang per Umgebungsvariablen (`RCLONE_SMB_HOST/USER/PASS/DOMAIN/PORT`), Passwort per `rclone obscure -` über **stdin**, nur im Env-Array → nie in Befehlszeile, Datei oder Log.
  - Befehl `rclone copy` (**nie** `sync`/`move`/`delete` → F3); ausgelagerte Pfade aus `paths_config.json` (außerhalb `data/`) nach `:smb:<freigabe>/<pfad>/extern/<schlüssel>`.
  - **Positivliste statt Ausschlussliste (Review R2):** kopiert werden nur freigegebene Unterordner/Dateien von `data/` (`backups/`, `uploads/`, `bautagebuch/`, `datanorm/`, `archiv/`, `exports/`, `pg-backups/`, `paths_config.json`, Modul-Datenordner laut Inventur). Die Inventur aller Dateien in `data/` ist Schritt 1 von T4 und wird im AP Abschnitt 3 dokumentiert. **Nie** kopiert: `database.sqlite` + `database.sqlite-*` (WAL/SHM/Journal), `secret.key`, `systemadmin-passwort.txt`, `audit_pepper.txt`, `update/`, `run/`, Staging-/Lock-/Temp-Dateien – zusätzlich als root-verankerte Ausschlüsse abgesichert (doppelte Sicherung, Test).
  - `--contimeout 15s --timeout 5m --retries 3`, `--use-json-log --stats-one-line` → Kennzahlen; Gesamtlaufzeit-Grenze (Standard 3 h) mit Abbruch.
  - **Fehlercodes nach Phase statt nach Exit-Code (Review R5):** `NAS_KONFIGURATION` (Pflichtfelder/Validierung), `NAS_RCLONE_FEHLT`, `NAS_VERBINDUNG` (Schreibprobe scheitert), `NAS_TRANSFER` (Transfer mit Fehlern), `NAS_ZEIT` (Höchstdauer). rclone-Ausgabe nur intern loggen, vorher auf Host/Benutzer/Passwort bereinigt.
  - Am Ende Statusdatei `:smb:<freigabe>/<pfad>/_baukalkulation_status.json` (Zeitpunkt, Ergebnis, Version) → NAS-seitige Überwachung möglich (E6).
  - Ausführbarer Pfad injizierbar (E-034) → Tests mit Fake-rclone-Skript.
- **UI** (Bereich Datensicherung & NAS): Felder, „Verbindung testen“ = **Schreibprobe** gegen den konkreten Zielpfad (`rclone mkdir` + Hochladen/Überschreiben von `_baukalkulation_verbindungstest.txt`, max. 20 s, POST, Admin; Datei bleibt wegen F3 liegen), „Jetzt abgleichen“, letzter Lauf/Kennzahlen.
- **secret.key (F9):** Hinweis-Karte „Schlüssel separat aufbewahren“ mit „Schlüssel anzeigen/herunterladen“: nur Admin, **erneute Passwortabfrage**, POST, Audit-Log-Eintrag, nicht bei gesetztem `BK_SECRET_KEY` (dann Hinweis „Schlüssel kommt aus der Umgebung“).
- **Übergang:** Anleitung „Host-Cronjob erst abschalten, wenn der App-Abgleich 3 Tage grün war“ (Bedienungsanleitung + `docs/installation.md`).

#### D. Überwachung & Systemmeldungen (F4, F5)

- `App\Services\Jobs\Ueberwachung` (läuft im Planer jede Minute, billig):
  - Für jeden **aktiven** Job (`sicherung`, `nas`, `backupmail`, `probe_wiederherstellung`) gilt:
    - **Tageslauf fehlgeschlagen** (alle Versuche nach F10 gescheitert) → Outbox-Eintrag `fehler` mit Schlüssel `<job>:<tageslaufDatum>:fehler` → höchstens 1 Fehler-Mail je Job und Tag.
    - **Ausbleiben**: `jetzt − letzterErfolgAm > ausbleibenStunden` (Standard 26; bei `backupmail` aus Zyklus berechnet) und kein Fehler-Ereignis für denselben Tag → Outbox `ausbleiben` mit Schlüssel `<job>:<datum>:ausbleiben` (max. 1 je Tag).
    - **Erster Erfolg nach Störung** (`stoerungSeit` gesetzt) → Outbox `entwarnung`, `stoerungSeit` = NULL – in derselben Transaktion.
  - Schonfrist nach Ersteinrichtung/Aktivierung: Ausbleiben erst ab dem ersten fälligen Lauf.
  - Outbox-Zustellung: `gesendetAm` erst nach erfolgreichem `MailService::send()`, sonst `versuche++` und Backoff (5 min, 30 min, 2 h …); UNIQUE-Schlüssel verhindert Doppelmails bei parallelem Planer/Watchdog (Review R7, Test mit zwei konkurrierenden Prozessen).
- Mail-Inhalt: Job, seit wann, Fehlercode + fester Text, Handlungsempfehlung, Link zur Verwaltung; **keine** Passwörter, Pfade mit Zugangsdaten oder Ausnahmetexte (E-014).
- Empfänger: `ueberwachung.empfaenger[]` (validiert, max. 10); leer → alle aktiven Admins mit E-Mail.
- SMTP selbst defekt → Outbox bleibt offen, Admin-Banner zeigt „Systemmeldungen können nicht zugestellt werden“.
- **Cron steht, Apache läuft (Review R8):** Herzschlag `job_status['planer'].letzterLaufAm`. Der vom Docker-Healthcheck alle 30 s aufgerufene `api.php?action=check` startet **intern** und stark gedrosselt (max. alle 10 min, Sperrdatei) eine Watchdog-Prüfung: Herzschlag > 15 min alt → Outbox `planer:<datum>:ausbleiben` + Zustellung. Die Antwort von `check` bleibt **unverändert** (keine Statusdaten an Anonyme, E-013). Zusätzlich roter Admin-Hinweis bei jedem Admin-Aufruf.
- **Container oder Host komplett aus:** aus dem Container heraus nicht meldbar → Abnahme ausdrücklich auf externe Überwachung beschränkt (E6: NAS prüft Alter von `_baukalkulation_status.json` bzw. Uptime-Kuma); Anleitung dafür ist Teil von T4.
- **Admin-Hinweis:** neues Feld im Admin-Teil einer bestehenden Antwort (neuer Feldname, bestehende Felder unverändert – E-088), Banner mit Link „Überwachung“.
- UI: Ampel je Job, letzte 30 Läufe, „Testmail an Empfänger“, Schwellen.

#### E. Datenbank-Bereich – PostgreSQL Stufe 1 (F7)

- **Status:** Treiber, Server-Version, DB-Größe, Tabellen/Zeilen, Schema-/Migrationsstand, Ort der SQLite-Datei bzw. PG-Host (ohne Passwort).
- **Voraussetzungs-Check:** `pdo_pgsql` geladen, Docker erkannt, Updater vorhanden, freier Speicher, letzte Sicherung < 24 h.
- **Testverbindung (Review R11):** Formular Host/Port/DB/Benutzer/Passwort (Passwort **nicht gespeichert**), POST, Admin. Strenge Syntaxprüfung (Host = Hostname/IP, DB/Benutzer `[A-Za-z0-9_\-]`, kein `;`, NUL, Leerzeichen, `=` → keine DSN-Einschleusung), Port 1–65535, `connect_timeout=5`, festes `sslmode=prefer`, `statement_timeout`; persistente Drosselung je Benutzer (5/min) + Audit-Log-Eintrag; nur feste Fehlermeldungen (kein Server-Text). Optional: erlaubte Hosts in den Einstellungen einschränken (Standard: `db` + localhost + private Netze).
- **Probe-Transfer (Trockenlauf, Review R9) – eigener Ablauf, nicht `db:transfer`:** als `job_auftraege`-Auftrag `pgsql_probe` (UI pollt). Ziel muss **frei von Anwendungstabellen** sein (nicht nur leer). Eine äußere Transaktion **vor jeglichem DDL**: `PgsqlDialect::prepareSchema()`, `TableCopier::createMissingTables()`, Kopie, Zeilenvergleich je Tabelle; **Sequenz-Reset im Probemodus überspringen** (`setval` ist nicht zurückrollbar); im `finally` immer `ROLLBACK`. Danach über eine zweite Verbindung prüfen, dass keine Tabellen/Artefakte verblieben sind (sonst Fehler „Ziel manuell bereinigen“). Ergebnis-Tabelle + Dauer → Schätzung der Umstellzeit. Zugangsdaten nur verschlüsselt im Auftrag (`payload`), Ablauf 30 min, danach gelöscht.
- **Befehls-Assistent:** erzeugt die exakten Host-Schritte (Secret-Datei, `COMPOSE_FILE`/`-f docker-compose.postgres.yml`, `db:transfer to-pgsql`, Neustart, Rückweg `to-sqlite`) für Linux/NAS und Windows (`install.ps1`), zum Kopieren; nur Anzeige, keine Ausführung.
- Hinweis auf Stufe 2 (Knopf über Updater-Sidecar) als „geplant“.

#### F. Empfehlungen (über den Auftrag hinaus, nicht alle Teil dieses AP)

| Nr | Empfehlung | In diesem AP? |
|---|---|---|
| E1 | 3-2-1-Regel: Serversicherung (lokal) + NAS + Backup-Mail/Offsite | Hinweistext in der UI |
| E2 | Wiederherstellungs-Probe: monatlich automatisch die neueste Sicherung in Wegwerf-SQLite importieren und prüfen (`BackupArchive` + `integrity_check`) | **Ja**, als Job `probe_wiederherstellung` (monatlich), überwacht wie die anderen |
| E3 | Speicherplatz-Warnung (lokal < 1 GB / NAS voll) | lokal ja (Überwachung); NAS über Fehlercode `NAS_PLATZ` |
| E4 | Da die NAS nie löscht (F3): NAS-Speicher wächst – jährlich manuell aufräumen oder Snapshot-Funktion der NAS (Synology Snapshot Replication) nutzen | Hinweis in Anleitung |
| E5 | Ransomware-Schutz: eigener NAS-Benutzer nur mit Schreibrecht auf den Zielordner, NAS-Snapshots aktivieren | Hinweis in Anleitung |
| E6 | Externe Überwachung, falls Container/Host komplett ausfällt (NAS prüft Alter von `_baukalkulation_status.json`, oder Uptime Kuma auf `api.php?action=check`) | nur Doku |
| E7 | Autoheal bei Cron-Stillstand (Healthcheck wird rot, wenn Planer-Herzschlag > 10 min) | nein – Folge-AP nach Erfahrung mit D |

### 2.5 Betroffene Dateien

| Datei | Änderung |
|---|---|
| `migrations/2026101200000x_create_system_job_tables.php` | neu (B) |
| `tests/fixtures/backups/…` | neu erzeugt (E-020) |
| `src/Services/Jobs/{JobProtokoll,SystemEinstellungen,Planer,NasAbgleich,Ueberwachung,WiederherstellungsProbe,PgsqlProbe}.php` | neu |
| `src/Console/Command/{SystemPlanerCommand,NasAbgleichCommand,SystemUeberwachungCommand}.php`, `src/Console/Kernel.php` | neu / Registrierung |
| `src/DataService.php` | `createDailyBackup()`: Sperre, Staging + Prüfung + atomares Umbenennen, wirft bei Fehler; Aufbewahrung aus Einstellung |
| `src/Backup/Importer.php` | `SKIP_TABLES` um `job_status`, `job_laeufe`, `job_auftraege`, `meldungen_outbox`; Secrets in `system_einstellungen` beim Import prüfen |
| `public/api.php` (`check`) | gedrosselte interne Watchdog-Prüfung, Antwort unverändert |
| `src/Handlers/AdminActions.php` (+ Routing in `public/api.php`) | neue Admin-Aktionen: `system_einstellungen_laden/speichern`, `nas_test`, `job_anfordern`, `job_protokoll`, `ueberwachung_testmail`, `secret_key_export`, `db_status`, `pgsql_test`, `pgsql_probe_start/status`; Admin-Hinweis-Feld |
| `src/Handlers/DataActions.php`, `src/Console/Command/BackupCreateCommand.php`, `public/api.php` | `BACKUP_MAX` → Einstellung |
| `cron_backup_email.php` | Protokollierung über `JobProtokoll` |
| `Dockerfile.app` | rclone, Crontab-Zeile Planer |
| `public/script.js` (+ `script.min.js`, `?v=`, `sw.js` `CACHE_VERSION`) | Verwaltung gegliedert, neue Bereiche, Admin-Banner |
| `public/verwaltung-nav.js` | neu, reine Helfer (testbar) |
| `public/index.html`, `public/style.css` (bzw. vorhandene CSS-Datei) | Einbindung, Layout Unternavigation |
| `public/bedienungsanleitung.html` | Verwaltung neu, Datensicherung & NAS, Überwachung, Datenbank (E-081) |
| `docs/installation.md` | Host-Cron ablösen, rclone/NAS, externe Überwachung, PG-Assistent |
| `CHANGELOG.md` | Unveröffentlicht |
| `tests/Unit/…`, `tests/Api/…`, `tests/js/verwaltung-nav.test.mjs` | neu (siehe 2.10) |

### 2.6 Struktur (Vorlage copier-astral)

- Neue Abläufe nur als `bin/console`-Befehle, **keine neuen Root-Skripte** (`cron_*.php` bleibt Bestand).
- Services typisiert unter `src/Services/Jobs/`, Tests in `tests/Unit` / `tests/Api` / `tests/js`.
- Abweichung: keine – rclone ist Laufzeitabhängigkeit des Images, kein Entwicklungswerkzeug.

### 2.7 Berücksichtigte Erkenntnisse

E-003 (bedingtes Schreiben nach langem Lauf), E-010/E-012 (Secrets, Pfade), E-013 (anonyme Endpunkte), E-014 (keine Ausnahmetexte in Feldern/Mails), E-015 (zustandsändernd nur POST), E-018 (kein privilegierter Prozess), E-020 (Fixture), E-030/E-031/E-032 (Tests Windows, Gesamtläufe), E-034 (injizierbare externe Aufrufe – rclone, Uhr, Mail), E-065 (`www-data`), E-074, E-075, E-081, E-082, E-083/E-086 (alle Einstiegspunkte), E-084 (Vertrag bindend), E-087 (Kodierung), E-088 (alte Clients).

### 2.8 Schritte (je Teilpaket eigener Durchlauf G2/G3, eigener Commit und Patch-Release – E-073)

| T | Inhalt | Commit |
|---|---|---|
| T1 | A: Verwaltung gliedern (ohne neue Funktionen) + Anleitung | `feat(verwaltung): Verwaltung allgemein in Bereiche gliedern` |
| T2 | B: Migration, Protokoll, Planer, zeitgesteuerte Sicherung, Aufbewahrung, Backup-Mail protokolliert + Anleitung | `feat(sicherung): zeitgesteuerte Serversicherung mit Laufprotokoll` |
| T3 | D + E2/E3: Überwachung, Fehler-/Ausbleiben-/Entwarnungs-Mail, Admin-Hinweis, Testmail, Wiederherstellungs-Probe | `feat(ueberwachung): Fehler-Mails für Sicherung und Abgleich` |
| T4 | C: rclone-NAS-Abgleich, Verbindungstest, secret.key-Hinweis/Export, Übergangsanleitung | `feat(nas): NAS-Abgleich per rclone aus der Verwaltung` |
| T5 | E: Datenbank-Bereich PostgreSQL Stufe 1 | `feat(datenbank): PostgreSQL-Status, Test und Assistent` |

Reihenfolge zwingend T1 → T2 → T3 → T4 → T5 (T3/T4 bauen auf B auf; T4 vor T3 wäre ohne Überwachung).

### 2.9 Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Laufende SQLite-DB inkonsistent kopiert | Live-DB ausgeschlossen, konsistente Kopie über `backups/<Tag>/` (VACUUM INTO) |
| NAS-Passwort-Leck (Log, `ps`, Fehlermeldung) | Passwort nur als Env + obscure via stdin, feste Fehlertexte, Test prüft Log/Antwort auf Passwort |
| NAS wächst unbegrenzt (F3) | Anzeige übertragener Bytes, Empfehlung E4/E5 |
| Mail-Flut bei Dauerstörung (Minutenlauf) | Outbox mit UNIQUE-Schlüssel je Job/Tag/Typ, Test „max. 1 Fehler-Mail je Job und Tag“ |
| Doppelte oder verlorene Mail (Absturz zwischen Senden und Statusschreiben, paralleler Watchdog) | Ereignis + Zustand atomar, Zustellung getrennt mit Retry; Doppelmail nur im seltenen Absturzfall nach SMTP-Annahme möglich (akzeptiert) |
| Planer läuft doppelt / hängt | `flock`, `laeuftSeit` mit Zeitgrenze, Abbruch rclone nach Höchstdauer |
| Halbfertiger Tagesordner gilt als Erfolg | Staging + `BackupArchive::fromDir()` + atomares `rename()`, gemeinsame Sicherungssperre |
| Weitere Geheimnisse in `data/` landen auf der NAS | Positivliste + Inventur in T4 + root-verankerte Ausschlüsse + Test |
| Planer nutzt falsche DB (Cron ohne Env) | Crontab-Zeile mit `/etc/baukalkulation.env`, Test mit `BK_DB_DRIVER=pgsql` |
| Restore bringt fremden Job-Zustand/fremd verschlüsselte Secrets zurück | Job-Tabellen in `SKIP_TABLES`, nicht entschlüsselbare Secrets verwerfen + Hinweis |
| Probe-Transfer verändert Ziel (`setval`, Migrationen) | eigener Ablauf ohne Sequenz-Reset, DDL in der Transaktion, Nachprüfung über 2. Verbindung |
| Fehlalarm nach Update/Neustart | Ausbleiben erst > 26 h ab letztem Erfolg; nach Ersteinrichtung Schonfrist bis erster Lauf |
| Doppelte Sicherung (Speichern + Planer) | bestehende Tagesordner-Prüfung (`is_dir`) bleibt, Planer erkennt vorhandene Tagessicherung als Erfolg |
| `BACKUP_MAX` an versteckter Stelle übersehen | grep-Inventar aller Leser (E-083) im AP Abschnitt 3 |
| Image wird größer (rclone ≈ 50–60 MB) | in Doku nennen; minimal-Build ebenfalls (Sync ist Kernfunktion) |
| Testverbindung PostgreSQL als Port-Scanner missbraucht | nur Admin, POST, Drosselung, feste Meldungen, Timeout |
| `secret.key`-Export | Passwort-Bestätigung, Audit-Log, nur POST, nicht cachebar |
| Alte Clients nach Update (E-088) | nur neue Aktionen/Felder, bestehende Antworten unverändert |
| Nicht-Docker-Installationen ohne Cron/rclone | Voraussetzungs-Anzeige „rclone fehlt“/„Planer läuft nicht“, Doku Host-Crontab für `system:planer` |
| SQLite/PostgreSQL | neue Tabellen + Upserts mit `ON CONFLICT … DO UPDATE` (E-001), Gesamtläufe auf beiden |

### 2.10 Abnahmekriterien

1. Verwaltung allgemein zeigt links die Bereiche aus A; jede bisherige Einstellung ist auffindbar und speichert wie vorher; Suche findet z. B. „SMTP“, „Personalnummer“; letzter Bereich wird gemerkt; mobil Dropdown; Header-Verknüpfungen öffnen „Datensicherung“ bzw. „System“.
2. Serversicherung entsteht täglich zur eingestellten Uhrzeit **ohne** Nutzeraktion; Aufbewahrung 3–90 wirkt nur auf Tagessicherungen; manuelle/Pre-Restore-Sicherungen bleiben.
3. Jeder Lauf von `sicherung`, `nas`, `backupmail` steht im Laufprotokoll (Status, Dauer, Kennzahlen, Fehlercode).
4. NAS-Abgleich kopiert nur die Positivliste aus `data/` und ausgelagerte Pfade; **nie** Live-DB/Journale, `secret.key`, `systemadmin-passwort.txt`, `audit_pepper.txt`, `update/`, `run/`; löscht auf der NAS nie etwas; geänderte Dateien werden aktualisiert; „Verbindung testen“ prüft Schreibrecht im Zielpfad.
5. NAS-Passwort erscheint weder in Antworten, Logs, Protokoll, Mails noch in Prozessargumenten.
6. Tageslauf mit bis zu 3 Wiederholungen (30 min); scheitern alle → genau **eine** Fehler-Mail je Job und Tag an die Empfängerliste; nächster Tag erneut Fehlschlag → erneut eine Mail; Erfolg danach → genau **eine** Entwarnung; auch bei parallelem Planer/Watchdog keine Doppelmail.
7. Planer steht bei laufendem Apache (> 15 min kein Herzschlag) → Ausbleiben-Mail ohne Admin-Aufruf (über Healthcheck-Watchdog) und roter Admin-Hinweis; `check`-Antwort unverändert; Nicht-Admins sehen nichts. Kompletter Container-/Host-Ausfall: externe Überwachung laut Anleitung (E6).
8. Testmail an Empfänger funktioniert; SMTP-Fehler wird in der UI angezeigt.
9. Wiederherstellungs-Probe läuft monatlich und meldet ein defektes Archiv als Störung.
10. Datenbank-Bereich zeigt Status; Testverbindung meldet ok/feste Fehler und lehnt DSN-Steuerzeichen ab; Probe-Transfer vergleicht Zeilen und hinterlässt nachweislich keine Tabellen/Sequenzänderungen im Ziel; Assistent erzeugt korrekte Befehle für Linux und Windows.
11. Alle neuen Aktionen: nur Admin, zustandsändernd nur POST (405 nach Rollenprüfung), CSRF/Origin wie bestehend; `check` liefert keine neuen Daten an Anonyme.
12. Alte Sicherungen (`tests/fixtures/backups/`) bleiben importierbar; neue Fixture eingecheckt; Restore importiert keine Job-Tabellen und verwirft nicht entschlüsselbare Secrets mit Hinweis.
13. Planer per Cron nutzt bei `BK_DB_DRIVER=pgsql` dieselbe DB wie der Webprozess.
14. Tagessicherung: Abbruch mitten im Schreiben hinterlässt keinen als gültig gewerteten Tagesordner; Speichern + Planer gleichzeitig → genau eine gültige Sicherung.
15. PHPUnit SQLite + PostgreSQL + `test:js` vollständig grün, PHPStan ohne neue Baseline, `check-versions --strict` grün.
16. `public/bedienungsanleitung.html` und `docs/installation.md` beschreiben alle sichtbaren Änderungen inkl. Übergang vom Host-Cron und externer Überwachung.

### 2.11 Testplan (Tester, je Teilpaket)

| Test | Art |
|---|---|
| `tests/js/verwaltung-nav.test.mjs` – Bereichszuordnung, Suche, Fallback bei unbekanntem Bereich | JS |
| `SystemEinstellungenTest` – Whitelist, Grenzen, Secret nie im Klartext/Antwort | Unit |
| `JobProtokollTest`, `PlanerTest` (Fälligkeit, Sperre, Anforderung, Uhr injiziert) | Unit |
| `UeberwachungTest` – Tageslauf mit 3 Wiederholungen, Fehler-Mail max. 1 je Job/Tag, Ausbleiben, Entwarnung, Schonfrist, Backup-Mail-Zustände (`nicht_faellig` ≠ Erfolg); Uhr + Mail-Sender injiziert | Unit |
| `MeldungenOutboxTest` – UNIQUE-Schlüssel, Retry/Backoff, zwei konkurrierende Prozesse → 1 Mail | Unit/Integration |
| `NasAbgleichTest` – Fake-rclone: Argument-Array, Ziel beginnt mit `:smb:`, `copy` (nie `sync`), Positivliste + Ausschlüsse (inkl. `systemadmin-passwort.txt`, `audit_pepper.txt`, `database.sqlite-journal`), Env statt Argument, Phasen-Fehlercodes, Passwort nirgends im Log, Pfad-Einschleusung abgelehnt | Unit |
| `SicherungPlanTest` – zeitgesteuerte Sicherung, Aufbewahrung, Staging/Abbruch, gleichzeitige Auslösung, Rückfall beim Speichern bleibt funktionsfähig | Unit/Api |
| `PlanerCronEnvTest` – Planer mit `BK_DB_DRIVER=pgsql` nutzt dieselbe DB | Api (PG-Lauf) |
| `SystemJobsApiTest` – Rechte (anonym/normal/master/admin), POST-Pflicht, Antworten ohne Secrets, `check` unverändert, Admin-Hinweis-Feld | Api |
| `RestoreJobTabellenTest` – alte + neue Fixture, Job-Tabellen übersprungen, fremd verschlüsseltes NAS-Passwort verworfen | Api |
| `DbStatusApiTest`, `PgsqlProbeTest` (nur PG-Lauf: Ziel ohne Anwendungstabellen, ROLLBACK, keine Reste/Sequenzen, DSN-Einschleusung abgelehnt) | Api |
| Backup-Fixture-Import (bestehend) | Api |

### 2.12 Offene Punkte (mit vorgeschlagenem Standard – bei G1 bestätigen oder ändern)

| # | Punkt | Vorschlag |
|---|---|---|
| O1 | Standard-Uhrzeit Serversicherung + NAS | 02:00 (Wiederholungen 02:30, 03:00, 03:30) |
| O2 | Watchdog-Schwelle Planer-Herzschlag | 15 min |
| O3 | Ausbleiben-Schwelle | 26 h (einstellbar 2–168 h) |
| O4 | Laufprotokoll-Aufbewahrung | 90 Tage |
| O5 | Wiederherstellungs-Probe | monatlich am 1., 04:00 |
| O6 | NAS-Zielstruktur | `<freigabe>/<pfad>/data/…` und `<pfad>/extern/<schlüssel>/…` |
| O7 | Releases | je Teilpaket ein Patch-Release (3.0.18 … 3.0.22) |

### 2.13 Plan-Review vor G1 (Rubber-Duck, andere Modellinstanz, 2026-10-12)

10 blockierende + 2 weitere Befunde, an den Fundstellen geprüft (E-072) und eingearbeitet:

| R | Befund | Geprüft | Eingearbeitet in |
|---|---|---|---|
| R1 | Planer-Crontab ohne `/etc/baukalkulation.env` → SQLite statt PostgreSQL | `Dockerfile.app` Crontab-Muster | B, AK 13 |
| R2 | Weitere Geheimnisse in `data/` (`systemadmin-passwort.txt`, `audit_pepper.txt`) | `SystemadminPassword.php:11`, `AuditService.php:26` | C (Positivliste), AK 4 |
| R3 | Vorhandener Tagesordner ≠ gültige Sicherung; keine gemeinsame Sperre | `DataService.php` `is_dir`-Prüfung | B, AK 14 |
| R4 | rclone-Ziel ohne `:smb:` = lokaler Pfad; `lsd` prüft kein Schreibrecht | rclone-Doku | C |
| R5 | rclone-Exit-Codes erlauben keine Diagnose „Anmeldung/Platz“ | rclone-Doku | C (Phasen-Codes) |
| R6 | Backup-Mail „nicht fällig“ (Exit 0) würde als Erfolg zählen | `cron_backup_email.php` | B, D |
| R7 | „Jeder Fehlschlag“ vs. Übergang; Doppelmail-Rennen | – | F10 (Nutzer), Outbox |
| R8 | Cron-Ausfall-Mail hing an Admin-Aufrufen | Healthcheck `docker-compose.yml` | D (Healthcheck-Watchdog), AK 7 |
| R9 | Probe-Transfer: `db:transfer` migriert/committet, `setval` nicht rückrollbar | `DbTransferCommand.php`, `PgsqlDialect.php:178` | E |
| R10 | Probe-Zugangsdaten in einer `job_status`-Zeile → Rennen/Liegenbleiben | – | B (`job_auftraege`) |
| R11 | PG-Testverbindung: DSN-Einschleusung, interner Portscan | – | E |
| R12 | Restore-Vertrag für neue Tabellen fehlt | `Importer.php:23` `SKIP_TABLES` | B, AK 12 |

**Freigabe G1:** ☑ durch Nutzer am 2026-10-12 – ohne Änderungen; Umsetzung erst nach Abschluss von AP-20261012-ki-modelle-tests („zuerst das erste, dann das zweite“).

## 3. Umsetzung

### T1 – Verwaltung gliedern (Schnitt durch Leitstand, 2026-10-16)

Releases verschieben sich um eins, weil inzwischen 3.0.18 erschienen ist: T1 = 3.0.19 … T5 = 3.0.23 (O7).

Vertrag T1 (bindend, E-084):
- Karten in `renderAllgemeinSettings()` bleiben **im DOM**: Jede Karte bekommt `data-bereich="<id>"`, die Unternavigation blendet nur ein und aus. Element-IDs, `updateSetting(...)`, `saveErinnerungSettings()`, `loadErinnerungSettings()` und `loadOciLieferanten()` bleiben unverändert. Kein Server-/API-Code ändert sich.
- Bereiche in T1: `firma`, `module`, `anmeldung`, `nummern`, `baustellen`, `zeiterfassung`, `email`, `schnittstellen`, `datensicherung`, `system`. `ueberwachung` und `datenbank` kommen erst mit T3/T5 dazu; leere Bereiche werden nicht angezeigt.
- **Abweichung 1:** Der neue Bereich `schnittstellen` (Gemini-KI, OCI-Großhandel) fehlte in der Planliste; diese Karten passten in keinen Bereich.
- **Abweichung 2:** Die Header-Punkte „Speicherpfade“ und „Update & Systeminfo“ bleiben unverändert (öffnen weiterhin ihre Dialoge). Die Bereiche `datensicherung` und `system` bekommen zusätzlich Knöpfe, die dieselben Dialoge öffnen. Grund: Die Dialoge sind Modals und keine Seiten; so ist das Risiko geringer.
- **Abweichung 3:** Kein `#verwaltung/<id>`-Deep-Link, nur `localStorage` `bk.verwaltung.bereich`. Grund: Die App nutzt kein Hash-Routing.

#### Umsetzung Runde 1 (Entwickler, Modell: Claude Sonnet 5 (copilot))

Umgesetzt wie im Vertrag beschrieben: Alle Karten/Abschnitte aus `renderAllgemeinSettings()` bleiben im DOM, bekommen
`data-bereich="<id>"` + `class="vw-karte"`; eine neue linke Unternavigation (`#vwNav`, `<768px` als `#vwNavSelect`)
blendet per `class="vw-hidden"` ein/aus. Suche (`#vwSuche`) filtert über `textContent` + Input-`placeholder` aller
Karten, hebt bei nicht leerem Begriff die Bereichsauswahl auf und zeigt bei 0 Treffern einen Hinweis; eine leere
Suche kehrt zum zuletzt gewählten/gespeicherten Bereich zurück (`localStorage['bk.verwaltung.bereich']`, try/catch).

**Geänderte Dateien:**
- `public/script.js` – `renderAllgemeinSettings()`: Kopf (h2 + `#vwSuche`), `.vw-layout` (Nav + Inhalt), jede Karte/jeder
  Abschnitt mit `data-bereich`/`class="vw-karte"`; zwei neue Karten `datensicherung` (Knöpfe „Wiederherstellungspunkte /
  Datensicherung öffnen“ → `openBackupModal()`, nur Admin „Speicherpfade öffnen“ → `showStoragePaths()`) und `system`
  (Knopf „Update & Systeminfo öffnen“ → `openUpdateModal()`, nur Admin); neue Funktionen `initVerwaltungNav(view)`,
  `vwBereichWaehlen(id)`, `vwSucheEingabe(wert)`. Keine Element-IDs, `updateSetting(...)`-Aufrufe oder API-Pfade geändert.
- `public/script.min.js` – neu erzeugt (`npm run minify`).
- `public/verwaltung-nav.js` – neu: reine Helferfunktionen (kein DOM-Zugriff), s.u.
- `public/index.html` – `verwaltung-nav.js?v=1` vor `script.min.js` eingebunden; `style.css?v=146`, `script.min.js?v=236`.
- `public/sw.js` – `CACHE_VERSION` `bk-es-v238` → `bk-es-v239`; `PRECACHE_URLS` um `./verwaltung-nav.js?v=1` ergänzt;
  `style.css?v=146`, `script.min.js?v=236`.
- `public/style.css` – neuer Block `.vw-*` (Nav, Layout, Suche, `vw-hidden`, Media-Query `<768px`); nutzt bestehende
  CSS-Variablen (`--card-bg`, `--border`, `--primary`, `--grey-50`, `--grey-400`, `--text`), damit Dark-Mode ohne
  Zusatzregeln funktioniert.
- `public/bedienungsanleitung.html` – Abschnitt 15 „Einstellungen & Rechte“ um Beschreibung der Bereiche, Suche,
  gemerktem Bereich und mobiler Auswahlliste ergänzt (E-081).
- `CHANGELOG.md` – Eintrag unter „Unveröffentlicht“ → „Geändert“.

**Funktionen in `public/verwaltung-nav.js`:**
- `BK_VERWALTUNG_BEREICHE` – Array `{id, titel, ico}`, Reihenfolge: firma, module, anmeldung, nummern, baustellen,
  zeiterfassung, email, schnittstellen, datensicherung, system.
- `bkVerwaltungBereich(gespeicherteId, sichtbareIds)` – liefert `gespeicherteId`, wenn in `sichtbareIds` enthalten,
  sonst `sichtbareIds[0]` bzw. `null` wenn leer.
- `bkVerwaltungNormalisiere(text)` – klein, `ä→ae`, `ö→oe`, `ü→ue`, `ß→ss`, Whitespace zusammengefasst/getrimmt.
- `bkVerwaltungTreffer(suchbegriff, kartenTexte)` – Indizes der Karten, die alle normalisierten Wörter des Begriffs
  enthalten; leerer Begriff → alle Indizes.

**Abweichung 4 (zusätzlich zu 1–3 im Vertrag):** Beim Durchsuchen von `renderAllgemeinSettings()` fiel ein bestehender
Fehler auf: Der Block „Standard-Branche“ (nach „Mobile App – Button-Reihenfolge“) hatte **keine eigene Karten-Hülle**
(`<div>`/`<h3>` fehlten; `label`/`select`/`label` standen direkt als Geschwister im CSS-Grid). Da jede Karte eine
`data-bereich`-Hülle braucht, wurde eine `<div class="vw-karte" data-bereich="firma"><h3>Branche</h3>…</div>`-Hülle
ergänzt (Inhalt/Felder/IDs unverändert) und dem Bereich „Firma & Branding“ zugeordnet. Bitte im Review bestätigen.

**Prüfungen:**
- `node --check public/script.js` → ok
- `node --check public/verwaltung-nav.js` → ok
- `node scripts/check-versions.mjs --strict` → `OK – alle ?v=-Versionen sind konsistent.` (CACHE_VERSION bk-es-v239)
- `npm run test:js` → 12/12 grün (bestehende `sollzeit.test.mjs`; `verwaltung-nav.test.mjs` schreibt der Tester)
- Manuelle Stichprobe der neuen Helferfunktionen per `node:vm` (Fallback unbekannt/leer/gültig/keine Sichtbaren,
  Normalisierung, Treffer bei SMTP/Mehrwort/leer) – alle wie erwartet.

**Nicht geprüft (liegt außerhalb T1 bzw. macht der Tester/Reviewer):** PHPUnit/PHPStan (keine PHP-Änderung),
visuelle/responsive Abnahme im Browser, `tests/js/verwaltung-nav.test.mjs`.

#### T1 – Runde 2 (Entwickler, Modell: Claude Sonnet 5 (copilot)): Review-Hinweis 1 behoben – Suchfeld wird bei Bereichswechsel geleert; script.min.js?v=237, CACHE_VERSION bk-es-v240.

#### T1 – Abnahme Leitstand (2026-10-16)

- Gelaufene Modelle laut Laufzeit: Entwickler `claude-sonnet-5`, Tester `gpt-5.3-codex` (nennt sich selbst „GPT-5.5“), Reviewer `gpt-5.5`. Tester und Reviewer gehören damit einer anderen Familie an als der Entwickler (E-089).
- Sichtprüfung im eingebetteten Chromium (Admin, Wegwerf-DB) bestanden: 10 Bereiche; keine Karte außerhalb von `#vwInhalt`; die Suche findet „smtp“, „personalnummer“ und „grosshandel“, bei Nichttreffer erscheint der Hinweis; Bereichswechsel und gemerkter Bereich funktionieren; Speichern liefert `save_settings` 200 mit „Gespeichert“; die Backup-Mail-Felder werden geladen; bei 390 px erscheint die Auswahlliste; nach Runde 2 wird das Suchfeld beim Bereichswechsel geleert. Rolle master/normal und Dark-Mode nicht geprüft (Review-Hinweis 2, Restrisiko gering: nur Ein-/Ausblenden).
- Gesamtläufe: PHPUnit SQLite 537 Tests grün (2 übersprungen), PostgreSQL 537 Tests grün (3 übersprungen), `test:js` 18/18 grün, `check-versions --strict` OK.
- Version 3.0.19 (Commits `6406362` und `6e7dc3f`, Tag `v3.0.19` gepusht).

### T2 – Planer und zeitgesteuerte Serversicherung (Schnitt durch Leitstand, 2026-10-16)

Vertrag T2 (bindend; zusätzliche Abweichungen im AP melden, E-084). Zeitstempel überall als `Y-m-d H:i:s` (Ortszeit, PHP `date()`), keine SQL-Datumsfunktionen; alle Uhrzeiten in den Services injizierbar (E-034).

1. **Migration** (`php vendor/bin/phinx create CreateSystemJobTables`, DDL in SQLite-Syntax, Spalten camelCase, alle Textspalten `NOT NULL DEFAULT ''`):
   `system_einstellungen(id INTEGER PRIMARY KEY, data TEXT DEFAULT '{}')` ·
   `job_status(job TEXT PRIMARY KEY, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit, laeuftSeit, tageslaufDatum, tageslaufStatus, versuche INTEGER DEFAULT 0, naechsterVersuchAm)` ·
   `job_laeufe(id INTEGER PRIMARY KEY AUTOINCREMENT, job, ausloeser, gestartetAm, beendetAm, status, fehlerCode, meldung, kennzahlen DEFAULT '{}')` + Index `(job, gestartetAm)` ·
   `job_auftraege(id TEXT PRIMARY KEY, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis DEFAULT '{}')` ·
   `meldungen_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT, schluessel TEXT UNIQUE, job, typ, daten DEFAULT '{}', erstelltAm, gesendetAm, versuche INTEGER DEFAULT 0, naechsterVersuchAm)` (wird erst in T3 genutzt, eine Fixture-Erneuerung genügt).
   Danach `php tests/bin/build-backup-fixture.php` ausführen und die neue Fixture einchecken (E-020). `Importer::SKIP_TABLES` += `job_status`, `job_laeufe`, `job_auftraege`, `meldungen_outbox`.
2. **`App\Services\Jobs\SystemEinstellungen`**: `laden(PDO): array` (Standardwerte tief gemischt, `sicherung.uhrzeit = '02:00'`, `sicherung.aufbewahrungTage = 7`); `speichernSicherung(PDO, array): array` mit Prüfung (`HH:MM` 00:00–23:59, ganze Zahl 3–90); bei ungültigen Werten `\InvalidArgumentException` mit festem deutschem Text. Upsert `ON CONFLICT(id) DO UPDATE` (E-001).
3. **`App\Services\Jobs\JobProtokoll`** (PDO, optionale Uhr):
   - `start(job, ausloeser): int`, `erfolg(laufId, kennzahlen)`, `fehler(laufId, code, meldung)`, `herzschlag(job)`, `status(job): array`, `letzteLaeufe(limit, ?job)`, `aufraeumen(tage = 90)`.
   - `fehler()` speichert nur feste Codes und Texte. Ausnahmedetails gehen ausschließlich per `error_log()` (E-014).
4. **`App\Services\Jobs\Tagessicherung::erstelle(PDO, DateTimeImmutable, bool warten): string`**, Rückgabe `erstellt` | `vorhanden` | `gesperrt`; wirft eine `\RuntimeException` mit festem Text.
   - Sperre über `flock` auf `DATA_DIR/run/sicherung.lock`: blockierend bei `warten`, sonst `LOCK_NB` und Rückgabe `gesperrt`.
   - Ablauf: in `BACKUP_DIR/.tmp-<zufall>/` schreiben, mit `BackupArchive::fromDir()` prüfen, dann per `rename()` auf `JJJJ-MM-TT` umbenennen.
   - Ein vorhandener Tagesordner gilt nur nach erfolgreicher Prüfung als `vorhanden`; ein ungültiger wird gelöscht und neu aufgebaut (`error_log`).
   - `.tmp-*`-Reste, die älter als 6 h sind, werden entfernt.
   - Danach `pruneBackupCategory('daily', aufbewahrungTage)`.
   - **Abweichung 5:** Die Sperre gilt nur für die Tagessicherung. Manuelle Sicherungen und die Sicherung vor einer Wiederherstellung schreiben in eindeutige Ordnernamen und kollidieren nicht (R3 eingeschränkt).
5. **`DataService::createDailyBackup()`** behält seine Signatur und ruft `Tagessicherung::erstelle(…, warten: false)` auf.
   - Bei `erstellt` protokolliert sie Erfolg (Auslöser `speichern`), bei einer Ausnahme den Fehler (Code `SICHERUNG_FEHLER`).
   - Jede Ausnahme, auch eine aus dem Protokoll selbst, wird abgefangen: Das Speichern der Projektdaten darf nie scheitern.
6. **`App\Services\Jobs\Planer`**:
   - Reine Funktion `faelligkeit(array status, string uhrzeit, DateTimeImmutable jetzt): ?string` mit den Ergebnissen `erstversuch` | `wiederholung` | `null`.
   - Erster Versuch ab der Uhrzeit des Tages, auch nachgeholt. Bei Fehler bis zu 3 Wiederholungen im Abstand von 30 min; danach `tageslaufStatus = fehlgeschlagen`, bei Erfolg `ok` (F10).
   - `lauf(PDO, DateTimeImmutable)` arbeitet in dieser Reihenfolge:
     1. Herzschlag `planer` schreiben.
     2. Ein `laeuftSeit` älter als 3 h als `abgebrochen` abschließen.
     3. `wartend`-Aufträge, deren `ablaufAm` überschritten ist, auf `abgelaufen` setzen.
     4. Aufträge annehmen, nur per `UPDATE … WHERE id = ? AND status = 'wartend'` mit Prüfung von `rowCount()` (E-003).
     5. Den planmäßigen Job `sicherung` ausführen.
     6. `aufraeumen()`.
   - Ein erfolgreicher manueller Lauf setzt einen heute fehlgeschlagenen Tageslauf auf `ok`.
7. **Console**: `system:planer` (Sperre auf `DATA_DIR/run/planer.lock` mit `LOCK_NB`; ist sie belegt, endet der Befehl mit Exit 0) und `sicherung:erstellen` (Auslöser `konsole`), beide in `Console\Kernel` registriert. **Crontab** in `Dockerfile.app`, exakt:
   `* * * * * www-data . /etc/baukalkulation.env; php /var/www/html/bin/console system:planer >> /var/www/html/data/cron.log 2>&1` (R1).
8. **`cron_backup_email.php`** protokolliert den Job `backupmail`.
   - Bei `deaktiviert`, `nicht fällig` und `bereits gesendet` entsteht kein Eintrag.
   - `gesendet` = Erfolg, und zwar nur nach `MailService::send()` (R6).
   - Fehlercodes: `BACKUPMAIL_EMPFAENGER`, `BACKUPMAIL_PASSWORT`, `BACKUPMAIL_ERSTELLEN`, `BACKUPMAIL_VERSAND`, jeweils mit festem Text. `--force` ergibt den Auslöser `manuell`.
   - Derselbe Fehlercode im selben Zyklusschlüssel erzeugt keinen neuen Laufeintrag, sondern aktualisiert nur `letzterFehlerAm`. Grund: Das Skript läuft alle 15 min.
   - Fehler im Protokoll brechen den Mailablauf nie ab.
9. **API**: neuer Handler `App\Handlers\SystemJobActions`, Aktionen in `public/api.php` eintragen, nur Rolle `admin`.
   - `system_jobs_status` (GET): Einstellung `sicherung`, Status `sicherung`, `backupmail` und `planer`, die letzten 20 Läufe, `planerAktiv` (Herzschlag < 15 min). Keine Pfade, keine Secrets.
   - `system_einstellungen_speichern` (POST, `{sicherung:{uhrzeit, aufbewahrungTage}}`): bei ungültigen Werten 400.
   - `job_anfordern` (POST, `{job:'sicherung'}` aus einer Positivliste): ist bereits ein offener Auftrag da, wird dessen ID zurückgegeben; Ablauf nach 30 min; Eintrag im Audit-Log.
   - `job_auftrag_status` (GET `id`): liefert `status` und `ergebnis`, nie `payload`.
   - Zustandsändernde Aktionen antworten nach der Rollenprüfung auf GET mit 405 (E-015).
   - `check` bleibt unverändert.
   - `listBackups`: Grenze statt `BACKUP_MAX` = `aufbewahrungTage + BACKUP_MAX * 2 + 7`.
10. **Frontend** (Bereich `datensicherung`, nur admin): Karte „Serversicherung (zeitgesteuert)“ mit eigenem Platzhalter, geladen per `loadServersicherung()`.
    - Felder: Uhrzeit und Aufbewahrung, gespeichert bei Änderung.
    - Status: letzter Erfolg, letzter Fehler (Code + Text), Planer zuletzt aktiv. Ist der Planer seit mehr als 15 min nicht gelaufen oder ist kein Herzschlag vorhanden, erscheint ein roter Hinweis „Zeitplaner läuft nicht – automatische Sicherung inaktiv“.
    - Laufprotokoll mit 20 Zeilen; Knopf „Tageslauf jetzt ausführen“ (Auftrag anlegen, Abfrage alle 3 s, höchstens 2 min).
    - Alles über `textContent` bzw. `esc()`. Cache-Busting nach `frontend.instructions.md`.
11. **Doku**:
    - `public/bedienungsanleitung.html` (E-081).
    - `docs/installation.md`: Planer-Cron für Installationen ohne Docker; unter Docker läuft er automatisch.
    - `CHANGELOG.md` unter „Unveröffentlicht“.

#### Umsetzung Runde 1 (Entwickler, Modell: Claude Sonnet 5 (copilot))

Vertrag 1–11 vollständig umgesetzt; keine Abweichungen (E-084: keine zusätzlichen Einschränkungen über
den Vertrag hinaus eingeführt).

**Migration:**
- `migrations/20261016000000_create_system_job_tables.php` (Klasse `CreateSystemJobTables`, SQLite-DDL
  per `$this->getAdapter()->getConnection()->exec(...)` wie `CreatePruefungTables`): `system_einstellungen`,
  `job_status`, `job_laeufe` (+ Index `(job, gestartetAm)`), `job_auftraege`, `meldungen_outbox` (Spalten exakt
  laut Vertrag, Textspalten `NOT NULL DEFAULT ''`, `job_laeufe.kennzahlen`/`job_auftraege.ergebnis`/
  `meldungen_outbox.daten` `DEFAULT '{}'`). Fixture neu erzeugt: `tests/fixtures/backups/v3.0.19.zip` +
  `v3.0.19.expected.json` (gleiche Version wie bisher, da `VERSION` nicht geändert wurde). `Importer::SKIP_TABLES`
  um `job_status`, `job_laeufe`, `job_auftraege`, `meldungen_outbox` ergänzt; `system_einstellungen` bewusst
  **nicht** übersprungen (wie `settings`/`erinnerung_settings` Teil der Sicherung).

**Neue Dateien (`src/Services/Jobs/`):**
- `SystemEinstellungen::laden(PDO $db): array` – Standardwerte (`sicherung.uhrzeit='02:00'`,
  `sicherung.aufbewahrungTage=7`) tief gemischt mit gespeicherten Werten (unbekannte Schlüssel für künftige
  Bereiche `nas`/`ueberwachung` bleiben erhalten).
  `SystemEinstellungen::speichernSicherung(PDO $db, array $eingabe): array` – prüft `HH:MM` (00:00–23:59) und
  ganze Zahl 3–90, wirft `\InvalidArgumentException` mit festem deutschem Text, Upsert `ON CONFLICT(id) DO UPDATE`.
- `JobProtokoll::__construct(PDO $db, ?callable $uhr = null)`,
  `start(string $job, string $ausloeser, ?string $tageslaufSchluessel = null): int`,
  `erfolg(int $laufId, array $kennzahlen = []): void`, `fehler(int $laufId, string $code, string $meldung): void`,
  `herzschlag(string $job): void`, `status(string $job): array`, `letzteLaeufe(int $limit, ?string $job = null): array`,
  `aufraeumen(int $tage = 90): void`.
  **Zusatz gegenüber dem Vertrag (Erweiterung, keine Einschränkung):** `start()` hat einen optionalen dritten
  Parameter `$tageslaufSchluessel` (Default `null`, bestehende Aufrufe mit 2 Argumenten unverändert gültig) und
  es gibt zusätzlich `fehlerZeitAktualisieren(string $job): void`. Beides wird ausschließlich von
  `cron_backup_email.php` genutzt, um Punkt 8 Satz 3 umzusetzen („Derselbe Fehlercode im selben Zyklusschlüssel
  erzeugt keinen neuen Laufeintrag, sondern aktualisiert nur `letzterFehlerAm`“): der Zyklusschlüssel
  (`taeglich`/`woechentlich`/`monatlich`) wird dafür in `job_status.tageslaufDatum` abgelegt (für `backupmail` als
  generische „aktueller Zyklus“-Kennung, nicht als Kalendertag). Für `sicherung`/`planer` bleibt `tageslaufDatum`
  unverändert der Kalendertag, da `Planer` es selbst direkt setzt.
- `Tagessicherung::erstelle(PDO $db, DateTimeImmutable $jetzt, bool $warten = false): string` – Rückgabe
  `Tagessicherung::ERSTELLT|VORHANDEN|GESPERRT` (Strings `'erstellt'|'vorhanden'|'gesperrt'`), `flock` auf
  `DATA_DIR/run/sicherung.lock` (`LOCK_EX` blockierend bei `$warten`, sonst `LOCK_NB`), Staging in
  `BACKUP_DIR/.tmp-<zufall>/` + `BackupArchive::fromDir()`-Prüfung + `rename()`, vorhandener Tagesordner wird vor
  `vorhanden` geprüft und bei Ungültigkeit neu aufgebaut, `.tmp-*` älter 6 h werden entfernt, danach
  `DataService::pruneBackupCategory('daily', aufbewahrungTage)`. Wirft `\RuntimeException` mit festem Text,
  Ursache nur per `error_log()`.
- `Planer::faelligkeit(array $status, string $uhrzeit, DateTimeImmutable $jetzt): ?string` (reine Funktion,
  `'erstversuch'|'wiederholung'|null`) und `Planer::lauf(PDO $db, DateTimeImmutable $jetzt): array` (liefert
  CLI-Ausgabezeilen) – Reihenfolge exakt laut Vertrag (Herzschlag → hängende Läufe > 3 h abbrechen → abgelaufene
  `job_auftraege` markieren → Auftrag für `sicherung` per `UPDATE … WHERE status='wartend'` + `rowCount()`
  annehmen (E-003) → Job `sicherung` ausführen → `aufraeumen()`). `tageslaufStatus` zusätzlich zu `laufend`/`ok`/
  `fehlgeschlagen` mit Zwischenwert `fehler` (Fehlschlag, Wiederholung folgt) – intern, nicht Teil des Vertrags.
  Ein erfolgreicher manueller Lauf (`job_auftraege`) setzt einen heute fehlgeschlagenen Tageslauf auf `ok`; ein
  fehlgeschlagener manueller Lauf verändert `tageslaufStatus`/`versuche` nicht (Admin-Knopf beeinflusst die
  automatische Wiederholungslogik nicht).

**Geänderte Dateien:**
- `src/DataService.php` – `createDailyBackup(PDO $db, array $data): void` behält die Signatur, ruft
  `Tagessicherung::erstelle($db, new DateTimeImmutable(), false)` auf; protokolliert nur bei tatsächlichem
  `ERSTELLT` einen Erfolg bzw. bei einer Ausnahme den Fehler `SICHERUNG_FEHLER` (Auslöser `speichern`) – sonst
  (`vorhanden`/`gesperrt`) kein Laufeintrag, damit das Protokoll nicht von jedem Speichervorgang des Tages
  zugemüllt wird; jede Ausnahme inkl. der aus dem Protokoll selbst ist abgefangen.
- `src/Backup/Importer.php` – `SKIP_TABLES` erweitert (s. o.).
- `src/Handlers/DataActions.php` – `listBackups()`: Grenze `BACKUP_MAX` → `aufbewahrungTage + BACKUP_MAX*2 + 7`
  (`SystemEinstellungen::laden()`).
- `src/Console/Kernel.php` – zwei neue Befehle registriert.
- `Dockerfile.app` – Crontab-Zeile ergänzt: `* * * * * www-data . /etc/baukalkulation.env; php /var/www/html/bin/console system:planer >> /var/www/html/data/cron.log 2>&1` (R1, exakt wie im Vertrag).
- `cron_backup_email.php` – protokolliert Job `backupmail` über `JobProtokoll`: `BACKUPMAIL_EMPFAENGER`
  (ungültiger Empfänger), `BACKUPMAIL_PASSWORT` (Entschlüsselung scheitert), `BACKUPMAIL_ERSTELLEN`
  (Sicherung/ZIP scheitert), `BACKUPMAIL_VERSAND` (`MailService::send()` wirft); Erfolg erst nach
  `MailService::send()` protokolliert (R6). „Deaktiviert“, „nicht fällig“ (Uhrzeit) und „bereits gesendet“
  bleiben ohne Protokolleintrag (Punkt 8 Satz 2). Zyklusschlüssel-Berechnung einmalig vorgezogen (vorher doppelt
  im Code), wird auch für die Entdopplung gleicher Fehlercodes verwendet (s. o.).
- `public/api.php` – neue Routen `system_jobs_status` (GET), `system_einstellungen_speichern` (POST),
  `job_anfordern` (POST), `job_auftrag_status` (GET); `check` unverändert.
- `public/script.js` – neue, nur für `admin` sichtbare Karte „Serversicherung (zeitgesteuert)“ im Bereich
  `datensicherung` (`id="servSicherungCard"`, vor der bestehenden Backup-E-Mail-Karte), neue Funktionen
  `loadServersicherung()`, `renderServersicherung()`, `servFormatZeitpunkt()`, `servLaufZeile()`,
  `speichereServersicherung()`, `servTageslaufJetzt()`, `servJetztPollen()`; `showAllgemeinSettings()` ruft
  `loadServersicherung()` zusätzlich für `admin` auf. Alles über Template-Strings mit `esc(...)` für dynamische
  Werte (keine ungeprüften Nutzerdaten).
- `public/script.min.js` – neu erzeugt (`npm run minify`).
- `public/index.html` – `script.min.js?v=238`.
- `public/sw.js` – `CACHE_VERSION` `bk-es-v240` → `bk-es-v241`; Precache `script.min.js?v=238`.
- `public/bedienungsanleitung.html` – Abschnitt 12 „Datensicherung“ um die zeitgesteuerte Serversicherung ergänzt.
- `docs/installation.md` – Abschnitt 5: `system:planer`-Cron für Installationen ohne Docker.
- `CHANGELOG.md` – neuer Abschnitt „Unveröffentlicht“ (noch kein Release in dieser Runde).

**Neue API-Aktionen (nur Rolle `admin`; `SystemJobActions`):**
- `system_jobs_status` (GET) → `{ok:true, einstellungen:{sicherung:{uhrzeit,aufbewahrungTage}}, status:{sicherung:{...job_status-Felder...}, backupmail:{...}, planer:{letzterLaufAm, aktiv:bool}}, laeufe:[{job,ausloeser,gestartetAm,beendetAm,status,fehlerCode,meldung,kennzahlen:{}}, …20]}` – keine Pfade/Secrets.
- `system_einstellungen_speichern` (POST, Body `{sicherung:{uhrzeit:"HH:MM", aufbewahrungTage:int}}`) →
  `{ok:true, sicherung:{...}}`; 400 mit festem Text bei ungültigen Werten; 405 bei GET.
- `job_anfordern` (POST, Body `{job:"sicherung"}`) → `{ok:true, id:"<hex>"}` (bestehender offener Auftrag wird
  wiederverwendet); 400 bei unbekanntem Job; 405 bei GET; Audit-Log `job_angefordert`.
- `job_auftrag_status` (GET, Query `id`) → `{ok:true, status:"wartend|laufend|fertig|fehlgeschlagen|abgelaufen", ergebnis:{...}}` – nie `payload`.

**Ausgeführte Prüfungen (Ergebnis):**
- `php -l` auf allen 12 neuen/geänderten PHP-Dateien → alle ok.
- Migration auf Wegwerf-SQLite (`BK_DATA_DIR`): `db:migrate` lief ohne Fehler; `system:planer` zweimal
  hintereinander → 1. Lauf `sicherung: erstellt` (Tagesordner angelegt, `job_status`/`job_laeufe` korrekt: Erfolg,
  `tageslaufStatus=ok`, `versuche=0`), 2. Lauf `Kein fälliger Job.` (kein weiterer Eintrag); `sicherung:erstellen`
  → `Tagessicherung: vorhanden` (dritter protokollierter Lauf, Auslöser `konsole`). Stichprobe der Tabellen per
  eigenem Prüfskript bestätigt die erwarteten Werte. Wegwerf-Verzeichnisse danach gelöscht.
- `php tests/bin/build-backup-fixture.php` → `tests/fixtures/backups/v3.0.19.zip` neu geschrieben.
- `php vendor/bin/phpunit --filter "Backup|Migration|ConsoleBackup|Console"` (SQLite) → **37 Tests grün, 1
  übersprungen** (bestehende Tests, keine neuen – die schreibt der Tester).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → **keine Fehler** (ein Zwischenbefund
  `property.onlyWritten` zu `Planer::$db` wurde behoben, indem der ungenutzte Konstruktor/die Property entfernt
  wurden – `lauf()` bekommt `PDO` bereits als Parameter).
- `node --check public/script.js` und `node --check public/script.min.js` → ok.
- `node scripts/check-versions.mjs --strict` → `OK – alle ?v=-Versionen sind konsistent.` (`CACHE_VERSION = bk-es-v241`).
- `npm.cmd run test:js` → **18/18 grün** (bestehende Tests inkl. T1-Wächtertest; keine neuen JS-Tests – Karte
  nutzt nur die bereits erlaubte `data-bereich="datensicherung"`-ID).

**Nicht geprüft (liegt außerhalb der Entwickler-Rolle bzw. macht der Tester/Reviewer/Leitstand):**
PHPUnit/`test:js` als Gesamtlauf (SQLite **und** PostgreSQL), neue Testfälle zu `JobProtokoll`/`Planer`/
`SystemEinstellungen`/`Tagessicherung`/`SystemJobActions`/`cron_backup_email.php` laut Testplan (2.11),
visuelle Abnahme der neuen Karte im Browser, Commit/Release.

**Abweichungen vom Vertrag:** keine inhaltlichen; zwei rückwärtskompatible technische Ergänzungen an
`JobProtokoll` (optionaler dritter `start()`-Parameter, zusätzliche Methode `fehlerZeitAktualisieren()`) wurden
oben begründet dokumentiert (E-084).

#### Umsetzung Runde 2 (Entwickler, Modell: Claude Sonnet 5 (copilot)): Review-/Test-Befunde behoben

Vier bestätigte Befunde (1 Blocker, 2 Major, 1 Minor) behoben, keine Tests geändert.

**1. Blocker – Sperre zählte als Erfolg:**
- `src/Services/Jobs/Tagessicherung.php` – Signatur geändert zu
  `erstelle(\PDO $db, \DateTimeImmutable $jetzt, int $warteSekunden = 0): string`. `0` = weiterhin sofort per
  `LOCK_NB` (kein Blockieren, Speichern-Rückfall in `DataService` unverändert). `>0`: Schleife mit `LOCK_NB` im
  Abstand von 250 ms (`usleep`), bis die Sperre frei ist oder `$warteSekunden` verstrichen ist – danach
  `self::GESPERRT`. Kein unbegrenztes Blockieren mehr (vorher `LOCK_EX` ohne `LOCK_NB`, konnte theoretisch ewig
  warten).
- `src/Services/Jobs/Planer.php` – `sicherungAusfuehren()`: Aufruf jetzt mit neuer Konstante
  `SPERR_WARTE_SEKUNDEN = 30`. `Tagessicherung::GESPERRT` wird nicht mehr wie Erfolg behandelt, sondern über die
  neue private Methode `sicherungFehlschlagBehandeln()` (gemeinsam mit dem Ausnahme-Zweig genutzt) wie ein
  Fehlversuch: `protokoll->fehler(laufId, 'SICHERUNG_GESPERRT', 'Tagessicherung war durch einen anderen Vorgang
  gesperrt.')`, Tageslauf-Zähler/Wiederholung alle 30 min bzw. `fehlgeschlagen` nach 4 Versuchen (identisch zum
  bisherigen Fehlerpfad), manueller Auftrag → `fehlgeschlagen` mit `ergebnis.fehlerCode`.
- `src/Console/Command/SicherungErstellenCommand.php` – ruft `Tagessicherung::erstelle(..., 30)` auf; bei
  `GESPERRT` protokolliert `fehler(..., 'SICHERUNG_GESPERRT', ...)`, Ausgabe „Tagessicherung war durch einen
  anderen Vorgang gesperrt.“, `Command::FAILURE` (vorher wäre `GESPERRT` als Erfolg ausgegeben worden).
- `src/DataService.php` – Aufruf `Tagessicherung::erstelle(..., false)` → `Tagessicherung::erstelle(..., 0)`
  (gleiches Verhalten, nur an die neue `int`-Signatur angepasst).
- Geprüft: `tests/Api/SystemPlanerApiTest.php::testBekannterBefundSicherungsSperreDarfNichtAlsErfolgZaehlen` jetzt
  **grün** (Laufzeit durch die 30 s Wartezeit beim Planer-Aufruf, wie im Befund akzeptiert).

**2. Major – Protokollfehler konnten den Mailablauf abbrechen:**
- `cron_backup_email.php` – Versand-`try` enthält jetzt ausschließlich ZIP lesen (`file_get_contents`) und
  `MailService::send()`. `JobProtokoll`-Konstruktion (`$protokoll` jetzt nullable, Konstruktion in eigenem
  `try/catch(\Throwable)` mit `error_log`, bei Fehlschlag bleibt `$protokoll = null`), der Erfolgs-Eintrag nach
  gesendeter Mail und alle Aufrufe des Fehler-Helfers `protokolliereBackupmailFehler()` sind jeweils in einem
  eigenen `try/catch(\Throwable)` mit nur `error_log()` gekapselt (die Funktion selbst kapselt ihren Rumpf und
  ignoriert `$protokoll === null`). Dadurch kann eine `PDOException` (die als `RuntimeException` sonst in den
  Versand-`catch` gelaufen wäre) den bereits erfolgreichen Versand nicht mehr als Fehlschlag erscheinen lassen.
  Das Lock-File (`backup_email_sent.txt`) wird jetzt immer direkt nach erfolgreichem Versand geschrieben,
  unabhängig vom Ausgang der Protokollierung – verhindert den gemeldeten Doppelversand.

**3. Major – laufende manuelle Aufträge konnten hängen:**
- `src/Services/Jobs/Planer.php` – `haengendeLaeufeAbschliessen()`: Beim Abschließen eines hängenden Jobs
  (`laeuftSeit` > 3 h) werden jetzt zusätzlich dessen `job_auftraege` mit `status = 'laufend'` auf
  `'fehlgeschlagen'` mit `ergebnis = {"fehlerCode":"AUFTRAG_ABGEBROCHEN"}` gesetzt. Zusätzliches Sicherheitsnetz
  (unabhängig von `job_status`): alle Aufträge mit `status = 'laufend'` und `aktualisiertAm` älter als 3 h werden
  ebenso abgeschlossen.
- `src/Handlers/SystemJobActions.php` – `auftragAnfordern()`: neue Konstante
  `AUFTRAG_LAUFEND_MAX_STUNDEN = 3` (muss zu `Planer::HAENGENDER_LAUF_STUNDEN` passen, dort dokumentiert). Die
  Abfrage nach einem bestehenden Auftrag liefert eine ID jetzt nur noch für `'wartend'` (nicht abgelaufen:
  `ablaufAm = '' OR ablaufAm >= jetzt`) oder `'laufend'` (nicht älter als 3 h: `aktualisiertAm >= jetzt-3h`);
  andernfalls wird wie zuvor ein neuer Auftrag angelegt.

**4. Minor – Migration:**
- `migrations/20261016000000_create_system_job_tables.php` – `job_status.job` (Z. 25) und `job_auftraege.id`
  (Z. 56) von `TEXT PRIMARY KEY` auf `TEXT NOT NULL PRIMARY KEY` geändert (SQLite impliziert bei
  Nicht-`INTEGER`-Primärschlüsseln kein `NOT NULL`). Geprüft: `PgsqlDialect::translateDdl()` hat keine eigene
  Regel für einfaches `TEXT ... PRIMARY KEY` – die Zeile wird unverändert durchgereicht und ist in PostgreSQL
  gültiges DDL (`PRIMARY KEY` impliziert dort ohnehin `NOT NULL`); per Testskript bestätigt (`translate()`
  liefert `TEXT NOT NULL PRIMARY KEY` unverändert zurück). Migration noch unveröffentlicht (VERSION unverändert),
  daher direkt geändert statt neuer Migration.
- `php tests/bin/build-backup-fixture.php` ausgeführt → `tests/fixtures/backups/v3.0.19.zip` +
  `v3.0.19.expected.json` neu geschrieben. **Meldung:** `VERSION` enthält weiterhin `3.0.19` (wie angewiesen
  nicht geändert), das Skript leitet den Dateinamen aus der `check`-Antwort (letztlich `VERSION`) ab – der
  Dateiname bleibt deshalb `v3.0.19.*` und wurde **nicht** auf `v3.0.20` umbenannt; kein Namenskonflikt, da die
  im Auftrag erwähnte Versionsanhebung auf 3.0.20 in dieser Runde nicht stattgefunden hat.

**Ausgeführte Prüfungen (Ergebnis):**
- `php -l` auf allen 7 geänderten Dateien → alle ok.
- `php tests/bin/build-backup-fixture.php` → Fixture `v3.0.19.*` neu geschrieben (s. o.).
- `php vendor/bin/phpunit --filter "PlanerFaelligkeitTest|SystemPlanerApiTest|Backup|Console"` (SQLite) →
  **48 Tests, 47 grün, 1 übersprungen** (keine roten Tests mehr; Laufzeit ~58 s wegen der 30 s Wartezeit im
  Sperre-Test, wie im Befund akzeptiert).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → **[OK] No errors** (keine neuen Befunde).

**Nicht geprüft (liegt außerhalb der Entwickler-Rolle):** PostgreSQL-Lauf der Testsuite, neue Testfälle,
visuelle Abnahme, Commit/Release.

**Abweichungen vom Vertrag:** keine; alle vier Befunde wie vom Leitstand beschrieben behoben.

#### T2 – Abnahme Leitstand (2026-10-16)

- Gelaufene Modelle laut Laufzeit: Entwickler `claude-sonnet-5`, Tester `gpt-5.3-codex`, Reviewer `gpt-5.5` (E-089 eingehalten).
- Fixture: Das Skript benennt die Datei nach der laufenden Version. Deshalb hat der Leitstand sie mit VERSION 3.0.20 erzeugt (`tests/fixtures/backups/v3.0.20.*`), `v3.0.19.*` entfernt und VERSION bis zum Release-Commit auf 3.0.19 zurückgesetzt.
- Doku korrigiert (E-081): „Datensicherung & NAS“ → „Datensicherung“ in Anleitung, `docs/installation.md` und CHANGELOG, denn der Menüpunkt heißt bis T4 „Datensicherung“.
- Sichtprüfung (Admin, Wegwerf-DB auf 20261016000000 migriert):
  - Die Karte „Serversicherung (zeitgesteuert)“ zeigt ohne Herzschlag den roten Hinweis.
  - „Tageslauf jetzt ausführen“ liefert `job_anfordern` 200; danach erstellt `system:planer` per CLI die Tagessicherung `backups/2026-10-16`.
  - Die Karte zeigt anschließend Erfolg, Herzschlag und einen Laufeintrag „manuell ✔“; der Hinweis verschwindet.
- Zeitzone: Lokales Windows-PHP läuft in UTC. Im Container gilt `date.timezone = Europe/Berlin` (Dockerfile.app) für CLI und Apache, der Cron erhält zusätzlich `TZ` → kein Befund.
- Gesamtläufe: PHPUnit SQLite 557 grün (2 übersprungen), PostgreSQL 557 grün (3 übersprungen); `test:js`, PHPStan (No errors) und `php-cs-fixer` grün.
- Version 3.0.20.

## 4. Tests

### T1 – Runde 1 (Tester, Modell: GPT-5.5 (copilot))

**GRÜN**

- Neu: `tests/js/verwaltung-nav.test.mjs`
- Ausgeführt:
  - `npm.cmd run test:js` → **18/18 grün**
  - `node scripts/check-versions.mjs --strict` → **OK – alle ?v=-Versionen sind konsistent.**

| Abnahmekriterium | Test (Datei::Testname) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| AK 1 (Bereichsliste T1, Reihenfolge, Titel) | `tests/js/verwaltung-nav.test.mjs::BK_VERWALTUNG_BEREICHE enthält Vertrags-Bereiche eindeutig und sortiert` | n/a – reines Frontend | n/a – reines Frontend | grün |
| AK 1 (Bereichs-Fallback bei ungültig/unsichtbar/leer) | `tests/js/verwaltung-nav.test.mjs::bkVerwaltungBereich nutzt gespeicherte ID oder fällt robust auf sichtbare Bereiche zurück` | n/a – reines Frontend | n/a – reines Frontend | grün |
| AK 1 (Suche-Normalisierung inkl. Umlaute/Whitespace) | `tests/js/verwaltung-nav.test.mjs::bkVerwaltungNormalisiere normalisiert Groß-/Kleinschreibung, Umlaute und Leerzeichen` | n/a – reines Frontend | n/a – reines Frontend | grün |
| AK 1 (Suche-Treffer inkl. SMTP/Personalnummer/Umlaut/Mehrwort/leer/kein Treffer) | `tests/js/verwaltung-nav.test.mjs::bkVerwaltungTreffer erfüllt Suche nach Vertrag (SMTP, Personalnummer, Umlaut, Mehrwort)` | n/a – reines Frontend | n/a – reines Frontend | grün |
| AK 1 (statischer Wächter: `data-bereich` nur Vertrags-IDs + Pflicht-Element-IDs vorhanden) | `tests/js/verwaltung-nav.test.mjs::Wächter: renderAllgemeinSettings nutzt nur bekannte data-bereich-IDs und behält Pflicht-Element-IDs` | n/a – reines Frontend | n/a – reines Frontend | grün |
| AK 1 + AK 15 (statisch: Einbindung/Precache-Version konsistent) | `tests/js/verwaltung-nav.test.mjs::Statisch: index lädt verwaltung-nav.js vor script.min.js, sw.js precacht dieselbe Version` | n/a – reines Frontend | n/a – reines Frontend | grün |

### T2 – Runde 1 (Tester, Modell: GPT-5 (copilot))

**ROT** (1 bekannter Produktivcode-Befund bleibt offen, Test absichtlich nicht abgeschwächt)

- Neu:
  - `tests/Unit/PlanerFaelligkeitTest.php`
  - `tests/Api/SystemPlanerApiTest.php`
- Ausgeführt:
  - `php vendor/bin/phpunit --filter "PlanerFaelligkeitTest|SystemPlanerApiTest"` (SQLite) → **15 Tests, 14 grün, 1 rot**
  - PostgreSQL gezielt für dieselben Klassen via Wegwerf-Container (entspricht `scripts/test-pgsql.ps1`, wegen lokaler ExecutionPolicy manuell äquivalent gestartet) → **15 Tests, 14 grün, 1 rot**
  - `php vendor/bin/php-cs-fixer check --diff tests/` → **grün**

**ROT-Befund (bekannt, nicht abgeschwächt):**
- `tests/Api/SystemPlanerApiTest.php::testBekannterBefundSicherungsSperreDarfNichtAlsErfolgZaehlen`
  - Erwartet: Bei gehaltenem `data/run/sicherung.lock` darf der Tageslauf nicht als `tageslaufStatus = ok` zählen.
  - Erhalten: `tageslaufStatus = ok` (Lauf wird trotz Sperre als Erfolg gewertet).
  - Vermutete Ursache: `Planer::sicherungAusfuehren()` behandelt `Tagessicherung::erstelle(...)=gesperrt` derzeit wie Erfolg.

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| AK 2 (zeitgesteuerte Tagessicherung; kein zweiter Tagesversuch am selben Tag) | `tests/Api/SystemPlanerApiTest.php::testPlanerErstelltTaeglicheSicherungNurEinmalUndSetztStatus` | grün | grün | n/a |
| AK 2 + AK 14 (ungültiger Tagesordner wird ersetzt; `.tmp-*` nicht gelistet) | `tests/Api/SystemPlanerApiTest.php::testUngueltigerTagesordnerWirdErsetztUndTmpOrdnerNichtGelistet` | grün | grün | n/a |
| AK 2 (Aufbewahrung 3–90 betrifft nur Tagessicherungen) | `tests/Api/SystemPlanerApiTest.php::testAufbewahrungBehaeltNurDreiNeuesteTagessicherungenUndLaesstAndereKategorienStehen` | grün | grün | n/a |
| AK 2 (Speichern-Rückfall bleibt funktionsfähig) | `tests/Api/SystemPlanerApiTest.php::testSpeichernErstelltTagessicherungAlsRueckfallOhneBestehendeSicherung` | grün | grün | n/a |
| AK 3 + R6 (Backup-Mail-Status: deaktiviert ohne Eintrag, Fehler dedupliziert je Zyklus) | `tests/Api/SystemPlanerApiTest.php::testCronBackupEmailProtokolliertDeaktiviertNichtUndDedupliziertFehlerImSelbenZyklus` | grün | grün | n/a |
| AK 11 (Rechte, POST-Pflicht, Validierung, kein Payload-Leak, keine Pfad-Strings, `check` unverändert) | `tests/Api/SystemPlanerApiTest.php::testSystemJobsApiRechteMethodenUndValidierung` | grün | grün | n/a |
| AK 11 (Auftragsfluss) | `tests/Api/SystemPlanerApiTest.php::testAuftragWirdNachPlanerLaufAlsFertigGemeldet` | grün | grün | n/a |
| AK 12 (Restore importiert keine Job-Tabellen; Fixture v3.0.19 in Fixture-Suite enthalten) | `tests/Api/SystemPlanerApiTest.php::testRestoreImportiertKeineJobTabellenUndFixtureV3019IstImFixtureTestEnthalten` | grün | grün | n/a |
| AK 13 (Planer-Cron/CLI nutzt dieselbe DB wie Webprozess im PG-Lauf) | `tests/Api/SystemPlanerApiTest.php::testPlanerErstelltTaeglicheSicherungNurEinmalUndSetztStatus`, `tests/Api/SystemPlanerApiTest.php::testAuftragWirdNachPlanerLaufAlsFertigGemeldet` | grün | grün | n/a |
| Vertrag 6 (Planer-Fälligkeit: Erstversuch/Wiederholung/Stop-Regeln) | `tests/Unit/PlanerFaelligkeitTest.php::*` | grün | grün | n/a |
| Leitstand-Befund (Sperre darf nicht als Erfolg zählen) | `tests/Api/SystemPlanerApiTest.php::testBekannterBefundSicherungsSperreDarfNichtAlsErfolgZaehlen` | **rot** | **rot** | n/a |

### T2 – Runde 2 (Tester, Modell: GPT-5 (copilot))

**GRÜN**

- Geändert/neu:
  - `tests/Api/SystemPlanerApiTest.php`
- Ausgeführt:
  - `php vendor/bin/phpunit --filter "PlanerFaelligkeitTest|SystemPlanerApiTest|BackupFixture"` (SQLite) → **22 Tests, 22 grün**
  - derselbe Filter auf PostgreSQL im Wegwerf-Container (E-064, manuell äquivalent zu `scripts/test-pgsql.ps1`) → **22 Tests, 22 grün**
  - `php vendor/bin/php-cs-fixer check --diff` → **grün**

| Abnahmekriterium / Befund | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| Leitstand-Befund 1 (Sperre zählt nicht als Erfolg) | `tests/Api/SystemPlanerApiTest.php::testBekannterBefundSicherungsSperreDarfNichtAlsErfolgZaehlen` | grün | grün | n/a |
| Leitstand-Befund 1 (CLI `sicherung:erstellen` bei gehaltener Sperre: Exit-Code ≠ 0, kein neuer Tagesordner) | `tests/Api/SystemPlanerApiTest.php::testSicherungErstellenCommandScheitertBeiGehaltenerSperreOhneNeuenTagesordner` | grün | grün | n/a |
| Leitstand-Befund 2 (hängender manueller Auftrag >3 h wird auf `fehlgeschlagen` mit `AUFTRAG_ABGEBROCHEN` gesetzt; neue Anforderung liefert neue ID) | `tests/Api/SystemPlanerApiTest.php::testHaengenderLaufenderAuftragWirdAbgebrochenUndNeueAnforderungErhaeltNeueId` | grün | grün | n/a |
| Leitstand-Befund 2 (frischer `laufend`-Auftrag <3 h wird wiederverwendet) | `tests/Api/SystemPlanerApiTest.php::testFrischerLaufenderAuftragWirdBeiNeuerAnforderungWiederverwendet` | grün | grün | n/a |
| Leitstand-Befund 3 (Protokollfehler bricht fachlichen Backup-Mail-Fehlerpfad nicht ab) | `tests/Api/SystemPlanerApiTest.php::testCronBackupEmailMitProtokollfehlerBleibtBeimFachlichenEmpfaengerFehler` | grün | grün | n/a |
| AK 12 (Restore importiert keine Job-Tabellen; Fixture-Suite enthält jetzt `v3.0.20`) | `tests/Api/SystemPlanerApiTest.php::testRestoreImportiertKeineJobTabellenUndFixtureV3020IstImFixtureTestEnthalten` | grün | grün | n/a |
| Fixture-Regression (v3.0.20 importierbar) | `tests/Api/BackupFixtureImportTest.php::testFixtureLaesstSichEinspielen` (Datensatz `v3.0.20.zip`) | grün | grün | n/a |

## 5. Review

### T1 – Runde 1 (Reviewer, Modell: GPT-5.5 (copilot))

**Urteil:** APPROVE

**Prüfungen:** `npm.cmd run test:js` → 18/18 grün; `node scripts/check-versions.mjs --strict` → OK, `CACHE_VERSION = bk-es-v239`.

**Befunde:**

| Nr | Schwere (Blocker/Major/Minor/Hinweis) | Datei:Zeile | Befund | Vorschlag |
|---|---|---|---|---|
| 1 | Hinweis | `public/script.js:11689` | Wird bei aktiver Suche ein Bereich gewählt, blendet `vwBereichWaehlen()` den Bereich korrekt ein, lässt den nicht mehr angewendeten Suchbegriff in `#vwSuche` aber stehen. Das ist kein Funktionsbruch, kann aber irritieren. | Optional beim manuellen Bereichswechsel das Suchfeld leeren oder den Suchmodus sichtbar beibehalten. |
| 2 | Hinweis | `tests/js/verwaltung-nav.test.mjs:125` | Die neuen Tests prüfen die reinen Such-/Fallback-Helfer und statisch `data-bereich`/Pflicht-IDs. Rollenvarianten (admin/master/normal), DOM-Navigation nach Suche und Dark-Mode bleiben Test-/Manuell-Lücken. | Optional einen DOM-nahen Test oder eine feste manuelle Checkliste für master/normal + Dark-Mode ergänzen. |

**Geprüft ohne Befund:** HTML-Verschachtelung in `renderAllgemeinSettings()` ist für admin/master/sonstige plausibel; alle Karten liegen in `#vwInhalt`, Abweichung 4 (Branche-Karte) ist korrekt gekapselt. Neue `innerHTML`-Stellen verwenden nur konstante Bereichsdaten bzw. `esc(...)`; keine neuen unescaped Nutzerdaten gefunden. Rollenlogik für neue Dialog-Knöpfe passt zur bisherigen Admin/Master-Backup-Logik. Anleitung, Changelog, Cache-Busting, `script.min.js` und `sw.js` sind konsistent. Keine neu eingeführten Kodierungsartefakte (`Ã`, `â€`) in den geänderten Diff-Zeilen gefunden.

**Lernpunkt-Kandidaten:** Keine.

### T2 – Runde 1 (Reviewer, Modell: gpt-5.5) – vom Leitstand eingetragen (Reviewer schrieb wegen paralleler Tester-Arbeit nicht ins AP)

**Urteil:** CHANGES_REQUESTED

| Nr | Schwere | Datei:Zeile | Befund | Ergebnis |
|---|---|---|---|---|
| 1 | Blocker | `Planer.php:108-111`, `Tagessicherung.php:42`, `SicherungErstellenCommand.php:23-24` | `gesperrt` zählte als Erfolg, Tageslauf `ok` ohne Sicherung (Leitstand-Befund bestätigt, Tester-Test rot) | behoben: Wartezeit 30 s, `SICHERUNG_GESPERRT` als Fehlversuch |
| 2 | Major | `cron_backup_email.php:206-230` | Protokollfehler (PDOException ⊂ RuntimeException) nach dem Versand → `exit(1)`, keine Sperrdatei → doppelter Versand (vom Leitstand an der Fundstelle bestätigt) | behoben: Protokoll in eigenem try/catch |
| 3 | Major | `Planer.php:65-66,145-147`, `SystemJobActions.php:77` | laufende manuelle Aufträge konnten dauerhaft hängen | behoben: `AUFTRAG_ABGEBROCHEN`, neue ID |
| 4 | Minor | Migration Z. 25, 56 | Text-PKs ohne `NOT NULL` | behoben |

### T2 – Runde 2 (Reviewer, Modell: gpt-5.5)

**Urteil:** APPROVE – alle 4 Vorbefunde behoben, keine neuen Befunde (`php -l`, PHPStan, 52 gezielte Tests grün).

## 6. Lernpunkte

- E-021, E-022 (Backup/Migration) aus Review T2 in `erkenntnisse.md`, erstes Auftreten → nur dort.

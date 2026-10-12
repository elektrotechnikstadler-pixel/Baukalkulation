# AP-20261012-datensicherung-nas: Datensicherung, NAS-Abgleich, Überwachung, Verwaltung gliedern, PostgreSQL-Bereich

| Feld | Wert |
|---|---|
| Status | freigegeben |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
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

## 4. Tests

## 5. Review

## 6. Lernpunkte

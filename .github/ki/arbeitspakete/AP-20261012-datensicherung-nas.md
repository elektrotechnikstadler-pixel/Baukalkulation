# AP-20261012-datensicherung-nas: Datensicherung, NAS-Abgleich, Überwachung, Verwaltung gliedern, PostgreSQL-Bereich

| Feld | Wert |
|---|---|
| Status | T1 → 3.0.19, T2 → 3.0.20, T3 + T4 → 3.0.23 abgeschlossen, CI-Folgefix → 3.0.24; T5 (PostgreSQL-Bereich) offen |
| Typ | Feature |
| Testläufe rot | T2: 1 · T3: R2 2 (N1; Test widersprach Vertrag), R3 1 (Folge N1) · T4: R1 8/12 nur Linux PHP 8.2 (Leitstand), R2 1 (neue Kennzahl), R3 2 (Filterstrings, `pfad`-Trim) – Testanpassungen jeweils mit Begründung (E-090) |
| Review-Runden | T1: 1 · T2: 2 · T3: 3 · T4: 3 |
| Commit | T1 `6406362` · T2 `bcb28a8` · T3 + T4 siehe Abschnitt 3 |

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

### T3 – Überwachung und Systemmeldungen (Schnitt durch Leitstand, 2026-11-01)

> Wiederhergestellt durch den Leitstand (2026-11-01): Vertrag, Umsetzung Runde 1 und Testberichte zu T3 sowie Vertrag und Umsetzung Runde 1 zu T4 fehlten nach einem unbeabsichtigten Überschreiben dieser Datei; die Ursache ließ sich nicht mehr klären (ein `git stash` des Entwicklers enthielt das AP nicht). Wiederhergestellt aus dem Leitstand-Verlauf, den Agenten-Rückmeldungen und `git show HEAD`.

Ausgangslage: Seit T2 sind 3.0.21 und 3.0.22 erschienen (Zeiterfassung, PHP-CVE), ohne Überschneidung mit T3. Auf Wunsch des Nutzers (2026-11-01) werden T3 und T4 gemeinsam als **3.0.23** veröffentlicht.

Vertrag T3 (bindend; Abweichungen im AP melden, E-084). Zeitstempel wie in T2 `Y-m-d H:i:s` (Ortszeit); Uhren und Mailversand injizierbar (E-034); nur feste Texte in DB, Mails und Antworten (E-014).

1. **Migration** `AddBeobachtetSeitToJobStatus`: `ALTER TABLE job_status ADD COLUMN beobachtetSeit TEXT NOT NULL DEFAULT ''`. Fixture mit Zielversion erzeugen (E-022); `v3.0.20.*` bleibt als ältere Fixture.
2. **`MailService::send()`**: optionaler Schlüssel `smtp_timeout` (Sekunden); ohne ihn bleibt das Verhalten unverändert (PHPMailer-Standard 300 s).
3. **`SmtpKonfig::laden(PDO)`**: SMTP-Konfiguration wie in `cron_backup_email.php` (nicht entschlüsselbares Passwort → leer + `error_log`); `cron_backup_email.php` wird nicht umgebaut.
4. **Einstellungen** `ueberwachung` mit den Standardwerten `{aktiv: true, empfaenger: [], ausbleibenStunden: 26, speicherMinMb: 1024}`.
   - `speichernUeberwachung()` prüft: höchstens 10 gültige, entdoppelte Empfänger; `ausbleibenStunden` ganze Zahl 25–168 (Abweichung 6; nach Review-Befund 8 → **26–168**); `speicherMinMb` 100–1 000 000; `aktiv` bool.
5. **Planer**, Reihenfolge der Schritte:
   1. Herzschlag.
   2. Hängende Läufe und Aufträge.
   3. Aufträge für `sicherung` und `probe_wiederherstellung` annehmen.
   4. `sicherung`.
   5. `probe_wiederherstellung` (monatlich ab 04:00, Wiederholungen wie bei der Sicherung).
   6. `speicherplatz` (stündlich; `SPEICHER_KNAPP`; ein Laufeintrag nur bei Zustandswechsel).
   7. `Ueberwachung::pruefen()`.
   8. `MeldungsZustellung::zustellen()`.
   9. Aufräumen.

   Jeder Schritt läuft in einem eigenen `try/catch` (E-022). `faelligkeit(…, $periode = 'tag'|'monat')`.
   - `WiederherstellungsProbe::pruefe()`: neueste Tagessicherung über `BackupArchive::fromDir()`, Kopie der DB, `PRAGMA integrity_check`; Fehlercodes `PROBE_KEINE_SICHERUNG`, `PROBE_DEFEKT`.
6. **`Ueberwachung::pruefen()`**:
   - **Aktive Jobs:** `sicherung`, `probe_wiederherstellung`, `speicherplatz` immer; `backupmail`, wenn aktiviert; `planer` erst nach dem ersten Herzschlag. Schonfrist über `beobachtetSeit`.
   - **Gemeldete Störung** = jüngster Eintrag `fehler`/`ausbleiben` nach der letzten Entwarnung.
   - **Fehler-Ereignisse:**
     - höchstens 1 je Job und Periode (UNIQUE-Schlüssel, R7);
     - `sicherung`/`probe` bei `tageslaufStatus = 'fehlgeschlagen'`;
     - `backupmail` bei Störung ≥ 60 min;
     - `speicherplatz` bei `SPEICHER_KNAPP`.
   - **Ausbleiben** nur ohne gemeldete Störung. Fristen: `ausbleibenStunden`; Probe 32 × 24 + 2 h; Backup-Mail nach Zyklus 26 h / 170 h / 746 h; Planer 15 min.
   - **Entwarnung** nur nach einer gemeldeten Störung, in einer Transaktion mit dem Zurücksetzen von `stoerungSeit`.
7. **`MeldungsZustellung`** (injizierbarer Sender):
   - offene Einträge jünger als 7 Tage;
   - Empfänger aus den Einstellungen, sonst aktive Admins mit E-Mail;
   - Backoff 5 min / 30 min / 2 h / 6 h;
   - HTML-Mail mit Jobname, Ereignis, Fehlertext und Handlungsempfehlung, ohne Pfade oder Geheimnisse.
8. **Watchdog** in `AuthActions::check()`:
   - fehlertolerant, höchstens alle 10 min (Sperrdatei und Zeitstempel);
   - Planer-Herzschlag > 15 min → Ausbleiben, danach Zustellung mit kurzem Zeitbudget;
   - die Antwort von `check` bleibt unverändert (E-013, E-088).
9. **API** (nur admin, POST/405):
   - `system_jobs_status` additiv erweitert;
   - `system_einstellungen_speichern` mit `ueberwachung`;
   - neu `system_hinweise` (GET);
   - neu `ueberwachung_testmail` (POST, 1/60 s, Audit);
   - `job_anfordern` zusätzlich mit `probe_wiederherstellung`.
10. **Frontend:**
    - Bereich `ueberwachung` (Ampel, Einstellungen, Empfänger, Testmail, Probe auslösen, Systemmeldungen, Laufprotokoll mit 30 Einträgen);
    - roter Admin-Banner über `system_hinweise` (für die Sitzung schließbar);
    - `verwaltung-nav.js?v=2`.
11. **Doku:** Bedienungsanleitung (E-081), `docs/installation.md`, CHANGELOG.

#### Umsetzung Runde 1 (Entwickler, Modell: claude-sonnet-5) – Zusammenfassung wiederhergestellt

- Migration `migrations/20261101000000_add_beobachtet_seit_to_job_status.php`.
- Neue Klassen in `src/Services/Jobs/`: `SmtpKonfig`, `WiederherstellungsProbe`, `Ueberwachung`, `MeldungsZustellung` (`__construct(?callable $absender)`, `zustellen(PDO, DateTimeImmutable, int $max = 20, int $timeoutSek = 30): int`, `empfaengerWirksam(PDO)`) und `Watchdog`.
- Geändert: `MailService`, `SystemEinstellungen`, `Planer` (9 Schritte), `SystemJobActions`, `AuthActions::check()`, `public/api.php`, Frontend, Doku.
- Prüfungen: `php -l`; Wegwerf-SQLite (Fehler → 1 Outbox-Eintrag → kein Duplikat → Erfolg → 1 Entwarnung); Fixture `v3.0.23` mit VERSION 3.0.23 erzeugt, danach wieder 3.0.22; gezielte PHPUnit-Tests 71/71; PHPStan 0; `test:js` 19/19; `check-versions` ok.
- Abweichung (E-084): Probe-Baseline für frische Installationen. In Runde 2 nach Review-Befund 5 umgebaut zum Status `uebersprungen`, nur ohne ältere gültige Tagessicherung.

#### Umsetzung Runde 2 (Entwickler, Modell: Claude Sonnet 5 (copilot)): Review-Befunde 1–17 behoben

Alle 17 vom Reviewer (gpt-5.5) gemeldeten Befunde bearbeitet (Befund 1 unabhängig auch vom
Leitstand gefunden); Befund 17 war laut Vorgabe reine Dokumentation ohne Code-Änderung. Keine
Tests geändert.

1. **Major – Doppelzustellung** (`MeldungsZustellung.php`): `zustellen()` beansprucht jeden
   Eintrag jetzt vor dem Senden per CAS-UPDATE auf `naechsterVersuchAm` (neue private
   `beanspruchen()`, Lease 10 min) – nur bei `rowCount() === 1` wird gesendet; das Ergebnis wird
   danach per `WHERE id = ? AND gesendetAm = ''` festgehalten. `Watchdog::vielleichtPruefen()`
   stellt nur noch zu, wenn der Planer-Herzschlag fehlt oder älter als 15 min ist (neue private
   `planerHerzschlagPruefen()` gibt das als bool zurück); läuft der Planer, liefert sie `false`
   und Watchdog ruft `zustellen()` gar nicht erst auf.
2. **Major – Dauerbanner** (`SystemJobActions.php::hinweise()`, `MeldungsZustellung::zustellen()`):
   Der Zustell-Hinweis zählt jetzt nur ungesendete Einträge mit `erstelltAm >=` jetzt−7 Tage und
   `versuche >= 3` (neue Konstante `HINWEIS_ALTER_GRENZE_TAGE`). `zustellen()` löscht beim
   Aufräumen zusätzlich ungesendete Einträge älter als 90 Tage (zweites `DELETE`).
3. **Watchdog-Tagesschlüssel** (`Watchdog.php`, `Ueberwachung.php`): `Ueberwachung::gemeldeteStoerung()`
   ist jetzt `public static` (vorher `private`) und wird von
   `Watchdog::planerHerzschlagPruefen()` vor dem Anlegen eines `ausbleiben`-Eintrags für `planer`
   genutzt – besteht bereits eine offene (nicht entwarnte) Störung, wird kein weiterer Tageseintrag
   erzeugt.
4. **Watchdog `aktiv` + Doppellauf** (`Watchdog.php::vielleichtPruefen()`): Liest zu Beginn
   `SystemEinstellungen::laden()` und kehrt bei `ueberwachung.aktiv === false` sofort zurück (keine
   Outbox-Einträge, keine Zustellung). Der Zeitstempel (`watchdog.stamp`) wird nach erfolgreichem
   `flock()` erneut gelesen und gegen die Drosselschwelle geprüft, bevor er geschrieben wird
   (schließt die Race zwischen dem ungesicherten Lesen und dem Sperren).
5. **Probe-Baseline** (`Planer.php::probeAusfuehren()/probeBaselineSetzen()`,
   `WiederherstellungsProbe.php`): Neue `tageslaufStatus`-Ausprägung `'uebersprungen'` statt `'ok'`
   (E-021); `Planer::faelligkeit()` behandelt sie für die laufende Periode wie `'ok'`/`'fehlgeschlagen'`.
   Neue `public static WiederherstellungsProbe::hatGueltigenTagesordnerVor(string $heute): bool`
   (prüft per `BackupArchive::fromDir()` auf einen gültigen Tagesordner vor `$heute`); die Baseline
   wird nur noch gesetzt, wenn das `false` liefert (reine Neuinstallation). Bestehende
   Installationen mit älteren Tagessicherungen überspringen die Baseline und werden zur ersten
   regulären Fälligkeit geprüft. UI/Anleitung sprechen dafür von „erste Probe im Folgemonat“.
   `SystemPlanerApiTest::testPlanerErstelltTaeglicheSicherungNurEinmalUndSetztStatus` bleibt grün
   (an diesem Punkt existiert noch kein älterer Tagesordner als heute, da `sicherung` im selben
   Lauf zuvor die Tagessicherung für heute erst anlegt).
6. **Zeitbudget** (`MeldungsZustellung::zustellen()`, `SystemJobActions::testmail()`): `zustellen()`
   hat jetzt den Parameter `int $budgetSek = 60`; vor jedem Sendeversuch wird das Restbudget
   geprüft und `smtp_timeout = max(1, min(timeoutSek, Restbudget))` gesetzt. Nach dem ersten
   vollständigen Fehlschlag eines Eintrags (kein Empfänger erreicht) bricht der gesamte Lauf ab
   (`break`), der betroffene Eintrag bekommt Backoff, weitere Einträge bleiben unangetastet.
   `Planer::lauf()` ruft `zustellen($db, $jetzt, 20, 30, 60)` auf, `Watchdog` mit
   `self::ZUSTELL_BUDGET_SEKUNDEN = 4`. `SystemJobActions::testmail()` hat ein eigenes
   Gesamtbudget `TESTMAIL_BUDGET_SEKUNDEN = 20`; nach dem ersten Fehlschlag erhalten die übrigen
   Empfänger `{ok:false, fehler:'Nicht versucht – vorheriger Versand fehlgeschlagen.'}`.
7. **Hinweise** (`SystemJobActions.php::hinweise()`): inaktive Jobs werden jetzt über
   `Ueberwachung::istJobAktiv()` ausgelassen; die Störungstexte nutzen die lesbaren Namen aus
   `MeldungsZustellung::JOB_NAMEN` (jetzt `public const`, gemeinsame Tabelle), inkl. `'planer' =>
   'Zeitplaner (Cron)'`.
8. **Ausbleiben während Wiederholungen** (`Ueberwachung.php::ausbleibenPruefen()`,
   `SystemEinstellungen.php`): Für `sicherung`/`probe_wiederherstellung` wird kein Ausbleiben
   geprüft, solange `tageslaufStatus` ∈ {`laufend`,`fehler`} **und** `tageslaufDatum` der aktuellen
   Periode entspricht. Untergrenze `ausbleibenStunden` von 25 auf **26** angehoben (Standard bleibt
   26) – **Änderung von Abweichung 6** (Kommentar in `SystemEinstellungen.php` entsprechend
   aktualisiert).
9. **Validierung** (`SystemEinstellungen.php::validierenUeberwachung()`): Fehlertexte enthalten
   keine Nutzereingabe mehr (z. B. „Empfängerliste enthält eine ungültige E-Mail-Adresse.“ statt
   der Adresse im Text); nicht-String-Adressen lösen direkt 400 aus (kein impliziter
   String-Cast/Warnung). `aktiv` akzeptiert nur echte `bool` oder
   `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`, sonst 400.
10. **UI Testmail/Ampel** (`script.js`, `bedienungsanleitung.html`): Bei 0 wirksamen Empfängern
    zeigt `uebTestmail()` „Keine Empfänger – bitte Adressen eintragen oder Admins eine E-Mail
    hinterlegen.“; sonst je Empfänger Symbol + Adresse + ggf. Fehlertext. `uebAmpelSymbol()`/neue
    `uebAmpelText()` unterscheiden jetzt `status.aktiv === false` (grau = inaktiv) von „noch kein
    Lauf“ (gelb, eigener Text); ein Legendentext über der Job-Tabelle nennt die Ausbleiben-Frist
    als Fristhinweis. `SystemJobActions::status()` liefert dafür je Job zusätzlich `aktiv`
    (`Ueberwachung::istJobAktiv()`). Anleitung Abschnitt 13 entsprechend angeglichen (E-081).
11. **Fehlercode in Mail** (`MeldungsZustellung::inhalt()`): Bei passender Regex wird zusätzlich
    zum festen Beschreibungstext eine eigene Zeile „Fehlercode: SICHERUNG_FEHLER“ (Rohcode)
    ausgegeben.
12. `MeldungsZustellung::empfaengerWirksam()`: SQL nutzt jetzt `COALESCE(isLocked, 0) = 0`.
13. **Kein `@`**: `Planer::speicherplatzPruefen()` (`disk_free_space`), `WiederherstellungsProbe::kopiePruefen()`
    (`unlink`/`rmdir`) und `SystemJobActions::testmail()` (`mkdir`) nutzen jetzt explizite
    `is_dir`/`is_file`-Prüfungen plus `set_error_handler(static fn(): bool => true)` /
    `restore_error_handler()` (gleiches Muster wie `UpdatePruefung.php`) statt `@` – nötig, weil
    der globale Error-Handler (`public/api.php`) Warnungen sonst trotzdem in Exceptions verwandelt
    (E-063).
14. **Herzschlag je Schritt** (`Planer.php::lauf()`): Die `$schritt()`-Closure erneuert in einem
    `finally`-Block nach jedem Schritt `$protokoll->herzschlag('planer')` (selbst fehlertolerant
    gekapselt) – wichtig für lange NAS-Läufe in T4.
15. `Ueberwachung.php`: `fehlerEreignisPruefen()`/`ausbleibenPruefen()` nutzen jetzt
    `$jetzt->modify('-1 hour')`/`$jetzt->getTimestamp()` statt `date()`/`strtotime()`;
    `statusLesen()` wurde in `statusLesen()` (legt an) und neue `statusLesenOhneAnlegen()`
    (legt nicht an) aufgeteilt – inaktive Jobs bekommen keine `job_status`-Zeile mehr.
    `Watchdog::vielleichtPruefen(\PDO $db, \DateTimeImmutable $jetzt, ?callable $absender = null)`
    hat jetzt einen optionalen injizierbaren Sender (E-034), durchgereicht an `new
    MeldungsZustellung($absender)`.
16. `script.js`: `sysHinweisSchluessel()` bildet einen Schlüssel aus Art+Job+Seit aller aktuellen
    Hinweise; `sessionStorage['bk.sysHinweis.geschlossen']` speichert diesen Schlüssel statt `'1'`
    – ändert sich die Hinweisliste, erscheint der Banner wieder. `doLogout()` entfernt den
    Schlüssel beim Abmelden.
17. **Nur Dokumentation:** Hinweis in `bedienungsanleitung.html` Abschnitt 13 und oben ergänzt:
    Bei `ueberwachung.aktiv=false` werden auch Entwarnungen unterdrückt; nach dem
    Wiedereinschalten kann eine Entwarnung zu einer älteren, zwischenzeitlich eventuell schon
    behobenen Störung eintreffen. Kein Code geändert (Vorgabe Leitstand).

**Geänderte/neue Dateien:** `src/Services/Jobs/MeldungsZustellung.php`,
`src/Services/Jobs/Ueberwachung.php`, `src/Services/Jobs/Watchdog.php`,
`src/Services/Jobs/WiederherstellungsProbe.php`, `src/Services/Jobs/SystemEinstellungen.php`,
`src/Services/Jobs/Planer.php`, `src/Handlers/SystemJobActions.php`, `public/script.js`,
`public/script.min.js`, `public/bedienungsanleitung.html`, `public/index.html`, `public/sw.js`.

**Ausgeführte Prüfungen (Ergebnis):**
- `php -l` auf allen 7 geänderten PHP-Dateien → alle ok.
- `php vendor/bin/phpunit --filter "UeberwachungMeldungsZustellungTest|PlanerFaelligkeitTest|SystemPlanerApiTest|BackupFixture|Auth"`
  (SQLite) → **61 Tests, 60 grün, 1 rot** (siehe unten, vorgegebene Verhaltensänderung).
- `php vendor/bin/phpunit` Gesamtlauf (SQLite) → **578 Tests, 577 grün, 1 rot, 2 übersprungen**
  (derselbe rote Test wie oben; keine weiteren Regressionen).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → **1 Fehler**, aber **vorbestehend und
  nicht von dieser Runde verursacht**: `tests/Api/SystemPlanerApiTest.php:700`
  (`method.alreadyNarrowedType`, `assertSame(1, …)` auf eine bereits als `1` erkannte Spalte).
  Per `git stash` der drei in dieser Runde geänderten getrackten Dateien
  (`SystemJobActions.php`, `Planer.php`, `SystemEinstellungen.php`) bestätigt: Der Fehler
  besteht identisch auch ohne meine Änderungen (Runde-1-Altlast in einer Testdatei, die ich
  nicht ändern darf). Keine neuen PHPStan-Befunde durch Runde 2.
- `npm run minify` → `public/script.min.js` neu erzeugt; `script.min.js?v=` in `index.html` und
  `sw.js` von 240 auf **241**, `CACHE_VERSION` von `bk-es-v243` auf **`bk-es-v244`**.
- `node --check public/script.js` → ok.
- `node scripts/check-versions.mjs --strict` → **OK – alle ?v=-Versionen sind konsistent.**
- `npm run test:js` → **19/19 grün**.
- `VERSION` unverändert `3.0.22`.

**Roter Test wegen vorgegebener Verhaltensänderung (nicht abgeschwächt, Test nicht geändert):**
- `tests/Api/SystemPlanerApiTest.php::testUeberwachungEinstellungenWerdenValidiertUndDuplikateEntfernt`
  – erwartet u. a., dass `ausbleibenStunden => 25` beim Speichern als gültig akzeptiert wird
  (`assertOk(...)`, Zeile 528/529). Durch Befund 8 (Untergrenze von 25 auf 26 angehoben) liefert
  dieser Aufruf jetzt `400 Ausbleiben-Frist muss eine ganze Zahl zwischen 26 und 168 Stunden sein.`
  statt `200`. Das ist die vom Reviewer vorgegebene Verhaltensänderung; der Test müsste vom
  Tester auf `26` angepasst werden.

**Abweichungen vom Vertrag:** keine; alle 17 Befunde wie vom Reviewer/Leitstand vorgegeben
umgesetzt. `php-cs-fixer` wurde in dieser Runde nicht geprüft (nicht Teil der für Runde 2
vorgegebenen Prüfliste); ein Stichprobenlauf zeigte projektweit vorbestehende
Formatierungsabweichungen (z. B. Leerzeile nach `<?php`), die nicht Teil dieser Änderung sind.

#### Umsetzung Runde 3 (Entwickler, Modell: Claude Sonnet 5 (copilot)): Review-Befunde N1–N9 behoben

Letzte Runde vor der Schleifenbremse – nur die vom Leitstand benannten Punkte N1, N2, N3, N4,
N7, N8, N9 bearbeitet (N5/N6 laut Vorgabe nicht angefasst; `SystemPlanerApiTest`-Verhalten rund
um `aktiv:"false"` nicht geändert).

1. **N1 Major – Budget/Abbruch nur je Empfänger statt je Eintrag** (`MeldungsZustellung.php`):
   `zustellen()` übergibt jetzt Startzeit (`$begin`), `$budgetSek` und `$timeoutSek` an
   `einenZustellen()`. Dort wird vor **jedem** Empfänger das Restbudget seit Laufbeginn neu
   berechnet und `smtp_timeout = max(1, min(timeoutSek, Restbudget))` gesetzt; ist das
   Restbudget < 1, bricht der Lauf sofort ab. Nach der **ersten** Exception bei einem Empfänger
   werden die übrigen Empfänger desselben Eintrags ausgelassen und der gesamte Zustelllauf
   beendet (`einenZustellen()` liefert jetzt `array{erfolg:bool,abbruch:bool}` statt eines
   String-Ergebnisses; die alten `ERGEBNIS_*`-Konstanten entfallen, da nicht mehr benötigt).
   Wurde vorher mindestens ein Empfänger erreicht, zählt der Eintrag als gesendet
   (Teil-Erfolg-Regel unverändert), sonst erhält er Backoff. Der rote Vertragstest
   `UeberwachungMeldungsZustellungTest::testZustelllaufBrichtNachErstemEmpfaengerFehlerAbUndSetztBackoff`
   ist jetzt grün.
2. **N2 Minor – Ausnahme nach dem Claim ohne Backoff** (`MeldungsZustellung.php::zustellen()`):
   Der Aufruf von `einenZustellen()` steht jetzt in einem `try/catch(\Throwable)`; jede
   Ausnahme nach `beanspruchen()` (Claim) löst `rueckfallBackoff()` (`versuche++`,
   `naechsterVersuchAm` nach der bestehenden Backoff-Tabelle) aus, loggt per `error_log()` und
   beendet den Lauf – ein Eintrag bleibt dadurch nie nur mit der 10-min-Lease ohne erhöhten
   Versuchszähler stehen.
3. **N3 Minor – `aktiv` vor der Stamp-Drosselung gelesen** (`Watchdog.php::vielleichtPruefen()`):
   Das Laden von `SystemEinstellungen::laden()` sowie die `aktiv`-Prüfung erfolgen jetzt erst
   **nach** der ungesicherten Stamp-Drosselung (`stampLesen()` vs. `DROSSEL_SEKUNDEN`); bei
   jedem (meist gedrosselten) `check()`-Aufruf werden die Einstellungen dadurch nicht mehr
   unnötig geladen.
4. **N4 Minor – Probe-Text bei `uebersprungen`** (`public/script.js`): `uebAmpelText()` liefert
   bei `status.tageslaufStatus === 'uebersprungen'` jetzt „Probe ab nächstem Monat“ (vor der
   bisherigen „noch kein Lauf“-Prüfung); `uebJobZeile()` zeigt denselben Text in der
   Fehlertext-Spalte statt eines leeren Felds.
5. **N7 Hinweis – fehlendes `unset($row)`** (`SystemJobActions.php::letzteMeldungen()`): Nach
   `foreach ($rows as &$row) { … }` steht jetzt `unset($row);`, um eine hängende Referenz auf das
   letzte Array-Element zu vermeiden.
6. **N8 Hinweis – stille Standardwerte bei Zahleneingaben** (`public/script.js`): Neue Hilfsfunktion
   `uebZahlEingabe()` sendet eine gültige Ganzzahl-Eingabe als Zahl, eine ungültige oder leere
   Eingabe aber **unverändert als Text** statt sie durch `26`/`1024` zu ersetzen; `speichereUeberwachung()`
   nutzt sie für `ausbleibenStunden`/`speicherMinMb`, sodass der Server bei ungültiger Eingabe mit
   400 antwortet und dieser Fehler (statt eines stillen Standardwerts) über `showNotification(j.error, 'error')`
   angezeigt wird.
7. **N9 Hinweis – Teil-Erfolg dokumentiert** (`public/bedienungsanleitung.html`, Abschnitt 13
   „Überwachung & Systemmeldungen“): Ein Satz im Punkt „Systemmeldungen“ ergänzt, dass ein
   erreichter Empfänger vor dem Fehlschlag eines anderen die Meldung als zugestellt gelten lässt
   und für den nicht erreichten Empfänger kein erneuter Versandversuch erfolgt.

**Abweichung vom Vertrag / neu entdeckter Testkonflikt (Test nicht geändert):**
Die bereits bestehende `UeberwachungMeldungsZustellungTest::testZustellungMarkiertBeiMindestensEinemEmpfaengerAlsGesendetUndEscapedHtml`
konfiguriert die Empfänger in der Reihenfolge `['fail@example.com', 'ok@example.com']` – der
scheiternde Empfänger steht also **vor** dem erfolgreichen. Mit dem durch N1 vorgegebenen
Verhalten (Abbruch der übrigen Empfänger nach der ersten Exception) wird `ok@example.com` dadurch
nie mehr versucht, der Eintrag bekommt Backoff statt als gesendet zu gelten – der Test wird dadurch
**rot** (`assertSame(1, $anzahl)` schlägt fehl, `0` erhalten). Das ist eine direkte, unvermeidbare
Folge der in N1 vorgegebenen Vertragsänderung (dieselbe Art Konflikt wie bereits in Runde 2 bei
Befund 8/Untergrenze 26 dokumentiert) und keine Abschwächung meinerseits – der Test müsste vom
Tester auf die Reihenfolge `['ok@example.com', 'fail@example.com']` angepasst werden, damit der
erfolgreiche Empfänger vor dem scheiternden steht.

**Prüfungen:**
- `php -l` auf `MeldungsZustellung.php`, `Watchdog.php`, `SystemJobActions.php` → ok.
- `php vendor/bin/phpunit --filter "UeberwachungMeldungsZustellungTest|PlanerFaelligkeitTest"`
  (SQLite) → **25 Tests, 24 grün, 1 rot** (der oben dokumentierte, vorgegebene Testkonflikt; der
  im Auftrag genannte Test `testZustelllaufBrichtNachErstemEmpfaengerFehlerAbUndSetztBackoff` ist
  grün).
- `php vendor/bin/phpunit --filter "SystemPlanerApiTest"` (SQLite, informativ, nicht angefasst)
  → **27 Tests, 27 grün**.
- `php vendor/bin/phpstan analyse --memory-limit=2G` → **No errors**.
- `npm.cmd run minify` → `public/script.min.js` neu erzeugt; `script.min.js?v=` in `index.html`
  und `sw.js` von 241 auf **242**, `CACHE_VERSION` von `bk-es-v244` auf **`bk-es-v245`**.
- `node --check public/script.js` → ok.
- `node scripts/check-versions.mjs --strict` → **OK – alle ?v=-Versionen sind konsistent.**
- `npm.cmd run test:js` → **19/19 grün**.
- `VERSION` unverändert `3.0.22`; kein Commit.

#### T3 – Abnahme Leitstand (2026-11-01)

- Gelaufene Modelle laut Laufzeit: Entwickler `claude-sonnet-5`, Tester `gpt-5.3-codex`, Reviewer `gpt-5.5` (E-089 eingehalten).
- 3 Entwicklerrunden. Die Schleifenbremse wurde erreicht, aber nicht überschritten: Runde 3 = letzte, APPROVE.
- Testanpassungen mit Begründung (E-090):
  - Untergrenze 25 → 26 (Befund 8).
  - `aktiv:"false"` gültig laut Vertrag (Befund 9).
  - Empfänger-Reihenfolge im Teil-Erfolg-Test (Folge von N1).
- G2: gezielte Klassen grün auf SQLite **und** PostgreSQL (56 Tests). G3: APPROVE in Runde 3.
- Gesamtläufe folgen auf Wunsch des Nutzers erst nach T4, über den gemeinsamen Stand.
- Offen nach T4: R1 (Empfänger-Rotation) und R3 (Text) → T4-Vertrag Punkt 9. N5 ist durch T4 Punkt 5 erledigt (eigener NAS-Prozess). N6 und R2/R4/R5 sind zurückgestellt (Minor/Nit).
- Leitstand-Fehler bei diesem Eintrag: Ein Node-Hilfsskript wurde per `Set-Content -Encoding ASCII` geschrieben. Die Umlaute in diesem Abschnitt wurden dadurch zu `?` und sind von Hand korrigiert (2. Auftreten der Ursache von E-087 → Lernprotokoll).

### T4 – NAS-Abgleich per rclone (Schnitt durch Leitstand, 2026-11-01)

Vorgabe Nutzer (2026-11-01): T3 und T4 nacheinander umsetzen, die Gesamtläufe auf SQLite und PostgreSQL erst danach einmal über den gemeinsamen Stand. Release: ein gemeinsames **3.0.23**. Es gibt zwei Feature-Commits (T3, T4); der Gesamtlauf gilt für den gemeinsamen Stand (Abweichung zu O7 und E-082 auf Wunsch des Nutzers).

**Vorab-Prüfung Leitstand (rclone 1.60.1 = Debian-Paket in bookworm/trixie, gegen eine echte Samba-Freigabe im Container):**
- `rclone obscure -` liest von stdin. `RCLONE_SMB_HOST/USER/PASS/PORT/DOMAIN` werden ausgewertet; `RCLONE_CONFIG` auf eine nicht vorhandene Datei unterdrückt nur eine NOTICE.
- `mkdir` und `rcat` funktionieren als Schreibprobe; ohne Schreibrecht → `permission denied`, Exit 1.
- Die `--filter`-Positivliste (unten) überträgt genau die freigegebenen Pfade. Nicht übertragen wurden `database.sqlite`, `database.sqlite-wal`, `secret.key`, `systemadmin-passwort.txt`, `audit_pepper.txt`, `run/`, `update/`, `backups/.tmp-*` und `cron.log`.
- `copy` lässt eine lokal gelöschte Datei auf der NAS bestehen (F3) und überträgt geänderte Dateien.
- Die letzte JSON-Logzeile enthält `"stats":{bytes, errors, transfers, lastError…}` (bei `--use-json-log --stats 1m --stats-log-level NOTICE`).
- Unterscheidbare Meldungen in der Verbindungsphase: `The attempted logon is invalid` (falsche Anmeldung), `no such host` / `dial tcp` (nicht erreichbar), `permission denied` (keine Schreibrechte).

**Bestandsaufnahme `data/`** (grep über `src/`, `modules/`, `public/*.php`, `cron_*.php`):
- Daten: `backups/` (ohne `.tmp-*`), `uploads/`, `bautagebuch/`, `datanorm/`, `archiv/`, `archiv_material/`, `exports/`, `pg-backups/`, `paths_config.json`, `firma_logo.*`, `audit.log`. Module schreiben nur nach `UPLOADS_DIR` (vde0100); `datanorm_index.tsv` ist neu erzeugbar → nicht nötig.
- Nie übertragen: `database.sqlite*`, `secret.key`, `systemadmin-passwort.txt`, `audit_pepper.txt`, `update/`, `run/`, `*.lock`, `migrate.lock`, `*_sent.txt`, `*_result.json`, `*_last.txt`, `update_check.json`, `project_presence.json`, `cron.log`, `erinnerung.log`, `baukalkulation.json` (Altbestand), `erinnerung_settings.json` (Altbestand), `datanorm_index.tsv`.

Vertrag T4 (bindend, E-084):

1. **Image** (`Dockerfile.app`): `rclone` per apt im gemeinsamen `apt-get install`-Block (unabhängig von `ENABLE_OCR`). Danach Build-Prüfung `rclone version` und `rclone help backend smb > /dev/null` (Build bricht sonst ab).
2. **Einstellungen** `system_einstellungen.nas`, Standardwerte `{aktiv:false, host:'', port:445, freigabe:'', pfad:'baukalkulation', benutzer:'', passwort:'', domaene:'', bandbreiteKBs:0, passwortNeuEingeben:false}`. `SystemEinstellungen::speichernNas(PDO, array)` prüft:
   - `host` `^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$` oder IPv4;
   - `port` 1–65535;
   - `freigabe` `^[A-Za-z0-9 ._\-]{1,80}\$?$`;
   - `pfad`: Segmente `^[A-Za-z0-9 ._\-]{1,64}$`, getrennt durch `/`, kein `.`/`..`, kein führender oder abschließender `/`, höchstens 200 Zeichen;
   - `benutzer` `^[A-Za-z0-9 ._@\-]{1,100}$`; `domaene` `^[A-Za-z0-9.\-]{0,100}$`;
   - `bandbreiteKBs` 0–1 000 000; `aktiv` bool.

   `aktiv=true` setzt `host`, `freigabe`, `benutzer` und ein gesetztes Passwort voraus.

   **Passwort:**
   - nur schreibbar: Ein leeres oder fehlendes Feld behält das bisherige Passwort, `passwortLoeschen:true` leert es;
   - gespeichert über `SecretBox::encrypt`;
   - ein neues Passwort setzt `passwortNeuEingeben=false`.

   **Ausgabe:** Antworten enthalten nie das Passwort, nur `passwortGesetzt` (bool) und `passwortNeuEingeben`.
3. **Wiederherstellen (R12):** Nach dem Import von `system_einstellungen` (Importer, beide Modi) ruft der Importer `SystemEinstellungen::secretsNachImportPruefen(PDO)` auf. Lässt sich `nas.passwort` nicht entschlüsseln, wird es geleert und `passwortNeuEingeben=true` gesetzt; der Hinweis erscheint in `system_hinweise`.
4. **`App\Services\Jobs\NasAbgleich`** – `__construct(\PDO $db, array $rcloneBefehl = ['rclone'], ?callable $uhr = null)`, Standard über die Umgebung `BK_RCLONE_BIN`, falls gesetzt (E-034; Tests übergeben z. B. `[PHP_BINARY, '<fake-rclone.php>']`):
   - **Prozessaufruf**:
     - `proc_open` immer mit Argument-Array, nie über die Shell.
     - Umgebung = Minimal-Env: `PATH`, `HOME` = `DATA_DIR/run`, `RCLONE_CONFIG` = `DATA_DIR/run/rclone-leer.conf` (nicht vorhanden), `RCLONE_SMB_HOST/PORT/USER/PASS/DOMAIN`.
     - `RCLONE_SMB_PASS` = Ausgabe von `rclone obscure -`; das Passwort geht dabei nur über stdin.
     - Zeitbegrenzung über eine `stream_select`-Schleife und danach `proc_terminate`.
     - Alle Ausgaben werden vor jedem `error_log` bereinigt: Host, Benutzer, Passwort und das verschleierte Passwort werden durch `***` ersetzt, die Länge wird gekürzt.
   - **`verbindungTesten(array $nasEinstellungen): array`** (höchstens 20 s gesamt, `--contimeout 10s --timeout 15s --retries 1 --low-level-retries 1`):
     - Ablauf: `mkdir :smb:<freigabe>/<pfad>`, dann `rcat :smb:<freigabe>/<pfad>/_baukalkulation_verbindungstest.txt` (stdin: Zeitstempel und App-Version).
     - Ergebnis `{ok, fehlerCode?}`.
     - Fehlercodes:
       - `NAS_ANMELDUNG` – Meldung enthält `logon is invalid`
       - `NAS_NICHT_ERREICHBAR` – `no such host`, `dial tcp`, `i/o timeout` oder `connection refused`
       - `NAS_KEINE_SCHREIBRECHTE` – `permission denied` oder `access denied`
       - `NAS_VERBINDUNG` – alle übrigen Fälle sowie Zeitüberschreitung
       - `NAS_RCLONE_FEHLT` – Programm nicht startbar
       - `NAS_KONFIGURATION` – Validierung
   - **`abgleichen(): array`** (Kennzahlen) – Phasen:
     1. Konfiguration prüfen (`NAS_KONFIGURATION`).
     2. Schreibprobe wie `verbindungTesten` (die dortigen Codes).
     3. `rclone copy <DATA_DIR> :smb:<freigabe>/<pfad>/data` mit `--filter`-Regeln in genau dieser Reihenfolge:
        - `- /database.sqlite*`, `- /secret.key`, `- /systemadmin-passwort.txt`, `- /audit_pepper.txt`, `- /update/**`, `- /run/**`, `- /backups/.tmp-*/**`, `- *.lock`
        - `+ /backups/**`, `+ /uploads/**`, `+ /bautagebuch/**`, `+ /datanorm/**`, `+ /archiv/**`, `+ /archiv_material/**`, `+ /exports/**`, `+ /pg-backups/**`, `+ /paths_config.json`, `+ /firma_logo.*`, `+ /audit.log`
        - `- **`

        Weitere Optionen: `--contimeout 15s --timeout 5m --retries 3 --use-json-log --stats 1m --stats-log-level NOTICE`, bei `bandbreiteKBs>0` zusätzlich `--bwlimit <n>k`.
     4. Für jeden Pfad aus `paths_config.json` (`backups`, `archiv`, `exports`, `bautagebuch`, `uploads`, `datanorm`), dessen `realpath` außerhalb von `realpath(DATA_DIR)` liegt: `copy <pfad> :smb:<freigabe>/<pfad>/extern/<schlüssel>` mit `- /.tmp-*/**`, `- *.lock`.
     5. Statusdatei per `rcat :smb:<freigabe>/<pfad>/_baukalkulation_status.json` (`{zeit, ergebnis, version, transfers, fehler}`); wird bei Erfolg **und** bei Transferfehlern geschrieben, ein Fehler dabei wird nur geloggt.

     Fehlercodes: Transfer-Exit ≠ 0 → `NAS_TRANSFER`; Gesamtdauer über **2 h 45 min** → `NAS_ZEIT`, mit Abbruch. Grund: Der Planer schließt Läufe ab 3 h als hängend ab, die NAS-Grenze muss darunter liegen. Ein abgebrochener Lauf ist unkritisch, weil `copy` beim nächsten Versuch bereits übertragene Dateien überspringt.

     Kennzahlen: `transfers`, `bytes`, `fehler`, `dauerSek`, `externePfade` (Anzahl) – keine Pfade, keine Hosts.
   - Jedes `copy` startet von einer Quelle, nie `sync`, `move`, `delete`, `purge`, `rmdir` o. Ä. Es gibt einen Test, der die Argumente prüft.
5. **Eigener Prozess statt Planer-Schritt (Leitstand-Erkenntnis aus T3, Review-Hinweis 14):** Ein NAS-Lauf kann beim ersten Abgleich Stunden dauern. Als Planer-Schritt hielte er die Planer-Sperre, Überwachung und Zustellung würden stundenlang ausfallen, und der Watchdog meldete fälschlich „Zeitplaner steht“. Deshalb:
   - **Neuer Befehl** `bin/console nas:abgleich` mit eigener Sperre `DATA_DIR/run/nas.lock` (`LOCK_NB`; ist sie belegt, endet er mit Exit 0) und **eigener Crontab-Zeile** in `Dockerfile.app` nach dem Muster aus R1:
     `* * * * * www-data . /etc/baukalkulation.env; php /var/www/html/bin/console nas:abgleich >> /var/www/html/data/cron.log 2>&1`
   - **Ablauf je Minute:**
     1. Herzschlag `nas_prozess`.
     2. Ist `nas.aktiv` false und kein Auftrag offen → Ende.
     3. Wartenden Auftrag `nas` annehmen (wie im Planer, `rowCount`).
     4. Fälligkeit: `faelligkeit(status('nas'), <Uhrzeit der Sicherung>, jetzt)`. Ein Erstversuch erfolgt nur, wenn die heutige Sicherung abgeschlossen ist (`sicherung.tageslaufStatus` ∈ {`ok`, `fehlgeschlagen`} für heute) **oder** jetzt ≥ Uhrzeit + 2 h. So wird die frische Sicherung mit übertragen.
     5. Wiederholungen wie bei `sicherung` (F10).
     6. Protokoll über `JobProtokoll` (Job `nas`).
   - Den Planer **nicht** um einen NAS-Schritt erweitern; seine Hängelauf-Erkennung (3 h) gilt auch für `nas`.
   - Manuelle Anforderung per `job_anfordern` mit `job:'nas'` (in `ANFORDERBARE_JOBS` aufnehmen).
   - Fehlercode im Protokoll = Code aus der Phase, Text fest.
   - `Ueberwachung` (läuft weiter im Planer):
     - `nas` ist aktiv bei `nas.aktiv`; Fehler-Ereignis bei `tageslaufStatus='fehlgeschlagen'` (`nas:<tag>:fehler`); kein Ausbleiben während `laufend`/`fehler` (wie bei Befund 8 von T3), sonst Ausbleiben über `ausbleibenStunden`.
     - Die Codetabelle und die Handlungsempfehlungen für Mails bekommen alle `NAS_*`-Codes.
     - Die Jobnamen-Tabelle bekommt `nas` = „NAS-Abgleich“.
   - Nicht-Docker-Installationen: Die zweite Cron-Zeile wird in `docs/installation.md` dokumentiert.
6. **API** (nur `admin`, POST, 405 nach der Rollenprüfung):
   - `system_jobs_status`: additiv `einstellungen.nas` (ohne Passwort, mit `passwortGesetzt`), `status.nas`.
   - `system_einstellungen_speichern`: zusätzlicher Schlüssel `nas`.
   - **Neu** `nas_verbindung_testen` (POST, optional Formularwerte; ein leeres Passwort nutzt das gespeicherte): synchron, höchstens 20 s, Drosselung 1 je 10 s (sonst 429), Eintrag im Audit-Log ohne Zugangsdaten.
   - **Neu** `secret_key_anzeigen` (POST `{passwort}`): Das Passwort des angemeldeten Admins wird erneut geprüft (`password_verify` gegen `users.password`, sonst 403); Drosselung 5 je 10 min je Benutzer; Antwort `{ok, schluessel}` mit `Cache-Control: no-store`, Eintrag im Audit-Log. Ist `BK_SECRET_KEY` gesetzt: `{ok:true, ausUmgebung:true}` ohne Schlüssel.
7. **Frontend** (Bereich `datensicherung`, nur admin; Bereichstitel wird „Datensicherung & NAS“ → `verwaltung-nav.js` `?v=` erhöhen):
   - **Karte „NAS-Abgleich“:**
     - Felder aktiv, Host, Port, Freigabe, Pfad, Benutzer, Passwort (Platzhalter „gespeichert – neu eingeben zum Ändern“), Domäne, Bandbreite.
     - Knöpfe Speichern, „Verbindung testen“ (Ergebnis als fester Text je Code), „Jetzt abgleichen“ (Auftrag und Polling wie in T2).
     - Anzeige von Status und letztem Lauf mit Kennzahlen; Hinweis, wenn `passwortNeuEingeben` gesetzt ist.
     - Infotext: Auf der NAS wird nie gelöscht; Empfehlungen E4/E5; der bisherige Host-Cronjob erst abschalten, wenn der Abgleich 3 Tage grün war.
   - **Karte „Schlüssel separat aufbewahren“ (F9):**
     - Erklärung; Knopf „Schlüssel anzeigen“ mit Passwortabfrage; Anzeige in einem schreibgeschützten Feld mit Kopieren und Herunterladen als `baukalkulation-secret.key`.
     - Nach einem Bereichswechsel wird der Schlüssel aus dem DOM entfernt.
     - Bei `ausUmgebung` erscheint der Hinweis „Schlüssel kommt aus der Umgebung (`BK_SECRET_KEY`)“.
   - Darstellung nur über `textContent` bzw. `esc()`; Cache-Busting nach Vorgabe.
8. **Doku:**
   - `public/bedienungsanleitung.html` (E-081): NAS-Abgleich, Schlüssel, Übergang vom Host-Cron.
   - `docs/installation.md`: rclone im Image; Übergang vom Host-Cron; externe Überwachung (E6: NAS prüft das Alter von `_baukalkulation_status.json`, z. B. Synology-Aufgabenplaner, oder Uptime Kuma auf `api.php?action=check`); Empfehlungen E4/E5; Restore ohne Schlüssel → Passwörter neu eingeben.
   - `CHANGELOG.md` unter „Unveröffentlicht“ (gemeinsamer Abschnitt mit T3).
9. **Nachzug aus Review T3 Runde 3:**
   - **R1:** In `MeldungsZustellung::einenZustellen` die Empfängerliste je Versuch um `versuche % anzahl` rotieren. So verhindert ein dauerhaft abgelehnter erster Empfänger (z. B. SMTP 550) nicht dauerhaft die Zustellung an alle anderen.
   - **R3:** Text in `testmail()` bei aufgebrauchtem Budget: „Nicht versucht – Zeitbudget aufgebraucht.“
   - Weitere Änderungen an T3-Code nur, wenn T4 sie erfordert.

#### Umsetzung Runde 1 (Entwickler, Modell: claude-sonnet-5) – Zusammenfassung wiederhergestellt

- Neu: `src/Services/Jobs/{NasAbgleich,NasAbgleichFehler,NasJob}.php`, `src/Console/Command/NasAbgleichCommand.php`.
- Geändert: `SystemEinstellungen` (`nas`, `speichernNas`, `nasOeffentlich`, `secretsNachImportPruefen`), `Ueberwachung`/`MeldungsZustellung` (Job `nas`, R1-Rotation, R3-Text), `SystemJobActions` (`nas_verbindung_testen`, `secret_key_anzeigen`), `Importer` (R12), `Kernel`, `api.php`, `Dockerfile.app` (rclone, Build-Prüfung, 2. Crontab-Zeile), Frontend, Doku.
- Kern-API: `NasAbgleich::__construct(PDO, array $rcloneBefehl = ['rclone'], ?callable $uhr)`, `verbindungTesten(array): {ok, fehlerCode?}`, `abgleichen(): array` (wirft `NasAbgleichFehler`); `NasJob::__construct(PDO, ?callable $uhr)->lauf(NasAbgleich): ?string`.
- Docker-Echtprüfung (rclone 1.60.1 + Samba): `mkdir`/`rcat`/`copy` mit den Vertragsargumenten erfolgreich, Positivliste korrekt, F3 bestätigt, Fehlermeldungen wie erwartet.
- Prüfungen: `php -l`, eigenes Fake-rclone, gezielte PHPUnit-Tests 79/79, PHPStan 0, `test:js`, `check-versions`, `QuelltextKodierungTest`.

#### Umsetzung Runde 2 (Entwickler, Modell: Claude Sonnet 5 (copilot)): Review-Befunde 1–14 behoben

Alle 14 vom Reviewer (gpt-5.5)/Leitstand gemeldeten Befunde bearbeitet; Befund 15 (Hinweis,
Verbindungstest auf beliebigen Host/Port) laut Vorgabe unverändert gelassen (akzeptiertes
Restrisiko). Keine Tests geändert.

1. **Major – Exit-Code auf PHP 8.2/Linux** (`NasAbgleich.php::prozessAusfuehren()`): Der
   Exit-Code wird jetzt beim ersten `running===false` aus `$status['exitcode']` gemerkt (laut
   PHP-Doku nur dann gültig) und direkt verwendet; `proc_close()` wird weiterhin aufgerufen
   (Ressource freigeben), sein Rückgabewert aber nicht mehr als Exit-Code genutzt (auf PHP <
   8.3 sonst fälschlich -1 nach bereits beobachtetem `running=false`). Damit liefert auch die
   Erkennung „nicht startbar“ (`NAS_RCLONE_FEHLT`, z. B. in `obscure()`) auf PHP 8.2 und 8.3
   denselben, korrekten Exit-Code.
2. **Major – ausgelagerte Pfade** (`NasAbgleich.php::externePfade()`): (a) Ein externer Pfad
   wird jetzt übersprungen (mit `error_log` und neuer Kennzahl `externeUebersprungen`), wenn
   sein `realpath` gleich oder Vorfahr von `realpath(DATA_DIR)`/`APP_ROOT` ist oder ein
   Systempfad (`/`, `/etc`, `/proc`, `/sys`, `/dev`, `/root`, `/boot`, `/usr`, `/bin`, `/sbin`,
   `/lib`, `/lib64`, `/var/lib`, `/run`, `/var/run`) bzw. dessen Unterordner (neue
   `istGesperrterExternerPfad()`/`istVorfahrOderGleich()`). (b) Auf externe Kopien werden
   zusätzlich unverankerte harte Ausschlüsse angewendet (neue Konstante
   `EXTERN_FILTER_REGELN`: `**/secret.key`, `**/database.sqlite*`,
   `**/systemadmin-passwort.txt`, `**/audit_pepper.txt`, `**/*.env`, `**/.tmp-*/**`,
   `**/*.lock`). (c) Doku ergänzt: Ordner innerhalb `data/`, die nicht auf der Positivliste
   stehen, werden nicht übertragen (`installation.md` + `bedienungsanleitung.html`).
3. **Major – manueller Lauf überschreibt `tageslaufDatum`** (`NasJob.php::lauf()`):
   `$protokoll->start('nas', $ausloeser)` jetzt ohne dritten Parameter, wie beim Planer – das
   Datum setzt ausschließlich `tageslaufSetzen()`, wenn tatsächlich ein Tageslauf läuft.
4. **`NAS_ZEIT` im Verbindungstest** (`NasAbgleich.php::verbindungTesten()`): Ein in der
   Schreibprobe auftretendes `NAS_ZEIT` (z. B. aus `prozessAusfuehren()`) wird im `catch`-Block
   auf `NAS_VERBINDUNG` abgebildet – gilt auch für die Schreibprobe vor dem eigentlichen
   Abgleich, da `abgleichen()` dafür dieselbe Methode aufruft. Frontend `NAS_FEHLER_TEXT`
   (`script.js`) um `NAS_ZEIT` und `NAS_TRANSFER` ergänzt.
5. **NasJob: Protokoll/`naechsterVersuchAm` mit aktueller Uhr** (`NasJob.php::lauf()`): Die
   `JobProtokoll`-Instanz bekommt jetzt die injizierbare Uhr direkt durchgereicht
   (`$this->uhr`) statt einer Closure auf den eingefrorenen Startzeitpunkt – `start()`,
   `erfolg()`, `fehler()` und `herzschlag()` fragen die Uhrzeit damit bei jedem Aufruf neu ab.
   `naechsterVersuchAm` und `auftragAbschliessen()` nutzen nach `abgleichen()` zusätzlich
   explizit eine frisch abgefragte `$this->jetzt()` (neue lokale `$jetztNachAbgleich` im
   Fehlerpfad) statt des Startzeitpunkts – wichtig, da `abgleichen()` laut Klassendoku Stunden
   dauern kann und der 30-Minuten-Backoff sonst ab dem (längst vergangenen) Startzeitpunkt statt
   ab dem tatsächlichen Fehlschlag gezählt hätte. Die Uhr bleibt injizierbar (Tests verwenden
   weiterhin eine feste Uhrzeit je Lauf, daher unverändertes Testverhalten).
6. **`fwrite`/`mkdir` im geschützten Muster** (`NasAbgleich.php::prozessAusfuehren()`): `mkdir`
   des Sperrverzeichnisses und `fwrite` auf die stdin-Pipe laufen jetzt im selben Muster wie
   `proc_open` (`set_error_handler`/`finally restore_error_handler()`, kein `@`). Schlägt
   `fwrite` fehl, wird der Prozess sauber geschlossen (neue `prozessAbbrechen()`: terminieren,
   Pipes/Prozess schließen) und `NAS_RCLONE_FEHLT` geworfen.
7. **Regex mit `/D`** (`SystemEinstellungen.php::pruefeNasFelder()`): Host-, Freigabe-, Pfad-
   segment-, Benutzer- und Domänen-Regex haben jetzt `/D` (kein stillschweigendes Akzeptieren
   eines abschließenden `\n`); `freigabe` lehnt zusätzlich `.`/`..` explizit ab (Pfadsegmente
   taten das bereits).
8. **Nicht-String-Eingaben vor dem Cast** (`SystemEinstellungen.php`: neue
   `nasEingabeAlsString()` in `pruefeNasFelder()` für host/freigabe/pfad/benutzer/domaene; die
   Passwort-Prüfung in `vorabPruefenNas()` läuft jetzt unconditional vor der `aktiv`-Prüfung;
   `NasAbgleich.php::konfigurationPruefen()` lehnt ein Nicht-String-Passwort ebenfalls vor dem
   Cast ab) → 400 bzw. `NAS_KONFIGURATION` mit festem Text, keine Warnung.
9. **Drosselungen unter `flock`** (`SystemJobActions.php`: neue
   `nasTestDrosselPruefenUndSetzen()` und überarbeitete `secretDrosselPruefen()`): Lesen,
   Prüfen und Schreiben des Zeitstempels bzw. Zählers laufen jetzt in einer einzigen
   `flock(LOCK_EX)`-Sperre je Datei (`fopen('c+')` … `flock`/`ftruncate`/`fwrite` …
   `flock(LOCK_UN)`), keine Lese-/Schreib-Race mehr zwischen zwei gleichzeitigen Anfragen.
10. **Passwort-Modal statt `window.prompt()`** (`index.html`: neues Modal
    `secretKeyPasswortModal` mit `<input type="password">`; `script.js`: `secretKeyAnfordern()`
    öffnet das Modal, neue `secretKeyPasswortBestaetigen()`/`secretKeyPasswortAbbrechen()`
    führen den bisherigen API-Aufruf aus).
11. **Schlüssel-DOM-Entfernung erweitert** (`script.js`): `secretKeyAusBlenden()` wird jetzt
    zusätzlich in `vwSucheEingabe()` und zu Beginn von `renderAllgemeinSettings()` (Neu-Rendern
    der Verwaltung) aufgerufen sowie automatisch nach 60 s (neuer `secretKeyAutoHideTimer`).
    Anleitung entsprechend formuliert.
12. **NAS-Karte nur über „Speichern“** (`script.js::renderNasAbgleich()`): Alle
    `onchange="speichereNas()"`-Attribute der Eingabefelder/Checkbox entfernt; Speichern löst
    ausschließlich der Knopf „💾 Speichern“ aus (kein automatisches Neu-Rendern bei jedem Feld).
13. **Fehlererkennung case-insensitive** (`NasAbgleich.php::pruefeRcloneFehler()`): Die
    rclone-Ausgabe wird vor dem Abgleich mit `mb_strtolower()` kleingeschrieben; zusätzlich
    `access is denied` als weiterer Treffer für `NAS_KEINE_SCHREIBRECHTE`.
14. **Doku** (`installation.md`, `bedienungsanleitung.html`): `&amp;`/`&nbsp;` in
    `installation.md` (Markdown) durch Klartext `&`/Leerzeichen ersetzt (bedienungsanleitung.html
    bleibt als echtes HTML bei `&amp;`/`&nbsp;`); Satz „Die Live-Datenbank (`database.sqlite`)
    wird nie übertragen – die Tagessicherungen in `backups/` enthalten jedoch einen
    Datenbank-Snapshot“ ergänzt; Nicht-Docker-Cron-Zeile für `nas:abgleich` um den Hinweis „als
    Webserver-Benutzer (z. B. www-data)“ (E-065) ergänzt.
15. Hinweis (Verbindungstest auf beliebigen Host/Port) laut Vorgabe nicht bearbeitet
    (akzeptiertes Restrisiko).

**Abweichung vom Vertrag:** keine.

**Bekannter, vorgegebener Testkonflikt (Test nicht geändert):** Befund 2a verlangt die neue
Kennzahl `externeUebersprungen` in der `abgleichen()`-Rückgabe. Dadurch geht
`tests/Unit/NasAbgleichTest.php::testAbgleichenSchreibtStatusdateiBeiTransferfehlerUndWirftNasTransfer`
rot – der Test prüft `array_keys($e->kennzahlen)` exakt gegen
`['transfers','bytes','fehler','dauerSek','externePfade']` ohne den neuen Schlüssel. Grund:
unmittelbare Folge der in Runde 2 vorgegebenen Kennzahl-Erweiterung; Test absichtlich nicht
angepasst (Rolle Tester). Bitte im Testplan nachziehen (`externeUebersprungen` ergänzen).

**Prüfungen:**
- `php -l` auf `NasAbgleich.php`, `NasJob.php`, `SystemEinstellungen.php`,
  `SystemJobActions.php` → ok.
- `docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php vendor/bin/phpunit --colors=never
  --filter "NasAbgleichTest|NasJobTest"` (PHP 8.2.35, Linux, ohne `sh -c`) → **17 Tests, 81
  Assertions, 16 grün, 1 rot** (der oben dokumentierte, vorgegebene Testkonflikt). Befund 1
  bestätigt behoben: vorher 8/12 rot wegen `NAS_RCLONE_FEHLT`, jetzt nur noch der
  Kennzahl-Konflikt rot.
- Dieselbe Prüfung mit `php:8.3-cli` (PHP 8.3.36, Linux) → **identisch: 17 Tests, 81
  Assertions, 16 grün, 1 rot** (derselbe, vorgegebene Testkonflikt).
- Lokal (Windows, PHP 8.2.31) `php vendor/bin/phpunit --filter
  "NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture"`
  → **69 Tests, 728 Assertions, 68 grün, 1 rot** (derselbe Testkonflikt).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → **No errors** (zwei neue Meldungen
  während der Umsetzung – `nullCoalesce.variable` und `if.alwaysTrue` in der neuen
  Exit-Code-/`prozessAbbrechen()`-Logik – durch Umbau statt Unterdrückung behoben).
- `npm.cmd run minify` → `public/script.min.js` neu erzeugt; `script.min.js?v=` in `index.html`
  und `sw.js` von 243 auf **244**, `CACHE_VERSION` von `bk-es-v246` auf **`bk-es-v247`**.
- `node --check public/script.js` → ok.
- `node scripts/check-versions.mjs --strict` → **OK – alle ?v=-Versionen sind konsistent.**
- `npm.cmd run test:js` → **19/19 grün**.
- `VERSION` unverändert `3.0.22`; kein Commit.

#### Umsetzung Runde 3 (Entwickler, Modell: Claude Sonnet 5 (copilot)): Review-Befunde 1–6 behoben

Alle 6 vom Reviewer (gpt-5.5)/Leitstand gemeldeten Befunde der Runde 2 bearbeitet. Keine Tests
geändert (Vorgabe); die beiden dadurch roten Tests (Befund 1, Leitstand-Entscheidung zu `pfad`)
sind unten dokumentiert.

1. **Major – Filter greifen im Wurzelordner nicht** (`NasAbgleich.php::EXTERN_FILTER_REGELN`):
   Die Regeln sind jetzt unverankert **ohne** `**/`-Präfix (`- secret.key`, `-
   database.sqlite*`, `- systemadmin-passwort.txt`, `- audit_pepper.txt`, `- *.env`, `-
   .tmp-*/**`, `- *.lock`, unveränderte Reihenfolge) – mit `**/muster` verlangt rclone laut
   Dokumentation mindestens einen Verzeichnistrenner vor dem Treffer, ein unverankertes Muster
   ohne führendes `/` oder `**/` greift dagegen auf jeder Ebene inklusive Wurzel.
   **Verifikation mit echtem rclone 1.60.1 (ohne NAS), Temp-Ordner mit Dateien in Wurzel und
   Unterordner:**
   ```
   docker run --rm -v "<temp>:/q:ro" rclone/rclone:1.60.1 lsf -R /q
     --filter "- secret.key" --filter "- database.sqlite*"
     --filter "- systemadmin-passwort.txt" --filter "- audit_pepper.txt"
     --filter "- *.env" --filter "- .tmp-*/**" --filter "- *.lock" --filter "+ **"
   ```
   Inhalt: `secret.key`, `database.sqlite`, `database.sqlite-wal`,
   `systemadmin-passwort.txt`, `audit_pepper.txt`, `.env`, `a.env`, `x.lock`, `.tmp-b/f`,
   `normal.txt`, `sub/secret.key`, `sub/ok.txt`. Ergebnis: genau `normal.txt`, `sub/`,
   `sub/ok.txt` – wie gefordert. Zum Vergleich dieselbe Prüfung mit den alten,
   `**/`-verankerten Regeln: Ergebnis enthielt zusätzlich `.env`, `a.env`,
   `audit_pepper.txt`, `database.sqlite`, `database.sqlite-wal`, `secret.key`,
   `systemadmin-passwort.txt`, `x.lock` im Wurzelordner (Bug bestätigt, nur `sub/secret.key`
   wurde dort bereits korrekt gefiltert).
   Zusätzlich die Hauptkopie-Regeln (`FILTER_REGELN`, verankerte `- /database.sqlite*` +
   Positivliste) mit `lsf -R` gegen einen Testbaum (Wurzel-Geheimnisse,
   `backups/.tmp-x/`, alle Positivlisten-Ordner, ein nicht gelisteter `sonstiges/`-Ordner)
   geprüft: Ergebnis enthält ausschließlich die elf Positivlisten-Einträge
   (`archiv/`, `archiv_material/`, `audit.log`, `backups/`, `bautagebuch/`, `datanorm/`,
   `exports/`, `firma_logo.png`, `paths_config.json`, `pg-backups/`, `uploads/`) und ihre
   Dateien – `sonstiges/`, `backups/.tmp-x/`, Wurzel-Geheimnisse sowie `update/`/`run/`
   fehlen vollständig. Temp-Ordner nach der Prüfung gelöscht.
2. **Minor/Major – manueller Erfolg setzt Tageslauf** (`NasJob.php::lauf()`): Nach
   `$protokoll->erfolg($laufId, $kennzahlen)` wird `tageslaufSetzen(..., 'ok', ...)` jetzt nur
   noch aufgerufen, wenn `$faellig !== null` **oder** (manueller Auftrag **und** der vor dem
   Lauf gelesene `tageslaufDatum === heute` **und** `tageslaufStatus` ∈ `{fehler,
   fehlgeschlagen}`) – letzteres analog zur Planer-Regel aus T2 (ein erfolgreicher manueller
   Lauf ersetzt einen heute bereits fehlgeschlagenen Tageslauf). In allen anderen Fällen bleibt
   der Tageslauf unverändert. `NasJobTest::testManuellerLaufOhneFaelligkeitAendertTageslaufNicht`
   ist jetzt grün.
3. **Minor – Handler-Typprüfung** (`SystemJobActions.php::nasVerbindungTesten()`,
   `::secretKeyAnzeigen()`): `passwort` wird in beiden Handlern jetzt vor dem `(string)`-Cast
   per `is_string()` geprüft (analog `SystemEinstellungen::nasEingabeAlsString()`) → bei
   Nicht-String 400 mit festem Text (`Ungültiges NAS-Passwort.` bzw. `Ungültiges Passwort.`)
   statt eines möglichen `(string)`-Warnings auf einem Array/Objekt.
4. **Hinweis → umsetzen – Sperrliste erweitert** (`NasAbgleich.php::SYSTEM_PFADE`,
   `::istGesperrterExternerPfad()`): Zusätzlich `/tmp`, `/var/tmp`, `/var/log`,
   `/var/backups`, `/var/spool`, `/var/mail`, `/var/cache`, `/home` (jeweils inkl.
   Unterordner) ergänzt. Neu zusätzlich: ein ausgelagerter Pfad wird auch übersprungen, wenn
   er **innerhalb** von `APP_ROOT` liegt, aber außerhalb von `DATA_DIR` (z. B. `src`,
   `vendor`) – der Aufrufer hat „innerhalb `DATA_DIR`“ bereits vorher ausgefiltert, die neue
   Prüfung ergänzt den bisher nur umgekehrt (Vorfahr-von-APP_ROOT) abgedeckten Fall.
5. **Hinweis → umsetzen** (`script.js::renderNasAbgleich()`): `externeUebersprungen > 0` aus
   dem letzten `nas`-Lauf (`laeufe[0].kennzahlen.externeUebersprungen`) zeigt jetzt die
   Warnzeile „N ausgelagerte(r) Speicherpfad(e) wurde(n) aus Sicherheitsgründen nicht
   übertragen.“ in der NAS-Karte. `installation.md` und `bedienungsanleitung.html` erklären
   neu, welche Pfade gesperrt sind (Systempfade, `data/`/App-Verzeichnis selbst, Ordner
   innerhalb des App-Verzeichnisses außerhalb von `data/`) und dass dann dieselbe Warnzeile
   erscheint.
6. **Hinweis → umsetzen** (`script.js::secretKeyPasswortBestaetigen()`): Neues Modul-Flag
   `secretKeyPasswortLaeuft` verhindert eine Doppelauslösung durch Enter (Eingabefeld) und
   Klick (Bestätigen-Knopf) – ein zweiter Aufruf während der ersten laufenden Anfrage kehrt
   sofort zurück; das Flag wird im `finally`-Block zurückgesetzt.

**Abweichung vom Vertrag:** keine.

**Bekannte, vorgegebene Testkonflikte (Tests nicht geändert):**
- Befund 1 ändert die `EXTERN_FILTER_REGELN`-Strings (Vorgabe). Dadurch geht
  `NasAbgleichTest::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` rot – der Test
  prüft die alten, `**/`-verankerten Strings exakt. Grund: unmittelbare Folge der in Runde 3
  vorgegebenen, korrigierten Filterregeln; Test absichtlich nicht angepasst (Rolle Tester).
- Leitstand-Entscheidung (reine Information, keine Codeänderung): `pfad` mit abschließendem
  `\n` wird weiterhin getrimmt und als z. B. `a` gespeichert (gewollt). Dadurch bleibt
  `SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` (Teilfall
  `pfad="baukalkulation\n"`, erwartet 400) rot, bis der Tester den Test anpasst.

**Prüfungen:**
- `php -l` auf `NasAbgleich.php`, `NasJob.php`, `SystemJobActions.php`,
  `SystemEinstellungen.php` → ok.
- `docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php vendor/bin/phpunit --colors=never
  --filter "NasAbgleichTest|NasJobTest"` (PHP 8.2.35, Linux) → **26 Tests, 110 Assertions, 25
  grün, 1 rot** (Befund-1-Testkonflikt, s. o.).
- Dieselbe Prüfung mit `php:8.3-cli` (PHP 8.3.36, Linux) → **identisch: 26 Tests, 110
  Assertions, 25 grün, 1 rot**.
- Lokal (Windows, PHP 8.2.31) `php vendor/bin/phpunit --filter
  "NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture"`
  → **78 Tests, 737 Assertions, 76 grün, 2 rot** (Befund-1-Testkonflikt +
  Leitstand-Entscheidung `pfad`-Trim).
- PostgreSQL (Wegwerf-Container, `scripts/test-pgsql.ps1`, E-064), derselbe Filter → **78
  Tests, 737 Assertions, 76 grün, 2 rot** (identisch zu SQLite).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → **No errors**.
- `php vendor/bin/php-cs-fixer check --diff` → grün (0 von 52 Dateien mit Änderungsbedarf).
- `npm.cmd run minify` → `public/script.min.js` neu erzeugt; `script.min.js?v=` in
  `index.html` und `sw.js` von 244 auf **245**, `CACHE_VERSION` von `bk-es-v247` auf
  **`bk-es-v248`**.
- `node --check public/script.js` → ok.
- `node scripts/check-versions.mjs --strict` → **OK – alle ?v=-Versionen sind konsistent.**
- `npm.cmd run test:js` → **19/19 grün**.
- `VERSION` unverändert `3.0.22`; kein Commit.

**Rote Tests (gemeldet, nicht geändert):**
- `NasAbgleichTest::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` – Grund: Befund 1
  (`EXTERN_FILTER_REGELN` vertraglich korrigiert, Test prüft noch die alten `**/`-Strings).
- `SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` – Grund:
  Leitstand-Entscheidung, `pfad` mit abschließendem `\n` wird weiterhin getrimmt/akzeptiert
  (gewollt), Test erwartet noch 400.

#### T4 – Abnahme Leitstand (2026-11-01)

- Gelaufene Modelle: Entwickler `claude-sonnet-5`, Tester `gpt-5.3-codex`, Reviewer `gpt-5.5` (E-089).
- Drei Entwickler-/Review-Runden; Schleifenbremse erreicht, nicht überschritten; Runde 3 = APPROVE.
- **Leitstand-Prüfungen:**
  - Image-Build (`ENABLE_OCR=false`, Debian trixie): `rclone v1.60.1` installiert, Build-Prüfung bestanden, Cron-Zeile vorhanden.
  - Ende-zu-Ende-Prüfung im Image gegen Samba: Anmeldung, Erreichbarkeit, erfolgreicher Abgleich, Statusdatei, Passwort nur verschlüsselt, Positivliste.
  - Reproduktion des PHP-8.2-Exit-Code-Fehlers in `php:8.2-cli` (8/12 rot).
- **Eigene Fehler des Leitstands, korrigiert:**
  - Filtervorgabe `**/…` (Review R2 Befund 1, E-026).
  - Kodierungspanne im AP (E-087, 2. Auftreten).
- Doku-Nit aus Review R3 vom Leitstand korrigiert: Ordner innerhalb `data/` gelten nicht als ausgelagert.
- **Gesamtläufe** über T3 + T4 (auf Wunsch des Nutzers erst nach T4):

  | Lauf | Ergebnis |
  |---|---|
  | PHPUnit SQLite | 627 grün (3 übersprungen) |
  | PHPUnit PostgreSQL | 627 grün (4 übersprungen) |
  | `test:js` | 19/19 |
  | `check-versions --strict`, PHPStan (0 Fehler), `php-cs-fixer` | grün |
  | NAS-Tests Linux PHP 8.2 und 8.3 (Tester Runde 3) | grün |

- **Commits:** Ein gemeinsamer Feature-Commit für T3 + T4, weil Planer, `SystemJobActions` und `script.js` von beiden geändert wurden und sich nicht sauber trennen lassen. Abweichung von „zwei Feature-Commits“.
- **Gemischte Dateien:** Fremde, parallel entstandene Änderungen (E-091/E-092 in `erkenntnisse.md`, `AGENTS.md`, `.github/agents/*`, `docs/ki-architektur.md`, `AP-20261101-*`) werden **nicht** mitcommittet. In gemischten Dateien kommen nur die eigenen Zeilen in den Index (E-076).

#### T4 – CI-Folgefix (Entwickler, Modell: Claude Sonnet 5 (copilot))

- **Anlass:** Die CI (GitHub-Runner, Repo unter `/home/runner/work/…`) ist bei
  `NasAbgleichTest::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` rot, weil der
  externe Testordner neben dem Repo (also unter `/home`) liegt und daher durch
  `NasAbgleich::SYSTEM_PFADE` gesperrt wird – Testabbruch mit „5 is identical to 7“. Produktverhalten
  ist korrekt; der Test kann den Erfolgsfall auf dem Runner nicht prüfen, weil dort jeder
  beschreibbare Ort unter `/home` oder `/tmp` liegt.
- **Änderung (minimal, src/Services/Jobs/NasAbgleich.php):** Konstruktor um optionalen vierten
  Parameter `?array $systemPfade = null` erweitert:
  `NasAbgleich::__construct(\PDO $db, array $rcloneBefehl = ['rclone'], ?callable $uhr = null, ?array $systemPfade = null)`.
  `null` (Standard) übernimmt unverändert `self::SYSTEM_PFADE` – alle Produktiv-Aufrufer
  (`NasAbgleichCommand`, `SystemJobActions`) bleiben unverändert. Ein übergebenes Array ersetzt die
  Liste nur für diese Instanz (per `grep "new NasAbgleich("` gegengeprüft: alle Aufrufer nutzen
  höchstens drei positionale Argumente, der neue vierte Parameter ist rein additiv). Keine
  Konfiguration über Einstellungen/Umgebung (keine neue Angriffsfläche). `istGesperrterExternerPfad`
  liest jetzt die Instanzliste `$this->systemPfade` statt der Konstante; die Vorfahren-/APP_ROOT-
  Prüfungen bleiben unverändert und nicht abschaltbar. PHPDoc markiert den Parameter als „nur für
  Tests“.
- **Prüfung:** `php -l` ohne Fehler; `phpstan analyse --memory-limit=2G` 0 Fehler (153/153);
  `phpunit --filter "NasAbgleichTest|NasJobTest"` lokal 30 Tests/124 Assertions, 1 übersprungen
  (derselbe Test wie auf dem CI-Runner, lokal nur übersprungen statt rot, da `C:\Users\...` kein
  Systempfad ist) – keine Teständerung vorgenommen.

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

### T3 – Runde 1 (Tester, Modell: gpt-5.3-codex) – Zusammenfassung wiederhergestellt

**GRÜN** – neu `tests/Unit/UeberwachungMeldungsZustellungTest.php`, erweitert `PlanerFaelligkeitTest`, `SystemPlanerApiTest`. Gezielt 43 Tests auf SQLite **und** PostgreSQL grün; `php-cs-fixer` grün.

| Abnahmekriterium | Test | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| AK 6 / F10 / R7 (Fehler, Entwarnung, Ausbleiben, Deaktivierung, Doppelmail über 2 Verbindungen) | `UeberwachungMeldungsZustellungTest` (mehrere) | grün | grün | n/a |
| Zustellung (Backoff, > 7 Tage, Teil-Erfolg, Admin-Rückfall, Escaping) | `UeberwachungMeldungsZustellungTest` | grün | grün | n/a |
| Vertrag 5 (`faelligkeit(…,'monat')`, 3 Parameter kompatibel) | `PlanerFaelligkeitTest` | grün | grün | n/a |
| AK 7 / R8 (Watchdog, `check`-Schlüssel unverändert, < 3 s, Entwarnung) | `SystemPlanerApiTest::testWatchdog…`, `::testSystemJobsStatusUndCheck…` | grün | grün | n/a |
| AK 8 + 11 (Rechte, GET → 405, Testmail ohne SMTP, 429) | `SystemPlanerApiTest::testUeberwachungHinweiseUndTestmail…` | grün | grün | n/a |
| AK 11 + E-088 (Validierung, Duplikate, `sicherung` allein weiterhin gültig) | `SystemPlanerApiTest::testUeberwachungEinstellungen…` | grün | grün | n/a |
| AK 9 (Probe: Erfolg, `PROBE_KEINE_SICHERUNG`, `PROBE_DEFEKT`, Auftrag) | `SystemPlanerApiTest` (3 Methoden) | grün | grün | n/a |
| AK 3 (Speicherplatz-Zustandswechsel) | `SystemPlanerApiTest::testSpeicherplatz…` | grün | grün | n/a |
| AK 12 (Fixtures v3.0.23 und v3.0.20) | `SystemPlanerApiTest::testRestore…`, `BackupFixtureImportTest` | grün | grün | n/a |

### T3 – Runde 2 (Tester) – Zusammenfassung wiederhergestellt

**ROT** (2, nicht abgeschwächt):
- `testZustelllaufBrichtNachErstemEmpfaengerFehlerAbUndSetztBackoff` – Produktbefund N1.
- `testUeberwachungEinstellungenWerdenValidiertUndDuplikateEntfernt` – erwartete 400 für `aktiv:"false"` entgegen der Vorgabe zu Befund 9 → Leitstand: Test korrigieren.

Neu: Tests zu den Befunden 1–14 (Lease bei zwei Verbindungen, Watchdog nur bei stehendem Planer, Dauerbanner, `uebersprungen`, Abbruch nach erstem Fehler, Hinweise, Ausbleiben bei `laufend`/`fehler`, Validierung, Fehlercode in der Mail, `isLocked` NULL). E-090: Grenze 26 gültig / 25 ungültig. Ergebnis: 56 Tests (54 grün, 2 rot) auf SQLite und PostgreSQL; PHPStan 0.

### T3 – Runde 3 und 3b (Tester) – Zusammenfassung wiederhergestellt

- **Runde 3:** `aktiv:"false"` → 200 und als `false` gespeichert; `"abc"` → 400 mit festem Text (E-090, Vertrag Befund 9). `SystemPlanerApiTest` 27/27 grün.
- **Runde 3b:** Teil-Erfolg-Test mit Reihenfolge ok → fail (Folge der N1-Abbruchregel, E-090). 56 Tests grün auf SQLite und PostgreSQL; CS-Fixer und PHPStan grün.

### T4 - Runde 1 (Tester, Modell: GPT-5 (copilot))

**GRUEN**

- Neu/erweitert:
  - `tests/fixtures/rclone/fake-rclone.php`
  - `tests/Unit/NasAbgleichTest.php`
  - `tests/Unit/NasJobTest.php`
  - `tests/Unit/UeberwachungMeldungsZustellungTest.php`
  - `tests/Api/SystemPlanerApiTest.php`
- Ausgefuehrt:
  - `php vendor/bin/phpunit --filter "NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture"` (SQLite) -> **69 Tests, 731 Assertions, gruen**
  - `powershell -ExecutionPolicy Bypass -File scripts/test-pgsql.ps1 -Php <php.exe> -PhpUnitArgs @('--filter','NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture')` (PostgreSQL, Wegwerf-Container, E-064) -> **69 Tests, 731 Assertions, gruen**
  - `php vendor/bin/php-cs-fixer check --diff` -> **gruen**
  - `php vendor/bin/phpstan analyse --memory-limit=2G` -> **No errors**

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| AK 4 (Verbindungstest: `mkdir` dann `rcat` auf `:smb:<freigabe>/<pfad>`, Fehlercode-Mapping inkl. `NAS_RCLONE_FEHLT`/`NAS_KONFIGURATION`) | `tests/Unit/NasAbgleichTest.php::testVerbindungTestenFuehrtMkdirDannRcatMitSmbZielAus`, `::testVerbindungTestenMapptRcloneFehlercodes`, `::testVerbindungTestenLiefertRcloneFehltBeiNichtStartbaremBefehl`, `::testVerbindungTestenLiefertKonfigurationsfehlerBeiUngueltigemHost` | gruen | gruen | n/a |
| AK 4 + F3 (Abgleich nutzt nur erlaubte Befehle, nie sync/move/delete/purge/rmdir/cleanup; vertragliche `--filter` in Reihenfolge; Quelle `DATA_DIR`; externe Pfade -> `extern/<schluessel>`) | `tests/Unit/NasAbgleichTest.php::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` | gruen | gruen | n/a |
| AK 4 (Statusdatei via `rcat` auch bei Transferfehler, Transfer-Exit != 0 -> `NAS_TRANSFER`, Kennzahlen ohne Pfad/Host-Leak) | `tests/Unit/NasAbgleichTest.php::testAbgleichenSchreibtStatusdateiBeiTransferfehlerUndWirftNasTransfer` | gruen | gruen | n/a |
| AK 5 (Passwort-Leck-Schutz: kein Klartext in Args/Logs/Rueckgaben; `RCLONE_SMB_PASS` nur obscured, Klartext nur stdin bei `obscure`) | `tests/Unit/NasAbgleichTest.php::testPasswortBleibtAusArgumentenRueckgabenUndErrorLogs` | gruen | gruen | n/a |
| AK 6 (Zeitlimit-Verhalten: Abbruch auf `NAS_ZEIT` oder `NAS_VERBINDUNG`) | `tests/Unit/NasAbgleichTest.php::testVerbindungTestenBrichtBeiZeitlimitMitNasZeitOderNasVerbindungAb` | gruen | gruen | n/a |
| Vertrag 5 (NAS-Prozess/Faelligkeit: inaktiv -> nichts; Erstversuch erst nach Sicherung oder Uhrzeit+2h; Erfolg vor +2h bei abgeschlossener Sicherung) | `tests/Unit/NasJobTest.php::testInaktiverJobOhneAuftragMachtNichts`, `::testErstversuchWartetBisTagessicherungOderZweiStundenVorbei`, `::testErstversuchStartetVorZweiStundenWennSicherungAbgeschlossenIst` | gruen | gruen | n/a |
| Vertrag 5 + AK 6 (Wiederholung alle 30 min; nach 4 Versuchen `fehlgeschlagen`; Ueberwachung erzeugt `nas:<tag>:fehler`) | `tests/Unit/NasJobTest.php::testFehlerWirdWiederholtUndNachVierVersuchenFehlgeschlagenMitMeldung` | gruen | gruen | n/a |
| Vertrag 5/6 (manueller Auftrag `job_anfordern nas`; zweiter Lauf bei gehaltener `nas.lock` endet Exit 0 ohne Lauf) | `tests/Unit/NasJobTest.php::testManuellerAuftragWirdAuchBeiInaktivemJobAlsFertigMarkiert`, `tests/Api/SystemPlanerApiTest.php::testJobAnfordernErlaubtNasUndNasCommandUeberspringtBeiLock` | gruen | gruen | n/a |
| AK 11 (API `system_einstellungen_speichern` mit `nas`: Validierung, `aktiv=true` ohne Passwort -> 400, Passwort nur schreibbar, Antworten ohne Passwort/ohne `enc:v1:`) | `tests/Api/SystemPlanerApiTest.php::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` | gruen | gruen | n/a |
| Vertrag 6 (API `nas_verbindung_testen`/`secret_key_anzeigen`: Rechte, GET->405 nach Rollenpruefung, Drosselung 429, `Cache-Control: no-store`, Audit ohne Zugangsdaten) | `tests/Api/SystemPlanerApiTest.php::testNasUndSecretKeyApiRechteMethodenDrosselungUndAudit` | gruen | gruen | n/a |
| AK 12 + R12 (Restore mit fremdem Secret: NAS-Passwort geleert, `passwortNeuEingeben=true`, Hinweis in `system_hinweise`) | `tests/Api/SystemPlanerApiTest.php::testRestoreMitFremdemSecretLeertNasPasswortUndSetztHinweis` | gruen | gruen | n/a |
| Vertrag 9 / R1 (Empfaenger-Rotation je Versuch) | `tests/Unit/UeberwachungMeldungsZustellungTest.php::testEmpfaengerRotationBeginntBeimNaechstenVersuchMitAnderemEmpfaenger` | gruen | gruen | n/a |
| Vertrag 9 / R3 (API-Testmail meldet nicht versuchte Empfaenger als "Nicht versucht ...") | `tests/Api/SystemPlanerApiTest.php::testUeberwachungHinweiseUndTestmailRechteMethodenUndDrosselung` | gruen | gruen | n/a |

### T4 - Runde 2 (Tester, Modell: GPT-5 (copilot))

**ROT**

**A. Begruendete Testanpassung (E-090):** `NasAbgleichTest::testAbgleichenSchreibtStatusdateiBeiTransferfehlerUndWirftNasTransfer` prueft die Kennzahl-Schluessel jetzt inkl. `externeUebersprungen`, weil Befund 2a diese Kennzahl vertraglich neu eingefuehrt hat. Ohne Anpassung waere der Test ein falsches Negativ (kein Produktivfehler).

**Neu/erweitert (Runde 2):**
- `tests/Unit/NasAbgleichTest.php`
  - Exit-Code-Abdeckung (`exit 0/1/3`) in `testVerbindungTestenErkenntExitCodesKorrekt`.
  - Fehler-Mapping um gemischte Schreibweise ergaenzt (`Permission Denied`, `Access is denied`).
  - Externe harte Ausschluesse fuer legitime Pfade explizit geprueft.
  - Vorfahr-Pfad von `DATA_DIR` wird als ausgelagerter Pfad uebersprungen und zaehlt `externeUebersprungen` hoch.
  - Zeitueberschreitung in `verbindungTesten()` strikt auf `NAS_VERBINDUNG` geprueft.
- `tests/Unit/NasJobTest.php`
  - Manueller Lauf ohne Faelligkeit darf `tageslaufDatum`/`tageslaufStatus` nicht veraendern.
  - Fehlgeschlagener manueller Lauf blockiert den spaeteren automatischen Tageslauf nicht.
  - `naechsterVersuchAm` basiert auf Uhrzeit **nach** dem Abgleich (vorrueckende injizierte Uhr).
- `tests/Api/SystemPlanerApiTest.php`
  - NAS-Validierung um Befund-7/8-Faelle erweitert: `freigabe='.'/'..'`, `pfad` mit abschliessendem `\n`, Segment `..`, Nicht-String-Eingaben (`host`, `benutzer`, `passwort` als Array).

**Befund-Matrix (Runde 2):**
| Befund | Testabdeckung | Ergebnis |
|---|---|---|
| 1 Exit-Codes/`NAS_RCLONE_FEHLT` | `NasAbgleichTest::testVerbindungTestenErkenntExitCodesKorrekt`, `::testVerbindungTestenLiefertRcloneFehltBeiNichtStartbaremBefehl` | gruen in allen Laeufen; Linux 8.2/8.3 kein `proc_close()`-Regressionseffekt mehr |
| 2 Externe Pfade (Vorfahr/System + harte Ausschluesse) | `NasAbgleichTest::testAbgleichenUeberspringtExternePfadeDieDataDirVorfahrSind`, `::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` | gruen |
| 3 Manueller Lauf/Faelligkeit | `NasJobTest::testManuellerLaufOhneFaelligkeitAendertTageslaufNicht`, `::testFehlgeschlagenerManuellerLaufBlockiertAutomatischenTageslaufNicht` | **rot**: erster Test schlaegt fehl (Produktivverhalten ueberschreibt `tageslaufDatum`) |
| 4 Timeout Verbindungstest | `NasAbgleichTest::testVerbindungTestenBrichtBeiZeitlimitMitNasVerbindungAb` | gruen |
| 5 `naechsterVersuchAm` nach Abgleich-Uhr | `NasJobTest::testNaechsterVersuchAmOrientiertSichAnZeitNachAbgleich` | gruen |
| 7 Pfad/Freigabe-Validierung | `SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` (erweitert) | **rot**: `pfad` mit abschliessendem `\n` wird aktuell akzeptiert (HTTP 200 statt 400) |
| 8 Nicht-String-Eingaben -> 400 | `SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` (erweitert) | gruen (bis auf den oben genannten Pfad-`\n`-Teilfall) |
| 9 Drosselung `secret_key_anzeigen` (6. Versuch) | `SystemPlanerApiTest::testNasUndSecretKeyApiRechteMethodenDrosselungUndAudit` | gruen (6. Fehlversuch -> 429) |
| 13 Meldung `Permission Denied`/`Access is denied` | `NasAbgleichTest::testVerbindungTestenMapptRcloneFehlercodes` | gruen |

**Ausgefuehrt:**
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/phpunit --filter "NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture"` (SQLite)
- `powershell -ExecutionPolicy Bypass -File .\scripts\test-pgsql.ps1 -Php <php.exe> -PhpUnitArgs '--filter=NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture'` (PostgreSQL, E-064)
- `docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/php-cs-fixer check --diff` -> gruen
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/phpstan analyse --memory-limit=2G` -> `[OK] No errors`

**Umgebungsstatus (Pflichtausgabe):**
| Umgebung | Status | Details |
|---|---|---|
| SQLite | **ROT** | 78 Tests, 738 Assertions, 2 Failures (`NasJobTest::testManuellerLaufOhneFaelligkeitAendertTageslaufNicht`; `SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` Teilfall `pfad="baukalkulation\n"`) |
| PostgreSQL | **ROT** | 78 Tests, 738 Assertions, 2 Failures (identisch zu SQLite) |
| Linux PHP 8.2 | **ROT** | 26 Tests, 111 Assertions, 1 Failure (`NasJobTest::testManuellerLaufOhneFaelligkeitAendertTageslaufNicht`) |
| Linux PHP 8.3 | **ROT** | 26 Tests, 111 Assertions, 1 Failure (`NasJobTest::testManuellerLaufOhneFaelligkeitAendertTageslaufNicht`) |

Hinweis: Rote Tests sind unveraendert gemeldet und **nicht** abgeschwaecht (Vorgabe).

### T4 - Runde 3 (Tester, Modell: GPT-5 (copilot))

**Status:** GRUEN

**A. Begruendete Anpassung (`NasAbgleichTest::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle`):**
Die externen rclone-Filter wurden auf die in Runde 3 implementierten, unverankerten Regeln umgestellt (`- secret.key`, `- database.sqlite*`, `- systemadmin-passwort.txt`, `- audit_pepper.txt`, `- *.env`, `- .tmp-*/**`, `- *.lock`; ohne `**/`). Das ist der dokumentierten Leitstand-/Reviewer-Korrektur aus Befund 1 entsprechend (inkl. `rclone lsf`-Beleg in Abschnitt 3).

**B. Begruendete Anpassung (`SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert`):**
`pfad="baukalkulation\n"` wird gemaess Leitstand-Entscheidung getrimmt und als `baukalkulation` gespeichert (200, Rueckgabe + gespeicherter Wert geprueft). Umbruch *innerhalb* des Pfads (`"a\nb"`, `"a\n/b"`) bleibt 400.

**Neu/erweitert (Runde 3):**
- `tests/Unit/NasAbgleichTest.php`
  - Externe Filter-Erwartung auf die korrigierten Runde-3-Regeln umgestellt.
  - Neuer Test fuer ausgelagerten Pfad innerhalb `APP_ROOT` ausserhalb `DATA_DIR` (`externeUebersprungen`).
  - Neuer Test fuer Unix-Systempfad (`/tmp`) mit begruendetem Skip auf Windows; auf Linux aktiv.
- `tests/Unit/NasJobTest.php`
  - `testManuellerErfolgSetztHeutigenFehlgeschlagenenTageslaufAufOk`.
  - `testManuellerErfolgOhneHeutigenFehlschlagLaesstTageslaufUnveraendert`.
- `tests/Api/SystemPlanerApiTest.php`
  - `pfad`-Trim-Fall (`baukalkulation\n`) und interne Newline-Fehlerfaelle (`a\nb`, `a\n/b`).
  - Neuer API-Test: `nas_verbindung_testen`/`secret_key_anzeigen` mit `passwort: []` liefern 400 (kein 500).

**Befund-Matrix (Runde 3):**
| Thema | Testabdeckung | Ergebnis |
|---|---|---|
| A: Externe Filter ohne `**/` | `NasAbgleichTest::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` | gruen |
| B: `pfad`-Trim + interne Newlines | `SystemPlanerApiTest::testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert` | gruen |
| C1: Manueller Erfolg nach heutigem Fehlschlag -> `ok` | `NasJobTest::testManuellerErfolgSetztHeutigenFehlgeschlagenenTageslaufAufOk` | gruen |
| C2: Manueller Erfolg ohne Faelligkeit/ohne heutigen Fehlschlag -> Tageslauf unveraendert | `NasJobTest::testManuellerErfolgOhneHeutigenFehlschlagLaesstTageslaufUnveraendert` | gruen |
| C3: `passwort: []` bei NAS-/Secret-Endpunkten -> 400 | `SystemPlanerApiTest::testNasUndSecretKeyApiLehnenArrayPasswoerterMit400Ab` | gruen |
| C4: Externe Pfade in Systempfad bzw. innerhalb `APP_ROOT` ausserhalb `DATA_DIR` | `NasAbgleichTest::testAbgleichenUeberspringtExternePfadeInUnixSystempfaden`, `::testAbgleichenUeberspringtExternePfadeInnerhalbVonAppRootAusserhalbDataDir` | gruen (Windows: `/tmp`-Fall begruendet geskippt) |

**Ausgefuehrt:**
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/phpunit --filter "NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture"` (SQLite)
- `powershell -ExecutionPolicy Bypass -File .\scripts\test-pgsql.ps1 -Php <php.exe> -PhpUnitArgs '--filter=NasAbgleichTest|NasJobTest|UeberwachungMeldungsZustellungTest|SystemPlanerApiTest|BackupFixture'` (PostgreSQL, E-064)
- `docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/php-cs-fixer check --diff`
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/phpstan analyse --memory-limit=2G`

**Umgebungsstatus:**
| Umgebung | Status | Details |
|---|---|---|
| SQLite (PHP 8.2.31, Windows) | **GRUEN** | 83 Tests, 799 Assertions, 0 Failures, 1 Skip (`/tmp`-Systempfadtest auf Windows) |
| PostgreSQL (PHP 8.2.31, Windows + E-064 Container) | **GRUEN** | 83 Tests, 799 Assertions, 0 Failures, 1 Skip (wie SQLite) |
| Linux PHP 8.2 (Container) | **GRUEN** | 30 Tests, 128 Assertions, 0 Failures |
| Linux PHP 8.3 (Container) | **GRUEN** | 30 Tests, 128 Assertions, 0 Failures |
| php-cs-fixer | **GRUEN** | `Found 0 of 52 files that can be fixed` |
| phpstan | **GRUEN** | `[OK] No errors` |

### T4 – CI-Folgefix (Tester, Modell: GPT-5 (copilot))

**Status:** GRUEN

Runde 2: Review-Befunde 1/2 behoben - `/tmp`-Sperrfall separat verifiziert (Nicht-root-Lauf: `testAbgleichenUeberspringtTmpPfadInUnixSystempfaden` lief), `/home`-Sperrfall separat mit begruendetem Skip ohne Schreibrechte (Nicht-root-Lauf: `testAbgleichenUeberspringtHomePfadInUnixSystempfadenWennReproduzierbar` wurde geskippt); Docker-Ergebnisse: PHP 8.2/8.4 je **OK (22 Tests, 95 Assertions)**, Nicht-root PHP 8.2 **OK, but some tests were skipped!** (22 Tests, 91 Assertions, 1 Skip).

**Begruendung (E-090):**
- CI-Fehler war reproduzierbar: `tests/Unit/NasAbgleichTest.php::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` legte den externen Pfad unter `/home/...` an; auf Linux-Runnern ist `/home` in `NasAbgleich::SYSTEM_PFADE` gesperrt, dadurch wurde der externe Copy-Schritt uebersprungen (Transfervarianten 5 statt 7).
- Erfolgsfall-Tests fuer erlaubte externe Pfade laufen jetzt instanzlokal mit `systemPfade: []`, damit der fachliche Erfolgsfall plattformunabhaengig bleibt, ohne Produktiv-Sperrliste zu lockern.
- Sperrtests bleiben mit Standardliste aktiv; zusaetzlich abgesichert: `/home/...` und `/tmp/...` sind unter Linux gesperrt, unter Windows mit begruendetem Skip.
- Neues Guardrail: auch mit `systemPfade: []` bleibt ein Vorfahr von `DATA_DIR` gesperrt (Vorfahren-/`APP_ROOT`-Pruefung unveraendert).

**Geaenderte Tests:**
- `NasAbgleichTest::testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle` -> `new NasAbgleich(..., systemPfade: [])` fuer externen Erfolgsfall.
- Neu: `NasAbgleichTest::testAbgleichenUeberspringtDataDirVorfahrAuchBeiLeererSystempfadListe`.
- Neu (statt kombiniertem Unix-Systempfad-Test): `NasAbgleichTest::testAbgleichenUeberspringtTmpPfadInUnixSystempfaden` (muss unter Linux laufen) und `NasAbgleichTest::testAbgleichenUeberspringtHomePfadInUnixSystempfadenWennReproduzierbar` (bei fehlenden Schreibrechten begruendeter Skip).

**Ausgefuehrt (Pflicht):**
- `docker run --rm -v "${PWD}:/home/runner/work/app" -w /home/runner/work/app php:8.2-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `docker run --rm -v "${PWD}:/home/runner/work/app" -w /home/runner/work/app php:8.3-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `docker run --rm -v "${PWD}:/home/runner/work/app" -w /home/runner/work/app php:8.4-cli php vendor/bin/phpunit --colors=never --filter "NasAbgleichTest|NasJobTest"`
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/phpunit --filter "NasAbgleichTest|NasJobTest|SystemPlanerApiTest"`
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/php-cs-fixer check --diff`
- `C:\Users\wosch\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe vendor/bin/phpstan analyse --memory-limit=2G`

**Ergebnisse je Umgebung:**
| Umgebung | Ergebnis |
|---|---|
| Windows, PHP 8.2.31 (lokal) | **GRUEN**: `NasAbgleichTest|NasJobTest|SystemPlanerApiTest` -> 63 Tests, 650 Assertions, 0 Failures, 1 Skip (`/home`/`/tmp`-Sperrtest unter Windows). |
| Linux `/home/runner/work/app`, PHP 8.2.35 | **GRUEN**: 31 Tests, 132 Assertions, 0 Failures. |
| Linux `/home/runner/work/app`, PHP 8.3.36 | **GRUEN**: 31 Tests, 132 Assertions, 0 Failures. |
| Linux `/home/runner/work/app`, PHP 8.4.27 | **GRUEN**: 31 Tests, 132 Assertions, 0 Failures. |
| php-cs-fixer | **GRUEN**: `Found 0 of 52 files that can be fixed`. |
| phpstan | **GRUEN**: `[OK] No errors`. |

**Aufraeumen:** `tests/tmp` im Repo und `nas-extern-backups` neben dem Repo entfernt.

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

### T3 – Runde 1 (Reviewer, Modell: gpt-5.5) – vom Leitstand eingetragen

**Urteil:** CHANGES_REQUESTED (2 Major, 9 Minor, 6 Hinweise). `php -l`, PHPStan, `check-versions --strict` und `test:js` 19/19 waren grün; Fixture v3.0.23 korrekt; VERSION unverändert.

- (a) Probe-Baseline: als gemeldete Abweichung vertretbar, aber der Zustand `ok` ohne Ausführung widerspricht E-021 → Befund 5.
- (b) Watchdog: Antwort von `check` unverändert, Fehler werden nie nach außen gegeben, Drosselung wirksam; die Laufzeit ist nur eingeschränkt begrenzt → Befund 6.

| Nr | Schwere | Fundstelle | Befund | Vorgabe Leitstand für Runde 2 |
|---|---|---|---|---|
| 1 | Major | `MeldungsZustellung.php:105-117` | Erst senden, dann bedingtes UPDATE → Planer und Watchdog können doppelt zustellen (AK 6, R7). Vom Leitstand unabhängig ebenfalls gefunden | Vor dem Senden beanspruchen (Lease, `rowCount`); Watchdog stellt nur bei stehendem Planer zu |
| 2 | Major | `SystemJobActions.php:136` | Zustell-Hinweis zählt auch nie wieder versuchte Einträge über 7 Tage → Dauerbanner | Nur < 7 Tage zählen; ungesendete > 90 Tage löschen |
| 3 | Minor | `Watchdog.php:70-74` | Tägliche Ausbleiben-Mail trotz gemeldeter Störung | gemeinsame Prüfung „gemeldete Störung“ |
| 4 | Minor | `Watchdog.php:36-47` | `aktiv=false` ignoriert; Zeitstempel nach `flock` nicht erneut geprüft | beides umsetzen |
| 5 | Minor | `Planer.php:268-282` | Baseline schreibt `ok` ohne Ausführung (E-021) | Status `uebersprungen`, nur ohne ältere Tagessicherung |
| 6 | Minor | `MeldungsZustellung.php:103-111` | Timeout je Empfänger und Socket, kein Gesamtbudget (Watchdog ≫ 5 s, Testmail bis ~150 s). Vom Leitstand ebenfalls gefunden | Gesamtbudget; Abbruch nach dem ersten Fehlschlag |
| 7 | Minor | `SystemJobActions.php:125-131` | Hinweise auch für inaktive Jobs; Rohnamen | inaktive auslassen, lesbare Namen |
| 8 | Minor | `SystemEinstellungen.php:118` | 25 h → Ausbleiben während der Wiederholungen bzw. 25-h-Tag bei der Zeitumstellung | kein Ausbleiben bei `laufend`/`fehler`; Untergrenze 26 |
| 9 | Minor | `SystemEinstellungen.php:106` | Eingabe im 400-Text; `"false"` → true | fester Text, strenge bool-Prüfung |
| 10 | Minor | `script.js`, Anleitung | Testmail ohne Empfänger und Fehlertexte; Fristhinweis fehlt (AK 8, E-081) | UI und Anleitung angleichen |
| 11 | Minor | `MeldungsZustellung.php:~140` | Fehlercode wird nie ausgegeben (Vertrag 7) | bei passender Regex ausgeben |
| 12 | Hinweis | `MeldungsZustellung.php:182` | `isLocked` NULL | `COALESCE` |
| 13 | Hinweis | mehrere | `@`-Operator in neuem Code | explizite Prüfung |
| 14 | Hinweis | `Planer.php` | Herzschlag nur am Laufanfang → Fehlalarm bei langen Läufen (T4) | Herzschlag je Schritt |
| 15 | Hinweis | `Ueberwachung.php`, `Watchdog.php` | `strtotime` statt injizierter Uhr; Statuszeilen für inaktive Jobs; Sender nicht injizierbar | anpassen |
| 16 | Hinweis | `script.js` | `sessionStorage` unterdrückt auch neue Hinweise | Schlüssel je Hinweisliste, beim Logout löschen |
| 17 | Hinweis | `Ueberwachung.php:150-185` | `aktiv=false` unterdrückt die Entwarnung; sie kommt nach Wiedereinschalten nachträglich | nur dokumentieren |

### T3 – Runde 2 (Reviewer, Modell: gpt-5.5) – vom Leitstand eingetragen

**Urteil:** CHANGES_REQUESTED – Befunde 1–17 bis auf 6 behoben.

Neu:
- **N1 (Major):** Das Budget galt je Eintrag statt je Empfänger, kein Abbruch nach dem ersten Fehlschlag.
- **N2:** Eine Ausnahme nach dem Beanspruchen hinterließ eine Lease ohne Backoff.
- **N3:** Der Watchdog lud die Einstellungen bei jedem `check`.
- **N4:** UI-Text für `uebersprungen` fehlte.
- **N5:** Herzschlag bei langen Einzelschritten → durch T4 Punkt 5 gelöst.
- **N6–N9:** Hinweise.

### T3 – Runde 3 (Reviewer, Modell: gpt-5.5) – vom Leitstand eingetragen

**Urteil:** APPROVE. N1–N4 und N7–N9 sind behoben. Neue Befunde:
- **R1 (Minor):** Ein dauerhaft abgelehnter erster Empfänger blockiert die übrigen → T4 Punkt 9 (Rotation).
- **R2 (Minor):** Bei `aktiv=false` liest jeder `check` die Einstellungen.
- **R3 (Nit):** Text bei aufgebrauchtem Budget → T4 Punkt 9.
- **R4 (Nit), R5 (Info):** zurückgestellt.

### T4 – Runde 1 (Reviewer, Modell: gpt-5.5) – vom Leitstand eingetragen

**Urteil:** CHANGES_REQUESTED (3 Major, 11 Minor, 1 Hinweis). `php -l`, PHPStan, `check-versions`, `test:js` grün; keine Lecks des Passworts, Minimal-Env, nur `copy`/`mkdir`/`rcat`/`obscure`, Filter exakt nach Vertrag.

| Nr | Schwere | Fundstelle | Befund | Leitstand |
|---|---|---|---|---|
| 1 | Major | `NasAbgleich.php:426/465` | `proc_close()` liefert nach `proc_get_status()` auf PHP < 8.3 den Wert `-1` → jeder rclone-Aufruf gilt als fehlgeschlagen | **Bestätigt:** `php:8.2-cli` (8.2.35, Linux) → `NasAbgleichTest` 8/12 rot (`NAS_RCLONE_FEHLT`). Lokal (Windows 8.2) und in der Ende-zu-Ende-Prüfung (Image 8.3) unsichtbar |
| 2 | Major | `NasAbgleich.php:289-310` | Ausgelagerter Pfad als Vorfahr von `DATA_DIR`/`/etc` → Geheimnisse auf die NAS (Admin-Exfiltration, AK 4) | Vorfahren-/Systempfad-Sperre plus unverankerte Ausschlüsse |
| 3 | Major | `NasJob.php:91` | Manueller Lauf überschreibt `tageslaufDatum` → automatischer Lauf entfällt oder falsche Fehler-Mail | `start()` ohne Datum |
| 4–14 | Minor | div. | `NAS_ZEIT` im Verbindungstest; eingefrorene Uhr (F10); ungeschütztes `fwrite`/`mkdir`; Regex ohne `/D`; Nicht-String-Eingaben → 500; Drosselung ohne Sperre; `prompt()` mit Klartext-Passwort; Schlüssel bleibt im DOM; Auto-Speichern verschluckt Eingaben; Groß-/Kleinschreibung bei Fehlermeldungen; Doku (`&amp;`, „Live-Datenbank“, www-data) | alle in Runde 2 |
| 15 | Hinweis | `NasAbgleich.php` | Verbindungstest auf beliebige Host/Port | akzeptiert (Risikotabelle) |

**Leitstand-Prüfungen zu T4 Runde 1:**
- Image-Build (`ENABLE_OCR=false`, Debian trixie): `rclone v1.60.1-DEV` installiert, Build-Prüfung `help backend smb` bestanden, Crontab-Zeile `nas:abgleich` vorhanden.
- Ende-zu-Ende-Prüfung im gebauten Image (PHP 8.3, `www-data`) gegen eine Samba-Freigabe mit echtem App-Code:
  - falsches Passwort → `NAS_ANMELDUNG`; falscher Host → `NAS_NICHT_ERREICHBAR` (Host im Log als `***`);
  - richtige Zugangsdaten → Verbindungstest ok, manueller Auftrag → `nas: ok`, Auftrag `fertig`, Statusdatei geschrieben;
  - Passwort nur verschlüsselt in der DB, nicht in Protokoll/Status;
  - auf der NAS nur die Positivliste (nicht übertragen: `secret.key`, `database.sqlite`, Startpasswort, Pepper, `.tmp-*`).

### T4 – Runde 2 (Reviewer, Modell: gpt-5.5) – vom Leitstand eingetragen

**Urteil:** CHANGES_REQUESTED. Behoben: 1, 3–14 (Exit-Code jetzt auf Linux 8.2/8.3 belegt); 2 nur teilweise.

| Nr | Schwere | Befund | Leitstand |
|---|---|---|---|
| 1 | Major | Die unverankerten Ausschlüsse `**/secret.key` … greifen im **Wurzelordner** des externen Pfads nicht (rclone verlangt bei `**/x` mindestens ein `/`), belegt mit `rclone/rclone:1.60.1`. | **Fehler in der Leitstand-Vorgabe** (Runde 2, Punkt 2b) → Regeln ohne `**/`, Pflicht-Verifikation per `rclone lsf` in Runde 3 |
| 2 | Minor/Major | Ein manueller Erfolg setzt `tageslaufDatum = heute`, `ok` → der automatische Lauf nach der Sicherung entfällt | nur bei Fälligkeit oder heutigem Fehlschlag |
| 3 | Minor | Array als `passwort` im Handler → 500 statt 400 | `is_string` |
| 4 | Hinweis | Sperrliste lückenhaft (`/tmp`, `/home`, `/var/log`, APP_ROOT ohne `data/` …) | erweitern |
| 5 | Hinweis | `externeUebersprungen` nicht sichtbar | UI und Doku |
| 6 | Hinweis | Passwort-Modal ohne Sperre gegen Doppelauslösung | „läuft“-Flag |

**Leitstand-Entscheidung zum Tester-Befund (2):** `pfad="a\n"` wird getrimmt und als `a` gespeichert; ein Umbruch innerhalb scheitert an `/D` → gewolltes Verhalten, der Test wird angepasst (E-090).

### T4 – Runde 3 (Reviewer, Modell: gpt-5.5)

**Urteil:** APPROVE – Befunde 1–6 behoben.
- Filter mit echtem rclone 1.60.1 bis in die 3. Ebene geprüft.
- Pfadsperre per Reflection mit gut 20 Linux-/Windows-Pfaden geprüft (gesperrt: Vorfahren, APP_ROOT-Unterordner außerhalb `data/`, Systempfade; erlaubt: `/mnt/nas-export`, `/srv/baukalk-uploads` u. a.).
- Neue Befunde nur Nit/Hinweis: Doku-Formulierung zu `data/` (vom Leitstand korrigiert); Warnzeile nur am letzten Lauf; `/home` und `/tmp` komplett gesperrt (bewusst); `is_string`-400 nach der Drossel.

## 6. Lernpunkte

- E-021, E-022 (Backup/Migration) aus Review T2 in `erkenntnisse.md`, erstes Auftreten → nur dort.
- T3/T4: E-023 (Claim vor Zustellung), E-024 (Zeitbudget an der innersten Schleife), E-025 (UI-Hinweise nur aus behebbaren Zuständen), E-026 (Filter gegen echtes Werkzeug prüfen, Pfadsperre), E-027 (Tageslauf nur im Tageslauf-Pfad → `php-backend.instructions.md`, 2. Auftreten), E-067 (Prozess-Code unter Linux mit kleinster PHP-Version testen). E-087 → `AGENTS.md` (2. Auftreten).
- Kennzahlen T3/T4: je 3 Review-Runden (Schleifenbremse erreicht, nicht überschritten). Rote Testläufe überwiegend aus Leitstand-/Reviewer-Befunden; davon zwei Tests, die einer Vertragsvorgabe widersprachen (E-090).
- **CI nach Release 3.0.23 rot** (1 Test, Repository auf dem Runner unter `/home` → gesperrt). Nach Rückfrage beim Nutzer (Schleifenbremse) gab es den Folge-Patch 3.0.24: Sperrliste per Konstruktor-Parameter für Tests ersetzbar, Tests getrennt und isoliert. Leitstand-Prüfung: CI-Situation per Docker unter `/home/runner/…` nachgestellt, auch als Nicht-root. Gesamtläufe SQLite 628 / PostgreSQL 629 grün, Review APPROVE. E-067 (2. Auftreten) → `tests.instructions.md`.

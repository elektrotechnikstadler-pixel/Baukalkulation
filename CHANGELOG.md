# Noch offene Punkte

- **Feinkörnige Konflikt-Erkennung für Einzeldatensätze** (Kunden, Rechnungen, Termine,
  Gruppen, Dashboard, Schnellnotizen, Gleitzeit, Dienstleister, Modul-Datensätze,
  `admin_*_zeiterfassung`): Diese Endpunkte sind „letzter gewinnt" pro Datensatz – bearbeiten
  zwei Personen denselben Datensatz gleichzeitig, überschreibt die letzte Speicherung (nur
  dieser eine Datensatz, kein Massenverlust). Optional nachrüstbar über einen
  `updatedAt`/Versions-Check pro Datensatz. (Stand 2026-08-07)
- **Rechnungs-PDF in der Git-Historie:** `R26000174.pdf` ist aus dem Projekt entfernt, steckt aber
  noch in Commit `e2ec875` (Tag `v2.10.99-pre-restructure`). Endgültig entfernen hieße, die
  Historie umzuschreiben (alle Commit-Kennungen und der Tag ändern sich) – Entscheidung offen.
  (Stand 2026-09-26)
- **OCI-Lieferanten-Passwörter** (`oci_lieferanten.password`) liegen im Klartext in der Datenbank.
  Optional wie SMTP-Passwort per `SecretBox` verschlüsseln. (Stand 2026-09-26)
- **Backup-Mail ohne ZIP-Passwort:** wird derzeit unverschlüsselt (mit Warnung im Log) versendet.
  Offen, ob der Versand ohne Passwort blockiert werden soll. (Stand 2026-09-26)
- **Aufmaß-Import aus Magicplan (CSV/XLSX):** geplant und freigegeben (Parser für den Magicplan-„Statistiken“-Export,
  generischer Tabellen-Import, danach Schreinerei-Vorlagen), Umsetzung vorerst zurückgestellt.
  Plan: `.github/ki/arbeitspakete/AP-20260929-aufmass-import.md`. (Stand 2026-10-01)

---

## Unveröffentlicht

### Neu
- **Update & Systeminfo** (Verwaltung, nur Administratoren): zeigt Version, Schema-Stand,
  DB-Treiber, PHP und Extensions, Betriebsart, letzte Sicherung, Lizenz und Module.
  „Auf Updates prüfen“ fragt die neueste Release-Version ab (Quelle `BK_UPDATE_REPO` in `.env`,
  gedrosselt) und zeigt Datum, Änderungen und die Befehle zum Aktualisieren.
- `bin/console app:info` und `app:check-update` (Exit 0 aktuell, 2 Update verfügbar, 1 Fehler).

---

## v3.0.1 – PostgreSQL, Migrationen, Sicherheit, Kupferpreis, Datanorm-Umlaute, Projektansicht

### Neu – PostgreSQL (Phase 3)
- **Wahlweise PostgreSQL statt SQLite** (`BK_DB_DRIVER=pgsql`, Zugang über `BK_DB_*`,
  Passwort bevorzugt als Datei/Docker-Secret). SQLite bleibt Standard und wird weiter unterstützt.
- `docker-compose.postgres.yml`: PostgreSQL 17 ohne veröffentlichten Port, Passwort als Secret,
  Datenprüfsummen, Healthcheck, nächtlicher `pg_dump` (14 Tage).
- `bin/console db:transfer to-pgsql|to-sqlite`: kompletter Umzug in beide Richtungen in einer
  Transaktion, mit Zeilenzahl-Prüfung; befüllte Ziele nur mit `--force`.
- Sicherungen enthalten auch unter PostgreSQL eine `database.sqlite` – jede Sicherung lässt sich in
  beiden Betriebsarten einspielen, alte Sicherungen und JSON-Altbestände weiterhin auch.
- Cron-Jobs erhalten die Container-Umgebung (`/etc/baukalkulation.env`), damit sie die
  konfigurierte Datenbank nutzen.
- CI testet zusätzlich gegen PostgreSQL 15 und 17; lokal `make test-pgsql`.

### Behoben
- Datanorm: Nach dem Update einmal neu indexieren; bereits übernommene Positionen bleiben unverändert.
- Docker-Image seit der Umstellung auf `public/`: Die `.htaccess` der Projektwurzel erzeugte eine
  Redirect-Schleife (HTTP 500 auf alle Anfragen).
- Einspielen einer Sicherung stellte **archivierte Baustellen** nicht wieder her; ihre IDs konnten
  danach neu vergeben und alte Stunden/Rechnungen einer neuen Baustelle zugeordnet werden.
- Sicherung unter PostgreSQL brach bei verknüpften Lagerdaten ab (Fremdschlüssel beim Kopieren).
- Wochen- und Tagesprüfung: Die Tabellen entstanden erst beim ersten Aufruf und fehlten deshalb in
  Sicherungen aus PostgreSQL; sie werden jetzt per Migration angelegt.
  (Alle drei beim Probelauf mit einem echten Datenbestand gefunden.)
- Mehrere Mandanten auf einem Server: `install.sh`/`install.ps1` schlagen jetzt eindeutige Namen aus
  dem Mandantenordner vor (auch für Autoheal), prüfen Projektname, Container-Namen und Port auf
  Kollisionen mit anderen Instanzen und behalten beim Update alle übrigen `.env`-Einträge
  (bisher wurde die `.env` komplett neu geschrieben).
- **Kupferpreis (DEL-Notierung):** Abruf nur noch aus der oberen Kupfer-WM-Notiz von Westmetall
  (€/100 kg); die Ersatzquellen LME und finanzen.net lieferten Dollar je Tonne und damit einen
  falschen Metallzuschlag und wurden entfernt. Das Notiz-Datum wird gespeichert und angezeigt, ein
  veralteter Wert bzw. fehlgeschlagener Abruf ist sichtbar gekennzeichnet, Fehlversuche werden für
  30 Minuten gedrosselt. Ein manuell eingegebener Wert wird nicht mehr nach 6 Stunden automatisch
  überschrieben; Eingaben außerhalb 100–5000 €/100 kg werden abgelehnt. Ist „Kupferpreis DEL“
  abgeschaltet, wird weder abgerufen noch aufgeschlagen. Bezeichnung `[MZ+… €]` und `3×1,5`
  werden am Desktop wieder richtig dargestellt bzw. erkannt.

### Neu – PostgreSQL als Alternative zu SQLite (Phase 3)
- **Wahlweise PostgreSQL** (`BK_DB_DRIVER=pgsql`, Zugang über `BK_DB_*`, Passwort als
  Docker-Secret). SQLite bleibt Standard und wird weiter voll unterstützt.
- `docker-compose.postgres.yml`: PostgreSQL 17 ohne veröffentlichten Port, Datenprüfsummen,
  Healthcheck und nächtlichem `pg_dump` (14 Tage).
- **`bin/console db:transfer to-pgsql|to-sqlite`** überträgt den kompletten Datenbestand in
  beide Richtungen und prüft die Zeilenzahlen; ein befülltes Ziel wird nur mit `--force`
  überschrieben.
- **Sicherungen bleiben austauschbar:** auch unter PostgreSQL enthält jedes Backup eine
  `database.sqlite` (aus einem konsistenten Lese-Snapshot); alle bisherigen Sicherungen lassen
  sich in beide Betriebsarten einspielen.
- Cron-Jobs im Container erhalten jetzt die `BK_*`-Umgebung (vorher unsichtbar für cron).
- SQLite-spezifisches SQL im Code portabel gemacht (Groß-/Kleinschreibung, UPSERT, JSON,
  Audit-Export, Kalender-Export); Verhalten unter SQLite unverändert.
- CI testet zusätzlich gegen PostgreSQL 15 und 17.

### Sicherheit
- **Support-Konto „Systemadmin" ohne festes Passwort.** Bisher hatte es in jeder Installation
  dasselbe, im Quellcode stehende Startpasswort. Neu: `BK_SYSTEMADMIN_PASSWORD` (Env, min. 12
  Zeichen) oder ein zufälliges Passwort in `data/systemadmin-passwort.txt`. Bestehende
  Installationen, in denen das alte Passwort noch galt, bekommen beim Update automatisch ein
  neues (auch beim Einspielen alter Sicherungen). Zurücksetzen:
  `php bin/console user:reset-password Systemadmin`.
- **SMTP-Passwort und Gemini-API-Key** werden verschlüsselt gespeichert (vorhandene Klartextwerte
  werden beim Update umgestellt) und **nicht mehr an den Browser ausgeliefert** – bisher erhielt
  jeder angemeldete Benutzer beide Werte über `api.php?action=check`. Die Einstellungen zeigen nur
  noch „gespeichert"; leer lassen = unverändert.
- Wird eine Sicherung einer **anderen** Installation eingespielt, lassen sich diese Werte dort nicht
  entschlüsseln (anderer `data/secret.key`) und müssen neu eingegeben werden.
- **Firmenlogo:** `api.php?action=get_logo` lieferte ohne Anmeldung jede Server-Datei aus, deren Pfad
  in der Einstellung `firma_logo_url` stand (setzbar über die Einstellungen oder eine eingespielte
  Sicherung); Beleg-PDF, E-Rechnung und VDE-Protokoll betteten diese Datei ein. Jetzt gilt nur noch
  das hochgeladene Logo `firma_logo.{png,jpg,gif,webp}` im Datenverzeichnis mit Bild-Inhalt; andere
  Werte lehnt das Speichern ab. Nebeneffekte: `get_logo` beachtet `BK_DATA_DIR`, das VDE-Protokoll
  zeigt das Logo jetzt an. **SVG-Altlogos werden nicht mehr ausgeliefert – bitte als PNG neu hochladen.**
- **`api.php?action=check` ohne Anmeldung** liefert keine Einstellungen (Firmendaten, IBAN,
  SMTP-Server/-Benutzer) und keinen Lizenznehmer/Ablaufdatum mehr; Statuscode und übrige Felder
  (z. B. für den Docker-Healthcheck) bleiben.

### Neu – Datenbank-Migrationen und Sicherungen (Phase 2)
- **Versionierte Schema-Migrationen** (Phinx, Tabelle `schema_migrations`). Der bisherige
  Schema-Stand ist als Basis-Migration eingefroren und hebt auch alte Datenbanken an.
  Ist die Datenbank neuer als die App (Downgrade), verweigert die App den Betrieb (HTTP 503),
  der Container startet nicht.
- **`bin/console`**: `db:migrate`, `db:status`, `backup:create`, `backup:import`.
- **Sicherungsformat 2**: zusätzlich `manifest.json` (App-/Schema-Version, SHA-256).
  Die Datenbank wird per `VACUUM INTO` gesichert – konsistent auch bei laufenden
  Schreibzugriffen. Ältere Sicherungen (Format 1 und reine JSON-Tagessicherungen) lassen sich
  weiterhin einspielen und werden dabei automatisch auf den aktuellen Stand gehoben.
- **Import ganz oder gar nicht**: Wiederherstellen, Upload und „Komplett ersetzen" laufen in
  einer Transaktion. Bisher wurden fehlerhafte Tabellen still übersprungen (Teil-Restore).
  Manipulierte Sicherungen (Prüfsumme), Sicherungen neuerer Versionen und defekte Dateien
  werden abgelehnt. Nach dem Import müssen offene Clients die Zeiterfassung neu laden.
- **Backup per E-Mail verschlüsselt**: ZIP-Passwort (AES-256) in den Erinnerungs-
  Einstellungen; das Passwort wird verschlüsselt gespeichert (`data/secret.key`) und nie an den
  Browser ausgeliefert. Größenlimit für den Anhang (Standard 20 MB). Ohne Passwort wird wie
  bisher unverschlüsselt versendet (mit Warnung im Log und in der Mail).
- Verschlüsselte Sicherungen lassen sich hochladen; die App fragt nach dem Passwort.

### Behoben
- Tagessicherung und manuelle Sicherung konnten Daten der letzten Minuten verlieren: der
  WAL-Checkpoint scheiterte an einem offenen Statement („database table is locked") und die
  Datei wurde trotzdem kopiert.
- „Sicherung wiederherstellen" ist nur noch per POST möglich (vorher per GET-Link auslösbar).

### Umbau – Projektstruktur (Phase 1)
- **Webroot ist jetzt `public/`.** Alle Browser-Dateien (HTML, JS, CSS, Icons, `lib/`,
  Modul-JS/CSS) und die API-Einstiegspunkte liegen dort. `src/`, `vendor/`, `modules/`
  (Backend) und `data/` sind damit grundsätzlich nicht mehr per HTTP erreichbar.
- **Docker:** `DocumentRoot` = `/var/www/html/public`. Projektwurzel, Volumes und
  Cron-Pfade (`/var/www/html/cron_*.php`, `/var/www/html/migrate.php`) bleiben gleich –
  bestehende Installationen brauchen nur ein neues Image (`docker compose up -d --build`).
- **Ohne Docker:** Den Webserver auf `public/` zeigen lassen. Zeigt er noch auf die
  Wurzel, leitet eine `.htaccess` intern nach `public/` um.
- Firmenlogo für PDFs: `public/logo_es.png`. Die Logo-Vorschau in den Einstellungen lädt
  hochgeladene Logos jetzt über `api.php?action=get_logo`.
- App-Version steht nur noch in `VERSION` (`npm run version:sync` überträgt sie).
- Kopie-Ordner `deploy/` entfernt.
- Neu für die Entwicklung: PHPUnit-Tests, PHPStan, PHP-CS-Fixer, Makefile, Pre-commit-Hooks,
  GitHub-Actions (CI + Release mit Docker-Image), Renovate, Doku unter `docs/`.

### Behoben – Wiederherstellen setzte Baustellen nicht zurück
- „Sicherung wiederherstellen" und „Backup hochladen" meldeten Erfolg, stellten bei einer
  Instanz mit vorhandenen Daten aber **nur** Kunden, Benutzer, Zeiten und Modul-Daten wieder
  her – die Baustellen blieben auf dem aktuellen Stand (stiller Revisionskonflikt).
  Jetzt werden auch die Baustellen zurückgesetzt; bei einem echten Konflikt wird mit
  HTTP 409 abgebrochen, **bevor** etwas verändert wird.
- Eine Sicherung ohne Baustellen kann eine Instanz mit Baustellen nicht mehr leeren (HTTP 400).
- Sicherheitskopien vor einem Restore erhalten eindeutige Namen; ein Restore aus einer
  Sicherheitskopie überschreibt diese nicht mehr, wenn er in derselben Sekunde läuft.

---

## v2.10.99 – Stundenauswertung: „Projekt existiert nicht mehr" (ZE005) ausgeblendet

### Behoben – Warnliste lief mit gültigen ZE005-Buchungen voll
- Buchungen auf Projekte, die **nicht mehr existieren** (`ZE005` – archiviert **oder** hart
  gelöscht), wurden in der Stundenauswertung als „Auffällige Buchungen" / „nicht reparierbar"
  gelistet. `ZE005` ist aber reine Information: die Arbeitszeit bleibt gültig und ist pro
  Zeile nicht reparierbar. Bei vielen betroffenen Projekten wurde die Liste unnötig lang.
- Alle `ZE005`-Buchungen werden jetzt **aus der Warnbox ausgeblendet**
  (`_saIsProjektWegBuchung`). Sie erscheinen weiterhin ganz normal in der Tagesliste; die
  Stunden zählen unverändert. Fehlende Projektbuchungen aktiver Projekte (`ZE006`) bleiben
  weiterhin sichtbar und reparierbar.

### Verbessert – klareres Label für hart gelöschte Projekte
- Buchungen auf ein Projekt, das **weder aktiv noch im Archiv** existiert (hart gelöscht,
  ohne hinterlegten Namen), zeigten nur die nackte ID („#69"). Jetzt wird
  **„Projekt #69 (gelöscht)"** angezeigt. Der ursprüngliche Projektname ist in diesen Fällen
  datenseitig nicht mehr vorhanden.
- Solche verwaisten Stunden lassen sich über **„Verwaiste Stunden reparieren"** in benannte,
  archivierte Platzhalter überführen (Stunden bleiben gültig).

---

## v2.10.98 – Datensicherung: „Komplett ersetzen aus anderem Backup"

### Neu – Ziel-Instanz 1:1 aus einem Backup spiegeln
- Im Fenster **„Datensicherung"** gibt es einen neuen Bereich **„🔁 Komplett ersetzen aus
  anderem Backup"** mit eigenem Upload-Feld und Button (getrennt vom bisherigen
  „Wiederherstellen", das weiterhin **mergt**).
- **„Komplett ersetzen"** übernimmt die **aktiven** Daten **1:1** aus dem hochgeladenen
  ZIP (`backup_upload_replace` → `backupUploadReplace()`, `saveAllData` mit `force`).
- **Aktive Projekte, die nicht im Backup enthalten sind, werden ins Archiv verschoben**
  (Export-JSON in den Archiv-Ordner + Soft-Delete `archiviert=1`) – **nicht gelöscht**.
- Zusätzlich wird die **Zeiterfassungs-Historie** (`zeiterfassung_log`,
  `zeiterfassung_meta`) 1:1 gespiegelt (`_restoreFromSqlite(..., $full=true)`).
- **Bereits vorhandene Archivdaten des Zielservers bleiben unangetastet.**

### Sicherheit
- Vor dem Ersetzen wird automatisch ein **Sicherheits-Snapshot** (`_pre-replace`) angelegt;
  scheitert dieser, wird **nichts** verändert.
- Ein **Backup ohne aktive Baustellen** wird abgelehnt, damit nicht versehentlich alle
  aktiven Projekte archiviert werden.
- Serverlokale Daten (`audit_log`, `cal_tokens`, Revision `data_meta`) werden bewusst
  **nicht** übertragen.

---

## v2.10.97 – Rechnung/Angebot: Formular überarbeitet + PDF im Brieflayout (DIN 5008)

### Geändert – Eingabemaske größer & einheitlicher
- Das Rechnungs-/Angebotsformular ist breiter (940 px) und übersichtlicher.
- Die **Baustellen-/Projektauswahl** hat jetzt – analog zur Kundenauswahl – ein
  **Suchfeld über der Auswahlliste** (Filter nach Name **und** Projekt-Nr.). Beide Auswahlen
  sind damit optisch und in der Bedienung identisch.

### Geändert – PDF-Ausgabe im Brieflayout
- Rechnung und Angebot werden jetzt als **Geschäftsbrief nach DIN 5008** ausgegeben
  (Client-Vorschau **und** Server-PDF): Briefkopf rechts, Anschriftfeld links mit
  Rücksendezeile, **Infoblock rechts** (Nr., Datum, Kunden-Nr., Projekt, ggf. Fällig) sowie
  eine fette **Betreffzeile**. Ersetzt die bisherige waagerechte Meta-Zeile.

---

## v2.10.96 – „Neues Projekt": Kundensuche in der Zuweisung

### Neu – Kunde suchen/filtern beim Projekt anlegen
- Im Dialog **„Neues Projekt anlegen"** gibt es über der Auswahl **„Kunde zuweisen"** jetzt
  ein Suchfeld, das die Kundenliste live filtert (`filterNewBaustelleKunde`). Erleichtert die
  Auswahl bei vielen Kunden.

---

## v2.10.95 – Rechnungen/Angebote: Direkt-PDF pro Beleg, Formular-Layout, Filter-Dropdown

### Neu – Beleg als PDF per Klick (ohne Browser-Kopf-/Fußzeile)
- In der Rechnungs- und Angebotsliste öffnet der neue **„📄 PDF"-Button** jeden Beleg als
  serverseitig gerendertes PDF (Dompdf, `render_beleg_pdf`) in einem neuen Tab – **sauber
  ohne Browser-Kopf-/Fußzeile** (URL, Datum, Seitenzahl). Funktioniert für Rechnungen **und**
  Angebote. Optik identisch zur Vorschau (DIN 5008 Falzmarken, wiederholte Fußzeile,
  korrekte MwSt-/Kleinunternehmer-/Reverse-Charge-Behandlung).

### Fix – Eingabemaske „verschoben" bei neuen Belegen
- Bei einer **neuen** Rechnung/einem neuen Angebot war die Nummer-Zelle ein leeres
  Grid-Feld, wodurch die erste Formularzeile nach rechts verrutschte. Es wird jetzt ein
  Hinweisfeld („wird beim Speichern vergeben") angezeigt; das 2-Spalten-Raster ist oben
  ausgerichtet (`align-items:start`).

### Fix – Baustellensuche-Dropdown „verschoben" beim Scrollen
- Das Baustellen-Suchdropdown (`position:fixed`) wird beim Scrollen im Formular jetzt dem
  Eingabefeld **nachgeführt** statt zu verschwinden/zu verrutschen.

### Verbessert – Aktions-Buttons der Listen
- Die Aktionsschaltflächen je Zeile werden umbruchsicher gruppiert und rechtsbündig
  ausgerichtet (sauberere Darstellung).

---

## v2.10.94 – Wochenplanung: eigene Erfassungstypen nachtragen, „geplant" entfernt

### Neu – Eigene Erfassungstypen in der Jahresansicht nachtragbar
- Der Tages-Buchungsdialog der Wochenplanung-Jahresansicht bietet jetzt zusätzlich die
  **selbst angelegten Erfassungstypen** zur Auswahl (neben Urlaub/Krank/Gleitzeit/Fehlgrund).
- Für eigene **„Ist = Soll"**-Typen wird beim Nachtragen serverseitig automatisch die
  **Soll/Tag-Zeit** gutgeschrieben (`admin_add_zeiterfassung`), sodass sie korrekt als Ist zählen.

### Geändert – Jahresansicht zeigt nur noch „genommen"
- Die Anzeige „geplant/verplant" wurde entfernt; angezeigt werden **genommener Urlaub**,
  Jahresanspruch und Resturlaub (verplante = eingetragene Urlaubstage wurden ohnehin als
  genommen erkannt).

---

## v2.10.93 – Wochenplanung Urlaub/Soll-Ist, Erfassungstyp „Ist = Soll", Suchmodus-Schalter

### Fix – Backup-E-Mail zeigt aktuelle SW-Version
- `cron_backup_email.php` hatte eine fest verdrahtete, veraltete `APP_VERSION`. Sie wird
  jetzt dynamisch aus `manifest.json` gelesen → Mail-Text und Snapshot enthalten immer die
  aktuelle Version.

### Neu – Wochenplanung Jahresansicht (Einzel-Mitarbeiter)
- **Urlaub gesamt:** Jahresanspruch (aus Benutzerverwaltung), genommen, verplant und
  **Resturlaub** in der Zusammenfassung.
- **Soll/Ist ± je Monat:** jede Monatskachel zeigt Monats-Soll, Monats-Ist und Differenz;
  zusätzlich Jahres-Saldo. (`urlaubstageProJahr` jetzt auch in `list_users`/`list_users_basic`.)

### Neu – Erfassungstyp „Ist = Soll-Arbeitsstunden"
- In Verwaltung → Allgemein kann ein eigener Erfassungstyp (mit „Arbeitszeit") zusätzlich als
  **„Ist = Soll"** markiert werden. Bei Auswahl in der Stundenerfassung (**Desktop, mobil,
  mobile-light**) ist keine Stundeneingabe nötig – es werden automatisch die **Soll-Stunden
  des Tages** als Ist gebucht (Nicht-Arbeitstag = 0).
- Eigene „Arbeitszeit"-Typen **ohne** dieses Häkchen erlauben nun die **manuelle Stunden­eingabe**
  (zuvor wurde immer Soll gebucht). Mobile.html unterstützt eigene Erfassungstypen jetzt überhaupt.

### Neu – Suchleisten-Umschalter (Projektsuche ↔ Globale Suche)
- Neben dem Projekt-Suchfeld schaltet ein Segment-Schalter zwischen **Projektsuche**
  (filtert die Projektliste) und **Globale Suche** (öffnet/aktualisiert die globale Suche über
  alle Datensätze). Auswahl wird pro Gerät gespeichert.

---

## v2.10.92 – E-Rechnung (ZUGFeRD): Kleinunternehmer & Reverse-Charge

### Fix – Korrekte EN16931-Steuerkategorien
- `ZugferdService` erzwang bisher **19%** (Kategorie `S`) und ignorierte Kleinunternehmer/
  Reverse-Charge. Jetzt wird die korrekte Steuerkategorie gesetzt:
  - **Standard:** `S` mit konfiguriertem Satz (`firma_mwst_satz`).
  - **Kleinunternehmer (§19 UStG):** `E` (steuerbefreit), 0%, mit Befreiungsgrund.
  - **Reverse-Charge (§13b UStG):** `AE`, 0%, mit Begründung + Code `VATEX-EU-AE`.
  Der Befreiungsgrund wird korrekt in der Steueraufschlüsselung (BG-23) übergeben.
- **PDF-Darstellung korrigiert:** `buildInvoiceHtml` erhielt den MwSt-Satz gar nicht und
  zeigte **immer „MwSt. 19%"**. Jetzt wird der reale Satz angezeigt; bei 0% erscheint eine
  Umsatzsteuer-0-Zeile und der gesetzliche Hinweistext (§19 bzw. §13b).

---

## v2.10.91 – Kalkulations-Audit: MwSt & Material-Preisformel konsistent

### Fix – MwSt-Satz nicht mehr hartcodiert (19%)
- Offener Betrag / bezahlte Rechnungen (`recalc`), die Abschläge-Tabelle und die
  Kunden-Übersicht rechneten Rechnungen fest mit **19% MwSt** und ohne Positions-Rabatt/-Typ.
  Neu: gemeinsame Helfer `rechnungNetto`/`rechnungBrutto` nutzen den **konfigurierten
  MwSt-Satz** und berücksichtigen **Kleinunternehmer/Reverse-Charge (0%)** sowie
  Positions-Rabatt und `posTyp` – konsistent mit `renderLinkedDocs` und der Rechnungslogik.
- **Angebots-PDF per E-Mail** (`EmailActions::_generateAngebotPdf`): las den falschen
  Settings-Key `mwst_satz` (statt `firma_mwst_satz`) → rechnete **immer 19%**. Jetzt
  korrekter Satz inkl. Kleinunternehmer/Reverse-Charge.

### Fix – Material-Preis in Archiv-Auswertung & Export
- Die Archiv-Auswertung (`BaustelleActions`) und der Positions-Export (`ExportActions`)
  ignorierten **Rabatt** und **Direkt-VK (`vkDirekt`)** und wichen damit vom Frontend
  (`matVk`) ab. Formel angeglichen: `EK × (1+Aufschlag) × (1+Global) × (1−Rabatt)`
  bzw. bei `vkDirekt`: `EK × (1−Rabatt)`.

### Geprüft – korrekt (keine Änderung nötig)
- Material-VK (`matVk`), Arbeitszeit (`Stunden × Preis`, Fixkosten als €/h),
  Pauschalen, Summen/Gewinn, Rechnungs-/Beleg-PDF (`BelegPdfService`) berücksichtigen
  Steuerregelungen bereits korrekt.

### Bekannt – noch offen
- **ZUGFeRD/XRechnung** (`ZugferdService`) erzwingt 19% und behandelt Kleinunternehmer/
  Reverse-Charge nicht (bräuchte korrekte EN16931-Steuerkategorien 'E'/'AE'). Nur relevant
  bei E-Rechnungs-Export solcher Firmen – separat und sorgfältig zu lösen.

---

## v2.10.90 – Positions-IDs: automatische Persistenz & Archiv-Konsistenz

### Verbesserung – Reparatur sofort dauerhaft
- Wenn `migrateAppData` beim Laden doppelte/fehlende Positions-IDs korrigiert
  (`_ensureUniquePosIds`), wird der bereinigte Stand jetzt **einmalig automatisch
  gespeichert**. Dadurch ist die Reparatur sofort geräteübergreifend dauerhaft und
  nicht erst nach der nächsten manuellen Bearbeitung.

### Verbesserung – Archivieren entfernt exakt eine Position
- `archiveMatRow`, `archiveAzRow`, `archivePauschaleRow` nutzen jetzt `findIndex` +
  `splice(idx, 1)` statt `filter` – konsistent zu den Lösch-Funktionen (verhindert
  Mehrfach-Entfernung selbst bei theoretischer ID-Kollision).

---

## v2.10.89 – Datenintegrität: Positions-IDs (Löschen/Bearbeiten)

### Fix – Mehrfach-Löschung / falsche Bearbeitung von Positionen
- **Ursache:** In manchen Projekten hatten Positionen doppelte IDs (der Zähler
  `nextMatId`/`nextAzId`/… war auf 1 zurückgesetzt, obwohl bereits Positionen mit
  ID 1, 2, 3 … existierten). Dadurch löschte `filter(x => x.id !== id)` **mehrere**
  Zeilen gleichzeitig und `find(x => x.id === id)` bearbeitete teils die **falsche**
  Position.
- **Neu:** Beim Laden (`migrateAppData`) erzwingt `_ensureUniquePosIds(...)` eindeutige
  IDs und setzt die `next…Id`-Zähler korrekt – für **Material, Arbeitszeit (AZ),
  Pauschalen (entryId), Abschläge/„Sonstiges", fehlendes Material und Bautagebuch**.
  Bestehende Duplikate werden automatisch neu vergeben und beim nächsten Speichern
  dauerhaft repariert.
- **Zusätzliche Absicherung:** Die Lösch-Funktionen (`delMatRow`, `delAzRow`,
  `delPauschaleRow`, `delAbschlagRow`, `delFehlendesMatRow`) entfernen jetzt exakt
  **eine** Position per Index (splice), selbst falls doch noch eine ID-Kollision
  auftreten sollte.

---

## v2.10.88 – Suche, Auswertungs-Filter, Wochenplan-Jahresansicht & Monats-PDF

### Neu – Projektsuche
- **Mehrwort-Suche über Unterprojekte:** Der Suchindex bezieht jetzt Eltern-Name/-Nr
  mit ein. „Lidl Dachau" findet zuverlässig das Unterprojekt „Dachau" unter „Lidl";
  passt ein Unterprojekt, bleibt sein Oberprojekt als Container sichtbar (auto-aufgeklappt).
- **Umschalter globale Suche:** Button (🌐) am Projekt-Suchfeld öffnet die globale Suche
  mit dem aktuellen Begriff. Standard bleibt die Projektsuche.

### Neu – Wochenplanung Jahresansicht (Einzel-Mitarbeiter)
- Bei genau einem gewählten Mitarbeiter: Detailkalender je Monat mit **IST-Stunden und
  Differenz (+/-) zu Soll/Tag** je Tag, **Urlaub (geplant/genommen) je Monat** und
  **Gesamt-Urlaub (geplant)** unten. Größere Darstellung.
- IST + Differenz werden für **alle vergangenen Arbeitstage** angezeigt (nicht nur an
  Urlaubstagen); die **Soll-Arbeitszeit stammt aus der Benutzerverwaltung**
  (`sollstundenTag`/`arbeitstage` jetzt auch in `list_users`/`list_users_basic`).

### Neu – Monatliche Stundenauswertung per E-Mail
- Neuer Cron `cron_stundenauswertung_email.php`: sendet einmal im Monat eine **PDF-Übersicht
  der Mitarbeiterstunden** (Soll/Ist/Differenz, Urlaub, Krank) des Vormonats – alle
  Mitarbeiter gesammelt – an die konfigurierte Admin-Mail. Einstellung wie beim Backup
  (aktiv, Empfänger, Tag im Monat, Uhrzeit) inkl. „jetzt senden". PDF via Dompdf.

### Fixes
- **Archivierte/gelöschte Projekt-Stunden** (arbeitszeit-Positionen in der Baustelle)
  erscheinen nicht mehr als „Auffällige Buchungen" (ZE006), während die Zeitbuchung
  erhalten bleibt: die Sperrliste (`azImportBlocked`) wird nun auch im server-bewerteten
  Pfad berücksichtigt.

### Geprüft
- **Baustellen-Verlust:** Baustellen mit gebuchten Stunden sind gegen Löschen geschützt
  (mehrere Guards in saveAllData); kein stiller Datenverlust.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.88, `CACHE_VERSION` → `bk-es-v202`, `script.min.js?v=203`.
  `style.css` unverändert (v144).

---

## v2.10.87 – Dashboard-Anpassung, Archiv-Transfer, Auswertungs-Auswahl & Fixes

### Neu – Dashboard
- **Spalten ein-/ausblenden:** Aufgaben, Notizen, Erinnerungen und Termine lassen sich
  je Anwender über eine Konfig-Leiste oder das ✕ in der Spaltenkopfzeile ausblenden.
- **Spaltenbreite anpassbar:** Trenner zwischen den Spalten ziehen; Verhältnis wird
  pro Anwender gespeichert (Button „Breiten zurücksetzen"). Speicherung im Browser
  (`localStorage`, pro Anwender).
- **Termine-Widget:** heutige und kommende Termine direkt im Dashboard; Klick öffnet den
  Termin, „+ Neu" legt an.
- **Scrollfreies Layout:** Dashboard füllt den Bildschirm; überlange Listen scrollen
  innerhalb ihres Widgets statt der ganzen Seite.
- **Bugfix:** Klick auf eine verknüpfte Baustelle im Dashboard öffnete diese nicht
  (`showBaustelle` → `selectBaustelle`).

### Neu – Archiv
- **Download/Upload:** einzelne Archiv-Datei (JSON) oder das **komplette Archiv als ZIP**
  sichern und wieder hochladen (Zip-Slip-geschützt). Wichtig, da der Archiv-Ordner nicht
  Teil des Backups ist.
- **Archiv leeren** (`clear_archive`): löscht Archiv-Dateien, behält aber referenzierte
  Projekte als `archiviert=1` (keine verwaisten Stunden).
- **Verwaiste Stunden reparieren:** legt für Buchungen ohne Projekt einen archivierten
  Platzhalter an (nie als aktives Projekt) – nutzt den denormalisierten `baustelleName`.

### Neu – Auswertung
- **Projektauswahl je Anwender:** eigenständige Master-Auswahl (aktive + archivierte
  Projekte), unabhängig von `includeInAuswertung`, Jahresabschluss und Aktiv/Archiv-Filter.

### Neu – Wochenplanung / Kalender
- **Kalender-Abo mit Personen-Auswahl:** Nutzer mit „alle sehen" wählen vor dem Abo,
  welche Mitarbeiter im Feed enthalten sind (Spalte `cal_tokens.filter`).

### Neu – Sonstiges
- **App-Version in der Fußzeile** (dynamisch) statt hartem „v16".
- **Bedienungsanleitung** (`bedienungsanleitung.html`) über Button in der Fußzeile,
  druckbar und offline verfügbar.

### Fixes
- **Baustellen-Sichtbarkeit je Benutzer wurde nicht gespeichert:** Der Editor übergab
  wegen eines Template-Literal-Fehlers einen literalen Platzhalter statt des Benutzernamens
  – der Server fand den Benutzer nicht (404, stumm), jeder behielt „alle Baustellen".
  Der Callback nutzt jetzt die Closure-Variable; Fehler werden nun gemeldet.
- **`showToast` war nirgends definiert:** mehrere Fehler-/Erfolgsmeldungen (Dashboard,
  Sichtbarkeit) liefen in einen `ReferenceError`. Globaler Wrapper ergänzt (nutzt die
  Notification-Bar, färbt nach error/success).
- **Auswertung zeigte archiv=0 (500):** `load_auswertung_archives` griff ungesichert auf
  `$m['ek']` zu. Da api.php Warnungen zu Exceptions macht, kippte der Endpunkt bei
  Archiven mit Material ohne `ek`-Feld. Berechnung jetzt defensiv (`?? 0`, `is_array`).
- **Jahreswechsel-Assistent:** Quell-Jahr war fälschlich das Folgejahr (z. B. 2027 statt
  2026). Jetzt Quell-Jahr = laufendes Jahr.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.87, `CACHE_VERSION` → `bk-es-v200`, `script.min.js?v=201`.
  `style.css` unverändert (v144).

---

## v2.10.86 – Archiv-Reparatur: alte Projekte wieder auflösbar

### Problem
Vor v2.10.83 löschte das Archivieren die Baustellen-Zeile **hart**; erhalten blieb nur die
JSON-Datei im Archivverzeichnis. Die Diagnose las ausschließlich die `baustellen`-Tabelle
und meldete diese Projekte als „existiert nicht mehr“ – bei 430 Zeiteinträgen. Die Stunden
waren nie in Gefahr, aber der Projektbezug ließ sich nicht mehr anzeigen („#188“ statt Name)
und die Auswertung zeigte unnötige ZE005-Warnungen.

### Neu – `repair_archiv_projekte` (Admin/Master)
Einmalige Reparatur, die die Archiv-Dateien liest und fehlende Projekte als
**Soft-Delete-Zeilen mit ihrer Original-ID** wieder anlegt (`archiviert=1`).
- **Dry-Run per Default:** Ohne `?confirm=1` liefert der Endpunkt nur eine Vorschau –
  wie viele Projekte wiederherstellbar sind, welche davon Zeitbuchungen tragen und welche
  referenzierten IDs gar keine Archiv-Datei mehr haben.
- Wurde ein Projekt mehrfach archiviert, gewinnt das **jüngste** Archiv.
- Bereits vorhandene IDs werden **nie überschrieben** (Schutz vor ID-Wiederverwendung).
- Im Anschluss werden `zeiterfassung.baustelleName` nachgetragen und ZE005-Warnungen
  aufgelöst, sofern das Projekt wieder auflösbar ist.
- Idempotent: ein zweiter Lauf ändert nichts.
- Die wiederhergestellten Projekte bleiben in der App **ausgeblendet** (`archiviert=1`),
  sind aber für Auswertung, Reimport und Namensauflösung wieder verfügbar.

### Geändert – Kürzel-Doppelvergabe ist Warnung statt Sperre
- `add_user` und `set_kuerzel` lehnen ein doppeltes Kürzel nicht mehr ab, sondern melden
  es mit `needsConfirm` (HTTP 409). Der Administrator kann bewusst bestätigen.
- Hintergrund: Zwei Zeichen aus Initialen kollidieren zwangsläufig; eine harte Sperre
  würde künstliche Kürzel erzwingen. Seit v2.10.85 ist das Kürzel ohnehin nicht mehr
  identitätsstiftend – die Zuordnung läuft über `zeitUser`.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.86, `CACHE_VERSION` → `bk-es-v194`, `script.min.js?v=195`.
  `style.css` unverändert (v144).

---

## v2.10.85 – Kürzel-Kollision: aktiver Buchungsverlust behoben

### Problem (durch `diagnose_fehlbuchungen` aufgedeckt)
Zwei Benutzer teilten sich das Kürzel **„JR“** (Johannes Ruml / Josef Reisberger).
`reconcileAutoImport()` erkannte „eigene“ Projektbuchungen ausschließlich über
`erstelltVon === kuerzel`. Beim Speichern der Stunden löschte deshalb **jeder der beiden
die Projektbuchungen des anderen** auf gemeinsamen Projekten – fortlaufend, bei jedem
Speichervorgang. Das war kein Anzeigefehler, sondern echter Datenverlust im Projekt.

### Fix (src/Handlers/ZeiterfassungActions.php)
- **Zuordnung über `zeitUser` statt Kürzel:** Eine verknüpfte Position gehört eindeutig dem
  in `zeitUser` genannten Benutzer – unabhängig vom Kürzel. Damit sind auch Kürzel-Wechsel
  unkritisch (die Buchung folgt dem Benutzer, nicht dem Kürzel).
- **Legacy-Positionen ohne `zeitUser`** werden bei einem mehrfach vergebenen Kürzel
  **gar nicht mehr angefasst** – lieber eine verwaiste Position stehen lassen als die
  Buchung eines Kollegen löschen.
- **Zweiter Fund im selben Pfad:** `admin_delete_zeiterfassung` las die verbleibenden
  Einträge ohne `entryId`. Dadurch war die Soll-Menge der Verknüpfungen immer leer und
  **jedes Admin-Löschen entfernte sämtliche verknüpften Projektbuchungen** des Benutzers
  auf den betroffenen Projekten. Query um `entryId AS id` ergänzt.

### Neu (src/Handlers/UserActions.php) – Kürzel sind jetzt eindeutig
- `add_user` und `set_kuerzel` lehnen ein bereits vergebenes Kürzel mit HTTP 409 und
  Klartextmeldung ab (`code: kuerzel_duplicate`).
- Das Eingabefeld in der Benutzerverwaltung wird nach einer Ablehnung zurückgesetzt.

### Erweitert – `diagnose_fehlbuchungen`
- Neu `global.kuerzelKollisionen`: listet jedes mehrfach vergebene Kürzel mit den
  betroffenen Benutzern.
- Neu `users[].projektPositionen`: Gegenprobe aus Projektsicht – `mitZeitUser`,
  `mitKuerzel`, `linkVerwaist`, `fremderZeitUser`, `ohneLink`.
- Daraus abgeleitete **Handlungsempfehlung** je Benutzer: Sie unterscheidet, ob Buchungen
  tatsächlich fehlen („Alle reparieren“ ist sicher) oder ob sie existieren und nur die
  Verknüpfung kaputt ist (Reparatur würde **Dubletten** anlegen).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.85, `CACHE_VERSION` → `bk-es-v193`, `script.min.js?v=194`.
  `style.css` unverändert (v144).

---

## v2.10.84 – Zeitbuchungsart „Abwesend“ + Versionsanzeige

### Neu – Buchungsart „Abwesend“ (Desktop, Mobile, Mobile Light)
- Neuer Zeiterfassungs-Typ **Abwesend** mit **Stundeneingabe** und **Pflicht-Grund**
  (z. B. Arztbesuch, Behördengang).
- Verhält sich wie Gleitzeit: Die Stunden zählen **nicht** als Ist-Arbeitszeit und
  fließen weder in Monats-Ist, Gleitzeitsaldo noch in Projektbuchungen ein – sie werden
  aber dokumentiert und sind in Liste, Auswertung und PDF sichtbar.
- Einzeltag-Eintrag (kein Datumsbereich), mehrfach pro Tag erfassbar.
- Keine Baustellenzuordnung; die Buchung löst keinen Auto-Import aus.
- Auch gegen eine gleichnamige benutzerdefinierte Typ-Definition abgesichert:
  `abwesend` bleibt immer Fehlzeit.

### Neu – Versionsanzeige
- Die aktuelle Software-Version steht in der Fußzeile von Desktop, Mobile und Mobile Light.
- `check` liefert dazu das neue Feld `version` (aus `APP_VERSION`).

### Fix (scripts/check-versions.mjs)
- Das Prüfskript suchte noch nach `script.js?v=`, ausgeliefert wird seit v2.10.70 aber
  `script.min.js`. Dadurch meldete jeder Lauf die Falschwarnung „keine ?v= in index.html
  gefunden“. Asset-Liste korrigiert.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.84, `CACHE_VERSION` → `bk-es-v192`, `script.min.js?v=193`.
  `style.css` unverändert (v144).

---

## v2.10.83 – Zeitbuchung gehärtet: Fehlbuchungs-Katalog, Soft-Delete, Sync-Schutz

### Problem
Bei einzelnen Nutzern wurden **alle** Buchungen pauschal als „Fehlbuchung" gemeldet.
Ursachenanalyse ergab drei unabhängige Auslöser, die jeweils schlagartig den gesamten
Bestand eines Mitarbeiters kippen konnten.

### Root Causes
- **RC-1 (Hauptursache):** Der Fehlbuchungs-Check prüfte für jeden Arbeitseintrag, ob eine
  Auto-Import-Position im Projekt existiert – **ohne** zu prüfen, ob `auto_import_stunden`
  überhaupt aktiv ist. Bei deaktiviertem Auto-Import kann diese Position gar nicht existieren
  → **100 % Fehlbuchungen**.
- **RC-2:** Legacy-Positionen wurden über `erstelltVon === kuerzel` zugeordnet. Ein geändertes
  oder leeres Benutzer-Kürzel ließ **keinen einzigen** Altbestand mehr matchen.
- **RC-3:** `archive_baustelle` löschte die Projektzeile hart (`DELETE FROM baustellen`);
  `zeiterfassung.baustelleId` zeigte danach ins Leere. `reimport_baustelle` vergab zusätzlich
  eine **neue** ID, sodass die Verknüpfung auch nach Wiederherstellung tot blieb.
- **RC-4:** Full-Replace + `reconcileAutoImport` konnte bei unvollständigem Payload alle
  Projektbuchungen eines Mitarbeiters entfernen.
- **RC-5:** `entryId` wurde clientseitig als `max(id)+1` vergeben, ohne UNIQUE-Constraint →
  Kollisionen zwischen Geräten, `zeitEntryId`-Links zeigten auf falsche Einträge.
- **RC-6:** Die Offline-Queue legte einen kompletten Full-Replace-Snapshot ab und spielte ihn
  beim `online`-Event ungeprüft ein – zwischenzeitliche Buchungen gingen verloren.

### Neu (src/Services/BuchungValidator.php) – zentraler Fehlbuchungs-Katalog
- Einzige Wahrheit dafür, wann eine Buchung als Fehlbuchung gilt. Ersetzt die zuvor
  dreifach implementierte, auseinandergedriftete Regel (script.js, mobile, PHP).
- 12 Fehlercodes **ZE001–ZE012** mit `severity` (`blocker` = Speichern abgelehnt,
  `warning` = gespeichert und markiert) und einer verständlichen `user_friendly_message`.
- **ZE006** (fehlende Projektbuchung) wird nur noch vergeben, wenn der Auto-Import aktiv,
  das Projekt vorhanden und nicht archiviert und der Schlüssel nicht gesperrt ist → RC-1 behoben.
- Der Kürzel-Fuzzy-Abgleich ist nur noch letzter Fallback und toleriert abweichende
  Kürzel → RC-2 behoben.

### Neu – Entkopplung Arbeitszeit ↔ Projekt-Status (Soft-Delete)
- `baustellen` erhält `archiviert`, `archivFile`, `archiviertAm`, `archiviertVon`.
  Archivieren setzt nur noch das Flag; die Zeile (und damit die Zuordnung) bleibt erhalten.
- `reimport_baustelle` reaktiviert die **bestehende ID** statt eine neue zu vergeben –
  alle Zeitbuchungs-Verknüpfungen bleiben intakt.
- `zeiterfassung.baustelleName` wird denormalisiert mitgeschrieben: die Zuordnung überlebt
  auch ein endgültiges Löschen des Projekts.
- **Garantie:** Archivieren oder Löschen eines Projekts bzw. einer Arbeitszeit-Position
  ändert nie den Status des Zeiteintrags – die erbrachte Leistung bleibt `booked_valid`.
- `delete_archive` entfernt die Projektzeile nur, wenn keine Zeitbuchung mehr darauf verweist.

### Neu – Datenintegrität & Synchronisation
- `zeiterfassung` erhält `clientUuid` (geräteübergreifend stabile Identität, UNIQUE je
  Benutzer), `status`, `errorCode`, `errorMessage`, `createdAt`, `updatedAt`, `quelle`.
  Alle drei Clients erzeugen die UUID via `crypto.randomUUID()` → RC-5 behoben.
- **Optimistic Locking** pro Benutzer (`zeiterfassung_meta.rev`): Hat ein anderes Gerät
  zwischenzeitlich gespeichert, antwortet der Server mit `409 needsMerge` und überschreibt
  nichts. Die Clients laden den aktuellen Stand nach.
- Die Offline-Queue führt einen veralteten Snapshot per `clientUuid` mit dem Serverstand
  **zusammen**, statt ihn zu ersetzen → RC-6 behoben.
- `reconcileAutoImport` räumt nur noch auf, wenn der Client den Payload als vollständig
  deklariert (`fullSnapshot`) → RC-4 behoben.
- Archivierte Projekte werden weder neu bebucht noch nachträglich bereinigt.
- `INSERT OR REPLACE` auf `baustellen` durch UPSERT ersetzt, damit die Archiv-Spalten
  beim Speichern nicht zurückgesetzt werden.

### Neu – Historisierung von Projektumbuchungen
- Jeder Projektwechsel schreibt eine `move`-Zeile nach `zeiterfassung_log` mit altem und
  neuem Projektbezug (ID + Name), Zeitstempel und Bearbeiter – aus `save_zeiterfassung`,
  `admin_edit_zeiterfassung` und `move_position`.
- Der Audit-Trail für Umbuchungen wird **immer** geschrieben, nicht mehr nur bei aktiver
  „erweiterter Zeiterfassung".
- Neuer Endpunkt `get_zeiterfassung_historie` (eigene Historie immer, fremde nur mit
  Auswertungs-Recht).

### Neu – Diagnose
- Endpunkt `diagnose_fehlbuchungen` (Admin/Master, read-only): beantwortet je Eintrag,
  *warum* er als Fehlbuchung gilt, und weist Auto-Import-Status, Kürzel-Abweichungen,
  gelöschte Projekte und doppelte `entryId`s je Benutzer aus.

### Geändert (Anzeige)
- Die Fehlbuchungs-Box nennt jetzt **Fehlercode + Klartext** je Zeile; der Reparatur-Button
  erscheint nur bei tatsächlich behebbaren Fällen (ZE006).
- Der Client rechnet den Status nicht mehr selbst nach, sondern zeigt den serverseitig
  persistierten Befund an (Legacy-Fallback bleibt für Altdaten erhalten).

### Migration
- Rein additiv (`ALTER TABLE ADD COLUMN` + `CREATE INDEX`), idempotent, ohne Datenverlust.
  Bestandszeilen werden mit `clientUuid`, `status='booked_valid'` und `baustelleName`
  nachgefüllt. Alte Clients ohne `clientUuid`/`baseZeitRev` laufen unverändert weiter
  (Full-Replace-Pfad inkl. Shrink-Guard).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.83, `CACHE_VERSION` → `bk-es-v191`, `script.min.js?v=192`.
  `style.css` unverändert (v144).

---

## v2.10.82 – Datei-Upload robust bei langsamer Verbindung (Fernzugriff)

- APP_VERSION 2.10.81 → 2.10.82 (api.php, manifest.json)
- CACHE_VERSION bk-es-v189 → bk-es-v190 (sw.js)

### Problem
- Datei-/Foto-Upload (Mobile) schlug bei geringer Bandbreite über Fernzugriff (Cloudflare-Tunnel)
  fehl, funktionierte aber im lokalen WLAN. Ursachen: PHP `max_input_time` (Default 60 s, nicht
  gesetzt) bricht das Einlesen großer Uploads bei langsamem Uplink ab; zusätzlich greift der
  100-s-Edge-Timeout des Cloudflare-Tunnels. Handy-Fotos (5–12 MB) reißen diese Grenzen leicht.

### Fix
- **Dockerfile.app**: `max_input_time = 300` in der PHP-`custom.ini` ergänzt (erfordert Image-Rebuild).
- **mobile.html – `compressImageForUpload()`**: Bilder werden vor dem Upload clientseitig auf
  max. 1600 px / JPEG q0.82 verkleinert (nur Rasterbilder > 1 MB; HEIC/unlesbar → Original;
  kein Upscaling; wird das Ergebnis nicht kleiner, bleibt das Original). Spart Übertragungszeit
  und umgeht die Timeouts – wirkt auch ohne Server-Rebuild.
- **mobile.html – `uploadFilesMobile()`**: Erfolg/Fehler werden jetzt pro Datei über `r.ok`/`j.ok`
  ausgewertet; Erfolgsmeldung nur bei tatsächlichem Erfolg (vorher wurde nach Fehlern trotzdem
  „✓ Hochgeladen" angezeigt). Klare Meldung bei langsamer Verbindung.
- Änderungen auch in `deploy/` gespiegelt.

---

## v2.10.81 – Globale Suche intuitiver · Archiv read-only ansehen · Auswertungs-Filter

- APP_VERSION 2.10.80 → 2.10.81 (api.php, manifest.json)
- CACHE_VERSION bk-es-v188 → bk-es-v189 (sw.js)
- style.css?v=143 → v144 · script.min.js?v=190 → v191 (index.html, sw.js)

### §F: Globale Suche – intuitiver (index.html, script.js, style.css)
- Filter-Checkboxen zu **Chip-Toggles** umgebaut: jeder Chip zeigt nach der Suche die
  Treffer-Anzahl in Klammern an; inaktive Chips sind ausgegraut (`.gs-chip` + `.gs-chip-active`
  in style.css). Neue Buttons **„Alle"** / **„Keine"** zum Schnell-Ein-/Ausschalten aller Kategorien.
- **Tastatur-Navigation**: ArrowDown ab Suchfeld in erste Ergebniszeile, ArrowUp/Down durch
  Ergebnisse, Enter öffnet den markierten Treffer.
- **Relevanz-Sortierung**: Exakter Treffer = Score 10, Präfix-Treffer = 6, Enthält = 3.
  Gruppen und Einzeltreffer nach Score absteigend sortiert.
- **Multi-Token-Highlight**: `_gsHighlight` akzeptiert jetzt ein Tokens-Array statt einzelnem
  String – alle Suchbegriffe werden separat markiert.
- Archiv-Treffer im Suchergebnis erhalten zusätzlich den Button **„Ansehen"** (neben Importieren).
- Titelzeile des Modals: „Suche über alle Projekte" → „Suche über alle Datensätze".

### §F: Archiv – Read-Only Ansicht (BaustelleActions.php, api.php, index.html, script.js)
- **Backend**: Neue Aktion `view_archive` (`BaustelleActions::viewArchive()`) – liest
  Snapshot-JSON (ARCHIVE_DIR), gibt `baustelle`, `pauschalen`, `archivedAt`, `archivedBy`
  zurück. Auth: admin/master. Dateiname-Sanitizing identisch zu `reimport`.
- Route `view_archive` in api.php ergänzt.
- **Frontend**: Neues Modal `#archiveViewModal` in index.html. Im Archiv-Modal
  `renderArchivList` um „Ansehen"-Button (ico eye) erweitert.  
  `viewArchive(file)` lädt Snapshot, `_renderArchiveView(j)` zeigt Material/Arbeitszeit/
  Pauschalen/Abschläge/Bautagebuch in aufklappbaren `<details>`-Sektionen mit Summenbox.
  `closeArchiveViewModal()` schließt das Fenster. Kein Reimport wird ausgelöst.
- Archiv-Modaltext aktualisiert (erwähnt „ansehen").

### §F: Auswertung – Filter-Panel (BaustelleActions.php, script.js)
- **Backend** `loadAuswertungArchives`: Gibt jetzt `kundeId` ($b['kundeId']) mit zurück.
- **Frontend**: Neues Zustandsobjekt `awFilter = { quelleAktiv, quelleArchiv, jahr, kundeId }`
  (Default = heutiger Stand, d. h. aktive + aktuell sichtbare Archive wie bisher).
- `_awReadFilter()` liest Filter-Dropdowns aus dem DOM in `awFilter`.
- `resetAwFilter()` setzt auf Default zurück und löst `renderAuswertung()` aus.
- `renderAuswertung` baut Projekt-Listen gefiltert:  
  – Aktive Projekte: Quelle- und Kunden-Filter.  
  – Archivierte: Quelle-, Jahr- (nach `archivedAt`) und Kunden-Filter; Jahreswechsel-
  Ausblendung (`awArchivSichtbar`/`awShowArchivClosed`) bleibt erhalten.
- `renderAuswertungProjekte` erhält neue Parameter `sortedYears`, `kundeOpts` und rendert
  ein Always-Visible Filter-Panel (Checkboxen Aktiv/Archiv, Jahr-Dropdown, Kunden-Dropdown,
  Reset-Button – nur sichtbar wenn vom Default abgewichen).
- `awGetAllProjekte()` (CSV/Print) respektiert denselben `awFilter`.

## v2.10.80 – Datenverlust-Schutz Wochenplanung

### Ursache
- `save_wochenplanung` ersetzt den kompletten Plan (Admin: `DELETE FROM wochenplanung`
  für ALLE Mitarbeiter) ohne Optimistic-Locking oder Leer-/Schrumpf-Schutz. Ein
  veralteter/leerer Client-Stand hätte die gesamte Wochenplanung überschreiben können.

### Fix
- **Server (PlanActions.php):** Schrumpf-Schutz – würde ein Speichervorgang viele
  Einträge löschen (0 bei ≥3 vorhandenen, oder ≥5 Löschungen und weniger als die Hälfte),
  wird mit `needsConfirm` (409) abgelehnt statt gelöscht (scope-abhängig: Admin = alle,
  sonst nur eigene). `forceReplace` als bewusste Bestätigung.
- **Client (script.js):** `_wpLoaded`-Flag – Speichern nur nach erfolgreichem Laden des
  Plans; bei `needsConfirm` Rückfrage und erneutes Senden nur nach Bestätigung.
  Offline-Speichern (Queue) bleibt unverändert erhalten.

## v2.10.79 – Datenverlust-Schutz Zeiterfassung: Parität für mobile & mobile_light

- **mobile.html / mobile_light.html:** Analog zum Desktop wird das Speichern der
  Stundenerfassung jetzt blockiert, wenn die Erfassung **online, aber nicht erfolgreich
  geladen** wurde (`zeitLoaded` / `lightZeitLoaded`). So kann ein fehlgeschlagenes Laden
  nicht mehr dazu führen, dass eine Buchung den Server-Stand mit veralteten/leeren Daten
  überschreibt. 401 beim Laden führt sauber zum Login.
- **Keine Einschränkung im Normalbetrieb:** Nach erfolgreichem Laden funktioniert Buchen,
  Bearbeiten und Löschen unverändert. **Offline-Buchen bleibt vollständig erhalten**
  (Offline-Queue), da der Schutz nur im Online-Fall greift.

## v2.10.78 – Datenverlust-Schutz Zeiterfassung

### Ursache
- `save_zeiterfassung` ersetzt ALLE Stunden eines Users (DELETE + Neu-Insert), ohne
  Optimistic-Locking oder Leer-/Schrumpf-Schutz. Ein veralteter/leerer Client-Stand
  (fehlgeschlagenes Laden der Erfassung, zweites Gerät/Tab) konnte so die komplette
  Stundenerfassung überschreiben bzw. löschen.

### Fix
- **Client (script.js):** Neues `_zeitLoaded`-Flag – Speichern/Löschen in der
  Stundenerfassung ist gesperrt, solange die Einträge nicht erfolgreich geladen wurden
  (mit klarer Meldung). 401 beim Laden führt sauber zum Login.
- **Server (ZeiterfassungActions.php):** Schrumpf-Schutz – würde ein Speichervorgang
  viele Einträge löschen (0 bei ≥3 vorhandenen, oder ≥5 Löschungen und weniger als die
  Hälfte), wird mit `needsConfirm` (409) abgelehnt statt gelöscht. Der Client fragt dann
  nach und sendet nur nach Bestätigung mit `forceReplace` erneut.

### Bekannt / noch offen
- Vollständiger Schutz bei paralleler Erfassung auf zwei Geräten erfordert die Umstellung
  von „Full-Replace" auf id-basiertes Delta-Speichern (separater Schritt).

## v2.10.77 – KRITISCH: Abschlagsrechnungen gingen bei Parallel-Bearbeitung verloren

### Ursache
- Abschlagsrechnungen liegen im Baustellen-Datensatz. Der Konflikt-/Merge-Mechanismus
  arbeitete auf **ganzer-Baustellen-Ebene**: Bearbeiteten zwei Geräte dieselbe Baustelle
  in unterschiedlichen Sektionen (z.B. Abschlag vs. Material), entstand ein Konflikt, und
  „Meine Version erzwingen" übernahm den kompletten lokalen Block – die fremde
  Abschlagsrechnung ging dabei **still verloren**.

### Fix (Client, script.js)
- Merge jetzt **sektionsweise innerhalb einer Baustelle** (per Element-id):
  `abschlaege`, `material`, `arbeitszeit`, `pauschalen`, `fehlendesMaterial`, `bautagebuch`.
  Disjunkte Sektions-Änderungen derselben Baustelle werden **immer zusammengeführt** –
  eine gleichzeitig erfasste Abschlagsrechnung überlebt konkurrierende Bearbeitungen.
- Nur noch dasselbe Element von beiden Seiten geändert gilt als echter Konflikt; der
  Konflikt-Dialog benennt die betroffene Sektion (z.B. „Baustelle X – Abschlagsrechnungen").

### Sicherheitsnetz (Server, DataService.php / DataActions.php)
- Entfernt ein Speichervorgang vorhandene Abschlagsrechnungen aus der DB, wird das
  **nicht-blockierend im Audit-Log** dokumentiert (`abschlag_removed`) – inklusive der
  entfernten Daten (Bezeichnung, Betrag, Art), sodass jeder Verlust nachvollziehbar und
  wiederherstellbar ist. Legitime Löschungen bleiben möglich.

## v2.10.76 – Fix: PDF-Vorschau „Unexpected server response (0)"

- **Problem:** PDF.js konnte die als **Blob-URL** übergebene Datei im Worker nicht
  laden (`Unexpected server response (0)`).
- **Fix:** Die PDF-Datei wird jetzt als **Rohbytes** (`file.arrayBuffer()` →
  `getDocument({ data })`) an PDF.js übergeben – kein Fetch der Blob-URL mehr nötig.

## v2.10.75 – KI-Scan PDF-Vorschau via PDF.js (Edge-Blockade behoben)

- **Problem:** Edge/andere Browser zeigten die eingebettete PDF-Vorschau nicht an
  („Dieser Inhalt ist blockiert"); native `<object>`/`<iframe>`-Einbettung von PDFs
  ist unzuverlässig (Browser-/PWA-/Mobile-Restriktionen).
- **Fix:** PDFs werden jetzt mit **PDF.js** (lokal gebündelt unter `lib/pdfjs/`,
  offline-fähig) direkt auf Canvas gerendert – alle Seiten scrollbar in der linken
  Spalte. Kein Browser-PDF-Plugin mehr nötig. PDF.js wird erst bei Bedarf nachgeladen.
  Bilder werden weiterhin direkt als `<img>` angezeigt.

## v2.10.74 – Fix: KI-Scan Dokument-Vorschau wurde nicht angezeigt

- **Problem:** Die linke Dokument-Spalte blieb leer (PDF getestet). Ursache: Das
  Vorschau-`<iframe>`/`<img>` nutzte `height:100%`, was gegenüber dem per Flex
  gestreckten Eltern-Element keine auflösbare Höhe ergab → Höhe 0, nichts sichtbar.
- **Fix:** Die Dokument-Spalte ist jetzt ein Flex-Container (Spalte); die Vorschau
  füllt sie über `flex:1`. PDFs werden über `<object>` (mit `<iframe>`-Fallback)
  eingebettet, Bilder scrollbar dargestellt. Zusätzlich eine kleine Kopfzeile mit
  Dateiname und **„↗ In neuem Tab öffnen"** als Fallback.

## v2.10.73 – KI-Scan: Split-Ansicht + Briefkopf → Kundenstamm

### Neu – Dokument-Vorschau neben den erkannten Daten
- Beide KI-Scan-Ergebnisdialoge (projektbezogen **und** global) öffnen jetzt in
  **Vollbildansicht** mit zweispaltigem Layout: **links** das gescannte Dokument/Foto
  (Bild oder eingebettetes PDF), **rechts** die erkannten Positionen – so ist ein direkter
  Abgleich beim Prüfen/Korrigieren möglich.
- Die Vorschau nutzt die hochgeladene Datei direkt (Object-URL), die beim Schließen wieder
  freigegeben wird.

### Neu – Briefkopf/Absender optional in den Kundenstamm (src/Handlers/AiActions.php)
- Die KI extrahiert zusätzlich die **Absender-/Briefkopf-Adresse** des Belegs
  (Firma, Ansprechpartner, Straße, PLZ, Ort, Telefon, E-Mail).
- Im Ergebnisdialog erscheint – nur wenn etwas erkannt wurde – ein aufklappbarer Block
  mit editierbaren Feldern und dem Button **„In Kundenstamm übernehmen"**. Ein Kunde/
  Lieferant wird ausschließlich **auf ausdrücklichen Klick** angelegt (`save_kunde`).
- Hinweis: Die Backend-Erweiterung (Gemini-Prompt) wird erst nach einem Image-Rebuild
  (`docker compose up -d --build`) wirksam.



### Fix (src/DataService.php) – Optimistic-Locking-Lücke geschlossen
- **Problem:** Ein Gerät mit veraltetem Browser-Cache (Service-Worker-Cache oder localStorage)
  konnte beim Zugriff auf die App die **gesamte Datenbank mit alten Daten überschreiben**.
  Ursache: Wurde `baseRev` nicht mitgesendet (= `null`), wurde der Optimistic-Locking-Check
  **komplett übersprungen** → uralte Daten überschrieben den aktuellen Stand.
- **Fix:** `saveAllData()` lehnt jetzt Speichervorgänge **ohne `baseRev`** ab, wenn die DB
  bereits Daten enthält (`curRev > 0`). Nur bei erstmaliger Einrichtung (leere DB) oder
  explizitem `force` wird ohne Rev geschrieben. Veraltete Clients erhalten einen
  409-Konflikt und können die DB nicht mehr zerstören.

### Fix (script.js) – syncOfflineData() sendet jetzt baseRev
- **Problem:** `syncOfflineData()` (Offline→Online-Sync) sendete die gespeicherten Daten
  **ohne** `baseRev` → umging den Locking-Check komplett.
- **Fix:** Die Funktion sendet jetzt `appDataRev` mit. Bei Konflikt-Antwort (Offline-Daten
  veraltet) wird statt blindem Überschreiben der **frische Server-Stand geladen** und dem
  Nutzer eine Warnung angezeigt.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.71, `CACHE_VERSION` → `bk-es-v179`, `script.min.js?v=182`.

## v2.10.70 – Performance: script.js minifiziert (−25%, −271 KB)

### Neu (script.min.js, index.html, sw.js)
- **Frontend-Performance:** `script.js` (1079 KB) wird jetzt über **Terser** minifiziert
  als `script.min.js` (808 KB) ausgeliefert — **25% kleiner**, schnellerer Parse/Compile
  auf Mobilgeräten und im LAN. Die Entwickler-Version `script.js` bleibt unverändert erhalten.
- `index.html` und `sw.js` (PRECACHE) referenzieren jetzt `script.min.js?v=181`.
- PHP auf **8.3** angehoben (Dockerfile), `composer.json` PHP-Constraint `>=8.2`.
- `dompdf` auf `^3.0` (Security-Advisory-Fix für den Build).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.70, `CACHE_VERSION` → `bk-es-v178`, `script.min.js?v=181`.

## v2.10.69 – Fix: Session-Abmeldung nach ~15 Min statt eingestellter Zeit

### Fix (Dockerfile.app)
- **Ursache:** PHP's `session.gc_maxlifetime` war nicht gesetzt → Standard **1440 Sekunden
  (24 Minuten)**. Der PHP-Garbage-Collector löschte die Session-Datei nach diesem Intervall,
  **unabhängig** vom konfigurierten `session_timeout_minutes`. Bei normalem Traffic (regelmäßige
  GC-Läufe) wurde die Session schon nach ~15 Minuten Inaktivität zerstört.
- **Fix:** `session.gc_maxlifetime = 2592000` (30 Tage) in der PHP-Config gesetzt — analog zur
  Cookie-Lifetime. Die **tatsächliche** Abmeldung steuert weiterhin unsere eigene Logik
  (`_last_activity` + `session_timeout_minutes` in api.php).
- **Wirksam erst nach Image-Rebuild** (`docker compose up -d --build`).

## v2.10.68 – Fix: Automatische Abmeldung funktioniert jetzt zuverlässig

### Fix (script.js, api.php)
- **Problem:** Die automatische Abmeldung nach eingestellter Inaktivitätszeit griff nicht,
  obwohl der Timer korrekt konfiguriert war. Zwei Ursachen identifiziert:
  1. **Client:** `scroll` war in der Event-Liste für den Idle-Reset. Programmatische
     Scroll-Events (z.B. durch Wochenplanungs-Poll-Render) setzten den Timer permanent
     zurück → Logout nie erreicht. Fix: `scroll` entfernt; nur echte User-Events
     (`mousemove`, `mousedown`, `keydown`, `touchstart`, `click`, `wheel`) zählen;
     zusätzlich `e.isTrusted`-Prüfung gegen synthetische Events.
  2. **Server:** Der Wochenplanungs-Poll (alle 15 s, bei geöffnetem Modal) sendete
     reguläre API-Requests → `$_SESSION['_last_activity']` wurde permanent aktualisiert
     → Server-Timeout nie überschritten. Fix: Poll-Requests tragen jetzt `&_idle=1`;
     der Server aktualisiert `_last_activity` bei solchen Requests **nicht** mehr.
- **Ergebnis:** Lässt der Nutzer den Browser ohne Interaktion offen (auch mit
  geöffnetem Wochenplanungs-Modal), wird er nach der eingestellten Zeit (Standard 8 h)
  sowohl client- als auch serverseitig abgemeldet.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.68, `CACHE_VERSION` → `bk-es-v178`, `script.js?v=181`.
  `style.css` unverändert (v143).

## v2.10.67 – Wochenplanung: Jahresansicht (Urlaubsplanung/Abwesenheiten)

### Neu (script.js)
- **Neuer Tab „📆 Jahr"** in der Wochenplanung (neben Woche/Tag). Zeigt eine kompakte
  **12-Monats-Übersicht** (Mini-Monatsblöcke, farbcodierte Zellen) mit allen Abwesenheiten:
  Urlaub (blau), Krank (rot), Gleitzeit (türkis), Sonstiger Fehlgrund (orange), Schulung (lila),
  Feiertage (grau/gold), Wochenenden (abgedunkelt). Heutiges Datum hervorgehoben.
- **Datenquellen:** Zeiterfassung (gebuchte Abwesenheiten) + Wochenplanung (geplante Einträge,
  per Checkbox zuschaltbar/abwählbar).
- **Personen-Filter:** Checkbox-Liste aller sichtbaren Mitarbeiter + „Alle"/„Keine"-Buttons.
  Filter-Zustand bleibt für die Dauer der Session erhalten.
- **Direkte Buchung:** Klick auf einen Tag → Dialog mit Person, Typ, optionalem Zeitraum
  (von–bis für Mehrfachtage). Speichert über `admin_add_zeiterfassung` (bestehender Endpunkt).
  Vorhandene Einträge am Tag werden angezeigt und können direkt gelöscht werden.
- **Jahres-Navigation:** Vor-/Zurück-Buttons + „Heute"-Sprung.
- Bayerische + betriebliche Feiertage (aus Admin-Einstellungen) werden global markiert.
- Kein neuer Backend-Endpoint nötig (alle Daten/Aktionen bereits vorhanden).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.67, `CACHE_VERSION` → `bk-es-v177`, `script.js?v=180`.
  `style.css` unverändert (v143).

## v2.10.66 – Katalog-Lösch-Guard + reconcile-Performance

### Fix (src/DataService.php) – Massen-Lösch-Guard für Kataloge
- **Analog zum Baustellen-Guard:** Pauschalen-, Stundenkatalog- und Materialkatalog-Einträge
  werden nur noch gelöscht+neu geschrieben, wenn der Payload **nicht leer** ist (oder die DB
  ohnehin leer war). Ein leerer/fehlerhafter Payload durch einen Client-Glitch kann damit keine
  Katalog-Daten mehr unwiderruflich löschen.

### Performance (src/Handlers/ZeiterfassungActions.php) – reconcileAutoImport
- `reconcileAutoImport` liest jetzt **nur noch die betroffenen Baustellen** (IDs aus den
  Zeiteinträgen), statt alle Projekte zu laden. Bei vielen Projekten (100+) deutlich schneller;
  bei 5 Arbeitszeit-Einträgen auf 3 Projekte: 3 SELECTs statt vorher 100+.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.66 (rein serverseitige Änderung, kein Frontend-Update nötig).

## v2.10.65 – Stundenverlust-Fix, Reparatur-Sperrliste, Gesamtstunden + Unproduktiv

### Fix (src/DataService.php) – Stundenverlust bei neuen Projekten
- **Kritisch:** Beim Speichern durch parallele Clients (Mehrbenutzerbetrieb) konnten frisch
  angelegte Baustellen gelöscht werden, weil ein zweiter Client sie noch nicht im Payload hatte
  (Race-Condition). Die Zeiterfassungs-Buchungen verwiesen dann auf eine nicht mehr existierende
  `baustelleId` → „Stunden am nächsten Tag weg".
  **Fix:** `saveAllData` darf Baustellen, die noch in `zeiterfassung.baustelleId` referenziert
  werden, **nicht mehr löschen** (neue Prüfung vor `DELETE`). Reparatur bucht damit auch nicht
  mehr auf falsche Projekte, da die Original-Baustelle erhalten bleibt.

### Fix (script.js) – Reparaturfunktion respektiert Sperrliste
- `_computeFehlbuchungen` prüft jetzt die **`azImportBlocked`-Sperrliste** der Baustelle.
  Archivierte oder gelöschte Positionen werden nicht mehr als Fehlbuchung gemeldet und nicht
  mehr zur Reparatur vorgeschlagen.

### Neu (script.js) – Gesamtstunden pro Projekt + „Unproduktiv"-Checkbox
- **Gesamtstunden-Anzeige:** Die Arbeitszeit-Sektion zeigt in der Summenzeile die
  **Gesamtstunden** und die **produktiven Stunden** (Stunden ohne unproduktive Markierung).
  Auch im eingeklappten Header-Badge sichtbar.
- **Checkbox „Unproduktiv":** Jede Arbeitszeit-Position hat eine optionale Checkbox „Unp.".
  Markierte Stunden zählen **nicht** in die produktiven Projekt-Gesamtstunden, bleiben aber für
  die €-Berechnung und Auswertungen erhalten (Daten werden nie gelöscht, nur gekennzeichnet).
  Neues Feld `unproduktiv` (bool) im Baustellen-JSON.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.65, `CACHE_VERSION` → `bk-es-v176`, `script.js?v=179`.
  `style.css` unverändert (v143).

## v2.10.64 – Einzelpositionen archivieren (Material/Arbeitszeit/Pauschalen)

### Neu (script.js)
- **Einzelne Positionen archivierbar:** Material-, Arbeitszeit- und Pauschalen-Positionen haben
  jetzt zusätzlich zum Löschen einen **Archivieren-Button** (📦). Archivierte Positionen werden
  aus den aktiven Listen entfernt (fließen nicht mehr in Summen/Export/PDF) und im neuen
  einklappbaren Abschnitt **„Archivierte Positionen"** je Projekt/Unterprojekt geführt –
  mit **Wiederherstellen** und **endgültig Löschen** (Rechte je Positionsart).
- Bearbeiten der Positionen bleibt wie gehabt inline möglich.

### Neu (script.js, ZeiterfassungActions.php)
- **Keine Neubuchung archivierter/gelöschter Arbeitszeit:** Wird eine aus der Zeiterfassung
  **auto-importierte** Arbeitszeit-Position archiviert oder gelöscht, wird ihr Buchungsschlüssel
  in einer Sperrliste (`azImportBlocked`) der Baustelle vermerkt. Der serverseitige Auto-Import
  (`ensureProjectBooking`) legt gesperrte Buchungen **nicht erneut** an – der Zeiteintrag bleibt
  in der Zeiterfassung erhalten, erscheint im Projekt aber nur noch im Archiv (bzw. gar nicht,
  wenn gelöscht). Beim Wiederherstellen wird die Sperre aufgehoben.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.64, `CACHE_VERSION` → `bk-es-v175`, `script.js?v=178`.
  `style.css` unverändert (v143).

## v2.10.63 – Datenverlust-Fix: neue Kunden/Projekte überleben Container-Neustart

### Fix (src/Database.php, docker-entrypoint.sh, docker-compose*.yml)
- **Problem:** Neu angelegte Kunden/Projekte waren am nächsten Tag teilweise verschwunden.
  Ursache: SQLite lief mit `synchronous=NORMAL` im WAL-Modus; zuletzt gespeicherte Änderungen
  lagen nur im WAL und wurden bei einem (Auto-)Container-Neustart (autoheal, `restart:
  unless-stopped`, Deploy) **nicht** in die Haupt-DB übernommen → Verlust.
- **`synchronous=FULL`** (Database.php): jedes Commit wird sofort dauerhaft aufs Volume
  geschrieben (fsync) – überlebt auch harte Abbrüche.
- **Graceful Shutdown im Entrypoint:** Bei Stop-Signal (SIGTERM/INT) wird jetzt
  `PRAGMA wal_checkpoint(TRUNCATE)` ausgeführt (WAL wird in die Haupt-DB gefaltet) und eine
  Sicherheitskopie `data/backups/pre_stop.sqlite` abgelegt; beim Start wird ein offenes WAL
  eines vorherigen unsauberen Laufs eingefaltet. Apache läuft dafür im Hintergrund, damit der
  Handler zuverlässig greift.
- **`stop_grace_period: 30s`** (docker-compose.yml + minimal): gibt dem Checkpoint genügend
  Zeit vor dem harten Beenden.
- **Wichtig:** Wirksam erst nach **Image-Neubau** (`docker compose up -d --build`), da
  Entrypoint und PHP-Dateien ins Image gebacken werden. Vorher wie gehabt WAL-Checkpoint +
  Backup manuell ausführen.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.63, `CACHE_VERSION` → `bk-es-v174` (Frontend unverändert,
  `script.js?v=177`, `style.css` v143).

## v2.10.62 – Auto-Abmeldung (Client) & neueste Buchungen oben

### Neu (script.js)
- **Aktive automatische Abmeldung nach Inaktivität (Desktop):** Ergänzend zur serverseitigen
  Prüfung meldet ein Client-Timer den Nutzer jetzt **ohne Interaktion** nach der eingestellten
  Zeit (`session_timeout_minutes`, Admin → Allgemein) aktiv ab. Der Timer wird bei echter
  Aktivität (Maus, Tastatur, Touch, Scroll, Klick) zurückgesetzt (gedrosselt), Standard 8 h.
  Mobile/Mobile-Light bleiben wie bisher serverseitig abgesichert.

### Fix (script.js, mobile.html)
- **Neueste Buchungen stehen oben:** In der **Stundenauswertung Desktop** und **Stundenauswertung
  Mobile** werden die Einträge jetzt absteigend nach Datum (neuestes zuerst, Tiebreak nach ID)
  sortiert. Desktop-/Mobile-Stundenerfassung und mobile_light waren bereits so.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.62, `CACHE_VERSION` → `bk-es-v173`, `script.js?v=177`.
  `style.css` unverändert (v143).

## v2.10.61 – Stundenauswertung: Fehlbuchungen & archivierte Projekte

### Fix (script.js, BaustelleActions.php)
- **Mobile-/Mobile-Light-Buchungen erschienen fälschlich als Fehlbuchung:** Beim Öffnen der
  Stundenauswertung wurden Zeiterfassung und Prüfungen neu geladen, **nicht** aber die
  Projektdaten. Nach einer Buchung per Mobile (serverseitiger Auto-Import der Projektbuchung)
  war die lokale Baustellen-Kopie des Admins veraltet → der Fehlbuchungs-Check fand die
  Auto-Import-Position nicht. **`openStundenauswertungModal` frischt jetzt `appData` (Baustellen)
  vor der Prüfung via `loadData()` auf.**
- **Fehlbuchungen gelöschter (oder archivierter) Projekte werden nicht mehr angezeigt:**
  `_computeFehlbuchungen` überspringt Buchungen, deren Projekt nicht mehr aktiv ist
  (keine „(gelöscht)"-Zeile mehr).
- **Archivierte Projekte zeigen wieder ihren Namen** in der Buchungszeile (statt „#55").
  Neue Auflösung `_saResolveBaustelleName()`: aktive Baustelle → archiviertes Projekt
  („Name (archiviert)") → Fallback „#id". `list_archive` liefert dafür jetzt die Projekt-ID mit;
  die Stundenauswertung lädt die Archiv-Namen beim Öffnen. Gilt auch für den Suchfilter.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.61, `CACHE_VERSION` → `bk-es-v172`, `script.js?v=176`.
  `style.css` unverändert (v143).

## v2.10.60 – Arbeitszeit: Filter-/Suchfunktion im Projekt

### Neu (script.js)
- **Such-/Filterfeld über der Arbeitszeit-Tabelle** (analog zur Material-Suche). Filtert live
  über **Beschreibung, Monteur, Stundenkategorie, Datum** sowie Stunden/Preis; Mehrwort-Suche
  (alle Begriffe müssen vorkommen). Der aktive Filter bleibt nach dem Bearbeiten/Hinzufügen von
  Zeilen erhalten.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.60, `CACHE_VERSION` → `bk-es-v171`, `script.js?v=175`.
  `style.css` unverändert (v143).

## v2.10.59 – Arbeitszeit: Monteur-/User-Spalte im Projekt

### Neu (script.js)
- **Neue Spalte „Monteur" in der Arbeitszeit-Tabelle der Projekte.** Sie zeigt, wer die
  Position gebucht bzw. manuell eingetragen hat: das **Kürzel** (`erstelltVon`), bei aus der
  Zeiterfassung gebuchten Einträgen ersatzweise der **Benutzername** (`zeitUser`). Das
  „Import"-Kennzeichen für automatisch übernommene Buchungen steht jetzt in dieser Spalte.
  Die bisherige Anzeige des Kürzels als kleines Badge in der Beschreibungs-Zelle entfällt.
- Summenzeile entsprechend angepasst (colspan).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.59, `CACHE_VERSION` → `bk-es-v170`, `script.js?v=174`.
  `style.css` unverändert (v143).

## v2.10.58 – KI-Beleg-Scan: Splitten, manuelle Positionen, Beleg bei allen Projekten

### Neu (script.js)
- **Positionen anteilig aufteilen (Split):** Jede Position hat einen **➗**-Button. Er dupliziert
  die Zeile (Menge 0, ohne Baustelle), sodass Teilmengen **verschiedenen Projekten** zugewiesen
  werden können (z.B. 10 Stk. → 6 auf Projekt A, 4 auf Projekt B). Ein **✕** entfernt eine Zeile.
- **Positionen manuell anlegen:** Button **„➕ Position hinzufügen"** ergänzt eine leere Zeile –
  z.B. für Positionen, die die KI nicht erkannt hat. Manuell ergänzte Zeilen werden bei der
  Netto/Brutto-Umrechnung nicht verändert.
- **Beleg bei allen zugewiesenen Baustellen ablegen:** Die Fußzeile bietet jetzt „Beleg bei
  **allen zugewiesenen** Baustellen ablegen" – das Originaldokument wird beim Import an **jede**
  betroffene Baustelle hochgeladen (nicht mehr nur an eine).
- Technik: Umstellung des Dialogs auf ein **dynamisches Zeilenmodell** (`#kigTbody`), eigene
  Aktionsspalte, Summen/Modus/„Für alle" arbeiten per Zeilen-Traversierung; Import validiert je
  Zeile Menge > 0 und gültige Baustelle, mit Rechteprüfung (Material/Arbeitszeit).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.58, `CACHE_VERSION` → `bk-es-v169`, `script.js?v=173`.
  `style.css` unverändert (v143).

## v2.10.57 – Allgemeiner KI-Beleg-Scan mit freier Zuweisung

### Neu (index.html, script.js)
- **Neuer „🤖 KI-Scan"-Button in der Kopfzeile** (zwischen „🔍 Suche" und „📝 Schnellnotiz"),
  sichtbar für Nutzer mit Material- oder Arbeitszeit-Bearbeitungsrecht.
- **Beleg scannen und Positionen frei zuweisen:** Nach dem Scan (Lieferschein/Rechnung/Foto)
  öffnet sich ein größerer, übersichtlicherer Dialog. **Je Position** lassen sich festlegen:
  - **Baustelle** über ein **durchsuchbares Feld** (Autovervollständigung aller sichtbaren
    Baustellen inkl. Projektnummer),
  - **Zuweisung** als **Material** oder **Arbeitszeit**.
  - Komfort: „Für alle Positionen" setzt Baustelle und Zuweisung in einem Schritt; Netto/Brutto-
    Umschaltung inkl. MwSt-Satz; optionales Ablegen des Originaldokuments bei einer Baustelle.
- **Import** schreibt Material in `material` (EK netto) bzw. Arbeitszeit in `arbeitszeit`
  (Menge → Stunden, EK → Stundenpreis) der jeweiligen Baustelle – mit Rechteprüfung pro Zeile.
  Ist die aktuell geöffnete Baustelle betroffen, wird die Ansicht sofort aktualisiert.
- Der bisherige projektbezogene „🤖 KI Scan" (innerhalb eines Projekts) bleibt unverändert.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.57, `CACHE_VERSION` → `bk-es-v168`, `script.js?v=172`.
  `style.css` unverändert (v143).

## v2.10.56 – KI-Belegscan: umfangreiche Belege & neue Gemini-Modelle

### Fix (src/Handlers/AiActions.php)
- **Manche Belege wurden nicht erkannt, andere schon:** Ursache war das zu niedrige
  Ausgabe-Token-Limit (`maxOutputTokens` 4096). Umfangreiche Belege (viele Positionen) bzw.
  Thinking-Modelle erzeugten eine längere JSON-Antwort, die **abgeschnitten** wurde →
  `parseItems()` konnte das unvollständige JSON nicht mehr lesen → „Keine Materialpositionen
  erkannt". Behoben durch:
  - **`maxOutputTokens` auf 32768 angehoben** (verhindert Abschneiden bei großen Belegen).
  - **`parseItems()` rettet Positionen aus abgeschnittenem JSON:** Schlägt das vollständige
    Parsen fehl, werden die einzelnen vollständigen Positions-Objekte per Muster extrahiert;
    nur die letzte (unvollständige) Position geht verloren.
  - **`finishReason`-Auswertung:** Wird die Antwort trotzdem abgeschnitten (`MAX_TOKENS`) und
    nichts gerettet, erscheint jetzt ein **klarer Hinweis** (leistungsfähigeres Modell wählen
    oder Beleg in Teilen scannen) statt einer stillen Leermeldung.

### Neu (script.js)
- **Auswahl der Gemini-Modelle aktualisiert.** Die abgeschalteten/veralteten Einträge
  `gemini-2.0-flash`, `gemini-1.5-flash`, `gemini-1.5-pro` wurden entfernt; neu wählbar sind
  die aktuellen Modelle: `gemini-3.5-flash`, `gemini-3.1-flash-lite`, `gemini-3.1-pro-preview`,
  `gemini-3-flash-preview` sowie weiterhin `gemini-2.5-flash-lite/flash/pro`. Das freie
  Eingabefeld für manuelle Modell-IDs bleibt bestehen; Standardwert unverändert
  `gemini-2.5-flash-lite`.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.56, `CACHE_VERSION` → `bk-es-v167`, `script.js?v=171`.
  `style.css` unverändert (v143).

## v2.10.55 – Bugfix: Stundenauswertung PDF-Export (ReferenceError)

### Fix (script.js)
- **PDF-Export der Stundenauswertung** brach mit `ReferenceError: selectedUser is not defined`
  ab. In `exportStundenauswertungPDF()` wurde die Nutzerauswahl verwendet, aber nie aus dem
  Auswahlfeld gelesen. Ergänzt: `selectedUser` wird jetzt aus `#saUserSelect` gelesen
  (leer = alle sichtbaren Mitarbeiter).

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.55, `CACHE_VERSION` → `bk-es-v166`, `script.js?v=170`.
  `style.css` unverändert (v143).

## v2.10.54 – Zeiterfassung: Gleitzeit & sonstiger Fehlgrund sind Fehlzeit (0 h)

### Fix (script.js, mobile.html)
- **Korrektur zu v2.10.53:** Die Soll/Tag-Gutschrift gilt jetzt nur noch für Typen, die
  tatsächlich als **Arbeitszeit** zählen (Urlaub, Krank sowie als Arbeitszeit markierte
  eigene Typen). **Gleitzeit (Gleittag), „sonstiger Fehlgrund" und als Freizeit markierte
  eigene Typen werden als Fehlzeit mit 0 h** gebucht – nicht mehr mit Soll/Tag.
  - Die Anrechnung wird über die bestehende Anrechnungslogik gesteuert
    (`countsTowardIst` Desktop / `countsTowardIstM` Mobile): nur wer zur Ist-Zeit zählt,
    erhält an Arbeitstagen Soll/Tag, sonst 0.
  - **Gleittag** (Desktop + Mobile): Eintrag erhält jetzt `stunden = 0` (Arbeitszeit 0).
    Das Gleitzeitkonto wird dadurch weiterhin korrekt um die Tages-Sollzeit reduziert.

### Cache-Bust
- `APP_VERSION`/manifest → 2.10.54, `CACHE_VERSION` → `bk-es-v165`, `script.js?v=169`.
  `style.css` unverändert (v143).

## v2.10.53 – Zeiterfassung: Abwesenheiten mit Soll/Tag statt pauschal 8 h

### Fix (script.js, mobile.html)
- **Alle Abwesenheits-/Sondertypen werden jetzt mit der individuellen Soll/Tag-Zeit
  des Nutzers angerechnet – nicht mehr pauschal mit 8 h.** Bisher erhielten
  benutzerdefinierte Fehlgrund-Typen (das „…" neben Urlaub/Krank) sowie Gleittage
  fest 8 Stunden, unabhängig von der hinterlegten Tages-Sollzeit. Urlaub und Krank
  wurden bereits korrekt mit Soll/Tag gutgeschrieben (v2.10.38) – diese Logik gilt
  nun einheitlich für **alle** nicht-Arbeitszeit-Typen.
  - **Desktop-Stundenerfassung** (`saveSeDesktopEntry`): Fallback `: 8` durch
    Soll/Tag-an-Arbeitstagen (sonst 0) ersetzt; ungenutzte `_isCredited`-Variable entfernt.
  - **Mobile-Stundenerfassung** (`saveStundenerfassung`): identische Umstellung.
  - **Gleittag** (Desktop + Mobile): Eintrag erhält `stunden = Soll/Tag` statt fest 8
    (rein informativ; das Gleitzeitkonto wurde bereits korrekt um Soll/Tag reduziert).
  - **Mobile Admin-Bearbeitung** (`saMobileEditEntry`): kein pauschales 8 h mehr für
    Abwesenheiten; der Wert wird serverseitig (Soll/Tag) ermittelt und übernommen.

### Cache-Bust
- `CACHE_VERSION` → `bk-es-v164`, `script.js?v=168`. `APP_VERSION`/manifest bleiben 2.10.53,
  `style.css` unverändert (v143).

## v2.10.52 – Fixes: Drag&Drop, weiße Rechnungs-PDF, konfigurierbarer Abmelde-Timeout

### Fix (script.js)
- **Drag&Drop „Position auf Baustelle" warf einen JS-Fehler:** `movePositionToBaustelle`
  rief am Ende `notify(...)` auf – diese Funktion existiert nicht (`notify is not defined`),
  wodurch nach dem Verschieben `renderSidebar()`/`renderDetail()` nicht mehr liefen und die
  Funktion „kaputt" wirkte. Ersetzt durch `showNotification(...)`.
- **Rechnung „In Dateien speichern" erzeugte in Firefox eine weiße PDF:** Der temporäre
  Render-Container lag `position:fixed` außerhalb des Sichtbereichs – Firefox + html2canvas
  rendern das häufig leer. Umgestellt auf `position:absolute` mit explizit weißem Hintergrund
  und html2canvas-Optionen `backgroundColor:'#ffffff'`, `scrollX/Y:0`, `windowWidth:794`.

### Neu (Admin-Einstellungen: konfigurierbarer Abmelde-Timeout)
- **Automatische Abmeldung nach Inaktivität ist jetzt einstellbar** (Admin → Allgemein →
  „🔒 Automatische Abmeldung", nur für Admins). Wert in Minuten (5 – 43200 = 30 Tage),
  Standard 480 (8 Stunden). Wirkt ab dem nächsten Seitenaufruf.
- Backend: neuer Setting-Key `session_timeout_minutes` (Auth.php-Defaults) +
  Helper `Auth::sessionTimeoutSeconds()` (liest Wert, klemmt auf 5 Min…30 Tage, Fallback 8 h).
  `api.php` und `din1090_api.php` lesen den Timeout nach dem DB-Connect aus den Einstellungen
  statt der bisher hart codierten 8 Stunden; Session-Cookie-Lebensdauer großzügig (30 Tage),
  die serverseitige Inaktivitätsprüfung steuert die tatsächliche Abmeldung.

### Cache-Bust
- `CACHE_VERSION` → `bk-es-v162`, `script.js?v=166`, `APP_VERSION`/manifest → 2.10.52.
  `style.css` unverändert (v143).

## v2.10.51 – Optimistic Locking Phase 2 (Automatischer Merge disjunkter Änderungen)

### Neu (script.js, mobile.html)
- **Kein Fehl-Konflikt mehr bei parallelem Arbeiten an verschiedenen Baustellen:** Statt bei
  jedem `409` sofort einen Dialog zu zeigen, wird der Konflikt jetzt zuerst **automatisch
  aufgelöst**. Der Client holt die frische Server-Version und vergleicht **pro Baustelle bzw.
  Sektion** gegen eine beim letzten Sync gemerkte **Baseline**:
  - Änderungen an **unterschiedlichen** Baustellen/Sektionen → **automatischer Merge ohne
    Rückfrage** (fremde Änderungen bleiben erhalten, eigene werden daraufgesetzt, gespeichert).
  - Nur bei **echter Überschneidung** (dieselbe Baustelle/Sektion von beiden Seiten geändert)
    erscheint ein **präziser Dialog**, der nur die betroffenen Teile auflistet.
- **Verlustfreier Dialog:** Auch im Überschneidungsfall bleiben alle **disjunkten** eigenen
  Änderungen erhalten. Es ist nur noch für die kollidierenden Teile zu entscheiden:
  **„Fremde Version übernehmen"** oder **„Meine Version erzwingen"** (nicht mehr „alles
  verwerfen" vs. „alles überschreiben").
- **Robuste Änderungserkennung:** Der Vergleich nutzt eine kanonische Normalform
  (sortierte Schlüssel, gerundete Zahlen) und neutralisiert damit Schlüssel-Reihenfolge nach
  PHP-Round-Trip und Float-Rauschen. `nextId`-Zähler werden als Maximum zusammengeführt
  (verhindert ID-Kollisionen).
- **Baseline-Pflege:** Nach jedem Laden und jedem erfolgreichen Speichern (auch nach
  Merge/Erzwingen) wird die Baseline aktualisiert. Ein Wiederholungsschutz begrenzt
  Auto-Merge-Versuche; ohne Baseline greift der bisherige Konflikt-Dialog als Fallback.
- **Mobile-Offline:** Ein nachgeholter Offline-`save` mit veralteter Revision durchläuft
  denselben Auto-Merge/Dialog-Pfad statt still zu erzwingen.

## v2.10.50 – Optimistic Locking (Schutz vor Lost-Updates) – Phase 1

### Neu (Database.php, DataService.php, DataActions.php, ZeiterfassungActions.php, script.js, mobile.html)
- **Konflikterkennung statt stiller Datenverlust:** Der `save`-Endpunkt speichert die
  gesamten Projektdaten weiterhin per Full-Replace, prüft aber jetzt eine globale
  Datensatz-Revision. Jeder Client merkt sich beim Laden die Revision (`baseRev`), auf der
  seine Daten basieren, und sendet sie beim Speichern mit. Hat zwischenzeitlich ein anderer
  Nutzer gespeichert (Revision weitergezählt), wird **nicht mehr still überschrieben** –
  der Server antwortet mit `409 Conflict` inkl. `updatedBy`/`updatedAt`.
- **Neue Tabelle `data_meta`** (Singleton, `rev`/`updatedAt`/`updatedBy`) hält die aktuelle
  Revision. `DataService::currentRev()`/`bumpRev()` lesen/erhöhen sie **innerhalb der
  bestehenden Speicher-Transaktion** (atomar). `saveAllData()` nimmt `baseRev` + `force`
  entgegen; bei Konflikt Rollback + Konflikt-Info, sonst Commit + neue Revision.
- **Konflikt-Dialog (Desktop & Mobile):** Bei `409` erscheint ein Dialog mit drei Optionen:
  **„Neu laden"** (aktuelle Server-Version übernehmen, lokale ungespeicherte Änderungen
  verwerfen), **„Meine Version erzwingen"** (`forceOverwrite`, überschreibt bewusst) und
  **„Später entscheiden"**. Während der Dialog offen ist, werden weitere Auto-Saves
  pausiert; lokale Änderungen bleiben in `localStorage` erhalten.
- **Kein Selbst-Konflikt durch Auto-Import:** Der serverseitige Zeiterfassungs-Auto-Import
  (`save_zeiterfassung`) sowie die Reparatur-Endpunkte erhöhen die Revision und geben sie
  als `dataRev` zurück. Desktop und Mobile ziehen `appDataRev` daraus nach, damit der
  nächste Full-Replace-Save nicht mit sich selbst kollidiert.
- **Offline-Queue-Politik (Mobile):** Ein nachgeholter Offline-`save` mit veralteter
  `baseRev` löst beim Flush denselben Konflikt-Dialog aus – es wird **nicht still
  erzwungen**.

## v2.10.49 – Einheitlicher Buchungspfad (Desktop-Doppelschreiben entfernt)

### Geändert (script.js, ZeiterfassungActions.php, Database.php)
- **Nur noch ein Schreibpfad:** Der Desktop legte die Projektbuchung (autoImport) bisher
  zusätzlich clientseitig an und speicherte sie über einen **zweiten** Request
  (`saveDataDebounced`), parallel zur serverseitigen Auto-Import-Buchung in
  `save_zeiterfassung`. Dieser zweite Client-Schreibpfad ist entfernt – die Projektbuchung
  erfolgt jetzt **ausschließlich serverseitig und atomar**. Das war die letzte verbliebene
  Quelle asynchroner Fehlbuchungen auf dem Desktop.
- **Kategorie-Treue:** Zeiterfassungs-Einträge tragen jetzt die gewählte `stundenKatId`
  (neue Spalte in Tabelle `zeiterfassung`, per Migration ergänzt) an den Server. Die
  serverseitige Projektbuchung verwendet **primär diese pro-Eintrag gewählte Kategorie**
  (Preis/Fixkosten) und fällt nur ersatzweise auf die Standard-Stundenkategorie des Users
  zurück. So bleibt das Verhalten identisch zum bisherigen Desktop-Client.
- **Optimistische Anzeige:** Ist die Ziel-Baustelle gerade geöffnet, wird sie lokal ohne
  separates Speichern aktualisiert – die Persistenz erledigt der Server.

## v2.10.48 – Fehlbuchungs-Reconciliation (Zeiterfassung ↔ Projektbuchung)

### Neu (ZeiterfassungActions.php, Database.php, api.php, script.js, mobile.html, mobile_light.html)
- **Datenintegrität (Prävention):** Der serverseitige Auto-Import läuft jetzt in
  **derselben Transaktion** wie die Zeiterfassung – entweder werden User-Stunden UND
  Projektbuchung geschrieben oder keine (verhindert asynchrone Fehlbuchungen bei
  Abbruch/Timeout). Auto-Import-Positionen speichern jetzt zusätzlich `zeitEntryId` +
  `zeitUser`, sodass die Verknüpfung **exakt** statt fuzzy erfolgt. Bestehende
  Legacy-Positionen werden beim nächsten Speichern automatisch mit dem Link nachgerüstet.
- **Reconciliation-Engine:** Neuer Endpoint `reconcile_bookings` (Admin/Master) findet
  Fehlbuchungen beidseitig – **Orphans** (Arbeits-Zeiteintrag mit Baustelle ohne
  Projektbuchung) und **Ghosts** (verknüpfte Projektbuchung ohne Zeiterfassung).
- **Reparatur:** Neue Endpoints `repair_booking` und `repair_bookings_bulk` (idempotent,
  transaktional) tragen fehlende Projektbuchungen nach.
- **Wochenprüfung/Stundenauswertung:** Fehlbuchungen werden pro Benutzer als rote
  ⛔-Badge + Warnbox angezeigt, mit „Reparieren"-Button je Eintrag und „Alle reparieren".
  Der Filter „Nur auffällige Buchungen" berücksichtigt jetzt auch Fehlbuchungen.
- **Mobil:** Erfolgs-Toast zeigt die Anzahl angelegter Projektbuchungen an.
- **Migration:** Neuer Index `idx_zeit_user_entry` auf `zeiterfassung(username, entryId)`.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v158`, `script.js?v=162`.

## v2.10.47 – Archiv-Suche, globale Suche im Archiv, Hinweis-Quittierung, Sonstig-Ganztag

### Neu (script.js, mobile.html, mobile_light.html, index.html, api.php, BaustelleActions.php)
- **A.6 Archiv-Suche:** Im Archiv-Dialog gibt es jetzt ein Suchfeld (Name, Projekt-Nr.,
  Archivierer, Datum). Neuer Endpoint `search_archive` für serverseitige Metadaten-Suche.
- **E.1 Globale Suche:** Neue Filter-Option „Archiv" – archivierte Baustellen werden in
  die Ergebnisse aufgenommen (mit direktem „Importieren"-Button).
- **B.4 Hinweis-Quittierung:** Einzelne Zeiterfassungs-Hinweise können per ✓ quittiert und
  damit ausgeblendet werden (pro Benutzer in localStorage). Quittierungen lassen sich
  zurücksetzen.
- **B.5 Sonstiger Fehlgrund – Ganztag vs. Zeitspanne:** Auswahl „Ganzer Tag" (Soll-Tag,
  wochentagsabhängig) oder „Zeitspanne" (Von/Bis) in allen drei Oberflächen (Desktop,
  Mobil, Mobil-Light). Sonstige Fehlgründe werden weiterhin NICHT auf Ist/Gleitzeit
  angerechnet (nur Soll-Bewertung).
- Cache-Bust: `CACHE_VERSION` → `bk-es-v157`, `script.js?v=161`.

## v2.10.46 – Positionen per Drag&Drop zwischen Baustellen verschieben

### Neu (script.js, style.css, api.php, BaustelleActions.php)
- **Drag&Drop (A.1):** Arbeitszeit- und Material-Zeilen können per Maus auf einen
  Baustellen-/Unterprojekt-Eintrag in der Seitenleiste gezogen und so in eine andere
  Baustelle verschoben werden (native HTML5-DnD, ohne Bibliothek). Vor dem Verschieben
  Sicherheitsabfrage; Drop-Ziel wird optisch hervorgehoben.
- **Zeiterfassungs-Kaskade (A.2):** Beim Verschieben eines auto-importierten
  Arbeitszeit-Eintrags wird die verknüpfte Zeiterfassung serverseitig auf die neue
  Baustelle umgehängt (`UPDATE zeiterfassung.baustelleId`). Dafür speichert der
  Auto-Import jetzt `zeitEntryId` + `zeitUser`. Neuer Endpoint `move_position`
  (Berechtigung: Admin/Master beliebig, normale Nutzer nur eigene Einträge).
- Der lokale Zeiterfassungs-Cache wird mitgezogen, damit ein späterer Full-Replace die
  Umbuchung nicht überschreibt.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v156`, `script.js?v=160`, `style.css?v=143`.

## v2.10.45 – Abschläge: Schlussrechnung markieren + Warnhinweis

### Neu (script.js, mobile.html)
- **Art pro Abschlag:** Jeder Eintrag kann als *Abschlag* oder *Schlussrechnung*
  markiert werden (Desktop: neue Spalte „Art", Mobile: Auswahlfeld im Formular).
- **Warnhinweis:** Sobald eine Schlussrechnung existiert, erscheint ein Warn-Banner
  über den Abschlägen. Beim Hinzufügen weiterer Einträge wird zusätzlich eine
  Bestätigung verlangt.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v155`, `script.js?v=159`.

## v2.10.44 – Rechnungs-PDF: keine weißen Seiten + Druck-Layout A4

### Fix (script.js)
- **PDF-Speichern (`_saveRechnungPdfBackground`):** Vor dem Rendern via html2canvas
  wird jetzt auf das Dekodieren aller Bilder (`img.decode()`) und auf
  `document.fonts.ready` (max. 3 s) gewartet. Verhindert, dass bei ungünstigem Timing
  eine leere/weiße PDF-Seite gespeichert wird.

### Verbesserung (style.css)
- **Druck-CSS (`@media print`):** Neue `@page`-Regeln (`size:A4`, `margin:14mm 12mm`).
  Bildschirm-Chrome (Sidebar, Topbar, Aktions-Buttons, Modals, Toasts, FAB) wird im
  Ausdruck ausgeblendet; Tabellenzeilen/Karten mit `page-break-inside:avoid`,
  Tabellenköpfe wiederholen sich pro Seite.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v154`, `script.js?v=158`, `style.css?v=142`.

## v2.10.43 – Material: Kundenrabatt pro Position + „Listenpreis als VK"

### Neu (script.js, mobile.html, index.html)
- **Rabatt (%) pro Materialposition:** Neue Spalte in der Material-Tabelle (Desktop)
  bzw. Feld im Material-Formular (Mobile). Der Rabatt reduziert den VK-Preis der
  Position (`VK = EK × (1+Aufschlag) × (1+Global) × (1−Rabatt/100)`).
- **„Listenpreis als VK":** Checkbox im Katalog-Picker (Desktop). Aktiv → importierter
  Listenpreis wird direkt als VK übernommen (kein Auf-/Global-Aufschlag, `vkDirekt`,
  `aufschlag=0`). Aus → bisheriges Verhalten (Listenpreis als EK).
- **Negative Mengen/Preise erlaubt:** `min="0"` bei Anzahl und EK-Preis entfernt
  (Gutschriften/Abzüge). `matVk` clamped nicht mehr auf ≥ 0.
- `matVk` (Desktop + Mobile) berücksichtigt nun `rabatt` und `vkDirekt`.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v153`, `script.js?v=157`.

## v2.10.42 – Pauschalen: Dezimalfaktor statt nur ganze Zahlen

### Änderung (script.js, mobile.html)
- Die Pauschalen-**Anzahl** akzeptiert nun Dezimalwerte (z.B. `0,5` für einen halben
  Satz). Eingabefelder `step=0.01`, `min=0`; Parsing via `parseFloat` (Komma wird
  toleriert). Gesamt = Einzelpreis × Faktor.
- Desktop (`setPauschaleField`) und Mobile (`setPauschaleFieldMobile`) angepasst.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v152`, `script.js?v=156`.

## v2.10.41 – Stundenauswertung: Filter „auffällige Buchungen" + Vollständigkeit

### Neu (index.html, script.js)
- Checkbox **„⚠️ Nur auffällige Buchungen"** in der Stundenauswertung. Aktiv werden nur
  Mitarbeiter mit Auffälligkeiten und deren betroffene Tage angezeigt (Detail automatisch
  aufgeklappt).
- Neue Anomalie-Erkennung `_computeZeitAnomalien` (Basis: datumsgefilterte Einträge, vor
  Textsuche):
  - **Fehlender Eintrag** an Arbeitstagen (Vollständigkeit, ohne Feiertage/Zukunft; Scan
    = Filterzeitraum bzw. letzte 30 Tage).
  - **Unter Soll** an Arbeitstagen (Ist < Soll/Tag).
  - **Über 10 h** Tagessumme (§ 3 ArbZG).
  - **Arbeitseintrag mit 0 h**.
  - **Zeitüberschneidung** (Von/Bis am selben Tag).
- Auffälligkeiten-Zähler als Badge im Mitarbeiter-Kopf + Auffälligkeiten-Box im Detail.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v151`, `script.js?v=155`.

## v2.10.40 – Offline-Schutz für Feldgeräte (mobile & mobile_light)

### Problem
- Auf Baustellen-Geräten (z.B. Motorola/Chrome) gingen Speicherungen bei Funkloch/
  instabiler Verbindung **still verloren**: kein Timeout, kein Offline-Check, keine
  Wiederholung – nur `console.warn`.

### Fix (mobile.html, mobile_light.html)
- **fetchWithTimeout** (AbortController, 12 s) für alle Speicher-Requests statt endlos
  hängender `fetch`.
- **Offline-Queue in localStorage**: Bei fehlender Verbindung/Timeout wird der letzte
  Stand je Aktion (`save`, `save_zeiterfassung`) lokal vorgehalten (Full-Replace-Semantik).
  Lokale Änderung bleibt **erhalten** (kein stiller Rollback bei Netzfehler).
- **Auto-Flush** bei `online`-Event und beim App-Start (`flushOfflineQueue` /
  `flushLightOfflineQueue`).
- **Klare Unterscheidung** Netzwerk-/Timeout-Fehler (→ einreihen, Änderung behalten) vs.
  echter Serverfehler wie Validierung/Limit (→ Rollback + Fehlermeldung).
- Sichtbares Feedback via Toast/Sync-Leiste („Offline – Änderungen werden nachgeholt").
- Cache-Bust: `CACHE_VERSION` → `bk-es-v150` (mobile.html/mobile_light.html sind precached).

## v2.10.39 – Zeiterfassung: neueste Einträge oben

### Änderung
- Die Zeiterfassungs-Eintragsliste sortiert nun **neuestes Datum zuoberst** (absteigend).
  Der **aktuelle Monat bleibt** als Block oben gruppiert, innerhalb davon (und im Rest)
  absteigend; bei gleichem Datum sekundär nach `id` absteigend.
- Betroffen: Desktop (`renderSeDesktop` – Eintragsliste inkl. virtueller Feiertage) und
  Mobile (`renderStundenerfassung`). `mobile_light` sortierte bereits absteigend
  (unverändert).
- Berechnungen für Ist/Soll/Gleitzeit sowie PDF-/Auswertungssortierungen sind **unverändert**.
- Cache-Bust: `CACHE_VERSION` → `bk-es-v149`, `script.js?v=154`.

## v2.10.38 – Bugfix: JS-Cache-Truncation & Urlaub/Krank mit Soll/Tag anrechnen

### Problem 1 – JavaScript-Fehler nach Funkloch/Teil-Download
- Firefox meldete `SyntaxError: missing name after . operator` (aufmass.js) und
  `SyntaxError: '' literal not terminated before end of script` (lager.js). Ursache waren
  **abgeschnittene, gecachte Modul-Kopien** (unvollständiger Download, z.B. bei
  instabiler Verbindung). Die Quelldateien selbst sind intakt.

### Fix 1 (sw.js, script.js, mobile.html)
- Neuer **Truncation-Guard** `cleanForCache()` im Service Worker: Antworten werden nur noch
  gecacht, wenn die Blob-Grösse zur `Content-Length` passt – abgeschnittene Antworten
  landen nicht mehr im Cache und werden nie ausgeliefert.
- `CACHE_VERSION` → `bk-es-v148` (verwirft alte, defekte Caches beim Aktivieren).
- Cache-Bust der optionalen Module: **aufmass v54**, **lager v57** (js+css) in
  `script.js` **und** `mobile.html` (identisch gehalten). `script.js?v=153`.

### Problem 2 – Urlaubstag ohne Soll/Tag-Stunden
- Urlaubstage wurden teils mit **0 Stunden** verbucht statt mit der Soll-Tagesarbeitszeit;
  Urlaub/Krank zählten zudem nicht zur Ist-Zeit (Gleitzeit verfälscht). Kein Jahreslimit.

### Fix 2 (ZeiterfassungActions.php, script.js, mobile.html, mobile_light.html)
- **Backend ist massgeblich**: `save()`, `adminAddEntry()`, `adminEditEntry()` überschreiben
  die Stunden für **Urlaub/Krank** immer mit **Soll/Tag** des jeweiligen Arbeitstages
  (0h an Nicht-Arbeitstagen) – Gleitzeit-neutral, da Soll und Ist denselben Tag gutschreiben.
- **Ist-Anrechnung**: Urlaub/Krank zählen jetzt in allen Ist-/Gleitzeit-Summen mit
  (`countsTowardIst` Desktop, `countsTowardIstM` mobil, inline in mobile_light).
- **Jahres-Urlaubslimit** (`urlaubstageProJahr`, sonst 30 Tage) wird server- und
  clientseitig geprüft. Bei Erreichen: Meldung „Urlaubslimit für <Jahr> erreicht
  (<X> Tage). Buchung nicht möglich.“ – Buchung wird abgebrochen. Bestehende
  Über-Limit-Altdaten bleiben editierbar.


## v2.10.37 – Bugfix: Stundenerfassung – Bearbeiten/Löschen datumsbasiert (letzte 5 Tage)

### Problem
- Die Bearbeit-/Lösch-Sperre für eigene Einträge war in **mobile.html** und **mobile_light.html**
  **zählbasiert** (letzte 5 Einträge per `slice(0,5)`), nicht datumsbasiert. Folge: Wurde
  Urlaub/Einträge in der **Zukunft** erfasst, belegten diese die 5 „Slots“ – aktuelle Einträge
  liessen sich dann nicht mehr bearbeiten oder löschen.
- **Desktop** war zwar datumsbasiert, nutzte aber **3 Tage** statt 5.
- In **mobile_light** war der Löschen-Button (`×`) zudem gar nicht beschränkt.

### Fix (script.js, mobile.html, mobile_light.html)
- Einheitliche Regel in allen drei Erfassungen: Bearbeiten/Löschen nur für Einträge der
  **letzten 5 Tage** (datumsbasiert, `Datum >= heute − 5 Tage`, inkl. Zukunft, damit künftiger
  Urlaub editierbar bleibt und keine Slots blockiert).
- mobile_light: Löschen-Button (`×`) jetzt ebenfalls an dieselbe 5-Tage-Regel gekoppelt.
- Cache-Bust `script.js?v=152`, SW `bk-es-v147`.

### Hinweis
- Die Beschränkung ist clientseitig (UI). Der Self-Service-Endpoint `save_zeiterfassung`
  ersetzt weiterhin den kompletten Eintragssatz ohne serverseitige Tagesgrenze.


## v2.10.36 – Bugfix: Safari/iOS „Response served by service worker has redirections“

### Service-Worker (sw.js)
- **Safari/WebKit-Fix**: Safari verweigert jede vom Service Worker ausgelieferte Antwort mit
  `response.redirected === true` (Fehler „Response served by service worker has redirections“,
  Seite lässt sich mobil nicht öffnen). Ausgelöst durch Server-Weiterleitungen einer
  Navigationsseite (z.B. `./` → `index.html`, Redirect zu `login.html`, HTTPS-/Trailing-Slash-
  Redirect), die als weitergeleitete Response gecacht/ausgeliefert wurden.
- Neue Helper `cleanRedirect()` baut weitergeleitete Antworten als saubere, nicht-weitergeleitete
  Response neu auf. Angewendet beim **Precache** (`fetch`+`cleanRedirect` statt `cache.add`) und im
  **Fetch-Handler** für statische Assets/Navigationen (vor Cache-Speicherung **und** Auslieferung).
- `CACHE_VERSION` → `bk-es-v146`: verwirft den alten Cache mit den fehlerhaften redirected-Einträgen
  beim Aktivieren des neuen SW.


## v2.10.35 – Tagesprüfung (Zeitübersicht & Stundenauswertung)

### Neu: Tagesprüfung ergänzend zur Wochenprüfung
- Im Prüfungs-Tab (Stundenauswertung **und** Zeitübersicht) lässt sich jede Kalenderwoche
  jetzt über das Dreieck (▸/▾) **aufklappen**. Darunter erscheinen alle erfassten Tage der
  Woche mit eigener Prüf-Checkbox (Tagesprüfung), Datum, Eintragsart und Ist-Stunden.
- **Kopplung Woche → Tage (persistiert)**: Wird die Wochenprüfung vom Berechtigten abgehakt,
  gelten automatisch **alle erfassten Tage dieser Woche als bestätigt** – der Status wird
  serverseitig für jeden Tag in die DB geschrieben (`zeiterfassung_tagespruefung`). Wird die
  Woche zurückgesetzt, werden dieselben Tage wieder auf ungeprüft gesetzt. Bei geprüfter Woche
  sind die Tages-Checkboxen gesperrt („Über Wochenprüfung bestätigt“).
- Je Woche zeigt das Label `(x/y Tage geprüft)` den Tagesprüf-Fortschritt.

### Technik
- **Backend** (`src/Handlers/ZeiterfassungActions.php`): neue Tabelle
  `zeiterfassung_tagespruefung` (username+datum, unique), Handler `loadTagespruefung` /
  `saveTagespruefung`; `saveWochenpruefung` propagiert den Status über
  `propagateWochenpruefungToTage()` auf alle Tage der ISO-Woche mit Einträgen. Berechtigung
  wie Wochenprüfung (`canSeeWochenpruefung`).
- **API** (`api.php`): neue Actions `load_tagespruefung` / `save_tagespruefung`.
- **Backup/Restore** (`src/Handlers/DataActions.php`): `zeiterfassung_tagespruefung` in den
  Snapshot/Restore aufgenommen.
- **Frontend** (`script.js`): gemeinsame ISO-Wochen-Helfer, ausklappbare Tagesblöcke in
  `renderWochenpruefung` (Stundenauswertung) und `zuRenderWochenpruefung` (Zeitübersicht);
  Cache-Bust `script.js?v=151`, SW `bk-es-v145`.


## v2.10.34 – Betriebsabsicherung: Auto-Restart, Ressourcen-Limits & Backup-Integritätsprüfung

### Infrastruktur (docker-compose.yml)
- **Autoheal-Container** (`willfarrell/autoheal`): startet den App-Container automatisch neu,
  sobald der vorhandene Healthcheck `unhealthy` meldet. Schliesst die Lücke, dass ein
  Healthcheck einen hängenden Container nur markiert, Docker ihn aber nicht von selbst neu
  startet (`restart` greift nur bei echtem Prozess-Exit). Docker-Socket read-only gemountet,
  Prüfintervall 30 s, Karenz 60 s nach Start.
- **Log-Rotation** am App-Container (`json-file`, `max-size: 10m`, `max-file: 3`): verhindert
  volllaufende Logs/Platte – häufigster Grund für ein nach Monaten stehenbleibendes NAS.
- **Ressourcen-Limits**: `mem_limit` (Default 1g) und `cpus` (Default 1.5), überschreibbar via
  `APP_MEM_LIMIT` / `APP_CPUS` in der `.env`. Verhindert, dass ein Speicherleck oder OCR den
  ganzen NAS lahmlegt; bei OOM startet der Container dank Autoheal/`restart` sauber neu.

### Datensicherheit (cron_backup_email.php)
- **Integritätsprüfung vor Backup**: `PRAGMA integrity_check` läuft vor dem Sichern der
  Datenbank. Bei Befund wird `ACHTUNG: ...` in `data/cron.log` protokolliert. Rein additiv –
  das Backup wird trotzdem erstellt, damit im Fehlerfall überhaupt eine Kopie existiert.

### Geprüft (kein Handlungsbedarf)
- SQLite-Mehrbenutzerbetrieb: `journal_mode=WAL`, `synchronous=NORMAL`, `foreign_keys=ON` und
  `busy_timeout=15000` (15 s) sind in `src/Database.php` bereits sauber gesetzt – paralleles
  Speichern wird korrekt serialisiert statt mit „database is locked“ abzubrechen.


## v2.10.33 – Bug-Review: Datenintegrität beim Löschen & Transaktions-Absicherung

### Bugfixes (aus vollständigem Code-Review)
- **mobile.html: Löschen ohne Server-Bestätigung** (`delStundenerfassung`): Eintrag wurde lokal
  entfernt und sofort „Gelöscht“ gemeldet, der `fetch` aber nur per `console.warn` abgefangen.
  Bei Serverfehler → UI zeigte Erfolg, Eintrag blieb auf dem Server (Inkonsistenz). Jetzt mit
  echter Erfolgsprüfung (`response.ok` + `payload.ok`) und **Rollback** der gelöschten Einträge
  bei Fehler.
- **PHP `save_zeiterfassung`**: `DELETE`+`INSERT` lief in einer Transaktion ohne `try/catch`.
  Bei einem Insert-Fehler blieb die Transaktion offen. Jetzt in `try/catch` mit `rollBack()`
  und sauberer Fehlerantwort (500).
- **PHP `save_wochenplanung`**: gleiches Muster (`DELETE`+`INSERT` ohne Absicherung) → jetzt
  ebenfalls `try/catch` mit `rollBack()`.
- **mobile.html `saveGleitzeitStartdatumMobile`**: `fetch` ohne jede Fehlerbehandlung → jetzt
  mit Erfolgsprüfung und Fehler-Toast.
- **script.js `renderPickerSelectedBar`**: `v.label.length` konnte bei Katalogartikeln ohne
  Bezeichnung einen TypeError werfen → defensiv auf `v.label || ''` abgesichert.

### Geprüft (sauber)
- Restliches `script.js` (~19.600 Zeilen), `mobile_light.html` und das übrige PHP-Backend:
  keine weiteren TDZ-, Doppeldeklarations-, SQL-Injection- oder Auth-Probleme gefunden.



### Bugfixes
- **mobile light: Speichern nicht möglich**: In `saveEntry()` wurde `_isArbeit` (per `const`)
  erst nach seiner ersten Verwendung deklariert → jeder Speicherversuch warf sofort einen
  `ReferenceError: Cannot access '_isArbeit' before initialization` (Temporal Dead Zone).
  Deklaration nach vorne gezogen; Speichern/Löschen funktioniert wieder.
- **Gelöschte/geänderte Stunden blieben im Projekt gebucht**: Bei aktivem Auto-Import wurden
  Arbeitsstunden in das `arbeitszeit` der Baustelle gebucht, beim Löschen aber nie wieder
  entfernt. Neu: Abgleich (`reconcileAutoImport`) beim Speichern (`save_zeiterfassung`) und
  beim Admin-Löschen (`admin_delete_zeiterfassung`) – verwaiste Auto-Import-Positionen
  (gleiches Kürzel, ohne passenden Erfassungseintrag zu Baustelle/Datum/Stunden) werden aus
  dem Projekt entfernt. Nur Auto-Import-Positionen des jeweiligen Mitarbeiters werden berührt;
  manuell erfasste Positionen bleiben unangetastet.



### Verbesserung
- **Stundenwerte einheitlich mit 2 Nachkommastellen** (z.B. `8,25 h`) in allen Stunden-
  Übersichten – vorher wurden Werte teils auf 1 Stelle gerundet angezeigt (`8,3 h`)
  - **mobile Stundenerfassung**: KPI-Kacheln (Ist/Soll/+-/Gleitzeit/Heute), Eintragsliste,
    Monats-/Jahrestabellen, Gleitzeit-Buchungen, Je-Monteur-/Tagesansichten, PDF-Export
  - **mobile light**: Eintragskarten (Stunden) und Tagesinfo (Ist/Soll)
  - **Desktop**: Stundenauswertung (Monatssummen + Eintragszeilen + KW-Prüfung),
    Zeiterfassungs-Übersicht (Zusammenfassung/Je-Monteur/Je-Tag), Mitarbeiter-Auswertung
    (Tabelle/CSV/PDF), Monatsdetail- und Druckansichten
  - Reine Hinweis-/Warn-Dialoge, Dateigrößen und Prozentangaben bleiben unverändert

## v2.10.30 – CSP/CDN lokal gehostet; einklappbare Monate; Jahreswechsel-Ausblendung (Stunden + Archive)

### Bugfixes
- **PDF-Report (mobil) schlug fehl** & **leere Auswertungs-Diagramme**: Root Cause war die
  `.htaccess`-CSP (`script-src 'self'`), die alle Skripte von `cdnjs.cloudflare.com` blockiert
  (Chart.js, xlsx, html2pdf wurden nie geladen → `typeof X === 'undefined'`)
  - Bibliotheken lokal nach `lib/` gehostet: `chart.umd.min.js` (4.4.1), `xlsx.full.min.js`
    (0.18.5), `html2pdf.bundle.min.js` (0.10.2) – via `'self'` von der CSP erlaubt
  - `index.html` Script-Tags + alle html2pdf-Loader (mobile.html 3×, script.js 6×) auf `lib/`
    umgestellt; `sw.js` precacht jetzt die lokalen Libs statt der CDN-URLs
- **Rechnung/Angebot „weiße Seite"** beim im Projekt gespeicherten PDF: In
  `_saveRechnungPdfBackground` stand der Render-Container auf `position:fixed; top:-9999px`
  → html2canvas erfasste eine leere Fläche. Fix: `top:0` (wie funktionierende Mobil-Variante)
- **Auswertung Mojibake**: „Kaufm²nnisch"→„Kaufmännisch", „überstunden"→„Überstunden",
  „Mitarbeiter-übersicht"→„Mitarbeiter-Übersicht"

### Stundenerfassung – einklappbare Monate
- Einträge sind in **mobiler Stundenerfassung**, **mobile light** und **Desktop-Stundenauswertung**
  nach Monat gruppiert und einklappbar; aktueller Monat offen, übrige eingeklappt
- Zustand pro Monat wird in `localStorage` gemerkt; bei aktiver Suche/Filter immer aufgeklappt

### Jahreswechsel – Vorjahres-Buchungen ausblenden (kein Löschen)
- Neuer Schritt im Jahreswechsel-Assistenten: Buchungen bis einschließlich gewähltem Jahr
  werden in Stundenerfassung & Stundenauswertung **ausgeblendet** (reiner Anzeigefilter,
  Einstellung `zeiterfassung_abschluss_jahr`) – Daten bleiben vollständig erhalten
- Button **„Buchungen {Jahr} als Excel herunterladen"** (alle Mitarbeiter, Export im Browser)
- Schalter **„Abgeschlossene Jahre anzeigen"** in Desktop-Auswertung & mobiler Erfassung;
  Häkchen **„Abschluss aufheben"** macht alles wieder sichtbar
- Salden (Gleitzeit/Urlaub) nutzen weiterhin die volle Historie – nur Listen sind gefiltert

### Jahreswechsel – Archivierte Baustellen aus Auswertung ausblenden
- Eigener Schritt im Assistenten: archivierte Baustellen bis einschließlich gewähltem
  Archiv-Jahr werden in der **kaufmännischen Auswertung** ausgeblendet (Einstellung
  `auswertung_archiv_abschluss_jahr`) – Archive bleiben unverändert erhalten
- Schalter **„Ausgeblendete Archive anzeigen"** in der Auswertung; Häkchen
  **„Ausblendung aufheben"** im Assistenten; pro-Baustelle-`includeInAuswertung` bleibt vorrangig
- Aktive Baustellen & Mitarbeiter-Tab unberührt; Jahr-Dropdown bleibt vollständig

### Sicherheit / Datenintegrität
- Alle Ausblendungen sind reine Anzeigefilter über Einstellungen – **keine DB-Migration,
  kein Löschen, keine Mutation** von Buchungen oder Archiven; jederzeit umkehrbar
- `Auth.php` Defaults um `zeiterfassung_abschluss_jahr` und `auswertung_archiv_abschluss_jahr`
  ergänzt (sonst hätte `saveSettingsAction` unbekannte Keys still verworfen)

### Versionen
- APP_VERSION 2.10.29 → 2.10.30 (api.php, manifest.json)
- CACHE_VERSION bk-es-v138 → bk-es-v140 (sw.js)
- script.js ?v=145 → ?v=147 (index.html)
- mobile.html: lager.css ?v=55→56, aufmass.css ?v=52→53 (Angleich an Desktop-Stand)

## v2.10.15 – Gesamtübersicht Aggregation; Sidebar Drag & Drop; Backup WAL-Fix; JS-Cache-Fix

### Bugfixes
- **VDE 0100 JS-Fehler** (Firefox): `vde0100.js?v=90` verursachte SyntaxError im Browser-Cache;
  Cache-Version auf `?v=91` angehoben – Browser lädt aktuelles Modul
- **mobile.html Modulversionen**: `aufmass.js?v=52→53`, `lager.js?v=55→56`, `din1090.js?v=55→56`
  stimmten nicht mit `script.js` überein – jetzt synchron

### Gesamtübersicht – Oberprojekt-Aggregation
- Oberprojekte zeigen jetzt die **Summe aller Unterprojekte** (Material, Arbeitszeit, Pauschalen,
  Abschläge, Gewinn) – vorher wurden nur eigene Positionen des Oberprojekts angezeigt
- Grand-Total-Zeile zählt Oberprojekte einmalig (keine Doppelzählung der Unterprojekte)
- Oberprojekte mit Unterprojekten erhalten Badge **∑ Gruppe** in der Namensspalte
- Sortierung der Unterprojekte in der Übersicht berücksichtigt jetzt `sortPos`

### Sidebar – Drag & Drop für Unterprojekte
- Unterprojekte innerhalb eines Oberprojekts können per Drag & Drop umsortiert werden
- Drag-Handle (⠿) links neben dem Projektnamen; beim Loslassen wird die neue Reihenfolge
  sofort per `save_sub_sort_order`-API gespeichert (Feld `sortPos` in Baustellen-JSON)
- Neue API-Route `save_sub_sort_order` + `BaustelleActions::saveSubSortOrder()`

### Backup – WAL-Checkpoint
- Vor jeder SQLite-Kopie (manuelles Backup, Download, Cron-E-Mail) wird jetzt
  `PRAGMA wal_checkpoint(FULL)` ausgeführt → keine unvollständigen Backups mehr
  bei WAL-Modus (Root Cause des Datenverlusts vom 2026-06-05)
- Betrifft: `DataActions::backupCreate()`, `cron_backup_email.php`

### Versions
- APP_VERSION 2.10.14 → 2.10.15 (api.php, manifest.json, cron_backup_email.php)
- CACHE_VERSION bk-es-v122 → bk-es-v123 (sw.js)
- ?v=126 → ?v=127 (style.css, script.js in index.html)
- vde0100.js?v=90→91 (script.js), vde0100.js?v=89→91 (mobile.html)
- aufmass.js?v=52→53, lager.js?v=55→56, din1090.js?v=55→56 (mobile.html)

## v2.10.14 – Baustellen-Selektor overflow-sicher; Cron-Daemon im Docker-Container

### Bugfixes
- **Baustellen-Selektor**: Dropdown/Picker überall overflow-sicher umgestellt
  - `bsDdFilter` (Termin-Form, Rechnung-Form, Schnellnotiz-Desktop, WA-Baustelle):
    `position:fixed` + `getBoundingClientRect()` statt `position:absolute`
    → kein Clipping durch `overflow:auto` in Modal-Containern mehr
  - Wochenplanung Desktop-Popup (`wpPopBauSearch`): Custom-Dropdown → `bsDdHtml()`
  - `mobile.html` Stundenerfassung Sheet: Floating-Dropdown → Vollbild-Picker-Modal
  - `mobile.html` Wochenplanung Mobile Sheet: Floating-Dropdown → Vollbild-Picker-Modal
  - `mobile_light.html` Stundenerfassung: bs-wrap/bs-list → Vollbild-Picker-Modal
  - Bereits OK (kein Fix nötig): Desktop-SE (`index.html`), VDE 0100 (`<select>`)
- **Automatisches Backup per E-Mail**: Wurde nie automatisch gesendet
  - Root Cause: `docker-entrypoint.sh` startete keinen Cron-Daemon; alle drei
    Cron-Skripte wurden nie aufgerufen (manueller Versand via `--force` funktionierte)
  - `Dockerfile.app`: Paket `cron` ergänzt; `/etc/cron.d/baukalkulation-cron`
    erstellt (alle 3 Skripte alle 15 Min als `www-data`)
  - `docker-entrypoint.sh`: `/usr/sbin/cron` wird jetzt vor `apache2-foreground`
    gestartet (nach chown data/, damit www-data schreiben kann)
  - ⚠ Re-Build des Images erforderlich: `docker compose up -d --build`

### Versionen
- APP_VERSION 2.10.13 → 2.10.14 (api.php, manifest.json)
- CACHE_VERSION bk-es-v121 → bk-es-v122 (sw.js)
- ?v=125 → ?v=126 (style.css, script.js in index.html)

## v2.10.13 – Bugfixes, DIN1090 Projektliste, Steuerrecht, Angebot/Rechnung als Ausgangsbeleg

### Bugfixes
- **DIN1090 Checkliste**: Notiz wurde beim Umschalten einer Checkbox gelöscht
  (Ursache: `din1090ToggleCheck` sendete immer `bemerkung: ''`; Fix: aktuellen
  DOM-Wert lesen und mitschicken)
- **Mobile Schnellerfassung**: Baustellen-Dropdown war hinter Sheet-Overlay verborgen
  (z-index:20 → z-index:2000; Overlay hat z-index:500)
- **Bestellwesen**: „Als bestellt markieren" wurde nach Reload nicht gespeichert wenn
  der User kein Admin/Master ist – PHP `markOrdered()` erlaubt jetzt zusätzlich
  alle User mit `canOrderMaterial`-Berechtigung
- **JS-Cache**: Module-Versionen angehoben um Browser-Cache zu brechen:
  aufmass.js?v=52→53, lager.js?v=55→56, vde0100.js?v=89→90

### DIN1090 – Projektliste auf Startseite
- Wenn kein Projekt ausgewählt ist, erscheint jetzt eine Kartenliste aller Projekte
  (Bezeichnung, Projekt-Nr., Ausführungsklasse-Badge, Baustelle, Status-Badge)
- Suchfeld über der Liste zum schnellen Filtern
- Löschen-Button je Karte (nur für Benutzer mit Schreibrecht)
- Klick auf Karte öffnet das Projekt direkt

### Steuerrechtliche Einstellungen (Admin-Einstellungen → Firmenangaben)
- Neuer Abschnitt „⚖️ Steuerrechtliche Besonderheiten":
  - **Kleinunternehmerregelung (§ 19 UStG)**: MwSt-Zeile auf Rechnungen/Angeboten
    wird ausgeblendet; stattdessen gesetzlicher Hinweis
  - **Reverse Charge (§ 13b UStG)**: Zusatztext auf Rechnung/Angebot, MwSt = 0
    (konfigurierbarer Hinweistext)
- Rechnungsvorschau `previewRechnung()` und Summen-Label im Formular passen sich
  dynamisch an die aktive Steuerregelung an
- Neue Settings-Keys: `firma_kleinunternehmer`, `firma_reverse_charge`,
  `firma_reverse_charge_text` (mit Default-Text)

### Angebot/Rechnung als Ausgangsbeleg in Dateien & Fotos
- Neuer Button „📎 In Dateien speichern" im Rechnungs-/Angebots-Formular
  (neben „Vorschau / Drucken")
- Auto-Save: Beim Öffnen der Druckvorschau wird das PDF automatisch in
  Dateien & Fotos der Baustelle gespeichert (Kategorie: Ausgangsbelege/Angebote)
- Nutzt bestehenden `api.php?action=save_generated_file`-Endpunkt (FileActions.php)

### Versionen
- APP_VERSION 2.10.12 → 2.10.13 (api.php, manifest.json)
- CACHE_VERSION bk-es-v120 → bk-es-v121 (sw.js)
- ?v=124 → ?v=125 (style.css, script.js in index.html)
- aufmass.js?v=52→53, lager.js?v=55→56, vde0100.js?v=89→90 (in script.js)

## v2.10.12 – Jahreswechsel-Assistent

### Neues Feature: Jahreswechsel-Assistent (Admin-Einstellungen)
- Neuer Bereich „🗓️ Jahreswechsel-Assistent" in den Admin-Einstellungen (unterhalb Backup-E-Mail)
- Modal mit drei Abschnitten, je nach Konfiguration angezeigt:

**🏖 Urlaubstage:**
- Zeigt alle Benutzer mit ihren Urlaubstagen des Quell-Jahres
- Eingabefeld für das Ziel-Jahr (vorausgefüllt mit dem Wert des Quell-Jahres)
- Pro Benutzer per Checkbox auswählen → `set_urlaubstage`-API

**⏱ Gleitzeitkonto-Übertrag** (nur wenn Gleitzeitkonto aktiviert):
- Server-seitige Saldo-Berechnung pro Benutzer (`get_jahreswechsel_data`)
- Replikation der clientseitigen `calcGleitzeitSaldo()`-Logik in PHP
  (Bayerische Feiertage, Sollstunden, custom_feiertage, gleitzeit_startdatum)
- Übertragsbetrag editierbar, Kommentar-Pflichtfeld
- Bucht als Gleitzeitkonto-Buchung auf den 01.01. des Ziel-Jahres

**📅 Betriebliche Feiertage:**
- Betriebliche Sonderfeiertage des Quell-Jahres mit angepasstem Zieldatum vorschlagen
- Per Checkbox auswählen → werden als neue Einträge in `custom_feiertage` gespeichert

**Technisch:**
- Neuer API-Endpunkt `get_jahreswechsel_data` (GET) in `ZeiterfassungActions.php`
- `saveGleitzeitBuchung()`: akzeptiert jetzt optionales `datum`-Feld (ISO 8601, validiert)
- Quell-Jahr wählbar (Dropdown, Standard: aktuelles Jahr); Ziel-Jahr = Quell-Jahr + 1
- Alle Aktionen einzeln auswählbar (Checkboxen), kein Zwang zur Gesamt-Ausführung

### Gesetzliche Feiertage (keine Änderung nötig)
- Bayerische gesetzliche Feiertage werden bereits dynamisch per Jahr berechnet –
  kein Handlungsbedarf beim Jahreswechsel

### Versionen
- APP_VERSION 2.10.11 → 2.10.12 (api.php, manifest.json)
- CACHE_VERSION bk-es-v119 → bk-es-v120 (sw.js)
- ?v=123 → ?v=124 (style.css, script.js in index.html)

## v2.10.11 – Backup-E-Mail Fix: Permission denied / cron_backup_email.php fehlend

### Backup-E-Mail Bugfix
- Fehler „sh: 1: /var/www/html/cron_backup_email.php: Permission denied" behoben
- Ursache 1: cron_backup_email.php fehlte im deploy/-Verzeichnis → Datei ergänzt
- Ursache 2: PHP_BINARY gibt in mod_php (Apache-Modul) einen leeren String zurück
  → exec() führte dann die .php-Datei direkt als Shell-Skript aus → Permission denied
- Fix: PHP_BINARY-Fallback in AdminActions::triggerBackupEmail() –
  bei leerem PHP_BINARY wird `which php` verwendet, Fallback auf /usr/local/bin/php
  (bekannter Pfad in offiziellen Docker-PHP-Images)

### Versionen
- APP_VERSION 2.10.10 → 2.10.11 (api.php, manifest.json)
- CACHE_VERSION bk-es-v118 → bk-es-v119 (sw.js)
- ?v=122 → ?v=123 (style.css, script.js in index.html)

## v2.10.10 – Lager KI-Scan Fix; Material KI-Scan Gesamtpreise

### Lager KI-Scan Fix
- Fehler „KI Antwort konnte nicht verarbeitet werden" behoben
- Ursache: Gemini 2.5+ Thinking-Modelle liefern mehrere Parts in der API-Antwort.
  parts[0] ist der Thinking-Token (thought:true), die eigentliche JSON-Antwort
  stand in parts[1] – der Code griff immer auf parts[0] zu → leerer/ungültiger Text.
- Fix: Ersten Non-Thought-Part in der Parts-Liste verwenden (modules/lager/backend/Module.php)
- Gleicher Fix in src/Handlers/AiActions.php (Material KI-Scan, Konsistenz)

### Material KI-Scan – Gesamtpreise in Erfassung
- Neue Spalte „Gesamt (€)" in der Positions-Tabelle des KI-Scan-Ergebnis-Dialogs
  (Menge × EK-Preis pro Position)
- Summenzeile „Gesamt (Auswahl):" am unteren Ende der Tabelle
- Live-Update: Gesamtsumme und Positionspreise werden sofort neu berechnet
  wenn Menge oder EK-Preis geändert wird
- Gesamtsumme berücksichtigt nur ausgewählte (✓) Positionen
- Beim Klick auf „Alle" / „Keine" wird die Summe ebenfalls sofort aktualisiert

### Versionen
- APP_VERSION 2.10.9 → 2.10.10 (api.php, manifest.json)
- CACHE_VERSION bk-es-v117 → bk-es-v118 (sw.js)
- ?v=121 → ?v=122 (style.css, script.js in index.html)

## v2.10.9 – Terminplanung in Wochenplanung; Gruppen bei Terminzuweisung; Backup-E-Mail Fix

### Terminplanung in der Wochenplanung
- Neuer Button „📋 Termine" im Footer der Wochenplanung
- Öffnet ein gestapeltes Overlay-Fenster (Wochenplanung bleibt geöffnet/sichtbar)
- Zeigt die Terminliste chronologisch sortiert mit Farb-Markierung, Zeit, Ersteller, Zugewiesen
- Klick auf Termin öffnet die bestehende Detail-Ansicht (wpShowTerminDetail)
- Button „+ Neuer Termin" nur für Benutzer mit canManageTermine-Berechtigung
- Bearbeiten-Button (✏) direkt in der Liste für Benutzer mit canManageTermine
- Nach Schließen: termineData + Wochenplanung werden automatisch aktualisiert
- Nach Speichern/Löschen eines Termins aus der Wochenplanung heraus: Overlay-Liste + WP-Ansicht
  werden sofort neu geladen – kein manuelles Aktualisieren nötig

### Gruppen bei Terminzuweisung
- Im Termin-Formular können nun neben Einzelpersonen auch Gruppen und „Alle" zugewiesen werden
- „🌐 Alle" auswählen: Termin gilt für alle Benutzer, hebt Einzelauswahl auf
- Gruppen-Checkboxen (aus Benutzerverwaltung) mit Farb-Punkt und Gruppenname
- Unterstützte Formate in `zugewiesen[]`: `'alle'`, `'gruppe:X'` (X = Gruppen-ID), `username`
- Wochenplanung & Tagesansicht zeigen Termine korrekt für alle zugewiesenen Gruppen-Mitglieder
- Gruppen werden beim Öffnen der Wochenplanung parallel mit geladen (wpGruppenData)

### Backup-E-Mail Testversand Fix
- Testversand schlug fehl, weil `php` nicht im PATH des Webserver-Prozesses lag
- Fix: `exec('php ...')` → `exec(PHP_BINARY . ' ...')` (PHP_BINARY = vollständiger Pfad zur laufenden Binary)
- Betrifft: `triggerBackupEmail()` in `src/Handlers/AdminActions.php`
- Reguläre SMTP-Testmail (Mailservereinstellung) war davon nicht betroffen und funktioniert weiterhin

### Versionen
- APP_VERSION 2.10.8 → 2.10.9 (api.php, manifest.json)
- CACHE_VERSION bk-es-v116 → bk-es-v117 (sw.js)
- ?v=120 → ?v=121 (style.css, script.js in index.html)

## v2.10.8 – Wochenplanung: Tagesansicht

### Neue Funktion: Tagesansicht in der Wochenplanung
- Toggle-Button „🕐 Tagesansicht" in der Navigationsleiste der Wochenplanung
- In der Tagesansicht wird ein Tag als Zeitstrahl-Tabelle dargestellt:
  - Zeilen = Mitarbeiter (wie Wochenansicht, gleiche Sichtbarkeitsfilter)
  - X-Achse = Zeitstrahl 06:00–18:00 Uhr
  - Linke Spalte „Ganztags": Baustellen-Einträge aus der Wochenplanung, Urlaub, Krank,
    Sonstig, Betrieb sowie ganztags Termine (keine Zeitangabe)
  - Rechte Spalte (Zeitstrahl): Arbeitszeiteinträge aus der Stundenerfassung (von/bis)
    werden als farbige horizontale Balken positioniert; Termine mit Uhrzeit ebenso
- Navigation in der Tagesansicht:
  - ‹ / › Buttons: einen Tag vor/zurück (überspringt Sonntag)
  - Tages-Dropdown: Mo–Sa der aktuellen Woche direkt auswählbar
  - „Heute"-Button springt zum aktuellen Tag
  - KW-Anzeige zeigt die aktuelle Kalenderwoche
- „📅 Wochenansicht"-Button wechselt zurück zur unveränderten Wochenansicht

### Technisch
- Neue State-Variablen: `wpViewMode` ('week'|'day'), `wpSelectedDay`
- Neue Funktionen: `wpSetViewMode()`, `wpNavDay()`, `wpJumpToDay()`,
  `wpTimeToPercent()`, `renderWochenplanungTag()`
- Neue CSS-Klassen: `.wp-day-title`, `.wp-day-table`, `.wp-day-allday-hd/-cell`,
  `.wp-day-timehd`, `.wp-day-hours-bar`, `.wp-day-hour-label`,
  `.wp-day-time-cell`, `.wp-day-timeline-track`, `.wp-day-grid-line`, `.wp-day-bar`
- Dark-Theme-Unterstützung für alle neuen Klassen

### Versionen
- APP_VERSION 2.10.7 → 2.10.8 (api.php, manifest.json)
- CACHE_VERSION bk-es-v115 → bk-es-v116 (sw.js)
- ?v=119 → ?v=120 (style.css, script.js in index.html)

## v2.10.7 – Mobile Schnellerfassung: Baustelle-Suche + Mehrfach-Positionen; Lager Fotoscan Fix

### Schnellerfassung (mobile.html)
- Baustelle-Select durch Suchfeld ersetzt (analog Stundenerfassung): Tippen filtert nach Name, Projektnr, Kunde
- Aktuelle Baustelle wird beim Öffnen automatisch vorausgewählt
- Material-Tab: Mehrere Positionen in einer Erfassung möglich ("+ Position hinzufügen")
  - Felder pro Zeile: Bezeichnung, Anzahl, Einheit, EK-Preis, Aufschlag
  - Zeilen einzeln löschbar; mindestens 1 Zeile bleibt immer erhalten
  - Toast zeigt Anzahl importierter Positionen
- Bestellungs-Tab: Mehrere Bestellpositionen in einer Erfassung (Bezeichnung, Menge, Einheit)
- Stunden-Tab: unverändert (1 Zeile, wie gewünscht)

### Lager Fotoscan (modules/lager/lager.js)
- Bug behoben: "Übernehmen"-Button schlug bei Gemini 2.5 Pro fehl wenn KI-Antwort
  Sonderzeichen in notiz-Feld enthielt (inline JSON-in-HTML-Attribut brach → JS-Fehler)
- Fix: state._aiJson (bereits gesetzt) statt inline JSON-Serialisierung im onclick-Handler
- lager.js Lader-Version v54 → v55

### Versionen
- APP_VERSION 2.10.6 → 2.10.7 (api.php, manifest.json)
- CACHE_VERSION bk-es-v114 → bk-es-v115 (sw.js)
- ?v=118 → ?v=119 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

## v2.10.6 – Zeitzone Europe/Berlin im Docker-Container

- Dockerfile.app: tzdata installiert, /etc/localtime → Europe/Berlin, /etc/timezone gesetzt
- Dockerfile.app: PHP-INI erweitert um date.timezone = Europe/Berlin
- docker-compose.yml: Umgebungsvariable TZ: "${TZ:-Europe/Berlin}" hinzugefügt
- .env.example: TZ=Europe/Berlin dokumentiert
- Behebt falsche Zeitstempel bei Uploads, Einträgen und Audit-Log (Container lief auf UTC)
- APP_VERSION 2.10.5 → 2.10.6 (api.php, manifest.json)
- CACHE_VERSION bk-es-v113 → bk-es-v114 (sw.js)
- ?v=117 → ?v=118 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

ACHTUNG DEPLOYMENT: Nach dem Update muss der Container neu gebaut werden:
  docker compose up -d --build

## v2.10.5 – Backup-E-Mail-Einstellungen nach Verwaltung/Allgemein verschoben

- Backup-E-Mail-Einstellungen aus WhatsApp-Modal entfernt (war nicht erreichbar ohne WhatsApp-Konfiguration)
- Backup-E-Mail-Einstellungen neu unter Verwaltung → Allgemein → "📦 Automatisches Backup per E-Mail"
- loadErinnerungSettings() wird jetzt auch beim Öffnen von Verwaltung/Allgemein aufgerufen
- APP_VERSION 2.10.4 → 2.10.5 (api.php, manifest.json)
- CACHE_VERSION bk-es-v112 → bk-es-v113 (sw.js)
- ?v=116 → ?v=117 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

## v2.10.4 – Oberpositionen / Unterpositionen in Angeboten und Rechnungen

- Neue Hierarchiestruktur bei Positionen: posTyp='gruppe' = Oberposition mit 1., 2. Nummerierung
- Unterpositionen erhalten X.Y-Nummerierung (1.1, 1.2, 2.1 usw.)
- Gesamtpreis einer Oberposition wird automatisch aus Unterpositionen berechnet (anzeige + PDF)
- Neuer Button "+ Gruppe (Oberposition)" im Positions-Editor
- "+UP" Button in jeder Gruppe fügt eine Unterposition direkt in die Gruppe ein
- Gruppen können mit ↑ ↓ verschoben werden (inkl. aller Unterpositionen)
- Rückwärtskompatibel: bestehende Angebote ohne id/parentId laden weiterhin als Standalone
- previewRechnung(): Hierarchie-Rendering, Gruppen-Kopfzeilen in Blau, Unterpositionen eingerückt
- _generateAngebotPdf(): identisches Hierarchie-Rendering im PDF
- saveRechnungForm(): collectPositionen() statt DOM-Queryselector-Loop
- updateRechnungTotals(): berechnet Gruppengesamtsummen + setzt Pos-Nummern (1., 1.1, 2. …)
- APP_VERSION 2.10.3 → 2.10.4 (api.php, manifest.json)
- CACHE_VERSION bk-es-v111 → bk-es-v112 (sw.js)
- ?v=115 → ?v=116 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

## v2.10.3 – Automatisches Backup per E-Mail

- Neues Cron-Skript cron_backup_email.php: erstellt ZIP (baukalkulation.json + database.sqlite)
  und sendet ihn per SMTP; Lock-File-Zyklus (täglich/wöchentlich/monatlich)
- Einstellungen unter Verwaltung/Allgemein → Automatische Erinnerungen:
  Aktivieren, Empfänger (leer = Absende-E-Mail), Zyklus, Uhrzeit
- Button "▶ Backup-E-Mail jetzt senden" für manuellen Test (--force, umgeht Lock)
- MailService::send() um optionalen $attachMime-Parameter erweitert (rückwärtskompatibel)
- AdminActions: loadErinnerungSettings + saveErinnerungSettings um 4 Backup-Keys erweitert
- AdminActions: neue Methode triggerBackupEmail()
- Neuer API-Endpunkt: trigger_backup_email
- Validierung: backup_email_empfaenger muss gültige E-Mail sein (oder leer)
- Cron-Einrichtung: täglich auf konfigurierte Uhrzeit (z.B. 0 7 * * * php cron_backup_email.php)
- APP_VERSION 2.10.2 → 2.10.3 (api.php, manifest.json)
- CACHE_VERSION bk-es-v110 → bk-es-v111 (sw.js)
- ?v=114 → ?v=115 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

## v2.10.2 – Audit-Log Download (Admin/Master)

- Verwaltung/Allgemein: neue Sektion "System-Protokoll (Audit-Log)"
- Admin/Master können Audit-Log als CSV (UTF-8 BOM, Excel-kompatibel) oder TXT herunterladen
- Backend: AdminActions::downloadAuditLog() mit Auth::requireRole('admin','master')
- Neuer API-Endpunkt: download_audit_log (POST)
- Frontend: downloadAuditLog(format) via dynamischem Form-Submit (Session-Cookie)
- APP_VERSION 2.10.1 → 2.10.2 (api.php, manifest.json)
- CACHE_VERSION bk-es-v109 → bk-es-v110 (sw.js)
- ?v=113 → ?v=114 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

## v2.10.1 – VDE 0100: neue Protokollfelder, Abgang ohne RCD, Mobile-Fix

- APP_VERSION 2.10.0 → 2.10.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v108 → bk-es-v109 (sw.js)
- ?v=112 → ?v=113 (style.css, script.js in index.html + sw.js PRECACHE_URLS)
- vde0100.js/css ?v=88 → ?v=89 (script.js loadOptionalModule + mobile.html)
- mobile.html hatte noch ?v=87 für vde0100 → korrigiert auf ?v=89

### §A: VDE 0100 – Neue Protokollfelder (vde0100.js, backend/Module.php, Vde0100Database.php)
- 3 neue Felder im Protokoll-Formular und in der DB:
  * Kundenanschrift (textarea, aus Stammdaten vorbelegt)
  * Anlagenanschrift (textarea, aus Baustellen-Stammdaten vorbelegt)
  * Netzbetreiber (text)
- Bezeichnung "Schutzmaßnahme" → "Netzform"
- Bezeichnung "Erstellt von" → "Geprüft von"
- Rb mΩ-Spalte aus Stromkreistabelle entfernt
- PDF-Ausgabe entsprechend angepasst
- DataActions.php Backup-Restore: vde0100_protokolle auf 28 Spalten erweitert
- Kunde-Select: onchange-Handler _onKundeChange / _onBaustelleChange
  (belegen Kundenanschrift/Anlagenanschrift vor, wenn Feld leer)

### §B: VDE 0100 – Abgang ohne RCD (vde0100.js, backend/Module.php)
- Abgänge (Sicherungen) können jetzt direkt am Verteiler angelegt werden,
  ohne einen FI/RCD-Block voraussetzen zu müssen
- Button "+ Abgang (ohne RCD)" in der Verteileransicht
- Backend: sicherungSave() akzeptiert rcdId=null + verteilerId aus Body
- Backend: protokollLoad() lädt sicherungen WHERE rcdId IS NULL separat als
  `sicherungenDirekt[]` pro Verteiler
- Frontend: renderVerteiler() zeigt Abschnitt "Abgänge ohne RCD" wenn vorhanden
- saveSicherungField(): sucht auch in sicherungenDirekt, sendet verteilerId statt rcdId

## v2.10.0 – Gruppen + Zuweisung für Dashboard & Schnellnotizen

- APP_VERSION 2.9.29 → 2.10.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v107 → bk-es-v108 (sw.js)
- ?v=111 → ?v=112 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §A: Gruppen-System (src/Handlers/GruppenActions.php – neu)
- Neue Tabellen: `gruppen` (id, name, farbe, erstelltAm, erstelltVon),
  `gruppen_mitglieder` (id, gruppen_id, username, UNIQUE)
- Migration in `src/Database.php runMigrations()` (v2.10.0-Block)
- Routen: `load_gruppen`, `load_gruppen_for_user`, `save_gruppe`, `delete_gruppe`
- Nur Admin/Master können Gruppen anlegen, bearbeiten und löschen

### §B: Zuweisung für Dashboard-Einträge erweitert (DashboardActions.php)
- `zugewiesen_an`-Feld bleibt backward-kompatibel:
  '' = niemand, 'alle' = alle MA, 'username' = User, 'gruppe:N' = Gruppe N
- `load()`: normale User sehen jetzt auch Items wo `zugewiesen_an='alle'`
  oder `zugewiesen_an='gruppe:N'` (wenn sie Mitglied sind)
- Frontend: "Zuweisen an"-Dropdown zeigt Alle/Benutzer/Gruppen-Optgroups
  (nur für Admin/Master sichtbar); Badges zeigen lesbaren Namen (📢/👤/👥)

### §C: Zuweisung für Schnellnotizen (PlanActions.php)
- Neue Spalte `schnellnotizen.zugewiesen_an TEXT DEFAULT ''` (Migration)
- `saveSchnellnotiz()`: Admin/Master können `zugewiesen_an` setzen
- `loadSchnellnotizen()`: normale User sehen jetzt auch Notizen die ihnen
  direkt, via 'alle' oder via Gruppe zugewiesen sind
- `openSchnellnotizDesktopModal()`: Admin/Master sehen "Zuweisen an"-Dropdown

### §D: DataActions.php – Backup/Restore erweitert
- `_restoreFromSqlite()`: `gruppen` und `gruppen_mitglieder` Tabellen ergänzt

### §E: Frontend-Änderungen (script.js)
- `let gruppenData = []`: globale Gruppen-Variable, beim Login für alle User geladen
- Gruppen-Sektion im Benutzerverwaltungs-Modal: Liste mit Edit/Delete-Buttons,
  "Neue Gruppe"-Button öffnet dynamisches Formular mit Mitglieder-Checkboxes
- `resolveZuweisung(val)`: Hilfsfunktion für Badge-Label (alle/user/gruppe:N)
- `renderSchnellnotizCard()`: zeigt Zuweisungs-Badge
- Backup/Restore: gruppen-Tabellen in DataActions.php ergänzt

## v2.9.29 – Datensicherung: Restore vollständig (VDE + DIN1090), docker-entrypoint Berechtigungsfix

- APP_VERSION 2.9.28 → 2.9.29 (api.php, manifest.json)
- CACHE_VERSION bk-es-v106 → bk-es-v107 (sw.js)
- ?v=110 → ?v=111 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §A: Bugfix – `restore()` in DataActions.php (Server-seitiger Backup-Restore)
- Kritische Asymmetrie behoben: `restore()` nutzte einen veralteten inline
  `$sectionRestore`-Block, der nach `aufmass_position` endete → VDE 0100 (6 Tabellen)
  und DIN EN 1090 (9 Tabellen) wurden beim Restore aus gespeicherten Server-Backups
  nicht wiederhergestellt (beim ZIP-Upload via `backupUpload()` war es korrekt).
- Inline-Block durch `$this->_restoreFromSqlite($bDir . 'database.sqlite')` ersetzt
  → beide Restore-Pfade nutzen jetzt dieselbe vollständige Methode
- Abwärtskompatibel: Backups ohne VDE/DIN1090-Tabellen schlagen nicht fehl
  (`_restoreFromSqlite` überspringt fehlende Tabellen per try/catch)

### §B: Bugfix – docker-entrypoint.sh Dateiberechtigungen
- `migrate.php` lief als root und erstellte `database.sqlite` als `root:root 644`
- Apache (www-data) konnte nicht schreiben → Setup-Wizard schlug lautlos fehl
  → betraf jede Erstinstallation auf jedem System
- Fix: nach `migrate.php` werden alle Dateien in `data/` auf `www-data:www-data` gesetzt:
  `chown -R www-data:www-data /var/www/html/data/`

### §C: tests/smoketest_2_9_29.php – Neuer Integrationstest
- Versionskonsistenz (APP_VERSION, manifest, sw.js, CACHE_VERSION)
- `restore()` Refactoring: `_restoreFromSqlite()` wird aufgerufen (kein inline Block mehr)
- VDE + DIN1090 Tabellen in `_restoreFromSqlite()` vorhanden
- Abwärtskompatibilität: Backup ohne optionale Tabellen → kein Fehler

## v2.9.28 – KI-Scan Lieferschein-Kategorie, Gleitzeit optional, Smoketest

- APP_VERSION 2.9.27 → 2.9.28 (api.php, manifest.json)
- CACHE_VERSION bk-es-v105 → bk-es-v106 (sw.js)
- ?v=109 → ?v=110 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §A: KI-Materialscan – Lieferschein / Bezeichnung (script.js, mobile.html)
- Neues Kopf-Textfeld „📋 Lieferschein / Bezeichnung" im Ergebnis-Modal
- Alle importierten Positionen erhalten automatisch die gleichnamige matKategorie
  (bestehende Kategorie wird per case-insensitivem Vergleich gesucht oder neu angelegt)
- `showKiScanResults()`: Lieferschein-Input-Feld eingefügt (über Alle/Keine-Links)
- `_kiScanImport()`: Lieferschein-Kategorie-Block nach `let count = 0;` deklariert;
  `kategorieId: lieferscheinKatId` statt `null` in `b.material.unshift()`
- Identische Änderungen in mobile.html portiert

### §B: Gleitzeit optional – `gleitzeit_enabled` (src/Auth.php, script.js)
- `Auth::loadSettings()`: Neuer Default `'gleitzeit_enabled' => true`
  (bestehende Installationen ohne gespeicherten Wert bleiben unverändert aktiv)
- `renderAllgemeinSettings()` (Erweiterte-Zeiterfassung-Box): Neue Checkbox
  „Gleitzeitkonto aktivieren (kumulierter Jahressaldo)" mit erklärendem Hinweis
- `renderSeDesktop()`: `gleitzeitAktiv`-Flag; Gleitzeit-KPI-Card nur wenn aktiv;
  Gleitzeitkonto-Buchungen-Block im Jahresausdruck unter Guard
- Admin-Stundenauswertung (`renderStundenauswertung`): Gleitzeit-Saldo + Buchungs-Schaltfläche
  nur wenn `gleitzeitAktivSa`; Gleitzeitkonto-Spalte in Tabellenkopf bedingt
- Admin-Druckauswertung: `gleitzeitAktivPrint`-Guard für Saldo in Titelzeile + Buchungen
- Auswertungs-Tab Mitarbeiter: Gleitzeitkonto-Spalte nur wenn aktiv

### §C: tests/smoketest_2_9_28.php – Neuer Integrationstest
- 9 Sektionen: Versionskonsistenz, Neue Routen, gleitzeit_enabled-Default,
  SMTP-Defaults, Baustellen-CRUD via DataService (inkl. matKategorien),
  Cents-Randwerte, Gleitzeitkonto-Buchungen-CRUD, Modul-Klassen, Backup-Dir schreibbar

## v2.9.27 – E-Mail-Versand: VDE-Protokoll, Rechnung (ZUGFeRD), Angebot, DIN 1090

- APP_VERSION 2.9.26 → 2.9.27 (api.php, manifest.json)
- CACHE_VERSION bk-es-v104 → bk-es-v105 (sw.js)
- ?v=108 → ?v=109 (style.css, script.js in index.html + sw.js PRECACHE_URLS)
- vde0100.js / vde0100.css: ?v=86 → ?v=87 (script.js loadOptionalModule + mobile.html)
- din1090.js / din1090.css: ?v=54 → ?v=55 (script.js loadOptionalModule + mobile.html)

### §A: src/Auth.php – SMTP-Defaults
- Neue Standardwerte in `$defaults`: smtp_host, smtp_port (587), smtp_user,
  smtp_pass, smtp_from_email, smtp_from_name, smtp_security (tls)

### §B: src/Services/MailService.php – Neu
- PHPMailer-Wrapper: `MailService::send($cfg, $to, $toName, $subject, $htmlBody, $attachBytes, $attachName)`
- Unterstützt TLS, SSL, none; wirft RuntimeException bei Fehler

### §C: modules/vde0100/backend/Module.php – getPdfData()
- Neue public Methode `getPdfData(int $id): array` → liefert ['pdfData', 'fileName']

### §D: src/Handlers/EmailActions.php – Neu
- 5 Methoden: sendVde(), sendRechnung(), sendAngebot(), sendDin1090(), testSmtp()
- Verwendet MailService + ZugferdService + Dompdf + AuditService

### §E: api.php – Neue Routen
- send_vde, send_rechnung, send_angebot, send_din1090, test_smtp → EmailActions

### §F: script.js
- `renderAllgemeinSettings()`: Neue SMTP-Sektion (admin-only) mit 7 Feldern +
  "Verbindung testen"-Button → `testSmtpConnection()`
- `testSmtpConnection()`: POST api.php?action=test_smtp
- `openEmailDialog(to, subject, onSend)`: Inline-Overlay-Dialog mit To/Betreff
- `_doEmailSend()`, `sendRechnungEmail(id, to, subject)`, `sendAngebotEmail(id, to, subject)`
- ✉-Button in Rechnungen- und Angebote-Tabelle

### §G: modules/vde0100/vde0100.js
- `emailPdf(id)`: Sendet Protokoll-PDF per API-Route send_vde → openEmailDialog
- ✉ E-Mail-Button in Protokoll-Header (neben PDF-Button)

### §H: modules/din1090/din1090.js
- `din1090EmailPDF()`: Generiert PDF via html2pdf → base64 → send_din1090
- ✉-Button neben "Protokoll PDF"-Button

### §I: composer.json
- phpmailer/phpmailer ^6.0 hinzugefügt

## v2.9.26 – Datensicherungs-Modal: Download, manuelle Sicherung, Upload

- APP_VERSION 2.9.25 → 2.9.26 (api.php, manifest.json) [in v2.9.27 weitergebumpt]
- CACHE_VERSION bk-es-v103 → bk-es-v104 (sw.js) [in v2.9.27 weitergebumpt]
- ?v=107 → ?v=108 (in v2.9.27 weitergebumpt zu ?v=109)

### §A: src/Handlers/DataActions.php
- `listBackups()`: glob-Pattern `????-??-??*` (vorher `????-??-??`)
- `restore()`: Sanitize-Regex erlaubt jetzt `_` und Buchstaben in Ordnernamen
- NEU `backupCreate()`: Erstellt `BACKUP_DIR/YYYY-MM-DD_HH-ii[_slug]/` mit JSON+SQLite,
  pruned auf BACKUP_MAX*2, optionaler Name (Slug)
- NEU `backupDownload()`: Streamt bestehendes oder Live-Backup als ZIP-Download
- NEU `backupUpload()`: ZIP hochladen, baukalkulation.json + optionale SQLite wiederherstellen
- NEU `_restoreFromSqlite(string $path)`: Privater SQLite-Restore aus ZIP-Extrakt

### §B: api.php – Neue Routen
- backup_create, backup_download, backup_upload → DataActions

### §C: index.html
- Backup-Modal-Titel → "Datensicherung", Footer-Button → "↻ Datensicherung"
- Modal erweitert um 4 Sektionen: Herunterladen, Manuell erstellen,
  Hochladen & Wiederherstellen, Wiederherstellungspunkte

### §D: script.js
- `openBackupModal()`: Liste zeigt jetzt Datum+Name, 💾-Button je Eintrag
- NEU `downloadBackup(date)`: fetch → Blob → a.click()
- NEU `createManualBackup()`: POST backup_create, Modal-Refresh
- NEU `uploadAndRestoreBackup()`: FormData POST backup_upload, appData-Restore

## v2.9.25 – Bugfix: VDE Messgerät-Felder nach Cache-Invalidierung sichtbar

- APP_VERSION 2.9.24 → 2.9.25 (api.php, manifest.json)
- CACHE_VERSION bk-es-v102 → bk-es-v103 (sw.js)
- ?v=106 → ?v=107 (style.css, script.js in index.html + sw.js PRECACHE_URLS)
- vde0100.js / vde0100.css: ?v=85 → ?v=86 (script.js loadOptionalModule + mobile.html)

### Root Cause
- In v2.9.19 wurden die Messgerät-Felder (Hersteller, Typ, Kalibrierung) in vde0100.js
  und Vde0100Database.php hinzugefügt. Der Cache-Busting-Parameter für vde0100.js/css
  (getrennt von script.js/style.css) wurde dabei NICHT mitgezogen — blieb auf ?v=85.
- Browser/PWA-Cache lieferte die alte vde0100.js ohne Messgerät-Felder.

### §F: script.js + mobile.html – vde0100.js/css Cache-Buster
- `loadOptionalModule(...)` in script.js: vde0100.js?v=85 → ?v=86, vde0100.css?v=85 → ?v=86
- mobile.html VDE-Modul-Loader: vde0100.js?v=85 → ?v=86, vde0100.css?v=85 → ?v=86

### Regel ergänzt in Änderungshinweise.txt
- Bei Änderungen an Modul-JS/CSS (modules/<name>/<name>.js/css) immer AUCH den
  modulspezifischen ?v=NN-Parameter in script.js (loadOptionalModule) und mobile.html
  anheben — unabhängig vom globalen script.js/style.css-Cache-Buster.

## v2.9.24 – KI Scan: Originaldokument in Dateien ablegen

- APP_VERSION 2.9.23 → 2.9.24 (api.php, manifest.json)
- CACHE_VERSION bk-es-v101 → bk-es-v102 (sw.js)
- ?v=105 → ?v=106 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §P: script.js + mobile.html – _kiScanImport / showKiScanResults
- Im Ergebnis-Modal: Checkbox "Originaldokument ablegen in" + Kategorie-Select
  (Optionen: Eingangsbelege [Standard], Ausgangsbelege, Angebote, Dateien, Sonstiges).
  Checkbox ist standardmäßig aktiviert.
- `_kiScanImport()` in script.js: nach Positions-Import, wenn Checkbox aktiv:
  POST `api.php?action=upload_file` mit `window._kiScanPendingFile` + `baustelleId` + gewählter Kategorie;
  danach `loadBaustelleFiles()` aufgerufen. Funktion auf `async` umgestellt.
- `_kiScanImport()` in mobile.html: identische Logik, `loadDateienMobile()` statt `loadBaustelleFiles()`.

## v2.9.23 – KI Materialscan in Mobile-App

- APP_VERSION 2.9.22 → 2.9.23 (api.php, manifest.json)
- CACHE_VERSION bk-es-v100 → bk-es-v101 (sw.js)
- ?v=104 → ?v=105 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §P: mobile.html – KI Materialscan portiert
- **🤖 KI-Button** in der Material-Topbar ergänzt (id="btnKiScanMat"), sichtbar nur für `canEditMaterial`-User.
- Permissions-Block: `btnKiScanMat.style.display` analog zu `btnAddMaterial` gesetzt.
- `openKiScanModal()`: Upload-Modal mit Dropzone, Bildvorschau, 10-MB-Guard.
  Anpassung: `modal-close` statt `close-btn` (mobile.html-Konvention).
- `_kiScanDrop()`: Drag & Drop Handler.
- `_kiScanFileChosen()`: Datei-Validierung + Vorschau.
- `_kiScanRun()`: FileReader→Base64, POST `api.php?action=ai_scan_material`, Ladezustand.
- `showKiScanResults(items, hint)`: Ergebnis-Modal mit editierbarer Tabelle.
  Anpassung: `modal-close` statt `close-btn`; einfache Tabelle statt `data-table`-Klasse.
- `_kiScanUpdateBtn()`: Aktualisiert Import-Button-Text + Zähler.
- `_kiScanImport()`: Fügt gecheckte Positionen per `unshift()` in `b.material` ein,
  ruft `saveData()` + `renderMaterial()` auf.
  Anpassung: `showToast()` statt `showNotification()`; kein `recalc()` (mobile hat keines).

## v2.9.22 – VDE PDF Bestätigungsabschnitt Layout-Fix

- APP_VERSION 2.9.21 → 2.9.22 (api.php, manifest.json)
- CACHE_VERSION bk-es-v99 → bk-es-v100 (sw.js)
- ?v=103 → ?v=104 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §P: modules/vde0100/backend/Module.php – Bestätigungsabschnitt Seite-2-Fix
- Bestätigungsabschnitt: `margin-top:36mm` → `page-break-before:always; padding-top:36mm; margin-top:0`
- Ursache: In Dompdf ist `position:fixed; top:0` relativ zur Druckfläche (innerhalb @page-Ränder),
  nicht zur physischen Seite. Die `padding-top:36mm` des Content-Wrappers gilt nur auf Seite 1.
  Auf Seite 2 beginnt Content bei 0mm der Druckfläche = direkt unter dem 33mm-Fixed-Header.
  `margin-top:36mm` schob den Block auf Seite 2, aber ohne eigene Clearance überlappt er den Header.
- Fix: `page-break-before:always` erzwingt Seitenumbruch; `padding-top:36mm` schafft 36mm Clearance
  unterhalb des fixen Headers auf Seite 2 (entspricht Page-1-Verhalten des Content-Wrappers).

## v2.9.21 – KI Dokumentenerkennung (Materialimport)

- APP_VERSION 2.9.20 → 2.9.21 (api.php, manifest.json)
- CACHE_VERSION bk-es-v98 → bk-es-v99 (sw.js)
- ?v=102 → ?v=103 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §P: src/Handlers/AiActions.php – NEU
- Klasse `AiActions` mit Methode `scanMaterial()`.
- MIME-Whitelist: image/jpeg, image/png, image/webp, image/gif, application/pdf.
- Base64-Größengrenze: ~10 MB (14 MB Base64), Regex-Validierung.
- cURL-POST an Gemini API mit `response_mime_type: application/json`.
- Fallback auf `file_get_contents` wenn cURL nicht verfügbar.
- JSON-Antwort sanitizen: strip_tags, mb_substr, Bounds-Checks auf Anzahl/EK.
- Markdown-Fence-Stripping als Fallback falls Modell doch Backticks liefert.
- Fehlerbehandlung: kein API-Key (400), nicht erreichbar (502), Gemini-Fehler (502).

### §P: api.php – Route ai_scan_material
- `'ai_scan_material' => ['App\\Handlers\\AiActions', 'scanMaterial']` nach `material_top_used`.

### §P: script.js – KI-Scan Frontend
- **🤖 KI Scan-Button** im Materialabschnitt-Header (neben Kupfer-Button), sichtbar für alle `canEdit`-User.
- `openKiScanModal()`: Datei-Dropzone + Click-Upload, Bildvorschau, 10-MB-Guard.
- `_kiScanDrop()`: Drag & Drop Handler.
- `_kiScanFileChosen()`: Datei-Validierung + Vorschau.
- `_kiScanRun()`: FileReader→Base64, POST `ai_scan_material`, Ladezustand.
- `showKiScanResults(items, hint)`: Ergebnis-Modal mit editierbarer Tabelle.
  Spalten: ☐ Bezeichnung | Menge | Einheit | EK (€), alle Felder editierbar.
  Alle Checkboxen initial aktiv; "Alle / Keine"-Toggle; Live-Zähler.
- `_kiScanUpdateBtn()`: Aktualisiert Import-Button-Text + Zähler.
- `_kiScanImport()`: Fügt gecheckte, editierte Positionen per `unshift` in `b.material`
  ein, ruft `saveData()`, `renderMatRows()`, `recalc()`, `showNotification()` auf.

## v2.9.20 – JavaScript Error-Handler Verbesserung

- APP_VERSION 2.9.19 → 2.9.20 (api.php, manifest.json)
- CACHE_VERSION bk-es-v97 → bk-es-v98 (sw.js)
- ?v=101 → ?v=102 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §P: script.js – JS Error-Handler
- Hilfsfunktion `_showErrBanner(text)` extrahiert (kein Duplikat-Code mehr)
- Stack-Trace (`err?.stack`) wird im Banner angezeigt
- Browser-UA (erste 80 Zeichen) wird beim ersten Fehler mitgeloggt
- ×-Schließen-Button im Banner ergänzt
- `window.onunhandledrejection` nutzt jetzt ebenfalls Stack-Trace

## v2.9.19 – Druckqualität, VDE-Fixes, Messgerät-Felder

- APP_VERSION 2.9.18 → 2.9.19 (api.php, manifest.json)
- CACHE_VERSION bk-es-v96 → bk-es-v97 (sw.js)
- ?v=100 → ?v=101 (style.css, script.js in index.html + sw.js PRECACHE_URLS)

### §P: style.css – Dark-Mode-Print-Fix
- Neuer `@media print`-Block am Dateiende: setzt alle `body.dark-theme`-Variablen
  auf Light-Werte zurück und erzwingt `color-scheme: light !important` auf `*`.
- Verhindert dunkle/unleserliche Ausdrucke wenn Dark-Mode aktiv oder OS-Ebene
  `prefers-color-scheme: dark` aktiv ist.

### §P: script.js – Dark-Mode-Print-Fix (alle 7 Print-Templates)
- `<html style="color-scheme:light">` in allen inline Print-HTML-Templates:
  Bautagebuch-Export, Bautagebuch-Druck, Rechnung/Angebot, Arbeitszeiten,
  Stundenauswertung, Zeitübersicht, Kaufm. Auswertung, Mitarbeiter-Auswertung.

### §P: modules/vde0100/Vde0100Database.php – Messgerät-Felder DB-Migration
- 3 neue Spalten in vde0100_protokolle per ALTER TABLE (try/catch):
  `messgeraet_hersteller`, `messgeraet_typ`, `messgeraet_kalibrierung`

### §P: modules/vde0100/vde0100.js – Messgerät-Felder Frontend
- 3 neue Formfelder in VDE Kopfdaten-Formular nach Prüfdatum:
  "Hersteller Messgerät", "Typ Messgerät", "Letzte Kalibrierung"
- `saveProtokolllForm()` um die 3 neuen Felder erweitert.

### §P: modules/vde0100/backend/Module.php – VDE PDF 4 Fixes
- Fix 1 (Dark-Mode): `color-scheme: light` zu PDF-body-CSS hinzugefügt.
- Fix 2 (Footer-Overlap): Content-Wrapper Bottom-Padding 11px → 18mm, sodass
  der position:fixed Footer (14mm) keinen Content mehr verdeckt.
- Fix 3 (Messgerät): UPDATE- und INSERT-SQL um neue Felder erweitert.
  In `buildPdfHtml()`: Messgerät-Variablen extrahiert, in linker Info-Tabelle
  unter Nennstrom angezeigt (nur wenn nicht leer).
- Fix 4 (Bestätigung): "Prüfdatum:"-Label-div im Bestätigungsabschnitt entfernt –
  nur noch Datumswert + Unterlinien-Label "Datum der Prüfung" angezeigt.

## v2.9.18 – P0 Geld-Präzision (Phase A): Float-Robustness via money_round()

- APP_VERSION 2.9.17 → 2.9.18 (api.php, manifest.json)
- CACHE_VERSION bk-es-v95 → bk-es-v96 (sw.js)
- ?v=99 → ?v=100 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### Hintergrund
Geldbeträge sind im Schema aktuell REAL (Float). SQL-Aggregate (z. B.
Aufmass `SUM(menge * einzelpreis)`) und PHP-Kalkulationen (Aufschlag,
Stundenpreis * Stunden) erzeugen IEEE-754-Rauschen wie 14.989999999999999.
Das führt zu sichtbaren 0.01-EUR-Differenzen in Summen, Anzeigen und
Exports. Eine echte INTEGER-Cents-Migration (Plan B) ist separat geplant
und erfordert eine Wartungssession mit Container-Zugriff.

Diese Phase A führt einen zentralen Helper ein, der Float-Rauschen an
ALLEN Lese-Stellen und schreibseitigen API-Grenzen normalisiert. Frontend,
DB-Schema und API-Format bleiben unverändert.

### §P: src/Helpers.php – money_round() Helper
- Neue globale Funktion `money_round(mixed $v): float`
- Macht `round((float)$v, 2)`, mit Null-/Leer-Schutz.
- Per `files`-Autoload in allen Handlern und Modulen verfügbar.

### §P: src/DataService.php
- loadAllData(): preis, fixkosten, ek, aufschlag aus pauschalen /
  stunden_katalog / material_katalog werden über money_round() ausgegeben.
- saveAllData(): identische Normierung beim INSERT, damit das Frontend
  beim nächsten Lesen exakt die gleichen Werte zurückbekommt.

### §P: src/Handlers/BaustelleActions.php
- Pauschalen-Liste für Archiv-Snapshots wird normiert.

### §P: src/Handlers/ExportActions.php
- Globale Pauschalen werden direkt nach SELECT normiert, bevor sie in
  IN-FORM-/Preisexport-Kalkulationen einfließen. Die bereits vorhandenen
  `round($ep, 2)` / `round($gp, 2)` an Display-Stellen bleiben erhalten.

### §P: src/Handlers/ZeiterfassungActions.php
- Auto-Import von Stunden: stundenpreis und fixkosten aus stunden_katalog
  werden vor der Speicherung im Baustellen-JSON normiert.

### §P: modules/aufmass/backend/Module.php
- listAufmasse(): `SUM(menge * einzelpreis)` wird per money_round() in der
  PHP-Response normiert (genau hier entsteht die größte Drift).
- loadAufmass(): einzelpreis und ek werden je Position normiert.

### §P: modules/lager/backend/Module.php
- artikelList(): ek_preis und vk_preis werden normiert.

### Bewusst NICHT angefasst (kein Funktionsbruch)
- Frontend (script.js, modules/*.js): Frontend erhält weiterhin Euro-
  Dezimal-Werte mit ≤2 NK. Keine Änderung nötig.
- DB-Schema: bleibt REAL. Schema-Migration ist Plan B.
- src/Handlers/OciActions.php: nutzt bereits `round(.., 4)` für die
  Preis-pro-Stück-Division (PRICEUNIT). 4 NK sind hier absichtlich.
- modules/aufmass/aufmass.js parseFormel(): `round(.., 2)` ist Frontend-
  Verantwortung und bereits via `fmtEur(...maximumFractionDigits:2)`
  abgedeckt.
- src/Services/AuditService.php, PlanActions, CrudActions etc.: keine
  direkte Geldberechnung.

### Validierung
- IDE-Diagnostics aller geänderten Dateien: clean.
- PHP/Docker lokal nicht verfügbar → nach Deploy im Container:
    docker compose exec app php tests/smoketest_2_1_0.php
- Manuelle Prüfung empfohlen: ein bestehendes Aufmass mit mehreren
  Positionen öffnen → Summenanzeige darf sich gegenüber v2.9.17 maximal
  um Rundung der zweiten Nachkommastelle unterscheiden, niemals um >1 Cent.

## v2.9.17 – Security/Code-Review: Quickwins (keine Funktionsänderung)

- APP_VERSION 2.9.16 → 2.9.17 (api.php, manifest.json)
- CACHE_VERSION bk-es-v94 → bk-es-v95 (sw.js)
- ?v=98 → ?v=99 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### §S: reset_next_build.php nach scripts/ verschoben + Klartext-PW-Ausgabe entfernt
- Datei lag im Web-Root und gab das initiale Systemadmin-Passwort im Klartext
  in der CLI aus ("Fixed login: Systemadmin / Stadler2580!").
- Neu: scripts/reset_next_build.php (außerhalb des direkt aufrufbaren Bereichs
  bleiben Web-Aufrufe weiterhin durch den CLI-Guard verhindert).
- $baseDir nutzt jetzt dirname(__DIR__) statt __DIR__, damit relative Pfade
  weiterhin auf data/ im Projekt-Root zeigen.
- CLI-Ausgabe weist nur noch auf den Standard-Benutzernamen hin; das
  Initial-Passwort steht in der internen Dokumentation und muss beim ersten
  Login geändert werden (mustChangePassword=1 ist bereits aktiv).
- Hinweis: Das initial gesetzte Default-Passwort in Auth::ensureSystemadmin()
  wurde bewusst NICHT geändert, da bestehende Installationen davon abhängen.
  Eine Umstellung auf zufällige Initial-Passwörter wird separat geplant
  (siehe internen Plan – Phase 1, P0).

### §S: mime_content_type() → finfo (3 Stellen)
- src/Handlers/FileActions.php – downloadFile(): finfo für ausgehende
  Content-Type-Header (vermeidet die als deprecated geltende Funktion und
  liefert robustere Ergebnisse).
- src/Services/ZugferdService.php – Logo-Embedding in PDF/ZUGFeRD.
- src/Handlers/AdminActions.php – Logo-Ausgabe als statischer Endpunkt.
- Verhalten identisch: Fallback auf Extension-Mapping bzw.
  'application/octet-stream' bleibt erhalten.

### §H: tests/smoketest_1_8_0.php entfernt
- Veralteter Smoketest für v1.8.0; durch tests/smoketest_2_1_0.php (deckt
  aktuelle Module Lager/Aufmaß ab) abgelöst.

### §H: .gitignore erweitert
- Neue Ausschlüsse: .env.*.local, *.pem/*.key/*.crt/*.csr, .vite/, *.bak,
  *.backup, *.orig, *.old, *~

### §I: Verifizierte Audit-Befunde, die KEINE Änderung erforderten
- modules/aufmass/aufmass.js parseFormel() – `new Function(...)` ist sicher,
  da der Whitelist-Regex /^[\d.+\-*/() \t]+$/ keinerlei Buchstaben/Identifier
  zulässt; vermeintliche Injection-Payloads scheitern bereits am Whitelist-
  Check (zusätzlich: Klammer-Balance-Check). Keine Änderung.
- src/Handlers/PlanActions.php – md5() für ICS-UIDs ist KEINE Krypto-Nutzung,
  sondern erzeugt STABILE Event-Identifier. Würde man random_bytes() nutzen,
  würden Outlook/Apple Calendar bei jedem Export Duplikate anlegen.
  Keine Änderung.
- src/Handlers/ExportActions.php / modules/vde0100/backend/Module.php –
  error_reporting(E_ALL & ~E_DEPRECATED) ist hier SCOPED (try/finally für
  dompdf-Aufrufe) und damit korrekt. Keine Änderung.
- api.php / din1090_api.php – Globales ~E_DEPRECATED ist mit dem
  Error-Handler-Filter für E_DEPRECATED konsistent; entfernen bringt keinen
  Sicherheitsgewinn. Keine Änderung.

### §I: Größere Punkte aus dem Code-Review (separat geplant, NICHT in 2.9.17)
- P0: Geldbeträge REAL → INTEGER (Cents) – Schema-Migration mit DB-Backup,
  API-Format bleibt Euro-Dezimal.
- P0: export_all Streaming statt fetchAll() (Memory-Schutz bei großen Exports).
- P1: N+1 in modules/vde0100/backend/Module.php (Protokolle→Gebäude→Verteiler
  → JOIN-basierte Hydrierung).
- P1: Pagination für list_kunden/list_rechnungen.
- P1: GZIP/Output-Komprimierung, PHP-INI-Tuning (Dockerfile.app).
- P1: Passwort-Policy ≥12 Zeichen, OCI-Lieferanten-PW verschlüsselt,
  Gemini-API-Key aus settings → .env.
- P2: Frontend-Listener-Cleanup (script.js Modale), CSRF-Token,
  sw.js PRECACHE_URLS automatisieren, Audit-Log-Rotation.
- P2: Klärung Doppel-Architektur Legacy (modules/) vs. Vite (src/frontend/modules/).

## v2.9.16 – Bugfix: DIN EN 1090 auf Mobile – Zurück-Button schloss Modul nicht

- APP_VERSION 2.9.15 → 2.9.16 (api.php, manifest.json)
- CACHE_VERSION bk-es-v93 → bk-es-v94 (sw.js)
- ?v=97 → ?v=98 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)
- din1090.js?v=53 → ?v=54 (script.js + mobile.html)
- din1090.css?v=53 → ?v=54 (script.js + mobile.html)

### §B: modules/din1090/din1090.js – hideDin1090 ergänzt
- Ursache: Es gab nur `window.showDin1090`, aber kein `window.hideDin1090`.
  `hideMobileModuleBar()` rief alle anderen Module (Lager, Aufmaß, VDE 0100) über
  ihre Hide-Funktionen auf – für DIN 1090 fehlte der Eintrag.
  Folge: `#din1090View` blieb sichtbar, auch nach dem Zurück-Klick.
- Fix: `window.hideDin1090` in din1090.js ergänzt (entfernt `.hidden`-Klasse
  vom View, deaktiviert aktiven Button-State).

### §B: mobile.html – hideMobileModuleBar erweitert
- `window.hideDin1090?.()` in `hideMobileModuleBar()` ergänzt.

## v2.9.15 – Bugfix: DIN EN 1090 – Projekt anlegen/bearbeiten auf Mobile nicht möglich

- APP_VERSION 2.9.14 → 2.9.15 (api.php, manifest.json)
- CACHE_VERSION bk-es-v92 → bk-es-v93 (sw.js)
- ?v=96 → ?v=97 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### §B: mobile.html – Modal-Basisstyles ergänzt
- Ursache: `mobile.html` lädt `style.css` nicht (eigenständige HTML-Datei mit
  inline CSS). `din1090EditProject()` und `din1090ShowFormModal()` hängen ein
  `.modal-overlay`/`.modal-box`-Konstrukt an `document.body`. Diese CSS-Klassen
  waren nur in `style.css` definiert → auf Mobile unsichtbar/nicht nutzbar.
- Fix: Fehlende CSS-Klassen direkt in `mobile.html` inline ergänzt:
  `.modal-overlay`, `.modal-box`, `.modal-header`, `.modal-header h2/h3`,
  `.modal-close`, `.modal-body`, `.modal-footer` inkl. Dark-Mode-Support.
- Betrifft: „Neues DIN 1090 Projekt" und alle Unterformulare (Material, Schweißer,
  WPS, Schweißnaht, Prüfung, Oberfläche, NCR) im DIN-1090-Modul auf Mobile.
- Nicht betroffen: Desktop (index.html) – dort war und ist alles korrekt.

## v2.9.14 – Security Audit: Härtung für IONOS Linux Server

- APP_VERSION 2.9.13 → 2.9.14 (api.php, manifest.json)
- CACHE_VERSION bk-es-v91 → bk-es-v92 (sw.js)
- ?v=95 → ?v=96 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### Befund & Bewertung (OWASP Top 10)
A01 Broken Access Control:      ✅ kein Befund – Auth/Perm-System vollständig
A02 Cryptographic Failures:     ✅ bcrypt, HTTPS-Erkennung, HSTS, Audit-HMAC
A03 Injection:                  ✅ prepared statements überall, escapeshellarg(), basename()
A04 Insecure Design:            🔧 CSRF-Check in din1090_api.php fehlte → behoben
A05 Security Misconfiguration:  🔧 mehrere Punkte behoben (siehe unten)
A06 Outdated Components:        ℹ️  regelmäßig `composer outdated` ausführen
A07 Auth Failures:              ✅ Rate-Limit, Kontosperre, Session-Invalidierung
A08 Data Integrity:             ✅ Audit-Log HMAC-Kette, SRI nicht nötig (alle Assets lokal)
A09 Logging & Monitoring:       ✅ Audit-Log + login_attempts-Tabelle
A10 SSRF:                       ✅ fetchUrl() nur für geprüfte externe URLs (Kupferpreis etc.)

### §H: .htaccess (Root) – Härtung
- `Options -Indexes` ergänzt: verhindert Verzeichnislisting bei fehlender index-Datei.
- `<IfModule mod_headers.c>` Block hinzugefügt:
  * `Header unset X-Powered-By` – PHP-Version nicht preisgeben
  * `Header always set X-Frame-Options "SAMEORIGIN"` (Clickjacking)
  * `Header always set X-Content-Type-Options "nosniff"`
  * `Header always set Referrer-Policy "same-origin"`
  * `Header always set X-Permitted-Cross-Domain-Policies "none"`
  * `Header always set Permissions-Policy "camera=(), microphone=(), ..."`
  * `Header always set Content-Security-Policy "default-src 'self'; ..."`
    (unsafe-inline/eval nötig wegen Vanilla-JS-Architektur; frame-ancestors 'self')

### §H: data/.htaccess – Ergänzt
- `Options -Indexes` ergänzt (Verzeichnislisting war durch `Require all denied`
  schon geblockt, aber explizit deaktiviert für defense-in-depth).

### §H: vendor/.htaccess – Modernisiert
- War nur Apache-2.2-Syntax. Jetzt zusätzlich `Options -Indexes` und Apache-2.4-Block
  (`<IfModule mod_authz_core.c> Require all denied </IfModule>`).

### §S: din1090_api.php – CSRF-Schutz ergänzt
- Origin-Header-Check (identisch mit api.php) fehlte komplett.
  Alle POST-Requests werden jetzt auf Cross-Origin überprüft.
- `Strict-Transport-Security` Header ergänzt (wurde wenn HTTPS erkannt, bisher
  nur in api.php gesetzt).

### §P: FileActions.php – Dangerous Extension Block
- In `uploadFile()` nach `basename($file['name'])` eingefügt:
  Erweiterungen php, php3–8, phtml, phar, shtml, cgi, pl, py, rb, sh, bash, htaccess
  werden mit HTTP 400 abgelehnt.
  Begründung: Der Filename-Sanitizer lässt `.php` bisher durch
  (`preg_replace` erlaubt Punkte). data/.htaccess blockiert Ausführung auf
  Webserver-Ebene bereits – dies ist defense-in-depth auf Anwendungsebene.

## v2.9.13 – Feature: Kundenstamm – Tel/Mail als klickbare Links, Bearbeiten nur per Icon

- APP_VERSION 2.9.12 → 2.9.13 (api.php, manifest.json)
- CACHE_VERSION bk-es-v90 → bk-es-v91 (sw.js)
- ?v=94 → ?v=95 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### §F: Frontend – script.js (renderKundenListe)
- `<tr onclick="openKundeForm(...)">` entfernt – Zeile ist nicht mehr anklickbar.
  Bearbeiten nur noch über das Bearbeiten-Icon in der Aktionsspalte möglich.
- `onclick="event.stopPropagation()"` in `.kunde-actions`-Zelle entfernt (nicht mehr nötig).
- Telefon-Zelle: Wenn Telefon/Mobil vorhanden → `<a href="tel:...">` Link, sonst '-'.
- E-Mail-Zelle: Wenn E-Mail vorhanden → `<a href="mailto:...">` Link, sonst '-'.

### §S: Styles – style.css
- `.kunden-table tr:hover`: `cursor: pointer` entfernt (Zeile nicht mehr anklickbar).
- Neue Regeln: `a[href^="tel:"]` und `a[href^="mailto:"]` in Tabellenzellen:
  standardmäßig `color: inherit; text-decoration: none;`,
  bei hover unterstrichen + blau (var(--blue)).

## v2.9.12 – Bugfix: DIN EN 1090 Modul – Mobile Usability (iOS Auto-Zoom & Touch-Targets)

- APP_VERSION 2.9.11 → 2.9.12 (api.php, manifest.json)
- CACHE_VERSION bk-es-v89 → bk-es-v90 (sw.js)
- ?v=93 → ?v=94 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)
- din1090.css Modul-Cache: ?v=52 → ?v=53 (script.js loadOptionalModule-Aufruf)

### §M: Modul – modules/din1090/din1090.css (Mobile-Fixes)
- **iOS Auto-Zoom-Fix (kritisch)**: `.form-control` hat global `font-size: 0.9rem` (≈14px).
  iOS zoomt automatisch heran wenn font-size < 16px – macht Eingaben im DIN-1090-Modul nahezu
  unmöglich. Fix: Im `@media (max-width: 768px)` Block `.form-control`, `select.form-control`,
  `textarea.form-control` auf `font-size: 16px !important; min-height: 44px` gesetzt.
- **Falscher Selektor in Checkliste korrigiert**: Die bisherige Regel
  `.din1090-check-note select, .din1090-check-note input[type="text"]...` war wirkungslos,
  da `.din1090-check-note` selbst das `<input>`-Element ist (kein Container).
  Fix: `font-size: 16px !important; min-height: 44px; padding: 8px 10px;` direkt auf `.din1090-check-note`.
- **Touch-Targets**: Größere Checkboxen (`width/height: 20px`) in Checklisten.
- **Tabellen**: `.din1090-table-wrap .btn` mit `min-height/min-width: 36px; padding: 6px 10px`.
- **Tabellen-Scroll**: `-webkit-overflow-scrolling: touch` für flüssiges Scrollen auf iOS.
- **Projekt-Selector**: `font-size: 16px; min-height: 44px` im mobilen Breakpoint ergänzt.
- **Copy-Modal Feedback**: `.din1090-copy-item:active` mit Touch-Highlight (#E3F2FD).

## v2.9.11 – Bugfix: Pfeil-Icon Oberkategorie im Baustellen-Material

- APP_VERSION 2.9.10 → 2.9.11 (api.php, manifest.json)
- CACHE_VERSION bk-es-v88 → bk-es-v89 (sw.js)
- ?v=92 → ?v=93 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### §F: Frontend – script.js (Bugfix: Kategorie-Chevron zeigte '?' statt Pfeil)
- `renderCatHeader()`: Chevron-Span verwendete `'?'` als Platzhalter (war nie befüllt worden).
  Fix: `▾` (U+25BE, SMALL BLACK DOWN-POINTING TRIANGLE) eingesetzt.
  Das CSS-Transform `rotate(-90deg)` war bereits korrekt vorhanden und dreht das Zeichen
  im eingeklappten Zustand zu `▶` (rechtsweisend). Im ausgeklappten Zustand zeigt `▾` nach unten.
  Betroffen: Oberkategorie-Header in Baustellen → Material.


## v2.9.10 – Bugfix: katalogSearch-ReferenceError, Modulansichten-Sichtbarkeit, Install-Interaktivität

- APP_VERSION 2.9.9 → 2.9.10 (api.php, manifest.json)
- CACHE_VERSION bk-es-v87 → bk-es-v88 (sw.js)
- ?v=91 → ?v=92 (style.css, script.js, dist/core.js in index.html + sw.js PRECACHE_URLS)

### §F: Frontend – index.html (Bug 1: ReferenceError katalogSearchHandler)
- Zeile 358: `oninput="katalogSearchHandler(this.value)"` → `katalogSearch(this.value)`
- Zeile 361: `onchange="katalogSearchHandler(..."` → `katalogSearch(...`
- Ursache: HTML-Eventhandler rief eine nicht existente Funktion auf;
  script.js definiert seit jeher nur `katalogSearch()` (und intern `doKatalogSearch()`).

### §F: Frontend – script.js (Bug 2: Modulansichten bleiben beim Wechsel sichtbar)
- `showDienstleister()`: `hide('din1090View'); hide('lagerView'); hide('aufmassView'); hide('vde0100View');` ergänzt
- `showRechnungenView()`: dieselben 4 hide()-Aufrufe ergänzt
- `showDashboard()`: `hide('vde0100View');` ergänzt (din1090View/lagerView/aufmassView waren bereits vorhanden)
- `showAuswertung()`: die 4 Modul-hide()-Aufrufe ergänzt
- Ursache: Diese Funktionen existierten vor Einführung der optionalen Module und wurden nie
  um deren hide()-Aufrufe erweitert. Beim Wechsel z.B. von Lager → Rechnungen blieb
  lagerView sichtbar.

### §I: Install – install.sh & install.ps1 (Bug 3: keine Rückfragen bei nicht-interaktiver Ausführung)
- install.sh: TTY-Prüfung (`[ ! -t 0 ]`) am Skript-Anfang eingefügt.
  Bei nicht-interaktivem stdin (curl|bash, SSH ohne TTY) bricht das Skript mit
  verständlicher Fehlermeldung ab statt alle Defaults still zu übernehmen.
- install.ps1: `[System.Console]::IsInputRedirected`-Prüfung eingefügt.
  Verhindert stilles Übernehmen aller Defaults in nicht-interaktiven PowerShell-Sitzungen
  (Task Scheduler, Invoke-Expression, CI/CD).
- Hinweis für zukünftige Änderungen: Install-Skripte IMMER interaktiv ausführen:
    Linux: `chmod +x install.sh && ./install.sh`
    Windows: `powershell -ExecutionPolicy Bypass -File .\install.ps1`
  Ein Ausführen via `curl | bash`, `Invoke-Expression` oder ähnlichem ist nicht unterstützt
  und führt nun zu einem expliziten Fehler statt stiller Default-Übernahme.

### Hinweis
- lager.js und aufmass.js bleiben unverändert; `hideAllOtherViews()` ist dort IIFE-scoped
  → kein Namenskonflikt, Funktionsweise war korrekt.
- script.js: Nur die 4 betroffenen show*()-Funktionen wurden angepasst, keine weiteren
  Änderungen. Alle anderen Funktionen und Icons unverändert.


## v2.9.9 – Modularisierung Phase 5: Companion-Mode (dist/core.js aktiv in index.html)

- APP_VERSION 2.9.8 → 2.9.9 (api.php, manifest.json)
- CACHE_VERSION bk-es-v86 → bk-es-v87 (sw.js)
- ?v=90 → ?v=91 (style.css, script.js in index.html; sw.js PRECACHE_URLS korrigiert)

### §I: Infrastruktur – Dockerfile.app
- Multi-Stage-Build eingeführt:
  Stage 1: `node:lts-alpine` → `npm install && npm run build` → `dist/`
  Stage 2: `php:8.2-apache` → COPY --from=frontend-builder /build/dist ./dist/
  Damit ist `dist/` immer frisch gebaut – kein node_modules im PHP-Image.

### §I: Infrastruktur – .dockerignore
- `dist/` aufgenommen → stale lokale dist/ wird nie ins Image kopiert;
  der Node-Stage liefert immer den aktuellen Build.

### §F: Frontend – index.html (Companion-Modus)
- `style.css?v=90` → `style.css?v=91`
- `script.js?v=90` → `script.js?v=91`
- Neuer Tag hinzugefügt (nach script.js):
  `<script type="module" src="dist/core.js?v=91"></script>`
  → aktiviert ES-Modul-Bootstrap (init.js) parallel zu script.js.
  script.js bleibt primäre Laufzeit (unverändert).

**ROLLBACK:** diese eine Zeile aus index.html entfernen + git revert Dockerfile.app
= vollständiger Rollback auf v2.9.8 ohne Datenverlust.

### §F: Frontend – sw.js
- CACHE_VERSION bk-es-v86 → bk-es-v87 (erzwingt Cache-Invalidierung)
- PRECACHE_URLS: veraltete `?v=73`-Referenzen auf `?v=91` korrigiert
  (Bugfix: sw.js cachte seit v2.9.6 die falschen Asset-Versionen)
- `./dist/core.js?v=91` in PRECACHE_URLS aufgenommen

### §F: Frontend – src/frontend/core/init.js
- Kommentar-Abschnitt PHASE 5 / ROLLBACK / NÄCHSTER SCHRITT ergänzt.
  Kein funktionaler Code geändert.

### Hinweis
- script.js, style.css, mobile.html, login.html sind UNVERÄNDERT.
- init.js läuft als Companion: macht eigenen api.php?action=check-Aufruf
  parallel zu script.js (harmloser read-only-Doppelaufruf, entfällt in Phase 6+).
- Zwei api.php?action=check-Requests beim Seitenaufruf sind gewollt;
  der Auth-Guard in index.html macht einen dritten (vor DOM-Load). Gesamt: 3.


## v2.9.8 – Modularisierung Phase 4: Feature-Wrapper (ES-Modul-Adapter für script.js-Funktionen)

- APP_VERSION 2.9.7 → 2.9.8 (api.php, manifest.json)
- CACHE_VERSION bleibt bk-es-v86 (script.js / style.css / index.html unverändert)
- ?v=90 bleibt

### §F: Frontend – src/frontend/modules/*/index.js (NEUE Dateien)

11 thin ES-Modul-Adapter für Feature-Bereiche aus script.js.
Jeder Adapter folgt dem Muster `{ name, init(context), show(...args), hide() }`.
`init(context)` speichert den Store-Kontext. `show()` / `hide()` delegieren an
die jeweiligen `window.*`-Globals aus script.js. script.js bleibt bis Phase 5
die einzige Ladestelle.

| Datei | show-Delegation | hide-Delegation |
|---|---|---|
| `src/frontend/modules/rechnungen/index.js` | `window.showRechnungenView(filterTyp)` | `#rechnungenView.hidden` |
| `src/frontend/modules/kunden/index.js` | `window.showKundenstamm()` | `#kundenstammView.hidden` |
| `src/frontend/modules/zeiterfassung/index.js` | `window.openStundenerfassungModal()` | `window.closeStundenerfassungModal()` |
| `src/frontend/modules/wochenplanung/index.js` | `window.openWochenplanungModal()` | `window.closeWochenplanungModal()` |
| `src/frontend/modules/stundenauswertung/index.js` | `window.openStundenauswertungModal()` | `window.closeStundenauswertungModal()` |
| `src/frontend/modules/termine/index.js` | `window.openTermineModal()` | `#termineOverlay.remove()` |
| `src/frontend/modules/dashboard/index.js` | `window.showDashboard()` | `#dashboardView.hidden` |
| `src/frontend/modules/whatsapp/index.js` | `window.showWhatsApp()` | `#whatsappView.hidden` |
| `src/frontend/modules/auswertung/index.js` | `window.showAuswertung()` | `#auswertungView.hidden` |
| `src/frontend/modules/oci/index.js` | `window.openOciSupplierPicker()` | (kein View) |
| `src/frontend/modules/datanorm/index.js` | (eingebettet in Material-Katalog) | (kein View) |

### §I: Infrastruktur – vite.config.js
- 11 Feature-Wrapper-Entries aktiviert (Phase-4-Block).
- `npm run build` erzeugt jetzt zusätzlich `dist/rechnungen.js`, `dist/kunden.js`,
  `dist/zeiterfassung.js`, `dist/wochenplanung.js`, `dist/stundenauswertung.js`,
  `dist/termine.js`, `dist/dashboard.js`, `dist/whatsapp.js`, `dist/auswertung.js`,
  `dist/oci.js`, `dist/datanorm.js`.

### Hinweis
- `script.js`, `style.css`, `index.html` und `sw.js` sind UNVERÄNDERT.
- Alle Adapter sind aktuell reine Delegations-Schichten.
  In Phase 5 werden die window-Globals durch direkte ES-Modul-Importe ersetzt.


## v2.9.7 – Modularisierung Phase 3: Frontend-Modul-Adapter (ES-Module-Wrapper)

- APP_VERSION 2.9.6 → 2.9.7 (api.php, manifest.json)
- CACHE_VERSION bleibt bk-es-v86 (script.js / style.css / index.html unverändert)
- ?v=90 bleibt

### §F: Frontend – modules/*/frontend/index.js (NEUE Dateien)

Jedes Modul erhält einen ES-Modul-Einstiegspunkt als Thin Adapter:
**`{ name, init(context), mount(), show(), hide() }`**

`init(context)` speichert den Store-Kontext (aus Phase 2 Core).
`mount()` lädt das bestehende Legacy-IIFE-Script + CSS dynamisch per `<script>`-Tag
(analog zur `loadOptionalModule()`-Logik in script.js, aber als Promise).
`show()` / `hide()` delegieren an die jeweiligen `window.*`-Globals des Legacy-Scripts.

| Datei | show-Delegation | hide-Delegation | Zusatz |
|---|---|---|---|
| `modules/din1090/frontend/index.js` | `window.showDin1090()` | `#din1090View.hidden` | — |
| `modules/lager/frontend/index.js` | `window.showLager()` | `window.hideLager()` | `.api` → `window.lagerModul` |
| `modules/aufmass/frontend/index.js` | `window.showAufmass()` | `window.hideAufmass()` | `.api`, `showForBaustelle(id)` |
| `modules/vde0100/frontend/index.js` | `window.vde0100Modul.showVde0100View()` | `vde0100Modul.hideVde0100View()` | `.api` → `window.vde0100Modul` |

### §B: Backend – modules/vde0100/module.json
- Neues Feld `"tier": "professional"` ergänzt (analog din1090/lager/aufmass aus v2.9.5).

### §I: Infrastruktur – vite.config.js
- Vier Modul-Entries aktiviert:
  `din1090`, `lager`, `aufmass`, `vde0100` → `modules/<name>/frontend/index.js`
- `npm run build` erzeugt jetzt `dist/core.js` + `dist/din1090.js` + `dist/lager.js`
  + `dist/aufmass.js` + `dist/vde0100.js`

### Hinweis
- `script.js`, `style.css`, `index.html` und `sw.js` sind UNVERÄNDERT.
- Die Legacy-IIFE-Scripts (din1090.js, lager.js, aufmass.js, vde0100.js) sind UNVERÄNDERT.
- Bis Phase 5 laden `loadOptionalModule()` (in script.js) und
  die `mount()`-Methoden der Adapter dasselbe Legacy-Script.
- In Phase 4 werden die Legacy-Scripts schrittweise durch vollständige
  ES-Module ersetzt; die Adapter-Dateien werden dabei aktualisiert.



- APP_VERSION 2.9.5 → 2.9.6 (api.php, manifest.json)
- CACHE_VERSION bleibt bk-es-v86 (script.js / style.css / index.html unverändert)
- ?v=90 bleibt

### §F: Frontend – src/frontend/core/ (NEUE Dateien)

**api.js**
- Neuer Fetch-Wrapper für alle api.php-Aufrufe.
- `apiPost(action, body)`: JSON-POST, 401 → automatischer Redirect zu login.html.
- `apiGet(action, params)`: Query-GET, gleiche Fehlerbehandlung.

**store.js**
- Reaktiver Zustandsspeicher ohne externe Abhängigkeiten.
- `getState()` / `setState(patch)` / `subscribe(key, cb)`.
- `_syncGlobals()`: Compat-Layer schreibt Store-Werte in die window-Globals von script.js
  zurück (currentUser, currentRole, currentPerms, appSettings, enabledModules …).
  Wird in Phase 5 entfernt.

**auth.js**
- `loadSession()`: Ruft api.php?action=check auf, befüllt Store, ruft _syncGlobals().
- `canDo(perm)`: Entspricht globaler canDo() in script.js (Admin darf alles).
- `getUser()`, `getRole()`, `isMasterOrAbove()`.

**utils.js**
- Reine Hilfsfunktionen, spiegeln die globalen Pendants in script.js wider:
  `esc()`, `fmt()`, `fmtNum()`, `fmtDecimals()`, `formatDate()`, `formatDateObj()`,
  `debounce()`, `showToast()` (fällt auf showNotification() zurück wenn verfügbar).

**init.js** (ersetzt Stub aus Phase 1)
- Vollständiger Bootstrap: loadSession() → Store befüllen → Module loggen.
- Dynamischer Modul-Import vorbereitet (auskommentiert, Phase 3).
- Startet automatisch via DOMContentLoaded wenn als type="module" geladen.

### §I: Infrastruktur – vite.config.js
- Entry `core: 'src/frontend/core/init.js'` aktiviert (war auskommentiert).
- `npm run build` erzeugt jetzt `dist/core.js` + `dist/.vite/manifest.json`.

### Hinweis
- `script.js`, `style.css`, `index.html` und `sw.js` sind UNVERÄNDERT.
- Die neuen Module sind erst in Phase 5 live (index.html wechselt zu dist/core.js).
- Bis dahin koexistieren script.js und core.js via Compat-Layer (_syncGlobals).



- APP_VERSION 2.9.4 → 2.9.5 (api.php, manifest.json)
- CACHE_VERSION bleibt bk-es-v86 (keine Frontend-Asset-Änderungen)
- ?v=90 bleibt (style.css / script.js unverändert)

### §B: Backend – src/Services/LicenseService.php (NEU)
- Neue statische Service-Klasse `App\Services\LicenseService`.
- Liest `data/license.json` und wertet Tier, Module und Ablaufdatum aus.
- Dev-Modus (keine license.json): Tier „dev", alle Module freigeschaltet (Wildcard `["*"]`).
- Abgelaufene Lizenz: Tier „expired", keine Module freigeschaltet.
- Öffentliche API: `getActiveTier()`, `getEnabledModules()`, `isModuleAllowed(string)`,
  `getPublicInfo()`, `clearCache()`.

### §B: Backend – src/Core/ModuleLoader.php
- `use App\Services\LicenseService` hinzugefügt.
- `userHasAccess()`: neues Lizenz-Gating via `LicenseService::isModuleAllowed($m['name'])`.
- `listForFrontend()`: gibt nun zusätzlich `'tier'` je Modul zurück.

### §B: Backend – src/Handlers/AuthActions.php
- `use App\Core\ModuleLoader` und `use App\Services\LicenseService` hinzugefügt.
- `check()`-Response ergänzt um Felder `modules` (ListForFrontend-Daten) und
  `license` (Tier, Kunde, Ablaufdatum) – `modules` nur wenn authentifiziert.

### §B: Backend – modules/*/module.json (din1090, lager, aufmass)
- Neues Pflichtfeld `"tier": "professional"` in allen drei Modul-Manifesten ergänzt.

### §I: Infrastruktur – Vite-Build-System (Grundgerüst)
- `package.json` angelegt: `vite ^6.3.0` als devDependency, Build-Skripte `dev`/`build`/`preview`.
- `vite.config.js` angelegt: outDir `dist/`, manifest.json, Alias `@core` / `@modules`,
  Entry-Points auskommentiert (werden in Phase 2 eingetragen).
- `src/frontend/core/init.js` als Stub angelegt (Einstiegspunkt für Phase 2).
- `data/license.example.json` angelegt als Vorlage (nicht in .gitignore, im Repo eingecheckt).
- `.gitignore`: `node_modules/` und `!data/license.example.json` ergänzt.



- APP_VERSION 2.9.3 → 2.9.4 (api.php, manifest.json)
- CACHE_VERSION bk-es-v85 → bk-es-v86 (sw.js), ?v=89 → ?v=90 (index.html, style.css, script.js)

### §F: Frontend – style.css
- Neue CSS-Klassen `.confirm-del-overlay`, `.confirm-del-modal`, `.confirm-del-icon`,
  `.confirm-del-message`, `.confirm-del-typing-hint`, `.confirm-del-input`,
  `.confirm-del-actions`, `.confirm-del-cancel-btn`, `.confirm-del-ok-btn`
  inkl. Dark-Theme-Varianten hinzugefügt.

### §F: Frontend – script.js
- Neue Funktion `confirmDelete(message, onConfirm, { requireTyping })` vor `// INIT` eingefügt.
  Ersetzt alle `confirm()` / `alert()` bei Löschoperationen durch ein zentriertes Modal.
  Mit `requireTyping: true` muss der Nutzer „LÖSCHEN" eintippen, bevor die Aktion ausgeführt wird.
- 5 kritische Deletes → `requireTyping: true`:
  `deleteBaustelle`, `deleteUser`, `deleteDienstleister`, `deleteKunde`, `dsgvoDeleteKunde`
  (dsgvoDeleteKunde: doppeltes confirm() durch einmaliges Typing-Modal ersetzt)
- 8 normale Deletes → Standard-Modal:
  `delMatKategorie`, `datanormClear`, `deleteArchive`, `deleteTermin`,
  `deleteRechnung`, `deleteOciLieferant`, `deleteBaustelleFile`, `deleteWaTemplate`

### §F: Frontend – mobile.html
- Funktion `confirmDelete(message, onConfirm)` (ohne requireTyping) vor `showToast()` eingefügt.
- Passende CSS-Klassen in den `<style>`-Block eingefügt.
- `deleteTerminMobile()`, `deleteFileMobile()` auf `confirmDelete()` umgestellt.

## v2.9.3 – Bugfix (Tage-Verschiebung in Wochenplanung; Admin Betriebseintrag ohne Feedback)

- APP_VERSION 2.9.2 → 2.9.3 (api.php, manifest.json)
- CACHE_VERSION bk-es-v84 → bk-es-v85 (sw.js), ?v=88 → ?v=89 (index.html, style.css, script.js)

### §F: Frontend – script.js
- `renderWochenplanung()`: Manuelle `<th class="wp-tl-sep">` Erzeugung im day-header-Loop entfernt.
  Die KW-Header-Zeile verwendet `rowspan="2"` für Separatoren zwischen Kalenderwochen – diese Spalten
  sind in der Tagesnamen-Zeile (Zeile 2) bereits automatisch belegt. Zusätzliche manuelle Sep-Zellen
  verschoben Tag-Header ab KW2 um je 1 Spalte (ab KW3: 2 Spalten usw.), da der Browser extra Zellen
  in die nächste freie Spalte nach der rowspan-belegten Spalte platziert.
  Body-Zeilen-Seps (idx % 6 === 0) bleiben unverändert.
- `wpPopAdd()`, Typ `betrieb`: Nach dem Eintragen für alle Mitarbeiter wird nun `wpPopRemove()` +
  `renderWochenplanung()` aufgerufen statt `wpPopRefresh()`. Damit werden alle Mitarbeiter-Zellen
  des betroffenen Tages sofort neu gezeichnet und der Admin sieht die Wirkung unmittelbar.
- `wpPopDelBetrieb()`: Analog zu `wpPopAdd()` – nach dem Löschen bei allen Mitarbeitern
  `wpPopRemove()` + `renderWochenplanung()` statt `wpPopRefresh()`.

## v2.9.2 – Bugfix (DIN1090-Modul auf Mobile nicht scrollbar)

- APP_VERSION 2.9.1 → 2.9.2 (api.php, manifest.json)
- CACHE_VERSION bk-es-v83 → bk-es-v84 (sw.js), ?v=87 → ?v=88 (index.html, style.css, script.js)
- din1090.css ?v=52 → ?v=53 (mobile.html)

### §F: Frontend – mobile.html
- `#app > #din1090View` zur CSS-Whitelist der scroll-fähigen Modul-Views hinzugefügt (Zeile 87-95)
- Ursache: generische Regel `#app > [id^="view"] { overflow: hidden }` blockierte Scrolling; nur lagerView/aufmassView/vde0100View hatten `overflow-y: auto` – din1090View fehlte

### §F: Frontend – modules/din1090/din1090.css
- `@media (max-width: 768px)`: `.din1090-tab` erhält `min-height: 44px; padding: 10px 14px; white-space: nowrap` (Touch-Target-Mindestgröße)
- `@media (max-width: 768px)`: `.din1090-tabs` erhält `-webkit-overflow-scrolling: touch` (Momentum-Scroll iOS für Tab-Leiste)
- `@media (max-width: 768px)`: `.din1090-check-note select/input` erhalten `min-height: 36px; font-size: 16px` (verhindert iOS-Auto-Zoom beim Fokus)

## v2.9.1 – Bugfix (Feiertagsanzeige in Wochenplanung)

- APP_VERSION 2.9.0 → 2.9.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v82 → bk-es-v83 (sw.js), ?v=86 → ?v=87 (index.html, style.css, script.js)

### §F: Frontend – script.js
- `buildHolidayNameMap(year)`: neue Hilfsfunktion, liefert Map<dateKey, holidayName> für alle bayerischen + custom Feiertage
- `renderWochenplanung()`:
  - Spalten-Header-`<th>` erhält bei Feiertagen jetzt die Klasse `wp-tl-day-feiertag` (orangefarbene Hervorhebung) + `title`-Attribut mit Feiertagsname (z. B. „Pfingstmontag")
  - Feiertag-Block in Datenzellen zeigt jetzt den konkreten Feiertagsnamen (z. B. „🎉 Pfingstmontag") statt nur generischem „Feiertag"

### §F: Frontend – style.css
- `.wp-tl-day-feiertag`: orangefarbener Hintergrund + Schrift für Spaltenheader-Feiertage (light theme)
- Dark-Theme-Variante: `body.dark-theme .wp-tl-day-feiertag` (dunkles Orange)

### §F: Frontend – wochenplan_display.html
- `buildDisplayHolidayNames(year)`: neue Hilfsfunktion analog zu script.js
- `render()`: Spalten-Header-`<th>` und Datenzellen erhalten bei Feiertagen die Klasse `feiertag-col`; Feiertag-Block zeigt Feiertagsnamen
- CSS: `.feiertag-col` / `thead .feiertag-col` (orangefarbene Hervorhebung) hinzugefügt

## v2.9.0 – Feature (Betriebseinträge in Wochenplanung für alle Mitarbeiter)

- APP_VERSION 2.8.9 → 2.9.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v81 → bk-es-v82 (sw.js), ?v=85 → ?v=86 (index.html, style.css, script.js)

### §T: Backend – src/Database.php
- `SCHEMA` (initSchema): `wochenplanung`-Tabelle erhält neue Spalte `betriebTyp TEXT DEFAULT ''`
- `runMigrations()`: idempotente ALTER TABLE Migration für `betriebTyp`-Spalte (bestehende DBs)

### §T: Backend – src/Handlers/PlanActions.php
- `buildPlanStructure()`: SELECT enthält nun `betriebTyp`; nicht-leerer Wert wird in Eintrag übernommen
- `saveWochenplanung()`: INSERT enthält nun `betriebTyp`; Wert wird aus Eintrag übernommen (`betriebTyp ?? ''`)

### §F: Frontend – script.js
- `renderWochenplanung()` / `wpCellRedraw()`: Rendering von `typ='betrieb'`-Einträgen mit CSS-Klassen `wp-block-betrieb-frei` (🏖 Freizeit) und `wp-block-betrieb-arbeit` (💼 Arbeitszeit)
- Legende: Betrieb (Freizeit) + Betrieb (Arbeitszeit) Einträge hinzugefügt
- `wpPopRenderInner()`:
  - Neuer Typ-Option `🏢 Betriebseintrag (alle MA)` im Typ-Select (nur für Admin/Master sichtbar)
  - Betrieb-Einträge in der Entry-Liste mit Freizeit/Arbeitszeit-Badge und `✕ alle`-Löschen-Button
  - Neue `wpPopBetriebDiv`: Bezeichnungsfeld + Freizeit/Arbeitszeit-Radio-Buttons + Hinweis
- `wpPopTypChanged()`: Anzeige/Verstecken von `wpPopBetriebDiv` und `wpPopBemDiv` für Typ `betrieb`
- `wpPopAdd()`: Bei `typ='betrieb'` wird Eintrag für alle sichtbaren Mitarbeiter gleichzeitig eingetragen
- `wpPopDelBetrieb(idx)`: Neue Funktion – entfernt Betriebseintrag bei allen Mitarbeitern für diesen Tag
- Konflikt-Check: `betrieb`-Einträge werden im Überschneidungshinweis angezeigt

### §F: Frontend – style.css
- `.wp-block-betrieb-frei`: Hintergrund #FFF3E0, Farbe #BF360C (light theme)
- `.wp-block-betrieb-arbeit`: Hintergrund #E8F5E9, Farbe #1B5E20 (light theme)
- Dark-Theme-Varianten für beide Klassen

### §F: Frontend – wochenplan_display.html
- CSS-Klassen `.block-betrieb-frei` / `.block-betrieb-arbeit` hinzugefügt
- Rendering von `typ='betrieb'`-Einträgen in der Ansicht
- Legende: Betrieb (Freizeit) + Betrieb (Arbeitszeit) hinzugefügt

## v2.8.9 – Feature (Feiertagsverwaltung in Verwaltung Allgemein)

- APP_VERSION 2.8.8 → 2.8.9 (api.php, manifest.json)
- CACHE_VERSION bk-es-v80 → bk-es-v81 (sw.js), ?v=84 → ?v=85 (index.html, script.js, mobile.html, vde0100.js/css)

### §T: Backend – PlanActions.php
- `loadWochenplanungDisplay`: liefert jetzt `custom_feiertage`-Array aus Settings mit aus (für wochenplan_display.html)

### §F: Frontend – script.js
- `getBavarianHolidays(year)`: berücksichtigt nun `appSettings.custom_feiertage` (betriebliche Zusatzfeiertage)
- `renderAllgemeinSettings`: neuer Abschnitt „📅 Feiertage & arbeitsfreie Tage" in der Settings-Ansicht (Karte mit `grid-column:1/-1`)
- `renderFeiertageSection(s)`: rendert Liste aller bayerischen Feiertage (aktuelles Jahr) + editierbare Liste der betrieblichen Zusatztage mit Formular zum Hinzufügen/Löschen
- `addCustomFeiertag()` / `deleteCustomFeiertag(idx)`: Speichern/Löschen via `updateSetting('custom_feiertage', ...)`
- `_computeEaster(year)`: Hilfsfunktion zur Berechnung des Osterdatums (für UI-Anzeige in renderFeiertageSection)

### §F: Frontend – mobile.html
- `getBavarianHolidays(year)`: berücksichtigt nun `appSettings.custom_feiertage` (analog zu script.js)

### §F: Frontend – wochenplan_display.html
- `wpCustomFeiertage`-Global + `getBavarianHolidays`: verwendet `wpCustomFeiertage` aus API-Response
- `loadData`: setzt `wpCustomFeiertage = data.custom_feiertage || []`

## v2.8.8 – Feature (DIN EN 1090: Berechtigungssystem)

- APP_VERSION 2.8.7 → 2.8.8 (api.php, manifest.json)
- CACHE_VERSION bk-es-v79 → bk-es-v80 (sw.js), ?v=83 → ?v=84 (index.html, script.js, mobile.html, vde0100.js/css), din1090.js ?v=51 → ?v=52 → ?v=53 (Bugfix), din1090.css ?v=51 → ?v=52

### §T: Backend – Auth.php, Din1090Actions.php
- `canReadDin1090` / `canWriteDin1090` zu `getDefaultPermissions()` hinzugefügt (master: beide true, normal: read=true, write=false)
- `Din1090Actions::dispatch()`: Berechtigungsprüfung für `canReadDin1090` (alle Aktionen) und `canWriteDin1090` (alle Schreib-/Löschaktionen) hinzugefügt

### §F: Frontend – script.js, mobile.html, din1090.js
- `allPerms`: `canReadDin1090` / `canWriteDin1090` ergänzt (Gruppe „DIN EN 1090" in `permGroups`)
- Modul-Ladecheck (script.js + mobile.html): DIN 1090 nur anzeigen wenn `canReadDin1090`
- `din1090.js`: `din1090CanWrite()` Hilfsfunktion; alle Schreib-Buttons (Neu, Bearbeiten, Löschen, Kopieren, Checkliste generieren) sowie Checklisten-Checkbox/-Bemerkungsinput berechtigungsabhängig ausgeblendet/deaktiviert
- **Bugfix**: `window.showDin1090` in `din1090.js` ergänzt — fehlte auf Mobile (dort ist script.js nicht geladen); weiße Seite beim Öffnen des DIN 1090-Moduls auf Mobile behoben

## v2.8.7 – Feature (VDE 0100: Fixiertes Protokoll als Entwurf kopieren)

- APP_VERSION 2.8.6 → 2.8.7 (api.php, manifest.json)
- CACHE_VERSION bk-es-v78 → bk-es-v79 (sw.js), ?v=82 → ?v=83 (index.html, script.js, mobile.html, vde0100.js/css)

### §T: Backend – Module.php
- Neuer Endpoint `protokoll_kopieren` (Berechtigung: canWriteVde0100)
- Methode `protokollKopieren()`: dupliziert das Protokoll (inkl. Gebäude, Verteiler, RCDs, Sicherungen) als neuen Entwurf mit Titel „Kopie von [Original]"; status='entwurf', pruef_datum leer, unterschrift/fixiert_am nicht übernommen, erstellt_von/erstellt_am auf aktuellen Benutzer/Zeitstempel gesetzt

### §F: Frontend – vde0100.js
- Im Protokoll-Header: Button „📋 Als Entwurf kopieren" sichtbar, wenn status='fixiert' (ersetzt den 🔒-Fixieren-Button bei fixierten Protokollen)
- Neue async-Funktion `kopierenAlsEntwurf(id)`: Bestätigungsdialog → API-Aufruf → Liste neu laden → neues Entwurfs-Protokoll direkt öffnen
- `kopierenAlsEntwurf` in `window.vde0100Modul` registriert

## v2.8.6 – Patch (VDE 0100 PDF: Leere Seite durch erzwungenen Seitenumbruch vor Bestätigung behoben)

- APP_VERSION 2.8.5 → 2.8.6 (api.php, manifest.json)
- CACHE_VERSION bk-es-v77 → bk-es-v78 (sw.js), ?v=81 → ?v=82 (index.html, script.js, mobile.html, vde0100.js/css)

### §T: Backend – buildPdfHtml (Module.php)
- `page-break-before:always` aus dem Bestätigungs-sec-block entfernt; `margin-top:36mm` bleibt erhalten
- Bestätigung folgt jetzt direkt nach dem vorherigen Inhalt (kein erzwungener Seitenumbruch mehr)
- Der 36mm-Margin stellt sicher, dass der Abschnitt unterhalb des Kopfzeilen-Headers startet, wenn er auf eine neue Seite rutscht

## v2.8.5 – Patch (VDE 0100 PDF: Kopfzeilen-Überlappung behoben; Bestätigung vergrößert)

- APP_VERSION 2.8.4 → 2.8.5 (api.php, manifest.json)
- CACHE_VERSION bk-es-v76 → bk-es-v77 (sw.js), ?v=80 → ?v=81 (index.html, script.js, mobile.html, vde0100.js/css)

### §T: Backend – buildPdfHtml (Module.php)
- ROOT CAUSE "Anlage & Auftraggeber abgeschnitten": dompdf setzt position:fixed; top:0 relativ zur Content-Area-Top (= nach @page-Margin), nicht zur physischen Seitenoberkante → Header (33mm) überlappt Seiteninhalt von Anfang an (Seite 1 und folgende)
- FIX Seite 1: Content-Wrapper padding-top von 9px auf 36mm erhöht → Inhalt ab Seite 1 startet 36mm nach Content-Area-Top, also 3mm unterhalb des 33mm-Headers; kein Abschneiden mehr
- FIX Seite 2+ (Bestätigung): Bestätigungs-sec-block erhält page-break-before:always + margin-top:36mm → immer eigene Seite, Inhalt beginnt 36mm unterhalb des Headers auf der neuen Seite
- Bestätigungs-Zellen: padding 8px → 10px, Unterschriftsfeld min-height 45px → 70px, Stempelfeld min-height 42px → 70px, Datums-Schreibraum margin-top 45px → 70px

## v2.8.4 – Patch (VDE 0100 PDF: thead-Regression behoben; Menü-Button race condition behoben)

- APP_VERSION 2.8.3 → 2.8.4 (api.php, manifest.json)
- CACHE_VERSION bk-es-v75 → bk-es-v76 (sw.js), ?v=79 → ?v=80 (index.html, script.js, mobile.html, vde0100.js/css)

### §T: Backend – buildPdfHtml (Module.php)
- REGRESSION REVERT: thead-Ansatz aus v2.8.3 entfernt — dompdf kann keine komplexen nested tables/divs in <thead> verarbeiten → Daten wurden erst ab Seite 3 ausgegeben, Bestätigungsabschnitt fehlte
- Zurück zu bewährtem position:fixed-Header: <div class="pdf-header-fixed"> position:fixed; top:0; height:33mm
- @page margin-top wieder auf 35mm (Header liegt in der physischen Seitenrandzone)
- .pdf-header-fixed CSS-Regel wieder hinzugefügt
- Alle v2.8.2-Verbesserungen bleiben erhalten (real HTML tables, sec-block page-break-inside:avoid, Bestätigungsabschnitt)

### §T: Frontend – Menü (index.html, script.js)
- ROOT CAUSE "VDE-Button rutscht nach oben": loadOptionalModule startet für alle Module parallele fetch-Requests; je nach Antwortgeschwindigkeit wurde btnVde0100 vor btnDin1090/btnAufmass/btnLager in die NavSection eingefügt → nicht-deterministisch höhere Position
- FIX: btnVde0100 jetzt statisch in index.html als letzter Button in .sidebar-nav-section (display:none); Position dadurch fixiert unabhängig von fetch-Timing
- loadOptionalModule-Guard von getElementById(btnId) auf Script-Tag-Check umgestellt: prüft ob script[src] bereits im DOM → verhindert Doppel-Ladung, erlaubt aber pre-definierte HTML-Buttons
- Button/View-Erstellung in loadOptionalModule nur noch wenn nicht bereits im DOM; existierender Button erhält den Click-Handler nachträglich
- applyModuleSettings VDE-Abschnitt: show/hide des statischen btnVde0100, ruft loadOptionalModule nur noch für JS/CSS-Ladung

## v2.8.3 – Patch (VDE 0100 PDF: Header-Überlappung auf Seite 2+ behoben via thead)

- APP_VERSION 2.8.2 → 2.8.3 (api.php, manifest.json)
- CACHE_VERSION bk-es-v74 → bk-es-v75 (sw.js), ?v=78 → ?v=79 (index.html, script.js, mobile.html, vde0100.js/css)

### §T: Backend – buildPdfHtml (Module.php)
- ROOT CAUSE: `position:fixed; top:0` in dompdf positioniert relativ zum Content-Bereich (nach @page-Margin), nicht zur physischen Seite – auf Seite 2+ überlappte der Header den Seiteninhalt vollständig
- `position:fixed` Header-Div entfernt; stattdessen gesamtes Dokument als `<table><thead><tbody>` strukturiert: dompdf wiederholt `<thead>` (Briefkopf + Titelband) auf jeder Seite nativ, Content in `<tbody>` beginnt immer direkt unter dem Header ohne mögliche Überlappung
- `@page` margin-top von 35mm auf 8mm reduziert (Header ist jetzt im normalen Dokumentfluss via thead, braucht keinen Abstand mehr per margin)
- Footer (`position:fixed; bottom:0`) bleibt unverändert

## v2.8.2 – Patch (VDE 0100 PDF: Bestätigung immer sichtbar, Seitenumbruch vor Abschnitten)

- APP_VERSION 2.8.1 → 2.8.2 (api.php, manifest.json)
- CACHE_VERSION bk-es-v73 → bk-es-v74 (sw.js), ?v=77 → ?v=78 (index.html, script.js, mobile.html, vde0100.js/css)

### §S: Backend – buildPdfHtml (Module.php)
- ROOT CAUSE FIX: Alle `display:table`/`display:table-cell` CSS-Klassen auf Divs entfernt (lh-wrap/lh-logo/lh-firm, title-inner/title-left/title-right, doc-title-row/doc-title-cell/doc-status, two-col/two-col-left/two-col-right, sig-row/sig-cell, pdf-footer/pdf-footer-l/pdf-footer-r) – dompdf 2.x rendert diese Konstrukte nach komplexen Messtabellen nicht zuverlässig
- Alle Layout-Divs durch echte HTML-`<table>`-Elemente mit Inline-Styles ersetzt: Briefkopf, Titelband, Footer, Protokoll-Titelzeile, Anlage/Auftraggeber-Zweispalter
- Seitenumbruch-Wrapper: `.sec-block` (page-break-inside: avoid) um Abschnitte Anlage&Auftraggeber, Sichtprüfung/Funktionsprüfung, Bemerkungen und Bestätigung – Umbruch erfolgt vor dem Abschnitt wenn er nicht auf die aktuelle Seite passt

## v2.8.1 – Patch (VDE 0100: PDF bei Fixierung auto-speichern; PDF Seitenzahl + Sicht-/Funk-Fix)

- APP_VERSION 2.8.0 → 2.8.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v72 → bk-es-v73 (sw.js), ?v=76 → ?v=77 (index.html, script.js, mobile.html, vde0100.js/css)

### §R: Backend (Module.php)
- renderPdf(): neue private Hilfsmethode – extrahiert vollständige PDF-Generierung (Daten laden, buildPdfHtml, dompdf rendern, Canvas-Seitenzahl) aus protokollPdf()
- protokollPdf(): nutzt jetzt renderPdf() – keine Duplizierung mehr
- protokollFixieren(): erzeugt nach dem DB-Update automatisch das PDF via renderPdf() und speichert es unter UPLOADS_DIR/<Baustelle>/Protokolle/<Datum_Titel.pdf>; Fehler beim Speichern werden geloggt, der Fixier-Vorgang schlägt dadurch nicht fehl; Response enthält 'savedFile' (Dateiname oder null)
- PDF-Seitenzahl: CSS-Counter-Ansatz entfernt; stattdessen dompdf Canvas-API ($canvas->page_text()) – zuverlässig auf jeder Seite
- Sichtprüfung & Funktionsprüfung: Zwei-Spalten-Div-Ansatz (display:table-cell + nested table) durch einfache 4-spaltige HTML-Tabelle ersetzt – korrekte Darstellung in dompdf

## v2.8.0 – Feature Release (VDE 0100: neue Messfelder, Sicht-/Funktionsprüfung, DGUV V3)

- APP_VERSION 2.7.5 → 2.8.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v71 → bk-es-v72 (sw.js), ?v=75 → ?v=76 (index.html, script.js, mobile.html, vde0100.js/css)

### §M: DB-Migration (Vde0100Database.php)
- vde0100_sicherungen: messung_zi REAL, messung_polaritaet TEXT ergänzt
- vde0100_rcd: messung_rcd_id REAL, messung_rcd_tt REAL ergänzt (zuvor auf Sicherungs-Ebene, jetzt korrekt je RCD)
- vde0100_protokolle: sichtpruefung_status, sichtpruefung_bem, funktionspruefung_status, funktionspruefung_bem ergänzt
- Alle Migrationen als try/catch-Blöcke (backward-compatible)

### §N: Backend (Module.php)
- protokollGet(): RCD float-casts um messung_rcd_id/tt erweitert; messung_rcd_id/tt aus Sicherungen entfernt, messung_zi ergänzt
- protokollSave(): Norm-Validierung um 'dguv_v3' erweitert; 4 neue Sicht-/Funktionsprüfungs-Felder in UPDATE+INSERT
- rcdSave(): messung_rcd_id/tt in UPDATE+INSERT ergänzt
- sicherungSave(): messung_zi, messung_polaritaet in UPDATE+INSERT; messung_rcd_id/tt backward-compat behalten

### §O: PDF-Builder (buildPdfHtml)
- Norm DGUV V3: normTitle/normSub/normFooter/normRefVal für dguv_v3 ergänzt
- Messtabelle: Spalten umgestellt → Rb | Iso-R | Zs | Zi | Polarität | Re (RCD Id/tt entfernt)
- RCD-Block-Kopfzeile: messung_rcd_id + messung_rcd_tt als Inline-Messinfo angezeigt
- Neuer Abschnitt „Sichtprüfung & Funktionsprüfung" nach Messergebnissen (nur wenn befüllt)

### §P: Frontend JS (vde0100.js)
- Norm-Dropdown: Option „DGUV Vorschrift 3 (BGV A3)" ergänzt
- Protokoll-Formular: Sichtprüfung (Status+Bemerkung) und Funktionsprüfung (Status+Bemerkung) Felder ergänzt
- saveProtokolllForm(): 4 neue Felder in den Request-Body aufgenommen
- renderSicherungenTable(): Spalten neu geordnet (Rb zuerst, Zi+Polarität nach Zs, RCD Id/tt entfernt)
- saveSicherungField() BUG-FIX: Sendet jetzt die vollständige Zeile statt Partial-Update (verhindert Datenverlust)
- renderRcd(): FI-Messzeile (Id mA + Auslösezeit ms) unter dem RCD-Header eingefügt
- Neue Funktion saveRcdField(): Analog zu saveSicherungField für RCD-Messwerte
- window.vde0100Modul: saveRcdField exportiert

### §Q: Styles (vde0100.css)
- .vde-rcd-mess-row + Dark-Mode-Support ergänzt (FI-Messzeile Styling)

## v2.7.5 – Feature Release (Dark Mode Module, Mobile Overflow-Menü, DIN 1090 Button, VDE View-Fix)

- APP_VERSION 2.7.4 → 2.7.5 (api.php, manifest.json)
- CACHE_VERSION bk-es-v70 → bk-es-v71 (sw.js), ?v=74 → ?v=75 (index.html)
- Modul-Cache-Buster: din1090 ?v=50→51, aufmass ?v=50→52, lager ?v=53→54, vde0100 ?v=1→75

### I: VDE-View-Fix beim Modul-Wechsel (lager.js + aufmass.js + script.js)

- lager.js – hideAllOtherViews(): 'vde0100View' in die IDs-Liste ergänzt
  * VDE-View wird jetzt beim Öffnen von Lager korrekt ausgeblendet
- aufmass.js – hideAllOtherViews(): 'vde0100View' in die IDs-Liste ergänzt
  * VDE-View wird jetzt beim Öffnen von Aufmaß korrekt ausgeblendet
- script.js – showDin1090(): hide('vde0100View') ergänzt
  * VDE-View wird jetzt beim Öffnen von DIN EN 1090 korrekt ausgeblendet

### J: DIN EN 1090-Button in mobiler Aktionsleiste (mobile.html + script.js)

- mobile.html: btnDin1090Mobile (⚙ DIN 1090) zur Haupt-Action-Bar ergänzt
  * background: #5C6BC0 (Indigo), onclick → showDin1090Mobile()
  * DIN1090-Assets werden in applyModuleSettings() dynamisch geladen (din1090.css?v=51, din1090.js?v=51)
- mobile.html: btnVde0100Mobile in der Reihenfolge nach Lager einsortiert
- script.js – _mobileBtnDefs: auf 6 Overflow-Einträge bereinigt (Aufmaß + Lager entfernt,
  da sie jetzt fest in der Haupt-Action-Bar sitzen)

### K: Mobiles Drei-Punkte-Overflow-Menü (mobile.html)

- Neues HTML: #overflowBackdrop (semitransparentes Overlay) + #mobileOverflowPanel
  * Panel öffnet sich von unten (bottom: 52px + safe-area), border-radius oben
  * 6 Buttons im Panel: Wochenplanung, Zeitübersicht, Stundenauswertung, Dienstleister,
    Termine, Dashboard
- Neuer Button „⋯ Mehr" (id=btnMoreMobile) am rechten Ende der Haupt-Action-Bar
- Neue JS-Funktionen: toggleOverflowMenu(), closeOverflowMenu(), showDin1090Mobile(),
  updateMoreBtnVisibility()
- applyMobileButtonOrder() zeigt/versteckt jetzt Buttons im #mobileOverflowPanel
- applyModuleSettings() ruft updateMoreBtnVisibility() am Ende auf

### L: Dark-Mode-Unterstützung für alle 4 Module (style.css + Modul-CSS)

#### style.css
- :root: --card-bg, --border, --input-bg, --text-muted, --disabled-bg, --table-alt ergänzt
- body.dark-theme: passende Dunkel-Werte für dieselben 6 CSS-Variablen

#### vde0100.css
- body.dark-theme-Block am Dateiende: badge-entwurf (gelb), badge-fixiert (dunkelgrün),
  verteiler-header, gebaeude/rcd-header:hover, rcd-block-Border, sig-box, template-box/-item/-badge,
  btn-vde-secondary Textfarbe, sicherungen td::before

#### din1090.css
- body.dark-theme-Block am Dateiende: Tabs, info-card/stat-card/sub-section, info-table,
  stat-warn-card, progress-Balken, hint, checklist-kat/-item, check-note, row-warn/-danger

#### lager.css
- body.dark-theme-Block am Dateiende: Tabellenhintergrund, th, bez, kat/einheit,
  pill-grey, Leer-Zustand, btn-icon, lager-chk, Modal, form-grid label, card-mobile, offmat-hint

#### aufmass.css
- body.dark-theme-Block am Dateiende: aufmass-card, card-meta, Leer-Zustand, Banner,
  form-grid label, btn-icon, pos-card, pos-card-sel, pos-card-meta

## v2.7.4 – Feature Release (VDE Mobile: Layout, Bugfixes, Signatur, Suche)

- APP_VERSION 2.7.3 → 2.7.4 (api.php, manifest.json)
- CACHE_VERSION bk-es-v69 → bk-es-v70 (sw.js), ?v=73 → ?v=74 (index.html, mobile.html)

### A: VDE 0100 – Bugfixes

#### Bugfix 1: VDE-Modul bleibt offen bei „Verwaltung → Allgemein" (script.js)
- showAllgemeinSettings(): hide('vde0100View') ergänzt – VDE-View wird nun korrekt ausgeblendet

#### Bugfix 2: „+ Abgang" / Änderungen springen an Seitenanfang (vde0100.js)
- openProtokolle(): neuer optionaler Parameter keepScroll
  * Bei keepScroll=true: scrollTop vor dem Re-Render gespeichert, per requestAnimationFrame wiederhergestellt
  * Kein Lade-Spinner bei CRUD-Aktionen die im gleichen Protokoll bleiben
- Alle CRUD-Funktionen (addSicherung, deleteSicherung, addGebaeude, editGebaeude,
  _saveVerteilerModal, _saveRcdModal, deleteRcd, deleteVerteiler) rufen jetzt openProtokolle(..., true) auf

### B: VDE 0100 – Farbschema auf App-Primary (#00B4D8) aktualisiert

#### vde0100.css
- 7× #1565c0 → var(--primary, #00B4D8) (Buttons, Header, Badges, Gebäude-Toggle)
- #0d47a1 → var(--blue-hover, #0096B7) (Tabellen-Header-Border, Button-Hover)
- #e3f2fd / #e8eaf6 → var(--blue-light, #E0F7FA) (Gebäude-Header, RCD-Header)
- #bbdefb / #c5cae9 → #b2ebf2 (Hover-States, RCD-Border)
- #f5f8ff → #f0fafb (Template-Hover-Hintergrund)

#### mobile.html
- VDE-Button in der Action-Bar: background:#1565c0 → var(--primary, #00B4D8)

### C: VDE 0100 – Vollbild-Unterschrift (vde0100.js)

- openSignatureModal(): neuer Button „⤢ Vollbild" neben den Aktionsbuttons
- Neue Funktion _sigFullscreen(): öffnet fullscreen-Overlay mit großem Canvas
  * Turquoise-Gradient-Header (linear-gradient(135deg,#00B4D8,#0096B7))
  * DPR-skalierter Canvas, Touch + Mouse Events, touch-action:none
  * Bestehende Unterschrift wird automatisch übertragen
  * „✓ Übernehmen" kopiert die Vollbild-Zeichnung zurück ins normale Canvas
- Neue Hilfsfunktionen: _sigFsCancel, _sigFsClear, _sigFsConfirm
- Alle neuen Funktionen in window.vde0100Modul exportiert

### D: VDE 0100 – Suchfelder für Auftraggeber & Baustelle (vde0100.js + vde0100.css)

- renderProtokolllForm(): Auftraggeber- und Baustellen-Select in div.vde-search-select eingebettet
  * Textfeld darüber mit oninput → window.vde0100Modul._filterSelect(selectId, value)
  * Im Fixiert-Modus (disabled) kein Suchfeld angezeigt
- Neue Funktion _filterSelect(selectId, query): filtert <option>-Elemente per style.display
- vde0100.css: neue Klassen .vde-search-select und .vde-search-filter

### E: VDE 0100 – Besseres Mobile-Layout (vde0100.css + vde0100.js)

#### vde0100.css (@media max-width: 640px)
- Sicherungen-Tabelle als Card-Layout (kein horizontales Scrollen):
  * thead ausgeblendet, tr als block-Element mit Border und Padding
  * td als flex-Zeile: data-label (linke Spalte, min-width 90px) + Eingabefeld (rechts)
  * td::before zeigt das data-label als beschriftete Spaltenüberschrift
- Touch-Targets: min-height 44px für btn-vde-primary, btn-vde-secondary, btn-vde-fixieren
- btn-vde-icon: min-height 40px
- .vde-verteiler-header: min-height 44px ergänzt

#### vde0100.js – renderSicherungenTable()
- Alle <td> mit data-label="..." Attributen versehen (Bezeichnung, Typ, Nennstrom A, etc.)
- Alle numerischen Eingabefelder: inputmode="decimal" ergänzt

### F: Backup / Datensicherung – Modul-Tabellen ergänzt

#### DataActions::restore() (src/Handlers/DataActions.php)
- VDE 0100: vde0100_templates, vde0100_protokolle, vde0100_gebaeude, vde0100_verteiler,
  vde0100_rcd, vde0100_sicherungen werden jetzt beim Backup-Restore aus dem SQLite-Dump
  wiederhergestellt (zuvor fehlend)
- DIN EN 1090: din1090_welders, din1090_projects, din1090_wps, din1090_materials,
  din1090_weld_log, din1090_inspections, din1090_ncr, din1090_checklists, din1090_surface
  werden jetzt beim Backup-Restore aus dem SQLite-Dump wiederhergestellt (zuvor fehlend)
- Reihenfolge: Eltern-Tabellen vor Kind-Tabellen (templates/welders/projects vor abhängigen Einträgen)
- Abwärtskompatibel: Tabellen, die in alten Backups fehlen, werden per try/catch übersprungen

### H: VDE 0100 – Mobile UX Fixes (vde0100.css + vde0100.js + Module.php)

#### Formularfelder abgeschnitten im Hochformat (vde0100.css)
- .vde-form-group: min-width: 0 ergänzt (verhindert Grid-Item-Überlauf)
- .vde-sig-actions: flex-wrap: wrap ergänzt (Buttons umbrechen statt überlaufen)
- @media (max-width: 640px):
  * .vde-detail-container: overflow-x: hidden ergänzt
  * .vde-form-group: max-width: 100%
  * .vde-form-group input/select/textarea: max-width: 100%
  * .vde-sig-actions Buttons: flex: 1 1 auto; min-width: 110px (gleichmäßig aufteilen)

#### Prüfdatum auch bei fixierten Protokollen eingeben (vde0100.js + Module.php)
- renderProtokolllForm(): Prüfdatum-Feld nicht mehr mit ${dis} deaktiviert
  * Bei fixiertem Protokoll: onchange → _savePruefDatum(id, value) automatisch
  * Bei Entwurf-Protokoll: wird wie bisher über „Speichern" mitgespeichert
- Neue Funktion _savePruefDatum(id, value): async, ruft protokoll_save_pruefdatum auf
- Module.php: neuer case 'protokoll_save_pruefdatum' → protokollSavePruefDatum()
  * Erlaubt pruef_datum-Update unabhängig vom fixiert-Status
  * Datumformat-Validierung (YYYY-MM-DD)

### G: Excel-Export (NAS + Vollexport) – Moduldaten ergänzt

#### weeklyExportToNAS() + downloadFullExcel() (script.js)
- weeklyExportToNAS() und downloadFullExcel() exportieren jetzt auch Moduldaten als zusätzliche Sheets:
  - Sheet „VDE 0100": alle Protokolle (Baustelle, Titel, Anlagenart, Status, Norm, Projektnummer …)
  - Sheet „DIN EN 1090": alle Projekte (Projektnummer, Name, Typ, Status, EXC-Klasse, Baustelle …)
  - Sheet „Aufmaß": alle Aufmaße (Baustelle, Titel, Status, Positionen, Summe …)
  - Sheet „Lagerbestand": alle Lagerartikel (ArtikelNr, Bezeichnung, Menge, EK/VK, Mindestbestand …)
- Jedes Modul-Sheet wird nur hinzugefügt, wenn das Modul Daten liefert (Länge > 0)
- Fehler je Modul werden per try/catch abgefangen und als console.warn geloggt
  (ein fehlgeschlagenes Modul bricht den Export nicht ab)

## v2.7.3 – Feature Release (VDE-Protokoll: Logo, Norm, Projektnummer)

- APP_VERSION 2.7.2 → 2.7.3 (api.php, manifest.json)
- CACHE_VERSION bk-es-v68 → bk-es-v69 (sw.js), ?v=72 → ?v=73 (index.html, mobile.html)

### A: VDE 0100 – Protokoll verschönert + Norm-Auswahl + Projektnummer

#### Datenbank (Vde0100Database.php)
- vde0100_protokolle: neue Spalte `norm` (TEXT, DEFAULT 'vde0100_600', v2.7.3)
- vde0100_protokolle: neue Spalte `projektnummer` (TEXT, DEFAULT '', v2.7.3)

#### Backend (Module.php)
- protokollSave: speichert `norm` (vde0100_600 / vde0105) und `projektnummer`
- protokollPdf: lädt baustellen.data für projektNr-Ermittlung (SELECT id, name, data)
- buildPdfHtml – vollständig überarbeitetes PDF-Layout:
  * Neuer Briefkopf (weiße Letterhead): Logo oben links, Firmenname/-adresse oben rechts
  * Blaue 3px-Linie als Trenner
  * Blaues Titelband darunter: Titel + Norm-Untertitel links, Projektnummer-Badge rechts
  * Norm-abhängige Texte: Titelzeile, Untertitel, "Geprüft nach"-Zeile, Fußzeile
  * Projektnummer-Reihenfolge: protokoll.projektnummer › baustelle.data.projektNr › VDE-XXXXX
  * Zeile "Geprüft nach" + "Projektnummer" in Infobereich ergänzt
  * Messwerttabellen-Header dunkelblau (#1a237e) statt #1565c0
  * UTF-8-Sonderzeichen konsequent als HTML-Entities (dompdf-sicher)

#### Frontend (vde0100.js)
- renderProtokolllForm: neues Feld "Prüfnorm" (Select: VDE 0100-600 / VDE 0105-100)
- renderProtokolllForm: neues Feld "Projektnummer" (Freitext, Hinweis auf Baustellen-Fallback)
- saveProtokolllForm: übergibt `norm` und `projektnummer` an API

## v2.7.2 – Feature Release (RCD-Ebene + Mobile VDE 0100)

- APP_VERSION 2.7.1 → 2.7.2 (api.php, manifest.json)
- CACHE_VERSION bk-es-v67 → bk-es-v68 (sw.js), ?v=71 → ?v=72 (index.html)

### A: VDE 0100 – Neue RCD-Ebene (Gebäude → Verteiler → RCD → Abgang)

#### Datenbank (Vde0100Database.php)
- Neue Tabelle vde0100_rcd (id, verteilerId, bezeichnung, nennstrom, nennfehlerstrom, typ, sortPos, bemerkung)
- vde0100_sicherungen: neue Spalte rcdId (nullable, FK → vde0100_rcd.id)
- Einmalige idempotente Migration: bestehende Sicherungen werden pro Verteiler in RCD-Einträge umgewandelt
- RCD-Felder (rcd_vorhanden, rcd_nennstrom, rcd_nennfehler, rcd_typ) bleiben als Legacy-Spalten in vde0100_verteiler erhalten

#### Backend (Module.php)
- Neue Aktionen: rcd_save, rcd_delete
- protokollGet: Hierarchie nun Gebäude→Verteiler→RCD→Sicherungen
- protokollDelete/gebaeudeDelete/verteilerDelete: Cascade-Löschen auf RCD-Ebene erweitert
- sicherungSave: verwendet jetzt rcdId (nicht mehr verteilerId)
- templateSave: speichert rcd_gruppen-Struktur
- templateInsert: erstellt Verteiler + RCD-Blöcke + Sicherungen
- getPredefinedTemplates: neue rcd_gruppen-Struktur (6 Vorlagen aktualisiert)
- buildPdfHtml/protokollPdf: PDF-Layout mit RCD-Ebene (blauvioletter Subheader pro RCD-Block)

#### Frontend (vde0100.js)
- renderVerteiler: zeigt RCD-Blöcke statt direkte Sicherungstabelle; "+ FI/RCD-Block"-Button
- Neue Funktion renderRcd: lila Header mit RCD-Info + Sicherungstabelle + "+ Abgang"-Button
- renderSicherungenTable: Parameter rcdId statt verteilerId
- Neue Funktionen: addRcd, editRcd, openRcdModal, _saveRcdModal, deleteRcd
- openVerteilerModal: RCD-Felder entfernt (Verteiler hat nur Bezeichnung, Nennstrom, Bemerkung)
- saveSicherungField: Suchpfad gebaeude→verteiler→rcd→sicherungen; sendet rcdId
- openTemplatePickerModal: Rückwärtskompatibilität für altes (rcd_vorhanden) und neues (rcd_gruppen) Format
- window.vde0100Modul: addRcd, editRcd, openRcdModal, _saveRcdModal, deleteRcd exportiert

#### CSS (vde0100.css)
- Neue Klassen: .vde-rcd-block, .vde-rcd-header, .vde-rcd-body (inkl. Mobile-Breakpoint)

### B: VDE 0100 auf Mobile (mobile.html)
- VDE-Button im mainActionBar (nur bei aktivem Modul + Berechtigung)
- CSS: #vde0100View bekommt flex-scroll-Layout
- applyModuleSettings: lädt vde0100.css/vde0100.js dynamisch, erstellt vde0100View-Container
- Neue Funktion showVde0100Mobile
- showView/hideMobileModuleBar: blenden vde0100View korrekt aus

## v2.7.1 – Feature Release (23.05.2026)

- APP_VERSION 2.7.0 → 2.7.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v66 → bk-es-v67 (sw.js), ?v=70 → ?v=71 (index.html)

### A: VDE 0100 – Gruppen-Vorlagen (RCD-Gruppen)

Neue Funktion zum Speichern und Wiederverwenden von Verteilergruppen-Konfigurationen.

#### Datenbank
- Neue Tabelle vde0100_templates (Benutzerdefinierte Vorlagen)

#### 6 vordefinierte Standard-Gruppen (read-only, hardcoded in PHP)
- Wohnbereich Standard: RCD 40A/30mA Typ A + 5× B16 + B10
- Küche: RCD 40A/30mA Typ A + B32 Herd + B16×3 + B10
- Keller/Technikraum: RCD 40A/100mA Typ B + B25 Heizung + B16×2 + B10
- Außenanlage/Garage: RCD 25A/30mA Typ F + B16×3 + B10
- Büro/Gewerbe: RCD 63A/30mA Typ A + B16×6 EDV + B10
- Bad/Nassbereich: RCD 25A/30mA Typ A + B16×2 + B10

#### API-Aktionen (Module.php)
- template_list, template_save, template_delete, template_insert

#### Frontend (vde0100.js)
- 📑-Button am Gebäude-Header öffnet Vorlagenauswahl-Modal
- 📑-Button am Verteiler-Header speichert Verteiler als neue Vorlage
- Eigene Vorlagen löschbar im Auswahl-Modal
- Einfügen erstellt neuen Verteiler + alle Sicherungen aus Vorlage

### B: VDE 0100 – Professionelleres PDF-Layout

- Neues dompdf-kompatibles Layout (table-basiert, kein Flexbox/Grid)
- Blauer Kopfband mit Firmenlogo + Protokolltitel
- Firmendaten-Leiste unter Kopfband
- Zwei-Spalten-Infoblock (Anlagendaten | Auftraggeber)
- Protokoll-Nummer im Format VDE-XXXXX
- Professioneller Unterschrifts-Block (3 Zellen: Datum | Unterschrift | Stempel)
- Äußerer blauer Rahmen (border: 2px solid #1565c0)

### C: VDE 0100 – Mobile-optimiertes Frontend

- Collapsible Gebäude-Blöcke (Klick auf Header klappt Inhalt ein/aus)
- Sicherungen-Tabelle in horizontalem Scroll-Container (.vde-table-wrap)
- Scroll-Hinweis "← Tabelle scrollen →" bei schmalem Display
- Media-Query @media (max-width: 640px):
  - Einspaltige Formulare
  - Kleinere Schriften in Tabellen
  - Größere Touch-Targets für Buttons

---

## v2.7.0 – Feature Release (22.05.2026)

- APP_VERSION 2.6.9 → 2.7.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v65 → bk-es-v66 (sw.js), ?v=69 → ?v=70 (index.html)

### A: DIN VDE 0100 Prüfprotokolle (neues Modul)

Neues optionales Modul zur Erstellung und Verwaltung von Prüfprotokollen
nach DIN VDE 0100-600 (Erstprüfung, Wiederholungsprüfung elektrischer Anlagen).

#### Neue Dateien
- modules/vde0100/module.json
- modules/vde0100/backend/Module.php (API-Handler, PDF-Export)
- modules/vde0100/Vde0100Database.php (4 SQLite-Tabellen)
- modules/vde0100/vde0100.js (IIFE-Frontend)
- modules/vde0100/vde0100.css

#### Datenbank-Tabellen
- vde0100_protokolle: Protokoll-Kopfdaten (Titel, Kunde, Baustelle, Anlagendaten, Status)
- vde0100_gebaeude: Gebäude je Protokoll
- vde0100_verteiler: Verteiler je Gebäude (inkl. FI-Daten)
- vde0100_sicherungen: Abgänge/Sicherungen je Verteiler (Messfelder nach VDE 0100-600)

#### Messfelder (Inline-Editing in Tabelle)
- Iso-R (MΩ), Zs (Ω), RCD-Id (mA), RCD-tt (ms), Rb (mΩ), Re (Ω)

#### Weitere Features
- Canvas-Unterschrift (Maus/Touch) beim Fixieren
- PDF-Export via dompdf (DIN A4, Portrait)
- Fixier-Funktion: Protokoll sperren nach Abnahme
- Aktivierbar in Einstellungen (modul_vde0100, Standard: deaktiviert)

#### Berechtigungen
- Auth.php: canReadVde0100, canWriteVde0100, canFixateVde0100
- Master: volle Rechte; Normal: nur Lesen

### B: OCI Bugfix – "Fehler beim Laden" (Detailtext)
- script.js loadOciLieferanten(): Zeigt jetzt den konkreten PHP-Fehlertext
  statt einer generischen Fehlermeldung

### C: sw.js PRECACHE_URLS Bugfix
- PRECACHE_URLS hatten ?v=64, jetzt korrekt ?v=70 (synchron mit index.html)

---

## v2.6.9 – Feature Release (22.05.2026)

- APP_VERSION 2.6.8 → 2.6.9 (api.php, manifest.json)
- CACHE_VERSION bk-es-v64 → bk-es-v65 (sw.js), ?v=68 → ?v=69 (index.html)

### A: OCI Punchout Schnittstelle (Großhandel-Integration)

#### Übersicht
Implementiert OCI 4.0/5.0 Punchout: Benutzer öffnet den Online-Shop eines konfigurierten
Lieferanten direkt aus dem Material-Picker, wählt Artikel aus und diese werden automatisch
in die aktuelle Baustelle übernommen. Generische Implementierung, konfigurierbar für
beliebige OCI-Lieferanten (z. B. fega & schmitt, Sonepar, Rexel, Würth).

#### Phase 1 – Datenbank
- `src/Database.php`: Neue idempotente Migrationen für zwei Tabellen:
  - `oci_lieferanten` (id, name, url, username, password, aktiv, notizen, erstelltAm)
  - `oci_nonces` (nonce TEXT PK, lieferantId, erstelltAm, verwendetAm) für Sicherheits-Tokens

#### Phase 2 – Backend Handler
- `src/Handlers/OciActions.php` (NEU): OCI Punchout Handler mit 6 Methoden:
  - `listLieferanten()` – Auth. Lieferantenliste (password maskiert)
  - `saveLieferant()` – Admin-only. INSERT/UPDATE Lieferant; validiert URL
  - `deleteLieferant()` – Admin-only. Löscht Lieferant + verwaiste Nonces
  - `prepare()` – Auth. Erzeugt random_bytes(32)-Nonce (TTL 30 min), speichert in DB
  - `start()` – Kein Session-Auth (Nonce-Validierung). Gibt HTML-Seite zurück,
    die Auto-Submit-Form zum Lieferanten sendet (USERNAME, PASSWORD, HOOK_URL, ~OCI_VERSION=4.0)
  - `hook()` – Kein Session-Auth (Nonce-Validierung). Parst NEW_ITEM-*[n] POST-Parameter,
    gibt HTML+JS zurück: `window.opener.postMessage({type:'oci_items', items:[...]}, window.location.origin)`

#### Phase 3 – API-Routing
- `api.php`: 6 neue Routen in $routes (oci_list_lieferanten, oci_save_lieferant,
  oci_delete_lieferant, oci_prepare, oci_start, oci_hook)
- `api.php`: CSRF-Ausnahme für `oci_hook` (empfängt legitime Cross-Origin-POSTs
  vom Lieferanten-Browser über $_csrfExemptActions)

#### Phase 4 – Admin-UI (Einstellungen)
- `script.js (renderAllgemeinSettings)`: Neuer Abschnitt "🏪 OCI Großhandel – Lieferanten"
  (nur für Admin/Master sichtbar) mit Lieferantentabelle + Inline-Formular
- `script.js`: Neue Funktionen: loadOciLieferanten(), renderOciLieferantenTable(),
  showOciLieferantForm(), saveOciLieferant(), deleteOciLieferant()
- `script.js (showAllgemeinSettings)`: Ruft loadOciLieferanten() automatisch auf

#### Phase 5 – Material-Picker Integration
- `index.html (katalogPickerModal)`: Neuer Button "🏪 OCI" neben dem Quellen-Dropdown
- `script.js`: Neue Funktionen für den Punchout-Flow:
  - `openOciSupplierPicker()` – Lädt Lieferanten; bei 1 aktiven direkt öffnen;
    bei mehreren Auswahl-Overlay (_showOciLieferantSelect)
  - `openOciPunchout(id)` – fetch oci_prepare → window.open(oci_start) → Listener
  - `_onOciMessage(event)` – Validiert window.location.origin, ruft _insertOciItems auf
  - `_insertOciItems(items)` – Mappt OCI-Felder auf Baustelle-Material, saveData(), renderMaterialListe()

#### OCI-Parameter-Mapping
  NEW_ITEM-DESCRIPTION[n]   → bezeichnung (+ "[artNr]" wenn MATNR vorhanden)
  NEW_ITEM-PRICE[n] / PRICEUNIT[n] → ek (Preis ÷ Preiseinheit)
  NEW_ITEM-UNIT[n]          → einheit (ST→Stk., M→m, M2→m², KG→kg, PAK→Pak., ROL→Rolle…)
  NEW_ITEM-QUANTITY[n]      → anzahl

#### Sicherheit
  - Nonces: random_bytes(32) hex, TTL 30 min, start() markiert Nonce als verwendet,
    hook() löscht Nonce (Einmal-Verwendung)
  - postMessage-Origin: window.location.origin (nur gleiche App)
  - Frontend validiert event.origin === window.location.origin
  - oci_hook CSRF-Ausnahme: notwendig da POST vom Lieferanten-Browser kommt (fremder Origin)



- APP_VERSION 2.6.7 → 2.6.8 (api.php, manifest.json)
- CACHE_VERSION bk-es-v63 → bk-es-v64 (sw.js), ?v=67 → ?v=68 (index.html)

### A: ICS-Schnittstelle vollständig umgesetzt

#### Phase 1 – RFC 5545 Konformität
- `src/Helpers.php`: `icalFold()` Funktion ergänzt – faltet Zeilen auf max. 75 Bytes
  (UTF-8-sicher, byte-genaues Schneiden) gemäß RFC 5545 §3.1
- `src/Handlers/PlanActions.php (exportIcal)`: VTIMEZONE-Komponente für
  `Europe/Berlin` (CET/CEST) mit RRULE-basierten DST-Regeln eingefügt
- Termine mit `zeitVon`/`zeitBis` verwenden jetzt `DTSTART;TZID=Europe/Berlin:`
  statt floating time (verhindert DST-Fehler in Kalender-Apps)
- `SEQUENCE:0` und `LAST-MODIFIED` zu allen VEVENTs ergänzt
- Alle VEVENT-Zeilen werden über `icalFold()` ausgegeben

#### Phase 2 – Stundenerfassung im ICS-Feed
- SE-Einträge (typ = urlaub/krank/arbeit) werden in den ICS-Export eingebunden:
  - Urlaub → ganztägiges VEVENT `SUMMARY:Urlaub`
  - Krank  → ganztägiges VEVENT `SUMMARY:Krank`
  - Arbeit → ganztägiges VEVENT `SUMMARY:<Baustellenname> – <X>h`
    inkl. Bemerkung als DESCRIPTION
- Admins sehen alle Mitarbeiter (mit Username in SUMMARY);
  normale User sehen nur eigene Einträge

#### Phase 3 – VALARM-Erinnerungen für Termine
- Termine mit `zeitVon` (nicht ganztags) erhalten ein VALARM:
  `ACTION:DISPLAY / TRIGGER:-PT30M` (30 Minuten vor Termin)

#### Phase 4 – Download-Header und Frontend-Modal
- `api.php ?action=export_wochenplan_ical&download=1` → `Content-Disposition: attachment`
  statt `inline` (Browser lädt .ics herunter statt sie als Text anzuzeigen)
- `script.js (openWpCalendarShare)`: Download-Link nutzt jetzt `&download=1`;
  Modal-Beschreibung zeigt an, was im Feed enthalten ist; Icon und Kopieren-Button
  mit Bestätigungstext (`✓`) verbessert

## v2.6.7 – Enhancement Release (22.05.2026)

- APP_VERSION 2.6.6 → 2.6.7 (api.php, manifest.json)
- CACHE_VERSION bk-es-v62 → bk-es-v63 (sw.js), ?v=66 → ?v=67 (index.html)

### A: Wochenplanung – Hover-Tooltip mit vollständigen Eintrags-Details
- `script.js (renderWochenplanung)`: Alle wp-block-Einträge erhalten jetzt ein `title`-Attribut.
  Bei Mouseover wird der vollständige Eintrag als nativer Browser-Tooltip angezeigt:
  - Baustelle (geplant): Baustelle + Bemerkung
  - Urlaub/Sonstig (geplant): Typ + Bemerkung
  - Feiertag: "Gesetzlicher Feiertag (Bayern)"
  - Urlaub/Krank/Sonstig (aus Stundenerfassung): Typ + "(aus Stundenerfassung)" + Bemerkung
  - Arbeit (aus Stundenerfassung): Baustelle, Stunden, Von/Bis, Bemerkung
  - Termin: Titel, Zeit, Ort, Baustelle, Beschreibung, Zugewiesen, Erstellt von
- `script.js (renderWochenplanung)`: urlaubMap und krankMap ergänzt, um Bemerkungen
  der synced SE-Einträge abrufen zu können

## v2.6.6 – Security & Performance Release (23.05.2026)

- APP_VERSION 2.6.5 → 2.6.6 (api.php, manifest.json)
- CACHE_VERSION bk-es-v61 → bk-es-v62 (sw.js), ?v=65 → ?v=66 (index.html)

### SEC-01: Stored XSS – SVG/HTML nie als inline ausliefern
- `src/Handlers/FileActions.php (downloadFile)`: Content-Disposition nur noch `inline` für
  sichere MIME-Typen (JPEG, PNG, GIF, WebP, PDF). Alle anderen Typen (SVG, HTML, JS, XML …)
  werden als `attachment` gesendet → Browser öffnet keinen inline-Renderer für Skript-fähige Inhalte.

### SEC-02: Path-Traversal Defense-in-Depth (realpath-Guard)
- `src/Handlers/FileActions.php (downloadFile, moveFile)`: Nach allen basename/category-Prüfungen
  wird realpath() des endgültigen Pfads mit dem UPLOADS_DIR-Basisverzeichnis verglichen.
  Schlägt die Prüfung fehl, wird 404 zurückgegeben.

### SEC-03: XSS im JavaScript-Error-Banner
- `script.js (window.onerror, window.onunhandledrejection)`: `innerHTML +=` mit unkontrollierten
  Fehlermeldungen/Rejection-Reasons durch sichere DOM-Text-Knoten (createTextNode) ersetzt,
  um potenziellen XSS-Angriffspfad über e.reason zu schließen.

### SEC-04: Strict-Transport-Security (HSTS) Header
- `api.php`: Wenn `$isHttps === true`, wird `Strict-Transport-Security: max-age=31536000;
  includeSubDomains` gesendet. Verhindert SSL-Stripping bei Folgeaufrufen.

### PERF-01: Doppeltes JSON.stringify in saveData() eliminiert
- `script.js (saveData)`: appData wurde pro Speichervorgang zweimal serialisiert
  (localStorage + fetch-body). Jetzt einmalig in `_appDataJson` zwischengespeichert;
  fetch-body als `'{"data":' + _appDataJson + '}'` zusammengesetzt.

## v2.6.5 – Enhancement Release (22.05.2026)

- APP_VERSION 2.6.4 → 2.6.5 (api.php, manifest.json)
- CACHE_VERSION bk-es-v60 → bk-es-v61 (sw.js), ?v=64 → ?v=65 (index.html)

### A: Stundenerfassung – Zwei Dezimalstellen
- `index.html (seDesktopStunden)`: step="0.25" → step="0.01"
- `script.js (renderSeDesktop)`: Stunden-Anzeige in Tabelle von .toFixed(1) auf .toFixed(2) umgestellt

### B: Stundenerfassung – Baustelle in Buchungsliste anzeigen
- `script.js (renderSeDesktop)`: Wenn baustelleName leer, wird Name per baustelleId
  aus appData.baustellen nachgeschlagen und angezeigt

### C: Stundenerfassung – Stundenkategorie auf Baustelle übertragen
- `index.html (stundenerfassungModal)`: Neues Feld „Stundenkategorie" (id="seDesktopStundenKat",
  id="seDesktopStundenKatWrap"), nur bei Typ „Arbeit" sichtbar
- `script.js (toggleSeDesktopFields)`: seDesktopStundenKatWrap wird bei Typ arbeit ein-/ausgeblendet
- `script.js (openStundenerfassungModal)`: Select wird mit appData.stundenKatalog befüllt;
  currentStundenKategorie des Users wird vorausgewählt falls vorhanden
- `script.js (addStundenerfassungDesktop)`: Nach erfolgreichem Speichern wird bei Typ=arbeit
  mit gewählter Stundenkategorie und Baustelle automatisch ein autoImport-Arbeitszeiteintrag
  in der Baustelle angelegt (stundenKatId, stundenpreis, fixkosten, erstelltVon, autoImport:true)

## v2.6.4 – Bugfix Release (14.05.2026)

- APP_VERSION 2.6.3 → 2.6.4 (api.php, manifest.json)
- CACHE_VERSION bk-es-v59 → bk-es-v60 (sw.js), ?v=63 → ?v=64 (index.html, sw.js)

### A: Lager/Aufmaß – Dashboard-View bleibt sichtbar (Desktop-Fix)
- `modules/lager/lager.js (hideAllOtherViews)`: `'dashboardView'` zur ID-Liste ergänzt
- `modules/aufmass/aufmass.js (hideAllOtherViews)`: `'dashboardView'` zur ID-Liste ergänzt
- Ursache: Beim Öffnen von Lager oder Aufmaß wurde `dashboardView` nicht ausgeblendet,
  sodass Dashboard und Lager/Aufmaß gleichzeitig sichtbar waren.

## v2.6.3 – Enhancement Release (14.05.2026)

- APP_VERSION 2.6.2 → 2.6.3 (api.php, manifest.json)
- CACHE_VERSION bk-es-v58 → bk-es-v59 (sw.js), ?v=62 → ?v=63 (index.html, sw.js PRECACHE_URLS)

### A: NAS-Export Excel – Inhalt vervollständigt
- `script.js (weeklyExportToNAS)`: Bisher fehlende Daten ergänzt:
  - Neuer Block „PAUSCHALEN" (Bezeichnung, Anzahl, Einzelpreis, Gesamt)
  - Neuer Block „ABSCHLAGSRECHNUNGEN" (Bezeichnung/Nummer, Betrag)
  - Gesamtsumme, Abschläge-Summe und Offener Betrag am Ende jedes Baustellen-Sheets
  - Neues Sheet „Übersicht" am Ende: alle Baustellen mit Mat/AZ/Pauschalen/Gesamt/Abschläge/Offener Betrag

### B: Vollexport-Download (Admin/Master)
- `index.html (Footer)`: Neuer Button „⬇ Vollexport (Download)" (id="btnFullExcelDownload"),
  initial display:none, nur für Admin/Master sichtbar
- `script.js (downloadFullExcel)`: Neue Funktion – XLSX vollständig im Browser, Direkt-Download.
  Sheets: Übersicht, je Baustelle (Mat+AZ+Pauschalen+Abschläge), Kunden, Materialkatalog,
  Zeiterfassung (API), Schnellnotizen (API), Dashboard (API)
  Dateiname: Baukalkulation_Vollexport_{datum}.xlsx
- `script.js (init-Block)`: btnFullExcelDownload nach Login für admin/master eingeblendet

## v2.6.2 – Bugfix Release (14.05.2026)

- APP_VERSION 2.6.1 → 2.6.2 (api.php, manifest.json)
- CACHE_VERSION bk-es-v57 → bk-es-v58 (sw.js), ?v=61 → ?v=62 (index.html, sw.js PRECACHE_URLS)

### A: Dashboard Mobile – Neuer Eintrag Sheet jetzt zuverlässig sichtbar
- `mobile.html (openDashboardSheetMobile)`: Umstellung von dynamischem `document.body.appendChild()`
  auf statisches Sheet-HTML-Pattern (wie alle anderen Sheets).
  Ursache: Das dynamisch erstellte und an body-appended Element konnte in bestimmten
  Browser-/iOS-Umgebungen nicht zuverlässig dargestellt werden.
- Neues statisches `<div id="dashboardSheet" class="sheet-overlay hidden">` mit allen
  Formularfeldern (Typ, Titel, Beschreibung, Fällig am, Priorität, Farbe, Zuweisen an)
- `openDashboardSheetMobile(id)`: Befüllt das statische Sheet per DOM-Manipulation und
  öffnet es via `openSheet('dashboardSheet')` (inkl. Swipe-to-close Unterstützung)
- `saveDashboardItemMobile()`: Liest Edit-ID aus `_dashboardEditId` (Modul-Variable),
  schließt Sheet via `closeSheet('dashboardSheet')` statt `.remove()`
- Zuweisen-an Dropdown wird bei jedem Öffnen zurückgesetzt und neu befüllt

## v2.6.1 – Bugfix & Enhancement Release (14.05.2026)

- APP_VERSION 2.6.0 → 2.6.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v56 → bk-es-v57 (sw.js), ?v=60 → ?v=61 (index.html, style.css, script.js)
- Lager-Modul: lager.js/lager.css ?v=51→?v=53 (script.js), ?v=52→?v=53 (mobile.html)

### A: Kalender-Abo – kein Authentifizierungsdialog mehr
- `src/Handlers/PlanActions.php (exportIcal)`: HTTP 401 durch 400/403 ersetzt.
  401 ohne WWW-Authenticate triggerte Auth-Dialog in Apple Calendar/Outlook/Thunderbird.
  Kein Token → 400 Bad Request; ungültiger Token → 403 Forbidden.

### B: KI-Fotoanalyse – Katalog- und Datanorm-Vergleich mit EK-Preisübernahme
- `modules/lager/backend/Module.php (fotoAi)`: Nach lager_treffer-Suche wird zusätzlich
  der Datanorm-Index (data/datanorm_index.tsv) nach übereinstimmenden Tokens durchsucht
  (OR-Verknüpfung, max. 5 Treffer). Ergebnis als `katalog_treffer[]` mit artNr,
  bezeichnung, einheit, ek, lp, quelle='Datanorm'.
- `modules/lager/lager.js (_showAiVorschau)`:
  - Neue Sektion „📦 Katalog-Vorschläge" unterhalb der Lager-Treffer, zeigt
    Backend-Datanorm-Treffer + Frontend-Treffer aus appData.materialKatalog (max. 3)
  - Klick auf Katalogeintrag übernimmt Bezeichnung, Artikelnr, Einheit und EK-Preis
    aus dem Katalog; Menge/Kategorie/Notiz aus der KI-Erkennung bleiben erhalten
- `modules/lager/lager.js (_applyAiFromKatalog)`: Neue Funktion – merged Katalogdaten
  mit KI-Zusatzfeldern und ruft _applyAiVorschlag(merged) auf
- `state._aiJson`: Speichert den KI-Antwort-Payload temporär für spätere Übernahme

## v2.6.0 – Feature Release (15.05.2026)

- APP_VERSION 2.5.1 → 2.6.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v55 → bk-es-v56 (sw.js), ?v=59 → ?v=60 (index.html)
- Lager-Modul: lager.js/lager.css ?v=50 → ?v=51 (script.js), ?v=51 → ?v=52 (mobile.html)

### A: KI-Bilderkennung im Lagermodul (Google Gemini 2.0 Flash Lite)
- `src/Auth.php ($defaults)`: Neues Setting `gemini_api_key` (leer = deaktiviert)
- `modules/lager/backend/Module.php`: Neue Actions `foto_ai` und `foto_ai_available`
  - `fotoAiAvailable()`: Gibt zurück, ob ein Gemini-API-Key konfiguriert ist
  - `fotoAi()`: Bild-Validierung (MIME, 8 MB), GD-Vorverarbeitung (EXIF-Rotation,
    Skalierung auf 800-1600 px), base64-Encoding, cURL POST an Gemini REST-API,
    JSON-Antwort mit bezeichnung/artikelnr/menge/einheit/kategorie/ek_preis/notiz/confidence,
    Suche nach ähnlichen Lagerartikeln in der Datenbank
- `script.js (renderAllgemeinSettings)`: Neuer Abschnitt "🤖 KI-Bilderkennung" in
  Admin-Einstellungen; Passwort-Input für Gemini API-Key (sichtbar/unsichtbar umschalten),
  Link zu aistudio.google.com/apikey
- `modules/lager/lager.js`:
  - `_checkOcrAvailable()`: Prüft parallel OCR (Tesseract) und KI (Gemini);
    setzt `state.ocrAvailable` und `state.aiVisionAvailable`
  - Foto-Button in openArtikelDialog: Label "🤖 Foto per KI analysieren" wenn KI
    verfügbar, sonst "📷 Foto scannen"; disabled nur wenn beides nicht verfügbar
  - `_sendFotoOcr()`: Leitet an `_sendFotoAi()` weiter, wenn `state.aiVisionAvailable`
  - `_sendFotoAi()`: Lade-Spinner → POST an foto_ai → `_showAiVorschau()` oder Fehlerhinweis
  - `_showAiVorschau()`: Vorschau-Modal mit Konfidenz-Balken (grün/orange/rot),
    erkannten Feldern, ähnlichen Lagerartikeln als klickbare Liste;
    "✓ Übernehmen" füllt Artikelformular via `_applyAiVorschlag()`
  - `_applyAiVorschlag()`: Öffnet Artikeldialog, befüllt alle Felder
    (inkl. Kategorie, Notiz, Menge die in _fillArtikelFromKatalog fehlen)

## v2.5.1 – Bugfix Release (13.05.2026)

- APP_VERSION 2.5.0 → 2.5.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v54 → bk-es-v55 (sw.js), ?v=58 → ?v=59 (index.html)

### A: Termine im mobilen Wochenplan
- `mobile.html (wpLoadDataMobile)`: Lädt nun zusätzlich `load_termine` parallel;
  Ergebnis wird in `mobileTermine` gespeichert
- `mobile.html (renderWochenplanungMobile)`: Termine werden als farbige "T"-Blöcke
  pro Tag und Mitarbeiter angezeigt (eigene + zugewiesene Termine)

### B: Dashboard Mobile – Add-Button Berechtigung
- `mobile.html (applyPermissions)`: `btnDashboardAddMobile` wird nun anhand von
  `canWriteDashboard` ein-/ausgeblendet (war zuvor immer sichtbar)

### C: Backup – dashboard_items in restore() ergänzt
- `src/Handlers/DataActions.php (restore)`: Neuer `sectionRestore`-Block für
  `dashboard_items` mit allen 14 Feldern; Dashboard-Einträge gehen bei
  Backup-Restore nicht mehr verloren

### D: Stundenexport Mobil – Web Share API
- `mobile.html (mobilePrintHtml)`: Neue Logik: immer Blob via html2pdf erzeugen,
  dann Web Share API (natives iOS/Android Share-Sheet) nutzen falls verfügbar,
  sonst direkter Blob-Download; letzter Fallback: Browser-Druckdialog.
  `mobileIsPwa()`-Abfrage entfällt im Haupt-Pfad

### E: Kalenderabo-Button in Termine-Ansicht
- `mobile.html (viewTermine Topbar)`: 📅-Button ruft `openWpCalendarShareMobile()` auf
- `script.js (openTermineModal)`: 📅-Button in Modal-Header ruft `openWpCalendarShare()` auf
- Dialog-Titel in beiden Kalenderabo-Dialogen: "Wochenplan abonnieren" →
  "Wochenplanung & Termine abonnieren" (iCal enthält beides)

## v2.5.0 – Feature Release (14.05.2026)

- APP_VERSION 2.4.0 → 2.5.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v53 → bk-es-v54 (sw.js), ?v=57 → ?v=58 (index.html)

### A: Dashboard – Zuweisung & Manager-Filter
- `src/Database.php`: Spalte `zugewiesen_an TEXT DEFAULT ''` in dashboard_items
  (getSchema() + runMigrations() mit idempotenter ALTER TABLE Migration für bestehende DBs)
- `src/Handlers/DashboardActions.php`:
  - load(): canManage-Flag in Response; filterUser-Parameter für Admin/Manager;
    normale Nutzer sehen eigene UND ihnen zugewiesene Einträge (OR zugewiesen_an)
  - saveItem(): zugewiesen_an in INSERT und UPDATE
  - rowToItem(): zugewiesen_an Feld in Rückgabe
  - updateSort() (neu): batch sortPos Update mit Berechtigungscheck
- `api.php`: neue Route update_dashboard_sort
- `src/Auth.php`: mobile_startseite und mobile_btn_order in $defaults

### B: Dashboard – Sortierung (Desktop + Mobile)
- `script.js`: ↑↓ Buttons pro Item in renderDashboardItem(); moveDashboardItem(id,dir)
  tauscht sortPos benachbarter Items und sendet an update_dashboard_sort
- Manager-Filter-Bar ("Alle Mitarbeiter / Meine / Mir zugewiesen") im Dashboard
- _dashboardOwnerFilter + _dashboardCanManage State-Variablen
- zugewiesen_an-Badge in Desktop-Dashboard-Items
- Zuweisungs-Select in openDashboardItemForm() (Benutzer per list_users API)

### C: Mobile App – Startseite & Button-Reihenfolge
- `src/Auth.php`: mobile_startseite, mobile_btn_order in Settings-Defaults
- `script.js (renderAllgemeinSettings)`: Mobile Startseite-Select +
  Button-Reihenfolge-Editor mit ↑↓ Buttons und Speichern/Zurücksetzen;
  renderMobileBtnOrderList(), moveMobileBtnInList(), resetMobileBtnOrder()
- `mobile.html (Init-IIFE)`: mobile_startseite wird nach showList() ausgewertet
  und ruft entsprechende show*-Funktion auf
- `mobile.html (applyModuleSettings)`: ruft applyMobileButtonOrder() auf
- applyMobileButtonOrder(): sortiert Buttons in #mainActionBar per DOM-Manipulation
  anhand der in mobile_btn_order gespeicherten Reihenfolge

### D: Mobile App – Dashboard Zuweisung & Manager-Filter
- `mobile.html`: _mDashOwnerFilter, _mDashCanManage State
- showDashboardMobile(): lädt mit filterUser-Payload; canManage aus Response
- renderDashboardMobile(): Owner-Filter-Bar für Manager; zugewiesen_an-Badge
- openDashboardSheetMobile(): Zuweisungs-Select mit Benutzer-Laden per API
- saveDashboardItemMobile(): zugewiesen_an im Payload

## v2.4.0 – Feature/Bugfix Release (13.05.2026)

- APP_VERSION 2.3.0 → 2.4.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v52 → bk-es-v53 (sw.js), ?v=56 → ?v=57 (index.html)

### A: Dashboard-Modul (Aufgaben, Notizen, Erinnerungen)
- Neues Modul "Dashboard" mit eigenem Menüpunkt (Desktop + Mobile)
- `src/Database.php`: Neue Tabelle `dashboard_items` (id, typ, titel, beschreibung,
  faelligAm, status, prioritaet, linkTyp, linkId, ersteller, erstelltAm, farbe, sortPos)
  sowohl in getSchema() als auch runMigrations() (idempotent)
- `src/Handlers/DashboardActions.php` (neu): CRUD-Backend mit load(), saveItem(),
  deleteItem(), updateStatus(). Besitzer-Check, canReadDashboard/canManageDashboard
- `api.php`: 4 neue Routen — load_dashboard, save_dashboard_item,
  delete_dashboard_item, update_dashboard_item_status
- `src/Auth.php`: modul_dashboard (Default: false) in $defaults; Berechtigungen
  canReadDashboard, canWriteDashboard, canManageDashboard in getDefaultPermissions()
- `index.html`: btnDashboard-Button, dashboardView-Container
- `style.css`: Vollständiges Dashboard-CSS (Filter-Bar, Spalten, Items, Badges)
- `script.js`: showDashboard(), loadDashboard(), renderDashboard(), renderDashboardItem(),
  openDashboardItemForm(), saveDashboardItem(), deleteDashboardItem(), toggleDashboardStatus();
  dashboardView hide in allen View-Switches; Berechtigungs-Labels + permGroup;
  applyModuleSettings() + applyPermissions() Erweiterung
- `mobile.html`: viewDashboard-Container, btnDashboardMobile, VIEWS-Array, Dashboard-
  Funktionen showDashboardMobile(), renderDashboardMobile(), mDashSetFilter(),
  openDashboardSheetMobile(), saveDashboardItemMobile(), mToggleDashboardStatus(),
  mDeleteDashboardItem(); applyPermissions() + applyModuleSettings() Erweiterung
- Modul aktivierbar unter Einstellungen > Allgemein, Berechtigungen in Benutzerverwaltung

### B: Bugfixes
- `src/Auth.php`: modul_stundenauswertung und modul_auswertung fehlten in $defaults
  (Toggle-Einstellung wurde nicht gespeichert) → hinzugefügt
- `mobile.html`: `saveTerminMobile()` sendete immer `zugewiesen: []` (Mitarbeiter
  wurden nicht gespeichert); showTerminFormMobile() lädt Benutzer per API und rendert
  Checkbox-Gruppe → zugewiesene Mitarbeiter werden korrekt übermittelt

## v2.3.0 – Feature/Bugfix Release (12.05.2026)

- APP_VERSION 2.2.3 → 2.3.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v51 → bk-es-v52 (sw.js), ?v=55 → ?v=56 (index.html)

### A: Upload-Ansicht in Projekten — Kategorien gruppiert
- script.js `loadBaustelleFiles()`:
  * Bei Tab "Alle": Dateien werden nach Kategorie gruppiert angezeigt
    (Kategorie-Header mit Anzahl-Badge, darunter Grid der Karten)
  * Bei gezielt gewählter Einzelkategorie: Verhalten wie bisher (flaches Grid)
  * Kategorie-Badge innerhalb der Karte entfernt (redundant bei Einzelansicht,
    und bei "Alle" übernimmt der Abschnitts-Header die Orientierung)
- style.css:
  * `.baustelle-files-grid` → `display:block` statt Grid (Container-Rolle)
  * `.baustelle-files-grid-inner` → Grid für die eigentlichen Karten (auto-fill)
  * `.files-cat-section` + `.files-cat-header` + `.files-cat-count` → neue Klassen
    für Kategorie-Abschnitte (Header mit Trennlinie, Zähler-Badge)

### B: Mobile Baustellensuche — Multi-Token + Kundendaten
- mobile.html `renderList()` (Hauptliste):
  * Multi-Token-AND-Suche wie Desktop (Leerzeichen trennt Suchbegriffe)
  * Sucht jetzt: Name + ProjektNr + Kunden-Daten (firma, vorname, nachname, kundennr.)
- mobile.html `filterSeBaustellen()` (Schnellerfassung Stunden):
  * Identische Verbesserung: Multi-Token + ProjektNr + Kundendaten
- mobile.html `wpMobileFilterBaustellen()` (Wochenplanung):
  * Identische Verbesserung: Multi-Token + ProjektNr + Kundendaten

### C: Lager-Modul + Verwaltung→Allgemein Überlappung
- script.js `showAllgemeinSettings()`:
  * `hide('lagerView'); hide('din1090View'); hide('aufmassView');` ergänzt
  * Verhindert dass Modul-Views noch sichtbar sind wenn Allgemein-Einstellungen
    geöffnet werden (fehlende hide-Aufrufe wie in allen anderen View-Funktionen)

### D: Foto-OCR Mobile — Preprocessing korrigiert
- modules/lager/backend/Module.php + modules/aufmass/backend/Module.php `fotoOcr()`:
  * GD-Kontrast: -45 → -20 (weniger aggressiv, robuster bei wechselnder Beleuchtung)
  * Noise-Filter-Schwelle: 35% → 15% (weniger OCR-Zeilen fälschlich verworfen)
  * Safeguard: processedPath wird auf null gesetzt wenn imagepng eine leere/ungültige
    Datei erzeugt (Fallback auf Original-Bild)



- APP_VERSION 2.2.2 → 2.2.3 (api.php, manifest.json)
- CACHE_VERSION bk-es-v50 → bk-es-v51 (sw.js), ?v=54 → ?v=55 (index.html)

### A: Foto-OCR – Rotationsrichtung und PSM gefixt
- modules/lager/backend/Module.php + modules/aufmass/backend/Module.php:
  * Bugfix: EXIF-Orientierung 6 wurde mit 90° CCW rotiert (falsch) statt 90° CW
    (PHP GD imagerotate dreht bei positiven Werten gegen den Uhrzeigersinn)
    Korrekt: { 3 => 180, 6 => 270, 8 => 90 }
    → bei Orientation 6 (häufigster Fall: Portrait-Kamerafoto auf Android/iOS)
      war das Bild nach dem Fix noch schlechter → "Kein Text erkannt"
  * Tesseract --psm 6 → --psm 3 (auto page segmentation, robuster für Kamerafotos
    mit Umgebung/Kontext um das Etikett)



- APP_VERSION 2.2.1 → 2.2.2 (api.php, manifest.json)
- CACHE_VERSION bk-es-v49 → bk-es-v50 (sw.js), ?v=53 → ?v=54 (index.html)

### A: Foto-OCR – Mobile Kamerafotos korrekt ausgerichtet
- Dockerfile.app: PHP `exif`-Extension (`docker-php-ext-install ... exif`) ergänzt
  → Container-Rebuild erforderlich: `docker compose up -d --build --no-cache`
- modules/lager/backend/Module.php `fotoOcr()`:
  * EXIF-Orientation wird per `exif_read_data()` ausgelesen und GD-Bild
    entsprechend rotiert (Orientation 3=180°, 6=90°, 8=270°) bevor OCR läuft
  * Verhindert dass Tesseract ein seitliches/auf-dem-Kopf stehendes Bild sieht
- modules/aufmass/backend/Module.php `fotoOcr()`:
  * Identische EXIF-Rotation wie Lager



- APP_VERSION 2.2.0 → 2.2.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v48 → bk-es-v49 (sw.js), ?v=52 → ?v=53 (index.html)

### A: Foto-OCR – Erkennungsqualität verbessert (Lager + Aufmaß)
- modules/lager/backend/Module.php `fotoOcr()`:
  * GD-Bildvorverarbeitung: Graustufen, Kontrast (-45), Schärfen, Hochskalierung auf
    min. 1200px (bicubic) → erheblich bessere Tesseract-Eingabe
  * Tesseract-Aufruf: --psm 6 --oem 1 (einheitlicher Textblock + LSTM-Netz)
    mit Fallback auf --psm 11 (sparse text) bei leerem Ergebnis
  * OCR-Text-Bereinigung: Zeilen mit >65% Sonderzeichen werden verworfen
  * Tokenisierung: Artikelnummer-Kandidaten (enthalten Ziffern) kommen zuerst,
    Duplikate entfernt, bis zu 10 Tokens (statt 6), min. 2 Zeichen + ≥1 Wort-Zeichen
  * Datanorm-Trefferlimit: 10 → 15
- modules/aufmass/backend/Module.php `fotoOcr()`:
  * Identische GD-Vorverarbeitung und Tesseract-Verbesserungen wie Lager



- APP_VERSION 2.1.1 → 2.2.0 (api.php, manifest.json)
- CACHE_VERSION bk-es-v47 → bk-es-v48 (sw.js), ?v=51 → ?v=52 (index.html)

### A: Lager-Modul – Artikel aus Katalog oder Foto einfügen
- modules/lager/backend/Module.php: Neuer Endpunkt `foto_ocr`
  * MIME-Validierung (nur Rasterbilder), max. 8 MB
  * Tesseract-OCR via shell_exec, Temp-Datei sofort nach OCR gelöscht
  * Tokenbased Suche in lager_artikel + datanorm_index.tsv
  * Gibt { ok, text, lager_treffer, datanorm_treffer } zurück
  * Gibt hint zurück wenn Tesseract nicht installiert (kein Fehler)
- modules/lager/lager.js:
  * openArtikelDialog(): Toolbar mit „🔍 Aus Katalog suchen" und „📷 Foto scannen"
  * _checkOcrAvailable(): Feature-Probe beim ersten Dialog-Öffnen
  * _openKatalogPickerLager(): Overlay-Picker, Datanorm-API + appData.materialKatalog
  * _lkpSearch() / _lkpPick() / _lkpBack(): Picker-Logik
  * _fillArtikelFromKatalog(): füllt nur leere Felder
  * _openFotoScanLager() / _sendFotoOcr(): Foto-Flow mit Kamera-Input und Trefferliste
  * state.ocrAvailable hinzugefügt
- modules/lager/lager.css: Toolbar, Ergebnis-Badges, OCR-Spinner, Textvorschau

### B: Aufmaß-Modul – Positionen aus Katalog oder Foto einfügen
- modules/aufmass/backend/Module.php: Neuer Endpunkt `foto_ocr`
  * Identische Sicherheitsprüfungen wie Lager-OCR
  * Sucht nur im Datanorm-Index (benötigt nur canReadAufmass)
- modules/aufmass/aufmass.js:
  * renderEditor(): Positionen-Toolbar mit „🔍 Aus Katalog", „📷 Foto", „+ Position"
  * _checkOcrAvailableAm(): Feature-Probe beim ersten Editor-Aufruf
  * _openKatalogPickerAm() / _lkpAmSearch() / _lkpAmPick(): Katalog-Picker Overlay
  * _openFotoScanAm() / _sendFotoOcrAm() / _amOcrPick(): Foto-Flow
  * _addPositionFromKatalog(): fügt neue Position mit Bez., Einheit, EK, Art.-Nr. ein
  * state.ocrAvailable hinzugefügt
- modules/aufmass/aufmass.css: .aufmass-pos-actions Flex-Gruppe, responsive ≤600px

### C: Sicherheitsfixes (aus v2.1.2-pre)
- src/Handlers/AdminActions.php: SVG aus erlaubten Logo-MIME-Types entfernt
  (verhindert Stored XSS durch SVG mit eingebettetem JavaScript)
- migrate.php: CLI-Guard (PHP_SAPI !== 'cli' → 403) verhindert Browser-Aufruf
- din1090_api.php: Referrer-Policy: same-origin Header ergänzt



- APP_VERSION 2.1.0 → 2.1.1 (api.php, manifest.json)
- CACHE_VERSION bk-es-v46 → bk-es-v47 (sw.js), ?v=50 → ?v=51 (index.html)

### A: Zeiterfassung – Überschneidungscheck
- Backend (ZeiterfassungActions.php): Paarweiser Overlap-Check bei
  `erweiterte_zeiterfassung=true`; bei Kollision HTTP 400 mit Fehlermeldung
- Desktop (script.js): Overlap-Check vor dem Speichern in `addStundenerfassungDesktop()`;
  Normal-Modus: Hinweis wenn Tagessumme >10h (§ 3 ArbZG)
- Mobile (mobile.html): Gleicher Check in `saveStundenerfassung()`,
  Edit-Modus berücksichtigt editingSeId
- Mobile Light (mobile_light.html): Gleicher Check in `saveEntry()`

### B: Mobile – Module SW-Cache & canDo-Konsistenz
- mobile.html: Dynamisches Laden von lager.js/aufmass.js und deren CSS
  jetzt mit `?v=51`-Parameter (verhindert SW-Cache-Problem nach Updates)
- lager.js + aufmass.js: `window.canDo` → bare `canDo` (Konsistenz)

### C: Offenes Material – Lager-Hint Bug-Fix
- script.js `renderOffenesMaterial()`: Drei Bugs im Lager-Hint-Block behoben:
  * `window.appSettings` → `appSettings` (let-Variable, nicht auf window)
  * `idToBsMap` für baustelleId-Lookup via eintragId aufgebaut
  * Response-Felder korrigiert: `mt.eintragId`, `mt.artikelId`, `mt.menge`,
    `mt.einheit`, `mt.lagerort_name` (statt fälschlich `mt.artikel.*`)

## v2.1.0 – Release (Phase 4)

- APP_VERSION 2.0.1 → 2.1.0 (api.php, manifest.json)
- Smoketest tests/smoketest_2_1_0.php hinzugefügt:
   * prüft Versions-Konsistenz (api.php / manifest.json /
     sw.js / index.html ?v=49)
   * prüft Tabellen lager_artikel, lager_ort, lager_buchung,
     aufmass, aufmass_abschnitt, aufmass_position und deren
     Spalten (status, uebernommen_am, formel, ref_typ, …)
   * prüft die 6 neuen Permission-Defaults
   * lädt die Modul-Klassen (Lager + Aufmaß)
   * isolierter Aufmaß-CRUD inkl. CASCADE-Löschtest
   * Status-Übergang entwurf→geprueft→uebernommen
- Git-Tag v2.1.0
- Damit ist v2.1 abgeschlossen: Module „Lager" und „Aufmaß"
  vollständig (Phase 1 Fundament, Phase 2 Lager,
  Phase 3 Aufmaß).

## v2.1 – in Arbeit (Phase 3: Aufmaß-Modul vollständig)

Phase 3 – Aufmaß als eigenständiges Modul:
- Backend (modules/aufmass/backend/Module.php) bietet:
   * list  – mit Filter ?baustelleId=, plus Aggregate
     (Anzahl Positionen, Σ menge*einzelpreis der ausgewählten)
   * load  – Aufmaß + Abschnitte + Positionen einzeln
   * save  – transaktional, Upsert-Pattern; tempIds (<0)
     werden in absIdMap auf neue Abschnitt-IDs gemappt;
     entfernte Zeilen werden gelöscht (existingIds-Diff)
   * delete – nur entwurf/geprueft, „uebernommen" gesperrt
   * status – Übergänge entwurf↔geprueft;
     entwurf erfordert canWriteAufmass,
     geprueft erfordert canApproveAufmass,
     uebernommen ist gesperrt (nur via uebernehmen)
   * uebernehmen – sperrt das Aufmaß, bucht Lager-Artikel ab
     (ref_typ='lager', delta=-menge), liefert die selektierten
     Positionen + lager_warnungen zurück; das Frontend fügt
     diese als Material-Zeilen in der Baustelle ein.
- Alle schreibenden Aktionen werden über AuditService geloggt.
- Desktop-UI (modules/aufmass/aufmass.js + aufmass.css):
   * Listenansicht mit Karten-Grid, Live-Suche (Bezeichnung
     + Bemerkung), Status- und Baustellen-Filter
   * Editor mit Kopfdaten (Baustelle, Bezeichnung, Datum,
     Bearbeiter, Bemerkung), Abschnitten und Positionen-Tabelle
   * Sicherer Formel-Parser: nur Zeichen [0-9 . , + - * / ( )]
     erlaubt, Klammer-Balance geprüft, Auswertung über
     `new Function('"use strict"; return ('+s+');')` – kein
     eval, keine Bezeichner zulässig. Komma wird intern als
     Dezimalpunkt gelesen, Ergebnis live unter dem Eingabefeld.
   * Pro Position: Auswahl-Checkbox (für Übernahme),
     Bezeichnung, Notiz, Formel→Menge, Einheit, Einzelpreis
     (sichtbar je nach canSeePrices), Abschnitt-Zuordnung,
     Lager-Picker (📦, ohne Buchung) zur Verknüpfung von
     ref_typ='lager' / ref_id, Löschen.
   * Tabellen-Footer zeigt Σ menge*einzelpreis der ausgewählten
     Positionen.
   * Aktionen: Speichern, Prüfen ⇄ Zurück auf Entwurf,
     Übernehmen, Löschen – alle berechtigungsabhängig.
- Anbindung an Projekt-Material:
   * Neuer Button „📏 Aufmaß" im Material-Bereich der Baustelle
     (sichtbar wenn modul_aufmass aktiv und canReadAufmass).
     Öffnet die Aufmaß-Liste mit voreingestelltem Baustellen-
     Filter (window.showAufmassForBaustelle(id)).
   * „Übernehmen" im Editor: erzeugt aus den ausgewählten
     Positionen Material-Einträge in der Baustelle (mit
     quelleAufmassId / quelleAufmassPosId), bucht ggf.
     verknüpfte Lager-Artikel ab und sperrt das Aufmaß.
- Mobile (mobile.html):
   * Action-Bar-Button „Aufmaß" (analog Lager) – sichtbar nur
     wenn modul_aufmass aktiv und canReadAufmass; Assets
     (aufmass.css/js) werden bei Bedarf nachgeladen.
- Sichtbarkeit: hide('din1090View')-Aufrufe in script.js
  um hide('lagerView') und hide('aufmassView') ergänzt, damit
  das Wechseln zwischen Top-Level-Ansichten beide Module
  korrekt ausblendet.
- Cache: CACHE_VERSION → bk-es-v44, ?v=49 in index.html.

============================================================

## v2.1 – in Arbeit (Phase 2: Lager-Modul vollständig)

Phase 2 – Lagerbestand vollständig nutzbar:
- Backend (modules/lager/backend/Module.php) bietet Endpoints:
   * orte_list / ort_save / ort_delete (Lagerort-Verwaltung,
     Schutz vor Löschen bei zugewiesenen Artikeln)
   * artikel_list (Multi-Token-AND-Suche über Bezeichnung,
     Artikel-Nr. und Kategorie; Filter: Lagerort, nur mit
     Bestand, unter Mindestbestand)
   * artikel_save / artikel_delete
   * buchung (Delta-basiert, transaktionsgesichert, lehnt
     negative Endbestände ab; optional baustelleId)
   * match_offenes_material (unscharfer Abgleich mit Substring-,
     Token-Jaccard- und similar_text-Fallback, Schwelle 0.55)
- Alle schreibenden Aktionen werden über AuditService geloggt.
- Desktop-UI (modules/lager/lager.js + lager.css):
   * Übersichtstabelle mit Bestand, Mindestbestand-Warnung,
     EK-Spalte je nach canSeePrices
   * Suche mit 200 ms Debounce, Treffer-Highlight via <mark>
   * Filter „nur mit Bestand" und „unter Mindestbestand"
   * Lagerort-Filter
   * Artikel-Dialog (anlegen/bearbeiten), Buchungs-Dialog
     (Zugang +, Abgang −, Korrektur ⚖) mit Pflichtfeld
     „Grund", Lagerorte-Verwaltung im eigenen Modal
   * Berechtigungs-aware UI: ohne canWriteLager keine
     Schreibaktionen, ohne canManageLagerorte kein Verwalten
- Anbindung Projekt-Material:
   * Im Projekt → Material neuer Button „📦 Aus Lager".
     Auswahl + Mengenabfrage → Lager-Buchung (Abgang) +
     Eintrag im Projektmaterial. Nur sichtbar, wenn Modul
     aktiv und User canReadLager.
- Anbindung Offenes Material:
   * Pro offene Position wird im Hintergrund gegen den
     Lagerbestand gematcht; Treffer erscheinen als grüner
     Hinweis „📦 Lager: N {Einheit}" mit Button
     „Entnehmen" → Buchung + Entfernen aus offene Liste.
- Mobile (mobile.html): Action-Bar-Button „📦 Lager" (braun).
  Aktiv, wenn Modul + canReadLager; Suche + Bedienung
  identisch zur Desktop-Version (Layout per Media Query).
- Cache: bk-es-v43, ?v=48 für style.css und script.js.

Hinweis: Phase 3 (Aufmaß-UI + Backend) und Phase 4 (Release
v2.1.0, Smoketest, Tag) folgen.

## v2.1 – in Arbeit (Phase 1: Fundament)

Zwei neue Module: „Aufmaß" und „Lagerbestand". In Phase 1 ist das
Fundament gelegt; die volle UI/Logik folgt in den Phasen 2 und 3.

Phase 1 – Schalter, Berechtigungen, Modulskelette:
- Verwaltung → Allgemein: zwei neue Modulschalter „Aufmaß" und
  „Lagerbestand / Lagerhaltung". Default aus.
- Benutzerverwaltung → Berechtigungen: neue Gruppe „Aufmaß & Lager"
  mit den Rechten canReadAufmass / canWriteAufmass / canApproveAufmass
  und canReadLager / canWriteLager / canManageLagerorte. Master = alle
  Rechte, Normal = nur lesen.
- Sidebar-Buttons „📏 Aufmaß" und „📦 Lager" werden dynamisch geladen
  (analog zum DIN-1090-Modul), wenn der jeweilige Schalter aktiv ist
  UND der User canRead*-Recht besitzt.
- Datenbankschema (idempotent, läuft beim ersten Boot mit aktivem
  Modul) angelegt:
   * Aufmaß: aufmass, aufmass_abschnitt, aufmass_position
   * Lager:  lager_orte, lager_artikel
- Cache: bk-es-v42, ?v=47 für style.css und script.js.

Hinweis: Beide Module zeigen aktuell nur Platzhalter-Views. Phase 2
(Lager-UI + Backend) und Phase 3 (Aufmaß-UI + Backend) folgen.

## v2.0.1 – UX-Hardening

Schwerpunkt: Bedienkomfort, weniger Klicks, robustere Mobile-Exporte
und mehr Transparenz im Admin-Bereich. Keine Schemabrüche, additive
Migration (lastLoginAt-Spalte).

Phase A1 – Seitenleiste resizbar:
- Drag-Handle am rechten Rand der linken Sidebar (Desktop).
- Breite zwischen 220 und 520px wählbar, Doppelklick = Reset auf 280px.
- Persistierung in localStorage (bk_sidebarWidth).

Phase A2 – Smartere Projektsuche:
- Suchfeld in der Projektliste arbeitet jetzt mit Multi-Token-AND.
- Tokens werden separat gegen Projektname, Projektnummer und
  zugeordneten Kunden (Firma + Name) geprüft.
- Beispiele: "müller wohnhaus" oder "2025 erfurt" finden Treffer
  in beliebiger Reihenfolge.

Phase A3 – Kompakte Aktions-Menüs (3-Punkte-Dropdowns):
- Header-Badges: Excel/PDF/Rechnung/Angebot in Dropdown
  zusammengefasst.
- Material-Header: EK-Toggle, Import, Export, Kategorie verwalten.
- Arbeitszeit-Header: Import, Export.
- Bautagebuch-Header: NAS-Export, PDF-Druck.
- Primäre Aktionen ("Aus Katalog", "+Zeile", "Tagesbericht" …)
  bleiben sichtbar.

Phase B – 7. Dokumentenordner "Sonstiges":
- Neue Kategorie "Sonstiges" zusätzlich zu den bestehenden 6.
- Permission-Defaults erweitert: canReadSonstiges (alle),
  canWriteSonstiges (Admin standardmäßig).
- Dateien-Bereich Desktop: Tabs je Kategorie mit Anzahl-Badge,
  Filter, Move-Dialog ("Datei verschieben") inklusive Kategorie-
  Auswahl beim Upload.
- Dateien-Bereich Mobile: Pill-Tabs zur Filterung, Upload mit
  Ordner-Abfrage; Move-Dialog für mobile später.

Phase C – Letzter Login + Admin-UX:
- Migration: users.lastLoginAt (INTEGER, default 0).
- Login-Erfolg setzt lastLoginAt = time().
- Benutzerliste zeigt farbiges Badge: grün <7 Tage, orange <30,
  grau älter, hellgrau "noch nie".
- Benutzer-Modal umsortiert: "Neuen Benutzer anlegen" oben mit
  inline-Anlegen-Button, danach Benutzerliste.
- Berechtigungen-Editor jetzt in 8 ausklappbare Gruppen sortiert
  (Kunden, Baustellen, Material, Arbeitszeit, Wochenplan,
  Rechnungen, Auswertungen, Dokumentenordner). Auf-/Zugeklappt-
  Status pro Gruppe in localStorage gespeichert.

Phase D – ⭐ Oft verbaut im Material-Picker:
- Neue API: action=material_top_used (limit 5–100, default 30).
- Aggregiert Material aller Baustellen nach Bezeichnung+Einheit
  und liefert Top-Treffer mit Anzahl/Summe.
- Desktop-Picker: Section "⭐ Oft verbaut" über der Suche, sofern
  Suchfeld leer.
- Mobile-Picker: Section "⭐ Oft verbaut" beim Öffnen sichtbar,
  Tap fügt Position direkt hinzu.

Phase E – Mobile Export-Audit:
- Datei-Downloads in Mobile (Bilder/Dokumente) erkennen iOS-PWA-
  Standalone-Modus und liefern Blob+<a download>, statt das im
  PWA-Modus blockierte window.open.
- PDF-Drucke (Zeitübersicht, Stundenübersicht, Stundenauswertung,
  Bauberichte) verwenden in der PWA jetzt html2pdf + Blob-Download
  als Fallback. Im Browser bleibt es bei window.open + print.

Versionierung & Cache:
- APP_VERSION 2.0.1
- manifest.json 2.0.1
- sw.js CACHE_VERSION = 'bk-es-v41' (Hotfixes nach Sidebar/Datanorm)
- index.html ?v=46 für style.css und script.js

Hotfix nach Release:
- Sidebar-Suche warf "kundeMatchIds is not defined" → Set wieder
  korrekt aufgebaut, zusätzlich liefert die Suche jetzt auch Treffer
  über zugeordnete Kunden ("Kunde"-Badge im Gruppenkopf).
- Datanorm-Pfad ist jetzt in den Speicherpfaden änderbar (Schlüssel
  'datanorm'). Default ist <DATA_DIR>/datanorm/, wird automatisch
  angelegt und liegt damit im persistenten Daten-Volume.
- Datanorm-Dateien können jetzt direkt aus der Anwendung
  hochgeladen werden (Material-Katalog → Karte 'Datanorm /
  Großhandel-Katalog'). Drei Slots für datanorm.001, datanorm.wrg
  und datpreis.001 mit Status (vorhanden/Größe/Änderungsdatum)
  sowie Reset-Button. PHP-Limit auf 100 MB erhöht.
- Reindexer nutzt DATANORM_DIR, sucht Dateien case-insensitiv und
  liefert klare Fehlermeldungen. Tippfehler "Äus Katalog" gefixt.


## v2.0.0 – 2026-05-02
Major: Modul-System (Plattform-Release).

Ziel:
Vorbereitung der Codebasis auf weitere Module (Buchhaltung, Lager,
Aufmaß, …). Zentrale Infrastruktur statt Boilerplate je Modul.

Neue Komponenten:
- src/Core/EventBus.php – Pub/Sub-Bus für modul-übergreifende
  Ereignisse (Backend). Listener-Fehler werden geloggt, brechen
  die Schleife aber nicht ab.
- src/Core/AbstractModule.php – Basisklasse für Module.
  Pflicht: dispatch(string $action). Optional: migrate(),
  registerEvents().
- src/Core/ModuleLoader.php – Discovery von modules/<name>/
  module.json, topologische Sortierung depends_on,
  idempotente Migrationen, Auth/Feature-Flag-Gating, sicheres
  Routing (Modulnamen-Whitelist [a-z][a-z0-9_]*).
- modules/din1090/module.json – DIN-1090 als erstes Modul auf
  neuem Pattern; Manifest beschreibt Frontend-Assets,
  Berechtigungen und Feature-Flag.
- modules/din1090/backend/Module.php – Adapter, der das
  bestehende Din1090Actions/Din1090Database unverändert kapselt.

API-Erweiterungen in api.php (zusätzlich, keine bestehenden
Routen geändert):
- ?action=list_modules – liefert die für den User sichtbaren
  Module inkl. Asset-Pfade.
- ?action=module&module=<name>&sub=<aktion> – delegiert an
  ModuleLoader::dispatch(). Body wird durchgereicht.
- ModuleLoader wird beim Boot initialisiert und ruft migrateAll()
  auf (idempotente CREATE TABLE IF NOT EXISTS in Modulen).

Frontend (script.js) – additiv:
- window.AppModules: register/get/list, on/emit (Frontend-Bus),
  api(modul, sub, body), boot() ruft list_modules und cached.
- Bestehende Funktionen (showDin1090, Sidebar etc.) sind
  unangetastet – AppModules ist Infrastruktur, nicht Pflicht.

Abwärtskompatibilität:
- din1090_api.php bleibt parallel funktional. Bestehende DIN-1090-
  Frontend-Aufrufe bleiben gültig.
- Keine DB-Änderungen – DIN-1090 nutzt seine vorhandene
  Migration weiter, jetzt zusätzlich automatisch beim Boot.
- Alle bestehenden Routen, Formate und €-/Icon-Darstellungen
  bleiben unverändert.

Sicherheits-Properties des Loaders:
- Auth::requireAuth() vor jeder Modul-Aktion
- permissions[] pro Manifest erzwingt Rollenfilter
- feature_flag deaktiviert Modul global
- Modulname per Regex gefiltert – kein Path-Traversal
- allowed_actions[] (optional) als zusätzliche Aktion-Whitelist

Versionen: APP_VERSION 2.0.0, manifest 2.0.0, sw CACHE_VERSION
v42, ?v=42 in style.css/script.js.

Dateien: src/Core/{EventBus,AbstractModule,ModuleLoader}.php,
modules/din1090/module.json, modules/din1090/backend/Module.php,
api.php, script.js, manifest.json, sw.js, index.html,
Änderungshinweise.txt

## v1.9.1 – 2026-04-29
Hotfix: Rückwärtskompatibilität alter Sicherungen wiederhergestellt.

Problem:
- Seit v1.8.0 verweigerte DataActions::restore() Sicherungen, die
  vor v1.5.0 erstellt wurden, mit Fehlermeldungen wie
  „Sicherung unvollständig: 'rechnungen' fehlt“ oder „Baustelle #N
  ohne id/name“. Ursache war die in M8 (v1.8.0) eingeführte strikte
  Schema-Validierung.
- Zusätzlich brach der Restore zusätzlicher Tabellen (Zeiterfassung/
  Wochenplanung/Schnellnotizen) komplett ab, sobald eine Tabelle in
  einem alten SQLite-Backup fehlte.

Fix in src/Handlers/DataActions.php::restore():
- Pflicht ist nur noch 'baustellen' als Array. Optionale Top-Level-
  Keys ('kunden', 'rechnungen', 'pauschalen', 'stundenKatalog',
  'materialKatalog') werden bei Fehlen automatisch mit [] aufgefüllt.
- Baustellen brauchen weiterhin eine 'id'. Fehlende 'name'-Felder
  werden mit „Baustelle #<id>“ ersetzt statt zu blockieren.
- Restore der Zusatztabellen ist jetzt isoliert: fällt eine
  Tabelle aus (z. B. fehlt in altem Backup), wird sie übersprungen,
  die anderen werden trotzdem wiederhergestellt. Spalten-Zugriff
  defensiv mit ?? null.

Sicherheit bleibt erhalten:
- Strukturelle Prüfung (data ist Objekt, baustellen ist Array, jede
  Baustelle hat id) verhindert weiterhin präparierte JSON-Imports.
- DataService::saveAllData verarbeitet ohnehin nur bekannte Keys.

Dateien: src/Handlers/DataActions.php, api.php, manifest.json,
Änderungshinweise.txt

## v1.9.0 – 2026-04-29
Accessibility & Stabilitäts-Release (A + B):

A – Accessibility (WCAG 2.1 AA Richtung):
- Skip-Link "Zum Hauptinhalt springen" am Body-Anfang. Nur sichtbar
  bei Tab-Fokus, springt direkt zu <main id="mainContent">.
- Landmark-Roles: <header role="banner">, <aside role="navigation">,
  <main role="main" tabindex="-1">. Sidebar bekommt
  aria-label="Hauptnavigation".
- Sidebar-Toggle: aria-controls="appSidebar" + aria-expanded
  synchron via toggleSidebar().
- Suche-Eingabe + Baustellen-Liste mit aria-label / role="list".
- Globaler :focus-visible Outline (#0066b3 Hell / #4FC3F7 Dark) +
  4px Offset-Ring – nur bei Tastatur-Navigation, nicht bei Maus.
- ESC schließt jetzt zusätzlich Header-Dropdowns.
- Kontrast-Fix: .text-muted/.form-hint von --grey-500 (≈2.85:1)
  auf --grey-700 (≈7:1) angehoben – erfüllt WCAG AA 4.5:1.
- prefers-reduced-motion Media-Query entfernt Animationen für
  Nutzer mit entsprechender System-Präferenz.
- .sr-only Utility-Klasse für screenreader-only Texte.

B – Mobile Defensive Null-Checks (M10):
- showView()/showToast() in mobile.html crashen nicht mehr, wenn
  ein View-Container fehlt (z. B. nach Layout-Änderung).
- 9x setTimeout(... .focus(), 300) auf optional chaining (?.focus())
  umgestellt – vermeidet Konsolen-Errors wenn Sheet bereits wieder
  geschlossen wurde.

Nicht umgesetzt (begründet → v2.0.0):
- C: Drag-Listener Delegation – ohne konkretes Bug-Repro nicht geändert.
- D: PHPUnit-Scaffold – ohne lokales PHP/CI nicht produktiv lauffähig.
- E: Password-Pepper – invalidiert alle Logins, Migration nötig.
- F: IndexedDB Offline-Cache + SW-Sync – Architekturarbeit mit
  Konfliktauflösung erforderlich.
- G: script.js Modularisierung (~7300 LOC) – hohes Regressionsrisiko
  ohne Test-Suite.

Dateien: index.html, style.css, script.js, mobile.html, api.php,
manifest.json, sw.js, Änderungshinweise.txt

## v1.8.0 – 2026-04-29
Security- & Stability-Release (H1–H8, M1, M5, M7–M9):
- H1: BaustelleActions::reimport – parentId wird nicht mehr pauschal
  auf null gesetzt. Reihenfolge: 1) alte ID geprüft 2) Match per
  parentName auf Top-Level-Projekt 3) sonst Top-Level. archive()
  speichert parentName mit, damit der Lookup zuverlässig wirkt.
- H2: FileActions::extractText (OCR) DoS-Härtung. PDFs >20 MB und
  Bilder >8 MB werden übersprungen; tesseract/pdftotext laufen mit
  Linux-Timeout 30 s.
- H3: Helpers::fetchUrl – TLS-Verify (CURLOPT_SSL_VERIFYPEER/HOST,
  stream verify_peer) standardmäßig aktiv. Optionaler Parameter
  $insecure=true für Spezialfälle.
- H4: AuditService um HMAC-SHA256-Hash-Chain in SQLite-Tabelle
  audit_log erweitert. Pepper in data/audit_pepper.txt (auto-erzeugt,
  chmod 0600). Methode verifyChain() prüft die Kette. Textlog
  data/audit.log bleibt erhalten (Backwards-Compat).
- H5: FileActions::uploadFile/saveGeneratedFile/listFiles/moveFile/
  downloadFile/deleteFile/renameFile prüfen Auth::canSeeBaustelle
  zusätzlich zu canDo* – Master-User können keine fremden Baustellen-
  Dateien mehr aufrufen.
- H6: SQLite busy_timeout 5000 ms → 15000 ms. Reduziert
  „database is locked“ bei gleichzeitigem Backup/OCR/Cron.
- H7: Session-Cookie-Flags (secure, httponly, samesite=Lax) und
  Origin-Check auf POST waren bereits aktiv – verifiziert.
- H8: CSRF-Schutz über SameSite=Lax + Origin-Header-Check
  bestätigt; separates Token wäre Defense-in-Depth ohne realen
  Mehrwert im aktuellen Setup, daher bewusst nicht ergänzt.

Mittel:
- M1: renderSidebar() coalesced via requestAnimationFrame.
  Mehrfache Aufrufe innerhalb eines Frames werden zu einem Render
  zusammengefasst.
- M5: index.html-Mobile-Redirect respektiert ?desktop=1 (persistent
  via localStorage 'forceDesktop'). Tablet-User können die Desktop-
  Ansicht erzwingen.
- M7: saveKunde – Auto-Kundennummer-Vergabe + INSERT laufen jetzt
  in einer SQLite IMMEDIATE-Transaktion mit 3-Versuchen-Retry.
- M8: DataActions::restore validiert das Backup-Schema (baustellen/
  kunden/rechnungen vorhanden, jede Baustelle hat id+name) bevor
  DataService::saveAllData die DB überschreibt.
- M9: migrate.php --force sichert zusätzlich -wal/-shm-Sidecar-
  Dateien und bricht hart ab wenn copy() fehlschlägt.

Nicht-Bugs / verschoben:
- M2 (Drag-Listener Delegation), M10 (Mobile Defensive Checks):
  Ohne konkretes Repro nicht geändert – Implementation Discipline.
- M3 (IndexedDB Offline-Cache) + M4 (Service-Worker Sync-Handler):
  Architekturarbeit mit Konflikt-Auflösungs-Strategie nötig → v1.9.0.
- M6 (=== null statt == null): Bewusst null-or-undefined-Checks,
  kein Bug.

DB-Migrationen (idempotent in Database::runMigrations):
- CREATE TABLE audit_log (id, ts, username, action, details,
  prev_hash, hash) + Index ts/username.

Dateien: 9 (Database.php, Helpers.php, AuditService.php,
FileActions.php, BaustelleActions.php, CrudActions.php,
DataActions.php, migrate.php, script.js, index.html, api.php,
manifest.json, sw.js, Änderungshinweise.txt)

## v1.7.1 – 2026-04-29
Sicherheits-Hotfixes (K1–K4):
- K1: Default-Passwort 'Stadler2580!' bleibt für Systemadmin, aber
  mustChangePassword=1 ist gesetzt; Server erzwingt Passwortänderung
  vor jeder anderen Aktion (api.php-Dispatcher prüft Whitelist).
- K2: Session-Invalidierung bei sicherheitsrelevanten Änderungen.
  Neue Spalte users.sessionInvalidatedAt; Auth::requireAuth prüft
  Session-Login-Zeitstempel gegen sie. Wird gestempelt bei setRole,
  setVisibility, adminResetPassword.
- K3: listArchive/downloadArchiveExcel/loadAuswertungArchives
  filtern jetzt nach Auth::getEffectiveVisibility (Cascade).
- K4: Login-Rate-Limit von JSON-Datei auf SQLite-Tabelle
  login_attempts umgestellt (race-condition-frei).

## v1.7.0 – 2026-04-26
Sicherheit & Berechtigungen:
- Sichtbarkeits-Cascade Ober-/Unterprojekt: Berechtigungen für ein
  Oberprojekt gelten automatisch für alle Unterprojekte (parentId).
  Für die Navigation werden zusätzlich Eltern eines sichtbaren
  Unterprojekts ergänzt.
  → Auth::expandVisibleBaustellenIds(), Auth::getEffectiveVisibility(),
    Auth::canSeeBaustelle()
- Neuer Service App\Services\PriceFilter: entfernt rekursiv alle Preis-,
  Margen- und Finanzfelder aus API-Antworten, wenn der User keine
  Berechtigung 'canSeePrices' hat. Damit gelangen Preise auch nicht
  mehr über Exporte oder Sicherungen an unberechtigte Nutzer.
- DataActions::load() filtert Baustellen jetzt nach Sichtbarkeit
  (inkl. Cascade) und strippt Preisfelder.
- DataActions::listBackups()/restore() nur noch für Admin/Master
  (Sicherungen enthalten vollständige SQLite-Dumps inkl. Preise).
- ExportActions::exportInformCsv/subunternehmerReport/generateZugferd
  prüfen jetzt canSeePrices + Sichtbarkeit der Baustelle (403 sonst).
- BaustelleActions::loadAuswertungArchives strippt Preisfelder für
  Nutzer ohne canSeePrices.
- AuthActions::check liefert die Cascade-erweiterte Sichtbarkeitsliste
  ans Frontend.
- script.js: Hinweis im Sichtbarkeits-Editor, dass Unterprojekte
  automatisch eingeschlossen werden.

## davortouch
- Modulare Docker-Builds mit Feature-Flags (ENABLE_OCR, ENABLE_WHATSAPP)
- Install-Skripte (install.sh / install.ps1)
- HiCAD-Bereinigung, Materialbibliothek_Uebersicht.csv als Ersatz

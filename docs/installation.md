# Planning Dashboard – Installationsanleitung

## Voraussetzungen

- **Docker** + **Docker Compose** (v3.8+)
- Mindestens **512 MB RAM** und **1 GB Speicherplatz**
- Freigegebene Ports (Standard: 8081 für App, 3002 für WhatsApp-Bridge)

---

## Modul-Matrix (Build-Auswahl für kleine NAS)

Über Build- und Laufzeit-Flags kann die Installation auf kleinen NAS-Systemen verschlankt werden. Die `.env.example` als Vorlage für eigene `.env` nutzen.| Feature | Standard | Build-Flag | Laufzeit-Flag | Image-Kosten | RAM-Kosten | Empfehlung |
|---|---|---|---|---|---|---|
| **Kern** (PHP, SQLite, Apache, ZUGFeRD, dompdf) | immer aktiv | — | — | ~150 MB | ~80 MB idle | — |
| **OCR** (Tesseract + poppler, Dokumente-Volltext) | aktiv | `ENABLE_OCR=true/false` | folgt Build | +200 MB | +200 MB Peak | auf NAS < 4 GB RAM: `false` |
| **WhatsApp-Bridge** (Benachrichtigungen) | inaktiv | — (eigener Container) | `docker compose --profile whatsapp up` | +500 MB (eigener Container) | +400–500 MB dauerhaft | nur auf Geräten ≥ 4 GB RAM |
| **HiCAD-Materialbibliothek** (Roh-IPT-Dateien) | nicht im Image | `.dockerignore` | — | ersetzt durch CSV | 0 | CSV-Übersicht reicht für Katalog |
| **DIN EN 1090** (Schweißnachweis) | aktiv, runtime-toggle | — | Einstellungen → `modul_din1090` | minimal | minimal | per App-Einstellung steuern |

### Module nachträglich aktivieren/deaktivieren

1. `.env` anpassen (z. B. `ENABLE_OCR=true`).
2. `docker compose up -d --build` neu starten.
3. Daten (SQLite, Uploads) bleiben in Volumes erhalten.

**Backups sind modulunabhängig.** Eine DB-Sicherung lässt sich in jeden Build wieder einspielen. Daten aus einem nicht installierten Modul bleiben in der DB, sind aber erst nach dem Aktivieren des Moduls sichtbar.

### Minimal-Konfiguration starten

```bash
docker compose -f docker-compose.minimal.yml up -d --build
```

### Interaktive Installation (empfohlen)

Wer nicht selbst `.env` bearbeiten möchte, kann das geführte Skript nutzen:

```bash
# Linux / NAS
chmod +x install.sh
./install.sh
```

```powershell
# Windows
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

Das Skript fragt Ports, OCR und WhatsApp ab, schreibt `.env` und startet anschließend `docker compose up -d --build`. Bei einem erneuten Aufruf werden die bestehenden `.env`-Werte als Vorgaben übernommen — so eignet es sich auch zum Ändern einzelner Flags.

---

## 1. Dateien auf den Server kopieren

Das komplette Repository (ohne `tests/`, `docs/`, `.github/`) in ein Verzeichnis auf dem Server kopieren (z.B. `/opt/baukalkulation/`).
Fertig zusammengestellt liegt es nach `npm run deploy:sync` im Ordner `deploy/` – diesen Ordner hochladen. Aufbau:

```
├── public/                      ← einziger Webroot (DocumentRoot)
│   ├── api.php, din1090_api.php ← API-Einstiegspunkte
│   ├── index.html, login.html, mobile.html, mobile_light.html, wochenplan_display.html
│   ├── script.min.js, style.css, sw.js, manifest.json
│   ├── logo_es.png              ← Firmenlogo für PDFs/Rechnungen (kundenspezifisch!)
│   ├── icons/, lib/
│   └── modules/<modul>/         ← Browser-Dateien der Module (JS/CSS)
├── src/                         ← PHP-Code (nicht per HTTP erreichbar)
├── modules/<modul>/             ← Modul-Backend (PHP, module.json)
├── data/                        ← Datenbank, Uploads, Backups (Volume)
├── Datanorm/                    ← optional: Großhändler-Kataloge
├── cron_*.php, migrate.php      ← CLI-Skripte (Cron / Container-Start)
├── VERSION                      ← App-Version (einzige Quelle)
├── composer.json, composer.lock
├── docker-compose.yml, Dockerfile.app, docker-entrypoint.sh
└── .env                         ← kundenspezifisch (siehe 2.1)
```

> **Ohne Docker** (z.B. Synology Web Station): Der Webserver muss auf `public/` zeigen,
> **nicht** auf die Projektwurzel. Zeigt er versehentlich auf die Wurzel, leitet die
> dortige `.htaccess` intern nach `public/` um; `src/` und `data/` bleiben gesperrt.

**Nicht benötigt** für die Installation:
- `tests/`, `docs/`, `.github/` (Entwicklung)
- `vendor/` Ordner (wird beim Docker-Build automatisch erstellt)

---

## 2. Kundenspezifische Anpassungen

### 2.1 `.env` Datei anlegen

Eine `.env`-Datei im Installationsverzeichnis anlegen (Vorlage: `.env.example`). Die `docker-compose.yml` liest alle Werte aus dieser Datei — sie selbst wird **nicht** pro Kunde geändert.

Folgende drei Werte **müssen** für jede Installation auf demselben Host eindeutig sein:

```env
# Eindeutiger Projektname – verhindert Network/Volume-Kollisionen zwischen Instanzen
COMPOSE_PROJECT_NAME=baukalkulation-es

# Eindeutiger Container-Name
APP_NAME=baukalkulation-es-app

# Eindeutiger Host-Port
APP_PORT=8082
```

> ⚠️ **Wenn alle drei Werte nicht eindeutig sind**, kommt es beim Start einer zweiten Instanz zu Port-Konflikten, Container-Namenskollisionen oder – schlimmer – zu gemeinsam genutzten Docker-Networks zwischen unabhängigen Instanzen.

> ⚠️ **Autoheal bei mehreren Instanzen auf einem Host:** Zusätzlich müssen
> `AUTOHEAL_NAME` **und** `AUTOHEAL_LABEL` je Instanz eindeutig sein. Ohne
> eindeutiges `AUTOHEAL_LABEL` würde der Autoheal der einen Instanz auch die App
> der anderen Instanz neu starten, da Autoheal alle Container mit demselben Label
> hostweit überwacht.
>
> ```env
> # Instanz 1
> AUTOHEAL_NAME=erp1-autoheal
> AUTOHEAL_LABEL=autoheal_erp1
>
> # Instanz 2
> AUTOHEAL_NAME=erp2-autoheal
> AUTOHEAL_LABEL=autoheal_erp2
> ```
>
> Alternativ: nur **einen** gemeinsamen Autoheal pro Host betreiben und ihn in
> der zweiten Instanz weglassen (z. B. nur den App-Service starten:
> `docker compose up -d app`).


> **Hinweis `install.sh`:** Das Installationsskript erstellt `.env` als `root`. Danach unbedingt:
> ```bash
> sudo chown $(whoami):$(whoami) .env
> ```

### 2.2 Firmenlogo ersetzen

Die Datei `public/logo_es.png` mit dem Firmenlogo des Kunden überschreiben. Dieses Logo wird verwendet für:
- PDF-Rechnungen und Angebote
- Berichte / Reports
- Mobile Exports

### 2.3 Datanorm-Kataloge (optional)

Eigene Datanorm-Dateien des Großhändlers in den `Datanorm/` Ordner legen:
- `datanorm.001` – Artikeldaten
- `datanorm.wrg` – Warengruppen
- `datpreis.001` – Preisdaten

---

## 3. Installation starten

```bash
cd /opt/baukalkulation/
docker-compose up -d --build
```

Beim ersten Start passiert automatisch:
1. PHP 8.2-Apache Image wird gebaut
2. Composer-Dependencies werden installiert (dompdf, zugferd)
3. SQLite-Datenbank wird erstellt
4. Standard-Admin-Benutzer wird angelegt

### 3.1 Verzeichnis-Berechtigungen setzen

Nach dem ersten Start müssen die Berechtigungen für `data/` explizit gesetzt werden — Docker erstellt neue Verzeichnisse sonst als `root`:

```bash
# Eigentümer auf www-data setzen
sudo chown -R www-data:www-data data

# Verzeichnisse mit SGID-Bit (neue Unterordner erben Gruppe automatisch)
sudo find data -type d -exec chmod 2775 {} \;

# Dateien lesbar/schreibbar für www-data und Gruppe
sudo find data -type f -exec chmod 0664 {} \;

# ACL: www-data und aktueller User bekommen dauerhaft rwX (gilt auch für neue Dateien)
sudo setfacl -R  -m u:$(whoami):rwX -m u:www-data:rwX data
sudo setfacl -dR -m u:$(whoami):rwX -m u:www-data:rwX data
```

**`audit_pepper.txt` besonders schützen** (wird beim ersten Start automatisch erstellt):

```bash
sudo chmod 0600 data/audit_pepper.txt
sudo chown www-data:www-data data/audit_pepper.txt
```

> Falls `audit_pepper.txt` fehlt (z.B. weil `data/` beim ersten Start nicht beschreibbar war), manuell erzeugen:
> ```bash
> docker compose exec app php -r "
>   \$p='/var/www/html/data/audit_pepper.txt';
>   if(!file_exists(\$p)){file_put_contents(\$p,bin2hex(random_bytes(32)));chmod(\$p,0600);echo 'erstellt';}
>   else echo 'vorhanden';
> "
> ```

### Erster Login

- **URL:** `http://SERVER-IP:8081`
- Beim ersten Aufruf wird der erste Administrator angelegt (Benutzername + Passwort frei wählbar).
- Zusätzlich entsteht das Support-Konto **`Systemadmin`**. Sein Startpasswort ist je Installation
  individuell: aus `BK_SYSTEMADMIN_PASSWORD` in der `.env` (min. 12 Zeichen) oder – wenn leer –
  zufällig erzeugt:

  ```bash
  docker exec baukalkulation-KÜRZEL-app cat /var/www/html/data/systemadmin-passwort.txt
  ```

  Bei der ersten Anmeldung muss es geändert werden. Vergessen / gesperrt:

  ```bash
  docker exec -it baukalkulation-KÜRZEL-app php bin/console user:reset-password Systemadmin
  ```

---

## 4. Überprüfung

```bash
# Container-Status prüfen
docker-compose ps

# Logs anzeigen
docker-compose logs -f app

# Health-Check
curl http://localhost:8081/api.php?action=check
```

Erwartete Antwort: `{"loggedIn":false}` (bei nicht eingeloggtem Zustand)

---

## 5. Cronjobs einrichten (optional)

Für automatische Erinnerungen per WhatsApp:

```bash
# Material-Erinnerungen (täglich 7:00)
0 7 * * * docker exec baukalkulation-KÜRZEL-app php /var/www/html/cron_material_erinnerung.php

# Stunden-Erinnerungen (Mo-Fr 17:00)
0 17 * * 1-5 docker exec baukalkulation-KÜRZEL-app php /var/www/html/cron_stunden_erinnerung.php
```

---

## 6. WhatsApp-Bridge einrichten (optional)

Siehe `WHATSAPP_SETUP.md` (liegt dem WhatsApp-Bridge-Paket bei) für die vollständige Anleitung.

Kurzfassung:
1. `whatsapp_config.php` mit API-Token und Bridge-URL konfigurieren
2. Container starten: `docker-compose up -d whatsapp-bridge`
3. QR-Code scannen: `http://SERVER-IP:3002/api/qr`
4. Mit WhatsApp auf dem Firmenhandy scannen

---

## 7. Backup & Wartung

### Automatische Backups

Die App erstellt täglich automatische Backups im `data/backups/` Verzeichnis (max. 7 Stück).
Jede Sicherung (Format 2) enthält `database.sqlite` (konsistenter Snapshot), `baukalkulation.json`
und `manifest.json` mit App-Version, Schema-Version und SHA-256-Prüfsummen.

### Backup per E-Mail

Einstellungen → Erinnerungen → „Backup per E-Mail". Mit einem **ZIP-Passwort** (min. 10 Zeichen)
wird das Archiv AES-256-verschlüsselt (öffnen mit 7-Zip/WinZip). Das Passwort wird verschlüsselt
gespeichert (Schlüssel `data/secret.key` bzw. Env `BK_SECRET_KEY`) und nie an den Browser
ausgeliefert. Übersteigt das ZIP das eingestellte Limit (Standard 20 MB), kommt nur eine Hinweis-Mail.

> `data/secret.key` gehört zur Installation und ist **nicht** Teil der Sicherungen. Geht er verloren,
> muss das Backup-Passwort neu gesetzt werden.

### Manuelle Sicherung / Wiederherstellung per Kommandozeile

```bash
# Sicherung anlegen (optional zusätzlich als verschlüsseltes ZIP)
docker exec baukalkulation-KÜRZEL-app php bin/console backup:create --name="vor-update" \
    --zip=/var/www/html/data/exports/backup.zip --password='…'

# Sicherung einspielen – ZIP (alle Formate), Sicherungsordner oder alte JSON-Tagessicherung.
# Vorher wird automatisch eine Sicherheitskopie des aktuellen Standes angelegt.
docker exec -it baukalkulation-KÜRZEL-app php bin/console backup:import /pfad/zur/sicherung.zip [--mode=replace]

# Schema-Stand prüfen
docker exec baukalkulation-KÜRZEL-app php bin/console db:status
```

Sicherungen aus **älteren** App-Versionen werden beim Einspielen automatisch auf den aktuellen
Schema-Stand gehoben. Sicherungen aus **neueren** Versionen werden abgelehnt.

### Daten-Volume sichern

```bash
# Daten-Volume sichern
docker cp baukalkulation-KÜRZEL-app:/var/www/html/data ./backup_data_$(date +%Y%m%d)

# Uploads sichern
docker cp baukalkulation-KÜRZEL-app:/var/www/html/data/uploads ./backup_uploads_$(date +%Y%m%d)
```

### Update einspielen

```bash
cd /opt/baukalkulation/

# Neue Dateien kopieren (NICHT den data/ Ordner überschreiben!)
# Dann:
docker-compose up -d --build
```

Beim Start migriert der Container das Schema automatisch (`bin/console db:migrate`). Schlägt die
Migration fehl oder ist die Datenbank neuer als die App (Downgrade), startet der Container nicht –
die Meldung steht in `docker logs`.

### Datenbank: SQLite oder PostgreSQL

Standard ist **SQLite** (`data/database.sqlite`) – ideal für eine Instanz auf einem NAS.
**PostgreSQL** empfiehlt sich bei vielen gleichzeitigen Benutzern oder wenn die Datenbank
getrennt vom App-Container betrieben und gesichert werden soll. Beide Varianten werden dauerhaft
unterstützt; Sicherungen (ZIP) lassen sich in beiden Betriebsarten einspielen.

```bash
# Einmalig: Datenbank-Passwort erzeugen (Datei nie ins Repository!)
mkdir -p secrets && openssl rand -base64 32 > secrets/db_password.txt && chmod 644 secrets/db_password.txt

# Neue Installation mit PostgreSQL
docker compose -f docker-compose.yml -f docker-compose.postgres.yml up -d

# Bestehende SQLite-Installation umstellen (data/database.sqlite bleibt als Rückfallebene erhalten)
docker compose -f docker-compose.yml -f docker-compose.postgres.yml up -d db
docker compose -f docker-compose.yml -f docker-compose.postgres.yml run --rm app \
    php bin/console db:transfer to-pgsql
docker compose -f docker-compose.yml -f docker-compose.postgres.yml up -d

# Zurück zu SQLite: Daten in eine SQLite-Datei übertragen, dann ohne Overlay starten
docker compose -f docker-compose.yml -f docker-compose.postgres.yml run --rm app \
    php bin/console db:transfer to-sqlite --force
```

| Variable | Bedeutung |
|----------|-----------|
| `BK_DB_DRIVER` | `sqlite` (Standard) oder `pgsql` |
| `BK_DB_HOST`, `BK_DB_PORT` | PostgreSQL-Server (Standard-Port 5432) |
| `BK_DB_NAME`, `BK_DB_USER` | Datenbank und Benutzer |
| `BK_DB_PASSWORD_FILE` | Datei mit dem Passwort (Docker-Secret); alternativ `BK_DB_PASSWORD` |

Sicherheit und Sicherung bei PostgreSQL:

- Die Datenbank hat **keinen** nach außen veröffentlichten Port; nur der App-Container erreicht sie.
- Das Passwort kommt als Docker-Secret, nicht als Umgebungsvariable im Klartext.
- Der Dienst `db-backup` legt täglich ein `pg_dump` in `data/pg-backups/` ab (14 Tage).
  Zusätzlich erstellt die App ihre gewohnten Sicherungen (inkl. portabler `database.sqlite`).
- Datenprüfsummen (`--data-checksums`) erkennen Schäden auf dem Speichermedium.
- Beim Wechsel der PostgreSQL-Hauptversion (z. B. 17 → 18): vorher `pg_dump`, neues Volume,
  dann einspielen – das Datenverzeichnis ist nicht zwischen Hauptversionen kompatibel.

---

## 8. Ports-Übersicht (Mehrere Installationen auf einem Host)

Jede Instanz braucht **drei eindeutige Werte** in ihrer `.env`:

| Kunde | `COMPOSE_PROJECT_NAME` | `APP_NAME` | `APP_PORT` | WA-Port |
|-------|------------------------|------------|------------|---------|
| ES    | `baukalkulation-es`    | `baukalkulation-es-app` | `8082` | 3002 |
| TW    | `baukalkulation-tw`    | `baukalkulation-tw-app` | `8083` | 3003 |
| MN    | `baukalkulation-mn`    | `baukalkulation-mn-app` | `8084` | 3004 |
| NEU   | `baukalkulation-neu`   | `baukalkulation-neu-app` | `8085` | 3005 |

> **Folgen bei doppelten Werten:**
> - Gleicher `APP_PORT` → zweiter Container startet nicht (Port bereits belegt)
> - Gleicher `APP_NAME` → Docker stoppt den bestehenden Container und ersetzt ihn
> - Gleicher `COMPOSE_PROJECT_NAME` → Docker Networks und Volumes werden zwischen den Instanzen geteilt — Datenvermischung möglich!

> **`docker compose` immer aus dem richtigen Verzeichnis aufrufen.** Die `.env` wird aus dem aktuellen Arbeitsverzeichnis geladen — im falschen Ordner werden die Werte der anderen Instanz verwendet.

> **Typische Falle: Ordner heißen überall `Baukalkulation`.** Fehlt `COMPOSE_PROJECT_NAME`, nimmt
> Docker den Ordnernamen als Projektnamen – alle Mandanten landen im selben Projekt `baukalkulation`.
> Folge: Jeder Build in einem Mandantenordner erzeugt die Container der anderen Mandanten neu
> („Recreated“), und alle bauen dasselbe Image `baukalkulation-app`. Namen und Port nur in der `.env`
> setzen, **nicht** in der `docker-compose.yml` (die wird beim nächsten Update überschrieben).
> `install.sh` schlägt eindeutige Namen aus dem Mandantenordner vor und bricht bei Kollisionen ab.
>
> Bestehende, vermischte Installation trennen (Daten in `data/` bleiben erhalten):
>
> ```bash
> docker compose ls                                   # zeigt Projekte und ihre compose-Dateien
> docker compose -p baukalkulation down               # Container des gemeinsamen Projekts entfernen
> # je Mandantenordner: eindeutige Werte in .env eintragen (oder ./install.sh), dann
> docker compose up -d --build
> ```

---

## Technische Details

| Komponente | Version / Technologie |
|------------|----------------------|
| Backend    | PHP 8.2 (Apache) |
| Datenbank  | SQLite 3 |
| Frontend   | Vanilla JS SPA |
| PDF        | dompdf 2.x |
| E-Rechnung | ZUGFeRD (horstoeko/zugferd) |
| PWA        | Service Worker + Manifest |
| WhatsApp   | Node.js Bridge (Baileys) |
| Container  | Docker + Docker Compose |

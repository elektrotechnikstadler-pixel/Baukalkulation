#!/bin/sh
# docker-entrypoint.sh
# Wird bei jedem Container-Start ausgeführt (nach Volume-Mount).
# Stellt sicher, dass die SQLite-DB existiert und alle Schema-Migrationen
# (runMigrations) auf der gemounteten Datenbank durchgeführt wurden.

set -e

echo "[entrypoint] Starte DB-Migration..."
# migrate.php zuerst: importiert bei fehlender DB einen evtl. vorhandenen JSON-Altbestand.
php /var/www/html/migrate.php || true
# Schema-Migrationen; bei Fehler oder zu neuem Schema startet die App nicht.
php /var/www/html/bin/console db:migrate
echo "[entrypoint] Migration abgeschlossen."

# Eigentümer aller Dateien im data-Verzeichnis auf www-data setzen,
# damit Apache (www-data) schreiben kann. Nötig weil migrate.php als
# root läuft und neu erstellte Dateien sonst root:root gehören.
# Verzeichnisschutz: data/index.php verhindert Direktzugriff via Browser
if [ ! -f /var/www/html/data/index.php ]; then
    echo '[entrypoint] data/index.php (Verzeichnisschutz) wird angelegt...'
    echo '<?php http_response_code(403); exit("Forbidden");' > /var/www/html/data/index.php
fi

echo "[entrypoint] Berechtigungen auf data/ korrigieren..."
chown -R www-data:www-data /var/www/html/data/ || true
find /var/www/html/data -type f -exec chmod 660 {} \; || true
find /var/www/html/data -type d -exec chmod 750 {} \; || true
echo "[entrypoint] Berechtigungen gesetzt."

# Cron-Daemon starten (für automatisches Backup-per-Mail + Erinnerungen).
# Muss NACH dem chown laufen, damit die Skripte als www-data in data/ schreiben
# können. Ohne diesen Daemon werden die Cron-Skripte nie automatisch ausgeführt.
if command -v cron >/dev/null 2>&1; then
    echo "[entrypoint] Starte Cron-Daemon..."
    cron
else
    echo "[entrypoint] WARNUNG: cron nicht installiert – automatische Backups/Erinnerungen inaktiv."
fi

# ── Datensicherheit: WAL-Checkpoint bei Start & Stop ─────────────
# Verhindert Datenverlust (neue Kunden/Projekte) bei Container-Neustart:
# Beim Stop wird das WAL in die Haupt-DB gefaltet (TRUNCATE) und eine
# Sicherheitskopie abgelegt; beim Start wird ein evtl. offenes WAL aus einem
# vorherigen (unsauberen) Lauf eingefaltet.
DB_PATH=/var/www/html/data/database.sqlite

checkpoint_db() {  # $1 = "backup" → zusätzlich Sicherheitskopie ablegen
    [ -f "$DB_PATH" ] || return 0
    php -r '
        $p = "/var/www/html/data/database.sqlite";
        if (!file_exists($p)) exit;
        try {
            $db = new PDO("sqlite:" . $p);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
            if (($argv[1] ?? "") === "backup") {
                $b = dirname($p) . "/backups";
                if (!is_dir($b)) @mkdir($b, 0750, true);
                @copy($p, $b . "/pre_stop.sqlite");
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "[entrypoint] Checkpoint-Fehler: " . $e->getMessage() . "\n");
        }
    ' "${1:-}" || true
}

term_handler() {
    echo "[entrypoint] Stop-Signal empfangen – sichere Datenbank (WAL-Checkpoint)..."
    checkpoint_db backup
    if [ -n "${APACHE_PID:-}" ]; then
        kill -TERM "$APACHE_PID" 2>/dev/null || true
        wait "$APACHE_PID" 2>/dev/null || true
    fi
    echo "[entrypoint] Datenbank gesichert – Container wird beendet."
    exit 0
}
trap term_handler TERM INT

# Beim Start: offenes WAL eines vorherigen Laufs einfalten
checkpoint_db

# Apache im Hintergrund starten, damit der Stop-Handler (Checkpoint) zuverlässig
# greift. `wait` blockiert bis Apache endet oder ein Stop-Signal eintrifft.
echo "[entrypoint] Starte Apache..."
apache2-foreground &
APACHE_PID=$!
wait "$APACHE_PID"

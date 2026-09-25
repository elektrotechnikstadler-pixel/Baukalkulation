<?php
/**
 * migrate.php – JSON → SQLite Migration
 *
 * Liest alle bestehenden JSON-Dateien und importiert sie in die
 * neue SQLite-Datenbank. Kann mehrfach gefahrlos ausgeführt werden.
 *
 * Verwendung:
 *   php migrate.php
 *   php migrate.php --force   (löscht bestehende DB und importiert neu)
 */

// Nur via CLI aufrufbar – verhindert Info-Leak und unbeabsichtigte Ausführung via Web.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;

// ── Konstanten ───────────────────────────────────────────────
define('DATA_DIR', __DIR__ . '/data/');
$_pathsCfg = file_exists(DATA_DIR . 'paths_config.json')
    ? (json_decode(file_get_contents(DATA_DIR . 'paths_config.json'), true) ?? [])
    : [];
define('BACKUP_DIR',   $_pathsCfg['backups']     ?? DATA_DIR . 'backups/');
define('ARCHIVE_DIR',  $_pathsCfg['archiv']      ?? DATA_DIR . 'archiv/');
define('EXPORT_DIR',   $_pathsCfg['exports']     ?? DATA_DIR . 'exports/');
define('TAGEBUCH_DIR', $_pathsCfg['bautagebuch'] ?? DATA_DIR . 'bautagebuch/');
define('UPLOADS_DIR',  $_pathsCfg['uploads']     ?? DATA_DIR . 'uploads/');
define('BACKUP_MAX', 7);

foreach ([DATA_DIR, BACKUP_DIR, ARCHIVE_DIR, EXPORT_DIR, TAGEBUCH_DIR, UPLOADS_DIR] as $_d) {
    if (!is_dir($_d) && !@mkdir($_d, 0750, true) && !is_dir($_d)) {
        error_log('[Baukalkulation] Verzeichnis nicht anlegbar: ' . $_d);
    }
}

// ── JSON-Dateipfade (Legacy) ─────────────────────────────────
$DATA_FILE         = DATA_DIR . 'baukalkulation.json';
$USERS_FILE        = DATA_DIR . 'users.json';
$ZEITERFASSUNG_FILE= DATA_DIR . 'zeiterfassung.json';
$KUNDEN_FILE       = DATA_DIR . 'kunden.json';
$DIENSTLEISTER_FILE= DATA_DIR . 'dienstleister.json';
$WOCHENPLANUNG_FILE= DATA_DIR . 'wochenplanung.json';
$SCHNELLNOTIZEN_FILE=DATA_DIR . 'schnellnotizen.json';
$RECHNUNGEN_FILE   = DATA_DIR . 'rechnungen.json';
$SETTINGS_FILE     = DATA_DIR . 'settings.json';
$PERMISSIONS_FILE  = DATA_DIR . 'permissions.json';
$METALLZUSCHLAG_FILE=DATA_DIR . 'metallzuschlag.json';

function readJson(string $path): mixed
{
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    return json_decode($raw, true);
}

function info(string $msg): void { echo "[INFO]  $msg\n"; }
function warn(string $msg): void { echo "[WARN]  $msg\n"; }

// ══════════════════════════════════════════════════════════════
echo "=== Baukalkulation: JSON → SQLite Migration ===\n\n";

$dbPath = DATA_DIR . 'database.sqlite';
$force  = in_array('--force', $argv ?? [], true);

if (file_exists($dbPath) && !$force) {
    echo "Datenbank existiert bereits: $dbPath\n";
    echo "Verwende --force um die Datenbank neu zu erstellen.\n";
    echo "ACHTUNG: --force löscht alle bestehenden SQLite-Daten!\n";
    exit(0);
}

if ($force && file_exists($dbPath)) {
    info("Lösche bestehende Datenbank (--force)...");
    // M9 (v1.8.0): Robusteres Backup vor --force
    //  - WAL/SHM-Dateien mitsichern (sonst Datenverlust bei aktivem WAL)
    //  - copy()-Fehler wird hart abgebrochen
    $stamp  = date('Ymd_His');
    $backup = $dbPath . '.bak_' . $stamp;
    if (!copy($dbPath, $backup)) {
        echo "[ERROR] Backup fehlgeschlagen: $backup nicht schreibbar.\n";
        exit(1);
    }
    foreach (['-wal', '-shm'] as $suffix) {
        $src = $dbPath . $suffix;
        if (file_exists($src)) {
            if (!copy($src, $backup . $suffix)) {
                echo "[ERROR] Backup-Sidecar $suffix fehlgeschlagen.\n";
                exit(1);
            }
        }
    }
    info("Backup erstellt: $backup (+ WAL/SHM falls vorhanden)");
    unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
}

$db = Database::connect();
info("Datenbank erstellt / Schema initialisiert.");

$db->beginTransaction();
try {
    // ── 1) Benutzer ──────────────────────────────────────────
    $usersData = readJson($USERS_FILE);
    if (is_array($usersData)) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO users (username, password, role, kuerzel, personalnummer, visibleBaustellen, mustChangePassword, isSubunternehmer, dienstleisterId, stundenKategorie, showInZeitverwaltung, showInWochenplanung, sollstunden, sollstundenTag, sollTageWoche, urlaubstageProJahr) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($usersData as $u) {
            $vb = $u['visibleBaustellen'] ?? 'all';
            $vbStr = is_array($vb) ? json_encode($vb) : (string)$vb;
            $upj = $u['urlaubstageProJahr'] ?? [];
            $upjStr = is_array($upj) ? json_encode($upj) : (string)$upj;
            $stmt->execute([
                $u['username'] ?? '',
                $u['password'] ?? '',
                $u['role'] ?? 'normal',
                $u['kuerzel'] ?? '',
                $u['personalnummer'] ?? '',
                $vbStr,
                (int)($u['mustChangePassword'] ?? 0),
                (int)($u['isSubunternehmer'] ?? 0),
                $u['dienstleisterId'] ?? null,
                $u['stundenKategorie'] ?? '',
                (int)($u['showInZeitverwaltung'] ?? 1),
                (int)($u['showInWochenplanung'] ?? 1),
                (float)($u['sollstunden'] ?? 0),
                (float)($u['sollstundenTag'] ?? 8),
                (float)($u['sollTageWoche'] ?? 5),
                $upjStr,
            ]);
        }
        info("Benutzer importiert: " . count($usersData));
    } else {
        warn("Keine Benutzerdaten gefunden ($USERS_FILE).");
    }

    // ── 2) Hauptdaten (Baustellen + Kataloge) ────────────────
    $appData = readJson($DATA_FILE);
    if (is_array($appData)) {
        // Baustellen
        $bStmt = $db->prepare("INSERT OR REPLACE INTO baustellen (id, name, kundeId, data) VALUES (?,?,?,?)");
        foreach ($appData['baustellen'] ?? [] as $b) {
            $id      = (int)($b['id'] ?? 0);
            $name    = $b['name'] ?? '';
            $kundeId = $b['kundeId'] ?? null;
            $nested  = $b;
            unset($nested['id'], $nested['name'], $nested['kundeId']);
            $bStmt->execute([$id, $name, $kundeId, json_encode($nested, JSON_UNESCAPED_UNICODE)]);
        }
        info("Baustellen importiert: " . count($appData['baustellen'] ?? []));

        // Pauschalen (v2.9.20: Euro -> Cents)
        $db->exec("DELETE FROM pauschalen");
        $pStmt = $db->prepare("INSERT INTO pauschalen (id, name, preis) VALUES (?,?,?)");
        foreach ($appData['pauschalen'] ?? [] as $p) {
            $pStmt->execute([(int)($p['id'] ?? 0), $p['name'] ?? '', money_to_cents($p['preis'] ?? 0)]);
        }
        info("Pauschalen importiert: " . count($appData['pauschalen'] ?? []));

        // Stundenkatalog (v2.9.20: Euro -> Cents)
        $db->exec("DELETE FROM stunden_katalog");
        $sStmt = $db->prepare("INSERT INTO stunden_katalog (id, kategorie, preis, fixkosten) VALUES (?,?,?,?)");
        foreach ($appData['stundenKatalog'] ?? [] as $s) {
            $sStmt->execute([(int)($s['id'] ?? 0), $s['kategorie'] ?? '', money_to_cents($s['preis'] ?? 0), money_to_cents($s['fixkosten'] ?? 0)]);
        }
        info("Stundenkatalog importiert: " . count($appData['stundenKatalog'] ?? []));

        // Materialkatalog (v2.9.20: ek -> Cents, aufschlag -> Basispunkte)
        $db->exec("DELETE FROM material_katalog");
        $mStmt = $db->prepare("INSERT INTO material_katalog (id, bezeichnung, einheit, ek, aufschlag, artikelNr) VALUES (?,?,?,?,?,?)");
        foreach ($appData['materialKatalog'] ?? [] as $m) {
            $mStmt->execute([(int)($m['id'] ?? 0), $m['bezeichnung'] ?? '', $m['einheit'] ?? 'Stk', money_to_cents($m['ek'] ?? 0), money_to_cents($m['aufschlag'] ?? 0), $m['artikelNr'] ?? '']);
        }
        info("Materialkatalog importiert: " . count($appData['materialKatalog'] ?? []));
    } else {
        warn("Keine Projektdaten gefunden ($DATA_FILE).");
    }

    // ── 3) Zeiterfassung ─────────────────────────────────────
    $zeitData = readJson($ZEITERFASSUNG_FILE);
    if (is_array($zeitData)) {
        $zStmt = $db->prepare("INSERT INTO zeiterfassung (username, entryId, datum, typ, baustelleId, stunden, bemerkung, clientUuid, status, createdAt, updatedAt) VALUES (?,?,?,?,?,?,?,?,'booked_valid',?,?)");
        $count = 0;
        $nowTs = date('Y-m-d H:i:s');
        foreach ($zeitData as $username => $userData) {
            $entries = $userData['entries'] ?? [];
            foreach ($entries as $e) {
                $zStmt->execute([
                    $username,
                    $e['id'] ?? null,
                    $e['datum'] ?? '',
                    $e['typ'] ?? '',
                    $e['baustelleId'] ?? null,
                    (float)($e['stunden'] ?? 0),
                    $e['bemerkung'] ?? '',
                    // Eindeutiger Idempotenz-Schlüssel je importiertem Eintrag.
                    'import:' . $username . ':' . $count,
                    $nowTs,
                    $nowTs,
                ]);
                $count++;
            }
        }
        info("Zeiterfassungs-Einträge importiert: $count");
    } else {
        warn("Keine Zeiterfassungsdaten gefunden ($ZEITERFASSUNG_FILE).");
    }

    // ── 4) Kunden ────────────────────────────────────────────
    $kundenData = readJson($KUNDEN_FILE);
    if (is_array($kundenData)) {
        $kStmt = $db->prepare("INSERT OR REPLACE INTO kunden (id, firma, anrede, vorname, nachname, strasse, plz, ort, telefon, mobil, email, notizen, erstellt, geaendert, kundennummer) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($kundenData as $k) {
            $kStmt->execute([
                $k['id'] ?? null, $k['firma'] ?? '', $k['anrede'] ?? '',
                $k['vorname'] ?? '', $k['nachname'] ?? '', $k['strasse'] ?? '',
                $k['plz'] ?? '', $k['ort'] ?? '', $k['telefon'] ?? '',
                $k['mobil'] ?? '', $k['email'] ?? '', $k['notizen'] ?? '',
                $k['erstellt'] ?? '', $k['geaendert'] ?? '',
                $k['kundennummer'] ?? '',
            ]);
        }
        info("Kunden importiert: " . count($kundenData));
    } else {
        warn("Keine Kundendaten gefunden ($KUNDEN_FILE).");
    }

    // ── 5) Dienstleister ─────────────────────────────────────
    $dlData = readJson($DIENSTLEISTER_FILE);
    if (is_array($dlData)) {
        $dStmt = $db->prepare("INSERT OR REPLACE INTO dienstleister (id, firma, kontakt, telefon, email, notizen) VALUES (?,?,?,?,?,?)");
        foreach ($dlData as $d) {
            $dStmt->execute([$d['id'] ?? null, $d['firma'] ?? '', $d['kontakt'] ?? '', $d['telefon'] ?? '', $d['email'] ?? '', $d['notizen'] ?? '']);
        }
        info("Dienstleister importiert: " . count($dlData));
    } else {
        warn("Keine Dienstleister-Daten gefunden ($DIENSTLEISTER_FILE).");
    }

    // ── 6) Wochenplanung ─────────────────────────────────────
    $planData = readJson($WOCHENPLANUNG_FILE);
    if (is_array($planData)) {
        $wpStmt = $db->prepare("INSERT INTO wochenplanung (username, datum, typ, baustelleId, bemerkung, position) VALUES (?,?,?,?,?,?)");
        $count = 0;
        foreach ($planData as $username => $dates) {
            if (!is_array($dates)) continue;
            foreach ($dates as $datum => $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $pos => $entry) {
                    if (!is_array($entry)) continue;
                    $wpStmt->execute([
                        $username,
                        $datum,
                        $entry['typ'] ?? '',
                        $entry['baustelleId'] ?? null,
                        $entry['bemerkung'] ?? '',
                        (int)$pos,
                    ]);
                    $count++;
                }
            }
        }
        info("Wochenplan-Einträge importiert: $count");
    } else {
        warn("Keine Wochenplanungsdaten gefunden ($WOCHENPLANUNG_FILE).");
    }

    // ── 7) Schnellnotizen ────────────────────────────────────
    $notizen = readJson($SCHNELLNOTIZEN_FILE);
    if (is_array($notizen)) {
        $nStmt = $db->prepare("INSERT INTO schnellnotizen (id, text, baustelleId, baustelleName, ersteller, kuerzel, datum, archiviert, archiviertAm, archiviertVon) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($notizen as $n) {
            $nStmt->execute([
                $n['id'] ?? null, $n['text'] ?? '', $n['baustelleId'] ?? null,
                $n['baustelleName'] ?? '', $n['ersteller'] ?? '', $n['kuerzel'] ?? '',
                $n['datum'] ?? '', (int)($n['archiviert'] ?? 0),
                $n['archiviertAm'] ?? '', $n['archiviertVon'] ?? '',
            ]);
        }
        info("Schnellnotizen importiert: " . count($notizen));
    } else {
        warn("Keine Schnellnotizen gefunden ($SCHNELLNOTIZEN_FILE).");
    }

    // ── 8) Rechnungen ────────────────────────────────────────
    $rechnungen = readJson($RECHNUNGEN_FILE);
    if (is_array($rechnungen)) {
        $rStmt = $db->prepare("INSERT OR REPLACE INTO rechnungen (id, typ, nummer, kundeId, baustelleId, datum, faelligAm, status, absender, positionen, notizen, beschreibung, zahlungsziel, createdAt, createdBy, updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rechnungen as $r) {
            $rStmt->execute([
                $r['id'] ?? '', $r['typ'] ?? 'rechnung', $r['nummer'] ?? '',
                $r['kundeId'] ?? null, $r['baustelleId'] ?? null,
                $r['datum'] ?? '', $r['faelligAm'] ?? '', $r['status'] ?? 'offen',
                is_array($r['absender'] ?? null) ? json_encode($r['absender']) : ($r['absender'] ?? '{}'),
                is_array($r['positionen'] ?? null) ? json_encode($r['positionen']) : ($r['positionen'] ?? '[]'),
                $r['notizen'] ?? '', $r['beschreibung'] ?? '', $r['zahlungsziel'] ?? '',
                $r['createdAt'] ?? '', $r['createdBy'] ?? '', $r['updatedAt'] ?? '',
            ]);
        }
        info("Rechnungen/Angebote importiert: " . count($rechnungen));
    } else {
        warn("Keine Rechnungsdaten gefunden ($RECHNUNGEN_FILE).");
    }

    // ── 9) Einstellungen ─────────────────────────────────────
    $settingsData = readJson($SETTINGS_FILE);
    if (is_array($settingsData)) {
        $db->prepare("UPDATE settings SET data = ? WHERE id = 1")
           ->execute([json_encode($settingsData, JSON_UNESCAPED_UNICODE)]);
        info("Einstellungen importiert.");
    }

    // ── 10) Berechtigungen ───────────────────────────────────
    $permsData = readJson($PERMISSIONS_FILE);
    if (is_array($permsData)) {
        $db->prepare("UPDATE permissions_config SET data = ? WHERE id = 1")
           ->execute([json_encode($permsData, JSON_UNESCAPED_UNICODE)]);
        info("Berechtigungen importiert.");
    }

    // ── 11) Metallzuschlag ───────────────────────────────────
    $metalData = readJson($METALLZUSCHLAG_FILE);
    if (is_array($metalData)) {
        $db->prepare("UPDATE metallzuschlag SET data = ? WHERE id = 1")
           ->execute([json_encode($metalData, JSON_UNESCAPED_UNICODE)]);
        info("Metallzuschlag importiert.");
    }

    // ── 12) Calendar Tokens ──────────────────────────────────
    $calFile = DATA_DIR . 'cal_tokens.json';
    $calData = readJson($calFile);
    if (is_array($calData)) {
        $ctStmt = $db->prepare("INSERT OR REPLACE INTO cal_tokens (token, username, role, created) VALUES (?,?,?,?)");
        foreach ($calData as $token => $info) {
            if (is_array($info)) {
                $ctStmt->execute([$token, $info['username'] ?? '', $info['role'] ?? 'normal', $info['created'] ?? '']);
            }
        }
        info("Calendar-Tokens importiert.");
    }

    // ── 13) Erinnerung-Einstellungen ─────────────────────────
    $erinnerungFile = DATA_DIR . 'erinnerung_settings.json';
    $erinnerungData = readJson($erinnerungFile);
    if (is_array($erinnerungData)) {
        $db->prepare("UPDATE erinnerung_settings SET data = ? WHERE id = 1")
           ->execute([json_encode($erinnerungData, JSON_UNESCAPED_UNICODE)]);
        info("Erinnerung-Einstellungen importiert.");
    }

    $db->commit();
    echo "\n=== Migration erfolgreich abgeschlossen! ===\n";
    echo "Datenbank: $dbPath\n";
    echo "Größe: " . round(filesize($dbPath) / 1024, 1) . " KB\n";

} catch (\Exception $e) {
    $db->rollBack();
    echo "\n[FEHLER] Migration fehlgeschlagen: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

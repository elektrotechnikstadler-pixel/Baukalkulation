<?php
namespace App;

/**
 * Laden / Speichern der Haupt-Projektdaten (Baustellen + Kataloge).
 */
class DataService
{
    /** Komplette Projektdaten aus SQLite laden (Format = bisheriges JSON). */
    public static function loadAllData(\PDO $db): array
    {
        // Baustellen (archivierte bleiben in der DB, erscheinen aber nicht in der App)
        $baustellen = [];
        $rows = $db->query("SELECT id, name, kundeId, data FROM baustellen WHERE archiviert = 0 ORDER BY id")->fetchAll();
        foreach ($rows as $r) {
            $b = json_decode($r['data'], true) ?: [];
            $b['id']      = (int)$r['id'];
            $b['name']    = $r['name'];
            $b['kundeId'] = $r['kundeId'] !== null ? (int)$r['kundeId'] : null;
            $baustellen[] = $b;
        }

        // Pauschalen (v2.9.20: preis in DB ist Cents-INTEGER, Konvertierung -> Euro)
        $pauschalen = [];
        foreach ($db->query("SELECT id, name, preis FROM pauschalen ORDER BY id")->fetchAll() as $r) {
            $pauschalen[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'preis' => money_from_cents($r['preis'])];
        }

        // Stundenkatalog (v2.9.20: Cents -> Euro)
        $stundenKatalog = [];
        foreach ($db->query("SELECT id, kategorie, preis, fixkosten FROM stunden_katalog ORDER BY id")->fetchAll() as $r) {
            $stundenKatalog[] = [
                'id'        => (int)$r['id'],
                'kategorie' => $r['kategorie'],
                'preis'     => money_from_cents($r['preis']),
                'fixkosten' => money_from_cents($r['fixkosten']),
            ];
        }

        // Materialkatalog (v2.9.20: ek in Cents, aufschlag in Basispunkten = %*100)
        $materialKatalog = [];
        foreach ($db->query("SELECT id, bezeichnung, einheit, ek, aufschlag, artikelNr FROM material_katalog ORDER BY id")->fetchAll() as $r) {
            $materialKatalog[] = [
                'id'          => (int)$r['id'],
                'bezeichnung' => $r['bezeichnung'],
                'einheit'     => $r['einheit'],
                'ek'          => money_from_cents($r['ek']),
                'aufschlag'   => money_from_cents($r['aufschlag']),
                'artikelNr'   => $r['artikelNr'],
            ];
        }

        $nextBaustelleId  = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM baustellen")->fetchColumn();
        $nextPauschaleId  = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM pauschalen")->fetchColumn();
        $nextKatalogId    = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM material_katalog")->fetchColumn();
        $nextStundenKatId = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM stunden_katalog")->fetchColumn();

        return [
            'baustellen'      => $baustellen,
            'pauschalen'      => $pauschalen,
            'stundenKatalog'  => $stundenKatalog,
            'materialKatalog' => $materialKatalog,
            'nextBaustelleId' => $nextBaustelleId,
            'nextPauschaleId' => $nextPauschaleId,
            'nextKatalogId'   => $nextKatalogId,
            'nextStundenKatId'=> $nextStundenKatId,
            'rev'             => self::currentRev($db),
        ];
    }

    /** Aktuelle globale Datensatz-Revision (Optimistic Locking). */
    public static function currentRev(\PDO $db): int
    {
        $v = $db->query("SELECT rev FROM data_meta WHERE id = 1")->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    /**
     * Datensatz-Revision erhöhen und neue Revision zurückgeben.
     * MUSS innerhalb einer bestehenden Transaktion aufgerufen werden (kein eigenes
     * begin/commit), damit der Bump atomar zum jeweiligen Schreibvorgang gehört.
     */
    public static function bumpRev(\PDO $db, string $user = ''): int
    {
        $newRev = self::currentRev($db) + 1;
        $db->prepare("UPDATE data_meta SET rev = ?, updatedAt = ?, updatedBy = ? WHERE id = 1")
           ->execute([$newRev, date('c'), $user]);
        return $newRev;
    }

    /**
     * Komplette Projektdaten in SQLite speichern (Transaktion).
     *
     * Optimistic Locking: Ist $baseRev gesetzt und weicht (ohne $force) von der
     * aktuellen Datensatz-Revision ab, wird NICHT geschrieben, sondern ein
     * Konflikt zurückgegeben. Bei Erfolg wird die Revision erhöht.
     *
     * @return array ['rev' => int] bei Erfolg | ['conflict' => true, 'currentRev' => int,
     *               'updatedAt' => string, 'updatedBy' => string] bei Konflikt.
     */
    public static function saveAllData(\PDO $db, array $data, ?int $baseRev = null, bool $force = false, string $user = ''): array
    {
        $db->beginTransaction();
        try {
            // ── Optimistic-Locking-Prüfung (vor jedem Schreibzugriff) ──
            // WICHTIG: Auch bei baseRev === null (z.B. Offline-Sync, alter Cache) wird
            // geprüft, ob bereits Daten existieren. Ohne baseRev darf NUR geschrieben
            // werden, wenn die DB noch leer ist (Ersteinrichtung) oder force gesetzt ist.
            // Verhindert, dass ein veralteter Client (alter Browser-Cache) die DB überschreibt.
            $curRev = self::currentRev($db);
            if ($baseRev === null && !$force && $curRev > 0) {
                // Kein baseRev gesendet, aber DB hat bereits Daten → Konflikt
                $meta = $db->query("SELECT updatedAt, updatedBy FROM data_meta WHERE id = 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
                $db->rollBack();
                return [
                    'conflict'   => true,
                    'currentRev' => $curRev,
                    'updatedAt'  => (string)($meta['updatedAt'] ?? ''),
                    'updatedBy'  => (string)($meta['updatedBy'] ?? ''),
                ];
            }
            // Letzte Verteidigung: force ohne gültige Rev darf DB nicht zerstören
            if ($force && $baseRev === null && $curRev > 0) {
                $dbBsCount = (int)$db->query("SELECT COUNT(*) FROM baustellen WHERE archiviert = 0")->fetchColumn();
                $inBsCount = count($data['baustellen'] ?? []);
                if ($dbBsCount > 3 && $inBsCount < $dbBsCount / 2) {
                    $db->rollBack();
                    return ['error' => "Force-Save abgelehnt: Payload hat $inBsCount von $dbBsCount Baustellen ohne gültige Revision."];
                }
            }
            if ($baseRev !== null && !$force && $baseRev !== $curRev) {
                $meta = $db->query("SELECT updatedAt, updatedBy FROM data_meta WHERE id = 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
                $db->rollBack();
                return [
                    'conflict'   => true,
                    'currentRev' => $curRev,
                    'updatedAt'  => (string)($meta['updatedAt'] ?? ''),
                    'updatedBy'  => (string)($meta['updatedBy'] ?? ''),
                ];
            }
            // ── Baustellen synchronisieren (NUR wenn im Payload enthalten) ──
            // Fehlt der Schlüssel 'baustellen' komplett, bleibt die Tabelle
            // unangetastet – ein unvollständiger/fehlerhafter Payload darf
            // niemals Baustellen löschen. Nur ein aktiv übergebenes (auch leeres)
            // 'baustellen'-Array verändert die Tabelle.
            $abschlagRemovals = [];
            if (array_key_exists('baustellen', $data) && is_array($data['baustellen'])) {
            // Archivierte Baustellen sind bewusst nicht Teil des Payloads und dürfen
            // deshalb nie als "vom Client entfernt" interpretiert werden.
            $existingRows = $db->query("SELECT id, data FROM baustellen WHERE archiviert = 0")->fetchAll(\PDO::FETCH_ASSOC);
            $existingIds  = array_map(fn($r) => (int)$r['id'], $existingRows);
            // Alten Abschlags-Stand je Baustelle merken (für Verlust-Audit).
            $oldAbschlaege = [];
            foreach ($existingRows as $er) {
                $ed = json_decode($er['data'], true) ?: [];
                $oldAbschlaege[(int)$er['id']] = is_array($ed['abschlaege'] ?? null) ? $ed['abschlaege'] : [];
            }
            $incomingIds = [];

            // UPSERT statt INSERT OR REPLACE: letzteres würde die Zeile neu anlegen und
            // dabei die Archiv-Spalten auf ihre Defaults zurücksetzen.
            $bStmt = $db->prepare("
                INSERT INTO baustellen (id, name, kundeId, data) VALUES (?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET
                    name    = excluded.name,
                    kundeId = excluded.kundeId,
                    data    = excluded.data
            ");
            foreach ($data['baustellen'] ?? [] as $b) {
                $id      = (int)$b['id'];
                $incomingIds[] = $id;
                $name    = $b['name'] ?? '';
                $kundeId = $b['kundeId'] ?? null;
                // Audit: entfernt dieser Save vorhandene Abschlagsrechnungen?
                $old = $oldAbschlaege[$id] ?? [];
                if ($old) {
                    $newAbs = is_array($b['abschlaege'] ?? null) ? $b['abschlaege'] : [];
                    $newIds = array_map(fn($a) => $a['id'] ?? null, $newAbs);
                    $removed = array_filter($old, fn($a) => isset($a['id']) && !in_array($a['id'], $newIds, true));
                    if ($removed) {
                        $abschlagRemovals[] = [
                            'baustelleId' => $id,
                            'name'        => $name,
                            'removed'     => array_map(fn($a) => [
                                'id'          => $a['id'] ?? null,
                                'bezeichnung' => (string)($a['bezeichnung'] ?? ''),
                                'betrag'      => (float)($a['betrag'] ?? 0),
                                'art'         => (string)($a['art'] ?? ''),
                            ], array_values($removed)),
                        ];
                    }
                }
                // id, name, kundeId aus dem Blob entfernen (schon als Spalten)
                $nested  = $b;
                unset($nested['id'], $nested['name'], $nested['kundeId']);
                $bStmt->execute([$id, $name, $kundeId, json_encode($nested, JSON_UNESCAPED_UNICODE)]);
            }

            $removed = array_diff($existingIds, $incomingIds);
            if ($removed) {
                // Schutz: Baustellen, die in der Zeiterfassung noch referenziert werden,
                // dürfen NICHT gelöscht werden (verhindert Stundenverlust bei Race-Conditions
                // mit neuen Projekten / parallelem Mehrbenutzerbetrieb).
                if (count($removed) > 0) {
                    $phRef = implode(',', array_fill(0, count($removed), '?'));
                    $refRows = $db->prepare("SELECT DISTINCT baustelleId FROM zeiterfassung WHERE baustelleId IN ($phRef)");
                    $refRows->execute(array_values($removed));
                    $referenced = array_map('intval', $refRows->fetchAll(\PDO::FETCH_COLUMN));
                    if ($referenced) {
                        $removed = array_diff($removed, $referenced);
                    }
                }
                if ($removed) {
                    $ph = implode(',', array_fill(0, count($removed), '?'));
                    $db->prepare("DELETE FROM baustellen WHERE id IN ($ph)")->execute(array_values($removed));
                }
            }
            }

            // ── Pauschalen (v2.9.20: Euro-Dezimal aus API -> Cents in DB)
            if (array_key_exists('pauschalen', $data) && is_array($data['pauschalen'])) {
            // Guard: leeren Payload nur akzeptieren wenn DB auch leer ist (verhindert Massen-Löschung)
            $pDbCount = (int)$db->query("SELECT COUNT(*) FROM pauschalen")->fetchColumn();
            if (count($data['pauschalen']) > 0 || $pDbCount === 0) {
            $db->exec("DELETE FROM pauschalen");
            $pStmt = $db->prepare("INSERT INTO pauschalen (id, name, preis) VALUES (?, ?, ?)");
            foreach ($data['pauschalen'] ?? [] as $p) {
                $pStmt->execute([(int)($p['id'] ?? 0), $p['name'] ?? '', money_to_cents($p['preis'] ?? 0)]);
            }
            }
            }

            // ── Stundenkatalog (v2.9.20: Euro -> Cents)
            if (array_key_exists('stundenKatalog', $data) && is_array($data['stundenKatalog'])) {
            $sDbCount = (int)$db->query("SELECT COUNT(*) FROM stunden_katalog")->fetchColumn();
            if (count($data['stundenKatalog']) > 0 || $sDbCount === 0) {
            $db->exec("DELETE FROM stunden_katalog");
            $sStmt = $db->prepare("INSERT INTO stunden_katalog (id, kategorie, preis, fixkosten) VALUES (?, ?, ?, ?)");
            foreach ($data['stundenKatalog'] ?? [] as $s) {
                $sStmt->execute([
                    (int)($s['id'] ?? 0),
                    $s['kategorie'] ?? '',
                    money_to_cents($s['preis'] ?? 0),
                    money_to_cents($s['fixkosten'] ?? 0),
                ]);
            }
            }
            }

            // ── Materialkatalog (v2.9.20: ek -> Cents, aufschlag -> Basispunkte)
            if (array_key_exists('materialKatalog', $data) && is_array($data['materialKatalog'])) {
            $mDbCount = (int)$db->query("SELECT COUNT(*) FROM material_katalog")->fetchColumn();
            if (count($data['materialKatalog']) > 0 || $mDbCount === 0) {
            $db->exec("DELETE FROM material_katalog");
            $mStmt = $db->prepare("INSERT INTO material_katalog (id, bezeichnung, einheit, ek, aufschlag, artikelNr) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($data['materialKatalog'] ?? [] as $m) {
                $mStmt->execute([
                    (int)($m['id'] ?? 0),
                    $m['bezeichnung'] ?? '',
                    $m['einheit'] ?? 'Stk',
                    money_to_cents($m['ek'] ?? 0),
                    money_to_cents($m['aufschlag'] ?? 0),
                    $m['artikelNr'] ?? '',
                ]);
            }
            }
            }

            $newRev = self::bumpRev($db, $user);

            $db->commit();
            return ['rev' => $newRev, 'abschlagRemovals' => $abschlagRemovals];
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Einzelne Baustelle laden (für gezielte Operationen). */
    public static function loadBaustelle(\PDO $db, int $id): ?array
    {
        $row = Database::fetchOne($db, "SELECT id, name, kundeId, data FROM baustellen WHERE id = ?", [$id]);
        if (!$row) return null;
        $b = json_decode($row['data'], true) ?: [];
        $b['id']      = (int)$row['id'];
        $b['name']    = $row['name'];
        $b['kundeId'] = $row['kundeId'] !== null ? (int)$row['kundeId'] : null;
        return $b;
    }

    /** Einzelne Baustelle speichern. */
    public static function saveBaustelle(\PDO $db, array $b): void
    {
        $id      = (int)$b['id'];
        $name    = $b['name'] ?? '';
        $kundeId = $b['kundeId'] ?? null;
        $nested  = $b;
        unset($nested['id'], $nested['name'], $nested['kundeId']);
        // UPSERT statt INSERT OR REPLACE, damit die Archiv-Spalten erhalten bleiben.
        $db->prepare("
            INSERT INTO baustellen (id, name, kundeId, data) VALUES (?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                name    = excluded.name,
                kundeId = excluded.kundeId,
                data    = excluded.data
        ")->execute([$id, $name, $kundeId, json_encode($nested, JSON_UNESCAPED_UNICODE)]);
    }

    /** Tägliches Backup erstellen. */
    public static function createDailyBackup(\PDO $db, array $data): void
    {
        $today      = date('Y-m-d');
        $backupDir  = BACKUP_DIR . $today . '/';
        if (is_dir($backupDir)) return; // Backup existiert schon

        if (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            error_log("Backup-Verzeichnis konnte nicht erstellt werden: $backupDir");
            return;
        }
        $written = file_put_contents(
            $backupDir . 'baukalkulation.json',
            json_encode(['ts' => date('c'), 'data' => $data], JSON_UNESCAPED_UNICODE)
        );
        if ($written === false) {
            error_log("Backup-JSON konnte nicht geschrieben werden: $backupDir");
            return;
        }

        // SQLite-Datei kopieren. WAL-Checkpoint erzwingen, damit alle committed
        // Transaktionen in der Hauptdatei stehen – sonst fehlen im Backup die
        // zuletzt gespeicherten Modul-Daten (WAL-Modus, v2.10.x).
        $dbPath = DATA_DIR . 'database.sqlite';
        if (file_exists($dbPath)) {
            try { $db->exec('PRAGMA wal_checkpoint(FULL)'); }
            catch (\Throwable $e) { error_log('WAL-Checkpoint vor Tagessicherung fehlgeschlagen: ' . $e->getMessage()); }
            if (!copy($dbPath, $backupDir . 'database.sqlite')) {
                error_log("SQLite-Backup-Kopie fehlgeschlagen: $backupDir");
            }
        }

        // Alte TAGES-Sicherungen aufräumen. Wichtig: manuelle und Pre-Restore-
        // Sicherungen werden NICHT angetastet, damit bewusst angelegte
        // Wiederherstellungspunkte nicht durch ein Routine-Backup verloren gehen.
        self::pruneBackupCategory('daily', BACKUP_MAX);
    }

    /**
     * Räumt alte Sicherungen EINER Kategorie auf und behält die $keep neuesten.
     * Kategorien werden anhand des Verzeichnisnamens erkannt:
     *   - 'daily'       : exakt JJJJ-MM-TT (automatische Tagessicherung)
     *   - 'pre-restore' : enthält '_pre-' (Sicherheits-Snapshot vor Restore/Upload)
     *   - 'manual'      : JJJJ-MM-TT_… (benannte manuelle Sicherung)
     * Andere Kategorien bleiben unangetastet – so kann das Aufräumen einer
     * Kategorie niemals Sicherungen einer anderen löschen.
     */
    public static function pruneBackupCategory(string $category, int $keep): void
    {
        $all = glob(BACKUP_DIR . '*', GLOB_ONLYDIR) ?: [];
        $matching = array_filter($all, function ($d) use ($category) {
            $base         = basename($d);
            $isDaily      = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $base);
            $isPreRestore = strpos($base, '_pre-') !== false;
            $isManual     = !$isDaily && !$isPreRestore
                            && (bool) preg_match('/^\d{4}-\d{2}-\d{2}_/', $base);
            return match ($category) {
                'daily'       => $isDaily,
                'pre-restore' => $isPreRestore,
                'manual'      => $isManual,
                default       => false,
            };
        });
        rsort($matching);
        foreach (array_slice($matching, max(0, $keep)) as $old) {
            foreach (glob($old . '/*') ?: [] as $f) @unlink($f);
            @rmdir($old);
        }
    }
}

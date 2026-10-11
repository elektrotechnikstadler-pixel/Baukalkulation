<?php
namespace App\Handlers;

use App\Auth;
use App\Database;
use App\Services\BuchungValidator;
use App\Services\Feiertage;
use App\Services\Gleitzeit;
use App\Services\Sollzeit;

class ZeiterfassungActions
{
    /** Abwesenheitstypen, die serverseitig immer mit Soll/Tag gutgeschrieben werden (Gleitzeit-neutral). */
    private const CREDITED_ABSENCE_TYPEN = ['urlaub', 'krank'];

    public function __construct(private \PDO $db, private array $body) {}

    /**
     * Lädt die Arbeitszeit-Konfiguration eines Users.
     * @return array<string, mixed>
     */
    private function loadUserZeitConfig(string $username): array
    {
        $u = Database::fetchOne($this->db, "SELECT sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, urlaubstageProJahr FROM users WHERE username = ?", [$username]);
        $upj = json_decode((string)($u['urlaubstageProJahr'] ?? '{}'), true);
        if (!is_array($upj)) $upj = [];
        return $u + ['urlaubProJahr' => $upj];
    }

    /** Gutschrift-Stunden für einen Abwesenheitstag nach dem aktuellen Tages-Soll. */
    private function absenceStundenForDate(string $datum, array $zeitCfg): float
    {
        return Sollzeit::tagesSoll($zeitCfg, $datum);
    }

    /** Ermittelt das Urlaubs-Jahreslimit (Default 30, falls für das Jahr nichts hinterlegt). */
    private function urlaubLimitForYear(array $urlaubProJahr, string $year): int
    {
        return (int)Sollzeit::urlaubsanspruch(['urlaubstageProJahr' => $urlaubProJahr], (int)$year);
    }

    /** save_zeiterfassung */
    public function save(): void
    {
        Auth::requireAuth();
        $username = $_SESSION['username'];
        $entries  = $this->body['entries'] ?? [];
        if (!is_array($entries)) {
            jsonOut(['error' => 'Ungültige Zeiterfassungsdaten.'], 400);
        }
        // Blocker-Prüfung über den zentralen Fehlbuchungs-Katalog (ZE001/ZE002/ZE010/ZE011).
        $blockers = BuchungValidator::findBlockers($entries);
        if ($blockers) {
            $first = $blockers[0];
            jsonOut([
                'error'    => $first['user_friendly_message'] . (isset($first['detail']) ? ' (' . $first['detail'] . ')' : ''),
                'code'     => $first['error_code'],
                'befunde'  => $blockers,
            ] + $first, 400);
        }
        $settings  = Auth::loadSettings($this->db);
        $erweitert = !empty($settings['erweiterte_zeiterfassung']);

        // Audit: alte Einträge lesen
        $oldRows = Database::fetchAll($this->db, "SELECT entryId, datum, typ, baustelleId, stunden, bemerkung, von, bis, pause FROM zeiterfassung WHERE username = ?", [$username]);

        // Arbeitszeit-Konfiguration (Soll/Tag, Arbeitstage, Urlaubslimit) laden
        $zeitCfg = $this->loadUserZeitConfig($username);

        // Urlaubslimit-Prüfung: Buchung nur blockieren, wenn NEUE Urlaubstage über das
        // Jahreslimit hinaus hinzukommen. Bestehende Über-Limit-Altdaten werden geduldet,
        // damit reine Bearbeitungen (z.B. Arbeits-Eintrag ändern) nicht scheitern.
        $oldUrlaubByYear = [];
        foreach (Database::fetchAll($this->db, "SELECT datum FROM zeiterfassung WHERE username = ? AND typ = 'urlaub'", [$username]) as $r) {
            $y = substr((string)($r['datum'] ?? ''), 0, 4);
            if ($y !== '') $oldUrlaubByYear[$y] = ($oldUrlaubByYear[$y] ?? 0) + 1;
        }
        $newUrlaubByYear = [];
        foreach ($entries as $e) {
            if (($e['typ'] ?? '') !== 'urlaub') continue;
            $y = substr((string)($e['datum'] ?? ''), 0, 4);
            if ($y !== '') $newUrlaubByYear[$y] = ($newUrlaubByYear[$y] ?? 0) + 1;
        }
        foreach ($newUrlaubByYear as $y => $cnt) {
            $limit  = $this->urlaubLimitForYear($zeitCfg['urlaubProJahr'], (string)$y);
            $oldCnt = $oldUrlaubByYear[$y] ?? 0;
            if ($cnt > $limit && $cnt > $oldCnt) {
                jsonOut(['error' => "Urlaubslimit für {$y} erreicht ({$limit} Tage). Buchung nicht möglich."], 400);
            }
        }

        // Auto-Import-Konfiguration des Users (Kürzel + Stundenkategorie) vorab laden,
        // damit die Projektbuchung in derselben Transaktion wie die Zeiterfassung erfolgt.
        $u = Database::fetchOne($this->db, "SELECT kuerzel, stundenKategorie FROM users WHERE username = ?", [$username]);
        $kuerzel   = (string)($u['kuerzel'] ?? '');
        $kategorie = (string)($u['stundenKategorie'] ?? '');
        $autoImported = 0;
        $autoUpdated  = 0;
        $autoRemoved  = 0;
        $newDataRev   = null;
        $newZeitRev   = null;
        // Alte Clients kennen das Flag nicht -> Default true erhält deren Verhalten.
        $fullSnapshot = !array_key_exists('fullSnapshot', $this->body) || !empty($this->body['fullSnapshot']);

        // Projektnamen für die Denormalisierung (inkl. archivierter Projekte).
        $projektNamen = [];
        foreach (Database::fetchAll($this->db, "SELECT id, name FROM baustellen") as $pn) {
            $projektNamen[(int)$pn['id']] = (string)$pn['name'];
        }

        // ── Optimistic Locking ──
        // Kennt der Client eine Revision und ist die serverseitige neuer, hat ein
        // anderes Gerät zwischenzeitlich gespeichert. Statt still zu überschreiben
        // wird der Konflikt gemeldet; der Client kann per clientUuid mergen.
        $baseZeitRev = array_key_exists('baseZeitRev', $this->body) && $this->body['baseZeitRev'] !== null
            ? (int)$this->body['baseZeitRev'] : null;
        $currentZeitRev = $this->currentZeitRev($username);
        if ($baseZeitRev !== null && empty($this->body['forceReplace']) && $baseZeitRev < $currentZeitRev) {
            jsonOut([
                'error'      => 'Die Stunden wurden zwischenzeitlich auf einem anderen Gerät geändert. '
                              . 'Es wurde nichts überschrieben – bitte neu laden.',
                'code'       => 'zeit_conflict',
                'needsMerge' => true,
                'zeitRev'    => $currentZeitRev,
            ], 409);
        }

        // ── Datenverlust-Schutz: destruktives Full-Replace abfangen ──
        // save_zeiterfassung ersetzt ALLE Stunden des Users. Ein veralteter/leerer
        // Client-Payload (fehlgeschlagenes Laden, zweites Gerät/Tab) würde sonst die
        // gesamte Zeiterfassung überschreiben. Bei drastischer Schrumpfung ohne
        // ausdrückliche Bestätigung (forceReplace) wird NICHTS verändert.
        $forceReplace = !empty($this->body['forceReplace']);
        $dbCount = (int)(Database::fetchOne($this->db, "SELECT COUNT(*) AS c FROM zeiterfassung WHERE username = ?", [$username])['c'] ?? 0);
        $inCount = count($entries);
        if (!$forceReplace && $dbCount > 0 && $inCount < $dbCount) {
            $deletes = $dbCount - $inCount;
            $drastic = ($inCount === 0 && $dbCount >= 3) || ($deletes >= 5 && $inCount < $dbCount / 2);
            if ($drastic) {
                jsonOut([
                    'error'        => "Speichern abgebrochen: Es würden {$deletes} von {$dbCount} Stundeneinträgen gelöscht. "
                                    . "Vermutlich ist die Erfassung nicht vollständig geladen – es wurde NICHTS verändert.",
                    'needsConfirm' => true,
                ], 409);
            }
        }

        // Zeiterfassung UND Auto-Import atomar in einer Transaktion: Entweder beide
        // Seiten (User-Stunden + Projektbuchung) werden geschrieben oder keine.
        // Verhindert asynchrone Fehlbuchungen bei Abbruch/Timeout des Requests.
        $this->db->beginTransaction();
        try {
            // Identität und Erstellzeitpunkt über den Full-Replace hinweg retten,
            // damit clientUuid und createdAt nicht bei jedem Speichern neu entstehen.
            $prevMeta = [];
            $vorherWerte = []; // entryId => Werte vor dem Speichern (Altbuchungen ohne Spiegel)
            foreach (Database::fetchAll($this->db, "SELECT entryId, clientUuid, createdAt, baustelleId, baustelleName, typ, stunden, datum, stundenKatId FROM zeiterfassung WHERE username = ?", [$username]) as $pm) {
                if ($pm['entryId'] === null) continue;
                $prevMeta[(int)$pm['entryId']] = $pm;
                if ($pm['typ'] === 'arbeit') $vorherWerte[(int)$pm['entryId']] = $pm;
            }
            $now    = date('Y-m-d H:i:s');
            $quelle = $this->requestQuelle();

            $this->db->prepare("DELETE FROM zeiterfassung WHERE username = ?")->execute([$username]);
            $stmt = $this->db->prepare(
                "INSERT INTO zeiterfassung
                   (username, entryId, datum, typ, baustelleId, stunden, bemerkung, von, bis, pause, stundenKatId,
                    clientUuid, status, errorCode, errorMessage, baustelleName, createdAt, updatedAt, quelle)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $written  = [];
            $moveLogs = [];
            foreach ($entries as $e) {
                $typ        = $e['typ'] ?? '';
                $stundenVal = (float)($e['stunden'] ?? 0);
                // Urlaub/Krank werden serverseitig immer mit Soll/Tag gutgeschrieben (Gleitzeit-neutral)
                if (in_array($typ, self::CREDITED_ABSENCE_TYPEN, true)) {
                    $stundenVal = $this->absenceStundenForDate((string)($e['datum'] ?? ''), $zeitCfg);
                }
                $eid  = isset($e['id']) && $e['id'] !== null ? (int)$e['id'] : 0;
                $prev = $eid > 0 ? ($prevMeta[$eid] ?? null) : null;

                $uuid = trim((string)($e['clientUuid'] ?? ''));
                if ($uuid === '') $uuid = (string)($prev['clientUuid'] ?? '');
                if ($uuid === '') $uuid = self::newUuid();

                $bId = isset($e['baustelleId']) && $e['baustelleId'] !== '' && $e['baustelleId'] !== null
                    ? (int)$e['baustelleId'] : null;
                // Projektname denormalisieren: die Zuordnung muss ein Archivieren
                // oder Löschen des Projekts überleben.
                $bName = $bId !== null ? (string)($projektNamen[$bId] ?? ($prev['baustelleName'] ?? '')) : '';

                $stmt->execute([
                    $username,
                    $e['id'] ?? null,
                    $e['datum'] ?? '',
                    $typ,
                    $bId,
                    $stundenVal,
                    $e['bemerkung'] ?? '',
                    $e['von'] ?? '',
                    $e['bis'] ?? '',
                    (float)($e['pause'] ?? 0),
                    (isset($e['stundenKatId']) && $e['stundenKatId'] !== '' && $e['stundenKatId'] !== null) ? (int)$e['stundenKatId'] : null,
                    $uuid,
                    BuchungValidator::STATUS_VALID,
                    '',
                    '',
                    $bName,
                    (string)($prev['createdAt'] ?? $now),
                    $now,
                    $quelle,
                ]);

                $written[] = [
                    'clientUuid'  => $uuid,
                    'id'          => $eid ?: null,
                    'typ'         => $typ,
                    'datum'       => $e['datum'] ?? '',
                    'baustelleId' => $bId,
                    'stunden'     => $stundenVal,
                ];

                // Projektwechsel für den Audit-Trail vormerken.
                $prevBId = $prev !== null && $prev['baustelleId'] !== null ? (int)$prev['baustelleId'] : null;
                if ($prev !== null && $prevBId !== $bId) {
                    $moveLogs[] = [
                        'entryId' => $eid,
                        'alt'     => ['baustelleId' => $prevBId, 'baustelleName' => (string)($prev['baustelleName'] ?? '')],
                        'neu'     => ['baustelleId' => $bId,      'baustelleName' => $bName],
                        'datum'   => (string)($e['datum'] ?? ''),
                    ];
                }
            }

            // Abgleich mit den Projektbuchungen (in derselben Transaktion).
            $vorherProjektIds = [];
            foreach ($oldRows as $r) {
                if (($r['typ'] ?? '') === 'arbeit' && $r['baustelleId'] !== null) $vorherProjektIds[] = (int)$r['baustelleId'];
            }
            $sync = $this->syncProjektbuchungen(
                $username, $kuerzel, $kategorie, $entries, $written, $vorherProjektIds,
                $fullSnapshot, !empty($settings['auto_import_stunden']), $vorherWerte
            );
            $autoImported = $sync['imported'];
            $autoUpdated  = $sync['updated'];
            $autoRemoved  = $sync['removed'];

            // Umbuchungen protokollieren (lückenlose Historie, unabhängig von der
            // Einstellung "erweiterte Zeiterfassung").
            if ($moveLogs) $this->writeMoveLogs($username, $moveLogs, $username, $now);

            // Hat der Auto-Import/Abgleich Baustellen-Daten verändert, die globale
            // Datensatz-Revision erhöhen (Optimistic Locking), damit andere Clients
            // den neuen Stand erkennen. $newDataRev wird dem Client zurückgegeben,
            // sodass sein appDataRev aktuell bleibt (kein Selbst-Konflikt).
            if ($sync['changed']) {
                $newDataRev = \App\DataService::bumpRev($this->db, $username);
            }

            $newZeitRev = self::bumpZeitRev($this->db, $username);

            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] save_zeiterfassung fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Zeiterfassung konnte nicht gespeichert werden.'], 500);
        }

        // Audit-Trail schreiben (nur bei erweiterter Zeiterfassung)
        if ($erweitert) {
            $this->writeAuditLog($username, $oldRows, $entries);
        }

        jsonOut([
            'ok'           => true,
            'autoImported' => $autoImported,
            'autoUpdated'  => $autoUpdated,
            'autoRemoved'  => $autoRemoved,
            'dataRev'      => $newDataRev,
            'zeitRev'      => $newZeitRev,
            'entries'      => $written,
        ]);
    }

    /**
     * Gleicht die Projektbuchungen eines Mitarbeiters mit seinem Zeiterfassungs-Stand ab:
     * verwaiste entfernen, fehlende anlegen, geänderte Werte nachziehen und den
     * Buchungsstatus setzen. Läuft in der Transaktion des Aufrufers und schreibt nie
     * Stunden oder Datum in die Zeiterfassung zurück.
     *
     * Deklariert der Client den Payload als unvollständig (z.B. Offline-Merge), wird
     * NICHT aufgeräumt – sonst würden fremde Projektbuchungen anhand einer Teilmenge
     * gelöscht.
     *
     * @param list<int> $vorherProjektIds Projekte des Stands vor der Änderung, damit
     *                                    auch dort verwaiste Buchungen entfernt werden
     * @param array<int, array<string, mixed>> $vorherWerte entryId => Zeiteintrag vor der
     *                                    Änderung (stunden, datum, stundenKatId)
     * @return array{imported: int, updated: int, removed: int, changed: bool}
     */
    private function syncProjektbuchungen(
        string $username,
        string $kuerzel,
        string $kategorie,
        array $entries,
        array $written,
        array $vorherProjektIds,
        bool $fullSnapshot,
        bool $autoImportAktiv,
        array $vorherWerte = []
    ): array {
        $result = ['imported' => 0, 'updated' => 0, 'removed' => 0, 'changed' => false];
        if ($kuerzel !== '') {
            if ($fullSnapshot) {
                $abgleich = $this->reconcileAutoImport($username, $kuerzel, $entries, $vorherProjektIds);
                $result['removed'] = $abgleich['removed'];
                $result['changed'] = $abgleich['written'];
            }
            if ($autoImportAktiv) {
                $import = $this->autoImportEntries($username, $kuerzel, $kategorie, $entries, $vorherWerte);
                $result['imported'] = $import['created'];
                $result['updated']  = $import['updated'];
                $result['changed']  = $result['changed'] || $import['written'];
            }
        }

        // Status NACH dem Abgleich bestimmen, damit neue Buchungen berücksichtigt sind.
        $this->applyBuchungsStatus($username, $kuerzel, $written, $autoImportAktiv);
        return $result;
    }

    /**
     * Gleicht nach einer Admin-Änderung den vollständigen Stand des Ziel-Benutzers ab
     * (gleiche Regeln wie save_zeiterfassung) und setzt den Status aller Einträge neu.
     *
     * @param list<int> $vorherProjektIds
     * @param array<int, array<string, mixed>> $vorherWerte siehe {@see syncProjektbuchungen()}
     * @return array{imported: int, updated: int, removed: int, changed: bool}
     */
    private function syncNachAdminAenderung(string $username, array $vorherProjektIds, array $vorherWerte = []): array
    {
        $u = Database::fetchOne($this->db, "SELECT kuerzel, stundenKategorie FROM users WHERE username = ?", [$username]);
        $rows = $this->resetBuchungsStatus($username);
        return $this->syncProjektbuchungen(
            $username,
            (string)($u['kuerzel'] ?? ''),
            (string)($u['stundenKategorie'] ?? ''),
            $rows,
            $rows,
            $vorherProjektIds,
            true,
            !empty(Auth::loadSettings($this->db)['auto_import_stunden']),
            $vorherWerte
        );
    }

    /**
     * Setzt den Buchungsstatus aller Einträge eines Benutzers zurück und liefert sie
     * im Format von save_zeiterfassung (id = entryId) für einen neuen Abgleich.
     *
     * @return list<array<string, mixed>>
     */
    private function resetBuchungsStatus(string $username): array
    {
        $this->db->prepare("UPDATE zeiterfassung SET status = ?, errorCode = '', errorMessage = '' WHERE username = ?")
            ->execute([BuchungValidator::STATUS_VALID, $username]);
        // entryId MUSS als "id" gelesen werden – sonst gelten alle Verknüpfungen als verwaist.
        return Database::fetchAll(
            $this->db,
            "SELECT entryId AS id, clientUuid, datum, typ, baustelleId, stunden, stundenKatId, bemerkung
               FROM zeiterfassung WHERE username = ?",
            [$username]
        );
    }

    /** Bewertet den Buchungsstatus aller Einträge eines Benutzers neu (nach Reparaturen). */
    private function aktualisiereBuchungsStatus(string $username): void
    {
        $u = Database::fetchOne($this->db, "SELECT kuerzel FROM users WHERE username = ?", [$username]);
        $this->applyBuchungsStatus(
            $username,
            (string)($u['kuerzel'] ?? ''),
            $this->resetBuchungsStatus($username),
            !empty(Auth::loadSettings($this->db)['auto_import_stunden'])
        );
    }

    /**
     * Bucht alle Arbeits-Einträge des aktuellen Speichervorgangs in die jeweiligen
     * Baustellen (Auto-Import) und zieht bestehende Buchungen nach.
     * Idempotent über {@see ensureProjectBookingInData()}.
     *
     * @param array<int, array<string, mixed>> $vorherWerte siehe {@see syncProjektbuchungen()}
     * @return array{created: int, updated: int, written: bool}
     */
    private function autoImportEntries(string $username, string $kuerzel, string $kategorie, array $entries, array $vorherWerte = []): array
    {
        $result = ['created' => 0, 'updated' => 0, 'written' => false];
        $projectIds = [];
        foreach ($entries as $entry) {
            if (($entry['typ'] ?? '') !== 'arbeit') continue;
            $bId = (int)($entry['baustelleId'] ?? 0);
            if ($bId > 0 && (float)($entry['stunden'] ?? 0) > 0) $projectIds[$bId] = true;
        }
        if (!$projectIds) return $result;

        $ids = array_keys($projectIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $projects = [];
        foreach (Database::fetchAll(
            $this->db,
            "SELECT id, data, archiviert FROM baustellen WHERE id IN ($ph)",
            $ids
        ) as $row) {
            $bId = (int)$row['id'];
            $projects[$bId] = [
                'archiviert' => (int)($row['archiviert'] ?? 0) === 1,
                'data'       => json_decode((string)$row['data'], true) ?: [],
                'changed'    => false,
            ];
        }

        [$catalogById, $catalogByName] = $this->loadStundenKatalog();

        foreach ($entries as $entry) {
            if (($entry['typ'] ?? '') !== 'arbeit') continue;
            $bId = (int)($entry['baustelleId'] ?? 0);
            if (!isset($projects[$bId]) || $projects[$bId]['archiviert']) continue;
            $ergebnis = $this->ensureProjectBookingInData(
                $projects[$bId]['data'],
                $entry,
                $username,
                $kuerzel,
                $kategorie,
                $catalogById,
                $catalogByName,
                $projects[$bId]['changed'],
                $vorherWerte[(int)($entry['id'] ?? 0)] ?? null
            );
            if ($ergebnis === 'created') $result['created']++;
            if ($ergebnis === 'updated') $result['updated']++;
        }

        $update = $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?");
        foreach ($projects as $bId => $project) {
            if (!$project['changed']) continue;
            $update->execute([json_encode($project['data'], JSON_UNESCAPED_UNICODE), $bId]);
            $result['written'] = true;
        }
        return $result;
    }

    /**
     * Stellt sicher, dass für einen Arbeits-Zeiteintrag eine Projektbuchung
     * (arbeitszeit-Position mit autoImport=true) in der Ziel-Baustelle existiert.
     * Verknüpfung primär exakt über zeitEntryId/zeitUser; Legacy-Positionen ohne
     * Link werden per Fuzzy-Treffer (Kürzel/Datum/Stunden) erkannt und – sofern
     * eine entryId vorliegt – nachträglich mit dem Link versehen (Migration).
     *
     * @param array $entry Zeiteintrag mit Keys id, datum, stunden, bemerkung
     * @param bool  $geschrieben true, wenn Projektdaten gespeichert wurden (auch ohne neue Buchung)
     * @return bool true, wenn eine neue Buchung angelegt wurde
     */
    private function ensureProjectBooking(
        int $bId,
        array $entry,
        string $username,
        string $kuerzel,
        string $kategorie,
        bool &$geschrieben = false
    ): bool {
        $geschrieben = false;
        $row = Database::fetchOne($this->db, "SELECT data, archiviert FROM baustellen WHERE id = ?", [$bId]);
        if (!$row || (int)($row['archiviert'] ?? 0) === 1) return false;

        [$catalogById, $catalogByName] = $this->loadStundenKatalog();

        $data = json_decode((string)$row['data'], true) ?: [];
        $ergebnis = $this->ensureProjectBookingInData(
            $data,
            $entry,
            $username,
            $kuerzel,
            $kategorie,
            $catalogById,
            $catalogByName,
            $geschrieben
        );
        if ($geschrieben) {
            $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")
                ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $bId]);
        }
        return $ergebnis === 'created';
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function loadStundenKatalog(): array
    {
        $byId = [];
        $byName = [];
        foreach (Database::fetchAll($this->db, "SELECT id, kategorie, preis, fixkosten FROM stunden_katalog") as $row) {
            $byId[(int)$row['id']] = $row;
            $byName[(string)$row['kategorie']] = $row;
        }
        return [$byId, $byName];
    }

    /**
     * Stellt die Projektbuchung zu einem Arbeits-Zeiteintrag in den Baustellen-Daten
     * sicher: neu anlegen oder – bei exakter Verknüpfung – nach {@see zieheBuchungNach()}
     * aktualisieren.
     *
     * @param bool $changed wird nur auf true gesetzt, nie zurückgesetzt
     * @param array<string, mixed>|null $vorher Zeiteintrag vor der Änderung (für Altbuchungen ohne Spiegel)
     * @return ''|'created'|'updated'
     */
    private function ensureProjectBookingInData(
        array &$bData,
        array $entry,
        string $username,
        string $kuerzel,
        string $kategorie,
        array $catalogById,
        array $catalogByName,
        bool &$changed,
        ?array $vorher = null
    ): string {
        $stunden = (float)($entry['stunden'] ?? 0);
        $datum   = (string)($entry['datum'] ?? '');
        $eid     = isset($entry['id']) && $entry['id'] !== null ? (int)$entry['id'] : 0;
        if ($stunden <= 0 || $datum === '') return '';
        if (!isset($bData['arbeitszeit']) || !is_array($bData['arbeitszeit'])) $bData['arbeitszeit'] = [];

        // Sperrliste: Vom Nutzer archivierte/gelöschte Buchungen NICHT erneut anlegen.
        // Schlüssel identisch zum Client (_azBookingKeys in script.js).
        $blocked = (isset($bData['azImportBlocked']) && is_array($bData['azImportBlocked'])) ? $bData['azImportBlocked'] : [];
        if ($blocked) {
            $idKey = 'id:' . strtolower($username) . ':' . $eid;
            $fzKey = 'fz:' . $kuerzel . ':' . $datum . ':' . (round($stunden, 2) + 0);
            if (($eid > 0 && in_array($idKey, $blocked, true)) || in_array($fzKey, $blocked, true)) {
                return '';
            }
        }

        // Preis aus Stundenkatalog (v2.9.20: Cents -> Euro).
        // Bevorzugt die pro Zeiteintrag gewählte Kategorie (stundenKatId), damit die
        // Projektbuchung dieselbe Kategorie/den selben Preis wie die Desktop-Auswahl trägt.
        // Fallback: Standard-Stundenkategorie des Users.
        $sk = $this->katalogEintrag($entry, $kategorie, $catalogById, $catalogByName);

        // Duplikat-/Bestandsprüfung
        foreach ($bData['arbeitszeit'] as $idx => $existing) {
            if (!($existing['autoImport'] ?? false)) continue;
            $hasLink = isset($existing['zeitEntryId']) && (int)$existing['zeitEntryId'] > 0;

            if ($eid > 0 && $hasLink
                && (int)$existing['zeitEntryId'] === $eid
                && strcasecmp((string)($existing['zeitUser'] ?? ''), $username) === 0) {
                $vorherBuchung = $bData['arbeitszeit'][$idx];
                if ($vorher !== null) {
                    // Eintragskategorie (null = Standard des Benutzers) wie bei der Buchung auflösen.
                    $vk = $this->katalogEintrag($vorher, $kategorie, $catalogById, $catalogByName);
                    $vorher['stundenKatId'] = $vk ? (int)$vk['id'] : null;
                }
                $nachgezogen = $this->zieheBuchungNach(
                    $bData['arbeitszeit'][$idx], $stunden, $datum, $sk, self::hatEigeneKategorie($entry), $catalogById, $vorher
                );
                if ($bData['arbeitszeit'][$idx] !== $vorherBuchung) $changed = true;
                return $nachgezogen ? 'updated' : '';
            }

            if (!$hasLink && ($existing['erstelltVon'] ?? '') === $kuerzel
                && ($existing['datum'] ?? '') === $datum
                && abs((float)($existing['stunden'] ?? 0) - $stunden) < 0.01) {
                // Legacy-Fuzzy-Treffer: Buchung existiert bereits.
                if ($eid > 0) {
                    // Nachträglich mit exaktem Link versehen (Migration).
                    $bData['arbeitszeit'][$idx]['zeitEntryId'] = $eid;
                    $bData['arbeitszeit'][$idx]['zeitUser']    = $username;
                    $this->zieheBuchungNach($bData['arbeitszeit'][$idx], $stunden, $datum, $sk, self::hatEigeneKategorie($entry), $catalogById);
                    $changed = true;
                }
                return '';
            }
        }

        $stundenKatId = $sk ? (int)$sk['id'] : null;
        $katName      = $sk ? (string)$sk['kategorie'] : $kategorie;

        $newId = 1;
        foreach ($bData['arbeitszeit'] as $a) {
            if (($a['id'] ?? 0) >= $newId) $newId = $a['id'] + 1;
        }
        $item = [
            'id'           => $newId,
            'beschreibung' => ((string)($entry['bemerkung'] ?? '')) ?: $katName,
            'stunden'      => $stunden,
            'stundenpreis' => $sk ? money_from_cents($sk['preis']) : 0.0,
            'stundenKatId' => $stundenKatId,
            'fixkosten'    => $sk ? money_from_cents($sk['fixkosten']) : 0.0,
            'datum'        => $datum,
            'erstelltVon'  => $kuerzel,
            'autoImport'   => true,
        ];
        if ($eid > 0) {
            $item['zeitEntryId']      = $eid;
            $item['zeitUser']         = $username;
            $item['zeitStunden']      = $stunden;
            $item['zeitDatum']        = $datum;
            $item['zeitStundenKatId'] = $stundenKatId;
            $item['zeitStundenpreis'] = $item['stundenpreis'];
            $item['zeitFixkosten']    = $item['fixkosten'];
        }
        $bData['arbeitszeit'][] = $item;
        $changed = true;
        return 'created';
    }

    /**
     * Katalogzeile für einen Zeiteintrag: bevorzugt die pro Eintrag gewählte
     * Kategorie, sonst die Standard-Stundenkategorie des Benutzers.
     *
     * @return array<string, mixed>|null
     */
    private function katalogEintrag(array $entry, string $kategorie, array $catalogById, array $catalogByName): ?array
    {
        $entryKatId = (isset($entry['stundenKatId']) && $entry['stundenKatId'] !== '' && $entry['stundenKatId'] !== null)
            ? (int)$entry['stundenKatId'] : 0;
        return $entryKatId > 0 ? ($catalogById[$entryKatId] ?? null) : ($catalogByName[$kategorie] ?? null);
    }

    /**
     * Zieht eine verknüpfte Projektbuchung dem Zeiteintrag nach.
     *
     * Die Felder zeitStunden/zeitDatum/zeitStundenKatId spiegeln den zuletzt
     * abgeglichenen Zeiteintrag. Weicht die Buchung davon ab, wurde sie im Projekt von
     * Hand geändert und behält ihre Werte; nur der Spiegel wird aktualisiert. Altbuchungen
     * ohne Spiegel werden mit dem Zeiteintrag vor der Änderung ($vorher) verglichen; ist
     * auch der unbekannt, wird nichts überschrieben. Zur Kategorie siehe {@see folgeKategorie()}.
     *
     * @param array<string, mixed>      $booking
     * @param array<string, mixed>|null $sk          Katalogzeile des Zeiteintrags
     * @param bool                      $katExplizit Eintrag hat eine eigene Stundenkategorie
     * @param array<int, array<string, mixed>> $catalogById
     * @param array<string, mixed>|null $vorher      Zeiteintrag vor der Änderung
     * @return bool true, wenn Stunden, Datum oder Kategorie geändert wurden
     */
    private function zieheBuchungNach(
        array &$booking,
        float $stunden,
        string $datum,
        ?array $sk,
        bool $katExplizit,
        array $catalogById,
        ?array $vorher = null
    ): bool {
        $geaendert = false;

        $spiegel = array_key_exists('zeitStunden', $booking)
            ? ['stunden' => $booking['zeitStunden'], 'datum' => $booking['zeitDatum'] ?? '', 'kat' => $booking['zeitStundenKatId'] ?? null]
            : ($vorher !== null
                ? ['stunden' => $vorher['stunden'] ?? 0, 'datum' => $vorher['datum'] ?? '', 'kat' => $vorher['stundenKatId'] ?? null]
                : null);

        if ($spiegel !== null) {
            $handGeaendert = abs((float)($booking['stunden'] ?? 0) - (float)$spiegel['stunden']) >= 0.01
                || (string)($booking['datum'] ?? '') !== (string)$spiegel['datum'];
            if (!$handGeaendert
                && (abs((float)($booking['stunden'] ?? 0) - $stunden) >= 0.01 || (string)($booking['datum'] ?? '') !== $datum)) {
                $booking['stunden'] = $stunden;
                $booking['datum']   = $datum;
                $geaendert = true;
            }
            if ($this->folgeKategorie($booking, $sk, $katExplizit, $catalogById, $spiegel['kat'])) $geaendert = true;
        }

        if (abs((float)($booking['zeitStunden'] ?? -1) - $stunden) >= 0.01) $booking['zeitStunden'] = $stunden;
        if (($booking['zeitDatum'] ?? null) !== $datum) $booking['zeitDatum'] = $datum;
        $this->spiegleKategorie($booking, $sk, $katExplizit);
        return $geaendert;
    }

    /**
     * Übernimmt Kategorie, Stundenpreis und Fixkosten des Zeiteintrags in die Buchung.
     *
     * Nur bei einer ausdrücklich gewählten Kategorie des Eintrags – eine geänderte
     * Standardkategorie des Benutzers preist bestehende Buchungen nicht um. Wurden
     * Kategorie oder Preis im Projekt von Hand geändert, bleibt die Buchung unverändert.
     *
     * @param array<string, mixed>             $booking
     * @param array<string, mixed>|null        $sk
     * @param array<int, array<string, mixed>> $catalogById
     */
    private function folgeKategorie(array &$booking, ?array $sk, bool $katExplizit, array $catalogById, mixed $spiegelKat): bool
    {
        if (!$sk || !$katExplizit) return false;
        $neueKatId  = (int)$sk['id'];
        $buchungKat = self::katId($booking['stundenKatId'] ?? null);
        if ($buchungKat === $neueKatId || $buchungKat !== self::katId($spiegelKat)) return false;
        if ($this->preisHandGeaendert($booking, $catalogById)) return false;

        $booking['stundenKatId']      = $neueKatId;
        $booking['stundenpreis']      = money_from_cents($sk['preis']);
        $booking['fixkosten']         = money_from_cents($sk['fixkosten']);
        $booking['zeitStundenpreis']  = $booking['stundenpreis'];
        $booking['zeitFixkosten']     = $booking['fixkosten'];
        return true;
    }

    /**
     * Wurden Stundenpreis oder Fixkosten der Buchung im Projekt von Hand geändert?
     * Vergleich mit dem zuletzt vom System gesetzten Preis (zeitStundenpreis/zeitFixkosten),
     * bei älteren Buchungen mit dem aktuellen Katalogpreis ihrer Kategorie. Ist das nicht
     * bestimmbar, gilt der Preis als geändert (nichts wird überschrieben).
     *
     * @param array<string, mixed>             $booking
     * @param array<int, array<string, mixed>> $catalogById
     */
    private function preisHandGeaendert(array $booking, array $catalogById): bool
    {
        if (array_key_exists('zeitStundenpreis', $booking)) {
            $preis = (float)$booking['zeitStundenpreis'];
            $fix   = (float)($booking['zeitFixkosten'] ?? 0);
        } else {
            $kat = $catalogById[self::katId($booking['stundenKatId'] ?? null) ?? 0] ?? null;
            if (!$kat) return true;
            $preis = money_from_cents($kat['preis']);
            $fix   = money_from_cents($kat['fixkosten']);
        }
        return abs((float)($booking['stundenpreis'] ?? 0) - $preis) >= 0.005
            || abs((float)($booking['fixkosten'] ?? 0) - $fix) >= 0.005;
    }

    /**
     * Spiegelt die Kategorie des Zeiteintrags. Ohne eigene Kategorie des Eintrags
     * (Standard des Benutzers) bleibt der Spiegel unverändert, damit eine spätere
     * ausdrückliche Wahl korrekt erkannt wird.
     *
     * @param array<string, mixed>      $booking
     * @param array<string, mixed>|null $sk
     */
    private function spiegleKategorie(array &$booking, ?array $sk, bool $katExplizit): void
    {
        if ($katExplizit) {
            $neu = $sk ? (int)$sk['id'] : null;
            if (!array_key_exists('zeitStundenKatId', $booking) || self::katId($booking['zeitStundenKatId']) !== $neu) {
                $booking['zeitStundenKatId'] = $neu;
            }
        } elseif (!array_key_exists('zeitStundenKatId', $booking)) {
            $booking['zeitStundenKatId'] = self::katId($booking['stundenKatId'] ?? null);
        }
    }

    private static function katId(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int)$v;
    }

    /** Hat der Zeiteintrag eine eigene Stundenkategorie (statt des Benutzer-Standards)? */
    private static function hatEigeneKategorie(array $entry): bool
    {
        return isset($entry['stundenKatId']) && $entry['stundenKatId'] !== '' && (int)$entry['stundenKatId'] > 0;
    }

    /**
     * Gleicht die Auto-Import-Buchungen eines Mitarbeiters in den Baustellen mit
     * seiner aktuellen Stundenerfassung ab. Auto-Import-Positionen, für die es keinen
     * passenden Arbeits-Eintrag mehr gibt, werden entfernt. So werden gelöschte oder
     * geänderte Stunden auch im Projekt zurückgenommen.
     *
     * Die Zuordnung erfolgt primär über zeitUser, nicht über das Kürzel: zwei Benutzer
     * mit demselben Kürzel würden sich sonst gegenseitig Buchungen aus den Projekten
     * löschen. Legacy-Positionen ohne zeitUser werden bei mehrdeutigem Kürzel gar nicht
     * mehr angefasst.
     *
     * @param list<int> $zusatzProjektIds weitere Projekte (Vorher-Stand), deren Buchungen
     *                                    ebenfalls abgeglichen werden
     * @return array{removed: int, written: bool} entfernte Auto-Import-Positionen und ob Projektdaten geschrieben wurden
     */
    private function reconcileAutoImport(string $username, string $kuerzel, array $entries, array $zusatzProjektIds = []): array
    {
        $ambiguous = $this->kuerzelIsAmbiguous($kuerzel);

        // Gewünschte Buchungen je Baustelle ableiten:
        //  - exakt: Menge der zeitEntryIds (moderne, verknüpfte Positionen)
        //  - fuzzy: Multiset datum|stunden (Legacy-Positionen ohne Link)
        $desiredIds   = []; // [bId => [entryId => true]]
        $desiredFuzzy = []; // [bId => ['datum|stunden' => count]]
        foreach ($entries as $entry) {
            if (($entry['typ'] ?? '') !== 'arbeit') continue;
            $bId     = (int)($entry['baustelleId'] ?? 0);
            $stunden = (float)($entry['stunden'] ?? 0);
            $datum   = $entry['datum'] ?? '';
            if (!$bId || $stunden <= 0 || !$datum) continue;
            $eid = isset($entry['id']) && $entry['id'] !== null ? (int)$entry['id'] : 0;
            if ($eid > 0) $desiredIds[$bId][$eid] = true;
            $key = $datum . '|' . number_format($stunden, 2, '.', '');
            $desiredFuzzy[$bId][$key] = ($desiredFuzzy[$bId][$key] ?? 0) + 1;
        }

        $removed = 0;
        $geschrieben = false;
        // Alle noch vorhandenen Eintrags-IDs des Benutzers (jeder Typ): Sperren für gelöschte
        // Einträge werden aufgehoben, weil die ID sonst bei einem neuen Eintrag wiederverwendet
        // würde und dieser nie gebucht würde.
        $vorhandeneIds = [];
        foreach ($entries as $entry) {
            $eid = isset($entry['id']) ? (int)$entry['id'] : 0;
            if ($eid > 0) $vorhandeneIds[$eid] = true;
        }
        $sperrPrefix = 'id:' . strtolower($username) . ':';
        // Nur betroffene Baustellen laden (Performance): aktuell referenzierte und die des
        // Vorher-Stands – sonst bliebe nach einem Projektwechsel die alte Buchung stehen.
        $affectedBIds = array_values(array_unique(array_merge(
            array_keys($desiredIds),
            array_keys($desiredFuzzy),
            array_filter($zusatzProjektIds, static fn(int $id): bool => $id > 0)
        )));
        if (empty($affectedBIds)) return ['removed' => 0, 'written' => false];
        $phB = implode(',', array_fill(0, count($affectedBIds), '?'));
        // Archivierte Projekte werden nicht angefasst: dort ist der Buchungsstand
        // eingefroren und darf nicht nachträglich bereinigt werden.
        $rows = Database::fetchAll($this->db, "SELECT id, data FROM baustellen WHERE archiviert = 0 AND id IN ($phB)", $affectedBIds);
        foreach ($rows as $bRow) {
            $bId   = (int)$bRow['id'];
            $bData = json_decode($bRow['data'], true) ?: [];
            $changed = false;

            if (!empty($bData['azImportBlocked']) && is_array($bData['azImportBlocked'])) {
                $sperren = array_values(array_filter(
                    $bData['azImportBlocked'],
                    static fn($k): bool => !is_string($k) || !str_starts_with($k, $sperrPrefix)
                        || !empty($vorhandeneIds[(int)substr($k, strlen($sperrPrefix))])
                ));
                if (count($sperren) !== count($bData['azImportBlocked'])) {
                    $bData['azImportBlocked'] = $sperren;
                    $changed = true;
                }
            }

            $arbeitszeit = (isset($bData['arbeitszeit']) && is_array($bData['arbeitszeit'])) ? $bData['arbeitszeit'] : [];
            $ids     = $desiredIds[$bId] ?? [];
            $fuzzy   = $desiredFuzzy[$bId] ?? [];
            $kept    = [];
            foreach ($arbeitszeit as $a) {
                $isAuto = is_array($a) && ($a['autoImport'] ?? false);
                $zu     = $isAuto ? (string)($a['zeitUser'] ?? '') : '';
                if ($zu !== '') {
                    // Verknüpfte Position: gehört eindeutig einem Benutzer, unabhängig vom Kürzel.
                    $isAutoByUser = strcasecmp($zu, $username) === 0;
                } else {
                    // Legacy ohne Link: nur bei eindeutigem Kürzel zuordenbar.
                    $isAutoByUser = $isAuto && ($a['erstelltVon'] ?? '') === $kuerzel && !$ambiguous;
                }
                if (!$isAutoByUser) { $kept[] = $a; continue; }

                $eid = isset($a['zeitEntryId']) ? (int)$a['zeitEntryId'] : 0;
                if ($eid > 0) {
                    // Exakte Verknüpfung: behalten, solange der Zeiteintrag existiert.
                    if (!empty($ids[$eid])) { $kept[] = $a; }
                    else { $changed = true; $removed++; }
                } else {
                    // Legacy ohne Link: Fuzzy-Multiset-Abgleich.
                    $key = ($a['datum'] ?? '') . '|' . number_format((float)($a['stunden'] ?? 0), 2, '.', '');
                    if (($fuzzy[$key] ?? 0) > 0) { $fuzzy[$key]--; $kept[] = $a; }
                    else { $changed = true; $removed++; }
                }
            }
            if ($changed) {
                if ($arbeitszeit) $bData['arbeitszeit'] = array_values($kept);
                $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")
                         ->execute([json_encode($bData, JSON_UNESCAPED_UNICODE), $bId]);
                $geschrieben = true;
            }
        }
        return ['removed' => $removed, 'written' => $geschrieben];
    }

    /** Prüft, ob ein Kürzel an mehr als einen Benutzer vergeben ist (Altbestand). */
    private function kuerzelIsAmbiguous(string $kuerzel): bool
    {
        if ($kuerzel === '') return true;
        $row = Database::fetchOne($this->db, "SELECT COUNT(*) AS c FROM users WHERE kuerzel = ?", [$kuerzel]);
        return (int)($row['c'] ?? 0) > 1;
    }

    /**
     * reconcile_bookings – Querprüfung Zeiterfassung ↔ Projektbuchung (Admin/Master).
     *
     * Findet Fehlbuchungen in beide Richtungen:
     *  - orphans: Arbeits-Zeiteinträge mit Baustelle, für die KEINE Projektbuchung
     *    (arbeitszeit-Position) existiert.
     *  - ghosts:  verknüpfte Projektbuchungen (autoImport + zeitEntryId), zu denen
     *    KEIN Zeiterfassungs-Eintrag mehr existiert.
     */
    public function reconcileBookings(): void
    {
        Auth::requireRole('admin', 'master');

        $settings   = Auth::loadSettings($this->db);
        $autoImport = !empty($settings['auto_import_stunden']);

        // username -> kuerzel
        $kuerzelByUser = [];
        foreach (Database::fetchAll($this->db, "SELECT username, kuerzel FROM users") as $u) {
            $kuerzelByUser[$u['username']] = (string)($u['kuerzel'] ?? '');
        }

        $projekte = BuchungValidator::loadProjekte($this->db);

        // Verknüpfte Projektbuchungen für die Ghost-Prüfung sammeln.
        $linkedBookings = [];
        foreach ($projekte as $bId => $p) {
            foreach ($p['az'] as $a) {
                if (!is_array($a) || !($a['autoImport'] ?? false)) continue;
                $eid = isset($a['zeitEntryId']) ? (int)$a['zeitEntryId'] : 0;
                if ($eid <= 0) continue;
                $linkedBookings[] = [
                    'baustelleId' => $bId,
                    'projektName' => $p['name'],
                    'archiviert'  => $p['archiviert'],
                    'azId'        => $a['id'] ?? null,
                    'datum'       => (string)($a['datum'] ?? ''),
                    'stunden'     => (float)($a['stunden'] ?? 0),
                    'erstelltVon' => (string)($a['erstelltVon'] ?? ''),
                    'zeitEntryId' => $eid,
                    'zeitUser'    => (string)($a['zeitUser'] ?? ''),
                ];
            }
        }

        // Zeiterfassung: relevante Arbeits-Einträge
        $zrows = Database::fetchAll(
            $this->db,
            "SELECT username, entryId, clientUuid, datum, baustelleId, stunden, bemerkung, baustelleName
               FROM zeiterfassung
              WHERE typ = 'arbeit' AND baustelleId IS NOT NULL AND stunden > 0"
        );

        $zeitIndex = []; // "bId|eid|userLower" => true (für Ghost-Prüfung)
        $orphans   = [];
        $abweichungen = [];
        $hinweise  = [];
        foreach ($zrows as $r) {
            $bId     = (int)$r['baustelleId'];
            $user    = (string)$r['username'];
            $kuerzel = $kuerzelByUser[$user] ?? '';
            $eid     = $r['entryId'] !== null ? (int)$r['entryId'] : 0;
            if ($eid > 0) $zeitIndex[$bId . '|' . $eid . '|' . strtolower($user)] = true;

            $entry = [
                'typ'         => 'arbeit',
                'id'          => $eid ?: null,
                'datum'       => (string)$r['datum'],
                'baustelleId' => $bId,
                'stunden'     => (float)$r['stunden'],
            ];
            [$status, $code, $msg] = BuchungValidator::classify($entry, [
                'autoImportAktiv' => $autoImport,
                'projekte'        => $projekte,
                'username'        => $user,
                'kuerzel'         => $kuerzel,
            ]);
            if ($code === '') continue;

            $befund = BuchungValidator::befund($code, [
                'username'    => $user,
                'kuerzel'     => $kuerzel,
                'datum'       => (string)$r['datum'],
                'baustelleId' => $bId,
                'projektName' => $projekte[$bId]['name'] ?? (($r['baustelleName'] ?? '') ?: ('#' . $bId)),
                'archiviert'  => $projekte[$bId]['archiviert'] ?? false,
                'stunden'     => (float)$r['stunden'],
                'entryId'     => $eid,
                'clientUuid'  => (string)($r['clientUuid'] ?? ''),
                'bemerkung'   => (string)($r['bemerkung'] ?? ''),
                'status'      => $status,
            ]);

            // Nur ZE006 (fehlt) und ZE013 (weicht ab) sind behebbar; ZE005 ist reine Information.
            if ($code === 'ZE006')     $orphans[] = $befund;
            elseif ($code === 'ZE013') $abweichungen[] = $befund;
            else                       $hinweise[] = $befund;
        }

        // Ghosts: verknüpfte Buchungen ohne passende Zeiterfassung
        $ghosts = [];
        foreach ($linkedBookings as $bk) {
            $key = $bk['baustelleId'] . '|' . $bk['zeitEntryId'] . '|' . strtolower($bk['zeitUser']);
            if (empty($zeitIndex[$key])) {
                $ghosts[] = BuchungValidator::befund('ZE007', $bk);
            }
        }

        jsonOut([
            'ok'       => true,
            'orphans'  => $orphans,
            'ghosts'   => $ghosts,
            'abweichungen' => $abweichungen,
            'hinweise' => $hinweise,
            'katalog'  => BuchungValidator::KATALOG,
            'global'   => ['autoImportAktiv' => $autoImport],
            'counts'   => [
                'orphans'  => count($orphans),
                'ghosts'   => count($ghosts),
                'abweichungen' => count($abweichungen),
                'hinweise' => count($hinweise),
            ],
        ]);
    }

    /**
     * diagnose_fehlbuchungen – read-only Ursachenanalyse (Admin/Master).
     *
     * Beantwortet die Frage "warum gilt diese Buchung als Fehlbuchung?" für jeden
     * Eintrag und aggregiert die Befunde je Benutzer. Verändert nichts.
     * Optional: ?username=… schränkt auf einen Benutzer ein.
     */
    public function diagnoseFehlbuchungen(): void
    {
        Auth::requireRole('admin', 'master');
        $filterUser = trim((string)($_GET['username'] ?? ''));

        $settings    = Auth::loadSettings($this->db);
        $autoImport  = !empty($settings['auto_import_stunden']);
        $erweitert   = !empty($settings['erweiterte_zeiterfassung']);

        // Projekte inkl. archivierter Zeilen indizieren.
        $projekte = [];
        foreach (Database::fetchAll($this->db, "SELECT id, name, data, archiviert FROM baustellen") as $b) {
            $bId  = (int)$b['id'];
            $data = json_decode($b['data'], true) ?: [];
            $projekte[$bId] = [
                'name'       => (string)$b['name'],
                'archiviert' => (int)($b['archiviert'] ?? 0) === 1,
                'blocked'    => (isset($data['azImportBlocked']) && is_array($data['azImportBlocked'])) ? $data['azImportBlocked'] : [],
                'az'         => (isset($data['arbeitszeit']) && is_array($data['arbeitszeit'])) ? $data['arbeitszeit'] : [],
            ];
        }

        // Kürzel-Kollisionen: zwei Benutzer mit demselben Kürzel löschen sich
        // gegenseitig Projektbuchungen (erstelltVon ist dann nicht mehr eindeutig).
        $kuerzelMap = [];
        foreach (Database::fetchAll($this->db, "SELECT username, kuerzel FROM users WHERE kuerzel <> ''") as $ku) {
            $kuerzelMap[(string)$ku['kuerzel']][] = (string)$ku['username'];
        }
        $kollisionen = [];
        foreach ($kuerzelMap as $kz => $names) {
            if (count($names) > 1) $kollisionen[] = ['kuerzel' => $kz, 'benutzer' => $names];
        }

        $userRows = $filterUser !== ''
            ? Database::fetchAll($this->db, "SELECT username, kuerzel FROM users WHERE username = ?", [$filterUser])
            : Database::fetchAll($this->db, "SELECT username, kuerzel FROM users ORDER BY LOWER(username)");

        $result = [];
        foreach ($userRows as $u) {
            $username = (string)$u['username'];
            $kuerzel  = (string)($u['kuerzel'] ?? '');

            $rows = Database::fetchAll(
                $this->db,
                "SELECT entryId, datum, baustelleId, stunden, bemerkung, clientUuid, status, errorCode
                   FROM zeiterfassung
                  WHERE username = ? AND typ = 'arbeit' AND baustelleId IS NOT NULL AND stunden > 0
                  ORDER BY datum",
                [$username]
            );
            if (!$rows) continue;

            $counts = [
                'gesamt'            => count($rows),
                'buchungVorhanden'  => 0,
                'buchungFehlt'      => 0,
                'projektGeloescht'  => 0,
                'projektArchiviert' => 0,
                'gesperrt'          => 0,
                'nurFuzzyTreffer'   => 0,
                'ohneLink'          => 0,
                'abweichend'        => 0,
            ];
            $beispiele = [];

            foreach ($rows as $r) {
                $bId     = (int)$r['baustelleId'];
                $eid     = $r['entryId'] !== null ? (int)$r['entryId'] : 0;
                $stunden = (float)$r['stunden'];
                $datum   = (string)$r['datum'];
                $befund  = '';

                if (!isset($projekte[$bId])) {
                    $counts['projektGeloescht']++;
                    $befund = 'Projekt #' . $bId . ' existiert nicht mehr (hart gelöscht/archiviert)';
                } else {
                    $p = $projekte[$bId];
                    if ($p['archiviert']) $counts['projektArchiviert']++;

                    $idKey = 'id:' . strtolower($username) . ':' . $eid;
                    $fzKey = 'fz:' . $kuerzel . ':' . $datum . ':' . (round($stunden, 2) + 0);
                    $isBlocked = ($eid > 0 && in_array($idKey, $p['blocked'], true)) || in_array($fzKey, $p['blocked'], true);

                    $exact = BuchungValidator::findLinkedBooking($p['az'], $eid, $username);
                    $fuzzy = false;
                    foreach ($p['az'] as $a) {
                        if ($exact !== null) break;
                        if (!($a['autoImport'] ?? false)) continue;
                        $aEid = isset($a['zeitEntryId']) ? (int)$a['zeitEntryId'] : 0;
                        if ($aEid === 0 && ($a['datum'] ?? '') === $datum
                            && abs((float)($a['stunden'] ?? 0) - $stunden) < 0.01) {
                            $fuzzy = true;
                            if (($a['erstelltVon'] ?? '') !== $kuerzel) $counts['nurFuzzyTreffer']++;
                        }
                    }

                    if ($exact !== null) {
                        $counts['buchungVorhanden']++;
                        if (!$p['archiviert'] && BuchungValidator::weichtAb($exact, $stunden, $datum)) {
                            $counts['abweichend']++;
                            $befund = 'Projektbuchung weicht ab (' . (float)($exact['stunden'] ?? 0) . ' h am '
                                    . (string)($exact['datum'] ?? '') . ') – ZE013';
                        }
                    }
                    elseif ($isBlocked)  { $counts['gesperrt']++;  $befund = 'Projektbuchung wurde archiviert/gelöscht (Sperrliste) – korrekt, keine Fehlbuchung'; }
                    elseif ($fuzzy)      { $counts['buchungVorhanden']++; $counts['ohneLink']++; }
                    else {
                        $counts['buchungFehlt']++;
                        $befund = $autoImport
                            ? 'Keine Projektbuchung gefunden (Auto-Import ist AKTIV)'
                            : 'Keine Projektbuchung vorhanden, weil Auto-Import DEAKTIVIERT ist -> RC-1, keine echte Fehlbuchung';
                    }
                }

                if ($befund !== '' && count($beispiele) < 20) {
                    $beispiele[] = [
                        'datum' => $datum, 'entryId' => $eid, 'baustelleId' => $bId,
                        'stunden' => $stunden, 'befund' => $befund,
                    ];
                }
            }

            // entryId-Duplikate (RC-5): brechen die zeitEntryId-Verknüpfung.
            $dups = Database::fetchAll(
                $this->db,
                "SELECT entryId, COUNT(*) AS c FROM zeiterfassung
                  WHERE username = ? AND entryId IS NOT NULL
                  GROUP BY entryId HAVING COUNT(*) > 1",
                [$username]
            );

            // Gegenprobe aus Projektsicht: Existieren die Projektpositionen des
            // Mitarbeiters überhaupt? Unterscheidet "Buchung fehlt wirklich"
            // (→ Reparatur) von "Buchung da, nur Verknüpfung kaputt" (→ Reparatur
            // würde Dubletten anlegen).
            $eigeneEntryIds = [];
            foreach (Database::fetchAll($this->db, "SELECT entryId FROM zeiterfassung WHERE username = ? AND entryId IS NOT NULL", [$username]) as $r) {
                $eigeneEntryIds[(int)$r['entryId']] = true;
            }
            $pos = ['mitZeitUser' => 0, 'mitKuerzel' => 0, 'linkVerwaist' => 0, 'fremderZeitUser' => 0, 'ohneLink' => 0];
            foreach ($projekte as $p) {
                foreach ($p['az'] as $a) {
                    if (!is_array($a) || !($a['autoImport'] ?? false)) continue;
                    $zu   = (string)($a['zeitUser'] ?? '');
                    $aEid = isset($a['zeitEntryId']) ? (int)$a['zeitEntryId'] : 0;
                    $mine = $zu !== '' && strcasecmp($zu, $username) === 0;
                    $byKz = $kuerzel !== '' && ($a['erstelltVon'] ?? '') === $kuerzel;
                    if (!$mine && !$byKz) continue;

                    if ($mine)  $pos['mitZeitUser']++;
                    if ($byKz)  $pos['mitKuerzel']++;
                    if ($byKz && $zu !== '' && !$mine) $pos['fremderZeitUser']++;
                    if ($aEid === 0) $pos['ohneLink']++;
                    elseif ($mine && empty($eigeneEntryIds[$aEid])) $pos['linkVerwaist']++;
                }
            }

            $verdacht = [];
            if (!$autoImport && $counts['buchungFehlt'] > 0) {
                $verdacht[] = 'RC-1: Auto-Import ist deaktiviert – ' . $counts['buchungFehlt']
                            . ' Einträge werden fälschlich als Fehlbuchung gemeldet.';
            }
            if ($kuerzel === '') {
                $verdacht[] = 'RC-2: Benutzer hat KEIN Kürzel – der Legacy-Abgleich kann nicht greifen.';
            } elseif (count($kuerzelMap[$kuerzel] ?? []) > 1) {
                $verdacht[] = 'RC-2: Kürzel "' . $kuerzel . '" ist mehrfach vergeben ('
                            . implode(', ', $kuerzelMap[$kuerzel]) . ') – diese Benutzer löschen sich '
                            . 'gegenseitig Projektbuchungen.';
            } elseif ($counts['nurFuzzyTreffer'] > 0) {
                $verdacht[] = 'RC-2: ' . $counts['nurFuzzyTreffer'] . ' Positionen tragen ein abweichendes '
                            . 'Kürzel (erstelltVon != "' . $kuerzel . '") – vermutlich Kürzel-Wechsel.';
            }
            if ($counts['projektGeloescht'] > 0) {
                $verdacht[] = 'RC-3: ' . $counts['projektGeloescht'] . ' Einträge zeigen auf ein nicht mehr '
                            . 'existierendes Projekt (Archivierung löschte die Zeile hart).';
            }
            if ($counts['ohneLink'] > 0) {
                $verdacht[] = 'Hinweis: ' . $counts['ohneLink'] . ' Positionen haben keine zeitEntryId '
                            . '(Legacy) und hängen am unsicheren Fuzzy-Abgleich.';
            }
            if ($dups) {
                $verdacht[] = 'RC-5: ' . count($dups) . ' doppelte entryId(s) – Verknüpfungen können auf den '
                            . 'falschen Eintrag zeigen.';
            }

            // Handlungsempfehlung für fehlende Buchungen
            if ($counts['buchungFehlt'] > 0) {
                if ($pos['mitZeitUser'] === 0 && $pos['mitKuerzel'] === 0) {
                    $verdacht[] = 'EMPFEHLUNG: Es existiert KEINE einzige Projektposition dieses Mitarbeiters. '
                                . 'Die Buchungen fehlen tatsächlich – "Alle reparieren" ist sicher.';
                } elseif ($pos['linkVerwaist'] > 0 || $pos['ohneLink'] > 0) {
                    $verdacht[] = 'ACHTUNG: Es liegen ' . ($pos['mitZeitUser'] + $pos['mitKuerzel'])
                                . ' Projektpositionen vor, davon ' . $pos['linkVerwaist'] . ' mit totem Link und '
                                . $pos['ohneLink'] . ' ohne Link. Reparieren würde DUBLETTEN anlegen – '
                                . 'zuerst die Verknüpfung prüfen.';
                }
            }

            $result[] = [
                'username'         => $username,
                'kuerzel'          => $kuerzel,
                'counts'           => $counts,
                'projektPositionen'=> $pos,
                'entryIdDuplikate' => array_map(fn($d) => ['entryId' => (int)$d['entryId'], 'anzahl' => (int)$d['c']], $dups),
                'verdacht'         => $verdacht,
                'beispiele'        => $beispiele,
            ];
        }

        jsonOut([
            'ok'     => true,
            'global' => [
                'autoImportAktiv'         => $autoImport,
                'erweiterteZeiterfassung' => $erweitert,
                'projekteAktiv'           => count(array_filter($projekte, fn($p) => !$p['archiviert'])),
                'projekteArchiviert'      => count(array_filter($projekte, fn($p) => $p['archiviert'])),
                'kuerzelKollisionen'      => $kollisionen,
            ],
            'users'  => $result,
        ]);
    }

    /**
     * repair_booking – behebt eine Fehlbuchung (Admin/Master). Idempotent.
     *
     * aktion (Standard "nachtragen"):
     *  - nachtragen:  fehlende Projektbuchung anlegen (ZE006)
     *  - uebernehmen: abweichende Projektbuchung auf den Zeiteintrag setzen (ZE013)
     *  - bestaetigen: Abweichung bewusst stehen lassen (ZE013 ausblenden)
     * Alle Aktionen schreiben nur ins Projekt, nie in die Zeiterfassung.
     */
    public function repairBooking(): void
    {
        Auth::requireRole('admin', 'master');
        $username = trim((string)($this->body['username'] ?? ''));
        $entryId  = isset($this->body['entryId']) ? (int)$this->body['entryId'] : 0;
        $aktion   = (string)($this->body['aktion'] ?? 'nachtragen');
        if ($username === '' || $entryId <= 0) {
            jsonOut(['error' => 'username und entryId sind erforderlich.'], 400);
        }
        if (!in_array($aktion, ['nachtragen', 'uebernehmen', 'bestaetigen'], true)) {
            jsonOut(['error' => 'Unbekannte Aktion.'], 400);
        }

        $row = Database::fetchOne(
            $this->db,
            "SELECT datum, baustelleId, stunden, bemerkung, stundenKatId FROM zeiterfassung
              WHERE username = ? AND entryId = ? AND typ = 'arbeit'",
            [$username, $entryId]
        );
        if (!$row) jsonOut(['error' => 'Zeiterfassungs-Eintrag nicht gefunden.'], 404);
        $bId = (int)($row['baustelleId'] ?? 0);
        if ($bId <= 0 || (float)$row['stunden'] <= 0) {
            jsonOut(['error' => 'Eintrag hat keine gültige Baustelle oder Stunden.'], 400);
        }

        $u = Database::fetchOne($this->db, "SELECT kuerzel, stundenKategorie FROM users WHERE username = ?", [$username]);
        $entry = [
            'id'          => $entryId,
            'datum'       => $row['datum'],
            'stunden'     => (float)$row['stunden'],
            'bemerkung'   => $row['bemerkung'],
            'baustelleId' => $bId,
            'stundenKatId'=> $row['stundenKatId'] !== null ? (int)$row['stundenKatId'] : null,
        ];
        $kategorie = (string)($u['stundenKategorie'] ?? '');

        $added = false;
        $changed = false;
        $this->db->beginTransaction();
        try {
            if ($aktion === 'nachtragen') {
                $added = $this->ensureProjectBooking($bId, $entry, $username, (string)($u['kuerzel'] ?? ''), $kategorie, $changed);
            } else {
                $changed = $this->behandleAbweichung($bId, $entry, $username, $kategorie, $aktion);
            }
            if ($changed) \App\DataService::bumpRev($this->db, (string)($_SESSION['username'] ?? ''));
            $this->aktualisiereBuchungsStatus($username);
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] repair_booking fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Reparatur fehlgeschlagen.'], 500);
        }

        if ($aktion !== 'nachtragen') {
            if (!$changed) jsonOut(['error' => 'Keine verknüpfte Projektbuchung gefunden.'], 404);
            jsonOut(['ok' => true, 'repaired' => 1]);
        }
        jsonOut(['ok' => true, 'repaired' => $added ? 1 : 0, 'alreadyPresent' => !$added]);
    }

    /**
     * Setzt eine abweichende, verknüpfte Projektbuchung auf den Zeiteintrag
     * ("uebernehmen") oder vermerkt die Abweichung als bestätigt ("bestaetigen").
     *
     * @return bool true, wenn die Buchung gefunden und gespeichert wurde
     */
    private function behandleAbweichung(int $bId, array $entry, string $username, string $kategorie, string $aktion): bool
    {
        $row = Database::fetchOne($this->db, "SELECT data FROM baustellen WHERE id = ? AND archiviert = 0", [$bId]);
        if (!$row) return false;
        $data = json_decode((string)$row['data'], true) ?: [];
        $eid  = (int)$entry['id'];

        foreach ($data['arbeitszeit'] ?? [] as $idx => $a) {
            if (BuchungValidator::findLinkedBooking([$a], $eid, $username) === null) continue;

            $booking = &$data['arbeitszeit'][$idx];
            if ($aktion === 'uebernehmen') {
                // Stunden und Datum folgen der Zeiterfassung; Kategorie und Preis nur nach
                // denselben Regeln wie beim Speichern (ein Handpreis bleibt erhalten).
                [$catalogById, $catalogByName] = $this->loadStundenKatalog();
                $sk = $this->katalogEintrag($entry, $kategorie, $catalogById, $catalogByName);
                $booking['stunden'] = (float)$entry['stunden'];
                $booking['datum']   = (string)$entry['datum'];
                $this->folgeKategorie($booking, $sk, self::hatEigeneKategorie($entry), $catalogById, $booking['zeitStundenKatId'] ?? ($booking['stundenKatId'] ?? null));
                $booking['zeitStunden'] = (float)$entry['stunden'];
                $booking['zeitDatum']   = (string)$entry['datum'];
                $this->spiegleKategorie($booking, $sk, self::hatEigeneKategorie($entry));
                unset($booking['abweichungBestaetigt']);
            } else {
                $booking['abweichungBestaetigt'] = [
                    'stunden'     => (float)($booking['stunden'] ?? 0),
                    'datum'       => (string)($booking['datum'] ?? ''),
                    'zeitStunden' => (float)$entry['stunden'],
                    'zeitDatum'   => (string)$entry['datum'],
                ];
            }
            unset($booking);

            $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")
                ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $bId]);
            return true;
        }
        return false;
    }

    /**
     * repair_bookings_bulk – repariert mehrere verwaiste Buchungen in einer
     * Transaktion (Admin/Master). Body: items = [{username, entryId}].
     */
    public function repairBookingsBulk(): void
    {
        Auth::requireRole('admin', 'master');
        $items = $this->body['items'] ?? null;
        if (!is_array($items)) jsonOut(['error' => 'items-Array ist erforderlich.'], 400);

        $repaired = 0; $skipped = 0; $failed = 0;
        $betroffen = [];
        $anyWritten = false;
        $this->db->beginTransaction();
        try {
            foreach ($items as $it) {
                $username = trim((string)($it['username'] ?? ''));
                $entryId  = isset($it['entryId']) ? (int)$it['entryId'] : 0;
                if ($username === '' || $entryId <= 0) { $failed++; continue; }

                $row = Database::fetchOne(
                    $this->db,
                    "SELECT datum, baustelleId, stunden, bemerkung, stundenKatId FROM zeiterfassung
                      WHERE username = ? AND entryId = ? AND typ = 'arbeit'",
                    [$username, $entryId]
                );
                if (!$row) { $failed++; continue; }
                $bId = (int)($row['baustelleId'] ?? 0);
                if ($bId <= 0 || (float)$row['stunden'] <= 0) { $failed++; continue; }

                $u = Database::fetchOne($this->db, "SELECT kuerzel, stundenKategorie FROM users WHERE username = ?", [$username]);
                $entry = [
                    'id'          => $entryId,
                    'datum'       => $row['datum'],
                    'stunden'     => (float)$row['stunden'],
                    'bemerkung'   => $row['bemerkung'],
                    'baustelleId' => $bId,
                    'stundenKatId'=> $row['stundenKatId'] !== null ? (int)$row['stundenKatId'] : null,
                ];
                $geschrieben = false;
                if ($this->ensureProjectBooking($bId, $entry, $username, (string)($u['kuerzel'] ?? ''), (string)($u['stundenKategorie'] ?? ''), $geschrieben)) {
                    $repaired++;
                } else {
                    $skipped++;
                }
                $anyWritten = $anyWritten || $geschrieben;
                $betroffen[$username] = true;
            }
            if ($anyWritten) \App\DataService::bumpRev($this->db, (string)($_SESSION['username'] ?? ''));
            foreach (array_keys($betroffen) as $username) {
                $this->aktualisiereBuchungsStatus((string)$username);
            }
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] repair_bookings_bulk fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Bulk-Reparatur fehlgeschlagen.'], 500);
        }

        jsonOut(['ok' => true, 'repaired' => $repaired, 'skipped' => $skipped, 'failed' => $failed]);
    }

    /** Erzeugt eine RFC-4122-v4-UUID für Einträge ohne clientseitige Identität. */
    private static function newUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Herkunft des Requests für die Nachvollziehbarkeit (desktop|mobile|light|admin). */
    private function requestQuelle(): string
    {
        $q = strtolower(trim((string)($this->body['quelle'] ?? '')));
        return in_array($q, ['desktop', 'mobile', 'light', 'admin'], true) ? $q : '';
    }

    /** Aktuelle Zeiterfassungs-Revision eines Benutzers (Optimistic Locking). */
    private function currentZeitRev(string $username): int
    {
        $row = Database::fetchOne($this->db, "SELECT rev FROM zeiterfassung_meta WHERE username = ?", [$username]);
        return (int)($row['rev'] ?? 0);
    }

    /**
     * Erhöht die Zeiterfassungs-Revision eines Benutzers und liefert den neuen Wert.
     * Öffentlich, weil auch das Verschieben einer Projektbuchung (move_position) die
     * Zeiterfassung ändert und andere Geräte das erkennen müssen.
     */
    public static function bumpZeitRev(\PDO $db, string $username): int
    {
        $db->prepare("
            INSERT INTO zeiterfassung_meta (username, rev, updatedAt, updatedBy)
            VALUES (?, 1, ?, ?)
            ON CONFLICT(username) DO UPDATE SET
                rev       = zeiterfassung_meta.rev + 1,
                updatedAt = excluded.updatedAt,
                updatedBy = excluded.updatedBy
        ")->execute([$username, date('Y-m-d H:i:s'), (string)($_SESSION['username'] ?? '')]);
        $row = Database::fetchOne($db, "SELECT rev FROM zeiterfassung_meta WHERE username = ?", [$username]);
        return (int)($row['rev'] ?? 0);
    }

    /**
     * Setzt status/errorCode/errorMessage der gerade geschriebenen Einträge über den
     * zentralen Fehlbuchungs-Katalog. Wird NACH dem Auto-Import aufgerufen.
     *
     * @param array $written Liste mit clientUuid, id, typ, datum, baustelleId, stunden
     */
    private function applyBuchungsStatus(string $username, string $kuerzel, array $written, bool $autoImportAktiv): void
    {
        $bIds = [];
        foreach ($written as $w) {
            if (($w['typ'] ?? '') === 'arbeit' && !empty($w['baustelleId'])) $bIds[] = (int)$w['baustelleId'];
        }
        if (!$bIds) return;

        $ctx = [
            'autoImportAktiv' => $autoImportAktiv,
            'projekte'        => BuchungValidator::loadProjekte($this->db, $bIds),
            'username'        => $username,
            'kuerzel'         => $kuerzel,
        ];

        $upd = $this->db->prepare(
            "UPDATE zeiterfassung SET status = ?, errorCode = ?, errorMessage = ?
              WHERE username = ? AND clientUuid = ?"
        );
        foreach ($written as $w) {
            [$status, $code, $msg] = BuchungValidator::classify($w, $ctx);
            if ($status === BuchungValidator::STATUS_VALID && $code === '') continue;
            $upd->execute([$status, $code, $msg, $username, $w['clientUuid']]);
        }
    }

    /**
     * Schreibt Projektumbuchungen in den Audit-Trail (Anforderung: lückenlose
     * Historie mit altem/neuem Projektbezug, Zeitstempel und Bearbeiter).
     */
    private function writeMoveLogs(string $targetUser, array $moves, string $bearbeiter, string $now): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO zeiterfassung_log (username, entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm)
             VALUES (?,?,'move',?,?,?,?)"
        );
        foreach ($moves as $m) {
            $stmt->execute([
                $targetUser,
                $m['entryId'] ?? null,
                json_encode($m['alt'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($m['neu'] ?? [], JSON_UNESCAPED_UNICODE),
                $bearbeiter,
                $now,
            ]);
        }
    }

    /**
     * get_zeiterfassung_historie – Audit-Trail eines Benutzers bzw. eines Eintrags.
     * Eigene Historie ist immer einsehbar; fremde nur mit Auswertungs-Recht.
     * Optional: ?entryId=… schränkt auf einen Eintrag ein.
     */
    public function getHistorie(): void
    {
        Auth::requireAuth();
        $target = trim((string)($_GET['username'] ?? ($_SESSION['username'] ?? '')));
        if (strcasecmp($target, (string)($_SESSION['username'] ?? '')) !== 0
            && !Auth::canDo($this->db, 'canSeeStundenauswertung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }

        $entryId = isset($_GET['entryId']) ? (int)$_GET['entryId'] : 0;
        $sql = "SELECT entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm
                  FROM zeiterfassung_log WHERE username = ?";
        $par = [$target];
        if ($entryId > 0) { $sql .= " AND entryId = ?"; $par[] = $entryId; }
        $sql .= " ORDER BY geaendertAm DESC, id DESC LIMIT 500";

        $eintraege = [];
        foreach (Database::fetchAll($this->db, $sql, $par) as $r) {
            $alt = json_decode((string)($r['alteWerte'] ?? ''), true);
            $neu = json_decode((string)($r['neueWerte'] ?? ''), true);
            $eintraege[] = [
                'entryId'      => $r['entryId'] !== null ? (int)$r['entryId'] : null,
                'aktion'       => (string)$r['aktion'],
                'alt'          => is_array($alt) ? $alt : null,
                'neu'          => is_array($neu) ? $neu : null,
                'geaendertVon' => (string)$r['geaendertVon'],
                'geaendertAm'  => (string)$r['geaendertAm'],
            ];
        }

        jsonOut(['ok' => true, 'username' => $target, 'historie' => $eintraege]);
    }

    /** load_zeiterfassung */
    public function load(): void
    {
        Auth::requireAuth();
        $rows = Database::fetchAll($this->db, "SELECT entryId, datum, typ, baustelleId, stunden, bemerkung, von, bis, pause, stundenKatId, clientUuid, status, errorCode, errorMessage, baustelleName FROM zeiterfassung WHERE username = ? ORDER BY datum, pk", [$_SESSION['username']]);
        $entries = array_map(fn($r) => [
            'id'          => $r['entryId'] !== null ? (int)$r['entryId'] : null,
            'datum'       => $r['datum'],
            'typ'         => $r['typ'],
            'baustelleId' => $r['baustelleId'] !== null ? (int)$r['baustelleId'] : null,
            'stunden'     => (float)$r['stunden'],
            'bemerkung'   => $r['bemerkung'],
            'von'         => $r['von'] ?? '',
            'bis'         => $r['bis'] ?? '',
            'pause'       => (float)($r['pause'] ?? 0),
            'stundenKatId'=> $r['stundenKatId'] !== null ? (int)$r['stundenKatId'] : null,
            'clientUuid'  => (string)($r['clientUuid'] ?? ''),
            'status'      => (string)($r['status'] ?? BuchungValidator::STATUS_VALID),
            'errorCode'   => (string)($r['errorCode'] ?? ''),
            'errorMessage'=> (string)($r['errorMessage'] ?? ''),
            'baustelleName'=> (string)($r['baustelleName'] ?? ''),
        ], $rows);
        jsonOut(['ok' => true, 'entries' => $entries, 'zeitRev' => $this->currentZeitRev($_SESSION['username'])]);
    }

    /** load_all_zeiterfassung */
    public function loadAll(): void
    {
        Auth::requireAuth();
        $rows = $this->db->query("SELECT username, entryId, datum, typ, baustelleId, stunden, bemerkung, von, bis, pause, stundenKatId, clientUuid, status, errorCode, errorMessage, baustelleName FROM zeiterfassung ORDER BY username, datum, pk")->fetchAll();
        $data = [];
        foreach ($rows as $r) {
            $u = $r['username'];
            if (!isset($data[$u])) $data[$u] = ['entries' => [], 'nextId' => 1];
            $entry = [
                'id'          => $r['entryId'] !== null ? (int)$r['entryId'] : null,
                'datum'       => $r['datum'],
                'typ'         => $r['typ'],
                'baustelleId' => $r['baustelleId'] !== null ? (int)$r['baustelleId'] : null,
                'stunden'     => (float)$r['stunden'],
                'bemerkung'   => $r['bemerkung'],
                'von'         => $r['von'] ?? '',
                'bis'         => $r['bis'] ?? '',
                'pause'       => (float)($r['pause'] ?? 0),
                'stundenKatId'=> $r['stundenKatId'] !== null ? (int)$r['stundenKatId'] : null,
                'clientUuid'  => (string)($r['clientUuid'] ?? ''),
                'status'      => (string)($r['status'] ?? BuchungValidator::STATUS_VALID),
                'errorCode'   => (string)($r['errorCode'] ?? ''),
                'errorMessage'=> (string)($r['errorMessage'] ?? ''),
                'baustelleName'=> (string)($r['baustelleName'] ?? ''),
            ];
            $data[$u]['entries'][] = $entry;
            if ($entry['id'] !== null && $entry['id'] >= $data[$u]['nextId']) {
                $data[$u]['nextId'] = $entry['id'] + 1;
            }
        }
        jsonOut(['ok' => true, 'data' => $data]);
    }

    /** set_sollstunden */
    public function setSollstunden(): void
    {
        Auth::requireRole('admin', 'master');
        $target      = trim($this->body['username'] ?? '');
        $sollstunden = (float)($this->body['sollstunden'] ?? 0);
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $cnt = $this->db->prepare("UPDATE users SET sollstunden = ? WHERE username = ?");
        $cnt->execute([$sollstunden, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    /** get_sollstunden */
    public function getSollstunden(): void
    {
        Auth::requireRole('admin', 'master');
        $rows = $this->db->query("SELECT username, role, sollstunden FROM users ORDER BY LOWER(username)")->fetchAll();
        $result = array_map(fn($r) => [
            'username'     => $r['username'],
            'role'         => $r['role'],
            'sollstunden'  => (float)$r['sollstunden'],
        ], $rows);
        jsonOut(['ok' => true, 'users' => $result]);
    }

    /** set_sollstunden_tag */
    public function setSollstundenTag(): void
    {
        Auth::requireRole('admin', 'master');
        $target = trim($this->body['username'] ?? '');
        $fields = [];
        $params = [];
        $weekdayFields = [
            'sollstundenMo', 'sollstundenDi', 'sollstundenMi', 'sollstundenDo',
            'sollstundenFr', 'sollstundenSa', 'sollstundenSo',
        ];
        $hasWeekdayInput = array_key_exists('sollzeitJeWochentag', $this->body);
        foreach ($weekdayFields as $field) {
            if (array_key_exists($field, $this->body)) $hasWeekdayInput = true;
        }
        if (array_key_exists('sollstundenTag', $this->body) || !$hasWeekdayInput) {
            $raw = $this->body['sollstundenTag'] ?? 8;
            if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 24) {
                jsonOut(['error' => 'Sollstunden müssen numerisch zwischen 0 und 24 liegen.'], 400);
            }
            $fields[] = 'sollstundenTag = ?'; $params[] = (float)$raw;
        }
        if (array_key_exists('sollzeitJeWochentag', $this->body)) {
            $enabled = $this->body['sollzeitJeWochentag'];
            if (!in_array($enabled, [true, false, 0, 1, '0', '1'], true)) {
                jsonOut(['error' => 'Ungültiger Wert für Sollzeit je Wochentag.'], 400);
            }
            $fields[] = 'sollzeitJeWochentag = ?'; $params[] = $enabled ? 1 : 0;
        }
        foreach ($weekdayFields as $field) {
            if (!array_key_exists($field, $this->body)) continue;
            $raw = $this->body[$field];
            if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 24) {
                jsonOut(['error' => 'Wochentags-Sollstunden müssen numerisch zwischen 0 und 24 liegen.'], 400);
            }
            $fields[] = $field . ' = ?'; $params[] = (float)$raw;
        }
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $params[] = $target;
        $cnt = $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE username = ?');
        $cnt->execute($params);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    /** set_soll_tage_woche */
    public function setSollTageWoche(): void
    {
        Auth::requireRole('admin', 'master');
        $target = trim($this->body['username'] ?? '');
        $val    = (float)($this->body['sollTageWoche'] ?? 5);
        if ($val < 1) $val = 1;
        if ($val > 7) $val = 7;
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $cnt = $this->db->prepare("UPDATE users SET sollTageWoche = ? WHERE username = ?");
        $cnt->execute([$val, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    /** set_urlaubstage */
    public function setUrlaubstage(): void
    {
        Auth::requireRole('admin', 'master');
        $target     = trim($this->body['username'] ?? '');
        $year       = (int)($this->body['year'] ?? date('Y'));
        $urlaubstage = (int)($this->body['urlaubstage'] ?? 30);
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);

        $row = Database::fetchOne($this->db, "SELECT urlaubstageProJahr FROM users WHERE username = ?", [$target]);
        if (!$row) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        $uProJahr = json_decode($row['urlaubstageProJahr'] ?? '{}', true) ?? [];
        $uProJahr[(string)$year] = $urlaubstage;
        $this->db->prepare("UPDATE users SET urlaubstageProJahr = ? WHERE username = ?")
                  ->execute([json_encode($uProJahr), $target]);
        jsonOut(['ok' => true]);
    }

    /** get_sollstunden_extended */
    public function getSollstundenExtended(): void
    {
        Auth::requireRole('admin', 'master');
        $rows = $this->db->query("SELECT username, role, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, sollstunden, urlaubstageProJahr, showInZeitverwaltung, showInWochenplanung FROM users ORDER BY LOWER(username)")->fetchAll();
        $result = array_map(fn($r) => [
            'username'             => $r['username'],
            'role'                 => $r['role'],
            'sollstundenTag'       => (float)($r['sollstundenTag'] ?? 8),
            'sollTageWoche'        => (float)($r['sollTageWoche'] ?? 5),
            'arbeitstage'          => $r['arbeitstage'] ?? '1,2,3,4,5',
            'sollzeitJeWochentag'  => (bool)($r['sollzeitJeWochentag'] ?? 0),
            'sollstundenMo'        => (float)($r['sollstundenMo'] ?? 0),
            'sollstundenDi'        => (float)($r['sollstundenDi'] ?? 0),
            'sollstundenMi'        => (float)($r['sollstundenMi'] ?? 0),
            'sollstundenDo'        => (float)($r['sollstundenDo'] ?? 0),
            'sollstundenFr'        => (float)($r['sollstundenFr'] ?? 0),
            'sollstundenSa'        => (float)($r['sollstundenSa'] ?? 0),
            'sollstundenSo'        => (float)($r['sollstundenSo'] ?? 0),
            'sollstunden'          => (float)($r['sollstunden'] ?? 0),
            'urlaubstageProJahr'   => json_decode($r['urlaubstageProJahr'] ?? '{}', true) ?? [],
            'showInZeitverwaltung' => (bool)$r['showInZeitverwaltung'],
            'showInWochenplanung'  => (bool)$r['showInWochenplanung'],
        ], $rows);
        jsonOut(['ok' => true, 'users' => $result]);
    }

    /**
     * auto_import_stunden – „Jetzt importieren“: bucht die Stunden aller Benutzer mit
     * Kürzel nach denselben Regeln wie save_zeiterfassung ins Projekt (exakte Verknüpfung,
     * Sperrliste, archivierte Projekte, Handänderungen bleiben). Entfernt nichts.
     */
    public function autoImport(): void
    {
        // Wirkt auf die Projektbuchungen aller Mitarbeiter – wie der Knopf in
        // „Allgemein“ nur für Administratoren.
        Auth::requireRole('admin');
        $settings = Auth::loadSettings($this->db);
        if (empty($settings['auto_import_stunden'])) jsonOut(['error' => 'Auto-Import ist deaktiviert.'], 400);

        $users = $this->db->query("SELECT username, kuerzel, stundenKategorie FROM users WHERE kuerzel != ''")->fetchAll();
        $imported = 0;
        $changed  = false;
        $dataRev  = null;
        $this->db->beginTransaction();
        try {
            foreach ($users as $u) {
                $username = (string)$u['username'];
                $rows = $this->resetBuchungsStatus($username);
                $sync = $this->syncProjektbuchungen(
                    $username, (string)$u['kuerzel'], (string)($u['stundenKategorie'] ?? ''),
                    $rows, $rows, [], false, true
                );
                $imported += $sync['imported'];
                $changed = $changed || $sync['changed'];
            }
            if ($changed) $dataRev = \App\DataService::bumpRev($this->db, (string)($_SESSION['username'] ?? ''));
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] auto_import_stunden fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Import fehlgeschlagen.'], 500);
        }
        jsonOut(['ok' => true, 'imported' => $imported, 'dataRev' => $dataRev]);
    }

    /** get_user_sollstunden – eigene Sollstunden (für normalen User) */
    public function getUserSollstunden(): void
    {
        Auth::requireAuth();
        $username = $_SESSION['username'];
        $row = Database::fetchOne($this->db, "SELECT sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, sollstunden, urlaubstageProJahr FROM users WHERE username = ?", [$username]);
        if (!$row) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true, 'sollstundenTag' => (float)($row['sollstundenTag'] ?? 8), 'sollTageWoche' => (float)($row['sollTageWoche'] ?? 5), 'arbeitstage' => $row['arbeitstage'] ?? '1,2,3,4,5', 'sollzeitJeWochentag' => (bool)($row['sollzeitJeWochentag'] ?? 0), 'sollstundenMo' => (float)($row['sollstundenMo'] ?? 0), 'sollstundenDi' => (float)($row['sollstundenDi'] ?? 0), 'sollstundenMi' => (float)($row['sollstundenMi'] ?? 0), 'sollstundenDo' => (float)($row['sollstundenDo'] ?? 0), 'sollstundenFr' => (float)($row['sollstundenFr'] ?? 0), 'sollstundenSa' => (float)($row['sollstundenSa'] ?? 0), 'sollstundenSo' => (float)($row['sollstundenSo'] ?? 0), 'sollstunden' => (float)($row['sollstunden'] ?? 0), 'urlaubstageProJahr' => json_decode($row['urlaubstageProJahr'] ?? '{}', true) ?? []]);
    }

    /** get_gleitzeitkonto_buchungen – Buchungen für einen User */
    public function getGleitzeitBuchungen(): void
    {
        Auth::requireAuth();
        $username = $_GET['username'] ?? $_SESSION['username'];
        // Normale User dürfen nur eigene sehen
        if ($username !== $_SESSION['username'] && !Auth::canDo($this->db, 'canSeeStundenauswertung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }
        $rows = Database::fetchAll($this->db, "SELECT * FROM gleitzeitkonto_buchungen WHERE username = ? ORDER BY erstellt_am DESC", [$username]);
        jsonOut(['ok' => true, 'buchungen' => $rows]);
    }

    /** save_gleitzeitkonto_buchung – Admin bucht Gleitzeit (z.B. Auszahlung) */
    public function saveGleitzeitBuchung(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeStundenauswertung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }
        $target   = trim($this->body['username'] ?? '');
        $betrag   = (float)($this->body['betrag'] ?? 0);
        $kommentar = trim($this->body['kommentar'] ?? '');
        // Optionales Datum (z.B. für Jahreswechsel-Übertrag); Standardwert = heute
        $datumRaw  = trim($this->body['datum'] ?? '');
        $datum     = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datumRaw) && strtotime($datumRaw)) ? $datumRaw : date('Y-m-d');
        if (!$target) jsonOut(['error' => 'Benutzername fehlt.'], 400);
        if (!$kommentar) jsonOut(['error' => 'Kommentar ist Pflicht.'], 400);

        $stmt = $this->db->prepare("INSERT INTO gleitzeitkonto_buchungen (username, datum, betrag, kommentar, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$target, $datum, $betrag, $kommentar, $_SESSION['username'], date('Y-m-d H:i:s')]);
        jsonOut(['ok' => true]);
    }

    /** admin_edit_zeiterfassung – Admin bearbeitet einen Eintrag eines Users */
    public function adminEditEntry(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeStundenauswertung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }
        $target  = trim($this->body['username'] ?? '');
        $entryId = (int)($this->body['entryId'] ?? 0);
        if (!$target || !$entryId) jsonOut(['error' => 'Ungültige Parameter.'], 400);

        $old = Database::fetchOne($this->db, "SELECT datum, typ, stunden, baustelleId, baustelleName, bemerkung, von, bis, pause FROM zeiterfassung WHERE username = ? AND entryId = ?", [$target, $entryId]);
        if (!$old) jsonOut(['error' => 'Eintrag nicht gefunden.'], 404);

        // Nicht gesendete Felder behalten ihren Wert (die mobile Bearbeitung sendet z.B.
        // weder Projekt noch Uhrzeiten – diese dürfen dabei nicht verloren gehen).
        $feld = fn(string $key, mixed $alt): mixed => array_key_exists($key, $this->body) ? $this->body[$key] : $alt;
        $datum   = trim((string)$feld('datum', $old['datum']));
        $typ     = trim((string)$feld('typ', $old['typ']));
        $stunden = (float)$feld('stunden', $old['stunden']);
        $bIdRaw  = $feld('baustelleId', $old['baustelleId']);
        $baustelleId = ($bIdRaw === null || $bIdRaw === '') ? null : (int)$bIdRaw;
        $bemerkung = trim((string)$feld('bemerkung', $old['bemerkung']));
        $von     = trim((string)$feld('von', $old['von']));
        $bis     = trim((string)$feld('bis', $old['bis']));
        $pause   = (float)$feld('pause', $old['pause']);

        // Urlaub/Krank serverseitig immer mit Soll/Tag gutschreiben (Gleitzeit-neutral)
        $zeitCfg = $this->loadUserZeitConfig($target);
        if (in_array($typ, self::CREDITED_ABSENCE_TYPEN, true)) {
            $stunden = $this->absenceStundenForDate($datum, $zeitCfg);
        }
        // Urlaubslimit prüfen (dieser Eintrag ausgenommen)
        if ($typ === 'urlaub') {
            $year = substr($datum, 0, 4);
            $row  = Database::fetchOne($this->db, "SELECT COUNT(*) AS c FROM zeiterfassung WHERE username = ? AND typ = 'urlaub' AND entryId <> ? AND substr(datum,1,4) = ?", [$target, $entryId, $year]);
            $limit = $this->urlaubLimitForYear($zeitCfg['urlaubProJahr'], $year);
            if ((int)($row['c'] ?? 0) + 1 > $limit) {
                jsonOut(['error' => "Urlaubslimit für {$year} erreicht ({$limit} Tage). Buchung nicht möglich."], 400);
            }
        }

        $settings = Auth::loadSettings($this->db);
        $now = date('Y-m-d H:i:s');

        // Projektname denormalisieren, damit die Zuordnung ein Archivieren überlebt.
        $neuName = '';
        if ($baustelleId !== null) {
            $bn = Database::fetchOne($this->db, "SELECT name FROM baustellen WHERE id = ?", [$baustelleId]);
            $neuName = (string)($bn['name'] ?? '');
        }
        $altBId = $old['baustelleId'] !== null ? (int)$old['baustelleId'] : null;

        $dataRev = null;
        $this->db->beginTransaction();
        try {
            // Audit-Trail: alten Wert protokollieren
            if (!empty($settings['erweiterte_zeiterfassung'])) {
                $logStmt = $this->db->prepare("INSERT INTO zeiterfassung_log (username, entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm) VALUES (?,?,?,?,?,?,?)");
                $logStmt->execute([
                    $target, $entryId, 'edit',
                    json_encode($old, JSON_UNESCAPED_UNICODE),
                    json_encode(['datum'=>$datum,'typ'=>$typ,'stunden'=>$stunden,'bemerkung'=>$bemerkung,'von'=>$von,'bis'=>$bis,'pause'=>$pause], JSON_UNESCAPED_UNICODE),
                    $_SESSION['username'],
                    $now,
                ]);
            }

            // Umbuchung immer protokollieren – unabhängig von "erweiterte Zeiterfassung".
            if ($altBId !== $baustelleId) {
                $this->writeMoveLogs($target, [[
                    'entryId' => $entryId,
                    'alt'     => ['baustelleId' => $altBId,      'baustelleName' => (string)($old['baustelleName'] ?? '')],
                    'neu'     => ['baustelleId' => $baustelleId, 'baustelleName' => $neuName],
                    'datum'   => $datum,
                ]], (string)($_SESSION['username'] ?? ''), $now);
            }

            $vorherKat = Database::fetchOne($this->db, "SELECT stundenKatId FROM zeiterfassung WHERE username = ? AND entryId = ?", [$target, $entryId]);
            $stmt = $this->db->prepare("UPDATE zeiterfassung SET datum = ?, typ = ?, stunden = ?, baustelleId = ?, baustelleName = ?, bemerkung = ?, von = ?, bis = ?, pause = ?, updatedAt = ? WHERE username = ? AND entryId = ?");
            $stmt->execute([$datum, $typ, $stunden, $baustelleId, $neuName, $bemerkung, $von, $bis, $pause, $now, $target, $entryId]);

            $vorherWerte = [$entryId => $old + ['stundenKatId' => $vorherKat['stundenKatId'] ?? null]];
            if ($this->syncNachAdminAenderung($target, $altBId !== null ? [$altBId] : [], $vorherWerte)['changed']) {
                $dataRev = \App\DataService::bumpRev($this->db, (string)($_SESSION['username'] ?? ''));
            }
            self::bumpZeitRev($this->db, $target);
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] admin_edit_zeiterfassung fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Eintrag konnte nicht gespeichert werden.'], 500);
        }
        jsonOut(['ok' => true, 'stunden' => $stunden, 'dataRev' => $dataRev]);
    }

    /** admin_add_zeiterfassung – Admin fügt einen oder mehrere Einträge für einen User nach.
     *  Optional: datumBis → erzeugt einen Eintrag pro Werktag im Zeitraum datum..datumBis. */
    public function adminAddEntry(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeStundenauswertung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }
        $target      = trim($this->body['username'] ?? '');
        $datum       = trim($this->body['datum']    ?? '');
        $datumBis    = trim($this->body['datumBis'] ?? '');
        $typ         = trim($this->body['typ']      ?? 'arbeit');
        $stunden     = (float)($this->body['stunden'] ?? 0);
        $baustelleId = isset($this->body['baustelleId']) && $this->body['baustelleId'] !== '' ? (int)$this->body['baustelleId'] : null;
        $bemerkung   = trim($this->body['bemerkung'] ?? '');
        $von         = trim($this->body['von']  ?? '');
        $bis         = trim($this->body['bis']  ?? '');
        $pause       = (float)($this->body['pause'] ?? 0);

        if (!$target || !$datum) jsonOut(['error' => 'Ungültige Parameter.'], 400);

        // Arbeitszeit-Konfiguration laden (Soll/Tag, Arbeitstage, Urlaubslimit)
        $zeitCfg = $this->loadUserZeitConfig($target);
        $settings = Auth::loadSettings($this->db);

        // Datumsbereich aufbauen: bei Zeitraum nur echte Soll-Tage ohne Feiertag.
        $dates = [];
        if ($datumBis && $datumBis > $datum) {
            $start    = new \DateTimeImmutable($datum);
            $end      = new \DateTimeImmutable($datumBis);
            $end      = $end->modify('+1 day');
            $interval = new \DateInterval('P1D');
            $period   = new \DatePeriod($start, $interval, $end);
            $customFeiertage = Feiertage::customAusEinstellungen($settings);
            $feiertageByYear = [];
            foreach ($period as $d) {
                $key = $d->format('Y-m-d');
                $year = (int)$d->format('Y');
                if (!isset($feiertageByYear[$year])) {
                    $feiertageByYear[$year] = Feiertage::fuerJahr($year, $customFeiertage);
                }
                if (Sollzeit::tagesSoll($zeitCfg, $key) > 0.0 && !isset($feiertageByYear[$year][$key])) {
                    $dates[] = $key;
                }
            }
        } else {
            $dates[] = $datum;
        }

        if (empty($dates)) jsonOut(['error' => 'Keine Arbeitstage im gewählten Zeitraum.'], 400);

        // Urlaubslimit prüfen (bestehende + neue Tage pro Jahr)
        if ($typ === 'urlaub') {
            $newByYear = [];
            foreach ($dates as $d) { $y = substr($d, 0, 4); $newByYear[$y] = ($newByYear[$y] ?? 0) + 1; }
            foreach ($newByYear as $y => $cnt) {
                $row      = Database::fetchOne($this->db, "SELECT COUNT(*) AS c FROM zeiterfassung WHERE username = ? AND typ = 'urlaub' AND substr(datum,1,4) = ?", [$target, (string)$y]);
                $limit    = $this->urlaubLimitForYear($zeitCfg['urlaubProJahr'], (string)$y);
                if ((int)($row['c'] ?? 0) + $cnt > $limit) {
                    jsonOut(['error' => "Urlaubslimit für {$y} erreicht ({$limit} Tage). Buchung nicht möglich."], 400);
                }
            }
        }

        // Nächste freie entryId für diesen User ermitteln
        $row    = Database::fetchOne($this->db, "SELECT COALESCE(MAX(entryId),0) AS m FROM zeiterfassung WHERE username = ?", [$target]);
        $nextId = (int)($row['m'] ?? 0) + 1;

        $stmt         = $this->db->prepare("INSERT INTO zeiterfassung (username, entryId, datum, typ, baustelleId, baustelleName, stunden, bemerkung, von, bis, pause, clientUuid, status, createdAt, updatedAt, quelle) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'admin')");
        $auditEnabled = !empty($settings['erweiterte_zeiterfassung']);
        $auditStmt    = $auditEnabled
            ? $this->db->prepare("INSERT INTO zeiterfassung_log (username, entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm) VALUES (?,?,?,?,?,?,?)")
            : null;

        $nowTs   = date('Y-m-d H:i:s');
        // Gutschrift-Typen: Urlaub/Krank + eigene "Ist = Soll"-Arbeitstypen (Soll/Tag als Ist).
        $creditedTypen = self::CREDITED_ABSENCE_TYPEN;
        foreach (($settings['ze_custom_typen'] ?? []) as $ct) {
            if (is_array($ct) && !empty($ct['value']) && ($ct['isArbeit'] ?? true) !== false && ($ct['istGleichSoll'] ?? false) === true) {
                $creditedTypen[] = $ct['value'];
            }
        }
        $bName   = '';
        if ($baustelleId !== null) {
            $bn    = Database::fetchOne($this->db, "SELECT name FROM baustellen WHERE id = ?", [$baustelleId]);
            $bName = (string)($bn['name'] ?? '');
        }

        $inserted = [];
        $dataRev  = null;
        $this->db->beginTransaction();
        try {
            foreach ($dates as $d) {
                // Urlaub/Krank + "Ist = Soll"-Typen pro Tag mit Soll/Tag gutschreiben (Gleitzeit-neutral)
                $stundenForDate = in_array($typ, $creditedTypen, true)
                    ? $this->absenceStundenForDate($d, $zeitCfg)
                    : $stunden;
                $stmt->execute([
                    $target, $nextId, $d, $typ, $baustelleId, $bName, $stundenForDate, $bemerkung, $von, $bis, $pause,
                    self::newUuid(), BuchungValidator::STATUS_VALID, $nowTs, $nowTs,
                ]);
                if ($auditStmt) {
                    $auditStmt->execute([
                        $target, $nextId, 'add', '',
                        json_encode(['datum'=>$d,'typ'=>$typ,'stunden'=>$stundenForDate,'bemerkung'=>$bemerkung,'von'=>$von,'bis'=>$bis,'pause'=>$pause], JSON_UNESCAPED_UNICODE),
                        $_SESSION['username'],
                        $nowTs,
                    ]);
                }
                $inserted[] = ['entryId' => $nextId, 'datum' => $d, 'stunden' => $stundenForDate];
                $nextId++;
            }

            if ($this->syncNachAdminAenderung($target, [])['changed']) {
                $dataRev = \App\DataService::bumpRev($this->db, (string)($_SESSION['username'] ?? ''));
            }
            self::bumpZeitRev($this->db, $target);
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] admin_add_zeiterfassung fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Einträge konnten nicht gespeichert werden.'], 500);
        }
        jsonOut(['ok' => true, 'inserted' => $inserted, 'dataRev' => $dataRev]);
    }

    /** admin_delete_zeiterfassung – Admin löscht einen Eintrag eines Users */
    public function adminDeleteEntry(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeStundenauswertung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }
        $target  = trim($this->body['username'] ?? '');
        $entryId = (int)($this->body['entryId'] ?? 0);
        if (!$target || !$entryId) jsonOut(['error' => 'Ungültige Parameter.'], 400);

        $old = Database::fetchOne($this->db, "SELECT datum, typ, stunden, baustelleId, bemerkung, von, bis, pause FROM zeiterfassung WHERE username = ? AND entryId = ?", [$target, $entryId]);
        if (!$old) jsonOut(['error' => 'Eintrag nicht gefunden.'], 404);

        $settings = Auth::loadSettings($this->db);
        $sync     = ['removed' => 0];
        $dataRev  = null;
        $this->db->beginTransaction();
        try {
            if (!empty($settings['erweiterte_zeiterfassung'])) {
                $logStmt = $this->db->prepare("INSERT INTO zeiterfassung_log (username, entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm) VALUES (?,?,?,?,?,?,?)");
                $logStmt->execute([$target, $entryId, 'delete', json_encode($old, JSON_UNESCAPED_UNICODE), '', $_SESSION['username'], date('Y-m-d H:i:s')]);
            }

            $this->db->prepare("DELETE FROM zeiterfassung WHERE username = ? AND entryId = ?")->execute([$target, $entryId]);

            // Abgleich: zugehörige Auto-Import-Buchung entfernen – auch wenn es der letzte
            // Eintrag des Benutzers auf diesem Projekt war.
            $sync = $this->syncNachAdminAenderung($target, $old['baustelleId'] !== null ? [(int)$old['baustelleId']] : []);
            if ($sync['changed']) {
                $dataRev = \App\DataService::bumpRev($this->db, (string)($_SESSION['username'] ?? ''));
            }
            self::bumpZeitRev($this->db, $target);
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] admin_delete_zeiterfassung fehlgeschlagen: ' . $ex->getMessage());
            jsonOut(['error' => 'Eintrag konnte nicht gelöscht werden.'], 500);
        }

        jsonOut(['ok' => true, 'autoRemoved' => $sync['removed'], 'dataRev' => $dataRev]);
    }

    /** Audit-Trail für bulk save (delete-all-reinsert pattern) */
    // -------------------------------------------------------
    // JAHRESWECHSEL-ASSISTENT
    // -------------------------------------------------------

    /** get_jahreswechsel_data – Daten für den Jahreswechsel-Assistenten */
    public function getJahreswechselData(): void
    {
        Auth::requireRole('admin', 'master');
        $year = (int)($_GET['year'] ?? (int)date('Y'));

        $settings      = Auth::loadSettings($this->db);
        $gleitzeitAktiv = ($settings['gleitzeit_enabled'] ?? null) !== false;
        $customFeiertage = Feiertage::customAusEinstellungen($settings);

        $rows = $this->db->query(
            "SELECT username, role, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, urlaubstageProJahr
               FROM users
              WHERE LOWER(username) != 'systemadmin'
              ORDER BY LOWER(username)"
        )->fetchAll();

        $users = [];
        foreach ($rows as $r) {
            $uProJahr  = json_decode($r['urlaubstageProJahr'] ?? '{}', true) ?? [];
            $gleitzeitSaldo = null;
            if ($gleitzeitAktiv) {
                $gleitzeitSaldo = Gleitzeit::saldo($this->db, (string)$r['username'], $r, $year, $settings);
            }

            $users[] = [
                'username'          => $r['username'],
                'role'              => $r['role'],
                'urlaubstageProJahr' => $uProJahr,
                'gleitzeitSaldo'    => $gleitzeitSaldo,
            ];
        }

        jsonOut([
            'ok'              => true,
            'users'           => $users,
            'customFeiertage' => $customFeiertage,
            'gleitzeitAktiv'  => $gleitzeitAktiv,
        ]);
    }

    private function writeAuditLog(string $username, array $oldRows, array $newEntries): void
    {
        $now = date('Y-m-d H:i:s');
        $logStmt = $this->db->prepare("INSERT INTO zeiterfassung_log (username, entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm) VALUES (?,?,?,?,?,?,?)");

        // Index old/new by entryId
        $oldById = [];
        foreach ($oldRows as $r) {
            $eid = $r['entryId'] !== null ? (int)$r['entryId'] : null;
            if ($eid !== null) $oldById[$eid] = $r;
        }
        $newById = [];
        foreach ($newEntries as $e) {
            $eid = $e['id'] ?? null;
            if ($eid !== null) $newById[$eid] = $e;
        }

        // Deleted entries
        foreach ($oldById as $eid => $old) {
            if (!isset($newById[$eid])) {
                $logStmt->execute([$username, $eid, 'delete', json_encode($old, JSON_UNESCAPED_UNICODE), '', $username, $now]);
            }
        }

        // New entries
        foreach ($newById as $eid => $ne) {
            if (!isset($oldById[$eid])) {
                $logStmt->execute([$username, $eid, 'create', '', json_encode($ne, JSON_UNESCAPED_UNICODE), $username, $now]);
            }
        }

        // Modified entries (only log if key fields changed)
        foreach ($newById as $eid => $ne) {
            if (!isset($oldById[$eid])) continue;
            $old = $oldById[$eid];
            $changed = ($old['datum'] ?? '') !== ($ne['datum'] ?? '')
                || ($old['typ'] ?? '') !== ($ne['typ'] ?? '')
                || abs((float)($old['stunden'] ?? 0) - (float)($ne['stunden'] ?? 0)) > 0.001
                || ($old['von'] ?? '') !== ($ne['von'] ?? '')
                || ($old['bis'] ?? '') !== ($ne['bis'] ?? '')
                || abs((float)($old['pause'] ?? 0) - (float)($ne['pause'] ?? 0)) > 0.001;
            if ($changed) {
                $logStmt->execute([$username, $eid, 'edit', json_encode($old, JSON_UNESCAPED_UNICODE), json_encode($ne, JSON_UNESCAPED_UNICODE), $username, $now]);
            }
        }
    }

    // -------------------------------------------------------
    // WOCHENPRÜFUNG – Laden
    // -------------------------------------------------------

    /** load_wochenpruefung – Prüfstatus aller User/KW als {username: {kw: {...}}} */
    public function loadWochenpruefung(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeWochenpruefung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }

        $rows = Database::fetchAll($this->db, "SELECT * FROM zeiterfassung_wochenpruefung", []);
        $result = [];
        foreach ($rows as $r) {
            $un = $r['username'];
            $kw = $r['kw'];
            if (!isset($result[$un])) $result[$un] = [];
            $result[$un][$kw] = [
                'geprueft'    => (bool)$r['geprueft'],
                'geprueftVon' => $r['geprueftVon'],
                'geprueftAm'  => $r['geprueftAm'],
                'kommentar'   => $r['kommentar'],
            ];
        }
        jsonOut(['ok' => true, 'pruefungen' => $result]);
    }

    // -------------------------------------------------------
    // WOCHENPRÜFUNG – Speichern/Toggeln
    // -------------------------------------------------------

    /** save_wochenpruefung – Prüfstatus für username+kw setzen */
    public function saveWochenpruefung(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeWochenpruefung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }

        $username  = trim($this->body['username'] ?? '');
        $kw        = trim($this->body['kw'] ?? '');
        $geprueft  = isset($this->body['geprueft']) ? (bool)$this->body['geprueft'] : false;
        $kommentar = trim($this->body['kommentar'] ?? '');

        if (!$username || !$kw) {
            http_response_code(400);
            jsonOut(['ok' => false, 'error' => 'username und kw sind Pflichtfelder']);
            return;
        }

        // KW-Format validieren (YYYY-WNN)
        if (!preg_match('/^\d{4}-W\d{1,2}$/', $kw)) {
            http_response_code(400);
            jsonOut(['ok' => false, 'error' => 'Ungültiges KW-Format (erwartet: YYYY-WNN)']);
            return;
        }

        $supervisor = $_SESSION['username'] ?? '';
        $now        = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO zeiterfassung_wochenpruefung (username, kw, geprueft, geprueftVon, geprueftAm, kommentar)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(username, kw) DO UPDATE SET
                geprueft   = excluded.geprueft,
                geprueftVon = excluded.geprueftVon,
                geprueftAm  = excluded.geprueftAm,
                kommentar   = excluded.kommentar
        ");
        $stmt->execute([$username, $kw, $geprueft ? 1 : 0, $supervisor, $geprueft ? $now : '', $kommentar]);

        // Kopplung: Wochenprüfung überträgt sich auf alle Tage der KW (persistiert).
        // Wird die Woche abgehakt, gelten alle erfassten Tage dieser Woche als geprüft;
        // wird sie zurückgesetzt, werden dieselben Tage wieder auf ungeprüft gesetzt.
        $this->propagateWochenpruefungToTage($username, $kw, $geprueft, $supervisor, $now);

        jsonOut(['ok' => true]);
    }

    /**
     * Überträgt den Prüfstatus einer KW auf alle Tage mit Zeiterfassungs-Einträgen
     * in dieser Woche (Mo–So).
     */
    private function propagateWochenpruefungToTage(string $username, string $kw, bool $geprueft, string $supervisor, string $now): void
    {
        if (!preg_match('/^(\d{4})-W(\d{1,2})$/', $kw, $mKw)) {
            return;
        }
        $year = (int)$mKw[1];
        $week = (int)$mKw[2];

        // Montag der ISO-Woche bestimmen, Sonntag = +6 Tage
        $monday = new \DateTime();
        $monday->setISODate($year, $week);
        $mondayKey = $monday->format('Y-m-d');
        $sunday = (clone $monday)->modify('+6 days');
        $sundayKey = $sunday->format('Y-m-d');

        $days = Database::fetchAll(
            $this->db,
            "SELECT DISTINCT datum FROM zeiterfassung WHERE username = ? AND datum >= ? AND datum <= ?",
            [$username, $mondayKey, $sundayKey]
        );
        if (!$days) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO zeiterfassung_tagespruefung (username, datum, geprueft, geprueftVon, geprueftAm, kommentar)
            VALUES (?, ?, ?, ?, ?, '')
            ON CONFLICT(username, datum) DO UPDATE SET
                geprueft   = excluded.geprueft,
                geprueftVon = excluded.geprueftVon,
                geprueftAm  = excluded.geprueftAm
        ");
        foreach ($days as $d) {
            $datum = $d['datum'] ?? '';
            if (!$datum) continue;
            $stmt->execute([$username, $datum, $geprueft ? 1 : 0, $supervisor, $geprueft ? $now : '']);
        }
    }

    // -------------------------------------------------------
    // TAGESPRÜFUNG – Laden
    // -------------------------------------------------------

    /** load_tagespruefung – Prüfstatus aller User/Tage als {username: {datum: {...}}} */
    public function loadTagespruefung(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeWochenpruefung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }

        $rows = Database::fetchAll($this->db, "SELECT * FROM zeiterfassung_tagespruefung", []);
        $result = [];
        foreach ($rows as $r) {
            $un = $r['username'];
            $dt = $r['datum'];
            if (!isset($result[$un])) $result[$un] = [];
            $result[$un][$dt] = [
                'geprueft'    => (bool)$r['geprueft'],
                'geprueftVon' => $r['geprueftVon'],
                'geprueftAm'  => $r['geprueftAm'],
                'kommentar'   => $r['kommentar'],
            ];
        }
        jsonOut(['ok' => true, 'pruefungen' => $result]);
    }

    // -------------------------------------------------------
    // TAGESPRÜFUNG – Speichern/Toggeln
    // -------------------------------------------------------

    /** save_tagespruefung – Prüfstatus für username+datum setzen */
    public function saveTagespruefung(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeWochenpruefung')) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
        }

        $username  = trim($this->body['username'] ?? '');
        $datum     = trim($this->body['datum'] ?? '');
        $geprueft  = isset($this->body['geprueft']) ? (bool)$this->body['geprueft'] : false;
        $kommentar = trim($this->body['kommentar'] ?? '');

        if (!$username || !$datum) {
            http_response_code(400);
            jsonOut(['ok' => false, 'error' => 'username und datum sind Pflichtfelder']);
            return;
        }

        // Datum-Format validieren (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            http_response_code(400);
            jsonOut(['ok' => false, 'error' => 'Ungültiges Datum-Format (erwartet: YYYY-MM-DD)']);
            return;
        }

        $supervisor = $_SESSION['username'] ?? '';
        $now        = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO zeiterfassung_tagespruefung (username, datum, geprueft, geprueftVon, geprueftAm, kommentar)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(username, datum) DO UPDATE SET
                geprueft   = excluded.geprueft,
                geprueftVon = excluded.geprueftVon,
                geprueftAm  = excluded.geprueftAm,
                kommentar   = excluded.kommentar
        ");
        $stmt->execute([$username, $datum, $geprueft ? 1 : 0, $supervisor, $geprueft ? $now : '', $kommentar]);

        jsonOut(['ok' => true]);
    }
}

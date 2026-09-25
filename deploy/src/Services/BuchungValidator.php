<?php
namespace App\Services;

use App\Database;
use PDO;

/**
 * Zentrale Validierung von Zeitbuchungen (Fehlbuchungs-Katalog).
 *
 * Einzige Wahrheit dafür, wann eine Buchung als Fehlbuchung gilt. Vorher war die
 * Regel dreifach implementiert (script.js, mobile, PHP) und driftete auseinander.
 *
 * Zwei Schweregrade:
 *  - BLOCKER: Speichern wird abgelehnt (HTTP 400), Daten bleiben unverändert.
 *  - WARNING: Eintrag wird gespeichert und mit status=booked_warning markiert;
 *             die Arbeitsleistung bleibt in jedem Fall erhalten.
 */
class BuchungValidator
{
    public const STATUS_VALID   = 'booked_valid';
    public const STATUS_WARNING = 'booked_warning';
    public const STATUS_INVALID = 'booked_invalid';

    public const SEVERITY_BLOCKER = 'blocker';
    public const SEVERITY_WARNING = 'warning';

    /**
     * Fehlbuchungs-Katalog: error_code => [severity, user_friendly_message].
     * Die Meldung ist bewusst handlungsorientiert formuliert.
     */
    public const KATALOG = [
        'ZE001' => [self::SEVERITY_BLOCKER, 'Die Zeiten überschneiden sich mit einer anderen Buchung am selben Tag.'],
        'ZE002' => [self::SEVERITY_BLOCKER, 'Die Endzeit muss nach der Startzeit liegen.'],
        'ZE003' => [self::SEVERITY_BLOCKER, 'Für Arbeitszeit muss ein Projekt ausgewählt sein.'],
        'ZE004' => [self::SEVERITY_BLOCKER, 'Ein Arbeitseintrag benötigt mehr als 0 Stunden.'],
        'ZE005' => [self::SEVERITY_WARNING, 'Das zugeordnete Projekt existiert nicht mehr. Die Arbeitszeit bleibt gültig.'],
        'ZE006' => [self::SEVERITY_WARNING, 'Die Stunden wurden noch nicht ins Projekt gebucht. Über "Reparieren" nachtragen.'],
        'ZE007' => [self::SEVERITY_WARNING, 'Im Projekt existiert eine Buchung ohne passenden Zeiteintrag.'],
        'ZE008' => [self::SEVERITY_WARNING, 'Mehr als 10 Stunden an einem Tag (§ 3 ArbZG).'],
        'ZE009' => [self::SEVERITY_WARNING, 'Die gesetzliche Mindestpause ist nicht eingehalten (§ 4 ArbZG).'],
        'ZE010' => [self::SEVERITY_BLOCKER, 'Das Datum fehlt oder ist ungültig.'],
        'ZE011' => [self::SEVERITY_BLOCKER, 'Dieser Eintrag existiert doppelt.'],
        'ZE012' => [self::SEVERITY_BLOCKER, 'Das Urlaubskontingent für dieses Jahr ist aufgebraucht.'],
    ];

    public static function severity(string $code): string
    {
        return self::KATALOG[$code][0] ?? self::SEVERITY_WARNING;
    }

    public static function message(string $code): string
    {
        return self::KATALOG[$code][1] ?? 'Unbekannter Fehler.';
    }

    /** Baut eine Befund-Struktur für die API-Ausgabe. */
    public static function befund(string $code, array $extra = []): array
    {
        return array_merge([
            'error_code'           => $code,
            'severity'             => self::severity($code),
            'user_friendly_message'=> self::message($code),
        ], $extra);
    }

    /**
     * Strukturelle Prüfung eines Eintrags-Sets VOR dem Schreiben.
     * Liefert ausschließlich Blocker; Warnungen entstehen erst im Kontext der DB
     * (siehe {@see classify()}).
     *
     * @param array $entries Roh-Einträge aus dem Client-Payload
     * @return array<int,array> Liste von Befunden mit entryIndex/entryId/datum
     */
    public static function findBlockers(array $entries): array
    {
        $befunde     = [];
        $rangesByDate = [];
        $seenUuids    = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $befunde[] = self::befund('ZE010', ['entryIndex' => $index]);
                continue;
            }

            $uuid = trim((string)($entry['clientUuid'] ?? ''));
            if ($uuid !== '') {
                if (isset($seenUuids[$uuid])) {
                    $befunde[] = self::befund('ZE011', [
                        'entryIndex' => $index,
                        'entryId'    => $entry['id'] ?? null,
                        'clientUuid' => $uuid,
                    ]);
                }
                $seenUuids[$uuid] = true;
            }

            $datum = trim((string)($entry['datum'] ?? ''));
            if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || strtotime($datum) === false) {
                $befunde[] = self::befund('ZE010', ['entryIndex' => $index, 'entryId' => $entry['id'] ?? null]);
                continue;
            }

            if (($entry['typ'] ?? '') !== 'arbeit') continue;

            $start = self::parseTime($entry['von'] ?? '');
            $end   = self::parseTime($entry['bis'] ?? '');
            if ($start !== null && $end !== null && $end <= $start) {
                $befunde[] = self::befund('ZE002', [
                    'entryIndex' => $index,
                    'entryId'    => $entry['id'] ?? null,
                    'datum'      => $datum,
                ]);
                continue;
            }
            if ($start === null || $end === null) continue;

            $rangesByDate[$datum][] = [
                'index'   => $index,
                'entryId' => $entry['id'] ?? null,
                'start'   => $start,
                'end'     => $end,
                'von'     => substr((string)$entry['von'], 0, 5),
                'bis'     => substr((string)$entry['bis'], 0, 5),
            ];
        }

        foreach ($rangesByDate as $datum => $ranges) {
            usort($ranges, static fn(array $l, array $r): int => $l['start'] <=> $r['start']);
            $previous = null;
            foreach ($ranges as $range) {
                if ($previous !== null && $range['start'] < $previous['end']) {
                    $befunde[] = self::befund('ZE001', [
                        'entryIndex'             => $range['index'],
                        'entryId'                => $range['entryId'],
                        'datum'                  => $datum,
                        'conflictWithEntryIndex' => $previous['index'],
                        'conflictWithEntryId'    => $previous['entryId'],
                        'detail'                 => sprintf(
                            '%s-%s überschneidet sich mit %s-%s',
                            $range['von'], $range['bis'], $previous['von'], $previous['bis']
                        ),
                    ]);
                }
                $previous = $range;
            }
        }

        return $befunde;
    }

    /**
     * Kontextbezogene Einstufung eines gespeicherten Arbeits-Eintrags gegen den
     * Projektbestand. Liefert [status, errorCode, message].
     *
     * Zentrale Regel gegen den Pauschal-Fehlalarm: ZE006 (fehlende Projektbuchung)
     * wird NUR vergeben, wenn der Auto-Import aktiv ist. Ist er aus, kann eine
     * Projektbuchung gar nicht existieren – das ist kein Fehler des Mitarbeiters.
     *
     * @param array $ctx  ['autoImportAktiv'=>bool, 'projekte'=>array, 'username'=>string, 'kuerzel'=>string]
     * @return array{0:string,1:string,2:string}
     */
    public static function classify(array $entry, array $ctx): array
    {
        if (($entry['typ'] ?? '') !== 'arbeit') {
            return [self::STATUS_VALID, '', ''];
        }

        $bId = (int)($entry['baustelleId'] ?? 0);
        if ($bId <= 0) {
            return [self::STATUS_VALID, '', ''];
        }

        $projekte = $ctx['projekte'] ?? [];
        if (!isset($projekte[$bId])) {
            return [self::STATUS_WARNING, 'ZE005', self::message('ZE005')];
        }

        $projekt = $projekte[$bId];

        // Archivierte Projekte sind ein gewollter Zustand: die erbrachte Leistung
        // bleibt uneingeschränkt gültig.
        if (!empty($projekt['archiviert'])) {
            return [self::STATUS_VALID, '', ''];
        }

        if (empty($ctx['autoImportAktiv'])) {
            return [self::STATUS_VALID, '', ''];
        }

        $username = (string)($ctx['username'] ?? '');
        $kuerzel  = (string)($ctx['kuerzel'] ?? '');
        $eid      = isset($entry['id']) && $entry['id'] !== null ? (int)$entry['id'] : 0;
        $stunden  = (float)($entry['stunden'] ?? 0);
        $datum    = (string)($entry['datum'] ?? '');

        // Vom Nutzer bewusst archivierte/gelöschte Projektbuchung -> keine Fehlbuchung.
        $blocked = $projekt['blocked'] ?? [];
        if ($blocked) {
            $idKey = 'id:' . strtolower($username) . ':' . $eid;
            $fzKey = 'fz:' . $kuerzel . ':' . $datum . ':' . (round($stunden, 2) + 0);
            if (($eid > 0 && in_array($idKey, $blocked, true)) || in_array($fzKey, $blocked, true)) {
                return [self::STATUS_VALID, '', ''];
            }
        }

        if (self::hasProjectBooking($projekt['az'] ?? [], $eid, $username, $kuerzel, $datum, $stunden)) {
            return [self::STATUS_VALID, '', ''];
        }

        return [self::STATUS_WARNING, 'ZE006', self::message('ZE006')];
    }

    /**
     * Sucht die Projektbuchung zu einem Zeiteintrag.
     *
     * Primär über die exakte Verknüpfung (zeitEntryId + zeitUser). Der Fuzzy-Abgleich
     * über das Kürzel ist nur noch letzter Fallback für Legacy-Positionen und
     * akzeptiert bewusst auch ein abweichendes Kürzel: sonst würde ein Kürzel-Wechsel
     * schlagartig alle Altbuchungen eines Mitarbeiters zu Fehlbuchungen machen.
     */
    public static function hasProjectBooking(
        array $arbeitszeit, int $eid, string $username, string $kuerzel, string $datum, float $stunden
    ): bool {
        $fuzzyFallback = false;
        foreach ($arbeitszeit as $a) {
            if (!is_array($a) || !($a['autoImport'] ?? false)) continue;

            $aEid = isset($a['zeitEntryId']) ? (int)$a['zeitEntryId'] : 0;
            if ($aEid > 0) {
                if ($eid > 0 && $aEid === $eid) {
                    $zu = (string)($a['zeitUser'] ?? '');
                    if ($zu === '' || strcasecmp($zu, $username) === 0) return true;
                }
                continue;
            }

            // Legacy-Position ohne Link
            if (($a['datum'] ?? '') === $datum && abs((float)($a['stunden'] ?? 0) - $stunden) < 0.01) {
                if ($kuerzel !== '' && ($a['erstelltVon'] ?? '') === $kuerzel) return true;
                $fuzzyFallback = true;
            }
        }
        return $fuzzyFallback;
    }

    /** Lädt Projekte inkl. Archiv-Flag, Sperrliste und Arbeitszeit-Positionen. */
    public static function loadProjekte(PDO $db, array $baustelleIds = []): array
    {
        if ($baustelleIds) {
            $ids = array_values(array_unique(array_map('intval', $baustelleIds)));
            if (!$ids) return [];
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::fetchAll($db, "SELECT id, name, data, archiviert FROM baustellen WHERE id IN ($ph)", $ids);
        } else {
            $rows = Database::fetchAll($db, "SELECT id, name, data, archiviert FROM baustellen");
        }

        $projekte = [];
        foreach ($rows as $b) {
            $data = json_decode($b['data'], true) ?: [];
            $projekte[(int)$b['id']] = [
                'name'       => (string)$b['name'],
                'archiviert' => (int)($b['archiviert'] ?? 0) === 1,
                'blocked'    => (isset($data['azImportBlocked']) && is_array($data['azImportBlocked'])) ? $data['azImportBlocked'] : [],
                'az'         => (isset($data['arbeitszeit']) && is_array($data['arbeitszeit'])) ? $data['arbeitszeit'] : [],
            ];
        }
        return $projekte;
    }

    private static function parseTime(mixed $value): ?int
    {
        $time = trim((string)$value);
        if ($time === '' || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) return null;
        [$h, $m] = array_map('intval', explode(':', $time));
        return ($h * 60) + $m;
    }
}

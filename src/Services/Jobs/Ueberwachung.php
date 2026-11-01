<?php
namespace App\Services\Jobs;

/**
 * Überwachung der System-Jobs und Systemmeldungen (T3, AP-20261012-datensicherung-nas).
 * Pflegt je Job den Beobachtungs-/Störungszustand in job_status und erzeugt bei Bedarf
 * Outbox-Einträge (meldungen_outbox) für Fehler, Ausbleiben und Entwarnung. Der eigentliche
 * Mailversand erfolgt getrennt über MeldungsZustellung::zustellen().
 */
final class Ueberwachung
{
    /** Alle von der Überwachung verwalteten Jobs (unabhängig davon, ob aktuell aktiv). */
    private const JOBS = ['sicherung', 'probe_wiederherstellung', 'speicherplatz', 'backupmail', 'planer', 'nas'];

    /** Ausbleiben-Fristen in Stunden; 'sicherung' kommt aus den Einstellungen (ausbleibenStunden). */
    private const FRIST_PROBE_STUNDEN = 32 * 24 + 2;
    private const FRIST_PLANER_MINUTEN = 15;
    private const FRIST_BACKUPMAIL_STUNDEN = ['taeglich' => 26, 'woechentlich' => 170, 'monatlich' => 746];

    public static function pruefen(\PDO $db, \DateTimeImmutable $jetzt): void
    {
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $heute = $jetzt->format('Y-m-d');
        $einstellungen = SystemEinstellungen::laden($db);
        $ueberwachung = $einstellungen['ueberwachung'] ?? [];
        $meldungenAktiv = (bool)($ueberwachung['aktiv'] ?? true);
        $ausbleibenStundenSicherung = (int)($ueberwachung['ausbleibenStunden'] ?? 26);
        $backupmailZyklus = (string)(self::erinnerungSettings($db)['backup_email_zyklus'] ?? 'woechentlich');

        foreach (self::JOBS as $job) {
            if (!self::istJobAktiv($db, $job)) {
                // Keine job_status-Zeile für inaktive Jobs anlegen (Reviewbefund 15); eine
                // evtl. bereits bestehende Beobachtung wird nur zurückgesetzt.
                $bestehend = self::statusLesenOhneAnlegen($db, $job);
                if (($bestehend['beobachtetSeit'] ?? '') !== '') {
                    $db->prepare("UPDATE job_status SET beobachtetSeit = '' WHERE job = ?")->execute([$job]);
                }
                continue;
            }

            $status = self::statusLesen($db, $job);

            // Schonfrist: Beobachtung startet jetzt, ohne sofortigen Ausbleiben-Alarm
            // (Bezugszeit unten wird dadurch automatisch "jetzt").
            if (($status['beobachtetSeit'] ?? '') === '') {
                $db->prepare('UPDATE job_status SET beobachtetSeit = ? WHERE job = ?')->execute([$jetztStr, $job]);
                $status['beobachtetSeit'] = $jetztStr;
            }

            $fehlerEreignisErstellt = self::fehlerEreignisPruefen($db, $job, $status, $heute, $meldungenAktiv, $jetzt, $jetztStr);
            $gemeldeteStoerung = self::gemeldeteStoerung($db, $job);

            if (!$fehlerEreignisErstellt && $gemeldeteStoerung === null) {
                self::ausbleibenPruefen($db, $job, $status, $heute, $jetzt, $ausbleibenStundenSicherung, $backupmailZyklus, $meldungenAktiv, $jetztStr);
            }

            self::entwarnungPruefen($db, $job, $status, $gemeldeteStoerung, $meldungenAktiv, $jetztStr);
        }
    }

    /** Ob ein Job aktuell überwacht wird (gemeinsam mit SystemJobActions für Admin-Hinweise/Ampel genutzt). */
    public static function istJobAktiv(\PDO $db, string $job): bool
    {
        return match ($job) {
            'backupmail' => (bool)(self::erinnerungSettings($db)['backup_email_aktiv'] ?? false),
            'planer'     => self::planerHatHerzschlagGehabt($db),
            'nas'        => (bool)(SystemEinstellungen::laden($db)['nas']['aktiv'] ?? false),
            default      => true,
        };
    }

    /** @param array<string,mixed> $status */
    private static function fehlerEreignisPruefen(\PDO $db, string $job, array $status, string $heute, bool $meldungenAktiv, \DateTimeImmutable $jetzt, string $jetztStr): bool
    {
        [$ausgeloest, $schluessel, $fehlerCode, $seit] = match ($job) {
            'sicherung' => [
                ($status['tageslaufStatus'] ?? '') === 'fehlgeschlagen',
                'sicherung:' . (string)($status['tageslaufDatum'] ?? $heute) . ':fehler',
                (string)($status['fehlerCode'] ?? ''),
                (string)($status['stoerungSeit'] ?? ''),
            ],
            'probe_wiederherstellung' => [
                ($status['tageslaufStatus'] ?? '') === 'fehlgeschlagen',
                'probe_wiederherstellung:' . (string)($status['tageslaufDatum'] ?? $heute) . ':fehler',
                (string)($status['fehlerCode'] ?? ''),
                (string)($status['stoerungSeit'] ?? ''),
            ],
            'nas' => [
                ($status['tageslaufStatus'] ?? '') === 'fehlgeschlagen',
                'nas:' . (string)($status['tageslaufDatum'] ?? $heute) . ':fehler',
                (string)($status['fehlerCode'] ?? ''),
                (string)($status['stoerungSeit'] ?? ''),
            ],
            'backupmail' => [
                (string)($status['fehlerCode'] ?? '') !== ''
                    && (string)($status['letzterFehlerAm'] ?? '') > (string)($status['letzterErfolgAm'] ?? '')
                    && (string)($status['stoerungSeit'] ?? '') !== ''
                    && (string)($status['stoerungSeit'] ?? '') <= $jetzt->modify('-1 hour')->format('Y-m-d H:i:s'),
                'backupmail:' . $heute . ':fehler',
                (string)($status['fehlerCode'] ?? ''),
                (string)($status['stoerungSeit'] ?? ''),
            ],
            'speicherplatz' => [
                ($status['fehlerCode'] ?? '') === 'SPEICHER_KNAPP',
                'speicherplatz:' . $heute . ':fehler',
                'SPEICHER_KNAPP',
                (string)($status['stoerungSeit'] ?? ''),
            ],
            default => [false, '', '', ''],
        };

        if (!$ausgeloest) return false;
        if (!$meldungenAktiv) return true;

        $daten = json_encode(['fehlerCode' => $fehlerCode, 'seit' => $seit], JSON_UNESCAPED_UNICODE) ?: '{}';
        $stmt = $db->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES (?, ?, 'fehler', ?, ?, '', 0, '')
             ON CONFLICT(schluessel) DO NOTHING"
        );
        $stmt->execute([$schluessel, $job, $daten, $jetztStr]);
        return true;
    }

    /** @param array<string,mixed> $status */
    private static function ausbleibenPruefen(
        \PDO $db,
        string $job,
        array $status,
        string $heute,
        \DateTimeImmutable $jetzt,
        int $ausbleibenStundenSicherung,
        string $backupmailZyklus,
        bool $meldungenAktiv,
        string $jetztStr,
    ): void {
        if ($job === 'speicherplatz') return; // entfällt laut Vertrag

        // Solange ein Tageslauf noch läuft bzw. gerade einen Fehlversuch für die aktuelle
        // Periode wiederholt, wird kein zusätzliches Ausbleiben gemeldet (Reviewbefund 8) –
        // ein endgültiger Fehlschlag wird bereits über fehlerEreignisPruefen() gemeldet.
        $periodenSchluessel = match ($job) {
            'sicherung'               => $heute,
            'probe_wiederherstellung' => $jetzt->format('Y-m'),
            'nas'                     => $heute,
            default                   => null,
        };
        if ($periodenSchluessel !== null
            && (string)($status['tageslaufDatum'] ?? '') === $periodenSchluessel
            && in_array((string)($status['tageslaufStatus'] ?? ''), ['laufend', 'fehler'], true)
        ) {
            return;
        }

        $letzterErfolg = (string)($status['letzterErfolgAm'] ?? '');
        $beobachtetSeit = (string)($status['beobachtetSeit'] ?? '');
        $bezugszeit = $job === 'planer'
            ? (string)($status['letzterLaufAm'] ?? '')
            : max($letzterErfolg, $beobachtetSeit);
        if ($bezugszeit === '') return;

        $fristStunden = match ($job) {
            'sicherung'               => $ausbleibenStundenSicherung,
            'probe_wiederherstellung' => self::FRIST_PROBE_STUNDEN,
            'backupmail'              => self::FRIST_BACKUPMAIL_STUNDEN[$backupmailZyklus] ?? self::FRIST_BACKUPMAIL_STUNDEN['woechentlich'],
            'planer'                  => self::FRIST_PLANER_MINUTEN / 60,
            // T4: Keine eigene Einstellung – teilt sich die allgemeine Ausbleiben-Frist mit
            // der Serversicherung (F4: "für NAS-Sync, tägliche Serversicherung und Backup-Mail").
            'nas'                     => $ausbleibenStundenSicherung,
            default                   => null,
        };
        if ($fristStunden === null) return;

        $vergangeneStunden = ($jetzt->getTimestamp() - (new \DateTimeImmutable($bezugszeit))->getTimestamp()) / 3600;
        if ($vergangeneStunden <= $fristStunden) return;

        $db->prepare("UPDATE job_status SET stoerungSeit = ? WHERE job = ? AND stoerungSeit = ''")->execute([$jetztStr, $job]);
        if (!$meldungenAktiv) return;

        $schluessel = $job . ':' . $heute . ':ausbleiben';
        $daten = json_encode(['fehlerCode' => (string)($status['fehlerCode'] ?? ''), 'seit' => $bezugszeit], JSON_UNESCAPED_UNICODE) ?: '{}';
        $db->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES (?, ?, 'ausbleiben', ?, ?, '', 0, '')
             ON CONFLICT(schluessel) DO NOTHING"
        )->execute([$schluessel, $job, $daten, $jetztStr]);
    }

    /** @param array<string,mixed> $status @param array<string,mixed>|null $gemeldeteStoerung */
    private static function entwarnungPruefen(\PDO $db, string $job, array $status, ?array $gemeldeteStoerung, bool $meldungenAktiv, string $jetztStr): void
    {
        $erfolgsZeit = $job === 'planer' ? (string)($status['letzterLaufAm'] ?? '') : (string)($status['letzterErfolgAm'] ?? '');
        $stoerungSeit = (string)($status['stoerungSeit'] ?? '');

        if ($gemeldeteStoerung !== null) {
            if ($erfolgsZeit === '' || $erfolgsZeit <= (string)$gemeldeteStoerung['erstelltAm']) return;

            $db->beginTransaction();
            try {
                if ($meldungenAktiv) {
                    $schluessel = $job . ':entwarnung:' . $gemeldeteStoerung['id'];
                    $daten = json_encode(['fehlerCode' => '', 'seit' => $gemeldeteStoerung['erstelltAm']], JSON_UNESCAPED_UNICODE) ?: '{}';
                    $db->prepare(
                        "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
                         VALUES (?, ?, 'entwarnung', ?, ?, '', 0, '')
                         ON CONFLICT(schluessel) DO NOTHING"
                    )->execute([$schluessel, $job, $daten, $jetztStr]);
                }
                $db->prepare("UPDATE job_status SET stoerungSeit = '' WHERE job = ?")->execute([$job]);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            return;
        }

        // Keine gemeldete Störung, aber ein stehen gebliebenes stoerungSeit (z. B. erster
        // Fehlschlag ohne je die Fehlgeschlagen-Schwelle zu erreichen) wird nach einem
        // nachfolgenden Erfolg still zurückgesetzt – ohne Mail.
        if ($stoerungSeit !== '' && $erfolgsZeit !== '' && $erfolgsZeit > $stoerungSeit) {
            $db->prepare("UPDATE job_status SET stoerungSeit = '' WHERE job = ?")->execute([$job]);
        }
    }

    /**
     * Jüngster offener Störungseintrag (fehler|ausbleiben) seit der letzten Entwarnung, oder
     * null. Öffentlich, damit Watchdog dieselbe Prüfung für den Job 'planer' nutzt
     * (Reviewbefund 3: keine tägliche Ausbleiben-Mail trotz bereits gemeldeter Störung).
     */
    public static function gemeldeteStoerung(\PDO $db, string $job): ?array
    {
        $stmt = $db->prepare(
            "SELECT * FROM meldungen_outbox
              WHERE job = ? AND typ IN ('fehler', 'ausbleiben')
                AND erstelltAm > COALESCE(
                    (SELECT MAX(erstelltAm) FROM meldungen_outbox WHERE job = ? AND typ = 'entwarnung'), ''
                )
              ORDER BY erstelltAm DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$job, $job]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> job_status-Zeile oder [], legt bei Fehlen eine Basiszeile an. */
    private static function statusLesen(\PDO $db, string $job): array
    {
        $row = self::statusLesenOhneAnlegen($db, $job);
        if ($row !== []) return $row;

        // Neu beobachteter Job ohne bisherigen Lauf (z. B. backupmail/probe_wiederherstellung
        // vor dem ersten Durchlauf) – Basiszeile anlegen, damit UPDATEs greifen.
        $db->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit,
                                      laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm, beobachtetSeit)
             VALUES (?, '', '', '', '', '', '', '', '', 0, '', '')
             ON CONFLICT(job) DO NOTHING"
        )->execute([$job]);
        return [];
    }

    /** @return array<string,mixed> job_status-Zeile oder [], legt keine Zeile an (Reviewbefund 15). */
    private static function statusLesenOhneAnlegen(\PDO $db, string $job): array
    {
        $stmt = $db->prepare('SELECT * FROM job_status WHERE job = ?');
        $stmt->execute([$job]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    private static function planerHatHerzschlagGehabt(\PDO $db): bool
    {
        $stmt = $db->prepare('SELECT letzterLaufAm FROM job_status WHERE job = ?');
        $stmt->execute(['planer']);
        return (string)($stmt->fetchColumn() ?: '') !== '';
    }

    /** @return array<string,mixed> */
    private static function erinnerungSettings(\PDO $db): array
    {
        $row = $db->query('SELECT data FROM erinnerung_settings WHERE id = 1')->fetch();
        return $row ? (json_decode((string)$row['data'], true) ?? []) : [];
    }
}

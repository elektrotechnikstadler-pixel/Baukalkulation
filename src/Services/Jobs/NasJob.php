<?php
namespace App\Services\Jobs;

/**
 * Eigener Prozess für den NAS-Abgleich statt Planer-Schritt (T4-Vertrag Punkt 5): ein
 * NAS-Lauf kann beim ersten Abgleich Stunden dauern. Als Planer-Schritt hielte er dessen
 * Sperre, Überwachung und Zustellung würden so lange ausfallen und der Watchdog meldete
 * fälschlich „Zeitplaner steht“. Aufgerufen per `bin/console nas:abgleich` (eigene
 * Crontab-Zeile, Dockerfile.app, eigene Sperre `DATA_DIR/run/nas.lock`).
 *
 * Fälligkeit/Wiederholungslogik ist dieselbe wie bei Planer::faelligkeit() (F10: bis zu 3
 * Wiederholungen alle 30 min), aber in eigenen, kleinen Methoden – der Planer wird laut
 * Vertrag bewusst nicht erweitert, damit seine 3-Stunden-Hängelauf-Erkennung unverändert
 * bleibt.
 */
final class NasJob
{
    private const WIEDERHOLUNG_MINUTEN = 30;
    private const MAX_VERSUCHE = 4; // Erstversuch + 3 Wiederholungen (F10)
    /** Erstversuch erst, wenn die heutige Serversicherung fertig ist, spätestens nach 2 h (Punkt 5.4). */
    private const SICHERUNG_WARTE_STUNDEN = 2;

    private const FEHLER_TEXTE = [
        'NAS_KONFIGURATION'       => 'Die NAS-Einstellungen sind unvollständig oder ungültig.',
        'NAS_RCLONE_FEHLT'        => 'rclone ist nicht verfügbar.',
        'NAS_ANMELDUNG'           => 'Die Anmeldung an der NAS-Freigabe ist fehlgeschlagen.',
        'NAS_NICHT_ERREICHBAR'    => 'Die NAS ist nicht erreichbar.',
        'NAS_KEINE_SCHREIBRECHTE' => 'Keine Schreibrechte auf der NAS-Freigabe.',
        'NAS_VERBINDUNG'          => 'Die Verbindung zur NAS ist fehlgeschlagen.',
        'NAS_TRANSFER'            => 'Der Dateiabgleich mit der NAS ist fehlgeschlagen.',
        'NAS_ZEIT'                => 'Der NAS-Abgleich hat die Höchstdauer überschritten.',
    ];

    /** @var callable(): \DateTimeImmutable */
    private $uhr;

    public function __construct(private readonly \PDO $db, ?callable $uhr = null)
    {
        $this->uhr = $uhr ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    private function jetzt(): \DateTimeImmutable
    {
        return ($this->uhr)();
    }

    /**
     * Ein Durchlauf (Ablauf laut Vertrag Punkt 5): Herzschlag, Auftrag annehmen, Fälligkeit
     * prüfen (Erstversuch erst nach der heutigen Sicherung bzw. spätestens 2 h nach ihrer
     * Uhrzeit), Abgleich ausführen, Protokoll.
     * @return string|null CLI-Ausgabezeile, null = nichts zu tun.
     */
    public function lauf(NasAbgleich $nasAbgleich): ?string
    {
        $jetzt = $this->jetzt();
        // Reviewbefund 5: die injizierbare Uhr direkt durchreichen (nicht als Closure auf den
        // eingefrorenen Startzeitpunkt) – so fragen Protokoll-Schreibvorgänge nach einem
        // potenziell stundenlangen abgleichen() (s. Klassendoku) die aktuelle Uhrzeit neu ab,
        // statt mit dem Startzeitpunkt zu protokollieren.
        $protokoll = new JobProtokoll($this->db, $this->uhr);
        $protokoll->herzschlag('nas_prozess');

        $einstellungen = SystemEinstellungen::laden($this->db);
        $nasEinstellungen = is_array($einstellungen['nas'] ?? null) ? $einstellungen['nas'] : [];
        $aktiv = (bool)($nasEinstellungen['aktiv'] ?? false);

        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $auftragId = $this->auftragAnnehmen($jetztStr);

        if (!$aktiv && $auftragId === null) {
            return null; // Vertrag Punkt 5.2: "ist nas.aktiv false und kein Auftrag offen -> Ende".
        }

        $heute = $jetzt->format('Y-m-d');
        $status = $protokoll->status('nas');
        $faellig = null;

        if ($aktiv) {
            $einstellungenSicherung = is_array($einstellungen['sicherung'] ?? null) ? $einstellungen['sicherung'] : [];
            $uhrzeitSicherung = (string)($einstellungenSicherung['uhrzeit'] ?? '02:00');
            $faellig = Planer::faelligkeit($status, $uhrzeitSicherung, $jetzt);

            if ($faellig === 'erstversuch' && !$this->sicherungBereit($protokoll, $jetzt, $heute, $uhrzeitSicherung)) {
                $faellig = null; // noch auf die heutige Serversicherung warten
            }
        }

        if ($faellig === null && $auftragId === null) {
            return null;
        }

        $versucheVorher = ((string)($status['tageslaufDatum'] ?? '') === $heute) ? (int)($status['versuche'] ?? 0) : 0;
        $neueVersuche = $versucheVorher + 1;
        $ausloeser = $auftragId !== null ? 'manuell' : 'planer';
        // Wie beim Planer (Reviewbefund 3): start() ohne Datum, sonst würde ein manueller Lauf
        // ohne fälligen Tageslauf (z. B. bei inaktivem Job) tageslaufDatum überschreiben – das
        // Datum setzt ausschließlich tageslaufSetzen() unten, wenn ein Tageslauf tatsächlich läuft.
        $laufId = $protokoll->start('nas', $ausloeser);
        if ($faellig !== null) {
            $this->tageslaufSetzen($heute, 'laufend', $neueVersuche, '');
        }

        try {
            $kennzahlen = $nasAbgleich->abgleichen();
        } catch (\Throwable $e) {
            $code = $e instanceof NasAbgleichFehler ? $e->fehlerCode : 'NAS_TRANSFER';
            error_log('[NasJob] NAS-Abgleich fehlgeschlagen: ' . $e->getMessage());
            $protokoll->fehler($laufId, $code, self::FEHLER_TEXTE[$code] ?? 'Der NAS-Abgleich ist fehlgeschlagen.');
            // Reviewbefund 5: aktuelle Uhr neu abfragen statt des eingefrorenen Startzeitpunkts
            // – abgleichen() kann Stunden gedauert haben, der Backoff zählt ab jetzt.
            $jetztNachAbgleich = $this->jetzt();
            if ($faellig !== null) {
                $endgueltig = $neueVersuche >= self::MAX_VERSUCHE;
                $naechsterVersuchAm = $endgueltig ? '' : $jetztNachAbgleich->modify('+' . self::WIEDERHOLUNG_MINUTEN . ' minutes')->format('Y-m-d H:i:s');
                $this->tageslaufSetzen($heute, $endgueltig ? 'fehlgeschlagen' : 'fehler', $neueVersuche, $naechsterVersuchAm);
            }
            if ($auftragId !== null) {
                $this->auftragAbschliessen($auftragId, 'fehlgeschlagen', ['fehlerCode' => $code], $jetztNachAbgleich);
            }
            return 'nas: fehlgeschlagen (' . $code . ')';
        }

        $protokoll->erfolg($laufId, $kennzahlen);
        // Review Runde 3: ein manueller Lauf ohne fälligen Tageslauf darf den Tageslauf nicht
        // anfassen (Vertrag Punkt 5 gilt nur für den automatischen Lauf) – außer er setzt
        // analog zur Planer-Regel aus T2 einen heute bereits fehlgeschlagenen Tageslauf auf
        // ok (ein erfolgreicher manueller Lauf ersetzt dann den Fehlversuch desselben Tages).
        $heutigerFehlerTageslauf = (string)($status['tageslaufDatum'] ?? '') === $heute
            && in_array((string)($status['tageslaufStatus'] ?? ''), ['fehler', 'fehlgeschlagen'], true);
        if ($faellig !== null || ($auftragId !== null && $heutigerFehlerTageslauf)) {
            $this->tageslaufSetzen($heute, 'ok', 0, '');
        }
        if ($auftragId !== null) {
            // Reviewbefund 5: aktuelle Uhr neu abfragen statt des eingefrorenen Startzeitpunkts.
            $this->auftragAbschliessen($auftragId, 'fertig', ['ergebnis' => $kennzahlen], $this->jetzt());
        }
        return 'nas: ok';
    }

    /**
     * Erstversuch nur, wenn die heutige Serversicherung bereits abgeschlossen ist (ok oder
     * fehlgeschlagen) oder spätestens 2 h nach ihrer eingestellten Uhrzeit – so wird die
     * frische Sicherung in der Regel mit übertragen, ohne auf einen ausbleibenden
     * Sicherungslauf unbegrenzt zu warten.
     */
    private function sicherungBereit(JobProtokoll $protokoll, \DateTimeImmutable $jetzt, string $heute, string $uhrzeitSicherung): bool
    {
        $statusSicherung = $protokoll->status('sicherung');
        $heutigerTageslauf = (string)($statusSicherung['tageslaufDatum'] ?? '') === $heute;
        $abgeschlossen = $heutigerTageslauf && in_array((string)($statusSicherung['tageslaufStatus'] ?? ''), ['ok', 'fehlgeschlagen'], true);
        if ($abgeschlossen) return true;

        $sicherungZeitpunkt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $heute . ' ' . $uhrzeitSicherung, $jetzt->getTimezone());
        if ($sicherungZeitpunkt === false) return true; // ungültige Uhrzeit -> nicht blockieren
        $spaetestens = $sicherungZeitpunkt->modify('+' . self::SICHERUNG_WARTE_STUNDEN . ' hours');
        return $jetzt >= $spaetestens;
    }

    /** UPDATE … WHERE status = 'wartend' + rowCount()-Prüfung (E-003) gegen gleichzeitige Läufe. */
    private function auftragAnnehmen(string $jetztStr): ?string
    {
        $stmt = $this->db->prepare("SELECT id FROM job_auftraege WHERE job = 'nas' AND status = 'wartend' ORDER BY erstelltAm ASC LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false) return null;

        $upd = $this->db->prepare("UPDATE job_auftraege SET status = 'laufend', aktualisiertAm = ? WHERE id = ? AND status = 'wartend'");
        $upd->execute([$jetztStr, $id]);
        return $upd->rowCount() === 1 ? (string)$id : null;
    }

    /** @param array<string,mixed> $ergebnis */
    private function auftragAbschliessen(string $id, string $status, array $ergebnis, \DateTimeImmutable $jetzt): void
    {
        $this->db->prepare('UPDATE job_auftraege SET status = ?, aktualisiertAm = ?, ergebnis = ? WHERE id = ?')
            ->execute([$status, $jetzt->format('Y-m-d H:i:s'), json_encode($ergebnis, JSON_UNESCAPED_UNICODE) ?: '{}', $id]);
    }

    private function tageslaufSetzen(string $datum, string $status, int $versuche, string $naechsterVersuchAm): void
    {
        $this->db->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit,
                                      laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm)
             VALUES ('nas', '', '', '', '', '', '', '', '', 0, '')
             ON CONFLICT(job) DO NOTHING"
        )->execute();
        $this->db->prepare(
            "UPDATE job_status SET tageslaufDatum = ?, tageslaufStatus = ?, versuche = ?, naechsterVersuchAm = ? WHERE job = 'nas'"
        )->execute([$datum, $status, $versuche, $naechsterVersuchAm]);
    }
}

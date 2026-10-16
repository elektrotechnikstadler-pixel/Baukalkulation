<?php
namespace App\Services\Jobs;

/**
 * Zeitgesteuerter Tageslauf „sicherung“ (F8) mit bis zu 3 Wiederholungen alle 30 min (F10).
 * Wird per Konsole `system:planer` jede Minute aufgerufen (Dockerfile.app Crontab).
 *
 * tageslaufStatus-Werte: '' (noch kein Versuch), 'laufend', 'ok',
 * 'fehler' (Fehlschlag, Wiederholung folgt) und 'fehlgeschlagen' (alle Versuche gescheitert).
 */
final class Planer
{
    private const MAX_VERSUCHE = 4; // Erstversuch + 3 Wiederholungen
    private const WIEDERHOLUNG_MINUTEN = 30;
    private const HAENGENDER_LAUF_STUNDEN = 3;
    private const SPERR_WARTE_SEKUNDEN = 30; // wie sicherung:erstellen – kein unbegrenztes Blockieren

    /**
     * Reine Funktion ohne Seiteneffekte – testbar ohne DB.
     * @param array<string,mixed> $status aktuelle job_status-Zeile des Jobs (oder [])
     * @return string|null 'erstversuch'|'wiederholung'|null
     */
    public static function faelligkeit(array $status, string $uhrzeit, \DateTimeImmutable $jetzt): ?string
    {
        $heute = $jetzt->format('Y-m-d');
        $tageslaufDatum = (string)($status['tageslaufDatum'] ?? '');
        $tageslaufStatus = (string)($status['tageslaufStatus'] ?? '');
        $versuche = (int)($status['versuche'] ?? 0);
        $naechsterVersuchAm = (string)($status['naechsterVersuchAm'] ?? '');

        if ($tageslaufDatum !== $heute) {
            // Neuer Tag: erster Versuch, sobald die Uhrzeit erreicht ist (auch nachgeholt).
            return $jetzt->format('H:i') >= $uhrzeit ? 'erstversuch' : null;
        }
        if ($tageslaufStatus === 'ok' || $tageslaufStatus === 'fehlgeschlagen') {
            return null; // Heutiger Tageslauf bereits abgeschlossen.
        }
        if ($versuche >= self::MAX_VERSUCHE) {
            return null;
        }
        if ($naechsterVersuchAm !== '' && $jetzt->format('Y-m-d H:i:s') < $naechsterVersuchAm) {
            return null; // 30-Minuten-Abstand noch nicht erreicht.
        }
        return $versuche > 0 ? 'wiederholung' : 'erstversuch';
    }

    /**
     * Ein Planer-Durchlauf. Reihenfolge laut Vertrag: Herzschlag, hängende Läufe abschließen,
     * abgelaufene Aufträge markieren, wartenden Auftrag annehmen, Job ausführen, aufräumen.
     * @return list<string> Meldungen für die CLI-Ausgabe
     */
    public function lauf(\PDO $db, \DateTimeImmutable $jetzt): array
    {
        $protokoll = new JobProtokoll($db, fn() => $jetzt);
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $zeilen = [];

        // 1. Herzschlag
        $protokoll->herzschlag('planer');

        // 2. Hängende Läufe (laeuftSeit älter als 3 h) abbrechen
        $this->haengendeLaeufeAbschliessen($db, $jetzt);

        // 3. Abgelaufene wartende Aufträge markieren
        $db->prepare(
            "UPDATE job_auftraege SET status = 'abgelaufen', aktualisiertAm = ?
             WHERE status = 'wartend' AND ablaufAm <> '' AND ablaufAm < ?"
        )->execute([$jetztStr, $jetztStr]);

        // 4. Wartenden Auftrag für 'sicherung' annehmen (falls vorhanden)
        $auftragId = $this->auftragAnnehmen($db, 'sicherung', $jetztStr);

        // 5. Planmäßigen Job 'sicherung' ausführen
        $einstellungen = SystemEinstellungen::laden($db);
        $uhrzeit = (string)($einstellungen['sicherung']['uhrzeit'] ?? '02:00');
        $status = $protokoll->status('sicherung');
        $faellig = self::faelligkeit($status, $uhrzeit, $jetzt);

        if ($auftragId !== null || $faellig !== null) {
            $zeilen[] = $this->sicherungAusfuehren($db, $protokoll, $jetzt, $status, $auftragId, $faellig);
        }

        // 6. Laufprotokoll aufräumen
        $protokoll->aufraeumen();

        return $zeilen;
    }

    private function sicherungAusfuehren(
        \PDO $db,
        JobProtokoll $protokoll,
        \DateTimeImmutable $jetzt,
        array $status,
        ?string $auftragId,
        ?string $faellig,
    ): string {
        $heute = $jetzt->format('Y-m-d');
        $tageslaufHeute = ($status['tageslaufDatum'] ?? '') === $heute;
        $versucheVorher = $tageslaufHeute ? (int)($status['versuche'] ?? 0) : 0;
        $neueVersuche = $versucheVorher + 1;
        $ausloeser = $auftragId !== null ? 'manuell' : 'planer';

        $laufId = $protokoll->start('sicherung', $ausloeser);
        if ($faellig !== null) {
            $this->tageslaufSetzen($db, 'sicherung', $heute, 'laufend', $neueVersuche, '');
        }

        try {
            $ergebnis = Tagessicherung::erstelle($db, $jetzt, self::SPERR_WARTE_SEKUNDEN);
        } catch (\Throwable $e) {
            error_log('[Planer] Tagessicherung fehlgeschlagen: ' . $e->getMessage());
            $this->sicherungFehlschlagBehandeln($db, $protokoll, $jetzt, $heute, $laufId, $faellig, $auftragId, $neueVersuche, 'SICHERUNG_FEHLER', 'Tagessicherung fehlgeschlagen.');
            return 'sicherung: fehlgeschlagen';
        }

        // Eine gehaltene Sperre ist kein Erfolg – sonst würde eine durch einen anderen Vorgang
        // (z. B. gleichzeitiges Speichern) gesperrte Tagessicherung fälschlich als "ok" gezählt.
        if ($ergebnis === Tagessicherung::GESPERRT) {
            $this->sicherungFehlschlagBehandeln($db, $protokoll, $jetzt, $heute, $laufId, $faellig, $auftragId, $neueVersuche, 'SICHERUNG_GESPERRT', 'Tagessicherung war durch einen anderen Vorgang gesperrt.');
            return 'sicherung: gesperrt';
        }

        $protokoll->erfolg($laufId, ['ergebnis' => $ergebnis]);
        if ($faellig !== null || $auftragId !== null) {
            $this->tageslaufSetzen($db, 'sicherung', $heute, 'ok', 0, '');
        }
        if ($auftragId !== null) {
            $this->auftragAbschliessen($db, $auftragId, 'fertig', ['ergebnis' => $ergebnis], $jetzt);
        }
        return "sicherung: {$ergebnis}";
    }

    /**
     * Gemeinsame Fehlschlag-Behandlung für eine gescheiterte oder gesperrte Tagessicherung:
     * Protokoll-Fehler, Tageslauf-Wiederholung (wie bei Fehler, bis zu 4 Versuche) bzw.
     * manueller Auftrag → 'fehlgeschlagen' mit Fehlercode.
     */
    private function sicherungFehlschlagBehandeln(
        \PDO $db,
        JobProtokoll $protokoll,
        \DateTimeImmutable $jetzt,
        string $heute,
        int $laufId,
        ?string $faellig,
        ?string $auftragId,
        int $neueVersuche,
        string $fehlerCode,
        string $fehlerMeldung,
    ): void {
        $protokoll->fehler($laufId, $fehlerCode, $fehlerMeldung);
        if ($faellig !== null) {
            $endgueltig = $neueVersuche >= self::MAX_VERSUCHE;
            $naechsterVersuchAm = $endgueltig ? '' : $jetzt->modify('+' . self::WIEDERHOLUNG_MINUTEN . ' minutes')->format('Y-m-d H:i:s');
            $this->tageslaufSetzen($db, 'sicherung', $heute, $endgueltig ? 'fehlgeschlagen' : 'fehler', $neueVersuche, $naechsterVersuchAm);
        }
        if ($auftragId !== null) {
            $this->auftragAbschliessen($db, $auftragId, 'fehlgeschlagen', ['fehlerCode' => $fehlerCode], $jetzt);
        }
    }

    private function tageslaufSetzen(\PDO $db, string $job, string $datum, string $status, int $versuche, string $naechsterVersuchAm): void
    {
        $db->prepare(
            'UPDATE job_status SET tageslaufDatum = ?, tageslaufStatus = ?, versuche = ?, naechsterVersuchAm = ? WHERE job = ?'
        )->execute([$datum, $status, $versuche, $naechsterVersuchAm, $job]);
    }

    private function haengendeLaeufeAbschliessen(\PDO $db, \DateTimeImmutable $jetzt): void
    {
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $grenze = $jetzt->modify('-' . self::HAENGENDER_LAUF_STUNDEN . ' hours')->format('Y-m-d H:i:s');
        $ergebnisAbgebrochen = json_encode(['fehlerCode' => 'AUFTRAG_ABGEBROCHEN'], JSON_UNESCAPED_UNICODE) ?: '{}';

        $stmt = $db->prepare("SELECT job FROM job_status WHERE laeuftSeit <> '' AND laeuftSeit < ?");
        $stmt->execute([$grenze]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $job) {
            $db->prepare("UPDATE job_laeufe SET status = 'abgebrochen', beendetAm = ? WHERE job = ? AND status = 'laufend'")
                ->execute([$jetztStr, $job]);
            $db->prepare("UPDATE job_status SET laeuftSeit = '' WHERE job = ?")->execute([$job]);
            // Ein zum hängenden Job gehörender manueller Auftrag darf nicht "laufend" bleiben,
            // sonst wartet die UI unbegrenzt auf ein Ergebnis, das nie kommt.
            $db->prepare("UPDATE job_auftraege SET status = 'fehlgeschlagen', aktualisiertAm = ?, ergebnis = ? WHERE job = ? AND status = 'laufend'")
                ->execute([$jetztStr, $ergebnisAbgebrochen, $job]);
        }

        // Sicherheitsnetz unabhängig von job_status: auch Aufträge, die seit über 3 h
        // "laufend" sind (aktualisiertAm), aber keinem (mehr) hängenden Job zugeordnet wurden.
        $db->prepare("UPDATE job_auftraege SET status = 'fehlgeschlagen', aktualisiertAm = ?, ergebnis = ? WHERE status = 'laufend' AND aktualisiertAm < ?")
            ->execute([$jetztStr, $ergebnisAbgebrochen, $grenze]);
    }

    /** UPDATE … WHERE id = ? AND status = 'wartend' + rowCount()-Prüfung (E-003) gegen gleichzeitige Planer-Läufe. */
    private function auftragAnnehmen(\PDO $db, string $job, string $jetztStr): ?string
    {
        $stmt = $db->prepare("SELECT id FROM job_auftraege WHERE job = ? AND status = 'wartend' ORDER BY erstelltAm ASC LIMIT 1");
        $stmt->execute([$job]);
        $id = $stmt->fetchColumn();
        if ($id === false) return null;

        $upd = $db->prepare("UPDATE job_auftraege SET status = 'laufend', aktualisiertAm = ? WHERE id = ? AND status = 'wartend'");
        $upd->execute([$jetztStr, $id]);
        return $upd->rowCount() === 1 ? (string)$id : null;
    }

    /** @param array<string,mixed> $ergebnis */
    private function auftragAbschliessen(\PDO $db, string $id, string $status, array $ergebnis, \DateTimeImmutable $jetzt): void
    {
        $db->prepare('UPDATE job_auftraege SET status = ?, aktualisiertAm = ?, ergebnis = ? WHERE id = ?')
            ->execute([$status, $jetzt->format('Y-m-d H:i:s'), json_encode($ergebnis, JSON_UNESCAPED_UNICODE) ?: '{}', $id]);
    }
}

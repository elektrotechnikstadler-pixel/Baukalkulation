<?php
namespace App\Services\Jobs;

/**
 * Healthcheck-Watchdog (T3, R8, AP-20261012-datensicherung-nas): erkennt einen
 * stehenden Planer, auch wenn kein Admin die App aufruft (Apache läuft, Cron/Container
 * steht). Wird aus AuthActions::check() aufgerufen – komplett fehlertolerant (E-022) und
 * auf max. alle 10 Minuten gedrosselt. Zustellt nur, solange der Planer selbst nicht läuft
 * (Herzschlag älter als PLANER_SCHWELLE_MINUTEN bzw. noch nie gelaufen) – sonst übernimmt
 * der Planer selbst Alarm und Zustellung (Reviewbefund 1, verhindert Doppelzustellung); ein
 * festes Zeitbudget (ZUSTELL_BUDGET_SEKUNDEN) sorgt zusätzlich dafür, dass ein einzelner
 * Healthcheck-Request nie durch einen hängenden Mailversand ausgebremst wird.
 */
final class Watchdog
{
    private const DROSSEL_SEKUNDEN = 600;   // höchstens alle 10 min
    private const PLANER_SCHWELLE_MINUTEN = 15;
    private const ZUSTELL_TIMEOUT_SEKUNDEN = 4;
    private const ZUSTELL_BUDGET_SEKUNDEN = 4;

    /**
     * @param callable(array<string,mixed>,string,string,string):void|null $absender Standard:
     *        MailService::send; injizierbar für Tests (E-034).
     */
    public static function vielleichtPruefen(\PDO $db, \DateTimeImmutable $jetzt, ?callable $absender = null): void
    {
        $runDir = rtrim(DATA_DIR, '/\\') . '/run';
        if (!is_dir($runDir) && !mkdir($runDir, 0750, true) && !is_dir($runDir)) {
            return;
        }

        $stampPfad = $runDir . '/watchdog.stamp';
        if ($jetzt->getTimestamp() - self::stampLesen($stampPfad) < self::DROSSEL_SEKUNDEN) {
            return;
        }

        // Einstellungen erst nach der ungesicherten Stamp-Drosselung laden – bei jedem
        // check()-Aufruf (meist gedrosselt) werden sie sonst unnötig geladen (Reviewbefund N3).
        $einstellungen = SystemEinstellungen::laden($db);
        if (!(bool)($einstellungen['ueberwachung']['aktiv'] ?? true)) {
            return; // Überwachung abgeschaltet: keine Outbox-Einträge, keine Zustellung (Reviewbefund 4).
        }

        $lock = fopen($runDir . '/watchdog.lock', 'c');
        if ($lock === false) {
            return;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }

        try {
            // Zeitstempel nach dem Erhalt der Sperre erneut lesen: ein zweiter Prozess kann
            // zwischen dem ungesicherten Lesen oben und dem Sperren bereits gelaufen sein
            // (Reviewbefund 4).
            if ($jetzt->getTimestamp() - self::stampLesen($stampPfad) < self::DROSSEL_SEKUNDEN) {
                return;
            }
            file_put_contents($stampPfad, (string)$jetzt->getTimestamp(), LOCK_EX);

            $planerLaeuftNicht = self::planerHerzschlagPruefen($db, $jetzt);
            if ($planerLaeuftNicht) {
                // Läuft der Planer, stellt nur er zu (Reviewbefund 1) – sonst hier übernehmen.
                (new MeldungsZustellung($absender))->zustellen($db, $jetzt, 1, self::ZUSTELL_TIMEOUT_SEKUNDEN, self::ZUSTELL_BUDGET_SEKUNDEN);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function stampLesen(string $stampPfad): int
    {
        return is_file($stampPfad) ? (int)trim((string)file_get_contents($stampPfad)) : 0;
    }

    /** @return bool true, wenn der Planer nicht (mehr) aktiv läuft (Herzschlag fehlt oder älter als die Schwelle). */
    private static function planerHerzschlagPruefen(\PDO $db, \DateTimeImmutable $jetzt): bool
    {
        $stmt = $db->prepare('SELECT letzterLaufAm FROM job_status WHERE job = ?');
        $stmt->execute(['planer']);
        $herzschlag = (string)($stmt->fetchColumn() ?: '');
        if ($herzschlag === '') {
            return true; // Noch nie gelaufen – kein Alarm (frische/Test-Installation), aber Watchdog übernimmt die Zustellung.
        }

        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $alterMinuten = (strtotime($jetztStr) - strtotime($herzschlag)) / 60;
        if ($alterMinuten <= self::PLANER_SCHWELLE_MINUTEN) {
            return false; // Planer läuft: er übernimmt selbst Alarm und Zustellung.
        }

        $db->prepare("UPDATE job_status SET stoerungSeit = ? WHERE job = 'planer' AND stoerungSeit = ''")
            ->execute([$jetztStr]);

        // Solange bereits eine offene (noch nicht entwarnte) Störung existiert, keine weitere
        // tägliche Ausbleiben-Mail erzeugen (Reviewbefund 3) – dieselbe Prüfung wie Ueberwachung.
        if (Ueberwachung::gemeldeteStoerung($db, 'planer') === null) {
            $heute = $jetzt->format('Y-m-d');
            $daten = json_encode(['fehlerCode' => '', 'seit' => $herzschlag], JSON_UNESCAPED_UNICODE) ?: '{}';
            $db->prepare(
                "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
                 VALUES (?, 'planer', 'ausbleiben', ?, ?, '', 0, '')
                 ON CONFLICT(schluessel) DO NOTHING"
            )->execute(['planer:' . $heute . ':ausbleiben', $daten, $jetztStr]);
        }

        return true;
    }
}

<?php
namespace App\Services\Jobs;

/**
 * Laufprotokoll der System-Jobs (sicherung, backupmail, planer, später nas, …).
 *
 * job_status enthält je Job genau eine Zeile mit dem aktuellen Zustand
 * (Herzschlag, letzter Erfolg/Fehler, Tageslauf-Zähler); job_laeufe ist das
 * Verlaufsprotokoll (ein Eintrag je tatsächlichem Ausführungsversuch).
 *
 * fehler() speichert nur feste Codes/Texte – Ausnahmedetails gehen ausschließlich
 * per error_log() an den Aufrufer (E-014).
 */
final class JobProtokoll
{
    /** @var callable|null */
    private $uhr;

    public function __construct(private readonly \PDO $db, ?callable $uhr = null)
    {
        $this->uhr = $uhr;
    }

    private function jetzt(): \DateTimeImmutable
    {
        return $this->uhr !== null ? ($this->uhr)() : new \DateTimeImmutable();
    }

    private function jetztStr(): string
    {
        return $this->jetzt()->format('Y-m-d H:i:s');
    }

    /**
     * Legt einen job_status-Datensatz an, falls noch keiner existiert.
     * $tageslaufSchluessel ist optional und dient Jobs ohne eigene Tages-Orchestrierung
     * (z. B. backupmail) als Zyklus-Kennung für die Fehler-Entdopplung in status().
     */
    public function start(string $job, string $ausloeser, ?string $tageslaufSchluessel = null): int
    {
        $jetzt = $this->jetztStr();
        $this->sicherstellen($job);

        $this->db->prepare(
            "INSERT INTO job_laeufe (job, ausloeser, gestartetAm, beendetAm, status, fehlerCode, meldung, kennzahlen)
             VALUES (?, ?, ?, '', 'laufend', '', '', '{}')"
        )->execute([$job, $ausloeser, $jetzt]);
        $laufId = (int)$this->db->lastInsertId();

        if ($tageslaufSchluessel !== null) {
            $this->db->prepare('UPDATE job_status SET laeuftSeit = ?, letzterLaufAm = ?, tageslaufDatum = ? WHERE job = ?')
                ->execute([$jetzt, $jetzt, $tageslaufSchluessel, $job]);
        } else {
            $this->db->prepare('UPDATE job_status SET laeuftSeit = ?, letzterLaufAm = ? WHERE job = ?')
                ->execute([$jetzt, $jetzt, $job]);
        }
        return $laufId;
    }

    /** @param array<string,mixed> $kennzahlen nur unkritische Kennzahlen, keine Secrets/Pfade mit Zugangsdaten */
    public function erfolg(int $laufId, array $kennzahlen = []): void
    {
        $jetzt = $this->jetztStr();
        $job = $this->jobVonLauf($laufId);
        if ($job === null) return;

        $json = json_encode($kennzahlen, JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->db->prepare('UPDATE job_laeufe SET beendetAm = ?, status = ?, kennzahlen = ? WHERE id = ?')
            ->execute([$jetzt, 'erfolg', $json, $laufId]);
        $this->db->prepare(
            "UPDATE job_status SET letzterErfolgAm = ?, laeuftSeit = '', fehlerCode = '', versuche = 0, naechsterVersuchAm = ''
             WHERE job = ?"
        )->execute([$jetzt, $job]);
    }

    /** $code/$meldung: ausschließlich feste, deutsche Texte (E-014). */
    public function fehler(int $laufId, string $code, string $meldung): void
    {
        $jetzt = $this->jetztStr();
        $job = $this->jobVonLauf($laufId);
        if ($job === null) return;

        $this->db->prepare('UPDATE job_laeufe SET beendetAm = ?, status = ?, fehlerCode = ?, meldung = ? WHERE id = ?')
            ->execute([$jetzt, 'fehler', $code, $meldung, $laufId]);
        $this->db->prepare(
            "UPDATE job_status SET letzterFehlerAm = ?, fehlerCode = ?, laeuftSeit = ''
             WHERE job = ?"
        )->execute([$jetzt, $code, $job]);
        // Erster Fehlschlag einer laufenden Störung markieren; Entwarnung/Reset macht die
        // Überwachung (T3), wenn der Job danach wieder erfolgreich war.
        $this->db->prepare("UPDATE job_status SET stoerungSeit = ? WHERE job = ? AND stoerungSeit = ''")
            ->execute([$jetzt, $job]);
    }

    /**
     * Aktualisiert nur letzterFehlerAm, ohne einen neuen job_laeufe-Eintrag anzulegen.
     * Für Jobs, die sehr häufig laufen (z. B. backupmail alle 15 min) und bei anhaltend
     * demselben Fehler im selben Zyklus keinen neuen Protokolleintrag erzeugen sollen.
     */
    public function fehlerZeitAktualisieren(string $job): void
    {
        $this->sicherstellen($job);
        $this->db->prepare('UPDATE job_status SET letzterFehlerAm = ? WHERE job = ?')
            ->execute([$this->jetztStr(), $job]);
    }

    /** Herzschlag für Jobs ohne eigenen Lauf-Datensatz (z. B. der Planer selbst). */
    public function herzschlag(string $job): void
    {
        $this->sicherstellen($job);
        $this->db->prepare('UPDATE job_status SET letzterLaufAm = ? WHERE job = ?')
            ->execute([$this->jetztStr(), $job]);
    }

    /** @return array<string,mixed> leeres Array, wenn der Job noch nie lief */
    public function status(string $job): array
    {
        $stmt = $this->db->prepare('SELECT * FROM job_status WHERE job = ?');
        $stmt->execute([$job]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    /** @return list<array<string,mixed>> */
    public function letzteLaeufe(int $limit, ?string $job = null): array
    {
        $limit = max(1, min(200, $limit));
        if ($job !== null) {
            $stmt = $this->db->prepare("SELECT * FROM job_laeufe WHERE job = ? ORDER BY gestartetAm DESC, id DESC LIMIT {$limit}");
            $stmt->execute([$job]);
        } else {
            $stmt = $this->db->query("SELECT * FROM job_laeufe ORDER BY gestartetAm DESC, id DESC LIMIT {$limit}");
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['kennzahlen'] = json_decode((string)($row['kennzahlen'] ?? '{}'), true) ?? [];
        }
        return $rows;
    }

    /** Entfernt Laufprotokoll-Einträge älter als $tage Tage. */
    public function aufraeumen(int $tage = 90): void
    {
        $grenze = $this->jetzt()->modify("-{$tage} days")->format('Y-m-d H:i:s');
        $this->db->prepare('DELETE FROM job_laeufe WHERE gestartetAm < ?')->execute([$grenze]);
    }

    private function sicherstellen(string $job): void
    {
        $this->db->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit,
                                      laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm)
             VALUES (?, '', '', '', '', '', '', '', '', 0, '')
             ON CONFLICT(job) DO NOTHING"
        )->execute([$job]);
    }

    private function jobVonLauf(int $laufId): ?string
    {
        $stmt = $this->db->prepare('SELECT job FROM job_laeufe WHERE id = ?');
        $stmt->execute([$laufId]);
        $job = $stmt->fetchColumn();
        return $job === false ? null : (string)$job;
    }
}

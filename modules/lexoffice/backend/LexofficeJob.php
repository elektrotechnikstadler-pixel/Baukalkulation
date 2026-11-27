<?php
// ============================================================
// Lexware Office – automatischer Abgleich (AP-20261126, B6)
// ============================================================
// Vom Zeitplaner (`system:planer`, jede Minute) aufgerufen; läuft
// höchstens stündlich und nur in Betriebsart Lexware mit geprüftem
// Konto. Spiegelt die Belege aller Projekte mit noch offenen Belegen
// (nur lesend, Zeitbudget, älteste Prüfung zuerst). Zugriffsfehler
// (Schlüssel/Konto) werden sofort als Störung gemeldet, andere Fehler
// erst nach 24 h (Ueberwachung).
// ============================================================

namespace App\Modules\Lexoffice;

use App\Auth;
use App\Services\Jobs\JobProtokoll;
use App\Services\RechnungsModus;

require_once __DIR__ . '/LexofficeTransport.php';
require_once __DIR__ . '/LexofficeClient.php';
require_once __DIR__ . '/LexofficeMapper.php';
require_once __DIR__ . '/LexofficeSync.php';
require_once __DIR__ . '/LexofficeBeleg.php';
require_once __DIR__ . '/LexofficeProjekt.php';

final class LexofficeJob
{
    public const JOB = 'lexoffice';
    public const FEHLER_ZUGANG = 'LEXWARE_ZUGANG';
    public const FEHLER_ABGLEICH = 'LEXWARE_ABGLEICH';
    private const INTERVALL_SEK = 3600;
    private const BUDGET_SEK = 40;
    private const ZUGANGSFEHLER = ['schluessel_fehlt', 'schluessel_ungueltig', 'tarif', 'anderes_konto', 'nicht_geprueft'];
    /** Lexware insgesamt gestört – weitere Projekte in diesem Lauf nicht anfragen. */
    private const ABBRUCHFEHLER = ['rate_limit', 'nicht_erreichbar'];

    /**
     * Gemeinsam mit der Überwachung: jede Betriebsart Lexware. Fehlt die Verbindungsprüfung (z. B. nach
     * Schlüsselwechsel), meldet der Lauf `nicht_geprueft` als Zugangsfehler statt still zu pausieren.
     */
    public static function aktiv(array $settings): bool
    {
        return RechnungsModus::inLexware($settings);
    }

    /** @return string|null Zeile für die Planer-Ausgabe */
    public static function lauf(\PDO $db, \DateTimeImmutable $jetzt): ?string
    {
        $settings = Auth::loadSettings($db);
        if (!self::aktiv($settings)) {
            return null;
        }
        $protokoll = new JobProtokoll($db, fn() => $jetzt);
        $vorher = $protokoll->status(self::JOB);
        $letzter = (string) ($vorher['letzterLaufAm'] ?? '');
        if ($letzter !== '' && $jetzt->getTimestamp() - strtotime($letzter) < self::INTERVALL_SEK) {
            return null;
        }
        $protokoll->herzschlag(self::JOB); // letzterLaufAm – auch bei Abbruch erst in einer Stunde erneut

        $ende = microtime(true) + self::BUDGET_SEK;
        $geprueft = 0;
        $fehler = 0;
        $code = '';
        try {
            $sync = LexofficeSync::fuer($db, $settings);
            $sync->kontoPruefen(); // auch ohne offene Projekte: gesperrter Schlüssel fällt binnen einer Stunde auf
            $projekt = new LexofficeProjekt($db, $sync);
            foreach (self::offeneProjekte($db, $sync->organisationId()) as $baustelleId) {
                if (microtime(true) > $ende) {
                    break; // Rest beim nächsten Lauf – älteste Prüfung zuerst
                }
                try {
                    $projekt->abgleichen($baustelleId, true);
                    $geprueft++;
                } catch (LexofficeFehler $e) {
                    if (in_array($e->fehlerCode, self::ZUGANGSFEHLER, true) || in_array($e->fehlerCode, self::ABBRUCHFEHLER, true)) {
                        throw $e;
                    }
                    error_log('[Lexoffice] Abgleich Projekt ' . $baustelleId . ': ' . $e->fehlerCode);
                    $fehler++;
                }
            }
            if ($fehler > 0) {
                $code = self::FEHLER_ABGLEICH;
            }
        } catch (LexofficeFehler $e) {
            error_log('[Lexoffice] Automatischer Abgleich: ' . $e->fehlerCode);
            $code = in_array($e->fehlerCode, self::ZUGANGSFEHLER, true) ? self::FEHLER_ZUGANG : self::FEHLER_ABGLEICH;
        } catch (\Throwable $e) {
            // Ohne Ausbleiben-Alarm muss jeder Dauerfehler im Jobstatus landen (sonst bleibt die Ampel grün).
            error_log('[Lexoffice] Automatischer Abgleich: ' . get_class($e));
            $code = self::FEHLER_ABGLEICH;
        }

        self::ergebnisSetzen($db, $protokoll, $vorher, $code, $jetzt);
        return $code === ''
            ? "lexoffice: {$geprueft} Projekte abgeglichen"
            : "lexoffice: fehlgeschlagen ({$code})";
    }

    /**
     * Projekte mit mindestens einem Beleg, der sich noch ändern kann; ein angenommenes Angebot nur, solange
     * es noch keine Schlussrechnung gibt (sonst entstehen keine neuen Abschläge mehr).
     * @return list<int>
     */
    public static function offeneProjekte(\PDO $db, string $org): array
    {
        [$offen, $offenParam] = LexofficeProjekt::offenSql('b.');
        $stmt = $db->prepare("SELECT b.baustelleId, MIN(b.geprueftAm) AS g FROM lexoffice_belege b
            WHERE b.organisationId = ? AND b.baustelleId IS NOT NULL AND {$offen}
              AND NOT (b.belegart = 'quotation' AND b.status = 'accepted' AND EXISTS (
                  SELECT 1 FROM lexoffice_belege s WHERE s.baustelleId = b.baustelleId AND s.organisationId = b.organisationId
                    AND s.schlussrechnung = 1 AND COALESCE(s.status, '') NOT IN ('voided', 'geloescht')))
            GROUP BY b.baustelleId ORDER BY g, b.baustelleId");
        $stmt->execute([$org, ...$offenParam]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Laufprotokoll nur bei Zustandswechsel (wie Speicherplatz), sonst nur Zeitstempel – ein stündlicher Job
     * soll die Laufliste der Serverjobs nicht verdrängen.
     * @param array<string,mixed> $vorher
     */
    private static function ergebnisSetzen(\PDO $db, JobProtokoll $protokoll, array $vorher, string $code, \DateTimeImmutable $jetzt): void
    {
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        if ($code !== (string) ($vorher['fehlerCode'] ?? '')) {
            $laufId = $protokoll->start(self::JOB, 'planer');
            if ($code === '') {
                $protokoll->erfolg($laufId);
            } else {
                $protokoll->fehler($laufId, $code, self::meldung($code));
            }
            return;
        }
        if ($code === '') {
            $db->prepare('UPDATE job_status SET letzterErfolgAm = ? WHERE job = ?')->execute([$jetztStr, self::JOB]);
            return;
        }
        $db->prepare('UPDATE job_status SET letzterFehlerAm = ? WHERE job = ?')->execute([$jetztStr, self::JOB]);
        $db->prepare("UPDATE job_status SET stoerungSeit = ? WHERE job = ? AND stoerungSeit = ''")->execute([$jetztStr, self::JOB]);
    }

    /** Feste Texte (E-014) – gleichlautend in MeldungsZustellung::FEHLER_TEXTE. */
    private static function meldung(string $code): string
    {
        return $code === self::FEHLER_ZUGANG
            ? 'Kein Zugriff auf Lexware Office (Verbindung nicht geprüft, API-Schlüssel abgelehnt, Tarif oder anderes Konto).'
            : 'Einzelne Projekte konnten nicht mit Lexware Office abgeglichen werden.';
    }
}

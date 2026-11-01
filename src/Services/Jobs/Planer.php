<?php
namespace App\Services\Jobs;

/**
 * Zeitgesteuerter Tageslauf „sicherung“ (F8) mit bis zu 3 Wiederholungen alle 30 min (F10).
 * Seit T3 zusätzlich: monatliche Wiederherstellungs-Probe, stündliche Speicherplatzprüfung,
 * Überwachung/Systemmeldungen und deren Zustellung (AP-20261012-datensicherung-nas).
 * Wird per Konsole `system:planer` jede Minute aufgerufen (Dockerfile.app Crontab).
 *
 * tageslaufStatus-Werte: '' (noch kein Versuch), 'laufend', 'ok',
 * 'fehler' (Fehlschlag, Wiederholung folgt), 'fehlgeschlagen' (alle Versuche gescheitert) und
 * 'uebersprungen' (Probe-Baseline bei Neuinstallation, s. probeBaselineSetzen(), E-021).
 */
final class Planer
{
    private const MAX_VERSUCHE = 4; // Erstversuch + 3 Wiederholungen
    private const WIEDERHOLUNG_MINUTEN = 30;
    private const HAENGENDER_LAUF_STUNDEN = 3;
    private const SPERR_WARTE_SEKUNDEN = 30; // wie sicherung:erstellen – kein unbegrenztes Blockieren
    private const PROBE_UHRZEIT = '04:00'; // O5: monatlich ab 04:00
    private const SPEICHERPLATZ_INTERVALL_SEKUNDEN = 3600; // höchstens einmal je Stunde

    /**
     * Reine Funktion ohne Seiteneffekte – testbar ohne DB.
     * @param array<string,mixed> $status aktuelle job_status-Zeile des Jobs (oder [])
     * @param string $periode 'tag' (Standard, tageslaufDatum = Y-m-d) oder 'monat' (Y-m) – T3 Vertrag Punkt 5
     * @return string|null 'erstversuch'|'wiederholung'|null
     */
    public static function faelligkeit(array $status, string $uhrzeit, \DateTimeImmutable $jetzt, string $periode = 'tag'): ?string
    {
        $heute = $periode === 'monat' ? $jetzt->format('Y-m') : $jetzt->format('Y-m-d');
        $tageslaufDatum = (string)($status['tageslaufDatum'] ?? '');
        $tageslaufStatus = (string)($status['tageslaufStatus'] ?? '');
        $versuche = (int)($status['versuche'] ?? 0);
        $naechsterVersuchAm = (string)($status['naechsterVersuchAm'] ?? '');

        if ($tageslaufDatum !== $heute) {
            // Neue Periode: erster Versuch, sobald die Uhrzeit erreicht ist (auch nachgeholt).
            return $jetzt->format('H:i') >= $uhrzeit ? 'erstversuch' : null;
        }
        if ($tageslaufStatus === 'ok' || $tageslaufStatus === 'fehlgeschlagen' || $tageslaufStatus === 'uebersprungen') {
            return null; // Heutiger Tageslauf bereits abgeschlossen (bzw. Baseline, Reviewbefund 5).
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
     * Ein Planer-Durchlauf. Reihenfolge laut T3-Vertrag (Punkt 5): Herzschlag, hängende
     * Läufe/abgelaufene Aufträge, Aufträge annehmen (sicherung + probe_wiederherstellung),
     * sicherung, probe_wiederherstellung, speicherplatz, Überwachung, Zustellung, Aufräumen.
     * Jeder Schritt läuft in eigenem try/catch(\Throwable): ein Fehler in einem Schritt
     * darf die folgenden Schritte nie verhindern (E-022).
     * @return list<string> Meldungen für die CLI-Ausgabe
     */
    public function lauf(\PDO $db, \DateTimeImmutable $jetzt): array
    {
        $protokoll = new JobProtokoll($db, fn() => $jetzt);
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $zeilen = [];
        $schritt = function (string $name, callable $fn) use (&$zeilen, $protokoll): void {
            try {
                $ergebnis = $fn();
                if (is_string($ergebnis) && $ergebnis !== '') {
                    $zeilen[] = $ergebnis;
                }
            } catch (\Throwable $e) {
                error_log("[Planer] Schritt '{$name}' fehlgeschlagen: " . $e->getMessage());
            } finally {
                // Herzschlag nach jedem Schritt erneuern (nicht nur zu Laufbeginn) – wichtig für
                // lange Läufe (z. B. NAS-Abgleich in T4), damit der Watchdog den Planer
                // währenddessen nicht fälschlich als hängend meldet (Reviewbefund 14).
                try {
                    $protokoll->herzschlag('planer');
                } catch (\Throwable $e) {
                    error_log("[Planer] Herzschlag nach Schritt '{$name}' fehlgeschlagen: " . $e->getMessage());
                }
            }
        };

        // 1. Herzschlag
        $schritt('herzschlag', function () use ($protokoll): void {
            $protokoll->herzschlag('planer');
        });

        // 2. Hängende Läufe (laeuftSeit älter als 3 h) abbrechen + abgelaufene Aufträge markieren
        $schritt('haengende-laeufe', function () use ($db, $jetzt, $jetztStr): void {
            $this->haengendeLaeufeAbschliessen($db, $jetzt);
            $db->prepare(
                "UPDATE job_auftraege SET status = 'abgelaufen', aktualisiertAm = ?
                 WHERE status = 'wartend' AND ablaufAm <> '' AND ablaufAm < ?"
            )->execute([$jetztStr, $jetztStr]);
        });

        // 3. Wartende Aufträge für 'sicherung' und 'probe_wiederherstellung' annehmen
        $auftragSicherung = null;
        $auftragProbe = null;
        $schritt('auftraege-annehmen', function () use (&$auftragSicherung, &$auftragProbe, $db, $jetztStr): void {
            $auftragSicherung = $this->auftragAnnehmen($db, 'sicherung', $jetztStr);
            $auftragProbe = $this->auftragAnnehmen($db, 'probe_wiederherstellung', $jetztStr);
        });

        // 4. Planmäßigen Job 'sicherung' ausführen
        $schritt('sicherung', function () use ($db, $protokoll, $jetzt, &$auftragSicherung) {
            $einstellungen = SystemEinstellungen::laden($db);
            $uhrzeit = (string)($einstellungen['sicherung']['uhrzeit'] ?? '02:00');
            $status = $protokoll->status('sicherung');
            $faellig = self::faelligkeit($status, $uhrzeit, $jetzt);
            if ($auftragSicherung !== null || $faellig !== null) {
                return $this->sicherungAusfuehren($db, $protokoll, $jetzt, $status, $auftragSicherung, $faellig);
            }
            return null;
        });

        // 5. Wiederherstellungs-Probe (monatlich, O5)
        $schritt('probe_wiederherstellung', function () use ($db, $protokoll, $jetzt, &$auftragProbe) {
            return $this->probeAusfuehren($db, $protokoll, $jetzt, $auftragProbe);
        });

        // 6. Speicherplatz (höchstens einmal je Stunde, nur bei Zustandswechsel protokolliert)
        $schritt('speicherplatz', function () use ($db, $jetzt): void {
            $this->speicherplatzPruefen($db, $jetzt);
        });

        // 7. Überwachung (Systemmeldungen – Fehler/Ausbleiben/Entwarnung)
        $schritt('ueberwachung', function () use ($db, $jetzt): void {
            Ueberwachung::pruefen($db, $jetzt);
        });

        // 8. Zustellung der Systemmeldungen (Gesamtbudget 60 s, Reviewbefund 6)
        $schritt('zustellung', function () use ($db, $jetzt): void {
            (new MeldungsZustellung())->zustellen($db, $jetzt, 20, 30, 60);
        });

        // 9. Laufprotokoll aufräumen
        $schritt('aufraeumen', function () use ($protokoll): void {
            $protokoll->aufraeumen();
        });

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
            $this->tageslaufFehlschlagBehandeln($db, 'sicherung', $protokoll, $jetzt, $heute, $laufId, $faellig, $auftragId, $neueVersuche, 'SICHERUNG_FEHLER', 'Tagessicherung fehlgeschlagen.');
            return 'sicherung: fehlgeschlagen';
        }

        // Eine gehaltene Sperre ist kein Erfolg – sonst würde eine durch einen anderen Vorgang
        // (z. B. gleichzeitiges Speichern) gesperrte Tagessicherung fälschlich als "ok" gezählt.
        if ($ergebnis === Tagessicherung::GESPERRT) {
            $this->tageslaufFehlschlagBehandeln($db, 'sicherung', $protokoll, $jetzt, $heute, $laufId, $faellig, $auftragId, $neueVersuche, 'SICHERUNG_GESPERRT', 'Tagessicherung war durch einen anderen Vorgang gesperrt.');
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
     * Gemeinsame Fehlschlag-Behandlung für einen gescheiterten/gesperrten Tageslauf
     * (sicherung und probe_wiederherstellung, T3): Protokoll-Fehler, Tageslauf-Wiederholung
     * (bis zu self::MAX_VERSUCHE) bzw. manueller Auftrag → 'fehlgeschlagen' mit Fehlercode.
     */
    private function tageslaufFehlschlagBehandeln(
        \PDO $db,
        string $job,
        JobProtokoll $protokoll,
        \DateTimeImmutable $jetzt,
        string $periodenSchluessel,
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
            $this->tageslaufSetzen($db, $job, $periodenSchluessel, $endgueltig ? 'fehlgeschlagen' : 'fehler', $neueVersuche, $naechsterVersuchAm);
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

    private function probeAusfuehren(\PDO $db, JobProtokoll $protokoll, \DateTimeImmutable $jetzt, ?string $auftragId): ?string
    {
        $status = $protokoll->status('probe_wiederherstellung');

        // Abweichung (E-084, siehe AP-20261012-datensicherung-nas, Abschnitt 3 „T3“, Umsetzung
        // Runde 2, Reviewbefund 5): Eine frische Installation hat noch keinen
        // job_status-Datensatz für diesen Job. Ohne Baseline wäre die Probe schon beim
        // allerersten Planer-Lauf fällig (jeden Tag ab 04:00 Uhr, solange "noch nie gelaufen"),
        // noch bevor überhaupt eine Tagessicherung existiert – und das bricht den bestehenden
        // Test SystemPlanerApiTest::testPlanerErstelltTaeglicheSicherungNurEinmalUndSetztStatus
        // (genau 1 job_laeufe-Zeile nach dem ersten Planer-Lauf). Die Baseline wird daher nur
        // gesetzt, wenn noch kein gültiger (älterer) Tagesordner existiert – bei Updates
        // bestehender Installationen (ältere Tagessicherungen vorhanden) läuft die Probe
        // stattdessen normal zur ersten Fälligkeit. Status 'uebersprungen' statt 'ok' (E-021:
        // kein falscher Erfolg ohne tatsächliche Ausführung); faelligkeit() behandelt ihn für
        // die laufende Periode wie abgeschlossen. Manuelles Anfordern (job_anfordern) läuft
        // unabhängig davon sofort.
        if ($status === [] && $auftragId === null && !WiederherstellungsProbe::hatGueltigenTagesordnerVor($jetzt->format('Y-m-d'))) {
            $this->probeBaselineSetzen($db, $jetzt->format('Y-m'));
            return null;
        }

        $faellig = self::faelligkeit($status, self::PROBE_UHRZEIT, $jetzt, 'monat');
        if ($auftragId === null && $faellig === null) {
            return null;
        }

        $periodenSchluessel = $jetzt->format('Y-m');
        $periodeAktuell = ($status['tageslaufDatum'] ?? '') === $periodenSchluessel;
        $versucheVorher = $periodeAktuell ? (int)($status['versuche'] ?? 0) : 0;
        $neueVersuche = $versucheVorher + 1;
        $ausloeser = $auftragId !== null ? 'manuell' : 'planer';

        $laufId = $protokoll->start('probe_wiederherstellung', $ausloeser);
        if ($faellig !== null) {
            $this->tageslaufSetzen($db, 'probe_wiederherstellung', $periodenSchluessel, 'laufend', $neueVersuche, '');
        }

        try {
            $ergebnis = WiederherstellungsProbe::pruefe();
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            if (!in_array($code, [WiederherstellungsProbe::FEHLER_KEINE_SICHERUNG, WiederherstellungsProbe::FEHLER_DEFEKT], true)) {
                $code = WiederherstellungsProbe::FEHLER_DEFEKT;
            }
            $text = $code === WiederherstellungsProbe::FEHLER_KEINE_SICHERUNG
                ? 'Es liegt keine gültige Tagessicherung zur Prüfung vor.'
                : 'Die Wiederherstellungs-Probe hat ein defektes Archiv festgestellt.';
            error_log('[Planer] Wiederherstellungs-Probe fehlgeschlagen: ' . $e->getMessage());
            $this->tageslaufFehlschlagBehandeln($db, 'probe_wiederherstellung', $protokoll, $jetzt, $periodenSchluessel, $laufId, $faellig, $auftragId, $neueVersuche, $code, $text);
            return 'probe_wiederherstellung: fehlgeschlagen';
        }

        $protokoll->erfolg($laufId, $ergebnis);
        if ($faellig !== null || $auftragId !== null) {
            $this->tageslaufSetzen($db, 'probe_wiederherstellung', $periodenSchluessel, 'ok', 0, '');
        }
        if ($auftragId !== null) {
            $this->auftragAbschliessen($db, $auftragId, 'fertig', ['ergebnis' => $ergebnis], $jetzt);
        }
        return 'probe_wiederherstellung: ok';
    }

    /**
     * Markiert den laufenden Monat als übersprungen, ohne die Probe auszuführen (s.
     * probeAusfuehren()). Status 'uebersprungen' statt 'ok' (Reviewbefund 5, E-021): kein
     * falscher Erfolg ohne tatsächliche Ausführung.
     */
    private function probeBaselineSetzen(\PDO $db, string $periodenSchluessel): void
    {
        $db->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit,
                                      laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm)
             VALUES ('probe_wiederherstellung', '', '', '', '', '', '', ?, 'uebersprungen', 0, '')
             ON CONFLICT(job) DO NOTHING"
        )->execute([$periodenSchluessel]);
    }

    /**
     * Freier Speicherplatz in BACKUP_DIR (T3, E3): höchstens einmal je Stunde geprüft; ein
     * job_laeufe-Eintrag entsteht nur bei Zustandswechsel (sonst würde die Stundenprüfung das
     * Protokoll füllen). Liefert disk_free_space() false, wird der Job ohne Fehler übersprungen.
     */
    private function speicherplatzPruefen(\PDO $db, \DateTimeImmutable $jetzt): void
    {
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $stmt = $db->prepare('SELECT * FROM job_status WHERE job = ?');
        $stmt->execute(['speicherplatz']);
        $vorher = $stmt->fetch();
        $vorher = is_array($vorher) ? $vorher : [];

        $letzterLaufAm = (string)($vorher['letzterLaufAm'] ?? '');
        if ($letzterLaufAm !== '' && (strtotime($jetztStr) - strtotime($letzterLaufAm)) < self::SPEICHERPLATZ_INTERVALL_SEKUNDEN) {
            return;
        }

        // Kein @ (E-063, der globale Error-Handler wirft trotzdem) – explizite Prüfung des
        // Verzeichnisses plus vorübergehend deaktivierter Error-Handler für disk_free_space()
        // selbst (kann z. B. bei Rechteproblemen eine Warnung auslösen).
        $backupDir = rtrim(BACKUP_DIR, '/\\');
        if (!is_dir($backupDir)) {
            return; // "wird der Job uebersprungen (kein Fehler)"
        }
        set_error_handler(static fn (): bool => true);
        try {
            $frei = disk_free_space($backupDir);
        } finally {
            restore_error_handler();
        }
        if ($frei === false) {
            return; // "wird der Job uebersprungen (kein Fehler)"
        }

        $einstellungen = SystemEinstellungen::laden($db);
        $minMb = (int)($einstellungen['ueberwachung']['speicherMinMb'] ?? 1024);
        $freiMb = (int)floor($frei / 1024 / 1024);
        $istKnapp = $freiMb < $minMb;
        $vorherigerFehlerCode = (string)($vorher['fehlerCode'] ?? '');
        $neuerFehlerCode = $istKnapp ? 'SPEICHER_KNAPP' : '';

        $this->sicherstellenJobStatus($db, 'speicherplatz');

        if ($neuerFehlerCode !== $vorherigerFehlerCode) {
            $protokoll = new JobProtokoll($db, fn() => $jetzt);
            $laufId = $protokoll->start('speicherplatz', 'planer');
            if ($istKnapp) {
                $protokoll->fehler($laufId, 'SPEICHER_KNAPP', 'Freier Speicherplatz unterschreitet die eingestellte Mindestgrenze.');
            } else {
                $protokoll->erfolg($laufId, ['freiMb' => $freiMb]);
            }
            return;
        }

        if ($istKnapp) {
            $db->prepare("UPDATE job_status SET letzterLaufAm = ?, letzterFehlerAm = ? WHERE job = 'speicherplatz'")
                ->execute([$jetztStr, $jetztStr]);
            $db->prepare("UPDATE job_status SET stoerungSeit = ? WHERE job = 'speicherplatz' AND stoerungSeit = ''")
                ->execute([$jetztStr]);
        } else {
            $db->prepare("UPDATE job_status SET letzterLaufAm = ?, letzterErfolgAm = ? WHERE job = 'speicherplatz'")
                ->execute([$jetztStr, $jetztStr]);
        }
    }

    private function sicherstellenJobStatus(\PDO $db, string $job): void
    {
        $db->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit,
                                      laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm)
             VALUES (?, '', '', '', '', '', '', '', '', 0, '')
             ON CONFLICT(job) DO NOTHING"
        )->execute([$job]);
    }
}

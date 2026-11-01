<?php
namespace App\Services\Jobs;

use App\Services\MailService;

/**
 * Zustellung der Systemmeldungen (T3, AP-20261012-datensicherung-nas). Trennt die
 * Zustandsänderung (Ueberwachung::pruefen(), eine Transaktion je Ereignis) von der
 * eigentlichen Mailzustellung mit Wiederholung/Backoff. Mail-Sender ist injizierbar
 * (Standard: MailService::send mit smtp_timeout) – damit in Tests ohne echten SMTP-Server
 * geprüft werden kann, ohne dass Verbindungsversuche hängen.
 */
final class MeldungsZustellung
{
    private const ALTER_GRENZE_TAGE = 7;     // offene Einträge älter als 7 Tage gelten als nicht zustellbar
    private const AUFRAEUMEN_TAGE = 90;      // gesendete + ungesendete Einträge älter als 90 Tage werden gelöscht
    private const MAX_EMPFAENGER = 10;
    private const BEANSPRUCHEN_MINUTEN = 10; // Lease-Dauer beim Beanspruchen eines Eintrags (Doppelzustellungsschutz)

    /** Lesbare Jobnamen – gemeinsame Tabelle mit SystemJobActions (Admin-Hinweise). */
    public const JOB_NAMEN = [
        'sicherung'               => 'Serversicherung',
        'probe_wiederherstellung' => 'Wiederherstellungs-Probe',
        'speicherplatz'           => 'Speicherplatz',
        'backupmail'              => 'Backup-E-Mail',
        'planer'                  => 'Zeitplaner (Cron)',
        'nas'                     => 'NAS-Abgleich',
    ];

    private const JOB_EMPFEHLUNG = [
        'sicherung'               => 'Bitte Speicherplatz und Zugriffsrechte des Servers prüfen.',
        'probe_wiederherstellung' => 'Bitte die letzte Tagessicherung manuell prüfen bzw. testweise wiederherstellen.',
        'speicherplatz'          => 'Bitte nicht mehr benötigte Daten löschen oder den Speicher erweitern.',
        'backupmail'              => 'Bitte SMTP-Einstellungen und Backup-E-Mail-Konfiguration prüfen.',
        'planer'                  => 'Bitte prüfen, ob der Cron-Dienst bzw. Container läuft.',
        'nas'                     => 'Bitte NAS-Zugangsdaten, Netzwerkverbindung und rclone-Installation prüfen.',
    ];

    private const FEHLER_TEXTE = [
        'SICHERUNG_FEHLER'      => 'Die Tagessicherung konnte nicht erstellt werden.',
        'SICHERUNG_GESPERRT'    => 'Die Tagessicherung war durch einen anderen Vorgang gesperrt.',
        'PROBE_KEINE_SICHERUNG' => 'Es liegt keine gültige Tagessicherung zur Prüfung vor.',
        'PROBE_DEFEKT'          => 'Die Wiederherstellungs-Probe hat ein defektes Archiv festgestellt.',
        'SPEICHER_KNAPP'        => 'Der freie Speicherplatz unterschreitet die eingestellte Mindestgrenze.',
        'BACKUPMAIL_EMPFAENGER' => 'Kein gültiger Backup-Empfänger konfiguriert.',
        'BACKUPMAIL_PASSWORT'   => 'Das Backup-Passwort kann nicht entschlüsselt werden.',
        'BACKUPMAIL_ERSTELLEN'  => 'Die Sicherung für den Versand konnte nicht erstellt werden.',
        'BACKUPMAIL_VERSAND'    => 'Der Mailversand ist fehlgeschlagen.',
        'NAS_KONFIGURATION'       => 'Die NAS-Einstellungen sind unvollständig oder ungültig.',
        'NAS_RCLONE_FEHLT'        => 'rclone ist nicht verfügbar.',
        'NAS_ANMELDUNG'           => 'Die Anmeldung an der NAS-Freigabe ist fehlgeschlagen.',
        'NAS_NICHT_ERREICHBAR'    => 'Die NAS ist nicht erreichbar.',
        'NAS_KEINE_SCHREIBRECHTE' => 'Keine Schreibrechte auf der NAS-Freigabe.',
        'NAS_VERBINDUNG'          => 'Die Verbindung zur NAS ist fehlgeschlagen.',
        'NAS_TRANSFER'            => 'Der Dateiabgleich mit der NAS ist fehlgeschlagen.',
        'NAS_ZEIT'                => 'Der NAS-Abgleich hat die Höchstdauer überschritten.',
    ];

    private const TYP_TEXTE = ['fehler' => 'Störung', 'ausbleiben' => 'Ausgeblieben', 'entwarnung' => 'Entwarnung'];

    /** @var callable(array<string,mixed> $cfg, string $an, string $betreff, string $html): void */
    private $absender;

    /** @param callable(array<string,mixed>,string,string,string):void|null $absender Standard: MailService::send */
    public function __construct(?callable $absender = null)
    {
        $this->absender = $absender ?? static function (array $cfg, string $an, string $betreff, string $html): void {
            MailService::send($cfg, $an, '', $betreff, $html);
        };
    }

    /**
     * @param int $budgetSek Gesamtzeitbudget für diesen Aufruf (Planer: 60, Watchdog: 4) – vor
     *        **jedem Empfänger** (nicht nur je Eintrag) wird das Restbudget seit Laufbeginn
     *        geprüft und `smtp_timeout = max(1, min(timeoutSek, Restbudget))` gesetzt; ist das
     *        Restbudget < 1, bricht der gesamte Zustelllauf sofort ab (Reviewbefund N1). Nach der
     *        ersten Exception bei einem Empfänger eines Eintrags werden die übrigen Empfänger
     *        dieses Eintrags ausgelassen und der gesamte Zustelllauf beendet, da derselbe
     *        SMTP-Server für alle Empfänger gleich betroffen wäre – wurde vorher mindestens ein
     *        Empfänger erreicht, gilt der Eintrag als gesendet (Teil-Erfolg-Regel), sonst bekommt
     *        er Backoff. Jede sonstige Ausnahme nach dem Beanspruchen eines Eintrags (Claim) führt
     *        ebenfalls zu Backoff und Laufabbruch statt einer unveränderten 10-min-Lease ohne
     *        erhöhten Versuchszähler (Reviewbefund N2).
     * @return int Anzahl in diesem Aufruf erfolgreich zugestellter Outbox-Einträge
     */
    public function zustellen(\PDO $db, \DateTimeImmutable $jetzt, int $max = 20, int $timeoutSek = 30, int $budgetSek = 60): int
    {
        $jetztStr = $jetzt->format('Y-m-d H:i:s');
        $frueheste = $jetzt->modify('-' . self::ALTER_GRENZE_TAGE . ' days')->format('Y-m-d H:i:s');
        $max = max(1, min(200, $max));

        $stmt = $db->prepare(
            "SELECT * FROM meldungen_outbox
              WHERE gesendetAm = ''
                AND (naechsterVersuchAm = '' OR naechsterVersuchAm <= ?)
                AND erstelltAm >= ?
              ORDER BY erstelltAm ASC LIMIT {$max}"
        );
        $stmt->execute([$jetztStr, $frueheste]);
        $eintraege = $stmt->fetchAll();

        $cfg = SmtpKonfig::laden($db);
        $firmaName = $this->firmaName($db);
        $empfaenger = self::empfaengerWirksam($db);

        $zugestellt = 0;
        $begin = microtime(true);
        foreach ($eintraege as $eintrag) {
            $restSek = $budgetSek - (int)floor(microtime(true) - $begin);
            if ($restSek < 1) {
                break; // Gesamtbudget aufgebraucht – Rest bleibt unangetastet für den nächsten Lauf.
            }

            // Vor dem Senden beanspruchen (Lease via rowCount): verhindert, dass Planer und
            // Watchdog denselben Eintrag gleichzeitig versenden (Reviewbefund 1).
            if (!$this->beanspruchen($db, $eintrag, $jetzt)) {
                continue;
            }

            try {
                $ergebnis = $this->einenZustellen($db, $eintrag, $cfg, $firmaName, $empfaenger, $jetzt, $begin, $budgetSek, $timeoutSek);
            } catch (\Throwable $e) {
                // Jede Ausnahme nach dem Beanspruchen führt zu Backoff (versuche++) statt einer
                // unveränderten 10-min-Lease, und beendet den Lauf (Reviewbefund N2).
                error_log('[MeldungsZustellung] Zustellung für Eintrag ' . (string)($eintrag['id'] ?? '?') . ' fehlgeschlagen: ' . $e->getMessage());
                $this->rueckfallBackoff($db, $eintrag, $jetzt);
                break;
            }
            if ($ergebnis['erfolg']) {
                $zugestellt++;
            }
            if ($ergebnis['abbruch']) {
                break; // SMTP/Budget betrifft alle Empfänger und Einträge gleich – Lauf beenden.
            }
        }

        $grenzeAufraeumen = $jetzt->modify('-' . self::AUFRAEUMEN_TAGE . ' days')->format('Y-m-d H:i:s');
        $db->prepare("DELETE FROM meldungen_outbox WHERE gesendetAm <> '' AND gesendetAm < ?")->execute([$grenzeAufraeumen]);
        $db->prepare("DELETE FROM meldungen_outbox WHERE gesendetAm = '' AND erstelltAm < ?")->execute([$grenzeAufraeumen]);

        return $zugestellt;
    }

    /**
     * Beansprucht einen Outbox-Eintrag per bedingtem UPDATE (CAS auf den zuvor gelesenen
     * `naechsterVersuchAm`-Wert) – nur bei `rowCount() === 1` gehört der Eintrag diesem Aufruf
     * (Reviewbefund 1).
     * @param array<string,mixed> $eintrag
     */
    private function beanspruchen(\PDO $db, array $eintrag, \DateTimeImmutable $jetzt): bool
    {
        $gelesenerWert = (string)($eintrag['naechsterVersuchAm'] ?? '');
        $lease = $jetzt->modify('+' . self::BEANSPRUCHEN_MINUTEN . ' minutes')->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            "UPDATE meldungen_outbox SET naechsterVersuchAm = ?
              WHERE id = ? AND gesendetAm = '' AND naechsterVersuchAm = ?"
        );
        $stmt->execute([$lease, $eintrag['id'], $gelesenerWert]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $eintrag @param array<string,mixed> $cfg @param list<string> $empfaenger
     * @param float $begin microtime(true) des Laufbeginns – Grundlage für das vor jedem Empfänger
     *        neu geprüfte Restbudget (Reviewbefund N1).
     * @return array{erfolg:bool,abbruch:bool} abbruch=true beendet den gesamten Zustelllauf
     *         (erste Empfänger-Exception oder aufgebrauchtes Gesamtbudget).
     */
    private function einenZustellen(\PDO $db, array $eintrag, array $cfg, string $firmaName, array $empfaenger, \DateTimeImmutable $jetzt, float $begin, int $budgetSek, int $timeoutSek): array
    {
        $jetztStr = $jetzt->format('Y-m-d H:i:s');

        if ($empfaenger === []) {
            $this->rueckfallBackoff($db, $eintrag, $jetzt);
            return ['erfolg' => false, 'abbruch' => false];
        }

        // T4-Vertrag Punkt 9 (R1): Die Empfängerliste wird je Versuch um `versuche % anzahl`
        // rotiert, damit ein dauerhaft abgelehnter erster Empfänger (z. B. SMTP 550) nicht
        // dauerhaft die Zustellung an alle übrigen Empfänger dieses Eintrags blockiert.
        $versuche = (int)($eintrag['versuche'] ?? 0);
        $anzahl = count($empfaenger);
        $versatz = $versuche % $anzahl;
        if ($versatz > 0) {
            $empfaenger = array_merge(array_slice($empfaenger, $versatz), array_slice($empfaenger, 0, $versatz));
        }

        [$betreff, $html] = $this->inhalt($eintrag, $firmaName);
        $erfolgreich = false;
        $abbruch = false;
        foreach ($empfaenger as $an) {
            $restSek = $budgetSek - (int)floor(microtime(true) - $begin);
            if ($restSek < 1) {
                $abbruch = true;
                break; // Gesamtbudget aufgebraucht – weitere Empfänger/Einträge nicht mehr versuchen.
            }
            $cfg['smtp_timeout'] = max(1, min($timeoutSek, $restSek));
            try {
                ($this->absender)($cfg, $an, $betreff, $html);
                $erfolgreich = true;
            } catch (\Throwable $e) {
                error_log('[MeldungsZustellung] Versand an ' . $an . ' fehlgeschlagen: ' . $e->getMessage());
                // Erste Exception beendet die übrigen Empfänger dieses Eintrags und den gesamten
                // Lauf – derselbe SMTP-Server wäre für alle gleich betroffen (Reviewbefund N1).
                $abbruch = true;
                break;
            }
        }

        if ($erfolgreich) {
            // Ergebnis per bedingtem UPDATE festhalten (kein erneutes CAS auf naechsterVersuchAm
            // nötig – dieser Aufruf hält bereits die Lease über gesendetAm = ''). Teil-Erfolg
            // (mindestens ein Empfänger erreicht, bevor ein anderer scheiterte) zählt als gesendet.
            $db->prepare("UPDATE meldungen_outbox SET gesendetAm = ? WHERE id = ? AND gesendetAm = ''")
                ->execute([$jetztStr, $eintrag['id']]);
            return ['erfolg' => true, 'abbruch' => $abbruch];
        }

        $this->rueckfallBackoff($db, $eintrag, $jetzt);
        return ['erfolg' => false, 'abbruch' => $abbruch];
    }

    /** @param array<string,mixed> $eintrag */
    private function rueckfallBackoff(\PDO $db, array $eintrag, \DateTimeImmutable $jetzt): void
    {
        $alteVersuche = (int)($eintrag['versuche'] ?? 0);
        $minuten = match (true) {
            $alteVersuche <= 0 => 5,
            $alteVersuche === 1 => 30,
            $alteVersuche === 2 => 120,
            default => 360,
        };
        $naechsterVersuchAm = $jetzt->modify("+{$minuten} minutes")->format('Y-m-d H:i:s');
        $db->prepare("UPDATE meldungen_outbox SET versuche = ?, naechsterVersuchAm = ? WHERE id = ? AND gesendetAm = ''")
            ->execute([$alteVersuche + 1, $naechsterVersuchAm, $eintrag['id']]);
    }

    /** @param array<string,mixed> $eintrag @return array{0:string,1:string} [Betreff, HTML] */
    private function inhalt(array $eintrag, string $firmaName): array
    {
        $job = (string)($eintrag['job'] ?? '');
        $typ = (string)($eintrag['typ'] ?? '');
        $daten = json_decode((string)($eintrag['daten'] ?? '{}'), true) ?: [];
        $fehlerCode = (string)($daten['fehlerCode'] ?? '');
        $seit = (string)($daten['seit'] ?? '');

        $jobName = self::JOB_NAMEN[$job] ?? 'Unbekannter Job';
        $ereignis = self::TYP_TEXTE[$typ] ?? 'Hinweis';
        $betreff = '[Baukalkulation – ' . $firmaName . '] ' . $ereignis . ': ' . $jobName;

        // Nur bei einem Code im zulässigen Format (sonst keine Interna aus Ausnahmetexten, E-014)
        // wird sowohl der feste Beschreibungstext als auch – zusätzlich – der Code selbst
        // ausgegeben (Reviewbefund 11, Vertrag 7).
        $fehlerText = '';
        if ($fehlerCode !== '' && preg_match('/^[A-Z_]{1,40}$/', $fehlerCode) === 1) {
            $fehlerText = self::FEHLER_TEXTE[$fehlerCode] ?? 'Unbekannter Fehler.';
        }
        $empfehlung = self::JOB_EMPFEHLUNG[$job] ?? 'Bitte die Überwachung in der Verwaltung prüfen.';

        $html = '<p><strong>' . htmlspecialchars($ereignis) . ':</strong> ' . htmlspecialchars($jobName) . '</p>';
        if ($seit !== '') {
            $html .= '<p>Seit: ' . htmlspecialchars($seit) . '</p>';
        }
        if ($fehlerText !== '') {
            $html .= '<p>' . htmlspecialchars($fehlerText) . '</p>';
            $html .= '<p>Fehlercode: ' . htmlspecialchars($fehlerCode) . '</p>';
        }
        $html .= '<p>' . htmlspecialchars($empfehlung) . '</p>';
        $html .= '<p><em>Verwaltung → Allgemein → Überwachung</em></p>';

        return [$betreff, $html];
    }

    /** @return list<string> ueberwachung.empfaenger, sonst alle aktiven Admins mit gültiger E-Mail */
    public static function empfaengerWirksam(\PDO $db): array
    {
        $einstellungen = SystemEinstellungen::laden($db);
        $konfiguriert = $einstellungen['ueberwachung']['empfaenger'] ?? [];
        $liste = [];
        if (is_array($konfiguriert)) {
            foreach ($konfiguriert as $adresse) {
                $adresse = trim((string)$adresse);
                if ($adresse !== '' && filter_var($adresse, FILTER_VALIDATE_EMAIL) && !in_array($adresse, $liste, true)) {
                    $liste[] = $adresse;
                }
            }
        }
        if ($liste !== []) {
            return array_slice($liste, 0, self::MAX_EMPFAENGER);
        }

        $stmt = $db->query("SELECT COALESCE(email, '') AS email FROM users WHERE role = 'admin' AND COALESCE(isLocked, 0) = 0");
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $liste, true)) {
                $liste[] = $email;
            }
        }
        return $liste;
    }

    private function firmaName(\PDO $db): string
    {
        $row = $db->query('SELECT data FROM settings WHERE id = 1')->fetch();
        $settings = $row ? (json_decode((string)$row['data'], true) ?? []) : [];
        $name = trim((string)($settings['firma_name'] ?? ''));
        return $name !== '' ? $name : 'Baukalkulation';
    }
}

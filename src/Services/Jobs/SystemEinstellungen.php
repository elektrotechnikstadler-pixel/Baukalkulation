<?php
namespace App\Services\Jobs;

/**
 * Einstellungen für die geplanten System-Jobs (Serversicherung, später NAS-Abgleich
 * und Überwachung). Persistiert als ein JSON-Blob in system_einstellungen (id = 1),
 * analog zu erinnerung_settings. Nur Admin darf speichern (siehe SystemJobActions).
 */
final class SystemEinstellungen
{
    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'sicherung' => [
            'uhrzeit'          => '02:00',
            'aufbewahrungTage' => 7,
        ],
        'ueberwachung' => [
            'aktiv'             => true,
            'empfaenger'        => [],
            'ausbleibenStunden' => 26,
            'speicherMinMb'     => 1024,
        ],
        // NAS-Abgleich per rclone (T4, AP-20261012-datensicherung-nas). 'passwort' enthält
        // nie Klartext, nur das Ergebnis von SecretBox::encrypt() bzw. '' (kein Passwort).
        'nas' => [
            'aktiv'               => false,
            'host'                => '',
            'port'                => 445,
            'freigabe'            => '',
            'pfad'                => 'baukalkulation',
            'benutzer'            => '',
            'passwort'            => '',
            'domaene'             => '',
            'bandbreiteKBs'       => 0,
            'passwortNeuEingeben' => false,
        ],
    ];

    /** @return array<string,mixed> */
    public static function laden(\PDO $db): array
    {
        $row = $db->query('SELECT data FROM system_einstellungen WHERE id = 1')->fetch();
        $data = $row ? json_decode((string)$row['data'], true) : null;
        return self::mischen(self::DEFAULTS, is_array($data) ? $data : []);
    }

    /**
     * Prüft und speichert die Einstellungen der Serversicherung.
     * @param array<string,mixed> $eingabe
     * @return array<string,mixed> alle Einstellungen nach dem Speichern
     * @throws \InvalidArgumentException bei ungültigen Werten (fester deutscher Text)
     */
    public static function speichernSicherung(\PDO $db, array $eingabe): array
    {
        $uhrzeit = (string)($eingabe['uhrzeit'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $uhrzeit)) {
            throw new \InvalidArgumentException('Ungültige Uhrzeit – Format HH:MM erwartet.');
        }

        $tageRoh = $eingabe['aufbewahrungTage'] ?? null;
        $istGanzzahl = is_int($tageRoh)
            || (is_string($tageRoh) && preg_match('/^\d+$/', $tageRoh) === 1)
            || (is_float($tageRoh) && $tageRoh === floor($tageRoh));
        if (!$istGanzzahl) {
            throw new \InvalidArgumentException('Aufbewahrung muss eine ganze Zahl zwischen 3 und 90 sein.');
        }
        $tage = (int)$tageRoh;
        if ($tage < 3 || $tage > 90) {
            throw new \InvalidArgumentException('Aufbewahrung muss eine ganze Zahl zwischen 3 und 90 sein.');
        }

        $alle = self::laden($db);
        $alle['sicherung'] = ['uhrzeit' => $uhrzeit, 'aufbewahrungTage' => $tage];
        $json = json_encode($alle, JSON_UNESCAPED_UNICODE);
        $db->prepare(
            "INSERT INTO system_einstellungen (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data"
        )->execute([$json]);
        return $alle;
    }

    /**
     * Prüft und speichert die Einstellungen der Überwachung/Systemmeldungen (T3).
     * @param array<string,mixed> $eingabe
     * @return array<string,mixed> alle Einstellungen nach dem Speichern
     * @throws \InvalidArgumentException bei ungültigen Werten (fester deutscher Text)
     */
    public static function speichernUeberwachung(\PDO $db, array $eingabe): array
    {
        $wert = self::validierenUeberwachung($eingabe);
        $alle = self::laden($db);
        $alle['ueberwachung'] = $wert;
        $json = json_encode($alle, JSON_UNESCAPED_UNICODE);
        $db->prepare(
            "INSERT INTO system_einstellungen (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data"
        )->execute([$json]);
        return $alle;
    }

    /**
     * Reine Formatprüfung der NAS-Felder (ohne Passwort/aktiv/DB) – von speichernNas() und
     * NasAbgleich (Konfigurationsphase) gemeinsam genutzt, damit die Validierung beim Lesen
     * aus der Datenbank identisch zur Validierung beim Speichern ist (T4-Vertrag Punkt 2).
     * @param array<string,mixed> $eingabe
     * @return array{host:string,port:int,freigabe:string,pfad:string,benutzer:string,domaene:string,bandbreiteKBs:int}
     * @throws \InvalidArgumentException bei ungültigen Werten (fester deutscher Text)
     */
    public static function pruefeNasFelder(array $eingabe): array
    {
        $host = trim(self::nasEingabeAlsString($eingabe['host'] ?? null, 'Ungültiger NAS-Host.'));
        if ($host !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$/D', $host) !== 1 && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Ungültiger NAS-Host.');
        }

        $port = self::ganzzahlInBereich($eingabe['port'] ?? 445, 1, 65535);
        if ($port === null) {
            throw new \InvalidArgumentException('NAS-Port muss eine ganze Zahl zwischen 1 und 65535 sein.');
        }

        $freigabe = trim(self::nasEingabeAlsString($eingabe['freigabe'] ?? null, 'Ungültiger NAS-Freigabename.'));
        if ($freigabe !== '' && ($freigabe === '.' || $freigabe === '..' || preg_match('/^[A-Za-z0-9 ._\-]{1,80}\$?$/D', $freigabe) !== 1)) {
            throw new \InvalidArgumentException('Ungültiger NAS-Freigabename.');
        }

        $pfad = trim(self::nasEingabeAlsString($eingabe['pfad'] ?? null, 'Ungültiger NAS-Pfad.'));
        if ($pfad !== '') {
            if (strlen($pfad) > 200 || str_starts_with($pfad, '/') || str_ends_with($pfad, '/')) {
                throw new \InvalidArgumentException('Ungültiger NAS-Pfad.');
            }
            foreach (explode('/', $pfad) as $segment) {
                if ($segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9 ._\-]{1,64}$/D', $segment) !== 1) {
                    throw new \InvalidArgumentException('Ungültiger NAS-Pfad.');
                }
            }
        }

        $benutzer = trim(self::nasEingabeAlsString($eingabe['benutzer'] ?? null, 'Ungültiger NAS-Benutzername.'));
        if ($benutzer !== '' && preg_match('/^[A-Za-z0-9 ._@\-]{1,100}$/D', $benutzer) !== 1) {
            throw new \InvalidArgumentException('Ungültiger NAS-Benutzername.');
        }

        $domaene = trim(self::nasEingabeAlsString($eingabe['domaene'] ?? null, 'Ungültige NAS-Domäne.'));
        if ($domaene !== '' && preg_match('/^[A-Za-z0-9.\-]{1,100}$/D', $domaene) !== 1) {
            throw new \InvalidArgumentException('Ungültige NAS-Domäne.');
        }

        $bandbreite = self::ganzzahlInBereich($eingabe['bandbreiteKBs'] ?? 0, 0, 1_000_000);
        if ($bandbreite === null) {
            throw new \InvalidArgumentException('Bandbreitenbegrenzung muss eine ganze Zahl zwischen 0 und 1.000.000 KB/s sein.');
        }

        return [
            'host'          => $host,
            'port'          => $port,
            'freigabe'      => $freigabe,
            'pfad'          => $pfad,
            'benutzer'      => $benutzer,
            'domaene'       => $domaene,
            'bandbreiteKBs' => $bandbreite,
        ];
    }

    /**
     * Lehnt Nicht-String-Eingaben vor dem `(string)`-Cast ab (Reviewbefund 8) – sonst würde ein
     * Array/Objekt eine PHP-Warnung auslösen, die der globale Error-Handler in eine Ausnahme mit
     * technischem Text verwandelt (E-063). `null`/fehlend bleibt erlaubt (Standard '').
     */
    private static function nasEingabeAlsString(mixed $wert, string $fehlertext): string
    {
        if ($wert === null) return '';
        if (!is_string($wert)) {
            throw new \InvalidArgumentException($fehlertext);
        }
        return $wert;
    }

    /**
     * Vollständige Prüfung einer NAS-Eingabe vor dem Speichern – inkl. „aktiv“ und der Regel
     * „aktiv=true setzt Host, Freigabe, Benutzer und ein gesetztes Passwort voraus“ – ohne
     * etwas zu schreiben (T3-Vertrag Punkt 4: kein Teilspeichern bei kombinierten Eingaben).
     * $bisher ist die aktuell gespeicherte nas-Teileinstellung (für die Passwort-Kept-Logik).
     * @param array<string,mixed> $eingabe
     * @param array<string,mixed> $bisher
     * @throws \InvalidArgumentException bei ungültigen Werten (fester deutscher Text)
     */
    public static function vorabPruefenNas(array $eingabe, array $bisher): void
    {
        $normalisiert = self::pruefeNasFelder($eingabe);
        $passwortRoh = $eingabe['passwort'] ?? '';
        if (!is_string($passwortRoh)) {
            // Immer prüfen (nicht erst bei aktiv=true), da speichernNas() den Wert in jedem
            // Fall per (string)-Cast weiterverarbeitet (Reviewbefund 8).
            throw new \InvalidArgumentException('Ungültiges NAS-Passwort.');
        }
        $aktiv = self::alsBool($eingabe['aktiv'] ?? ($bisher['aktiv'] ?? false));
        if ($aktiv === null) {
            throw new \InvalidArgumentException('"aktiv" muss ein echter Wahrheitswert sein.');
        }
        if (!$aktiv) return;

        $passwortLoeschen = (bool)($eingabe['passwortLoeschen'] ?? false);
        $passwortVorhanden = !$passwortLoeschen && ($passwortRoh !== '' || (string)($bisher['passwort'] ?? '') !== '');

        if ($normalisiert['host'] === '' || $normalisiert['freigabe'] === '' || $normalisiert['benutzer'] === '' || !$passwortVorhanden) {
            throw new \InvalidArgumentException('Für aktiven NAS-Abgleich sind Host, Freigabe, Benutzer und Passwort erforderlich.');
        }
    }

    /**
     * Prüft und speichert die NAS-Einstellungen (T4). Ein leeres/fehlendes Passwortfeld
     * behält das bisherige (verschlüsselte) Passwort, `passwortLoeschen:true` leert es, ein
     * neues Passwort wird per SecretBox verschlüsselt gespeichert und setzt
     * `passwortNeuEingeben` zurück auf false.
     * @param array<string,mixed> $eingabe
     * @return array<string,mixed> alle Einstellungen nach dem Speichern (inkl. verschlüsseltem
     *         Passwort – die Maskierung für die API-Antwort übernimmt nasOeffentlich()).
     * @throws \InvalidArgumentException bei ungültigen Werten (fester deutscher Text)
     */
    public static function speichernNas(\PDO $db, array $eingabe): array
    {
        $alle = self::laden($db);
        $bisher = is_array($alle['nas'] ?? null) ? $alle['nas'] : self::DEFAULTS['nas'];

        self::vorabPruefenNas($eingabe, $bisher);
        $normalisiert = self::pruefeNasFelder($eingabe);
        $aktiv = (bool)self::alsBool($eingabe['aktiv'] ?? ($bisher['aktiv'] ?? false));

        $passwortLoeschen = (bool)($eingabe['passwortLoeschen'] ?? false);
        $passwortRoh = (string)($eingabe['passwort'] ?? '');
        $passwortGespeichert = (string)($bisher['passwort'] ?? '');
        $passwortNeuEingeben = (bool)($bisher['passwortNeuEingeben'] ?? false);
        if ($passwortLoeschen) {
            $passwortGespeichert = '';
            $passwortNeuEingeben = false;
        } elseif ($passwortRoh !== '') {
            $passwortGespeichert = \App\Services\SecretBox::encrypt($passwortRoh);
            $passwortNeuEingeben = false;
        }

        $alle['nas'] = array_merge($normalisiert, [
            'aktiv'               => $aktiv,
            'passwort'            => $passwortGespeichert,
            'passwortNeuEingeben' => $passwortNeuEingeben,
        ]);
        $json = json_encode($alle, JSON_UNESCAPED_UNICODE);
        $db->prepare(
            "INSERT INTO system_einstellungen (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data"
        )->execute([$json]);
        return $alle;
    }

    /**
     * Maskiert die NAS-Teileinstellung für API-Antworten: Passwort wird nie ausgegeben, nur
     * `passwortGesetzt` (bool) – zusätzlich zum ohnehin vorhandenen `passwortNeuEingeben`
     * (T4-Vertrag Punkt 2 „Ausgabe“).
     * @param array<string,mixed> $nas
     * @return array<string,mixed>
     */
    public static function nasOeffentlich(array $nas): array
    {
        $out = $nas;
        $out['passwortGesetzt'] = (string)($out['passwort'] ?? '') !== '';
        unset($out['passwort']);
        return $out;
    }

    /**
     * Nach einer Wiederherstellung (Importer, beide Modi – T4-Vertrag Punkt 3, R12): Ein mit
     * einem fremden secret.key verschlüsseltes NAS-Passwort lässt sich nicht mehr entschlüsseln.
     * In dem Fall wird es geleert und `passwortNeuEingeben=true` gesetzt (Hinweis erscheint über
     * SystemJobActions::hinweise()). Unkritisch, wenn `nas` noch nie gespeichert wurde.
     */
    public static function secretsNachImportPruefen(\PDO $db): void
    {
        $alle = self::laden($db);
        $nas = $alle['nas'] ?? null;
        if (!is_array($nas)) return;
        $passwort = (string)($nas['passwort'] ?? '');
        if ($passwort === '' || !\App\Services\SecretBox::isEncrypted($passwort)) return;

        try {
            \App\Services\SecretBox::decrypt($passwort);
            return; // weiterhin entschlüsselbar -> nichts zu tun
        } catch (\Throwable $e) {
            error_log('[SystemEinstellungen] NAS-Passwort nach Wiederherstellung nicht entschlüsselbar: ' . $e->getMessage());
        }

        $alle['nas']['passwort'] = '';
        $alle['nas']['passwortNeuEingeben'] = true;
        $json = json_encode($alle, JSON_UNESCAPED_UNICODE);
        $db->prepare(
            "INSERT INTO system_einstellungen (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data"
        )->execute([$json]);
    }

    /**
     * Reine Prüfung ohne Datenbankzugriff – wird auch genutzt, um eine Kombination aus
     * {sicherung, ueberwachung} vorab vollständig zu prüfen, bevor irgendetwas geschrieben
     * wird (T3-Vertrag Punkt 4: „ungültige Werte → 400 ohne Speichern“, kein Teilspeichern).
     * @param array<string,mixed> $eingabe
     * @return array{aktiv:bool,empfaenger:list<string>,ausbleibenStunden:int,speicherMinMb:int}
     * @throws \InvalidArgumentException bei ungültigen Werten (fester deutscher Text)
     */
    public static function validierenUeberwachung(array $eingabe): array
    {
        $empfaengerRoh = $eingabe['empfaenger'] ?? [];
        if (!is_array($empfaengerRoh)) {
            throw new \InvalidArgumentException('Empfängerliste muss eine Liste von E-Mail-Adressen sein.');
        }
        $empfaenger = [];
        foreach ($empfaengerRoh as $adresse) {
            // Nicht-String-Adressen (z. B. Arrays/Objekte) führen zu 400 ohne Warnung statt
            // einer impliziten String-Umwandlung (Reviewbefund 9).
            if (!is_string($adresse)) {
                throw new \InvalidArgumentException('Empfängerliste darf nur Text-Adressen enthalten.');
            }
            $adresse = trim($adresse);
            if ($adresse === '') continue;
            if (!filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
                // Fester Text ohne die Nutzereingabe (Reviewbefund 9, E-014).
                throw new \InvalidArgumentException('Empfängerliste enthält eine ungültige E-Mail-Adresse.');
            }
            if (!in_array($adresse, $empfaenger, true)) {
                $empfaenger[] = $adresse;
            }
        }
        if (count($empfaenger) > 10) {
            throw new \InvalidArgumentException('Höchstens 10 Empfänger für Systemmeldungen erlaubt.');
        }

        // Untergrenze 26 (statt z. B. 2) statt der Standardlogik, weil eine tägliche Sicherung
        // bei Werten < 24 h jeden Tag einen Fehlalarm auslösen würde; zusätzlich deckt sie die
        // Zeitumstellung (max. 25 h) und die Wiederholungen der Tagessicherung ab (Abweichung 6,
        // E-084 – Untergrenze in T3 Runde 2 von 25 auf 26 angehoben, s. AP Abschnitt 3 „T3“).
        $ausbleibenStunden = self::ganzzahlInBereich($eingabe['ausbleibenStunden'] ?? null, 26, 168);
        if ($ausbleibenStunden === null) {
            throw new \InvalidArgumentException('Ausbleiben-Frist muss eine ganze Zahl zwischen 26 und 168 Stunden sein.');
        }

        $speicherMinMb = self::ganzzahlInBereich($eingabe['speicherMinMb'] ?? null, 100, 1_000_000);
        if ($speicherMinMb === null) {
            throw new \InvalidArgumentException('Mindest-Speicher muss eine ganze Zahl zwischen 100 und 1.000.000 MB sein.');
        }

        // Echter bool oder strenge filter_var-Prüfung statt (bool)-Cast (der z. B. "false" als
        // true werten würde), sonst 400 (Reviewbefund 9).
        $aktivRoh = $eingabe['aktiv'] ?? true;
        if (is_bool($aktivRoh)) {
            $aktiv = $aktivRoh;
        } else {
            $aktiv = is_scalar($aktivRoh) ? filter_var($aktivRoh, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        }
        if ($aktiv === null) {
            throw new \InvalidArgumentException('"aktiv" muss ein echter Wahrheitswert sein.');
        }

        return [
            'aktiv'             => $aktiv,
            'empfaenger'        => $empfaenger,
            'ausbleibenStunden' => $ausbleibenStunden,
            'speicherMinMb'     => $speicherMinMb,
        ];
    }

    /** Liefert den Wert als int zurück, wenn er eine ganze Zahl im Bereich [$min,$max] ist, sonst null. */
    private static function ganzzahlInBereich(mixed $wert, int $min, int $max): ?int
    {
        $istGanzzahl = is_int($wert)
            || (is_string($wert) && preg_match('/^-?\d+$/', $wert) === 1)
            || (is_float($wert) && $wert === floor($wert));
        if (!$istGanzzahl) return null;
        $zahl = (int)$wert;
        return ($zahl >= $min && $zahl <= $max) ? $zahl : null;
    }

    /**
     * Echter bool oder strenge filter_var-Prüfung statt (bool)-Cast (der z. B. "false" als
     * true werten würde); liefert null bei ungültigem Wert (Reviewbefund 9-Muster).
     */
    private static function alsBool(mixed $wert): ?bool
    {
        if (is_bool($wert)) return $wert;
        return is_scalar($wert) ? filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    }

    /**
     * Tiefes Zusammenführen: Werte aus $data überschreiben $defaults je Schlüssel,
     * unbekannte Schlüssel aus $data (z. B. künftige Bereiche nas/ueberwachung) bleiben erhalten.
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function mischen(array $defaults, array $data): array
    {
        $out = $data;
        foreach ($defaults as $key => $value) {
            if (is_array($value) && isset($out[$key]) && is_array($out[$key])) {
                $out[$key] = self::mischen($value, $out[$key]);
            } elseif (!array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}

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

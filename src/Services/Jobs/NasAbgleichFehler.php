<?php
namespace App\Services\Jobs;

/**
 * Phasen-Fehlercode statt Exit-Code (Review R5, T4-Vertrag): NasAbgleich wirft diese
 * Ausnahme mit einem festen `code` (z. B. NAS_VERBINDUNG, NAS_TRANSFER, NAS_ZEIT) statt des
 * rohen rclone-Exit-Codes. $kennzahlen ist optional (z. B. bei NAS_TRANSFER, wenn ein Teil
 * der Übertragung bereits gelungen war) – rein informativ, aktuell nicht persistiert.
 */
final class NasAbgleichFehler extends \RuntimeException
{
    /** @param array<string,mixed> $kennzahlen */
    public function __construct(public readonly string $fehlerCode, string $meldung = '', public readonly array $kennzahlen = [])
    {
        parent::__construct($meldung !== '' ? $meldung : $fehlerCode);
    }
}

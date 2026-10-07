<?php
declare(strict_types=1);

namespace App\Services;

final class Gleitzeit
{
    /**
     * @param array<string,mixed> $zeitCfg
     * @param array<string,mixed> $settings
     */
    public static function saldo(
        \PDO $db,
        string $username,
        array $zeitCfg,
        int $jahr,
        array $settings,
        ?\DateTimeImmutable $heute = null,
    ): float {
        $start = new \DateTimeImmutable(sprintf('%04d-01-01', $jahr));
        $jahrEnde = new \DateTimeImmutable(sprintf('%04d-12-31', $jahr));
        $stichtag = $heute ?? new \DateTimeImmutable('today');
        if ($stichtag > $jahrEnde) {
            $stichtag = $jahrEnde;
        }

        $startdatum = self::datumOderNull((string)($settings['gleitzeit_startdatum'] ?? ''));
        if ($startdatum !== null && $startdatum > $start) {
            $start = $startdatum;
        }
        if ($start > $stichtag) {
            return 0.0;
        }

        $customTypen = $settings['ze_custom_typen'] ?? [];
        if (!is_array($customTypen)) {
            $customTypen = [];
        }
        $feiertage = Feiertage::fuerJahr($jahr, Feiertage::customAusEinstellungen($settings));

        $stmt = $db->prepare('SELECT datum, typ, stunden FROM zeiterfassung WHERE username = ? AND datum >= ? AND datum <= ?');
        if ($stmt === false) {
            return 0.0;
        }
        $stmt->execute([$username, $start->format('Y-m-d'), $stichtag->format('Y-m-d')]);
        $entries = $stmt->fetchAll();

        $entryDates = [];
        $ist = 0.0;
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['datum'])) {
                $entryDates[(string)$entry['datum']] = true;
                $ist += Sollzeit::istStundenEintrag($zeitCfg, $entry, $customTypen);
            }
        }

        $soll = 0.0;
        for ($tag = $start; $tag <= $stichtag; $tag = $tag->modify('+1 day')) {
            $datum = $tag->format('Y-m-d');
            $tagesSoll = Sollzeit::tagesSoll($zeitCfg, $datum);
            $soll += $tagesSoll;
            if ($tagesSoll > 0.0 && isset($feiertage[$datum]) && !isset($entryDates[$datum])) {
                $ist += $tagesSoll;
            }
        }

        $buchungen = $db->prepare('SELECT betrag FROM gleitzeitkonto_buchungen WHERE username = ? AND datum >= ? AND datum <= ?');
        if ($buchungen === false) {
            return round($ist - $soll, 2);
        }
        $buchungen->execute([$username, $start->format('Y-m-d'), $stichtag->format('Y-m-d')]);
        foreach ($buchungen->fetchAll() as $buchung) {
            if (is_array($buchung)) {
                $ist += (float)($buchung['betrag'] ?? 0);
            }
        }

        return round($ist - $soll, 2);
    }

    private static function datumOderNull(string $datum): ?\DateTimeImmutable
    {
        if ($datum === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $datum ? $date : null;
    }
}

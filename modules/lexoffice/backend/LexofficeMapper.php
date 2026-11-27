<?php
// ============================================================
// Lexware Office – reine Abbildungsfunktionen (ohne DB/HTTP)
// AP-20261126-lexoffice-schnittstelle, B2. Fallisten:
// tests/fixtures/lexoffice/kontakte.json, zuordnung.json
// ============================================================

namespace App\Modules\Lexoffice;

final class LexofficeMapper
{
    /** Kunde der Baukalkulation → neuer Lexware-Kontakt (Rolle Kunde). */
    public static function kontakt(array $k): array
    {
        $t = static fn(string $f): string => trim((string) ($k[$f] ?? ''));
        $anrede = in_array($t('anrede'), ['Herr', 'Frau'], true) ? $t('anrede') : '';
        $kontakt = ['version' => 0, 'roles' => ['customer' => new \stdClass()]];

        if ($t('firma') !== '') {
            $firma = ['name' => $t('firma')];
            if ($t('ustId') !== '') {
                $firma['vatRegistrationId'] = $t('ustId');
            }
            if ($t('nachname') !== '') {
                $person = array_filter([
                    'salutation'   => $anrede,
                    'firstName'    => $t('vorname'),
                    'lastName'     => $t('nachname'),
                    'emailAddress' => $t('email'),
                    'phoneNumber'  => $t('telefon') !== '' ? $t('telefon') : $t('mobil'),
                ], static fn($v) => $v !== '');
                $firma['contactPersons'] = [$person + ['primary' => true]];
            }
            $kontakt['company'] = $firma;
        } else {
            $kontakt['person'] = array_filter([
                'salutation' => $anrede,
                'firstName'  => $t('vorname'),
                'lastName'   => $t('nachname'),
            ], static fn($v) => $v !== '');
        }

        if ($t('strasse') !== '' || $t('plz') !== '' || $t('ort') !== '') {
            $kontakt['addresses'] = ['billing' => [array_filter([
                'street'      => $t('strasse'),
                'zip'         => $t('plz'),
                'city'        => $t('ort'),
                'countryCode' => $t('land') !== '' ? strtoupper($t('land')) : 'DE',
            ], static fn($v) => $v !== '')]];
        }
        if ($t('email') !== '') {
            $kontakt['emailAddresses'] = ['business' => [$t('email')]];
        }
        $telefon = array_filter(['business' => $t('telefon'), 'mobile' => $t('mobil')], static fn($v) => $v !== '');
        if ($telefon) {
            $kontakt['phoneNumbers'] = array_map(static fn($n) => [$n], $telefon);
        }
        return $kontakt;
    }

    public static function inhaltHash(array $kunde): string
    {
        return sha1((string) json_encode(self::kontakt($kunde), JSON_UNESCAPED_UNICODE));
    }

    /** Anzeigename eines Lexware-Kontakts. */
    public static function kontaktName(array $c): string
    {
        if (!empty($c['company']['name'])) {
            return (string) $c['company']['name'];
        }
        return trim(($c['person']['firstName'] ?? '') . ' ' . ($c['person']['lastName'] ?? ''));
    }

    /**
     * Vorschlag für einen Kunden unter vorhandenen Lexware-Kontakten (nur aktive Kunden-Kontakte):
     * zuerst eindeutige E-Mail, dann eindeutiger Name (bei mehreren Namenstreffern entscheidet die PLZ).
     *
     * @param list<array<string,mixed>> $kandidaten
     * @return array{status:string, lexId?:string, name?:string, grund?:string, anzahl?:int}
     */
    public static function zuordnen(array $kunde, array $kandidaten): array
    {
        $aktiv = array_values(array_filter($kandidaten, static fn($c) => empty($c['archived']) && isset($c['roles']['customer']) && !empty($c['id'])));

        $email = strtolower(trim((string) ($kunde['email'] ?? '')));
        if ($email !== '') {
            $treffer = array_values(array_filter($aktiv, static fn($c) => in_array($email, self::emails($c), true)));
            if (count($treffer) === 1) {
                return self::treffer($treffer[0], 'email');
            }
            if (count($treffer) > 1) {
                return ['status' => 'mehrdeutig', 'anzahl' => count($treffer)];
            }
        }

        $name = self::norm(trim((string) ($kunde['firma'] ?? '')) !== ''
            ? (string) $kunde['firma']
            : trim(($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? '')));
        if ($name === '') {
            return ['status' => 'keiner'];
        }
        $treffer = array_values(array_filter($aktiv, static fn($c) => self::norm(self::kontaktName($c)) === $name));
        if (count($treffer) > 1) {
            $plz = trim((string) ($kunde['plz'] ?? ''));
            $mitPlz = $plz === '' ? [] : array_values(array_filter($treffer, static fn($c) => in_array($plz, array_column($c['addresses']['billing'] ?? [], 'zip'), true)));
            if (count($mitPlz) === 1) {
                return self::treffer($mitPlz[0], 'name_plz');
            }
            return ['status' => 'mehrdeutig', 'anzahl' => count($treffer)];
        }
        return count($treffer) === 1 ? self::treffer($treffer[0], 'name') : ['status' => 'keiner'];
    }

    /** @return list<string> */
    private static function emails(array $c): array
    {
        $alle = [];
        foreach ((array) ($c['emailAddresses'] ?? []) as $liste) {
            foreach ((array) $liste as $e) {
                $alle[] = $e;
            }
        }
        foreach ((array) ($c['company']['contactPersons'] ?? []) as $p) {
            $alle[] = $p['emailAddress'] ?? '';
        }
        return array_values(array_filter(array_map(static fn($e) => strtolower(trim((string) $e)), $alle)));
    }

    private static function treffer(array $c, string $grund): array
    {
        return ['status' => 'treffer', 'lexId' => (string) $c['id'], 'name' => self::kontaktName($c), 'grund' => $grund];
    }

    private static function norm(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return (string) preg_replace('/[^a-z0-9]+/', '', $s);
    }
}

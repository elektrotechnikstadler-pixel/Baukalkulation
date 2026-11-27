<?php
// ============================================================
// Lexware Office – Projektpositionen → Angebot/Rechnungsentwurf
// AP-20261126-lexoffice-schnittstelle, B4 (Plan 2.2.1). Rein, ohne
// DB/HTTP; Fallliste tests/fixtures/lexoffice/projekte.json.
// Rundung: eine Funktion für Export und Lexware (ProjektPositionen::
// zeilenbetrag): Einzelpreis 4 Nachkommastellen (wie die API erlaubt),
// Zeile = Menge × EP auf Cent. Zusammengefasste Zeilen werden aus der
// Summe gebildet – evtl. Cent-Abweichungen zeigt die Vorschau an.
// ============================================================

namespace App\Modules\Lexoffice;

final class LexofficeBeleg
{
    public const DARSTELLUNG = [
        'material'    => ['einzeln', 'kategorie', 'summe', 'aus'],
        'arbeitszeit' => ['kategorie', 'tag', 'tag_kuerzel', 'summe', 'aus'],
        'pauschale'   => ['einzeln', 'summe', 'aus'],
    ];
    private const UEBERSCHRIFT = ['material' => 'Material', 'arbeitszeit' => 'Arbeitsleistung', 'pauschale' => 'Pauschalen'];

    /** Auswahl der Übertragungsmaske prüfen und mit Standards ergänzen ($standard13b = Projektfeld). */
    public static function auswahl(array $roh, bool $standard13b = false): array
    {
        $a = [
            'belegart'         => in_array($roh['belegart'] ?? '', ['angebot', 'rechnung'], true) ? $roh['belegart'] : 'angebot',
            'ueberschriften'   => ($roh['ueberschriften'] ?? true) !== false,
            'nurOffene'        => ($roh['nurOffene'] ?? true) !== false,
            'reverseCharge13b' => array_key_exists('reverseCharge13b', $roh) ? $roh['reverseCharge13b'] === true : $standard13b,
            'von'              => self::datum($roh['von'] ?? ''),
            'bis'              => self::datum($roh['bis'] ?? ''),
            'einleitung'       => mb_substr(trim((string) ($roh['einleitung'] ?? '')), 0, 2000),
            'schluss'          => mb_substr(trim((string) ($roh['schluss'] ?? '')), 0, 2000),
            'ausgewaehlt'      => [],
        ];
        foreach (self::DARSTELLUNG as $art => $werte) {
            $a[$art] = in_array($roh[$art] ?? '', $werte, true) ? $roh[$art] : $werte[0];
            $ids = $roh['ausgewaehlt'][$art] ?? null;
            $a['ausgewaehlt'][$art] = is_array($ids) ? array_map('strval', $ids) : null;
        }
        if ($a['von'] !== '' && $a['bis'] !== '' && $a['bis'] < $a['von']) {
            [$a['von'], $a['bis']] = [$a['bis'], $a['von']];
        }
        return $a;
    }

    /**
     * @param list<array<string,mixed>> $positionen aus ProjektPositionen::aus(), je mit 'abgerechnet' (bool)
     * @param array{kleinunternehmer:bool, steuersatz:float} $firma
     * @return array{positionen:list<array<string,mixed>>, lineItems:list<array<string,mixed>>, taxType:string, steuersatz:float, summen:array{netto:float,steuer:float,brutto:float}, positionenNetto:float, von:string, bis:string}
     */
    public static function bilden(array $positionen, array $auswahl, array $firma): array
    {
        [$taxType, $satz] = match (true) {
            $firma['kleinunternehmer']    => ['vatfree', 0.0],
            $auswahl['reverseCharge13b']  => ['constructionService13b', 0.0],
            default                       => ['net', (float) $firma['steuersatz']],
        };

        $gewaehlt = array_values(array_filter($positionen, static function ($p) use ($auswahl) {
            $ids = $auswahl['ausgewaehlt'][$p['art']] ?? null;
            return $auswahl[$p['art']] !== 'aus'
                && abs($p['menge']) > 0
                && (!$auswahl['nurOffene'] || empty($p['abgerechnet']))
                && ($ids === null || in_array((string) $p['id'], $ids, true));
        }));

        $lineItems = [];
        foreach (['material', 'arbeitszeit', 'pauschale'] as $art) {
            $gruppe = array_values(array_filter($gewaehlt, static fn($p) => $p['art'] === $art));
            if (!$gruppe) {
                continue;
            }
            if ($auswahl['ueberschriften']) {
                $lineItems[] = ['type' => 'text', 'name' => self::UEBERSCHRIFT[$art]];
            }
            foreach (self::zeilen($art, $auswahl[$art], $gruppe) as $z) {
                $lineItems[] = self::zeile($z, $satz);
            }
        }

        $netto = 0.0;
        foreach ($lineItems as $li) {
            if ($li['type'] === 'custom') {
                $netto += self::betrag($li['quantity'], $li['unitPrice']['netAmount']);
            }
        }
        $netto = round($netto, 2);
        $steuer = round($netto * $satz / 100, 2);
        // Summe der Einzelpositionen wie im Projekt-Export – weicht bei zusammengefassten Zeilen evtl. um Cent ab.
        $positionenNetto = round(array_sum(array_map(static fn($p) => self::betrag($p['menge'], $p['ep']), $gewaehlt)), 2);

        $daten = array_filter(array_column($gewaehlt, 'datum'), static fn($d) => self::datum($d) !== '');
        $von = $auswahl['von'] !== '' ? $auswahl['von'] : ($daten ? min($daten) : '');
        $bis = $auswahl['bis'] !== '' ? $auswahl['bis'] : ($daten ? max($daten) : $von);

        return [
            'positionen' => $gewaehlt,
            'lineItems'  => $lineItems,
            'taxType'    => $taxType,
            'steuersatz' => $satz,
            'summen'     => ['netto' => $netto, 'steuer' => $steuer, 'brutto' => round($netto + $steuer, 2)],
            'positionenNetto' => $positionenNetto,
            'von'        => $von,
            'bis'        => $bis,
        ];
    }

    /** Anfrage für POST /v1/quotations bzw. /v1/invoices (ohne finalize = Entwurf). */
    public static function anfrage(array $beleg, array $auswahl, string $contactId, string $heute): array
    {
        $tag = static fn(string $d): string => (new \DateTimeImmutable($d . ' 00:00:00', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d\TH:i:s.vP');
        $anfrage = [
            'voucherDate'   => $tag($heute),
            'address'       => ['contactId' => $contactId],
            'lineItems'     => $beleg['lineItems'],
            'totalPrice'    => ['currency' => 'EUR'],
            'taxConditions' => ['taxType' => $beleg['taxType']],
        ];
        if ($auswahl['belegart'] === 'angebot') {
            $anfrage['expirationDate'] = $tag((new \DateTimeImmutable($heute))->modify('+30 days')->format('Y-m-d'));
        } else {
            $von = $beleg['von'] !== '' ? $beleg['von'] : $heute;
            $bis = $beleg['bis'] !== '' ? $beleg['bis'] : $von;
            $anfrage['shippingConditions'] = $von === $bis
                ? ['shippingType' => 'service', 'shippingDate' => $tag($von)]
                : ['shippingType' => 'serviceperiod', 'shippingDate' => $tag($von), 'shippingEndDate' => $tag($bis)];
        }
        if ($auswahl['einleitung'] !== '') {
            $anfrage['introduction'] = $auswahl['einleitung'];
        }
        if ($auswahl['schluss'] !== '') {
            $anfrage['remark'] = $auswahl['schluss'];
        }
        return $anfrage;
    }

    public static function betrag(float $menge, float $ep): float
    {
        return \App\Services\ProjektPositionen::zeilenbetrag($menge, $ep);
    }

    // ── intern ───────────────────────────────────────────────

    /** @return list<array{name:string, description:string, menge:float, einheit:string, ep:float}> */
    private static function zeilen(string $art, string $darstellung, array $gruppe): array
    {
        $summe = static fn(array $ps): float => array_sum(array_map(static fn($p) => self::betrag($p['menge'], $p['ep']), $ps));
        $katName = static fn($p): string => $p['kategorie'] !== '' ? $p['kategorie'] : ($art === 'arbeitszeit' ? $p['bezeichnung'] : '');

        if ($darstellung === 'summe') {
            $name = ['material' => 'Material lt. Aufstellung', 'arbeitszeit' => 'Arbeitsleistung lt. Aufstellung', 'pauschale' => 'Pauschalen'][$art];
            $liste = implode("\n", array_map(static fn($p) => $p['bezeichnung'] . ' (' . self::zahl($p['menge']) . ' ' . $p['einheit'] . ')', $gruppe));
            return [['name' => $name, 'description' => mb_substr($liste, 0, 2000), 'menge' => 1.0, 'einheit' => 'pauschal', 'ep' => $summe($gruppe)]];
        }

        if ($art === 'material' && $darstellung === 'kategorie') {
            $gruppen = [];
            foreach ($gruppe as $p) {
                $gruppen[$katName($p)][] = $p;
            }
            $out = [];
            foreach ($gruppen as $kat => $ps) {
                $out[] = ['name' => $kat !== '' ? 'Material – ' . $kat : 'Material', 'description' => count($ps) . ' Positionen lt. Aufstellung',
                    'menge' => 1.0, 'einheit' => 'pauschal', 'ep' => $summe($ps)];
            }
            return $out;
        }

        if ($art === 'arbeitszeit') {
            $gruppen = [];
            foreach ($gruppe as $p) {
                $schluessel = match ($darstellung) {
                    'tag'         => [$p['datum'], $katName($p), round($p['ep'], 4)],
                    'tag_kuerzel' => [$p['datum'], $p['kuerzel'], $katName($p), round($p['ep'], 4)],
                    default       => [$katName($p), round($p['ep'], 4)],
                };
                $gruppen[json_encode($schluessel)][] = $p;
            }
            $out = [];
            foreach ($gruppen as $ps) {
                $p = $ps[0];
                $daten = array_filter(array_column($ps, 'datum'), static fn($d) => self::datum($d) !== '');
                $name = match ($darstellung) {
                    'tag'         => $katName($p) . ' – ' . self::de($p['datum']),
                    'tag_kuerzel' => $katName($p) . ' – ' . self::de($p['datum']) . ($p['kuerzel'] !== '' ? ' (' . $p['kuerzel'] . ')' : ''),
                    default       => 'Arbeitszeit ' . $katName($p),
                };
                $zeitraum = $daten && $darstellung === 'kategorie'
                    ? 'Leistungszeitraum ' . self::de(min($daten)) . (min($daten) !== max($daten) ? ' – ' . self::de(max($daten)) : '')
                    : '';
                $out[] = ['name' => $name, 'description' => $zeitraum, 'menge' => array_sum(array_column($ps, 'menge')), 'einheit' => 'Std', 'ep' => $p['ep']];
            }
            return array_values(array_filter($out, static fn($z) => abs($z['menge']) > 0.00001));
        }

        return array_map(static fn($p) => [
            'name' => $p['bezeichnung'] !== '' ? $p['bezeichnung'] : 'Position',
            'description' => $p['artikelNr'] !== '' ? 'Art.-Nr. ' . $p['artikelNr'] : '',
            'menge' => $p['menge'], 'einheit' => $p['einheit'], 'ep' => $p['ep'],
        ], $gruppe);
    }

    private static function zeile(array $z, float $satz): array
    {
        $li = ['type' => 'custom', 'name' => mb_substr($z['name'], 0, 255)];
        if ($z['description'] !== '') {
            $li['description'] = $z['description'];
        }
        // Gutschrift (negative Menge): positive Menge mit negativem Einzelpreis.
        $menge = round((float) $z['menge'], 4);
        $ep = round((float) $z['ep'], 4);
        if ($menge < 0) {
            [$menge, $ep] = [-$menge, -$ep];
        }
        return $li + [
            'quantity'           => $menge,
            'unitName'           => mb_substr($z['einheit'] !== '' ? $z['einheit'] : 'Stk', 0, 50),
            'unitPrice'          => ['currency' => 'EUR', 'netAmount' => $ep, 'taxRatePercentage' => $satz],
            'discountPercentage' => 0,
        ];
    }

    private static function datum(mixed $d): string
    {
        $d = substr(trim((string) $d), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : '';
    }

    private static function de(string $d): string
    {
        $d = self::datum($d);
        return $d === '' ? 'ohne Datum' : substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4);
    }

    private static function zahl(float $z): string
    {
        return rtrim(rtrim(number_format($z, 4, ',', ''), '0'), ',');
    }
}

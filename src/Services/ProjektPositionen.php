<?php

namespace App\Services;

/**
 * Abrechenbare Projektpositionen (Material, Arbeitszeit, Pauschalen) mit Verkaufspreis –
 * eine Rechenquelle für IN-FORM-Export und Lexware-Übertragung (AP-20261126, B4, E-086).
 * JS-Gegenstück der Materialformel: `matVk()` in public/script.js.
 */
final class ProjektPositionen
{
    /**
     * @param array<string,mixed> $projekt          Projekt-JSON (baustellen.data)
     * @param array<int,array{name:string,preis:float}> $pauschalen Katalog, Preis in Euro
     * @param array<int,string> $stundenKategorien  stunden_katalog.id → Kategoriename
     * @return list<array{art:string,id:string,bezeichnung:string,artikelNr:string,menge:float,einheit:string,ep:float,kategorie:string,datum:string,kuerzel:string}>
     */
    public static function aus(array $projekt, array $pauschalen, array $stundenKategorien = []): array
    {
        $matKat = [];
        foreach ((array) ($projekt['matKategorien'] ?? []) as $k) {
            $matKat[(string) ($k['id'] ?? '')] = (string) ($k['name'] ?? '');
        }
        $out = [];
        foreach ((array) ($projekt['material'] ?? []) as $m) {
            $out[] = [
                'art'         => 'material',
                'id'          => (string) ($m['id'] ?? ''),
                'bezeichnung' => (string) ($m['bezeichnung'] ?? ''),
                'artikelNr'   => (string) ($m['artikelNr'] ?? $m['artikelnr'] ?? ''),
                'menge'       => (float) ($m['anzahl'] ?? 0),
                'einheit'     => (string) (($m['einheit'] ?? '') ?: 'Stk'),
                'ep'          => self::materialVk($m, (float) ($projekt['materialAufschlagGlobal'] ?? 0)),
                'kategorie'   => $matKat[(string) ($m['kategorieId'] ?? '')] ?? '',
                'datum'       => (string) ($m['datum'] ?? ''),
                'kuerzel'     => (string) ($m['erstelltVon'] ?? ''),
            ];
        }
        foreach ((array) ($projekt['arbeitszeit'] ?? []) as $a) {
            $kat = $stundenKategorien[(int) ($a['stundenKatId'] ?? 0)] ?? '';
            $out[] = [
                'art'         => 'arbeitszeit',
                'id'          => (string) ($a['id'] ?? ''),
                'bezeichnung' => (string) (($a['beschreibung'] ?? '') ?: 'Arbeitszeit'),
                'artikelNr'   => '',
                'menge'       => (float) ($a['stunden'] ?? 0),
                'einheit'     => 'Std',
                'ep'          => (float) ($a['stundenpreis'] ?? 0),
                'kategorie'   => $kat,
                'datum'       => (string) ($a['datum'] ?? ''),
                'kuerzel'     => (string) ($a['erstelltVon'] ?? ''),
            ];
        }
        foreach ((array) ($projekt['pauschalen'] ?? []) as $p) {
            $katalogId = (int) (is_array($p) ? ($p['id'] ?? 0) : $p);
            $def = $pauschalen[$katalogId] ?? null;
            $out[] = [
                'art'         => 'pauschale',
                'id'          => (string) (is_array($p) && isset($p['entryId']) ? $p['entryId'] : $katalogId),
                'bezeichnung' => (string) ($def['name'] ?? 'Pauschale'),
                'artikelNr'   => '',
                'menge'       => (float) (is_array($p) ? ($p['anzahl'] ?? 1) : 1),
                'einheit'     => 'psch',
                'ep'          => (float) ($def['preis'] ?? 0),
                'kategorie'   => '',
                'datum'       => (string) (is_array($p) ? ($p['datum'] ?? '') : ''),
                'kuerzel'     => '',
            ];
        }
        return $out;
    }

    /** Zeilenbetrag: Menge (4 Stellen) × Einzelpreis (4 Stellen), auf Cent – gilt für Export und Lexware. */
    public static function zeilenbetrag(float $menge, float $ep): float
    {
        return round(round($menge, 4) * round($ep, 4), 2);
    }

    /** Verkaufs-Einzelpreis Material (EK × Aufschlag × globaler Aufschlag × Rabatt bzw. VK direkt). */
    public static function materialVk(array $m, float $globalAufschlag): float
    {
        $ek = (float) ($m['ek'] ?? 0);
        $rabattF = 1 - ((float) ($m['rabatt'] ?? 0)) / 100;
        if (!empty($m['vkDirekt'])) {
            return $ek * $rabattF;
        }
        return $ek * (1 + ((float) ($m['aufschlag'] ?? 0)) / 100) * (1 + $globalAufschlag / 100) * $rabattF;
    }

    /** Pauschalenkatalog aus der DB (Preis in Cent gespeichert). @return array<int,array{name:string,preis:float}> */
    public static function pauschalenKatalog(\PDO $db): array
    {
        $out = [];
        foreach ($db->query('SELECT id, name, preis FROM pauschalen ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $out[(int) $p['id']] = ['name' => (string) $p['name'], 'preis' => money_from_cents($p['preis'])];
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function stundenKategorien(\PDO $db): array
    {
        $out = [];
        foreach ($db->query('SELECT id, kategorie FROM stunden_katalog')->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $out[(int) $s['id']] = (string) $s['kategorie'];
        }
        return $out;
    }
}

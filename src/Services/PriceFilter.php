<?php
namespace App\Services;

use App\Auth;

/**
 * Zentrale Preis-/Finanzfeld-Filterung für API-Antworten.
 *
 * Wird vor Auslieferung von JSON-/CSV-/Export-Daten angewandt, wenn der
 * aktuelle Benutzer keine Berechtigung 'canSeePrices' hat. Entfernt
 * rekursiv alle bekannten Preis- und Margenfelder, sodass selbst über
 * Exporte oder Sicherungen keine Preise an unberechtigte Nutzer gelangen.
 */
class PriceFilter
{
    /**
     * Liste der zu entfernenden Schlüssel (case-sensitive Treffer + Suffixe).
     * Reine Mengen/Stunden bleiben erhalten — nur monetäre Felder fallen weg.
     */
    private const PRICE_KEYS = [
        // Material
        'ek', 'vk', 'ep', 'gp', 'aufschlag', 'preis', 'einzelpreis', 'gesamtpreis',
        'matEk', 'matVk',
        'materialAufschlagGlobal',
        // Arbeitszeit
        'stundenpreis', 'stundensatz', 'stundensatzFk', 'stundensatzEin',
        'fixkosten', 'azEin', 'azFk',
        // Pauschalen / Aggregat
        'pSum', 'gesamt', 'gewinn', 'umsatz', 'kosten',
        // Rechnung / Auswertung
        'netto', 'brutto', 'mwst', 'rabatt', 'skonto', 'abSum', 'offen',
        'betrag', 'summe', 'zwischensumme',
    ];

    /**
     * Wendet den Filter an, wenn der aktuelle User keine Preise sehen darf.
     * Liefert die Daten unverändert zurück, wenn er sie sehen darf.
     */
    public static function apply(\PDO $db, mixed $data): mixed
    {
        if (Auth::canDo($db, 'canSeePrices')) return $data;
        return self::strip($data);
    }

    /**
     * Erzwingt das Strippen unabhängig von Rechten (z. B. für Logs).
     */
    public static function force(mixed $data): mixed
    {
        return self::strip($data);
    }

    /** Rekursive Tiefenwanderung ohne Rückgriff auf canDo(). */
    private static function strip(mixed $data): mixed
    {
        if (!is_array($data)) return $data;

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isPriceKey($key)) {
                continue; // Feld komplett entfernen
            }
            if (is_array($value)) {
                $out[$key] = self::strip($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function isPriceKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::PRICE_KEYS as $needle) {
            $n = strtolower($needle);
            // Exakter Treffer
            if ($lower === $n) return true;
            // Suffix-/Präfix-Treffer (preis_netto, gesamt_brutto, ek_pro_m)
            if (str_starts_with($lower, $n . '_') || str_ends_with($lower, '_' . $n)) {
                return true;
            }
        }
        // Generische Suffix-Erkennung
        if (str_ends_with($lower, '_netto') || str_ends_with($lower, '_brutto')
            || str_starts_with($lower, 'preis') || str_starts_with($lower, 'kosten')) {
            return true;
        }
        return false;
    }
}

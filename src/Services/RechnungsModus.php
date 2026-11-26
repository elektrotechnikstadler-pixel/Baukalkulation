<?php

namespace App\Services;

/**
 * Betriebsart „Rechnungen erzeugen in“ (AP-20261126-lexoffice-schnittstelle, 2.2).
 * Einzige Stelle für die Entscheidung, ob Angebote/Rechnungen in der Baukalkulation
 * erzeugt werden dürfen; JS-Gegenstück `public/rechnungen-modus.js`, gemeinsame
 * Fallliste `tests/fixtures/lexoffice/rechnungen-modus.json`.
 */
final class RechnungsModus
{
    public const BAUKALKULATION = 'baukalkulation';
    public const LEXWARE        = 'lexware';
    public const MELDUNG_DEAKTIVIERT = 'Angebote und Rechnungen werden in Lexware Office erzeugt (Verwaltung → Allgemein → Schnittstellen).';

    /** Neue Belege, Löschen und Versand in der Baukalkulation erlaubt? */
    public static function aktiv(array $settings): bool
    {
        if (($settings['modul_rechnungen'] ?? true) === false) {
            return false;
        }
        return !self::inLexware($settings);
    }

    /** Liste/Formular sichtbar? In Betriebsart Lexware nur noch für Admins (Archiv). */
    public static function lesbar(array $settings, string $rolle): bool
    {
        if (($settings['modul_rechnungen'] ?? true) === false) {
            return false;
        }
        return !self::inLexware($settings) || $rolle === 'admin';
    }

    public static function inLexware(array $settings): bool
    {
        return ($settings['modul_lexoffice'] ?? false) === true
            && ($settings['rechnung_erzeugung'] ?? self::BAUKALKULATION) === self::LEXWARE;
    }
}

<?php
// ============================================================
// LicenseService – Lokale Lizenzverwaltung (v1.0.0)
// ============================================================
// Liest data/license.json und entscheidet, welche Module für
// diese Installation freigeschaltet sind.
//
// Dev-Modus (keine license.json): alle Module erlaubt.
// Abgelaufene Lizenz: keine Module freigeschaltet.
//
// Format data/license.json:
//   {
//     "customer": "Mustermann GmbH",
//     "issued":   "2026-01-01",
//     "expires":  "2027-12-31",
//     "tier":     "professional",
//     "modules":  ["din1090", "lager", "aufmass"]
//   }
//
// Sonderfall "modules": ["*"] → alle Module freigeschaltet
// (wird im Dev-Modus ohne license.json automatisch gesetzt).
//
// Sicherheitshinweis: Die license.json wird ausschließlich
// server-seitig ausgewertet. Der Kunde bekommt im Frontend
// nur die Liste der tatsächlich erlaubten Module.
// ============================================================

namespace App\Services;

final class LicenseService
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    // ── Datei laden & cachen ─────────────────────────────────
    private static function load(): array
    {
        if (self::$cache !== null) return self::$cache;

        $path = (defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/../../data/') . 'license.json';

        if (!file_exists($path)) {
            // Dev-Modus: keine Lizenzdatei vorhanden → alles erlaubt
            return self::$cache = ['tier' => 'dev', 'modules' => ['*']];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            error_log('[LicenseService] Lizenzdatei nicht lesbar: ' . $path);
            return self::$cache = ['tier' => 'dev', 'modules' => ['*']];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('[LicenseService] Ungültige JSON-Struktur in license.json');
            return self::$cache = ['tier' => 'dev', 'modules' => ['*']];
        }

        // Ablaufdatum prüfen
        if (!empty($data['expires']) && strtotime((string)$data['expires']) < time()) {
            error_log('[LicenseService] Lizenz abgelaufen am ' . $data['expires']);
            return self::$cache = ['tier' => 'expired', 'modules' => []];
        }

        return self::$cache = $data;
    }

    // ── Öffentliche API ──────────────────────────────────────

    /**
     * Aktiver Lizenz-Tier.
     * Mögliche Werte: "dev" | "basis" | "standard" | "professional" | "enterprise" | "expired"
     */
    public static function getActiveTier(): string
    {
        return (string)(self::load()['tier'] ?? 'basis');
    }

    /**
     * Gibt alle explizit freigeschalteten Modulnamen zurück.
     * ["*"] bedeutet: alle Module erlaubt (Dev-Modus / Volllizenz).
     *
     * @return string[]
     */
    public static function getEnabledModules(): array
    {
        return (array)(self::load()['modules'] ?? ['*']);
    }

    /**
     * Prüft ob ein einzelnes Modul lizenziert ist.
     * Wildcard ["*"] erlaubt alle Module (Dev-Modus oder Volllizenz).
     */
    public static function isModuleAllowed(string $name): bool
    {
        $modules = self::getEnabledModules();
        if (in_array('*', $modules, true)) return true;
        return in_array($name, $modules, true);
    }

    /**
     * Öffentliche Lizenz-Metadaten für die Frontend-Check-Response.
     * Enthält keine sensiblen Felder (kein Signatur-Schlüssel o.Ä.).
     *
     * @return array{tier: string, customer: string|null, expires: string|null}
     */
    public static function getPublicInfo(): array
    {
        $data = self::load();
        return [
            'tier'     => $data['tier']     ?? 'basis',
            'customer' => $data['customer'] ?? null,
            'expires'  => $data['expires']  ?? null,
        ];
    }

    /** Cache leeren – sinnvoll nach Lizenz-Update im laufenden PHP-Request. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}

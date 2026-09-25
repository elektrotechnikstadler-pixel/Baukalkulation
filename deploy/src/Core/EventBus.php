<?php
// ============================================================
// EventBus – Modul-übergreifende Events (Backend)
// ============================================================
// Leichtgewichtiger Pub/Sub-Mechanismus. Module melden sich mit
// EventBus::on('rechnung.created', fn($p) => ...) an, der Core
// (oder andere Module) lösen mit EventBus::dispatch(...) aus.
//
// Fehler einzelner Listener werden geloggt, brechen die Dispatch-
// Schleife aber nicht ab – Robustheit > Strict-Fail.
// ============================================================

namespace App\Core;

final class EventBus
{
    /** @var array<string, array<int, array{cb:callable,priority:int}>> */
    private static array $listeners = [];

    /** Listener registrieren. Niedrige Priority läuft zuerst. */
    public static function on(string $event, callable $cb, int $priority = 10): void
    {
        self::$listeners[$event][] = ['cb' => $cb, 'priority' => $priority];
        usort(self::$listeners[$event], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /** Event auslösen. Liefert Array der Listener-Rückgaben. */
    public static function dispatch(string $event, array $payload = []): array
    {
        $results = [];
        foreach (self::$listeners[$event] ?? [] as $l) {
            try {
                $results[] = ($l['cb'])($payload);
            } catch (\Throwable $e) {
                error_log('[EventBus] ' . $event . ': ' . $e->getMessage()
                        . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        return $results;
    }

    /** Listener entfernen (nur für Tests). */
    public static function reset(?string $event = null): void
    {
        if ($event === null) {
            self::$listeners = [];
        } else {
            unset(self::$listeners[$event]);
        }
    }

    /** Liste aller registrierten Events (Debug/Admin). */
    public static function registeredEvents(): array
    {
        return array_keys(self::$listeners);
    }
}

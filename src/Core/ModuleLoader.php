<?php
// ============================================================
// ModuleLoader – Discovery, Migration, Dispatch (v2.0.0)
// ============================================================
// Sucht modules/<name>/module.json, lädt Backend-Klassen via
// require_once (auch ohne PSR-4-Eintrag), löst depends_on auf,
// führt Migrationen aus und routet API-Calls.
//
// Sicherheit:
//  - Auth::requireAuth() vor jeder Modul-Aktion
//  - Rollen-Whitelist aus manifest.permissions
//  - Optional feature_flag-Gate (function_exists feature_enabled)
//  - Modulnamen werden auf [a-z0-9_] gefiltert (kein Path-Traversal)
// ============================================================

namespace App\Core;

use App\Auth;
use App\Services\LicenseService;

final class ModuleLoader
{
    private \PDO $db;
    /** @var array<string, array<string,mixed>> */
    private array $modules = [];
    private bool  $migrated = false;

    public function __construct(\PDO $db, ?string $modulesDir = null)
    {
        $this->db = $db;
        $dir = $modulesDir ?? (defined('MODULES_DIR') ? MODULES_DIR : __DIR__ . '/../../modules/');
        $this->discover($dir);
    }

    // ── Discovery ────────────────────────────────────────────
    private function discover(string $modulesDir): void
    {
        $modulesDir = rtrim($modulesDir, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($modulesDir)) return;
        foreach (glob($modulesDir . '*/module.json') ?: [] as $manifestFile) {
            $raw = @file_get_contents($manifestFile);
            if ($raw === false) continue;
            $m = json_decode($raw, true);
            if (!is_array($m) || empty($m['name'])) {
                error_log('[ModuleLoader] Ungültiges Manifest: ' . $manifestFile);
                continue;
            }
            // Sicherheit: Modulname darf nur a-z 0-9 _ enthalten
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $m['name'])) {
                error_log('[ModuleLoader] Unsicherer Modulname: ' . $m['name']);
                continue;
            }
            $m['path'] = dirname($manifestFile);
            $this->modules[$m['name']] = $m;
        }
        $this->modules = $this->sortByDependencies($this->modules);
    }

    /** Topologische Sortierung anhand depends_on. */
    private function sortByDependencies(array $mods): array
    {
        $sorted = [];
        $visiting = [];
        $visit = function (string $name) use (&$visit, &$sorted, &$visiting, $mods) {
            if (isset($sorted[$name])) return;
            if (isset($visiting[$name])) {
                error_log('[ModuleLoader] Zyklische Abhängigkeit bei ' . $name);
                return;
            }
            $visiting[$name] = true;
            foreach ($mods[$name]['depends_on'] ?? [] as $dep) {
                if (isset($mods[$dep])) $visit($dep);
            }
            unset($visiting[$name]);
            $sorted[$name] = $mods[$name];
        };
        foreach (array_keys($mods) as $n) $visit($n);
        return $sorted;
    }

    // ── Klassenladung ────────────────────────────────────────
    private function loadClass(array $m): string
    {
        $file = $m['path'] . DIRECTORY_SEPARATOR . ($m['module_file'] ?? 'backend/Module.php');
        if (!file_exists($file)) {
            throw new \RuntimeException("Modul-Datei fehlt: $file");
        }
        require_once $file;
        $cls = $m['module_class'] ?? null;
        if (!$cls || !class_exists($cls)) {
            throw new \RuntimeException("Modul-Klasse nicht gefunden: " . ($cls ?? '?'));
        }
        return $cls;
    }

    // ── Migration ────────────────────────────────────────────
    public function migrateAll(bool $registerEvents = true): void
    {
        if ($this->migrated) return;
        foreach ($this->modules as $m) {
            try {
                $cls = $this->loadClass($m);
                if (method_exists($cls, 'migrate')) {
                    $cls::migrate($this->db);
                }
                if ($registerEvents && method_exists($cls, 'registerEvents')) {
                    $cls::registerEvents($this->db);
                }
            } catch (\Throwable $e) {
                error_log('[ModuleLoader] Migration ' . ($m['name'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        $this->migrated = true;
    }

    // ── Auth/Feature-Check ───────────────────────────────────
    private function userHasAccess(array $m): bool
    {
        if (empty($_SESSION['authenticated'])) return false;
        $perm = $m['permissions'] ?? null;
        if (is_array($perm) && count($perm) > 0) {
            $role = $_SESSION['role'] ?? 'normal';
            if (!in_array($role, $perm, true)) return false;
        }
        if (!empty($m['feature_flag']) && function_exists('feature_enabled')) {
            if (!\feature_enabled($m['feature_flag'])) return false;
        }
        // Lizenz-Gating: Modul muss in data/license.json freigeschaltet sein
        if (!empty($m['name']) && !LicenseService::isModuleAllowed($m['name'])) return false;
        return true;
    }

    // ── Dispatch ─────────────────────────────────────────────
    public function dispatch(string $module, string $action, array $body): void
    {
        Auth::requireAuth();
        $module = preg_replace('/[^a-z0-9_]/i', '', $module) ?? '';
        $m = $this->modules[$module] ?? null;
        if (!$m) {
            \jsonOut(['error' => 'Unbekanntes Modul: ' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8')], 404);
        }
        if (!$this->userHasAccess($m)) {
            \jsonOut(['error' => 'Keine Berechtigung für dieses Modul.'], 403);
        }
        // Aktion-Whitelist optional aus Manifest (allowed_actions)
        if (!empty($m['allowed_actions']) && !in_array($action, $m['allowed_actions'], true)) {
            \jsonOut(['error' => 'Aktion in diesem Modul nicht zugelassen.'], 400);
        }
        try {
            $cls = $this->loadClass($m);
        } catch (\Throwable $e) {
            error_log('[ModuleLoader] dispatch: ' . $e->getMessage());
            \jsonOut(['error' => 'Modul konnte nicht geladen werden.'], 500);
        }
        /** @var AbstractModule $h */
        $h = new $cls($this->db, $m, $body);
        $h->dispatch($action);
    }

    // ── Frontend-Manifest ────────────────────────────────────
    /** Liefert Liste aller für den aktuellen User sichtbaren Module. */
    public function listForFrontend(): array
    {
        $out = [];
        foreach ($this->modules as $m) {
            if (!$this->userHasAccess($m)) continue;
            $out[] = [
                'name'     => $m['name'],
                'title'    => $m['title']    ?? $m['name'],
                'icon'     => $m['icon']     ?? '🧩',
                'version'  => $m['version']  ?? '0.0.0',
                'tier'     => $m['tier']     ?? 'basis',
                'frontend' => $m['frontend'] ?? null,
            ];
        }
        return $out;
    }

    /** Alle Module (auch ohne Auth) – für Admin/Debug. */
    public function listAll(): array
    {
        return array_values($this->modules);
    }
}

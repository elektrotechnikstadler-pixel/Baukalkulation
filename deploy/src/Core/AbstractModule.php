<?php
// ============================================================
// AbstractModule – Basisklasse für alle Module (v2.0.0)
// ============================================================
// Jedes Modul liefert eine Klasse, die diese erweitert. Der
// ModuleLoader instanziiert sie pro Request. dispatch() ist die
// einzige Pflichtmethode; migrate() kann Tabellen anlegen.
// ============================================================

namespace App\Core;

abstract class AbstractModule
{
    protected \PDO   $db;
    protected array  $manifest;
    protected array  $body;
    protected string $user;

    public function __construct(\PDO $db, array $manifest, array $body = [])
    {
        $this->db       = $db;
        $this->manifest = $manifest;
        $this->body     = $body;
        $this->user     = $_SESSION['username'] ?? '';
    }

    /** Pflicht: Aktion ausführen, JSON-Response liefern. */
    abstract public function dispatch(string $action): void;

    /** Optional: Tabellen anlegen. Idempotent (CREATE TABLE IF NOT EXISTS). */
    public static function migrate(\PDO $db): void
    {
        // Default: nichts zu tun
    }

    /** Optional: Listener registrieren. Wird beim Boot aufgerufen. */
    public static function registerEvents(\PDO $db): void
    {
        // Default: keine Events
    }

    public function manifest(): array { return $this->manifest; }
}

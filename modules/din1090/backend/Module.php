<?php
// ============================================================
// DIN EN 1090 – Modul-Adapter für ModuleLoader (v2.0.0)
// ============================================================
// Brücke zwischen neuem ModuleLoader und bestehendem
// Din1090Actions. Dadurch bleibt der Legacy-Endpunkt
// din1090_api.php parallel funktional, OHNE den ursprünglichen
// Code anzufassen.
// ============================================================

namespace App\Modules\Din1090;

use App\Core\AbstractModule;

require_once __DIR__ . '/../Din1090Database.php';
require_once __DIR__ . '/../Din1090Actions.php';

class Module extends AbstractModule
{
    public static function migrate(\PDO $db): void
    {
        \Din1090Database::init($db);
    }

    public function dispatch(string $action): void
    {
        // Legacy-Erwartung: $body['sub'] = Aktion
        $body = $this->body;
        if (!isset($body['sub']) || $body['sub'] === '') {
            $body['sub'] = $action;
        }
        $handler = new \Din1090Actions($this->db, $body);
        $handler->dispatch();
    }
}

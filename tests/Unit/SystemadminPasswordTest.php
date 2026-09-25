<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SystemadminPassword;
use PHPUnit\Framework\TestCase;

final class SystemadminPasswordTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('BK_SYSTEMADMIN_PASSWORD');
    }

    public function testPasswortAusUmgebungsvariable(): void
    {
        putenv('BK_SYSTEMADMIN_PASSWORD=Support-Zugang-2026');

        $this->assertTrue(password_verify('Support-Zugang-2026', SystemadminPassword::newHash()));
    }

    public function testZuKurzesPasswortAusUmgebungsvariableWirdAbgelehnt(): void
    {
        putenv('BK_SYSTEMADMIN_PASSWORD=kurz');

        $this->expectException(\RuntimeException::class);
        SystemadminPassword::newHash();
    }
}

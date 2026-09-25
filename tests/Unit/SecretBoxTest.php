<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('BK_SECRET_KEY=' . base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    protected function tearDown(): void
    {
        putenv('BK_SECRET_KEY');
    }

    public function testRundreise(): void
    {
        $enc = SecretBox::encrypt('Geheim-Passwort ÄÖÜ');

        $this->assertStringStartsWith('enc:v1:', $enc);
        $this->assertStringNotContainsString('Geheim', $enc);
        $this->assertSame('Geheim-Passwort ÄÖÜ', SecretBox::decrypt($enc));
    }

    public function testGleicherKlartextErgibtUnterschiedlicheWerte(): void
    {
        $this->assertNotSame(SecretBox::encrypt('x'), SecretBox::encrypt('x'));
    }

    public function testKlartextAltwerteBleibenLesbar(): void
    {
        $this->assertSame('altes-passwort', SecretBox::decrypt('altes-passwort'));
        $this->assertSame('', SecretBox::encrypt(''));
    }

    public function testFalscherSchluesselWirdErkannt(): void
    {
        $enc = SecretBox::encrypt('x');
        putenv('BK_SECRET_KEY=' . base64_encode(str_repeat('z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->expectException(\RuntimeException::class);
        SecretBox::decrypt($enc);
    }
}

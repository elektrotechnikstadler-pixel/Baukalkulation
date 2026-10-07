<?php

declare(strict_types=1);

namespace Tests\Api;

final class ZeitErinnerungTest extends ApiTestCase
{
    public function testGeplanterMonteurOhneBuchungWirdGemeldet(): void
    {
        $this->setupErinnerung('2025-03-18');

        [$code, $out] = $this->server->runCli('bin/console', ['zeit:erinnerung', '--datum=2025-03-18', '--force']);

        self::assertSame(0, $code, $out);
        $result = $this->readErinnerungResult();
        self::assertSame('2025-03-18', $result['datum']);
        self::assertSame('monteur', $result['fehlend'][0]['username']);
        self::assertSame(['Baustelle A'], $result['fehlend'][0]['baustellen']);
    }

    public function testAusZeitverwaltungAusgeblendeterGeplanterMonteurOhneBuchungWirdGemeldet(): void
    {
        $this->setupErinnerung('2025-03-18');
        $this->assertOk($this->api->post('set_show_in_zeitverwaltung', ['username' => 'monteur', 'show' => false]));

        [$code, $out] = $this->server->runCli('bin/console', ['zeit:erinnerung', '--datum=2025-03-18', '--force']);

        self::assertSame(0, $code, $out);
        $result = $this->readErinnerungResult();
        self::assertSame('2025-03-18', $result['datum']);
        self::assertSame('monteur', $result['fehlend'][0]['username']);
        self::assertSame(['Baustelle A'], $result['fehlend'][0]['baustellen']);
    }

    public function testUrlaubseintragZaehltAlsGebucht(): void
    {
        $user = $this->setupErinnerung('2025-03-18');
        $this->assertOk($user->post('save_zeiterfassung', ['entries' => [
            $this->eintrag('urlaub-1', '2025-03-18', 'urlaub', 0),
        ]]));

        [$code, $out] = $this->server->runCli('bin/console', ['zeit:erinnerung', '--datum=2025-03-18', '--force']);

        self::assertSame(0, $code, $out);
        self::assertSame([], $this->readErinnerungResult()['fehlend']);
    }

    public function testFeiertagWirdNichtGeprueft(): void
    {
        $this->setupErinnerung('2025-04-18');

        [$code, $out] = $this->server->runCli('bin/console', ['zeit:erinnerung', '--datum=2025-04-18', '--force']);

        self::assertSame(0, $code, $out);
        self::assertSame([], $this->readErinnerungResult()['fehlend']);
    }

    public function testDeaktivierteStundenerinnerungPrueftNichts(): void
    {
        $this->setupErinnerung('2025-03-18');
        $this->assertOk($this->api->post('save_erinnerung_settings', ['stunden_aktiv' => false]));

        [$code, $out] = $this->server->runCli('bin/console', ['zeit:erinnerung', '--datum=2025-03-18', '--force']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('deaktiviert', mb_strtolower($out));
        self::assertFileDoesNotExist($this->server->dataPath('stunden_erinnerung_result.json'));
    }

    public function testOhneDatumLaeuftNurEinmalProTagUndProtokolliertKeineSecrets(): void
    {
        $datum = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->setupErinnerung($datum);
        $secrets = [self::ADMIN_PASS, 'Monteur-Pass-1', 'SMTP-Geheim-123', 'Gemini-Geheim-123'];
        $this->assertOk($this->api->post('save_settings', [
            'smtp_pass' => 'SMTP-Geheim-123',
            'gemini_api_key' => 'Gemini-Geheim-123',
        ]));

        [$code1, $out1] = $this->server->runCli('bin/console', ['zeit:erinnerung']);

        self::assertSame(0, $code1, $out1);
        $resultPath = $this->server->dataPath('stunden_erinnerung_result.json');
        self::assertFileExists($resultPath);
        $resultVorher = (string) file_get_contents($resultPath);

        [$code2, $out2] = $this->server->runCli('bin/console', ['zeit:erinnerung']);

        self::assertSame(0, $code2, $out2);
        self::assertStringContainsString('bereits geprüft', $out2);
        self::assertSame($resultVorher, (string) file_get_contents($resultPath));

        $log = (string) file_get_contents($this->server->dataPath('erinnerung.log'));
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $out1 . $out2 . $log);
        }
    }

    private function setupErinnerung(string $datum): \Tests\Support\ApiClient
    {
        $this->setupAdmin();
        $user = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => 'monteur',
            'sollstundenTag' => 8,
            'arbeitstage' => '1,2,3,4,5',
        ]));
        $this->saveBaustellen([['id' => 1, 'name' => 'Baustelle A']]);
        $this->assertOk($this->api->post('save_wochenplanung', [
            'plan' => [
                'monteur' => [
                    $datum => [
                        ['typ' => 'baustelle', 'baustelleId' => 1],
                    ],
                ],
            ],
        ]));

        return $user;
    }

    /** @return array<string,mixed> */
    private function readErinnerungResult(): array
    {
        $path = $this->server->dataPath('stunden_erinnerung_result.json');
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        return $json;
    }

    private function eintrag(string $uuid, string $datum, string $typ, float $stunden): array
    {
        return [
            'clientUuid' => $uuid,
            'datum' => $datum,
            'typ' => $typ,
            'baustelleId' => 0,
            'stunden' => $stunden,
            'von' => '',
            'bis' => '',
            'pause' => 0,
            'bemerkung' => '',
        ];
    }
}

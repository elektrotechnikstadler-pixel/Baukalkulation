<?php
namespace App\Services;

/**
 * Verschlüsselt Geheimnisse (Passwörter in Einstellungen) mit libsodium.
 * Schlüssel: Env BK_SECRET_KEY (base64, 32 Byte) oder DATA_DIR/secret.key (wird angelegt).
 * Der Schlüssel liegt nie in der Datenbank und damit auch nicht in Sicherungen.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Unverschlüsselte Altwerte werden unverändert zurückgegeben. */
    public static function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) return $value;
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Verschlüsselter Wert ist beschädigt.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key(),
        );
        if ($plain === false) {
            throw new \RuntimeException('Wert kann mit dem vorhandenen Schlüssel nicht entschlüsselt werden (secret.key geändert?).');
        }
        return $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): string
    {
        $env = getenv('BK_SECRET_KEY');
        if (is_string($env) && $env !== '') {
            $key = base64_decode($env, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('BK_SECRET_KEY muss 32 Byte (base64) lang sein.');
            }
            return $key;
        }

        $file = DATA_DIR . 'secret.key';
        if (!is_file($file)) {
            try {
                $fh = fopen($file, 'x');
            } catch (\Throwable) {
                $fh = false; // parallel von einem anderen Prozess angelegt
            }
            if ($fh !== false) {
                fwrite($fh, base64_encode(sodium_crypto_secretbox_keygen()));
                fclose($fh);
                chmod($file, 0600);
            }
        }
        $key = base64_decode(trim((string)file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Schlüsseldatei secret.key ist ungültig.');
        }
        return $key;
    }
}

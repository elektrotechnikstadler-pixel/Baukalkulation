<?php
namespace App\Services;

/**
 * Firmenlogo: einzig zulässig ist DATA_DIR/firma_logo.{png,jpg,jpeg,gif,webp} mit Bild-MIME.
 * Die Einstellung firma_logo_url kann aus Sicherungen stammen und wird deshalb nie als Pfad verwendet.
 */
final class FirmenLogo
{
    private const PATTERN = '~\A/?data/firma_logo\.(png|jpe?g|gif|webp)\z~';
    private const MIME_BY_EXT = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public static function isValidSetting(string $value): bool
    {
        return $value === '' || preg_match(self::PATTERN, $value) === 1;
    }

    /** @return array{path: string, mime: string}|null */
    public static function resolve(array $settings, ?string $dataDir = null): ?array
    {
        $value = $settings['firma_logo_url'] ?? '';
        if (!is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }
        $name = 'firma_logo.' . $m[1];
        $dir  = realpath($dataDir ?? DATA_DIR);
        if ($dir === false) {
            return null;
        }
        $real = realpath($dir . DIRECTORY_SEPARATOR . $name);
        // realpath löst Symlinks auf: Ziel muss selbst im Datenverzeichnis liegen.
        if ($real === false || !is_file($real) || dirname($real) !== $dir || basename($real) !== $name) {
            return null;
        }
        $mime = class_exists(\finfo::class)
            ? (new \finfo(FILEINFO_MIME_TYPE))->file($real)
            : self::MIME_BY_EXT[$m[1]];
        if (!in_array($mime, self::MIME_BY_EXT, true)) {
            return null;
        }
        return ['path' => $real, 'mime' => $mime];
    }

    /** Logo als data:-URI für PDF/HTML, sonst ''. */
    public static function dataUri(array $settings): string
    {
        $logo = self::resolve($settings);
        if ($logo === null) {
            return '';
        }
        $data = file_get_contents($logo['path']);
        return $data === false ? '' : 'data:' . $logo['mime'] . ';base64,' . base64_encode($data);
    }
}

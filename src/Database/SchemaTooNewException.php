<?php
namespace App\Database;

final class SchemaTooNewException extends \RuntimeException
{
    public function __construct(public readonly int $dbVersion, public readonly int $codeVersion)
    {
        parent::__construct(
            "Die Datenbank (Schema {$dbVersion}) ist neuer als diese App-Version (Schema {$codeVersion}). "
            . 'Bitte die passende oder eine neuere App-Version einspielen.'
        );
    }
}

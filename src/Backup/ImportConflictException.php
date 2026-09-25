<?php
namespace App\Backup;

final class ImportConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Daten wurden gerade von jemand anderem geändert. Es wurden KEINE Daten verändert. Bitte erneut versuchen.');
    }
}

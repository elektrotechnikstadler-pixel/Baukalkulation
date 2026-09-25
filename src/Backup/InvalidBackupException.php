<?php
namespace App\Backup;

final class InvalidBackupException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $passwordRequired = false)
    {
        parent::__construct($message);
    }
}

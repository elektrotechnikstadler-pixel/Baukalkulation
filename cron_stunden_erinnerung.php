<?php
declare(strict_types=1);

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bin/console') . ' zeit:erinnerung';
foreach (array_slice($argv ?? [], 1) as $arg) {
    $cmd .= ' ' . escapeshellarg((string)$arg);
}

passthru($cmd, $code);
exit((int)$code);
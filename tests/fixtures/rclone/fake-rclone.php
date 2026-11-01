<?php

declare(strict_types=1);

/**
 * Fake-rclone fuer Tests: protokolliert jeden Aufruf als JSON-Zeile und liefert
 * konfigurierbare Antworten ueber eine Steuerdatei im HOME/run-Verzeichnis.
 */
$argv = $_SERVER['argv'] ?? [];
$command = (string)($argv[1] ?? '');
$runDir = (string)(getenv('HOME') ?: getcwd());
$logFile = (string)(getenv('FAKE_RCLONE_LOG') ?: ($runDir . DIRECTORY_SEPARATOR . 'fake-rclone-log.jsonl'));
$controlFile = (string)(getenv('FAKE_RCLONE_CONTROL') ?: ($runDir . DIRECTORY_SEPARATOR . 'fake-rclone-control.json'));

$stdin = (string)stream_get_contents(STDIN);
$response = fakeRclonePopResponse($controlFile, $command);

if (($response['sleepMs'] ?? 0) > 0) {
    usleep((int)$response['sleepMs'] * 1000);
}

$stdout = (string)($response['stdout'] ?? '');
$stderr = (string)($response['stderr'] ?? '');
$exitCode = (int)($response['exitCode'] ?? 0);

$mode = (string)($response['mode'] ?? '');
if ($mode !== '') {
    if ($mode === 'logon-invalid') {
        $stderr = 'The attempted logon is invalid';
        $exitCode = 1;
    } elseif ($mode === 'no-such-host') {
        $stderr = 'dial tcp: no such host';
        $exitCode = 1;
    } elseif ($mode === 'permission-denied') {
        $stderr = 'permission denied';
        $exitCode = 1;
    }
}

if (array_key_exists('stats', $response) && is_array($response['stats'])) {
    $stdout .= json_encode(['level' => 'notice', 'stats' => $response['stats']], JSON_UNESCAPED_SLASHES) . "
";
} elseif ($command === 'copy' && ($response['emitDefaultStats'] ?? true)) {
    $stdout .= json_encode(['level' => 'notice', 'stats' => ['bytes' => 0, 'errors' => 0, 'transfers' => 0]], JSON_UNESCAPED_SLASHES) . "
";
}

if ($command === 'obscure' && !array_key_exists('stdout', $response)) {
    $stdout = "fake-obscured-pass
";
}

$env = [
    'HOME' => (string)(getenv('HOME') ?: ''),
    'RCLONE_CONFIG' => (string)(getenv('RCLONE_CONFIG') ?: ''),
    'RCLONE_SMB_HOST' => (string)(getenv('RCLONE_SMB_HOST') ?: ''),
    'RCLONE_SMB_PORT' => (string)(getenv('RCLONE_SMB_PORT') ?: ''),
    'RCLONE_SMB_USER' => (string)(getenv('RCLONE_SMB_USER') ?: ''),
    'RCLONE_SMB_PASS' => (string)(getenv('RCLONE_SMB_PASS') ?: ''),
    'RCLONE_SMB_DOMAIN' => (string)(getenv('RCLONE_SMB_DOMAIN') ?: ''),
];

$entry = [
    'time' => date('c'),
    'pid' => getmypid(),
    'cwd' => getcwd(),
    'argv' => array_slice($argv, 1),
    'command' => $command,
    'stdin' => $stdin,
    'env' => $env,
    'exitCode' => $exitCode,
    'stdoutPreview' => mb_substr($stdout, 0, 500),
    'stderrPreview' => mb_substr($stderr, 0, 500),
];

$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "
", FILE_APPEND | LOCK_EX);

fwrite(STDOUT, $stdout);
fwrite(STDERR, $stderr);
exit($exitCode);

/**
 * @return array<string,mixed>
 */
function fakeRclonePopResponse(string $controlFile, string $command): array
{
    if (!is_file($controlFile)) {
        return [];
    }

    $fh = fopen($controlFile, 'c+');
    if ($fh === false) {
        return [];
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            return [];
        }
        $raw = stream_get_contents($fh);
        $cfg = json_decode((string)$raw, true);
        if (!is_array($cfg)) {
            return [];
        }

        $response = [];
        if (isset($cfg['queue']) && is_array($cfg['queue']) && $cfg['queue'] !== []) {
            $response = array_shift($cfg['queue']);
            if (!is_array($response)) {
                $response = [];
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fh);
        } elseif (isset($cfg['commands']) && is_array($cfg['commands']) && isset($cfg['commands'][$command]) && is_array($cfg['commands'][$command])) {
            $response = $cfg['commands'][$command];
        } elseif (isset($cfg[$command]) && is_array($cfg[$command])) {
            $response = $cfg[$command];
        } elseif (isset($cfg['default']) && is_array($cfg['default'])) {
            $response = $cfg['default'];
        }

        return $response;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

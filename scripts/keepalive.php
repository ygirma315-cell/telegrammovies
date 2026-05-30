<?php

function keepaliveLoadEnvFile(string $file): void {
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = ltrim(trim($key), "\xEF\xBB\xBF");
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        $value = trim($value);
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
    }
}

function keepaliveBool(string $key, bool $default): bool {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function keepaliveInt(string $key, int $default, int $min, int $max): int {
    $value = filter_var(getenv($key), FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function keepaliveUrl(): string {
    $base = trim((string) (getenv('PUBLIC_BASE_URL') ?: getenv('RENDER_EXTERNAL_URL') ?: ''));
    if ($base === '') {
        $port = trim((string) (getenv('PORT') ?: '10000'));
        $base = 'http://127.0.0.1:' . $port;
    }

    return rtrim($base, '/') . '/warmup';
}

function keepaliveLog(string $message): void {
    fwrite(STDOUT, '[' . gmdate('c') . '] ' . $message . PHP_EOL);
}

keepaliveLoadEnvFile(__DIR__ . '/../.env.render');
keepaliveLoadEnvFile(__DIR__ . '/../.env');

if (!keepaliveBool('KEEPALIVE_SELF_PING_ENABLED', true)) {
    keepaliveLog('Keepalive self-ping disabled.');
    exit(0);
}

$intervalSeconds = keepaliveInt('KEEPALIVE_PING_INTERVAL_MINUTES', 5, 1, 120) * 60;
$target = keepaliveUrl();
keepaliveLog('Keepalive self-ping started | target=' . $target . ' interval_seconds=' . $intervalSeconds);
sleep(20);

while (true) {
    $started = microtime(true);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 25,
            'header' => "User-Agent: telegrammovies-keepalive/1.0\r\n",
        ],
    ]);
    $response = @file_get_contents($target . '?source=self&t=' . time(), false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    $durationMs = (int) round((microtime(true) - $started) * 1000);
    $ok = $status >= 200 && $status < 400;
    keepaliveLog(
        ($ok ? 'KEEPALIVE SELF-PING SUCCESS' : 'KEEPALIVE SELF-PING FAILED')
        . ' | status=' . $status
        . ' duration_ms=' . $durationMs
        . ' body=' . substr(trim((string) $response), 0, 180)
    );

    sleep($intervalSeconds);
}

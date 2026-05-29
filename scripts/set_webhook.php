<?php

function loadEnvFile(string $file): void {
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

        putenv($key . '=' . trim($value));
    }
}

loadEnvFile(__DIR__ . '/../.env.render');
loadEnvFile(__DIR__ . '/../.env');

$token = (string) (getenv('TELEGRAM_BOT_TOKEN') ?: '');
$url = (string) ($argv[1] ?? getenv('WEBHOOK_URL') ?: '');
$renderUrl = rtrim((string) (getenv('RENDER_EXTERNAL_URL') ?: ''), '/');
$webhookPath = '/' . ltrim((string) (getenv('TELEGRAM_WEBHOOK_PATH') ?: '/telegram/webhook'), '/');
$secret = (string) (getenv('TELEGRAM_WEBHOOK_SECRET') ?: '');

if ($url === '' && $renderUrl !== '') {
    $url = $renderUrl . $webhookPath;
}

if ($token === '') {
    fwrite(STDERR, "Missing TELEGRAM_BOT_TOKEN.\n");
    exit(1);
}

if ($url === '') {
    fwrite(STDERR, "Usage: php scripts/set_webhook.php https://your-render-service.onrender.com/telegram/webhook\n");
    fwrite(STDERR, "Or set RENDER_EXTERNAL_URL/WEBHOOK_URL and TELEGRAM_WEBHOOK_PATH.\n");
    exit(1);
}

$params = [
    'url' => $url,
    'allowed_updates' => json_encode(['message', 'callback_query']),
];

if ($secret !== '') {
    $params['secret_token'] = $secret;
}

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($params),
        'ignore_errors' => true,
        'timeout' => 20,
    ],
]);

$response = file_get_contents('https://api.telegram.org/bot' . $token . '/setWebhook', false, $context);
$data = json_decode((string) $response, true);
if (!is_array($data)) {
    fwrite(STDERR, "Telegram did not return JSON.\n");
    exit(1);
}

echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
if (empty($data['ok'])) {
    exit(1);
}

$commands = [
    ['command' => 'start', 'description' => 'Start the bot'],
    ['command' => 'search', 'description' => 'Search for a movie'],
    ['command' => 'movie', 'description' => 'Search for a movie'],
];

$commandContext = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['commands' => json_encode($commands)]),
        'ignore_errors' => true,
        'timeout' => 20,
    ],
]);

$commandResponse = file_get_contents('https://api.telegram.org/bot' . $token . '/setMyCommands', false, $commandContext);
$commandData = json_decode((string) $commandResponse, true);
echo json_encode($commandData, JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($commandData['ok']) ? 0 : 1);

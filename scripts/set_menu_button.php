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

function telegramApi(string $token, string $method, array $params = []): array {
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $params[$key] = json_encode($value);
        }
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

    $response = file_get_contents('https://api.telegram.org/bot' . $token . '/' . $method, false, $context);
    $data = json_decode((string) $response, true);
    return is_array($data) ? $data : ['ok' => false, 'description' => 'Telegram did not return JSON'];
}

loadEnvFile(__DIR__ . '/../.env.render');
loadEnvFile(__DIR__ . '/../.env');

$token = (string) (getenv('TELEGRAM_BOT_TOKEN') ?: '');
$url = (string) ($argv[1] ?? getenv('WEB_APP_URL') ?: 'https://raw.githack.com/ygirma315-cell/telegrammovies/main/docs/index.html');

if ($token === '') {
    fwrite(STDERR, "Missing TELEGRAM_BOT_TOKEN.\n");
    exit(1);
}

if (!preg_match('/^https:\/\/[^\s]+$/', $url)) {
    fwrite(STDERR, "Mini app URL must be an HTTPS URL.\n");
    exit(1);
}

$steps = [
    'setChatMenuButton' => telegramApi($token, 'setChatMenuButton', [
        'menu_button' => [
            'type' => 'web_app',
            'text' => 'Movie Hub',
            'web_app' => ['url' => $url],
        ],
    ]),
    'setMyCommands' => telegramApi($token, 'setMyCommands', [
        'commands' => [
            ['command' => 'start', 'description' => 'Start the movie bot'],
            ['command' => 'search', 'description' => 'Search for a movie'],
            ['command' => 'movie', 'description' => 'Search for a movie'],
            ['command' => 'app', 'description' => 'Open the movie hub'],
        ],
    ]),
    'setMyShortDescription' => telegramApi($token, 'setMyShortDescription', [
        'short_description' => 'Search movies and get available files in Telegram.',
    ]),
    'setMyDescription' => telegramApi($token, 'setMyDescription', [
        'description' => "Send a movie name to search the catalog, or open Movie Hub from the bot menu.\nMain channel: @movieshddddd2",
    ]),
];

echo json_encode(['url' => $url, 'steps' => $steps], JSON_PRETTY_PRINT) . PHP_EOL;
foreach ($steps as $result) {
    if (empty($result['ok'])) {
        exit(1);
    }
}

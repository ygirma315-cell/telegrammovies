<?php

function botLoadDotEnv(string $file): void {
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
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function botEnv(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return trim((string) $value);
}

function botPath(string $path): string {
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
        return $path;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

botLoadDotEnv(__DIR__ . '/.env');

function botJson(array $data, int $status = 200): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
}

function botApi(string $method, array $params = [], int $timeout = 25): array {
    $token = botEnv('TELEGRAM_BOT_TOKEN');
    if ($token === '') {
        return ['ok' => false, 'description' => 'Missing TELEGRAM_BOT_TOKEN'];
    }

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
            'timeout' => $timeout,
        ],
    ]);

    $response = @file_get_contents('https://api.telegram.org/bot' . $token . '/' . $method, false, $context);
    $data = json_decode((string) $response, true);
    return is_array($data) ? $data : ['ok' => false, 'description' => 'Bot API request failed'];
}

function botMainChannelUsername(): string {
    $channel = botEnv('MAIN_CHANNEL_USERNAME', '@movieshddddd2');
    if ($channel === '') {
        return '';
    }

    return '@' . ltrim($channel, '@');
}

function botWebAppUrl(): string {
    return botEnv('WEB_APP_URL', 'https://raw.githack.com/ygirma315-cell/telegrammovies/main/docs/index.html');
}

function botWebAppKeyboard(): array {
    $url = botWebAppUrl();
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return [];
    }

    return [
        'inline_keyboard' => [[
            [
                'text' => 'Open Movie Hub',
                'web_app' => ['url' => $url],
            ],
        ]],
    ];
}

function botWithMainChannel(string $text): string {
    $channel = botMainChannelUsername();
    if ($channel === '') {
        return $text;
    }

    return rtrim($text) . "\n\nMain channel: " . $channel;
}

function botSendStartHelp(string|int $chatId): void {
    $params = [
        'chat_id' => $chatId,
        'text' => botWithMainChannel('Send any movie name to search, or use /search movie name.'),
    ];
    $keyboard = botWebAppKeyboard();
    if ($keyboard !== []) {
        $params['reply_markup'] = $keyboard;
    }

    botApi('sendMessage', $params);
}

function botDecodeBase64Url(string $value): string {
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($value, true);
    return is_string($decoded) ? trim($decoded) : '';
}

function botHandleWebAppData(string|int $chatId, string $rawData): void {
    $payload = json_decode($rawData, true);
    if (!is_array($payload)) {
        botSendStartHelp($chatId);
        return;
    }

    $type = (string) ($payload['type'] ?? '');
    $query = trim((string) ($payload['query'] ?? ''));
    if ($type === 'search' && $query !== '') {
        botSendSearchResults($chatId, $query);
        return;
    }

    botSendStartHelp($chatId);
}

function botReadCatalog(): array {
    $sessionCatalog = botPath(botEnv('APP_SESSION_DIR', 'sessions') . '/stored_movies.json');
    $seedCatalog = botPath(botEnv('CATALOG_SEED_FILE', 'data/stored_movies.json'));
    $file = is_file($sessionCatalog) ? $sessionCatalog : $seedCatalog;
    if (!is_file($file)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function botNormalizeSearchText(string $text): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
}

function botCatalogEntryId(string $key): string {
    return substr(hash('sha256', $key), 0, 16);
}

function botCatalogEntryById(string $id): ?array {
    foreach (botReadCatalog() as $key => $entry) {
        if (botCatalogEntryId((string) $key) === $id) {
            return ['key' => (string) $key, 'entry' => (array) $entry];
        }
    }

    return null;
}

function botEntryHasDeliverableItems(array $entry): bool {
    foreach (($entry['items'] ?? []) as $item) {
        if (!empty($item['bot_file_id']) || !empty($item['bot_message_id']) || !empty($item['source_url'])) {
            return true;
        }
    }

    return false;
}

function botSortedCatalogEntries(): array {
    $catalog = botReadCatalog();
    uasort($catalog, fn($left, $right) => strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? '')));
    return $catalog;
}

function botSearchCatalog(string $query, int $limit = 10): array {
    $needle = botNormalizeSearchText($query);
    if ($needle === '') {
        return [];
    }

    $matches = [];
    foreach (botSortedCatalogEntries() as $key => $entry) {
        $entry = (array) $entry;
        if (!botEntryHasDeliverableItems($entry)) {
            continue;
        }

        $title = (string) ($entry['title'] ?? '');
        $haystack = botNormalizeSearchText($title);
        if ($haystack === $needle || str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
            $matches[] = ['key' => (string) $key, 'entry' => $entry];
        }

        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function botSendSearchResults(string|int $chatId, string $query): void {
    $matches = botSearchCatalog($query);
    if (!$matches) {
        botApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => botWithMainChannel("No movies found for: $query"),
        ]);
        return;
    }

    $keyboard = [];
    foreach ($matches as $index => $row) {
        $keyboard[] = [[
            'text' => ($index + 1) . '. ' . (string) ($row['entry']['title'] ?? 'Untitled'),
            'callback_data' => 'movie:' . botCatalogEntryId($row['key']),
        ]];
    }

    botApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => botWithMainChannel('Found ' . count($matches) . " result(s) for: $query"),
        'reply_markup' => ['inline_keyboard' => $keyboard],
    ]);
}

function botDeliverMovieToChat(string|int $chatId, string $movieId): void {
    $row = botCatalogEntryById($movieId);
    if (!$row) {
        botApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => botWithMainChannel('Movie is not available anymore.'),
        ]);
        return;
    }

    $title = (string) ($row['entry']['title'] ?? 'Movie');
    $items = array_values((array) ($row['entry']['items'] ?? []));
    $maxFiles = max(1, (int) botEnv('BOT_MAX_FILES_PER_REQUEST', '3'));
    $sent = 0;

    foreach (array_slice($items, 0, $maxFiles) as $item) {
        $item = (array) $item;
        $quality = (string) ($item['quality'] ?? 'file');
        if (!empty($item['bot_file_id'])) {
            $result = botApi('sendDocument', [
                'chat_id' => $chatId,
                'document' => $item['bot_file_id'],
                'caption' => botWithMainChannel("$title ($quality)"),
            ], 60);
            if (!empty($result['ok'])) {
                $sent++;
                continue;
            }
        }

        if (!empty($item['bot_message_id']) && !empty($item['source_chat_id'])) {
            $result = botApi('copyMessage', [
                'chat_id' => $chatId,
                'from_chat_id' => $item['source_chat_id'],
                'message_id' => $item['bot_message_id'],
                'caption' => botWithMainChannel("$title ($quality)"),
            ], 60);
            if (!empty($result['ok'])) {
                $sent++;
                continue;
            }
        }

        if (!empty($item['source_url'])) {
            botApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => botWithMainChannel("$title ($quality): " . $item['source_url']),
            ]);
            $sent++;
        }
    }

    if ($sent === 0) {
        botApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => botWithMainChannel("$title is listed, but no copyable file reference was saved. Rebuild the catalog locally so the bot has a file reference."),
        ]);
    }
}

function botHandleWebhook(array $update): array {
    if (isset($update['callback_query'])) {
        $callback = (array) $update['callback_query'];
        $data = (string) ($callback['data'] ?? '');
        $chatId = $callback['message']['chat']['id'] ?? null;
        if (isset($callback['id'])) {
            botApi('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }

        if ($chatId !== null && str_starts_with($data, 'movie:')) {
            botDeliverMovieToChat($chatId, substr($data, 6));
        }

        return ['ok' => true];
    }

    $message = (array) ($update['message'] ?? []);
    $chatId = $message['chat']['id'] ?? null;
    if ($chatId === null) {
        return ['ok' => true];
    }

    $webAppData = trim((string) ($message['web_app_data']['data'] ?? ''));
    if ($webAppData !== '') {
        botHandleWebAppData($chatId, $webAppData);
        return ['ok' => true];
    }

    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        return ['ok' => true];
    }

    if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/i', $text, $matches)) {
        $payload = trim((string) ($matches[1] ?? ''));
        if (str_starts_with($payload, 'movie_')) {
            botDeliverMovieToChat($chatId, substr($payload, 6));
        } elseif (str_starts_with($payload, 'q_')) {
            $query = botDecodeBase64Url(substr($payload, 2));
            if ($query === '') {
                botSendStartHelp($chatId);
            } else {
                botSendSearchResults($chatId, $query);
            }
        } else {
            botSendStartHelp($chatId);
        }

        return ['ok' => true];
    }

    if (preg_match('/^\/app(?:@\w+)?$/i', $text)) {
        botSendStartHelp($chatId);
        return ['ok' => true];
    }

    if (preg_match('/^\/(?:search|movie|movies)(?:@\w+)?\s*(.*)$/i', $text, $matches)) {
        $query = trim((string) ($matches[1] ?? ''));
        if ($query === '') {
            botApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => botWithMainChannel('Send any movie name to search. You can also use /search movie name.'),
            ]);
        } else {
            botSendSearchResults($chatId, $query);
        }

        return ['ok' => true];
    }

    if (!str_starts_with($text, '/')) {
        botSendSearchResults($chatId, $text);
    }

    return ['ok' => true];
}

if (PHP_SAPI !== 'cli') {
    ob_start();
    $update = json_decode((string) file_get_contents('php://input'), true);
    botJson(botHandleWebhook(is_array($update) ? $update : []));
}

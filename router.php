<?php

function routerLoadDotEnv(string $file): void {
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

function routerBool(string $key, bool $default = false): bool {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function routerCorsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Telegram-Bot-Api-Secret-Token');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

function routerJson(array $data, int $status = 200): void {
    http_response_code($status);
    routerCorsHeaders();
    header('Content-Type: application/json');
    echo json_encode($data);
}

function routerServeMiniApp(string $path): bool {
    if ($path !== '/app' && $path !== '/app/' && !str_starts_with($path, '/app/')) {
        return false;
    }

    $base = realpath(__DIR__ . '/docs');
    if ($base === false) {
        routerJson(['ok' => false, 'error' => 'Mini app not found'], 404);
        return true;
    }

    $relative = $path === '/app' || $path === '/app/' ? 'index.html' : ltrim(substr($path, 5), '/');
    if ($relative === '' || str_ends_with($relative, '/')) {
        $relative .= 'index.html';
    }

    $target = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($target === false || !str_starts_with($target, $base . DIRECTORY_SEPARATOR) || !is_file($target)) {
        routerJson(['ok' => false, 'error' => 'Not found'], 404);
        return true;
    }

    $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    http_response_code(200);
    routerCorsHeaders();
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    if ($extension !== 'html') {
        header('Cache-Control: public, max-age=300');
    }
    readfile($target);
    return true;
}

function routerAuthorized(string $password): bool {
    if ($password === '') {
        return true;
    }

    $provided = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    return hash_equals($password, $provided);
}

function routerRequireAuth(): void {
    header('WWW-Authenticate: Basic realm="Telegram Movies Admin"');
    routerJson(['ok' => false, 'error' => 'Authentication required'], 401);
}

routerLoadDotEnv(__DIR__ . '/.env');

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$publicBotOnly = routerBool('PUBLIC_BOT_ONLY', true);
$adminPassword = (string) (getenv('ADMIN_PASSWORD') ?: '');

if ($method === 'OPTIONS') {
    routerJson(['ok' => true]);
    return true;
}

if (routerServeMiniApp($path)) {
    return true;
}

if ($path === '/health' || ($publicBotOnly && $path === '/')) {
    routerJson(['ok' => true, 'service' => 'telegrammovies-bot']);
    return true;
}

if (($path === '/telegram/webhook' || $path === '/webhook' || $path === '/bot-webhook') && $method === 'POST') {
    $webhookSecret = (string) (getenv('TELEGRAM_WEBHOOK_SECRET') ?: '');
    $providedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($webhookSecret !== '' && !hash_equals($webhookSecret, $providedSecret)) {
        routerJson(['ok' => false], 403);
        return true;
    }

    require __DIR__ . '/bot.php';
    return true;
}

if ($path === '/server.php') {
    $action = (string) ($_REQUEST['action'] ?? '');
    if ($publicBotOnly && !in_array($action, ['bot_webhook'], true)) {
        routerJson(['ok' => false, 'error' => 'Not found'], 404);
        return true;
    }

    if ($publicBotOnly && $action === 'bot_webhook') {
        require __DIR__ . '/bot.php';
        return true;
    }

    if (!$publicBotOnly && !routerAuthorized($adminPassword)) {
        routerRequireAuth();
        return true;
    }

    require __DIR__ . '/server.php';
    return true;
}

if ($publicBotOnly) {
    routerJson(['ok' => false, 'error' => 'Not found'], 404);
    return true;
}

if (!routerAuthorized($adminPassword)) {
    routerRequireAuth();
    return true;
}

if (str_contains($path, '..') || str_starts_with(basename($path), '.')) {
    routerJson(['ok' => false, 'error' => 'Not found'], 404);
    return true;
}

return false;

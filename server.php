<?php
// server.php - Telegram Movie Scraper Backend (Fixed)
// Uses MadelineProto 8+ high-level methods

// ---------- CONFIG ----------
function loadDotEnv(string $file): void {
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

loadDotEnv(__DIR__ . '/.env');

function envString(string $key, ?string $default = null): string {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Missing required environment variable: $key");
    }
    return trim((string) $value);
}

function envInt(string $key, ?int $default = null): int {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Missing required environment variable: $key");
    }
    return (int) $value;
}

function appPath(string $path): string {
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
        return $path;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

$API_ID = envInt('TELEGRAM_API_ID');
$API_HASH = envString('TELEGRAM_API_HASH');
$BOT_TOKEN = envString('TELEGRAM_BOT_TOKEN', '');
$BOT_USERNAME = envString('TELEGRAM_BOT_USERNAME', 'moviesstorehdbot');
$INDEX_CHANNEL_ID = envInt('TELEGRAM_INDEX_CHANNEL_ID', -1003522601621);
$SESSION_DIR = appPath(envString('APP_SESSION_DIR', 'sessions'));
$CATALOG_SEED_FILE = appPath(envString('CATALOG_SEED_FILE', 'data/stored_movies.json'));
$SESSION_FILE = $SESSION_DIR . '/telegram_session.madeline';
$STOP_FILE = $SESSION_DIR . '/stop_scrape.flag';
$CATALOG_FILE = $SESSION_DIR . '/stored_movies.json';
$BOT_OFFSET_FILE = $SESSION_DIR . '/bot_update_offset.txt';
$INDEX_STATE_FILE = $SESSION_DIR . '/index_post_state.json';

const SCRAPE_HISTORY_LIMIT = 28;
const SCRAPE_POLL_USEC = 220000;
const SCRAPE_AFTER_CLICK_USEC = 250000;
const SCRAPE_AFTER_DOWNLOAD_USEC = 350000;
const SCRAPE_NO_RESULT_TIMEOUT = 12.0;
const SCRAPE_AFTER_CLICK_TIMEOUT = 42.0;
const SCRAPE_AFTER_DOWNLOAD_TIMEOUT = 48.0;
const SCRAPE_IDLE_RESULT_SECONDS = 5.0;
const SCRAPE_IDLE_CLICK_SECONDS = 7.0;
const SCRAPE_IDLE_DOWNLOAD_SECONDS = 12.0;
const CHANNEL_IMPORT_HISTORY_LIMIT = 80;
const CHANNEL_IMPORT_MAX_FILES_PER_SOURCE = 20;
const CHANNEL_IMPORT_MAX_FILES_PER_SELECTED = 80;
const CHANNEL_IMPORT_BOT_TIMEOUT = 45.0;
const CHANNEL_IMPORT_SELECTED_BOT_TIMEOUT = 120.0;
const CHANNEL_IMPORT_BOT_IDLE_SECONDS = 5.0;
const CHANNEL_IMPORT_CLICK_SETTLE_SECONDS = 2.0;
const CHANNEL_IMPORT_CLICK_TIMEOUT_SECONDS = 10.0;
const CHANNEL_IMPORT_MAX_BOT_CLICKS = 18;
const CHANNEL_IMPORT_SELECTED_MAX_BOT_CLICKS = 80;
const CHANNEL_IMPORT_PREFERRED_BOT = 'Filestore23bot';

// ---------- SESSION ----------
ob_start();
session_start();
putenv('PHPRC=' . __DIR__);
$_SERVER['PHPRC'] = __DIR__;

require_once 'vendor/autoload.php';
use danog\MadelineProto\API;
use danog\MadelineProto\Logger as MadelineLogger;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Settings;

/**
 * MadelineProto session management.
 * We store only a session file path because MadelineProto objects
 * cannot be safely serialised in $_SESSION.
 */
function getMadeline(): API {
    global $API_ID, $API_HASH, $SESSION_DIR, $SESSION_FILE;

    if (!is_dir($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0700, true);
    }

    $settings = new Settings;
    $settings->getAppInfo()
        ->setApiId((int) $API_ID)
        ->setApiHash($API_HASH);
    $settings->getLogger()
        ->setType(MadelineLogger::FILE_LOGGER)
        ->setExtra($SESSION_DIR . '/MadelineProto.log')
        ->setLevel(MadelineLogger::LEVEL_WARNING);

    return new API($SESSION_FILE, $settings);
}

function postValue(string $key): string {
    if (!isset($_POST[$key])) {
        throw new InvalidArgumentException("Missing field: $key");
    }
    return trim((string) $_POST[$key]);
}

function sendJson(array $data): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($data);
}

// ---------- ROUTER ----------
$action = $_REQUEST['action'] ?? '';
if (ob_get_level() > 0) {
    ob_clean();
}

if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    header('Content-Type: application/json');
    $update = json_decode((string) file_get_contents('php://input'), true);
    sendJson(handleBotWebhook(is_array($update) ? $update : []));
    exit;
}

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'login_phone':
            sendJson(loginPhone(postValue('phone')));
            break;
        case 'login_code':
            sendJson(loginCode(postValue('code')));
            break;
        case 'login_2fa':
            sendJson(login2FA(postValue('password')));
            break;
        case 'check_session':
            sendJson(checkSession());
            break;
        case 'logout':
            sendJson(logoutTelegram());
            break;
        case 'stop_scrape':
            sendJson(stopScrape());
            break;
        case 'catalog_status':
            sendJson(catalogStatus());
            break;
        case 'status_overview':
            sendJson(statusOverview());
            break;
        case 'member_growth_status':
            sendJson(memberGrowthStatus((string) ($_POST['target'] ?? '')));
            break;
        case 'create_member_invite':
            sendJson(createMemberInviteLink(
                (string) ($_POST['target'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (int) ($_POST['member_limit'] ?? 0),
                (int) ($_POST['expire_hours'] ?? 0),
                ($_POST['join_request'] ?? '') === '1'
            ));
            break;
        case 'discover_sources':
            sendJson(discoverTelegramSources((int) ($_POST['limit'] ?? 80)));
            break;
        case 'global_sources':
            sendJson(discoverGlobalSourcesForTitles(
                preg_split('/\R/', postValue('titles')) ?: [],
                (int) ($_POST['limit'] ?? 12)
            ));
            break;
        case 'import_channel':
            importChannelSources(
                postValue('username'),
                (int) ($_POST['limit'] ?? CHANNEL_IMPORT_HISTORY_LIMIT),
                normalizeTelegramSourceTarget((string) ($_POST['preferred_bot'] ?? CHANNEL_IMPORT_PREFERRED_BOT)),
                ($_POST['direct_only'] ?? '') === '1'
            );
            break;
        case 'preview_channel_lists':
            sendJson(previewChannelLists(
                postValue('username'),
                (int) ($_POST['offset_id'] ?? 0),
                (int) ($_POST['limit'] ?? 30),
                normalizeTelegramSourceTarget((string) ($_POST['preferred_bot'] ?? CHANNEL_IMPORT_PREFERRED_BOT)),
                json_decode((string) ($_POST['skip_keys'] ?? '[]'), true) ?: []
            ));
            break;
        case 'import_selected_lists':
            importSelectedChannelLists(
                json_decode(postValue('items'), true) ?: [],
                normalizeTelegramSourceTarget((string) ($_POST['preferred_bot'] ?? CHANNEL_IMPORT_PREFERRED_BOT))
            );
            break;
        case 'rebuild_catalog':
            sendJson(rebuildCatalogFromStorageBot());
            break;
        case 'repair_catalog':
            sendJson(repairCatalogBotReferences());
            break;
        case 'clear_index':
            sendJson(clearIndexChannel(2000, ($_POST['dry_run'] ?? '') === '1'));
            break;
        case 'post_index':
            sendJson(postIndexBatches());
            break;
        case 'index_preview':
            sendJson(indexPreview((int) ($_POST['batch_size'] ?? 10)));
            break;
        case 'post_index_stream':
            postIndexBatchesStream(
                (int) ($_POST['batch_size'] ?? 10),
                (string) ($_POST['main_channel'] ?? ''),
                (string) ($_POST['mode'] ?? 'resume'),
                json_decode((string) ($_POST['selected_batches'] ?? '[]'), true) ?: [],
                (int) ($_POST['cooldown_every'] ?? 8),
                (int) ($_POST['cooldown_seconds'] ?? 45)
            );
            break;
        case 'poll_bot':
            sendJson(pollBotUpdates());
            break;
        case 'bot_webhook':
            $update = json_decode((string) file_get_contents('php://input'), true);
            sendJson(handleBotWebhook(is_array($update) ? $update : []));
            break;
        case 'scrape':
            scrapeMoviesV4(
                postValue('username'),
                preg_split('/\R/', postValue('titles')) ?: []
            );
            break;
        default:
            sendJson(['error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    sendJson(['error' => $e->getMessage()]);
}

// ---------- AUTHENTICATION FUNCTIONS ----------
function loginPhone(string $phone): array {
    $madeline = getMadeline();
    if ($madeline->getAuthorization() === API::LOGGED_IN) {
        return ['success' => true, 'authorized' => true];
    }

    $result = $madeline->phoneLogin($phone);
    $_SESSION['phone_code_hash'] = $result['phone_code_hash'];
    return ['success' => true, 'code_sent' => true, 'phone_code_hash' => $result['phone_code_hash']];
}

function loginCode(string $code): array {
    $madeline = getMadeline();
    $result = $madeline->completePhoneLogin($code);

    if ($madeline->getAuthorization() === API::WAITING_PASSWORD) {
        return [
            'success' => true,
            'authorized' => false,
            'password_needed' => true,
            'hint' => $result['hint'] ?? '',
        ];
    }

    return ['success' => true, 'authorized' => true];
}

function login2FA(string $password): array {
    $madeline = getMadeline();
    try {
        $madeline->complete2faLogin($password);
        return ['success' => true, 'authorized' => true];
    } catch (RPCErrorException $e) {
        if ($e->rpc === 'PASSWORD_HASH_INVALID') {
            return ['error' => 'Invalid 2FA password'];
        }
        throw $e;
    }
}

function checkSession(): array {
    $madeline = getMadeline();
    return ['authorized' => $madeline->getAuthorization() === API::LOGGED_IN];
}

function logoutTelegram(): array {
    $madeline = getMadeline();
    if ($madeline->getAuthorization() === API::LOGGED_IN) {
        $madeline->logout();
    }
    session_destroy();
    return ['success' => true];
}

function stopScrape(): array {
    global $SESSION_DIR, $STOP_FILE;

    if (!is_dir($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0700, true);
    }
    file_put_contents($STOP_FILE, (string) time());

    return ['success' => true, 'stopping' => true];
}

function clearStopScrape(): void {
    global $STOP_FILE;

    if (is_file($STOP_FILE)) {
        unlink($STOP_FILE);
    }
}

function shouldStopScrape(): bool {
    global $STOP_FILE;

    return connection_aborted() || is_file($STOP_FILE);
}

function streamJsonLine(array $data): void {
    echo json_encode($data) . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function messageHasDocument(array $message): bool {
    return ($message['media']['_'] ?? '') === 'messageMediaDocument' || isset($message['media']['document']);
}

function messageText(array $message): string {
    return trim((string) ($message['message'] ?? ''));
}

function messageTotalResults(string $text): int {
    if (preg_match('/Total\s+Results\s*:\s*(\d+)/i', $text, $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

function messageSaysNoResults(string $text): bool {
    return preg_match('/(no\s+(movie|movies|result|results|file|files)\s+found|not\s+found|total\s+results\s*:\s*0)/i', $text) === 1;
}

function normalizeSearchText(string $text): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '';
}

function catalogKey(string $title): string {
    return normalizeSearchText($title);
}

function readCatalog(): array {
    global $CATALOG_FILE, $CATALOG_SEED_FILE;

    if (!is_file($CATALOG_FILE)) {
        if (!is_file($CATALOG_SEED_FILE)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($CATALOG_SEED_FILE), true);
        return is_array($data) ? $data : [];
    }

    $data = json_decode((string) file_get_contents($CATALOG_FILE), true);
    return is_array($data) ? $data : [];
}

function writeCatalog(array $catalog): void {
    global $SESSION_DIR, $CATALOG_FILE;

    if (!is_dir($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0700, true);
    }

    file_put_contents($CATALOG_FILE, json_encode($catalog, JSON_PRETTY_PRINT));
}

function extractQuality(string $text): string {
    if (preg_match('/\b(2160p|1080p|720p|480p|360p|240p)\b/i', $text, $matches)) {
        return strtolower($matches[1]);
    }
    if (preg_match('/\b(4k|uhd)\b/i', $text)) {
        return '2160p';
    }
    if (preg_match('/\b(hevc|x265)\b/i', $text)) {
        return 'hevc';
    }
    if (preg_match('/\b(x264|h\.?264)\b/i', $text)) {
        return 'x264';
    }

    return 'unknown';
}

function messageFileName(array $message): string {
    foreach (($message['media']['document']['attributes'] ?? []) as $attribute) {
        if (($attribute['_'] ?? '') === 'documentAttributeFilename' && isset($attribute['file_name'])) {
            return (string) $attribute['file_name'];
        }
    }

    return '';
}

function messageMatchesTitleOrFile(array $message, string $title): bool {
    $normalizedTitle = normalizeSearchText($title);
    if ($normalizedTitle === '') {
        return false;
    }

    $haystack = normalizeSearchText(messageFileName($message) . ' ' . messageText($message));
    return $haystack !== '' && (str_contains($haystack, $normalizedTitle) || str_contains($normalizedTitle, $haystack));
}

function catalogQualities(string $title): array {
    $catalog = readCatalog();
    $entry = $catalog[catalogKey($title)] ?? ['qualities' => []];
    return is_array($entry['qualities'] ?? null) ? $entry['qualities'] : [];
}

function catalogQualityCount(string $title): int {
    return count(catalogQualities($title));
}

function catalogItemCount(string $title): int {
    $catalog = readCatalog();
    $entry = $catalog[catalogKey($title)] ?? ['items' => []];
    return count((array) ($entry['items'] ?? []));
}

function catalogHasBotDeliverable(string $title): bool {
    $catalog = readCatalog();
    $entry = $catalog[catalogKey($title)] ?? ['items' => []];

    foreach ((array) ($entry['items'] ?? []) as $item) {
        if (!empty($item['bot_file_id']) || !empty($item['bot_message_id'])) {
            return true;
        }
    }

    return false;
}

function catalogHasQuality(string $title, string $quality): bool {
    return in_array($quality, catalogQualities($title), true);
}

function canStoreQuality(string $title, string $quality, array $sessionQualities = []): bool {
    if (catalogHasBotDeliverable($title) || count($sessionQualities) >= 1) {
        return false;
    }

    $storedQualities = catalogHasBotDeliverable($title) ? catalogQualities($title) : [];
    $knownQualities = array_unique(array_merge($storedQualities, $sessionQualities));
    if (count($knownQualities) >= 1) {
        return false;
    }

    return !in_array($quality, $knownQualities, true);
}

function markCatalogStored(string $title, string $quality, string $method, array $meta = []): void {
    $catalog = readCatalog();
    $key = catalogKey($title);

    $catalog[$key] ??= [
        'title' => $title,
        'qualities' => [],
        'items' => [],
    ];
    $catalog[$key]['title'] = $title;

    $hasBotMeta = !empty($meta['bot_file_id']) || !empty($meta['bot_message_id']);
    if (!$hasBotMeta && count((array) ($catalog[$key]['items'] ?? [])) >= 1) {
        return;
    }
    if ($hasBotMeta && !entryHasBotDeliverable((array) $catalog[$key])) {
        $catalog[$key]['qualities'] = [];
        $catalog[$key]['items'] = [];
    } elseif (count((array) ($catalog[$key]['items'] ?? [])) >= 1 || in_array($quality, $catalog[$key]['qualities'], true)) {
        return;
    }

    if (!in_array($quality, $catalog[$key]['qualities'], true)) {
        $catalog[$key]['qualities'][] = $quality;
    }

    $item = [
        'quality' => $quality,
        'method' => $method,
        'stored_at' => date('c'),
    ];
    foreach (['message_id', 'source_chat_id', 'source_url', 'file_name', 'bot_message_id', 'bot_file_id', 'bot_file_unique_id', 'bot_file_name'] as $field) {
        if (isset($meta[$field]) && $meta[$field] !== '') {
            $item[$field] = $meta[$field];
        }
    }

    $catalog[$key]['items'][] = $item;

    writeCatalog($catalog);
}

function isUsefulFileButton(array $button, string $title): bool {
    $label = trim((string) ($button['text'] ?? ''));
    $type = (string) ($button['_'] ?? '');

    if ($label === '' || preg_match('/\b(tips?|info|join|ads?|help|start|download)\b/i', $label)) {
        return false;
    }
    if (preg_match('/^\s*(?:files?|file)\s*[:\-]?\s*\d+\s*$/i', $label)) {
        return false;
    }
    if ($type === 'keyboardButtonUrl' && !empty($button['url']) && parseTelegramMessageUrl((string) $button['url'])) {
        return true;
    }

    $normalizedLabel = normalizeSearchText($label);
    $normalizedTitle = normalizeSearchText($title);

    return (
        ($normalizedTitle !== '' && str_contains($normalizedLabel, $normalizedTitle))
        || preg_match('/\b\d+(?:\.\d+)?\s*(?:mb|gb|kb)\b/i', $label)
        || preg_match('/\b(480p|720p|1080p|2160p|x264|x265|hevc|bluray|web-?dl|hdrip)\b/i', $label)
        || preg_match('/\.(mkv|mp4|avi|mov)\b/i', $label)
    );
}

function getCandidateButtons(array $message, string $title): array {
    $candidates = [];

    foreach (($message['reply_markup']['rows'] ?? []) as $row) {
        foreach (($row['buttons'] ?? []) as $button) {
            if (isUsefulFileButton($button, $title)) {
                $candidates[] = $button;
            }
        }
    }

    return $candidates;
}

function isDownloadButton(array $button): bool {
    $label = trim((string) ($button['text'] ?? ''));
    $type = (string) ($button['_'] ?? '');

    if ($label === '') {
        return false;
    }
    if (preg_match('/\b(download|get\s*file|open\s*file|file)\b/i', $label) || str_contains($label, '⬇')) {
        return true;
    }

    return $type === 'keyboardButtonUrl' && !empty($button['url']) && parseTelegramMessageUrl((string) $button['url']);
}

function messageLooksLikeFileDetail(array $message, string $title): bool {
    $text = messageText($message);
    $normalizedText = normalizeSearchText($text);
    $normalizedTitle = normalizeSearchText($title);

    return (
        preg_match('/file\s*name|file\s*size|please\s+forward|download/i', $text) === 1
        || preg_match('/\.(mkv|mp4|avi|mov)\b/i', $text) === 1
        || ($normalizedTitle !== '' && str_contains($normalizedText, $normalizedTitle))
    );
}

function messageButtonText(array $message): string {
    $labels = [];

    foreach (($message['reply_markup']['rows'] ?? []) as $row) {
        foreach (($row['buttons'] ?? []) as $button) {
            $label = trim((string) ($button['text'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }
    }

    return implode(' ', $labels);
}

function messageLooksLikeNavigationPrompt(array $message, string $title): bool {
    if (messageHasDocument($message) || getCandidateButtons($message, $title) || getDownloadButtons($message)) {
        return false;
    }

    $text = trim(messageText($message) . ' ' . messageButtonText($message));
    if ($text === '' || messageTotalResults($text) > 0 || messageSaysNoResults($text)) {
        return false;
    }

    $normalizedText = normalizeSearchText($text);
    $normalizedTitle = normalizeSearchText($title);
    if ($normalizedTitle !== '' && str_contains($normalizedText, $normalizedTitle) && preg_match('/\b(result|found|file|download)\b/i', $text)) {
        return false;
    }

    return preg_match('/\b(home|main\s*menu|menu|welcome|start|search\s*(movie|film)?|send\s*(movie|film)?|enter\s*(movie|film)?|movie\s*name|film\s*name|choose|select|language|try\s*again)\b/i', $text) === 1;
}

function getDownloadButtons(array $message): array {
    $buttons = [];

    foreach (($message['reply_markup']['rows'] ?? []) as $row) {
        foreach (($row['buttons'] ?? []) as $button) {
            if (isDownloadButton($button)) {
                $buttons[] = $button;
            }
        }
    }

    return $buttons;
}

function queueCandidateButtons(array $message, string $title, array $sessionQualities, array $clickedKeys): array {
    $queued = [];

    foreach (getCandidateButtons($message, $title) as $button) {
        $label = trim((string) ($button['text'] ?? ''));
        $quality = extractQuality($label);
        $key = normalizeSearchText(($button['_'] ?? '') . ' ' . $label . ' ' . (string) ($button['url'] ?? '') . ' ' . base64_encode((string) ($button['data'] ?? '')));

        if ($key === '' || isset($clickedKeys[$key]) || !canStoreQuality($title, $quality, $sessionQualities)) {
            continue;
        }

        $queued[] = [
            'button' => $button,
            'key' => $key,
            'label' => $label,
            'quality' => $quality,
        ];
    }

    return $queued;
}

function parseTelegramMessageUrl(string $url): ?array {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');

    if (!in_array($host, ['t.me', 'telegram.me'], true)) {
        return null;
    }

    $segments = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/'))));
    if (count($segments) < 2 || !ctype_digit(end($segments))) {
        return null;
    }

    $messageId = (int) array_pop($segments);
    if (($segments[0] ?? '') === 'c' && isset($segments[1]) && ctype_digit($segments[1])) {
        return ['peer' => '-100' . $segments[1], 'id' => $messageId];
    }

    return ['peer' => $segments[0], 'id' => $messageId];
}

function parseTelegramStartUrl(string $url): ?array {
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host'] ?? '');
    parse_str((string) ($parts['query'] ?? ''), $query);

    if (in_array($host, ['t.me', 'telegram.me'], true)) {
        $domain = trim((string) ($parts['path'] ?? ''), '/');
        $payload = (string) ($query['start'] ?? $query['startapp'] ?? '');
        if ($domain !== '' && $payload !== '') {
            return ['domain' => $domain, 'payload' => $payload];
        }
    }

    if ($scheme === 'tg' && $host === 'resolve') {
        $domain = (string) ($query['domain'] ?? '');
        $payload = (string) ($query['start'] ?? $query['startapp'] ?? '');
        if ($domain !== '' && $payload !== '') {
            return ['domain' => $domain, 'payload' => $payload];
        }
    }

    return null;
}

function openTelegramStartUrl(API $madeline, array $peer, string $url): bool {
    $start = parseTelegramStartUrl($url);
    if (!$start) {
        return false;
    }

    try {
        $madeline->messages->sendMessage([
            'peer' => $start['domain'] ?: $peer,
            'message' => '/start ' . $start['payload'],
        ]);
    } catch (Throwable $targetError) {
        $madeline->messages->sendMessage([
            'peer' => $peer,
            'message' => '/start ' . $start['payload'],
        ]);
    }

    return true;
}

function tryForwardTelegramUrl(API $madeline, string $url, string $toPeer): bool {
    $messageRef = parseTelegramMessageUrl($url);
    if (!$messageRef) {
        return false;
    }

    $madeline->messages->forwardMessages([
        'silent' => true,
        'drop_author' => true,
        'drop_media_captions' => true,
        'from_peer' => $messageRef['peer'],
        'id' => [$messageRef['id']],
        'to_peer' => $toPeer,
    ]);

    return true;
}

function storeTelegramMessageUrl(API $madeline, string $url, string $storageBot, string $title, string $quality): array {
    $messageRef = parseTelegramMessageUrl($url);
    if (!$messageRef) {
        return ['stored' => false, 'reason' => 'not_telegram_message'];
    }
    if (catalogHasBotDeliverable($title)) {
        return ['stored' => false, 'already' => true, 'reason' => 'movie_already_stored', 'quality' => $quality];
    }

    $storagePeer = $madeline->getInfo($storageBot, API::INFO_TYPE_PEER);
    $result = $madeline->messages->forwardMessages([
        'silent' => true,
        'drop_author' => true,
        'drop_media_captions' => true,
        'from_peer' => $messageRef['peer'],
        'id' => [$messageRef['id']],
        'to_peer' => $storageBot,
    ]);

    $messageId = sentMessageIdFromUpdates($result);
    if ($messageId === null) {
        $messageId = latestMessageId($madeline, $storagePeer) ?: null;
    }

    if ($messageId === null) {
        return ['stored' => false, 'reason' => 'stored_message_id_missing'];
    }

    $sourceChatId = ownerBotChatId($madeline);
    $botMeta = captureBotStoredReference($title, $quality, '', $sourceChatId);

    markCatalogStored($title, $quality, 'hidden_forward_link', array_merge([
        'message_id' => $messageId,
        'source_chat_id' => $sourceChatId,
        'source_url' => $url,
    ], $botMeta));

    return ['stored' => true, 'method' => 'hidden_forward_link', 'quality' => $quality];
}

function ownerBotChatId(API $madeline): string {
    $self = $madeline->getSelf();
    return (string) ($self['id'] ?? $self['User']['id'] ?? $self['user_id'] ?? '');
}

function sentMessageIdFromUpdates(mixed $value): ?int {
    if (!is_array($value)) {
        return null;
    }

    if (($value['_'] ?? '') === 'updateShortSentMessage' && isset($value['id'])) {
        return (int) $value['id'];
    }

    if (isset($value['message']) && is_array($value['message']) && isset($value['message']['id'])) {
        return (int) $value['message']['id'];
    }

    foreach ($value as $child) {
        $messageId = sentMessageIdFromUpdates($child);
        if ($messageId !== null) {
            return $messageId;
        }
    }

    return null;
}

function normalizeTelegramSourceTarget(string $target): string {
    $target = trim($target);
    if ($target === '') {
        return '';
    }

    $target = preg_replace('/^https?:\/\/(?:www\.)?(?:t|telegram)\.me\//i', '@', $target) ?? $target;
    $target = preg_replace('/^(?:t|telegram)\.me\//i', '@', $target) ?? $target;
    $target = strtok($target, "?#") ?: $target;
    $target = preg_replace('/\/.*$/', '', $target) ?? $target;
    $target = preg_replace('/\s+/', '', $target) ?? $target;

    if ($target !== '' && $target[0] !== '@' && !preg_match('/^-?\d+$/', $target)) {
        $target = '@' . $target;
    }

    return $target;
}

function sourceTargetsFromInput(string $value): array {
    $targets = [];
    $seen = [];

    foreach (preg_split('/[\r\n,;]+/', $value) ?: [] as $raw) {
        $target = normalizeTelegramSourceTarget((string) $raw);
        $key = strtolower($target);
        if ($target === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $targets[] = $target;
    }

    return $targets;
}

function clickOneButton(API $madeline, array $peer, array $message, array $button, string $storageBot): array {
    $label = trim((string) ($button['text'] ?? ''));
    $type = (string) ($button['_'] ?? '');

    if ($type === 'keyboardButtonCallback' && isset($button['data'])) {
        $answer = $madeline->messages->getBotCallbackAnswer([
            'peer' => $peer,
            'msg_id' => $message['id'],
            'data' => $button['data'],
        ]);

        if (!empty($answer['url'])) {
            if (openTelegramStartUrl($madeline, $peer, (string) $answer['url'])) {
                return ['clicked' => true, 'opened_start_url' => true, 'label' => $label, 'url' => (string) $answer['url']];
            }
            return ['stored_url' => true, 'label' => $label, 'url' => (string) $answer['url']];
        }

        return ['clicked' => true, 'label' => $label];
    }

    if ($type === 'keyboardButtonUrl' && isset($button['url'])) {
        if (openTelegramStartUrl($madeline, $peer, (string) $button['url'])) {
            return ['clicked' => true, 'opened_start_url' => true, 'label' => $label, 'url' => (string) $button['url']];
        }
        return ['stored_url' => true, 'label' => $label, 'url' => (string) $button['url']];
    }

    if ($type === 'keyboardButton' && $label !== '') {
        $madeline->messages->sendMessage(['peer' => $peer, 'message' => $label]);
        return ['clicked' => true, 'label' => $label];
    }

    return ['clicked' => false, 'label' => $label];
}

function storeMovieMessage(API $madeline, array $fromPeer, array $message, string $storageBot, string $title, ?string $quality = null): array {
    $quality ??= extractQuality(messageFileName($message) . ' ' . messageText($message));
    $fileName = messageFileName($message);
    $sourceChatId = ownerBotChatId($madeline);

    if (catalogHasBotDeliverable($title)) {
        return ['stored' => false, 'already' => true, 'reason' => 'movie_already_stored', 'quality' => $quality];
    }
    if (catalogHasBotDeliverable($title) && catalogHasQuality($title, $quality)) {
        return ['stored' => false, 'already' => true, 'reason' => 'same_quality', 'quality' => $quality];
    }

    try {
        $storagePeer = $madeline->getInfo($storageBot, API::INFO_TYPE_PEER);
        $result = $madeline->messages->forwardMessages([
            'silent' => true,
            'drop_author' => true,
            'drop_media_captions' => true,
            'from_peer' => $fromPeer,
            'id' => [$message['id']],
            'to_peer' => $storageBot,
        ]);

        $messageId = sentMessageIdFromUpdates($result);
        if ($messageId === null) {
            $messageId = latestMessageId($madeline, $storagePeer) ?: null;
        }
        if ($messageId === null) {
            throw new RuntimeException('Could not detect stored forward message id');
        }

        $botMeta = captureBotStoredReference($title, $quality, $fileName, $sourceChatId);
        markCatalogStored($title, $quality, 'hidden_forward', array_merge([
            'message_id' => $messageId,
            'source_chat_id' => $sourceChatId,
            'file_name' => $fileName,
        ], $botMeta));
        return ['stored' => true, 'method' => 'hidden_forward', 'quality' => $quality];
    } catch (Throwable $forwardError) {
        $storagePeer = $madeline->getInfo($storageBot, API::INFO_TYPE_PEER);
        $result = $madeline->messages->sendMedia([
            'peer' => $storageBot,
            'media' => $message['media'],
            'message' => $title,
            'silent' => true,
        ]);
        $messageId = sentMessageIdFromUpdates($result);
        if ($messageId === null) {
            $messageId = latestMessageId($madeline, $storagePeer) ?: null;
        }

        $botMeta = captureBotStoredReference($title, $quality, $fileName, $sourceChatId);
        markCatalogStored($title, $quality, 'copied_existing_media', array_merge([
            'message_id' => $messageId,
            'source_chat_id' => $sourceChatId,
            'file_name' => $fileName,
        ], $botMeta));
        return ['stored' => true, 'method' => 'copied_existing_media', 'quality' => $quality];
    }
}

function clickResultButtons(API $madeline, array $peer, array $message, string $title, string $storageBot): array {
    foreach (getCandidateButtons($message, $title) as $button) {
        $label = trim((string) ($button['text'] ?? ''));
        $type = (string) ($button['_'] ?? '');

        if ($type === 'keyboardButtonCallback' && isset($button['data'])) {
            $answer = $madeline->messages->getBotCallbackAnswer([
                'peer' => $peer,
                'msg_id' => $message['id'],
                'data' => $button['data'],
            ]);
            if (!empty($answer['url']) && tryForwardTelegramUrl($madeline, (string) $answer['url'], $storageBot)) {
                return ['forwarded_url' => true, 'label' => $label];
            }
            return ['clicked' => true, 'label' => $label];
        }

        if ($type === 'keyboardButtonUrl' && isset($button['url'])) {
            if (tryForwardTelegramUrl($madeline, (string) $button['url'], $storageBot)) {
                return ['forwarded_url' => true, 'label' => $label];
            }
            return ['clicked' => false, 'label' => $label, 'url' => $button['url']];
        }

        if ($type === 'keyboardButton' && $label !== '') {
            $madeline->messages->sendMessage(['peer' => $peer, 'message' => $label]);
            return ['clicked' => true, 'label' => $label];
        }
    }

    return ['clicked' => false];
}

function latestMessageId(API $madeline, array $peer): int {
    $history = $madeline->messages->getHistory([
        'peer' => $peer,
        'limit' => 1,
        'offset_id' => 0,
    ]);

    $latest = 0;
    foreach (($history['messages'] ?? []) as $message) {
        $latest = max($latest, (int) ($message['id'] ?? 0));
    }

    return $latest;
}

function latestBotMessageId(API $madeline, string $botTarget): ?int {
    try {
        $peer = $madeline->getInfo(ltrim($botTarget, '@'), API::INFO_TYPE_PEER);
        return latestMessageId($madeline, $peer);
    } catch (Throwable $error) {
        return null;
    }
}

function findRecentDocumentMessage(API $madeline, array $peer, int $baselineId, string $title): ?array {
    $history = $madeline->messages->getHistory([
        'peer' => $peer,
        'limit' => 40,
        'offset_id' => 0,
    ]);

    $documents = [];
    foreach (($history['messages'] ?? []) as $message) {
        $messageId = (int) ($message['id'] ?? 0);
        if ($messageId <= $baselineId || !messageHasDocument($message)) {
            continue;
        }

        if (messageMatchesTitleOrFile($message, $title)) {
            return $message;
        }

        $documents[] = $message;
    }

    return count($documents) === 1 ? $documents[0] : null;
}

function peerHistoryOldestFirst(API $madeline, array $peer, int $limit): array {
    $limit = max(1, $limit);
    $messages = [];
    $offsetId = 0;

    while (count($messages) < $limit) {
        $batch = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => min(100, $limit - count($messages)),
            'offset_id' => $offsetId,
        ]);

        $rows = array_values((array) ($batch['messages'] ?? []));
        if (!$rows) {
            break;
        }

        $oldestId = null;
        foreach ($rows as $message) {
            if (!is_array($message)) {
                continue;
            }
            $messageId = (int) ($message['id'] ?? 0);
            if ($messageId > 0) {
                $oldestId = $oldestId === null ? $messageId : min($oldestId, $messageId);
            }
            $messages[] = $message;
        }

        if ($oldestId === null || $oldestId <= 1 || count($rows) < 100) {
            break;
        }
        $offsetId = $oldestId;
    }

    usort($messages, fn($left, $right) => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)));
    return $messages;
}

function searchPeerMessages(API $madeline, array $peer, string $title, int $limit = 35): array {
    $messages = [];

    try {
        $result = $madeline->messages->search([
            'peer' => $peer,
            'q' => $title,
            'filter' => ['_' => 'inputMessagesFilterDocument'],
            'min_date' => 0,
            'max_date' => 0,
            'offset_id' => 0,
            'add_offset' => 0,
            'limit' => $limit,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ]);
        $messages = array_values((array) ($result['messages'] ?? []));
    } catch (Throwable $searchError) {
        $messages = [];
    }

    if ($messages) {
        return $messages;
    }

    try {
        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => min(80, max($limit, 40)),
            'offset_id' => 0,
        ]);
        return array_values((array) ($history['messages'] ?? []));
    } catch (Throwable $historyError) {
        return [];
    }
}

function storeDirectTelegramLinksFromMessage(API $madeline, array $message, string $storageBot, string $title): array {
    foreach (($message['reply_markup']['rows'] ?? []) as $row) {
        foreach (($row['buttons'] ?? []) as $button) {
            $url = (string) ($button['url'] ?? '');
            if ($url === '' || !parseTelegramMessageUrl($url)) {
                continue;
            }

            $quality = extractQuality((string) ($button['text'] ?? '') . ' ' . messageText($message));
            $stored = storeTelegramMessageUrl($madeline, $url, $storageBot, $title, $quality);
            if (!empty($stored['stored']) || !empty($stored['already'])) {
                return array_merge($stored, ['quality' => $quality]);
            }
        }
    }

    return ['stored' => false];
}

function findAndStoreFromReadablePeer(API $madeline, array $peer, string $sourceName, string $title, string $storageBot): array {
    if (catalogHasBotDeliverable($title)) {
        return ['found' => true, 'already' => true, 'detail' => 'movie already stored and deliverable'];
    }

    $messages = searchPeerMessages($madeline, $peer, $title);
    $found = false;
    $linkCandidate = null;

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $matches = messageMatchesTitleOrFile($message, $title);
        if (!$matches) {
            continue;
        }

        $found = true;
        if (messageHasDocument($message)) {
            $quality = extractQuality(messageFileName($message) . ' ' . messageText($message));
            $stored = storeMovieMessage($madeline, $peer, $message, $storageBot, $title, $quality);

            if (!empty($stored['stored']) || !empty($stored['already'])) {
                return array_merge($stored, [
                    'found' => true,
                    'quality' => $quality,
                    'source' => $sourceName,
                ]);
            }
        }

        $directLink = storeDirectTelegramLinksFromMessage($madeline, $message, $storageBot, $title);
        if (!empty($directLink['stored']) || !empty($directLink['already'])) {
            return array_merge($directLink, [
                'found' => true,
                'source' => $sourceName,
            ]);
        }

        $linkCandidate ??= $message;
    }

    if ($linkCandidate) {
        return ['found' => true, 'stored' => false, 'source' => $sourceName];
    }

    return ['found' => $found, 'stored' => false, 'source' => $sourceName];
}

function peerUsername(array $peer): string {
    $username = (string) ($peer['username'] ?? '');
    if ($username !== '') {
        return '@' . ltrim($username, '@');
    }

    foreach ((array) ($peer['usernames'] ?? []) as $row) {
        $value = (string) ($row['username'] ?? '');
        if ($value !== '') {
            return '@' . ltrim($value, '@');
        }
    }

    return '';
}

function peerDisplayTitle(array $peer): string {
    $title = trim((string) ($peer['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    $name = trim(((string) ($peer['first_name'] ?? '')) . ' ' . ((string) ($peer['last_name'] ?? '')));
    if ($name !== '') {
        return $name;
    }

    return (string) ($peer['username'] ?? $peer['id'] ?? 'Unknown');
}

function sourceFromInfo(array $info, string|int|null $fallbackId = null): ?array {
    $peer = [];
    foreach (['User', 'Chat', 'Channel', 'chat', 'user', 'channel'] as $key) {
        if (isset($info[$key]) && is_array($info[$key])) {
            $peer = $info[$key];
            break;
        }
    }
    if (!$peer && isset($info['id'])) {
        $peer = $info;
    }
    if (!$peer) {
        return null;
    }

    $target = peerUsername($peer);
    $type = (string) ($info['type'] ?? $peer['_'] ?? 'peer');
    $id = $fallbackId ?? ($peer['id'] ?? '');

    if ($target === '' && $id !== '') {
        $rawId = (string) $id;
        $target = str_starts_with($rawId, '-')
            ? $rawId
            : ((str_contains(strtolower($type), 'channel') || str_contains(strtolower($type), 'chat')) ? '-100' . $rawId : $rawId);
    }
    if ($target === '') {
        return null;
    }

    $isBot = !empty($peer['bot']) || ($info['type'] ?? '') === API::PEER_TYPE_BOT;
    $kind = $isBot ? 'bot' : (str_contains(strtolower($type), 'channel') ? 'channel' : (str_contains(strtolower($type), 'chat') ? 'group' : 'peer'));

    return [
        'target' => $target,
        'title' => peerDisplayTitle($peer),
        'type' => $kind,
    ];
}

function discoverTelegramSources(int $limit = 80): array {
    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $limit = max(1, min(200, $limit));
    $sources = [];
    $seen = [];

    try {
        $ids = array_slice($madeline->getDialogIds(), 0, $limit);
    } catch (Throwable $dialogError) {
        return ['error' => 'Could not read Telegram dialogs: ' . $dialogError->getMessage()];
    }

    foreach ($ids as $id) {
        try {
            $source = sourceFromInfo($madeline->getInfo($id), $id);
        } catch (Throwable $infoError) {
            continue;
        }
        if (!$source) {
            continue;
        }
        if (($source['type'] ?? '') === 'peer') {
            continue;
        }

        $key = strtolower((string) $source['target']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $sources[] = $source;
    }

    return ['success' => true, 'sources' => $sources, 'count' => count($sources)];
}

function peerMapsFromSearchResult(array $result): array {
    $chats = [];
    $users = [];

    foreach ((array) ($result['chats'] ?? []) as $chat) {
        if (isset($chat['id'])) {
            $chats[(string) $chat['id']] = $chat;
        }
    }
    foreach ((array) ($result['users'] ?? []) as $user) {
        if (isset($user['id'])) {
            $users[(string) $user['id']] = $user;
        }
    }

    return [$chats, $users];
}

function sourceFromMessagePeer(array $message, array $chats, array $users): ?array {
    $peerId = (array) ($message['peer_id'] ?? []);
    $type = (string) ($peerId['_'] ?? '');

    if ($type === 'peerChannel' && isset($peerId['channel_id'])) {
        $id = (string) $peerId['channel_id'];
        return sourceFromInfo(['type' => 'channel', 'Chat' => $chats[$id] ?? ['id' => $id]], '-100' . $id);
    }
    if ($type === 'peerChat' && isset($peerId['chat_id'])) {
        $id = (string) $peerId['chat_id'];
        return sourceFromInfo(['type' => 'chat', 'Chat' => $chats[$id] ?? ['id' => $id]], '-' . $id);
    }
    if ($type === 'peerUser' && isset($peerId['user_id'])) {
        $id = (string) $peerId['user_id'];
        return sourceFromInfo(['type' => 'user', 'User' => $users[$id] ?? ['id' => $id]], $id);
    }

    return null;
}

function discoverGlobalSourcesForTitles(array $titles, int $limitPerTitle = 12): array {
    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $titles = array_values(array_filter(array_map('trim', $titles), fn($title) => $title !== ''));
    if (!$titles) {
        return ['error' => 'Add at least one movie title first.'];
    }

    $limitPerTitle = max(3, min(30, $limitPerTitle));
    $sources = [];
    $seen = [];

    foreach ($titles as $title) {
        try {
            $result = $madeline->messages->searchGlobal([
                'filter' => ['_' => 'inputMessagesFilterDocument'],
                'q' => $title,
                'limit' => $limitPerTitle,
                'offset_rate' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
                'offset_id' => 0,
            ]);
        } catch (Throwable $globalError) {
            continue;
        }

        [$chats, $users] = peerMapsFromSearchResult((array) $result);
        foreach ((array) ($result['messages'] ?? []) as $message) {
            if (!is_array($message) || !messageMatchesTitleOrFile($message, $title)) {
                continue;
            }

            $source = sourceFromMessagePeer($message, $chats, $users);
            if (!$source) {
                continue;
            }
            if (($source['type'] ?? '') === 'peer') {
                continue;
            }

            $key = strtolower((string) $source['target']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $source['matched_title'] = $title;
            $sources[] = $source;
        }
    }

    return ['success' => true, 'sources' => $sources, 'count' => count($sources)];
}

function importTitleFromMessage(array $message, string $fallback = ''): string {
    $fileName = messageFileName($message);
    if ($fileName !== '') {
        return cleanTitleFromFilename($fileName);
    }

    $text = messageText($message);
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || preg_match('/https?:\/\/|t\.me\//i', $line)) {
            continue;
        }
        $line = preg_replace('/\b(get|open|download|watch|request|movie|movies|file|files|via|bot|click|here)\b/i', ' ', $line) ?? $line;
        $line = preg_replace('/[^\pL\pN\s._()[\]-]+/u', ' ', $line) ?? $line;
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = trim($line);
        if ($line !== '' && normalizeSearchText($line) !== '') {
            return mb_substr($line, 0, 120);
        }
    }

    $fallback = cleanTitleFromFilename($fallback);
    return $fallback !== '' ? $fallback : 'Imported movie';
}

function importTitleFromButton(array $button, array $message, string $fallback = ''): string {
    $label = trim((string) ($button['text'] ?? ''));
    if ($label !== '' && !preg_match('/^\s*(get|open|download|watch|request|movie|movies|file|files|via|bot|click|here|start)\b/i', $label)) {
        return cleanTitleFromFilename($label);
    }

    return importTitleFromMessage($message, $fallback !== '' ? $fallback : $label);
}

function isImportButton(array $button): bool {
    $label = trim((string) ($button['text'] ?? ''));
    $type = (string) ($button['_'] ?? '');
    $url = (string) ($button['url'] ?? '');

    if ($url !== '' && (parseTelegramStartUrl($url) || parseTelegramMessageUrl($url))) {
        return true;
    }

    if ($label === '' || preg_match('/\b(join|subscribe|ads?|support|help|share|rate|premium|vip)\b/i', $label)) {
        return false;
    }

    if ($type === 'keyboardButtonCallback' && isset($button['data'])) {
        return preg_match('/\b(get|open|download|watch|movie|movies|file|files|bot|request|search)\b/i', $label) === 1
            || preg_match('/\b(2160p|1080p|720p|480p|mkv|mp4|gb|mb|web-?dl|bluray|hdrip)\b/i', $label) === 1;
    }

    return false;
}

function isBotListButton(array $button): bool {
    $label = trim((string) ($button['text'] ?? ''));
    $type = (string) ($button['_'] ?? '');
    $url = (string) ($button['url'] ?? '');

    if ($url !== '' && (parseTelegramStartUrl($url) || parseTelegramMessageUrl($url))) {
        return true;
    }
    if ($label === '' || preg_match('/\b(join|subscribe|ads?|support|help|share|rate|premium|vip|back|home|menu|close)\b/i', $label)) {
        return false;
    }

    return in_array($type, ['keyboardButtonCallback', 'keyboardButtonUrl', 'keyboardButton'], true);
}

function importButtonKey(array $button): string {
    return normalizeSearchText(
        ($button['_'] ?? '') . ' '
        . (string) ($button['text'] ?? '') . ' '
        . (string) ($button['url'] ?? '') . ' '
        . base64_encode((string) ($button['data'] ?? ''))
    );
}

function startUrlScore(?array $start, string $preferredBot = CHANNEL_IMPORT_PREFERRED_BOT): int {
    if (!$start) {
        return 0;
    }

    $domain = strtolower((string) ($start['domain'] ?? ''));
    if ($domain === strtolower(ltrim($preferredBot, '@'))) {
        return 100;
    }
    if (str_contains($domain, 'file') || str_contains($domain, 'store') || str_contains($domain, 'movie')) {
        return 50;
    }

    return 10;
}

function sortImportButtonCandidates(array &$candidates, string $preferredBot = CHANNEL_IMPORT_PREFERRED_BOT): void {
    uasort($candidates, function (array $left, array $right) use ($preferredBot): int {
        $leftButton = (array) ($left['button'] ?? []);
        $rightButton = (array) ($right['button'] ?? []);
        $leftUrl = (string) ($leftButton['url'] ?? '');
        $rightUrl = (string) ($rightButton['url'] ?? '');

        $leftScore = startUrlScore($leftUrl !== '' ? parseTelegramStartUrl($leftUrl) : null, $preferredBot);
        $rightScore = startUrlScore($rightUrl !== '' ? parseTelegramStartUrl($rightUrl) : null, $preferredBot);

        return $rightScore <=> $leftScore;
    });
}

function streamStoredImport(array $stored, string $title, string $source, int $storedCount): void {
    if (!empty($stored['stored'])) {
        streamJsonLine([
            'status' => 'stored',
            'title' => $title,
            'quality' => $stored['quality'] ?? 'unknown',
            'detail' => ($stored['method'] ?? 'stored') . ' from ' . $source,
            'stored_count' => $storedCount,
            'stored_total' => catalogItemCount($title),
        ]);
    } elseif (!empty($stored['already'])) {
        streamJsonLine([
            'status' => 'already_available',
            'title' => $title,
            'quality' => $stored['quality'] ?? 'unknown',
            'detail' => $stored['reason'] ?? 'already stored',
        ]);
    }
}

function storeImportedDocument(API $madeline, array $fromPeer, array $message, string $storageBot, string $fallbackTitle, string $source): array {
    $title = importTitleFromMessage($message, $fallbackTitle);
    if (normalizeSearchText($title) === '') {
        return ['stored' => false, 'title' => $title, 'reason' => 'missing_title'];
    }

    $quality = extractQuality(messageFileName($message) . ' ' . messageText($message));
    $stored = storeMovieMessage($madeline, $fromPeer, $message, $storageBot, $title, $quality);
    return array_merge($stored, [
        'title' => $title,
        'quality' => $quality,
        'source' => $source,
    ]);
}

function storeImportedTelegramUrl(API $madeline, string $url, string $storageBot, string $title, string $label, string $source): array {
    $title = importTitleFromMessage(['message' => $title], $label);
    if (normalizeSearchText($title) === '') {
        return ['stored' => false, 'title' => $title, 'reason' => 'missing_title'];
    }

    $quality = extractQuality($label . ' ' . $title);
    $stored = storeTelegramMessageUrl($madeline, $url, $storageBot, $title, $quality);
    return array_merge($stored, [
        'title' => $title,
        'quality' => $quality,
        'source' => $source,
    ]);
}

function findPeerMessageById(API $madeline, array $peer, int $messageId): ?array {
    if ($messageId <= 0) {
        return null;
    }

    try {
        $result = $madeline->channels->getMessages([
            'channel' => $peer,
            'id' => [$messageId],
        ]);
        foreach ((array) ($result['messages'] ?? []) as $message) {
            if ((int) ($message['id'] ?? 0) === $messageId) {
                return $message;
            }
        }
    } catch (Throwable $channelError) {
        // Some peers are not channels; fall back to local history.
    }

    try {
        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => 8,
            'offset_id' => $messageId + 1,
        ]);
        foreach ((array) ($history['messages'] ?? []) as $message) {
            if ((int) ($message['id'] ?? 0) === $messageId) {
                return $message;
            }
        }
    } catch (Throwable $historyError) {
        return null;
    }

    return null;
}

function messageSnippet(array $message, int $length = 180): string {
    $text = preg_replace('/\s+/', ' ', messageText($message)) ?? '';
    $text = trim($text);
    if ($text === '') {
        $text = messageFileName($message);
    }

    return mb_substr($text, 0, $length);
}

function previewItemKey(string $target, int $messageId, string $buttonKey = 'document'): string {
    return substr(hash('sha256', strtolower($target) . ':' . $messageId . ':' . $buttonKey), 0, 24);
}

function buttonBotDomain(array $button): string {
    $url = (string) ($button['url'] ?? '');
    $start = $url !== '' ? parseTelegramStartUrl($url) : null;
    return $start ? '@' . ltrim((string) $start['domain'], '@') : '';
}

function buildPreviewItem(string $target, array $message, ?array $button = null): array {
    $messageId = (int) ($message['id'] ?? 0);
    $buttonKey = $button ? importButtonKey($button) : 'document';
    $label = $button ? trim((string) ($button['text'] ?? '')) : 'Direct file';
    $title = $button ? importTitleFromButton($button, $message, $label) : importTitleFromMessage($message, $label);

    return [
        'key' => previewItemKey($target, $messageId, $buttonKey),
        'target' => $target,
        'message_id' => $messageId,
        'button_key' => $button ? $buttonKey : '',
        'kind' => $button ? 'button' : 'document',
        'title' => $title,
        'label' => $label,
        'bot' => $button ? buttonBotDomain($button) : '',
        'snippet' => messageSnippet($message),
        'stored' => catalogHasBotDeliverable($title),
    ];
}

function previewChannelLists(string $sourceInput, int $offsetId = 0, int $limit = 30, string $preferredBot = CHANNEL_IMPORT_PREFERRED_BOT, array $skipKeys = []): array {
    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $targets = sourceTargetsFromInput($sourceInput);
    if (!$targets) {
        return ['error' => 'Add a channel, group, or bot source.'];
    }

    $target = $targets[0];
    $limit = max(5, min(80, $limit));
    $skip = array_fill_keys(array_map('strval', $skipKeys), true);

    try {
        $targetForInfo = ltrim($target, '@');
        $peer = $madeline->getInfo($targetForInfo, API::INFO_TYPE_PEER);
        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => $limit,
            'offset_id' => max(0, $offsetId),
        ]);
    } catch (Throwable $error) {
        return ['error' => $error->getMessage()];
    }

    $items = [];
    $oldestId = 0;
    foreach ((array) ($history['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $messageId = (int) ($message['id'] ?? 0);
        if ($messageId > 0) {
            $oldestId = $oldestId === 0 ? $messageId : min($oldestId, $messageId);
        }

        if (messageHasDocument($message)) {
            $item = buildPreviewItem($target, $message);
            if (empty($skip[$item['key']]) && !$item['stored']) {
                $items[] = $item;
            }
        }

        $buttonItems = [];
        foreach (($message['reply_markup']['rows'] ?? []) as $row) {
            foreach (($row['buttons'] ?? []) as $button) {
                if (!isImportButton($button)) {
                    continue;
                }
                $item = buildPreviewItem($target, $message, $button);
                if (empty($skip[$item['key']]) && !$item['stored']) {
                    $buttonItems[] = $item;
                }
            }
        }
        usort($buttonItems, fn($left, $right) => startUrlScore($right['bot'] ? ['domain' => ltrim((string) $right['bot'], '@')] : null, $preferredBot) <=> startUrlScore($left['bot'] ? ['domain' => ltrim((string) $left['bot'], '@')] : null, $preferredBot));
        array_push($items, ...$buttonItems);
    }

    return [
        'success' => true,
        'target' => $target,
        'items' => array_values($items),
        'next_offset_id' => $oldestId,
        'has_more' => $oldestId > 1 && count((array) ($history['messages'] ?? [])) >= $limit,
    ];
}

function streamSelectedBatchComplete(string $title, int $storedBefore, int $storedTotal): void {
    streamJsonLine([
        'status' => 'source_complete',
        'title' => $title,
        'stored_count' => max(0, $storedTotal - $storedBefore),
    ]);
}

function importSelectedChannelLists(array $items, string $preferredBot = CHANNEL_IMPORT_PREFERRED_BOT): void {
    global $BOT_USERNAME;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        echo json_encode(['error' => 'Please login first']) . "\n";
        exit;
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/x-ndjson');
    set_time_limit(0);
    ignore_user_abort(false);
    clearStopScrape();

    $storedTotal = 0;
    $itemTotal = count($items);
    $itemIndex = 0;
    foreach ($items as $item) {
        if (shouldStopScrape()) {
            streamJsonLine(['status' => 'stopped']);
            break;
        }
        if (!is_array($item)) {
            continue;
        }

        $target = normalizeTelegramSourceTarget((string) ($item['target'] ?? ''));
        $messageId = (int) ($item['message_id'] ?? 0);
        $buttonKey = (string) ($item['button_key'] ?? '');
        $fallbackTitle = (string) ($item['title'] ?? $target);
        if ($target === '' || $messageId <= 0) {
            continue;
        }

        $itemIndex++;
        $itemStoredBefore = $storedTotal;
        streamJsonLine([
            'status' => 'source_import',
            'title' => $fallbackTitle,
            'detail' => 'selected batch ' . $itemIndex . ' of ' . $itemTotal . ' from ' . $target,
        ]);

        try {
            $targetForInfo = ltrim($target, '@');
            $peer = $madeline->getInfo($targetForInfo, API::INFO_TYPE_PEER);
            $message = findPeerMessageById($madeline, $peer, $messageId);
            if (!$message) {
                streamJsonLine(['status' => 'found_no_file', 'title' => $fallbackTitle, 'detail' => 'message not found']);
                streamSelectedBatchComplete($fallbackTitle, $itemStoredBefore, $storedTotal);
                continue;
            }

            if ($buttonKey === '' && messageHasDocument($message)) {
                $stored = storeImportedDocument($madeline, $peer, $message, $BOT_USERNAME, $fallbackTitle, $target);
                if (!empty($stored['stored'])) {
                    $storedTotal++;
                }
                streamStoredImport($stored, $stored['title'] ?? $fallbackTitle, $target, $storedTotal);
                streamSelectedBatchComplete($stored['title'] ?? $fallbackTitle, $itemStoredBefore, $storedTotal);
                continue;
            }

            $button = null;
            foreach (($message['reply_markup']['rows'] ?? []) as $row) {
                foreach (($row['buttons'] ?? []) as $candidate) {
                    if (importButtonKey($candidate) === $buttonKey) {
                        $button = $candidate;
                        break 2;
                    }
                }
            }
            if (!$button) {
                streamJsonLine(['status' => 'found_no_file', 'title' => $fallbackTitle, 'detail' => 'selected button not found']);
                streamSelectedBatchComplete($fallbackTitle, $itemStoredBefore, $storedTotal);
                continue;
            }

            $label = (string) ($button['text'] ?? '');
            $url = (string) ($button['url'] ?? '');
            $buttonTitle = importTitleFromButton($button, $message, $fallbackTitle);

            if ($url !== '' && parseTelegramMessageUrl($url)) {
                $stored = storeImportedTelegramUrl($madeline, $url, $BOT_USERNAME, $buttonTitle, $label, $target);
                if (!empty($stored['stored'])) {
                    $storedTotal++;
                }
                streamStoredImport($stored, $stored['title'] ?? $buttonTitle, $target, $storedTotal);
                streamSelectedBatchComplete($stored['title'] ?? $buttonTitle, $itemStoredBefore, $storedTotal);
                continue;
            }

            $start = $url !== '' ? parseTelegramStartUrl($url) : null;
            if (!$start && (($button['_'] ?? '') === 'keyboardButtonCallback') && isset($button['data'])) {
                $click = clickOneButton($madeline, $peer, $message, $button, $BOT_USERNAME);
                $start = !empty($click['url']) ? parseTelegramStartUrl((string) $click['url']) : null;
                if (!$start && !empty($click['url']) && parseTelegramMessageUrl((string) $click['url'])) {
                    $stored = storeImportedTelegramUrl($madeline, (string) $click['url'], $BOT_USERNAME, $buttonTitle, $label, $target);
                    if (!empty($stored['stored'])) {
                        $storedTotal++;
                    }
                    streamStoredImport($stored, $stored['title'] ?? $buttonTitle, $target, $storedTotal);
                    streamSelectedBatchComplete($stored['title'] ?? $buttonTitle, $itemStoredBefore, $storedTotal);
                    continue;
                }
            }

            if ($start) {
                $botBaseline = latestBotMessageId($madeline, (string) $start['domain']);
                streamJsonLine([
                    'status' => 'opening',
                    'title' => $buttonTitle,
                    'detail' => 'opening @' . $start['domain'] . ' and waiting for the full batch',
                ]);
                openTelegramStartUrl($madeline, $peer, 'https://t.me/' . $start['domain'] . '?start=' . rawurlencode($start['payload']));
                usleep(500000);
                $botImport = observeBotImport(
                    $madeline,
                    $start['domain'],
                    $buttonTitle,
                    $BOT_USERNAME,
                    CHANNEL_IMPORT_MAX_FILES_PER_SELECTED,
                    $target,
                    $preferredBot,
                    $botBaseline,
                    CHANNEL_IMPORT_SELECTED_BOT_TIMEOUT,
                    CHANNEL_IMPORT_BOT_IDLE_SECONDS,
                    CHANNEL_IMPORT_SELECTED_MAX_BOT_CLICKS
                );
                $storedTotal += (int) ($botImport['stored'] ?? 0);
                streamSelectedBatchComplete($buttonTitle, $itemStoredBefore, $storedTotal);
                continue;
            }

            $botBaseline = latestBotMessageId($madeline, ltrim($preferredBot, '@'));
            $click = clickOneButton($madeline, $peer, $message, $button, $BOT_USERNAME);
            if (!empty($click['clicked'])) {
                usleep(500000);
                $botImport = observeBotImport(
                    $madeline,
                    ltrim($preferredBot, '@'),
                    $buttonTitle,
                    $BOT_USERNAME,
                    CHANNEL_IMPORT_MAX_FILES_PER_SELECTED,
                    $target,
                    $preferredBot,
                    $botBaseline,
                    CHANNEL_IMPORT_SELECTED_BOT_TIMEOUT,
                    CHANNEL_IMPORT_BOT_IDLE_SECONDS,
                    CHANNEL_IMPORT_SELECTED_MAX_BOT_CLICKS
                );
                $storedTotal += (int) ($botImport['stored'] ?? 0);
            }
            streamSelectedBatchComplete($buttonTitle, $itemStoredBefore, $storedTotal);
        } catch (Throwable $error) {
            streamJsonLine(['status' => 'found_no_file', 'title' => $fallbackTitle, 'detail' => $error->getMessage()]);
            streamSelectedBatchComplete($fallbackTitle, $itemStoredBefore, $storedTotal);
        }
    }

    streamJsonLine(['status' => 'complete', 'stored_count' => $storedTotal]);
    clearStopScrape();
    exit;
}

function queueImportButtons(array $message, array &$queue, array $clickedKeys, string $fallbackTitle, bool $botListMode = false): void {
    foreach (($message['reply_markup']['rows'] ?? []) as $row) {
        foreach (($row['buttons'] ?? []) as $button) {
            if ($botListMode ? !isBotListButton($button) : !isImportButton($button)) {
                continue;
            }

            $key = importButtonKey($button);
            if ($key === '' || isset($clickedKeys[$key]) || isset($queue[$key])) {
                continue;
            }

            $queue[$key] = [
                'button' => $button,
                'message' => $message,
                'title' => importTitleFromButton($button, $message, $fallbackTitle),
            ];
        }
    }
}

function observeBotImport(
    API $madeline,
    string $botTarget,
    string $fallbackTitle,
    string $storageBot,
    int $maxFiles,
    string $source,
    string $preferredBot = CHANNEL_IMPORT_PREFERRED_BOT,
    ?int $baselineId = null,
    ?float $timeout = null,
    ?float $idleSeconds = null,
    int $maxClicks = CHANNEL_IMPORT_MAX_BOT_CLICKS
): array {
    $botTarget = ltrim($botTarget, '@');
    $peer = $madeline->getInfo($botTarget, API::INFO_TYPE_PEER);
    $baselineId ??= latestMessageId($madeline, $peer);
    $timeout ??= CHANNEL_IMPORT_BOT_TIMEOUT;
    $idleSeconds ??= CHANNEL_IMPORT_BOT_IDLE_SECONDS;
    $start = microtime(true);
    $lastActivity = $start;
    $sawActivity = false;
    $awaitingClickResult = false;
    $lastClickAt = 0.0;
    $storedCount = 0;
    $processedIds = [];
    $clickedKeys = [];
    $buttonQueue = [];
    $clicks = 0;

    while (microtime(true) - $start < $timeout && $storedCount < $maxFiles) {
        if (shouldStopScrape()) {
            break;
        }

        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => 100,
            'offset_id' => 0,
        ]);

        $newMessageSeen = false;
        foreach (array_reverse((array) ($history['messages'] ?? [])) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $messageId = (int) ($message['id'] ?? 0);
            if ($messageId <= $baselineId || isset($processedIds[$messageId])) {
                continue;
            }
            $processedIds[$messageId] = true;
            $newMessageSeen = true;
            $sawActivity = true;
            $lastActivity = microtime(true);

            if (messageHasDocument($message)) {
                $stored = storeImportedDocument($madeline, $peer, $message, $storageBot, $fallbackTitle, $source);
                if (!empty($stored['stored'])) {
                    $storedCount++;
                }
                streamStoredImport($stored, $stored['title'] ?? $fallbackTitle, $source, $storedCount);
            }

            foreach (($message['reply_markup']['rows'] ?? []) as $row) {
                foreach (($row['buttons'] ?? []) as $button) {
                    $url = (string) ($button['url'] ?? '');
                    if ($url !== '' && parseTelegramMessageUrl($url)) {
                        $stored = storeImportedTelegramUrl(
                            $madeline,
                            $url,
                            $storageBot,
                            importTitleFromButton($button, $message, $fallbackTitle),
                            (string) ($button['text'] ?? ''),
                            $source
                        );
                        if (!empty($stored['stored'])) {
                            $storedCount++;
                        }
                        streamStoredImport($stored, $stored['title'] ?? $fallbackTitle, $source, $storedCount);
                    }
                }
            }

            queueImportButtons($message, $buttonQueue, $clickedKeys, $fallbackTitle, true);
            sortImportButtonCandidates($buttonQueue, $preferredBot);
        }

        if ($awaitingClickResult) {
            $now = microtime(true);
            $settledAfterResult = $newMessageSeen && ($now - $lastActivity) >= CHANNEL_IMPORT_CLICK_SETTLE_SECONDS;
            $quietAfterResult = $lastActivity > $lastClickAt && ($now - $lastActivity) >= CHANNEL_IMPORT_CLICK_SETTLE_SECONDS;
            $clickTimedOut = ($now - $lastClickAt) >= CHANNEL_IMPORT_CLICK_TIMEOUT_SECONDS;
            if ($settledAfterResult || $quietAfterResult || $clickTimedOut) {
                $awaitingClickResult = false;
            }
        }

        if ($buttonQueue && !$awaitingClickResult && $clicks < $maxClicks && $storedCount < $maxFiles) {
            $key = array_key_first($buttonQueue);
            $candidate = $buttonQueue[$key];
            unset($buttonQueue[$key]);
            $clickedKeys[$key] = true;
            $clicks++;
            $awaitingClickResult = true;
            $lastClickAt = microtime(true);
            $lastActivity = $lastClickAt;
            $sawActivity = true;

            streamJsonLine([
                'status' => 'opening',
                'title' => $candidate['title'] ?: $fallbackTitle,
                'detail' => 'opening import button ' . $clicks . ' in @' . $botTarget,
            ]);

            $buttonUrl = (string) ($candidate['button']['url'] ?? '');
            $nestedStart = $buttonUrl !== '' ? parseTelegramStartUrl($buttonUrl) : null;
            $nestedBaseline = $nestedStart ? latestBotMessageId($madeline, (string) $nestedStart['domain']) : null;
            $click = clickOneButton($madeline, $peer, $candidate['message'], $candidate['button'], $storageBot);
            if (!empty($click['stored_url']) && !empty($click['url']) && parseTelegramMessageUrl((string) $click['url'])) {
                $stored = storeImportedTelegramUrl(
                    $madeline,
                    (string) $click['url'],
                    $storageBot,
                    $candidate['title'] ?: $fallbackTitle,
                    (string) ($candidate['button']['text'] ?? ''),
                    $source
                );
                if (!empty($stored['stored'])) {
                    $storedCount++;
                }
                streamStoredImport($stored, $stored['title'] ?? $fallbackTitle, $source, $storedCount);
                $awaitingClickResult = false;
            }
            if (!empty($click['opened_start_url']) && $nestedStart) {
                usleep(500000);
                $remainingFiles = max(0, $maxFiles - $storedCount);
                if ($remainingFiles > 0) {
                    $nestedImport = observeBotImport(
                        $madeline,
                        (string) $nestedStart['domain'],
                        $candidate['title'] ?: $fallbackTitle,
                        $storageBot,
                        $remainingFiles,
                        $source,
                        $preferredBot,
                        $nestedBaseline,
                        max(10.0, $timeout - (microtime(true) - $start)),
                        $idleSeconds,
                        max(1, $maxClicks - $clicks)
                    );
                    $storedCount += (int) ($nestedImport['stored'] ?? 0);
                }
                $awaitingClickResult = false;
            }
        }

        if ($sawActivity && !$buttonQueue && !$awaitingClickResult && microtime(true) - $lastActivity >= $idleSeconds) {
            break;
        }

        usleep(250000);
    }

    return ['stored' => $storedCount, 'clicked' => $clicks];
}

function importChannelSources(string $sourceInput, int $historyLimit = CHANNEL_IMPORT_HISTORY_LIMIT, string $preferredBot = CHANNEL_IMPORT_PREFERRED_BOT, bool $directOnly = false): void {
    global $BOT_USERNAME;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        echo json_encode(['error' => 'Please login first']) . "\n";
        exit;
    }

    $targets = sourceTargetsFromInput($sourceInput);
    if (!$targets) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: application/x-ndjson');
        streamJsonLine(['error' => 'Add at least one channel, group, or bot source.']);
        streamJsonLine(['status' => 'complete']);
        exit;
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/x-ndjson');
    set_time_limit(0);
    ignore_user_abort(false);

    $historyLimit = max(10, min(500, $historyLimit));
    $maxFilesPerSource = $directOnly ? $historyLimit : CHANNEL_IMPORT_MAX_FILES_PER_SOURCE;
    $stopped = false;

    foreach ($targets as $target) {
        if (shouldStopScrape()) {
            $stopped = true;
            break;
        }

        $sourceName = $target;
        $sourceStored = 0;
        streamJsonLine([
            'status' => 'source_import',
            'title' => $sourceName,
            'detail' => $directOnly ? 'scanning direct files only' : 'scanning recent posts and bot buttons',
        ]);

        try {
            $targetForInfo = ltrim($target, '@');
            $peerInfo = $madeline->getInfo($targetForInfo);
            $peer = $madeline->getInfo($targetForInfo, API::INFO_TYPE_PEER);
            $isBot = ($peerInfo['type'] ?? '') === API::PEER_TYPE_BOT || !empty($peerInfo['User']['bot']);

            if ($directOnly && $isBot) {
                streamJsonLine([
                    'status' => 'found_no_file',
                    'title' => $sourceName,
                    'detail' => 'Direct file mode expects a channel or group, not a bot.',
                ]);
                continue;
            }

            if ($isBot) {
                $botBaseline = latestBotMessageId($madeline, $targetForInfo);
                $madeline->messages->sendMessage(['peer' => $peer, 'message' => '/start']);
                $botImport = observeBotImport($madeline, $targetForInfo, $sourceName, $BOT_USERNAME, $maxFilesPerSource, $sourceName, $preferredBot, $botBaseline);
                $sourceStored += (int) ($botImport['stored'] ?? 0);
            } else {
                $history = ['messages' => peerHistoryOldestFirst($madeline, $peer, $historyLimit)];

                foreach ((array) ($history['messages'] ?? []) as $message) {
                    if (shouldStopScrape()) {
                        $stopped = true;
                        break 2;
                    }
                    if (!is_array($message)) {
                        continue;
                    }

                    $fallbackTitle = importTitleFromMessage($message, $sourceName);
                    if (messageHasDocument($message)) {
                        $stored = storeImportedDocument($madeline, $peer, $message, $BOT_USERNAME, $fallbackTitle, $sourceName);
                        if (!empty($stored['stored'])) {
                            $sourceStored++;
                        }
                        streamStoredImport($stored, $stored['title'] ?? $fallbackTitle, $sourceName, $sourceStored);
                    }

                    if ($directOnly) {
                        if ($sourceStored >= $maxFilesPerSource) {
                            break;
                        }
                        continue;
                    }

                    foreach (($message['reply_markup']['rows'] ?? []) as $row) {
                        foreach (($row['buttons'] ?? []) as $button) {
                            if (!isImportButton($button)) {
                                continue;
                            }

                            $label = (string) ($button['text'] ?? '');
                            $url = (string) ($button['url'] ?? '');
                            $buttonTitle = importTitleFromButton($button, $message, $fallbackTitle);
                            $preferredStart = $url !== '' ? parseTelegramStartUrl($url) : null;

                            if ($url !== '' && parseTelegramMessageUrl($url)) {
                                $stored = storeImportedTelegramUrl($madeline, $url, $BOT_USERNAME, $buttonTitle, $label, $sourceName);
                                if (!empty($stored['stored'])) {
                                    $sourceStored++;
                                }
                                streamStoredImport($stored, $stored['title'] ?? $buttonTitle, $sourceName, $sourceStored);
                                continue;
                            }

                            $start = $preferredStart;
                            if (!$start && (($button['_'] ?? '') === 'keyboardButtonCallback') && isset($button['data'])) {
                                $click = clickOneButton($madeline, $peer, $message, $button, $BOT_USERNAME);
                                $start = !empty($click['url']) ? parseTelegramStartUrl((string) $click['url']) : null;
                                if (!$start && !empty($click['url']) && parseTelegramMessageUrl((string) $click['url'])) {
                                    $stored = storeImportedTelegramUrl($madeline, (string) $click['url'], $BOT_USERNAME, $buttonTitle, $label, $sourceName);
                                    if (!empty($stored['stored'])) {
                                        $sourceStored++;
                                    }
                                    streamStoredImport($stored, $stored['title'] ?? $buttonTitle, $sourceName, $sourceStored);
                                }
                            }

                            if ($start) {
                                $botBaseline = latestBotMessageId($madeline, (string) $start['domain']);
                                streamJsonLine([
                                    'status' => 'opening',
                                    'title' => $buttonTitle,
                                    'detail' => 'opening @' . $start['domain'] . ' from ' . $sourceName,
                                ]);
                                openTelegramStartUrl($madeline, $peer, 'https://t.me/' . $start['domain'] . '?start=' . rawurlencode($start['payload']));
                                usleep(500000);
                                $remaining = max(0, $maxFilesPerSource - $sourceStored);
                                if ($remaining > 0) {
                                    $botImport = observeBotImport($madeline, $start['domain'], $buttonTitle, $BOT_USERNAME, $remaining, $sourceName, $preferredBot, $botBaseline);
                                    $sourceStored += (int) ($botImport['stored'] ?? 0);
                                }
                            }

                            if ($sourceStored >= $maxFilesPerSource) {
                                break 3;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $sourceError) {
            streamJsonLine([
                'status' => 'found_no_file',
                'title' => $sourceName,
                'detail' => $sourceError->getMessage(),
            ]);
        }

        streamJsonLine([
            'status' => 'source_complete',
            'title' => $sourceName,
            'stored_count' => $sourceStored,
        ]);
    }

    if ($stopped) {
        streamJsonLine(['status' => 'stopped']);
    }
    clearStopScrape();
    streamJsonLine(['status' => 'complete']);
    exit;
}

function botApi(string $method, array $params = [], int $timeout = 25): array {
    global $BOT_TOKEN;

    if ($BOT_TOKEN === '') {
        return ['ok' => false, 'description' => 'Missing TELEGRAM_BOT_TOKEN environment variable'];
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

    $response = @file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/$method", false, $context);
    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'description' => 'Bot API request failed'];
    }

    return $data;
}

function readBotOffset(): int {
    global $BOT_OFFSET_FILE;
    return is_file($BOT_OFFSET_FILE) ? (int) trim((string) file_get_contents($BOT_OFFSET_FILE)) : 0;
}

function writeBotOffset(int $offset): void {
    global $SESSION_DIR, $BOT_OFFSET_FILE;

    if (!is_dir($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0700, true);
    }
    file_put_contents($BOT_OFFSET_FILE, (string) $offset);
}

function botDocumentMeta(array $message): ?array {
    $document = $message['document'] ?? null;
    if (!is_array($document)) {
        $document = $message['video'] ?? null;
    }
    if (!is_array($document) || empty($document['file_id'])) {
        return null;
    }

    return [
        'bot_message_id' => (int) ($message['message_id'] ?? 0),
        'bot_file_id' => (string) $document['file_id'],
        'bot_file_unique_id' => (string) ($document['file_unique_id'] ?? ''),
        'bot_file_name' => (string) ($document['file_name'] ?? ''),
    ];
}

function captureBotStoredReference(string $title, string $quality, string $fileName, string|int $ownerChatId): array {
    $offset = readBotOffset();
    $best = [];
    $normalizedTitle = normalizeSearchText($title);
    $normalizedFile = normalizeSearchText($fileName);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $longPollSeconds = $attempt >= 5 ? 1 : 0;
        $result = botApi('getUpdates', [
            'offset' => $offset,
            'limit' => 50,
            'timeout' => $longPollSeconds,
            'allowed_updates' => ['message'],
        ], $longPollSeconds + 4);

        if (empty($result['ok'])) {
            return $best;
        }

        $updates = (array) ($result['result'] ?? []);
        foreach ($updates as $update) {
            if (isset($update['update_id'])) {
                $offset = max($offset, ((int) $update['update_id']) + 1);
            }

            $message = (array) ($update['message'] ?? []);
            if ((string) ($message['chat']['id'] ?? '') !== (string) $ownerChatId) {
                continue;
            }

            $meta = botDocumentMeta($message);
            if (!$meta || empty($meta['bot_message_id'])) {
                continue;
            }

            $haystack = normalizeSearchText(($meta['bot_file_name'] ?? '') . ' ' . (string) ($message['caption'] ?? ''));
            $matchesFile = $normalizedFile !== '' && ($haystack === $normalizedFile || str_contains($haystack, $normalizedFile) || str_contains($normalizedFile, $haystack));
            $matchesTitle = $normalizedTitle !== '' && str_contains($haystack, $normalizedTitle);

            if ($matchesFile || $matchesTitle || !$best) {
                $best = $meta;
            }
            if ($matchesFile || $matchesTitle) {
                writeBotOffset($offset);
                return $best;
            }
        }

        writeBotOffset($offset);
        usleep($attempt < 5 ? 200000 : 400000);
    }

    return $best;
}

function telegramHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function catalogEntryId(string $key): string {
    return substr(hash('sha256', $key), 0, 16);
}

function catalogEntryLink(string $key): string {
    global $BOT_USERNAME;
    return 'https://t.me/' . ltrim($BOT_USERNAME, '@') . '?start=movie_' . catalogEntryId($key);
}

function sortedCatalogEntries(): array {
    $catalog = readCatalog();
    uasort($catalog, fn($left, $right) => strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? '')));
    return $catalog;
}

function catalogEntryById(string $id): ?array {
    foreach (readCatalog() as $key => $entry) {
        if (catalogEntryId((string) $key) === $id) {
            return ['key' => (string) $key, 'entry' => $entry];
        }
    }

    return null;
}

function entryHasDeliverableItems(array $entry): bool {
    foreach (($entry['items'] ?? []) as $item) {
        if (!empty($item['bot_file_id']) || !empty($item['bot_message_id']) || !empty($item['message_id']) || !empty($item['source_url'])) {
            return true;
        }
    }

    return false;
}

function entryHasBotDeliverable(array $entry): bool {
    foreach (($entry['items'] ?? []) as $item) {
        if (!empty($item['bot_file_id']) || !empty($item['bot_message_id'])) {
            return true;
        }
    }

    return false;
}

function pruneCatalogToSingleItems(): void {
    $catalog = readCatalog();
    $changed = false;

    foreach ($catalog as $key => $entry) {
        $items = array_values((array) ($entry['items'] ?? []));
        if (count($items) <= 1) {
            continue;
        }

        $catalog[$key]['items'] = [$items[0]];
        $catalog[$key]['qualities'] = [((string) ($items[0]['quality'] ?? 'unknown'))];
        $changed = true;
    }

    if ($changed) {
        writeCatalog($catalog);
    }
}

function catalogStatus(): array {
    pruneCatalogToSingleItems();
    $catalog = readCatalog();
    $movieCount = 0;
    $fileCount = 0;

    foreach ($catalog as $entry) {
        if (entryHasDeliverableItems((array) $entry)) {
            $movieCount++;
            $fileCount += count((array) ($entry['items'] ?? []));
        }
    }

    return [
        'success' => true,
        'movies' => $movieCount,
        'files' => $fileCount,
    ];
}

function firstIntByKeys(array $data, array $keys): ?int {
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            return (int) $data[$key];
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $found = firstIntByKeys($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function firstStringByKeys(array $data, array $keys): string {
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
            return (string) $data[$key];
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $found = firstStringByKeys($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function publicPeerMention(array $info, string|int $fallback): string {
    $username = firstStringByKeys($info, ['username']);
    if ($username !== '') {
        return '@' . ltrim($username, '@');
    }

    $title = firstStringByKeys($info, ['title', 'first_name']);
    return $title !== '' ? $title : (string) $fallback;
}

function statusOverview(): array {
    global $BOT_USERNAME, $INDEX_CHANNEL_ID;

    $catalog = catalogStatus();
    $rawCatalog = readCatalog();
    $deliverableEntries = 0;
    foreach ($rawCatalog as $entry) {
        if (entryHasDeliverableItems((array) $entry)) {
            $deliverableEntries++;
        }
    }

    $overview = [
        'success' => true,
        'bot_username' => '@' . ltrim($BOT_USERNAME, '@'),
        'bot_followers' => null,
        'bot_followers_note' => 'Telegram does not expose bot follower counts to this app.',
        'index_channel_id' => (string) $INDEX_CHANNEL_ID,
        'index_channel' => (string) $INDEX_CHANNEL_ID,
        'index_subscribers' => null,
        'movies' => (int) ($catalog['movies'] ?? 0),
        'files' => (int) ($catalog['files'] ?? 0),
        'catalog_entries' => count($rawCatalog),
        'deliverable_entries' => $deliverableEntries,
        'index_batches' => count(indexBatchGroups(10)),
    ];

    try {
        $bot = botApi('getMe');
        if (!empty($bot['ok'])) {
            $result = (array) ($bot['result'] ?? []);
            $overview['bot_name'] = (string) ($result['first_name'] ?? '');
            $overview['bot_username'] = '@' . ltrim((string) ($result['username'] ?? $BOT_USERNAME), '@');
        }
    } catch (Throwable $botError) {
        $overview['bot_error'] = $botError->getMessage();
    }

    try {
        $madeline = getMadeline();
        if ($madeline->getAuthorization() === API::LOGGED_IN) {
            $info = (array) $madeline->getInfo($INDEX_CHANNEL_ID);
            $overview['index_channel'] = publicPeerMention($info, $INDEX_CHANNEL_ID);
            try {
                $full = (array) $madeline->getFullInfo($INDEX_CHANNEL_ID);
                $overview['index_subscribers'] = firstIntByKeys($full, ['participants_count', 'subscribers', 'members_count']);
                $overview['index_channel'] = publicPeerMention($full + $info, $INDEX_CHANNEL_ID);
            } catch (Throwable $fullError) {
                $overview['index_subscribers'] = firstIntByKeys($info, ['participants_count', 'subscribers', 'members_count']);
            }
        }
    } catch (Throwable $channelError) {
        $overview['index_error'] = $channelError->getMessage();
    }

    return $overview;
}

function memberGrowthTarget(string $target = ''): string {
    global $INDEX_CHANNEL_ID;

    $target = trim($target);
    if ($target === '') {
        return (string) $INDEX_CHANNEL_ID;
    }

    return normalizeTelegramSourceTarget($target);
}

function memberGrowthPeerInput(string $target): string {
    return str_starts_with($target, '@') ? ltrim($target, '@') : $target;
}

function memberGrowthStatus(string $target = ''): array {
    $target = memberGrowthTarget($target);
    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    try {
        $peerInput = memberGrowthPeerInput($target);
        $info = (array) $madeline->getInfo($peerInput);
        $full = (array) $madeline->getFullInfo($peerInput);
        $members = firstIntByKeys($full, ['participants_count', 'subscribers', 'members_count']);
        if ($members === null) {
            $members = firstIntByKeys($info, ['participants_count', 'subscribers', 'members_count']);
        }

        return [
            'success' => true,
            'target' => $target,
            'title' => publicPeerMention($full + $info, $target),
            'username' => publicPeerMention($info, $target),
            'members' => $members,
            'uses_bot' => false,
            'mode' => 'client_session',
        ];
    } catch (Throwable $error) {
        return ['error' => 'Could not read public channel/group info: ' . $error->getMessage()];
    }
}

function createMemberInviteLink(string $target = '', string $name = '', int $memberLimit = 0, int $expireHours = 0, bool $joinRequest = false): array {
    $target = memberGrowthTarget($target);
    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $name = trim($name);
    if ($name === '') {
        $name = 'Growth ' . date('Y-m-d H:i');
    }
    $name = mb_substr($name, 0, 32);

    $params = ['peer' => memberGrowthPeerInput($target), 'title' => $name];
    if ($expireHours > 0) {
        $params['expire_date'] = time() + (min(720, $expireHours) * 3600);
    }
    if ($joinRequest) {
        $params['request_needed'] = true;
    } elseif ($memberLimit > 0) {
        $params['usage_limit'] = max(1, min(99999, $memberLimit));
    }

    try {
        $result = $madeline->messages->exportChatInvite(...$params);
        $invite = (array) ($result['invite'] ?? $result['new_invite'] ?? $result);
        return [
            'success' => true,
            'target' => $target,
            'name' => $name,
            'invite_link' => (string) ($invite['link'] ?? ''),
            'creates_join_request' => !empty($invite['request_needed']),
            'expire_date' => $invite['expire_date'] ?? null,
            'member_limit' => $invite['usage_limit'] ?? null,
            'uses_bot' => false,
            'mode' => 'client_session',
        ];
    } catch (RPCErrorException $error) {
        if (in_array($error->rpc, ['CHAT_ADMIN_REQUIRED', 'USER_NOT_PARTICIPANT'], true)) {
            return ['error' => 'The logged-in Telegram account must be an admin/member with invite-link permission in that target.'];
        }
        return ['error' => $error->getMessage()];
    } catch (Throwable $error) {
        return ['error' => 'Could not create invite link with the logged-in Telegram account: ' . $error->getMessage()];
    }
}

function cleanTitleFromFilename(string $fileName): string {
    $title = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $fileName) ?? $fileName;
    $title = preg_replace('/\b(2160p|1080p|720p|480p|360p|240p|4k|uhd|hevc|x265|x264|bluray|web-?dl|hdrip|dvdrip)\b/i', ' ', $title) ?? $title;
    $title = preg_replace('/\b\d+(?:\.\d+)?\s*(?:gb|mb|kb)\b/i', ' ', $title) ?? $title;
    $title = preg_replace('/[._\[\](){}-]+/', ' ', $title) ?? $title;
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    return trim($title);
}

function storedMessageTitle(array $message): string {
    $text = messageText($message);
    if ($text !== '') {
        return trim(strtok($text, "\r\n") ?: $text);
    }

    $fileName = messageFileName($message);
    return $fileName !== '' ? cleanTitleFromFilename($fileName) : '';
}

function addCatalogItem(array &$catalog, string $title, string $quality, string $method, array $meta = []): bool {
    $key = catalogKey($title);
    if ($key === '') {
        return false;
    }

    $catalog[$key] ??= [
        'title' => $title,
        'qualities' => [],
        'items' => [],
    ];

    $catalog[$key]['title'] = $title;
    $catalog[$key]['qualities'] = array_values(array_unique((array) ($catalog[$key]['qualities'] ?? [])));

    if (count((array) ($catalog[$key]['items'] ?? [])) >= 1 || in_array($quality, $catalog[$key]['qualities'], true)) {
        return false;
    }

    $catalog[$key]['qualities'][] = $quality;
    $item = [
        'quality' => $quality,
        'method' => $method,
        'stored_at' => date('c'),
    ];

    foreach (['message_id', 'source_chat_id', 'source_url', 'file_name'] as $field) {
        if (isset($meta[$field]) && $meta[$field] !== '') {
            $item[$field] = $meta[$field];
        }
    }

    $catalog[$key]['items'][] = $item;
    return true;
}

function rebuildCatalogFromStorageBot(int $maxMessages = 800): array {
    global $BOT_USERNAME;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $peer = $madeline->getInfo($BOT_USERNAME, API::INFO_TYPE_PEER);
    $sourceChatId = ownerBotChatId($madeline);
    $catalog = readCatalog();
    $checked = 0;
    $imported = 0;
    $offsetId = 0;

    while ($checked < $maxMessages) {
        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => min(100, $maxMessages - $checked),
            'offset_id' => $offsetId,
        ]);

        $messages = (array) ($history['messages'] ?? []);
        if (!$messages) {
            break;
        }

        $oldestId = null;
        foreach ($messages as $message) {
            $messageId = (int) ($message['id'] ?? 0);
            if ($messageId > 0) {
                $oldestId = $oldestId === null ? $messageId : min($oldestId, $messageId);
            }

            $checked++;
            if (!messageHasDocument($message)) {
                continue;
            }

            $title = storedMessageTitle($message);
            if ($title === '') {
                continue;
            }

            $quality = extractQuality(messageFileName($message) . ' ' . messageText($message));
            if (addCatalogItem($catalog, $title, $quality, 'rebuild_scan', [
                'message_id' => $messageId,
                'source_chat_id' => $sourceChatId,
                'file_name' => messageFileName($message),
            ])) {
                $imported++;
            }
        }

        if ($oldestId === null || $oldestId <= 1) {
            break;
        }
        $offsetId = $oldestId;
        usleep(250000);
    }

    writeCatalog($catalog);
    $status = catalogStatus();
    return [
        'success' => true,
        'checked' => $checked,
        'imported' => $imported,
        'movies' => $status['movies'],
        'files' => $status['files'],
    ];
}

function repairCatalogBotReferences(int $maxItems = 50): array {
    global $BOT_USERNAME;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $storagePeer = $madeline->getInfo($BOT_USERNAME, API::INFO_TYPE_PEER);
    $ownerChatId = ownerBotChatId($madeline);
    $catalog = readCatalog();
    $checked = 0;
    $repaired = 0;
    $failed = 0;

    foreach ($catalog as $key => $entry) {
        foreach ((array) ($entry['items'] ?? []) as $index => $item) {
            if ($checked >= $maxItems) {
                break 2;
            }
            if (!empty($item['bot_file_id']) || !empty($item['bot_message_id'])) {
                continue;
            }

            $checked++;
            $messageId = (int) ($item['message_id'] ?? 0);
            if ($messageId <= 0) {
                $failed++;
                continue;
            }

            try {
                $madeline->messages->forwardMessages([
                    'silent' => true,
                    'drop_author' => true,
                    'drop_media_captions' => true,
                    'from_peer' => $storagePeer,
                    'id' => [$messageId],
                    'to_peer' => $BOT_USERNAME,
                ]);

                $botMeta = captureBotStoredReference(
                    (string) ($entry['title'] ?? ''),
                    (string) ($item['quality'] ?? 'unknown'),
                    (string) ($item['file_name'] ?? ''),
                    $ownerChatId
                );

                if (!empty($botMeta['bot_file_id']) || !empty($botMeta['bot_message_id'])) {
                    $catalog[$key]['items'][$index] = array_merge($item, $botMeta, [
                        'repaired_at' => date('c'),
                    ]);
                    $repaired++;
                } else {
                    $failed++;
                }
            } catch (Throwable $repairError) {
                $failed++;
            }

            usleep(700000);
        }
    }

    writeCatalog($catalog);
    return [
        'success' => true,
        'checked' => $checked,
        'repaired' => $repaired,
        'failed' => $failed,
    ];
}

function clearIndexChannel(int $maxMessages = 2000, bool $dryRun = false): array {
    global $INDEX_CHANNEL_ID;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return ['error' => 'Please login first'];
    }

    $peer = $madeline->getInfo($INDEX_CHANNEL_ID, API::INFO_TYPE_PEER);
    $deleted = 0;
    $offsetId = 0;

    while ($deleted < $maxMessages) {
        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => min(100, $maxMessages - $deleted),
            'offset_id' => $offsetId,
        ]);
        $messages = (array) ($history['messages'] ?? []);
        if (!$messages) {
            break;
        }

        $ids = [];
        $oldestId = null;
        foreach ($messages as $message) {
            $id = (int) ($message['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $oldestId = $oldestId === null ? $id : min($oldestId, $id);
        }

        if (!$ids) {
            break;
        }

        if (!$dryRun) {
            try {
                $madeline->channels->deleteMessages([
                    'channel' => $peer,
                    'id' => $ids,
                ]);
            } catch (Throwable $channelDeleteError) {
                $madeline->messages->deleteMessages([
                    'revoke' => true,
                    'id' => $ids,
                ]);
            }
        }

        $deleted += count($ids);
        if ($oldestId === null || $oldestId <= 1) {
            break;
        }
        $offsetId = $oldestId;
        usleep(300000);
    }

    if (!$dryRun) {
        clearIndexPostState();
    }

    return ['success' => true, 'deleted' => $dryRun ? 0 : $deleted, 'found' => $deleted, 'dry_run' => $dryRun, 'checkpoint_cleared' => !$dryRun];
}

function indexLetterForTitle(string $title): string {
    $title = trim($title);
    if ($title === '') {
        return '#';
    }

    $first = strtoupper(substr($title, 0, 1));
    return preg_match('/[A-Z]/', $first) ? $first : '#';
}

function indexBatchGroups(int $batchSize = 10): array {
    $batchSize = max(1, min(30, $batchSize));
    $letters = [];

    foreach (sortedCatalogEntries() as $key => $entry) {
        if (!entryHasDeliverableItems((array) $entry)) {
            continue;
        }

        $title = trim((string) ($entry['title'] ?? 'Untitled'));
        $letter = indexLetterForTitle($title);
        $letters[$letter] ??= [];
        $letters[$letter][] = [
            'key' => (string) $key,
            'title' => $title !== '' ? $title : 'Untitled',
            'entry' => (array) $entry,
        ];
    }

    $orderedLetters = array_merge(range('A', 'Z'), ['#']);
    $groups = [];
    foreach ($orderedLetters as $letter) {
        if (empty($letters[$letter])) {
            continue;
        }

        $chunks = array_chunk($letters[$letter], $batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            $groups[] = [
                'letter' => $letter,
                'batch' => $chunkIndex + 1,
                'total_for_letter' => count($letters[$letter]),
                'items' => $chunk,
            ];
        }
    }

    return $groups;
}

function indexGroupsSignature(array $groups): string {
    $keys = [];
    foreach ($groups as $group) {
        foreach ((array) ($group['items'] ?? []) as $item) {
            $keys[] = (string) ($item['key'] ?? '');
        }
    }

    return hash('sha256', implode('|', $keys));
}

function readIndexPostState(): array {
    global $INDEX_STATE_FILE;

    if (!is_file($INDEX_STATE_FILE)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($INDEX_STATE_FILE), true);
    return is_array($data) ? $data : [];
}

function writeIndexPostState(array $state): void {
    global $SESSION_DIR, $INDEX_STATE_FILE;

    if (!is_dir($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0700, true);
    }

    $state['updated_at'] = date('c');
    file_put_contents($INDEX_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function clearIndexPostState(): void {
    global $INDEX_STATE_FILE;

    if (is_file($INDEX_STATE_FILE)) {
        unlink($INDEX_STATE_FILE);
    }
}

function indexStateMatches(array $state, int $batchSize, string $signature, int $totalBatches): bool {
    return (int) ($state['batch_size'] ?? 0) === $batchSize
        && (string) ($state['signature'] ?? '') === $signature
        && (int) ($state['total_batches'] ?? 0) === $totalBatches;
}

function indexPostedBatchNumbersFromState(int $batchSize, string $signature, int $totalBatches): array {
    $state = readIndexPostState();
    if (!indexStateMatches($state, $batchSize, $signature, $totalBatches)) {
        return [];
    }

    $posted = [];
    foreach ((array) ($state['posted_batches'] ?? []) as $batchNumber) {
        $batchNumber = (int) $batchNumber;
        if ($batchNumber > 0 && $batchNumber <= $totalBatches) {
            $posted[$batchNumber] = true;
        }
    }

    return array_keys($posted);
}

function indexPostedBatchNumbersFromChannel(int $totalBatches, int $scanLimit = 700): array {
    global $INDEX_CHANNEL_ID;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        return [];
    }

    $peer = $madeline->getInfo($INDEX_CHANNEL_ID, API::INFO_TYPE_PEER);
    $offsetId = 0;
    $checked = 0;
    $posted = [];

    while ($checked < $scanLimit) {
        $history = $madeline->messages->getHistory([
            'peer' => $peer,
            'limit' => min(100, $scanLimit - $checked),
            'offset_id' => $offsetId,
        ]);

        $messages = (array) ($history['messages'] ?? []);
        if (!$messages) {
            break;
        }

        $oldestId = null;
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $checked++;
            $id = (int) ($message['id'] ?? 0);
            if ($id > 0) {
                $oldestId = $oldestId === null ? $id : min($oldestId, $id);
            }

            $text = messageText($message);
            if (preg_match('/Index\s+batch\s+(\d+)\s+of\s+(\d+)/i', $text, $match)) {
                $batchNumber = (int) $match[1];
                $batchTotal = (int) $match[2];
                if ($batchNumber > 0 && $batchNumber <= $totalBatches && ($batchTotal === $totalBatches || $batchTotal === 0)) {
                    $posted[$batchNumber] = true;
                }
            }
        }

        if ($oldestId === null || $oldestId <= 1 || count($messages) < 100) {
            break;
        }
        $offsetId = $oldestId;
    }

    ksort($posted);
    return array_keys($posted);
}

function mergedPostedIndexBatches(int $batchSize, string $signature, int $totalBatches): array {
    $posted = [];
    foreach (indexPostedBatchNumbersFromState($batchSize, $signature, $totalBatches) as $batchNumber) {
        $posted[(int) $batchNumber] = true;
    }
    try {
        foreach (indexPostedBatchNumbersFromChannel($totalBatches) as $batchNumber) {
            $posted[(int) $batchNumber] = true;
        }
    } catch (Throwable $error) {
        // Resume still works from local state if channel history cannot be scanned.
    }

    ksort($posted);
    return array_keys($posted);
}

function indexMovieCountForBatchNumbers(array $groups, array $batchNumbers): int {
    $lookup = array_fill_keys(array_map('intval', $batchNumbers), true);
    $movies = 0;
    foreach ($groups as $index => $group) {
        $batchNumber = $index + 1;
        if (isset($lookup[$batchNumber])) {
            $movies += count((array) ($group['items'] ?? []));
        }
    }

    return $movies;
}

function saveIndexPostedBatch(int $batchSize, string $signature, int $totalBatches, int $batchNumber, int $moviesPosted, string $mainChannel = ''): void {
    $state = readIndexPostState();
    if (!indexStateMatches($state, $batchSize, $signature, $totalBatches)) {
        $state = [
            'batch_size' => $batchSize,
            'signature' => $signature,
            'total_batches' => $totalBatches,
            'posted_batches' => [],
            'movies_posted' => 0,
            'main_channel' => $mainChannel,
            'status' => 'running',
        ];
    }

    $posted = [];
    foreach ((array) ($state['posted_batches'] ?? []) as $postedBatch) {
        $posted[(int) $postedBatch] = true;
    }
    $posted[$batchNumber] = true;
    ksort($posted);

    $state['posted_batches'] = array_keys($posted);
    $state['movies_posted'] = $moviesPosted;
    $state['status'] = count($posted) >= $totalBatches ? 'complete' : 'paused';
    $state['main_channel'] = $mainChannel;
    writeIndexPostState($state);
}

function botRetryAfter(array $result): int {
    $retry = (int) ($result['parameters']['retry_after'] ?? 0);
    if ($retry > 0) {
        return $retry;
    }

    $description = (string) ($result['description'] ?? '');
    if (preg_match('/retry\s+after\s+(\d+)/i', $description, $match)) {
        return (int) $match[1];
    }

    return 0;
}

function waitForRetryWindow(int $seconds): bool {
    $end = time() + max(1, $seconds);
    while (time() < $end) {
        if (shouldStopScrape()) {
            return false;
        }
        sleep(1);
    }

    return !shouldStopScrape();
}

function normalizeSelectedBatchNumbers(array $selected, int $totalBatches): array {
    $numbers = [];
    foreach ($selected as $value) {
        $number = (int) $value;
        if ($number > 0 && $number <= $totalBatches) {
            $numbers[$number] = true;
        }
    }

    ksort($numbers);
    return array_keys($numbers);
}

function indexChannelMention(string $mainChannel = ''): string {
    global $INDEX_CHANNEL_ID;

    $mainChannel = normalizeTelegramSourceTarget($mainChannel);
    if ($mainChannel !== '') {
        return $mainChannel;
    }

    try {
        $madeline = getMadeline();
        if ($madeline->getAuthorization() === API::LOGGED_IN) {
            return publicPeerMention((array) $madeline->getInfo($INDEX_CHANNEL_ID), $INDEX_CHANNEL_ID);
        }
    } catch (Throwable $error) {
        // Fall through to the configured numeric channel id.
    }

    return (string) $INDEX_CHANNEL_ID;
}

function indexMessageText(array $group, int $globalBatch, int $totalBatches, string $mainChannel = ''): string {
    global $BOT_USERNAME;

    $letter = (string) ($group['letter'] ?? '#');
    $batch = (int) ($group['batch'] ?? 1);
    $items = (array) ($group['items'] ?? []);
    $mainChannel = indexChannelMention($mainChannel);

    $lines = [
        '<b>Movies ' . telegramHtml($letter === '#' ? 'Other' : $letter) . ' - Batch ' . $batch . '</b>',
        'Index batch ' . $globalBatch . ' of ' . $totalBatches,
        '',
    ];

    foreach ($items as $index => $row) {
        $number = $index + 1;
        $title = (string) ($row['title'] ?? 'Untitled');
        $link = catalogEntryLink((string) ($row['key'] ?? ''));
        $lines[] = $number . '. <a href="' . telegramHtml($link) . '">' . telegramHtml($title) . '</a>';
    }

    $lines[] = '';
    $lines[] = 'Tap a title or button below to get files via bot.';
    $lines[] = 'Main channel: ' . telegramHtml($mainChannel);
    $lines[] = 'Bot: @' . telegramHtml(ltrim($BOT_USERNAME, '@'));

    return implode("\n", $lines);
}

function indexReplyMarkup(array $group): array {
    global $BOT_USERNAME;

    $rows = [];
    $buttonRow = [];
    foreach ((array) ($group['items'] ?? []) as $index => $item) {
        $buttonRow[] = [
            'text' => (string) ($index + 1),
            'url' => catalogEntryLink((string) ($item['key'] ?? '')),
        ];
        if (count($buttonRow) === 5) {
            $rows[] = $buttonRow;
            $buttonRow = [];
        }
    }
    if ($buttonRow) {
        $rows[] = $buttonRow;
    }

    $rows[] = [[
        'text' => 'Get Files Via Bot',
        'url' => 'https://t.me/' . ltrim($BOT_USERNAME, '@'),
    ]];

    return ['inline_keyboard' => $rows];
}

function sendIndexBatch(array $group, int $globalBatch, int $totalBatches, string $mainChannel = ''): array {
    global $INDEX_CHANNEL_ID;

    return botApi('sendMessage', [
        'chat_id' => $INDEX_CHANNEL_ID,
        'text' => indexMessageText($group, $globalBatch, $totalBatches, $mainChannel),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => indexReplyMarkup($group),
    ]);
}

function sendIndexBatchWithRetry(array $group, int $globalBatch, int $totalBatches, string $mainChannel = '', bool $stream = false): array {
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $result = sendIndexBatch($group, $globalBatch, $totalBatches, $mainChannel);
        if (!empty($result['ok'])) {
            return $result;
        }

        $retry = botRetryAfter($result);
        if ($retry <= 0) {
            return $result;
        }

        if ($stream) {
            streamJsonLine([
                'status' => 'rate_limited',
                'title' => 'Telegram limit',
                'detail' => 'retry after ' . $retry . ' seconds',
                'retry_after' => $retry,
                'batch' => $globalBatch,
                'total_batches' => $totalBatches,
            ]);
        }

        if (!waitForRetryWindow($retry + 2)) {
            return ['ok' => false, 'stopped' => true, 'description' => 'Paused during retry wait'];
        }
    }

    return ['ok' => false, 'description' => 'Retry limit reached'];
}

function indexPreview(int $batchSize = 10): array {
    $groups = indexBatchGroups($batchSize);
    $signature = indexGroupsSignature($groups);
    $postedNumbers = mergedPostedIndexBatches(max(1, min(30, $batchSize)), $signature, count($groups));
    $postedMovies = indexMovieCountForBatchNumbers($groups, $postedNumbers);
    $movies = 0;
    $preview = [];
    foreach ($groups as $index => $group) {
        $items = (array) ($group['items'] ?? []);
        $movies += count($items);
        $batchNumber = $index + 1;
        $preview[] = [
            'letter' => $group['letter'],
            'batch' => $group['batch'],
            'global_batch' => $batchNumber,
            'posted' => in_array($batchNumber, $postedNumbers, true),
            'count' => count($items),
            'titles' => array_map(fn($item) => (string) ($item['title'] ?? 'Untitled'), array_slice($items, 0, 10)),
        ];
    }

    return [
        'success' => true,
        'batch_size' => max(1, min(30, $batchSize)),
        'batches' => count($groups),
        'posted_batches' => count($postedNumbers),
        'posted_movies' => $postedMovies,
        'next_batch' => count($postedNumbers) + 1,
        'movies' => $movies,
        'groups' => $preview,
        'main_channel' => indexChannelMention(),
    ];
}

function postIndexBatches(int $batchSize = 10): array {
    $groups = indexBatchGroups($batchSize);
    if (!$groups) {
        return ['error' => 'No stored movies found in catalog yet. Rebuild or scrape first.'];
    }

    $posted = 0;
    $movies = 0;
    $total = count($groups);
    foreach ($groups as $index => $group) {
        $result = sendIndexBatchWithRetry($group, $index + 1, $total);
        if (empty($result['ok'])) {
            return ['error' => $result['description'] ?? 'Failed to post index batch'];
        }

        $posted++;
        $movies += count((array) ($group['items'] ?? []));
        usleep(180000);
    }

    return ['success' => true, 'posted_batches' => $posted, 'movies' => $movies];
}

function postIndexBatchesStream(
    int $batchSize = 10,
    string $mainChannel = '',
    string $mode = 'resume',
    array $selectedBatches = [],
    int $cooldownEvery = 8,
    int $cooldownSeconds = 45
): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/x-ndjson');
    set_time_limit(0);
    ignore_user_abort(false);
    clearStopScrape();

    $batchSize = max(1, min(30, $batchSize));
    $mode = strtolower(trim($mode)) === 'fresh' ? 'fresh' : 'resume';
    $groups = indexBatchGroups($batchSize);
    if (!$groups) {
        streamJsonLine(['error' => 'No stored movies found in catalog yet. Rebuild or scrape first.']);
        streamJsonLine(['status' => 'complete', 'posted_batches' => 0, 'movies' => 0]);
        exit;
    }

    $total = count($groups);
    $signature = indexGroupsSignature($groups);
    $selectedNumbers = normalizeSelectedBatchNumbers($selectedBatches, $total);
    if (!$selectedNumbers) {
        $selectedNumbers = range(1, $total);
    }
    $selectedLookup = array_fill_keys($selectedNumbers, true);
    if ($mode === 'fresh') {
        clearIndexPostState();
        $postedNumbers = [];
    } else {
        $postedNumbers = mergedPostedIndexBatches($batchSize, $signature, $total);
    }
    $postedLookup = array_fill_keys(array_map('intval', $postedNumbers), true);
    $runAlreadyPosted = array_values(array_filter($selectedNumbers, fn($batchNumber) => isset($postedLookup[$batchNumber])));
    $posted = count($runAlreadyPosted);
    $movies = indexMovieCountForBatchNumbers($groups, $runAlreadyPosted);
    $runTotal = count($selectedNumbers);
    $cooldownEvery = max(0, min(50, $cooldownEvery));
    $cooldownSeconds = max(0, min(600, $cooldownSeconds));

    writeIndexPostState([
        'batch_size' => $batchSize,
        'signature' => $signature,
        'total_batches' => $total,
        'posted_batches' => array_keys($postedLookup),
        'movies_posted' => $movies,
        'main_channel' => $mainChannel,
        'status' => $posted >= $runTotal ? 'complete' : 'running',
    ]);

    streamJsonLine([
        'status' => 'index_resume',
        'posted_batches' => $posted,
        'total_batches' => $runTotal,
        'global_total_batches' => $total,
        'movies' => $movies,
        'mode' => $mode,
    ]);

    $runPosted = $posted;
    foreach ($groups as $index => $group) {
        $batchNumber = $index + 1;
        if (!isset($selectedLookup[$batchNumber])) {
            continue;
        }
        if (isset($postedLookup[$batchNumber])) {
            continue;
        }

        if (shouldStopScrape()) {
            streamJsonLine(['status' => 'stopped', 'posted_batches' => $runPosted, 'total_batches' => $runTotal, 'global_total_batches' => $total]);
            clearStopScrape();
            exit;
        }

        $count = count((array) ($group['items'] ?? []));
        streamJsonLine([
            'status' => 'index_batch',
            'title' => 'Movies ' . (($group['letter'] ?? '#') === '#' ? 'Other' : (string) $group['letter']),
            'batch' => $runPosted + 1,
            'total_batches' => $runTotal,
            'global_batch' => $batchNumber,
            'global_total_batches' => $total,
            'movies' => $count,
        ]);

        $result = sendIndexBatchWithRetry($group, $batchNumber, $total, $mainChannel, true);
        if (!empty($result['stopped'])) {
            writeIndexPostState([
                'batch_size' => $batchSize,
                'signature' => $signature,
                'total_batches' => $total,
                'posted_batches' => array_keys($postedLookup),
                'movies_posted' => $movies,
                'main_channel' => $mainChannel,
                'status' => 'paused',
            ]);
            streamJsonLine(['status' => 'stopped', 'posted_batches' => $runPosted, 'total_batches' => $runTotal, 'global_total_batches' => $total, 'movies' => $movies]);
            clearStopScrape();
            exit;
        }
        if (empty($result['ok'])) {
            streamJsonLine(['error' => $result['description'] ?? 'Failed to post index batch']);
            clearStopScrape();
            exit;
        }

        $runPosted++;
        $movies += $count;
        $postedLookup[$batchNumber] = true;
        saveIndexPostedBatch($batchSize, $signature, $total, $batchNumber, indexMovieCountForBatchNumbers($groups, array_keys($postedLookup)), $mainChannel);
        streamJsonLine([
            'status' => 'index_posted',
            'title' => 'Movies ' . (($group['letter'] ?? '#') === '#' ? 'Other' : (string) $group['letter']),
            'posted_batches' => $runPosted,
            'total_batches' => $runTotal,
            'global_batch' => $batchNumber,
            'global_total_batches' => $total,
            'movies' => $movies,
        ]);

        if ($cooldownEvery > 0 && $cooldownSeconds > 0 && $runPosted < $runTotal && $runPosted % $cooldownEvery === 0) {
            streamJsonLine([
                'status' => 'cooldown',
                'detail' => 'resting after ' . $runPosted . ' selected batches',
                'cooldown_seconds' => $cooldownSeconds,
                'posted_batches' => $runPosted,
                'total_batches' => $runTotal,
            ]);
            if (!waitForRetryWindow($cooldownSeconds)) {
                streamJsonLine(['status' => 'stopped', 'posted_batches' => $runPosted, 'total_batches' => $runTotal, 'global_total_batches' => $total, 'movies' => $movies]);
                clearStopScrape();
                exit;
            }
        }

        usleep(1500000);
    }

    clearStopScrape();
    writeIndexPostState([
        'batch_size' => $batchSize,
        'signature' => $signature,
        'total_batches' => $total,
        'posted_batches' => array_keys($postedLookup),
        'movies_posted' => indexMovieCountForBatchNumbers($groups, array_keys($postedLookup)),
        'main_channel' => $mainChannel,
        'status' => 'complete',
    ]);
    streamJsonLine(['status' => 'complete', 'posted_batches' => $runPosted, 'total_batches' => $runTotal, 'global_total_batches' => $total, 'movies' => $movies]);
    exit;
}

function searchCatalog(string $query, int $limit = 10): array {
    $needle = normalizeSearchText($query);
    if ($needle === '') {
        return [];
    }

    $matches = [];
    foreach (sortedCatalogEntries() as $key => $entry) {
        if (!entryHasDeliverableItems((array) $entry)) {
            continue;
        }

        $title = (string) ($entry['title'] ?? '');
        $haystack = normalizeSearchText($title);
        if ($haystack === $needle || str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
            $matches[] = ['key' => (string) $key, 'entry' => (array) $entry];
        }

        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function sendSearchResults(string|int $chatId, string $query): void {
    $matches = searchCatalog($query);
    if (!$matches) {
        botApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => "No movies found for: $query",
        ]);
        return;
    }

    $keyboard = [];
    foreach ($matches as $index => $row) {
        $keyboard[] = [[
            'text' => ($index + 1) . '. ' . (string) ($row['entry']['title'] ?? 'Untitled'),
            'callback_data' => 'movie:' . catalogEntryId($row['key']),
        ]];
    }

    botApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Found ' . count($matches) . " result(s) for: $query",
        'reply_markup' => ['inline_keyboard' => $keyboard],
    ]);
}

function deliverMovieToChat(string|int $chatId, string $movieId): void {
    $row = catalogEntryById($movieId);
    if (!$row) {
        botApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Movie is not available anymore.',
        ]);
        return;
    }

    $title = (string) ($row['entry']['title'] ?? 'Movie');
    $sent = 0;
    $items = array_values((array) ($row['entry']['items'] ?? []));
    foreach (array_slice($items, 0, 1) as $item) {
        $quality = (string) ($item['quality'] ?? 'file');
        if (!empty($item['bot_file_id'])) {
            $result = botApi('sendDocument', [
                'chat_id' => $chatId,
                'document' => $item['bot_file_id'],
                'caption' => "$title ($quality)",
            ]);
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
                'caption' => "$title ($quality)",
            ]);
            if (!empty($result['ok'])) {
                $sent++;
                continue;
            }
        }

        if (!empty($item['source_url'])) {
            botApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => "$title ($quality): " . $item['source_url'],
            ]);
            $sent++;
        }
    }

    if ($sent === 0) {
        botApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => "$title is listed, but no copyable file reference was saved. Re-scrape it once.",
        ]);
    }
}

function handleBotWebhook(array $update): array {
    if (isset($update['callback_query'])) {
        $callback = (array) $update['callback_query'];
        $data = (string) ($callback['data'] ?? '');
        $chatId = $callback['message']['chat']['id'] ?? null;
        if (isset($callback['id'])) {
            botApi('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
        if ($chatId !== null && str_starts_with($data, 'movie:')) {
            deliverMovieToChat($chatId, substr($data, 6));
        }
        return ['ok' => true];
    }

    $message = (array) ($update['message'] ?? []);
    $chatId = $message['chat']['id'] ?? null;
    $text = trim((string) ($message['text'] ?? ''));
    if ($chatId === null || $text === '') {
        return ['ok' => true];
    }

    if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/i', $text, $matches)) {
        $payload = trim((string) ($matches[1] ?? ''));
        if (str_starts_with($payload, 'movie_')) {
            deliverMovieToChat($chatId, substr($payload, 6));
        } else {
            botApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Send /search movie name',
            ]);
        }
        return ['ok' => true];
    }

    if (preg_match('/^\/search(?:@\w+)?\s*(.*)$/i', $text, $matches)) {
        $query = trim((string) ($matches[1] ?? ''));
        if ($query === '') {
            botApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Use: /search movie name',
            ]);
        } else {
            sendSearchResults($chatId, $query);
        }
        return ['ok' => true];
    }

    if (!str_starts_with($text, '/')) {
        sendSearchResults($chatId, $text);
    }

    return ['ok' => true];
}

function pollBotUpdates(): array {
    global $SESSION_DIR, $BOT_OFFSET_FILE;

    if (!is_dir($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0700, true);
    }

    $offset = is_file($BOT_OFFSET_FILE) ? (int) trim((string) file_get_contents($BOT_OFFSET_FILE)) : 0;
    $result = botApi('getUpdates', [
        'offset' => $offset,
        'limit' => 20,
        'timeout' => 0,
        'allowed_updates' => ['message', 'callback_query'],
    ], 8);

    if (empty($result['ok'])) {
        $description = (string) ($result['description'] ?? '');
        if (stripos($description, 'webhook') !== false || stripos($description, 'conflict') !== false) {
            botApi('deleteWebhook', ['drop_pending_updates' => false], 8);
            return ['success' => true, 'processed' => 0, 'detail' => 'Webhook disabled; polling will continue.'];
        }

        return ['error' => $description ?: 'Bot polling failed'];
    }

    $processed = 0;
    foreach ((array) ($result['result'] ?? []) as $update) {
        if (isset($update['update_id'])) {
            $offset = max($offset, ((int) $update['update_id']) + 1);
        }
        handleBotWebhook((array) $update);
        $processed++;
    }

    file_put_contents($BOT_OFFSET_FILE, (string) $offset);
    return ['success' => true, 'processed' => $processed];
}

// ---------- SCRAPING ENGINE (streaming JSON lines) ----------
function scrapeMovies(string $username, array $titles) {
    global $BOT_TOKEN, $BOT_USERNAME, $INDEX_CHANNEL_ID;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        echo json_encode(['error' => 'Please login first']) . "\n";
        exit;
    }

    $titles = array_values(array_filter(array_map('trim', $titles), fn($title) => $title !== ''));
    if (!$titles) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: application/x-ndjson');
        echo json_encode(['status' => 'complete']) . "\n";
        exit;
    }

    // ---------- FIX: use getInfo() instead of raw API ----------
    $target = ltrim($username, '@');
    if ($target === '') {
        echo json_encode(['error' => 'Bot/channel username is required']) . "\n";
        exit;
    }

    // Optionally check if it’s a bot using getFullInfo()
    $peerInfo = $madeline->getInfo($target);
    $peer = $madeline->getInfo($target, API::INFO_TYPE_PEER);
    $isBot = ($peerInfo['type'] ?? '') === API::PEER_TYPE_BOT || !empty($peerInfo['User']['bot']);

    // If it’s a bot, send /start
    if ($isBot) {
        $madeline->messages->sendMessage([
            'peer' => $peer,
            'message' => '/start'
        ]);
        sleep(2); // give the bot a moment to respond
    }

    // Streaming newline‑delimited JSON
    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/x-ndjson');
    set_time_limit(0);
    ignore_user_abort(true);

    foreach ($titles as $title) {
        $title = trim($title);
        if (empty($title)) continue;

        // Send the movie title
        $madeline->messages->sendMessage([
            'peer' => $peer,
            'message' => $title
        ]);

        // Wait for a document reply (simple polling)
        $startTime = time();
        $found = null;
        do {
            $history = $madeline->messages->getHistory([
                'peer' => $peer,
                'limit' => 5,
                'offset_id' => 0
            ]);
            if (isset($history['messages'])) {
                foreach ($history['messages'] as $msg) {
                    if (($msg['message'] ?? '') === $title) continue; // skip our own
                    if (isset($msg['media']['_']) && $msg['media']['_'] === 'messageMediaDocument') {
                        $found = $msg;
                        break 2;
                    }
                }
            }
            usleep(500000); // 0.5 seconds
        } while (time() - $startTime < 10); // 10 sec timeout

        if ($found) {
            // Forward to storage bot
            $madeline->messages->forwardMessages([
                'from_peer' => $peer,
                'id' => [$found['id']],
                'to_peer' => $BOT_USERNAME
            ]);

            // Send index message to channel via Bot API
            $indexMsg = "🎬 $title - Available on @$BOT_USERNAME";
            @file_get_contents(
                "https://api.telegram.org/bot$BOT_TOKEN/sendMessage?" .
                "chat_id=$INDEX_CHANNEL_ID&text=" . urlencode($indexMsg)
            );

            echo json_encode(['status' => 'found', 'title' => $title]) . "\n";
        } else {
            echo json_encode(['status' => 'not_found', 'title' => $title]) . "\n";
        }
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        sleep(1); // rate limiting
    }
    echo json_encode(['status' => 'complete']) . "\n";
    exit;
}

function scrapeMoviesV2(string $username, array $titles) {
    global $BOT_TOKEN, $BOT_USERNAME, $INDEX_CHANNEL_ID;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        echo json_encode(['error' => 'Please login first']) . "\n";
        exit;
    }

    clearStopScrape();
    $titles = array_values(array_filter(array_map('trim', $titles), fn($title) => $title !== ''));
    if (!$titles) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: application/x-ndjson');
        echo json_encode(['status' => 'complete']) . "\n";
        exit;
    }

    $target = ltrim($username, '@');
    if ($target === '') {
        echo json_encode(['error' => 'Bot/channel username is required']) . "\n";
        exit;
    }

    $peerInfo = $madeline->getInfo($target);
    $peer = $madeline->getInfo($target, API::INFO_TYPE_PEER);
    $isBot = ($peerInfo['type'] ?? '') === API::PEER_TYPE_BOT || !empty($peerInfo['User']['bot']);
    $resetBot = ($_POST['reset_bot'] ?? '') === '1';

    if ($isBot && $resetBot) {
        $madeline->messages->sendMessage(['peer' => $peer, 'message' => '/restart']);
        usleep(1200000);
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/x-ndjson');
    set_time_limit(0);
    ignore_user_abort(false);

    $stopped = false;
    foreach ($titles as $title) {
        if (shouldStopScrape()) {
            $stopped = true;
            break;
        }

        streamJsonLine(['status' => 'searching', 'title' => $title]);
        $madeline->messages->sendMessage(['peer' => $peer, 'message' => $title]);

        $startTime = time();
        $found = null;
        $resultSeen = false;
        $buttonClicked = false;
        $forwardedFromUrl = false;
        $noResults = false;
        $clickedMessages = [];

        do {
            if (shouldStopScrape()) {
                $stopped = true;
                break 2;
            }

            $history = $madeline->messages->getHistory([
                'peer' => $peer,
                'limit' => 12,
                'offset_id' => 0,
            ]);

            foreach (($history['messages'] ?? []) as $msg) {
                if (($msg['message'] ?? '') === $title) {
                    continue;
                }

                if (messageHasDocument($msg)) {
                    $found = $msg;
                    break 2;
                }

                $text = messageText($msg);
                if (messageSaysNoResults($text)) {
                    $noResults = true;
                    break 2;
                }

                $totalResults = messageTotalResults($text);
                $buttons = getCandidateButtons($msg, $title);

                if ($totalResults > 0 || $buttons) {
                    if (!$resultSeen) {
                        $resultSeen = true;
                        streamJsonLine([
                            'status' => 'result_found',
                            'title' => $title,
                            'detail' => $totalResults > 0 ? "Bot says total results: $totalResults" : 'File button found',
                        ]);
                    }

                    $messageId = (int) ($msg['id'] ?? 0);
                    if ($messageId && empty($clickedMessages[$messageId]) && $buttons) {
                        $clickedMessages[$messageId] = true;
                        $click = clickResultButtons($madeline, $peer, $msg, $title, $BOT_USERNAME);

                        if (!empty($click['forwarded_url'])) {
                            $forwardedFromUrl = true;
                            break 2;
                        }

                        if (!empty($click['clicked'])) {
                            $buttonClicked = true;
                            streamJsonLine([
                                'status' => 'opening',
                                'title' => $title,
                                'detail' => 'Clicked: ' . ($click['label'] ?? 'file button'),
                            ]);
                            usleep(800000);
                        }
                    }
                }
            }

            usleep(500000);
        } while (time() - $startTime < ($buttonClicked ? 35 : 25));

        if ($found) {
            $stored = storeMovieMessage($madeline, $peer, $found, $BOT_USERNAME, $title);
        } else {
            $stored = ['stored' => false];
        }

        if ($stored['stored'] || $forwardedFromUrl) {
            $indexMsg = "Movie: $title - Available on @$BOT_USERNAME";
            @file_get_contents(
                "https://api.telegram.org/bot$BOT_TOKEN/sendMessage?" .
                "chat_id=$INDEX_CHANNEL_ID&text=" . urlencode($indexMsg)
            );

            streamJsonLine([
                'status' => 'stored',
                'title' => $title,
                'detail' => $stored['method'] ?? 'linked',
            ]);
        } elseif ($resultSeen) {
            streamJsonLine([
                'status' => 'found_no_file',
                'title' => $title,
                'detail' => 'Result was found, but no file message arrived after opening the button.',
            ]);
        } elseif ($noResults) {
            streamJsonLine(['status' => 'not_found', 'title' => $title]);
        } else {
            streamJsonLine([
                'status' => 'no_response',
                'title' => $title,
                'detail' => 'No final answer arrived before timeout.',
            ]);
        }

        sleep(1);
    }

    if ($stopped) {
        streamJsonLine(['status' => 'stopped']);
    }
    clearStopScrape();
    streamJsonLine(['status' => 'complete']);
    exit;
}

function scrapeMoviesV4(string $username, array $titles) {
    global $BOT_TOKEN, $BOT_USERNAME, $INDEX_CHANNEL_ID;

    $madeline = getMadeline();
    if ($madeline->getAuthorization() !== API::LOGGED_IN) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        echo json_encode(['error' => 'Please login first']) . "\n";
        exit;
    }

    clearStopScrape();
    $titles = array_values(array_filter(array_map('trim', $titles), fn($title) => $title !== ''));
    if (!$titles) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: application/x-ndjson');
        echo json_encode(['status' => 'complete']) . "\n";
        exit;
    }

    $target = ltrim($username, '@');
    if ($target === '') {
        echo json_encode(['error' => 'Bot/channel username is required']) . "\n";
        exit;
    }

    $peerInfo = $madeline->getInfo($target);
    $peer = $madeline->getInfo($target, API::INFO_TYPE_PEER);
    $isBot = ($peerInfo['type'] ?? '') === API::PEER_TYPE_BOT || !empty($peerInfo['User']['bot']);
    $resetBot = ($_POST['reset_bot'] ?? '') === '1';

    if ($isBot && $resetBot) {
        $madeline->messages->sendMessage(['peer' => $peer, 'message' => '/restart']);
        usleep(1200000);
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: application/x-ndjson');
    set_time_limit(0);
    ignore_user_abort(false);

    $stopped = false;
    foreach ($titles as $title) {
        if (shouldStopScrape()) {
            $stopped = true;
            break;
        }

        if (catalogHasBotDeliverable($title)) {
            streamJsonLine([
                'status' => 'already_available',
                'title' => $title,
                'detail' => 'movie already stored and deliverable',
            ]);
            streamJsonLine([
                'status' => 'title_complete',
                'title' => $title,
                'stored_count' => 0,
                'stored_total' => catalogItemCount($title),
            ]);
            continue;
        }

        if (!$isBot) {
            streamJsonLine([
                'status' => 'searching',
                'title' => $title,
                'detail' => 'searching readable source ' . $target,
                'stored_total' => catalogItemCount($title),
            ]);

            $readonly = findAndStoreFromReadablePeer($madeline, $peer, $target, $title, $BOT_USERNAME);
            $storedCount = !empty($readonly['stored']) ? 1 : 0;

            if (!empty($readonly['stored'])) {
                streamJsonLine([
                    'status' => 'stored',
                    'title' => $title,
                    'quality' => $readonly['quality'] ?? 'unknown',
                    'detail' => ($readonly['method'] ?? 'stored') . ' from ' . $target,
                    'stored_count' => $storedCount,
                    'stored_total' => catalogItemCount($title),
                ]);
                streamJsonLine([
                    'status' => 'index_pending',
                    'title' => $title,
                    'detail' => 'saved for the next batch index post',
                ]);
            } elseif (!empty($readonly['already'])) {
                streamJsonLine([
                    'status' => 'already_available',
                    'title' => $title,
                    'quality' => $readonly['quality'] ?? 'unknown',
                    'detail' => $readonly['detail'] ?? ($readonly['reason'] ?? 'already stored'),
                ]);
            } elseif (!empty($readonly['found'])) {
                streamJsonLine([
                    'status' => 'found_no_file',
                    'title' => $title,
                    'detail' => 'Matching message found in ' . $target . ', but no copyable file was stored.',
                ]);
            } else {
                streamJsonLine([
                    'status' => 'not_found',
                    'title' => $title,
                    'detail' => 'not found in ' . $target,
                ]);
            }

            streamJsonLine([
                'status' => 'title_complete',
                'title' => $title,
                'stored_count' => $storedCount,
                'stored_total' => catalogItemCount($title),
            ]);

            usleep(120000);
            continue;
        }

        $sessionQualities = [];
        $clickedKeys = [];
        $processedMessageIds = [];
        $buttonQueue = [];
        $downloadQueue = [];
        $resultSeen = false;
        $noResults = false;
        $alreadyAvailable = false;
        $clickedOneButton = false;
        $clickedDownloadButton = false;
        $homeRecoveries = 0;
        $storedCount = 0;
        $baselineId = latestMessageId($madeline, $peer);

        streamJsonLine([
            'status' => 'searching',
            'title' => $title,
            'stored_total' => catalogItemCount($title),
        ]);
        $madeline->messages->sendMessage(['peer' => $peer, 'message' => $title]);

        $startTime = microtime(true);
        $lastActivityAt = $startTime;
        do {
            if (shouldStopScrape()) {
                $stopped = true;
                break 2;
            }

            $history = $madeline->messages->getHistory([
                'peer' => $peer,
                'limit' => SCRAPE_HISTORY_LIMIT,
                'offset_id' => 0,
            ]);

            foreach (($history['messages'] ?? []) as $msg) {
                $messageId = (int) ($msg['id'] ?? 0);
                if ($messageId <= $baselineId || isset($processedMessageIds[$messageId])) {
                    continue;
                }
                $processedMessageIds[$messageId] = true;
                $lastActivityAt = microtime(true);

                if (($msg['message'] ?? '') === $title) {
                    continue;
                }

                $text = messageText($msg);
                if (messageSaysNoResults($text)) {
                    $noResults = true;
                    break 2;
                }

                if (messageHasDocument($msg)) {
                    $resultSeen = true;
                    $quality = extractQuality(messageFileName($msg) . ' ' . $text);

                    if (!canStoreQuality($title, $quality, $sessionQualities)) {
                        streamJsonLine([
                            'status' => 'already_available',
                            'title' => $title,
                            'quality' => $quality,
                            'detail' => 'movie already stored locally',
                        ]);
                        $alreadyAvailable = true;
                        break 2;
                    }

                    $stored = storeMovieMessage($madeline, $peer, $msg, $BOT_USERNAME, $title, $quality);
                    if (!empty($stored['stored'])) {
                        $sessionQualities[] = $quality;
                        $storedCount++;
                        streamJsonLine([
                            'status' => 'stored',
                            'title' => $title,
                            'quality' => $quality,
                            'detail' => $stored['method'] ?? 'stored',
                            'stored_count' => $storedCount,
                            'stored_total' => catalogItemCount($title),
                        ]);
                        break 2;
                    } elseif (!empty($stored['already'])) {
                        streamJsonLine([
                            'status' => 'already_available',
                            'title' => $title,
                            'quality' => $quality,
                            'detail' => $stored['reason'] ?? 'already stored',
                        ]);
                        $alreadyAvailable = true;
                        break 2;
                    }
                }

                $downloadButtons = getDownloadButtons($msg);
                if ($clickedOneButton && !$clickedDownloadButton && $downloadButtons && messageLooksLikeFileDetail($msg, $title)) {
                    $downloadButton = $downloadButtons[0];
                    $downloadLabel = trim((string) ($downloadButton['text'] ?? 'DOWNLOAD'));
                    $quality = extractQuality(messageFileName($msg) . ' ' . $text . ' ' . $downloadLabel);
                    $key = normalizeSearchText(($downloadButton['_'] ?? '') . ' ' . $downloadLabel . ' ' . (string) ($downloadButton['url'] ?? '') . ' ' . base64_encode((string) ($downloadButton['data'] ?? '')));

                    if ($key !== '' && !isset($clickedKeys[$key]) && canStoreQuality($title, $quality, $sessionQualities)) {
                        $resultSeen = true;
                        $downloadQueue[$key] = [
                            'button' => $downloadButton,
                            'message' => $msg,
                            'label' => $downloadLabel,
                            'quality' => $quality,
                        ];
                    }
                }

                $totalResults = messageTotalResults($text);
                $newButtons = queueCandidateButtons($msg, $title, $sessionQualities, $clickedKeys);

                if (($clickedOneButton || $clickedDownloadButton || $resultSeen) && $homeRecoveries < 1 && messageLooksLikeNavigationPrompt($msg, $title)) {
                    $homeRecoveries++;
                    $clickedOneButton = false;
                    $clickedDownloadButton = false;
                    $downloadQueue = [];
                    $startTime = microtime(true);
                    $lastActivityAt = $startTime;

                    streamJsonLine([
                        'status' => 'opening',
                        'title' => $title,
                        'detail' => 'Home/menu detected; searching this title again',
                    ]);

                    $madeline->messages->sendMessage(['peer' => $peer, 'message' => $title]);
                    usleep(SCRAPE_AFTER_CLICK_USEC);
                    continue;
                }

                if ($totalResults > 0 || $newButtons) {
                    if (!$resultSeen) {
                        $resultSeen = true;
                        streamJsonLine([
                            'status' => 'result_found',
                            'title' => $title,
                            'detail' => $totalResults > 0 ? "Bot says total results: $totalResults" : 'File button found',
                        ]);
                    }

                    if (!$clickedOneButton && $newButtons) {
                        foreach ($newButtons as $candidate) {
                            $buttonQueue[$candidate['key']] ??= [
                                'button' => $candidate['button'],
                                'message' => $msg,
                                'label' => $candidate['label'],
                                'quality' => $candidate['quality'],
                            ];
                        }
                    }
                }
            }

            if ($buttonQueue && $clickedOneButton && !$clickedDownloadButton && $storedCount < 1 && microtime(true) - $lastActivityAt >= SCRAPE_IDLE_CLICK_SECONDS) {
                $clickedOneButton = false;
                streamJsonLine([
                    'status' => 'opening',
                    'title' => $title,
                    'detail' => 'No file after that click; trying the next result',
                ]);
            }

            if ($buttonQueue && !$clickedOneButton && $storedCount < 1 && !catalogHasBotDeliverable($title)) {
                $key = array_key_first($buttonQueue);
                $candidate = $buttonQueue[$key];
                unset($buttonQueue[$key]);
                $clickedKeys[$key] = true;
                $clickedOneButton = true;
                $lastActivityAt = microtime(true);

                streamJsonLine([
                    'status' => 'opening',
                    'title' => $title,
                    'quality' => $candidate['quality'],
                    'detail' => 'Clicked: ' . ($candidate['label'] ?: 'file button'),
                ]);

                $click = clickOneButton($madeline, $peer, $candidate['message'], $candidate['button'], $BOT_USERNAME);
                if (!empty($click['stored_url']) && !empty($click['url'])) {
                    $stored = storeTelegramMessageUrl($madeline, (string) $click['url'], $BOT_USERNAME, $title, $candidate['quality']);
                    if (!empty($stored['stored'])) {
                        $sessionQualities[] = $candidate['quality'];
                        $storedCount++;
                        streamJsonLine([
                            'status' => 'stored',
                            'title' => $title,
                            'quality' => $candidate['quality'],
                            'detail' => $stored['method'] ?? 'hidden_forward_link',
                            'stored_count' => $storedCount,
                            'stored_total' => catalogItemCount($title),
                        ]);
                        break;
                    }

                    streamJsonLine([
                        'status' => 'opening',
                        'title' => $title,
                        'quality' => $candidate['quality'],
                        'detail' => 'Opened link, waiting for actual file message',
                    ]);
                }
                usleep(SCRAPE_AFTER_CLICK_USEC);
            }

            if ($downloadQueue && !$clickedDownloadButton && $storedCount < 1 && !catalogHasBotDeliverable($title)) {
                $key = array_key_first($downloadQueue);
                $candidate = $downloadQueue[$key];
                unset($downloadQueue[$key]);
                $clickedKeys[$key] = true;
                $clickedDownloadButton = true;
                $lastActivityAt = microtime(true);

                streamJsonLine([
                    'status' => 'opening',
                    'title' => $title,
                    'quality' => $candidate['quality'],
                    'detail' => 'Clicked: ' . ($candidate['label'] ?: 'DOWNLOAD'),
                ]);

                $click = clickOneButton($madeline, $peer, $candidate['message'], $candidate['button'], $BOT_USERNAME);
                if (!empty($click['stored_url']) && !empty($click['url'])) {
                    $stored = storeTelegramMessageUrl($madeline, (string) $click['url'], $BOT_USERNAME, $title, $candidate['quality']);
                    if (!empty($stored['stored'])) {
                        $sessionQualities[] = $candidate['quality'];
                        $storedCount++;
                        streamJsonLine([
                            'status' => 'stored',
                            'title' => $title,
                            'quality' => $candidate['quality'],
                            'detail' => $stored['method'] ?? 'hidden_forward_link',
                            'stored_count' => $storedCount,
                            'stored_total' => catalogItemCount($title),
                        ]);
                        break;
                    }
                }

                usleep(SCRAPE_AFTER_DOWNLOAD_USEC);
            }

            if ($storedCount > 0) {
                break;
            }

            $elapsed = microtime(true) - $startTime;
            $idleFor = microtime(true) - $lastActivityAt;
            $maxWait = $clickedDownloadButton ? SCRAPE_AFTER_DOWNLOAD_TIMEOUT : ($clickedOneButton ? SCRAPE_AFTER_CLICK_TIMEOUT : SCRAPE_NO_RESULT_TIMEOUT);
            $idleLimit = $clickedDownloadButton ? SCRAPE_IDLE_DOWNLOAD_SECONDS : ($clickedOneButton ? SCRAPE_IDLE_CLICK_SECONDS : SCRAPE_IDLE_RESULT_SECONDS);

            if ($resultSeen && !$buttonQueue && !$downloadQueue && $idleFor >= $idleLimit) {
                break;
            }

            usleep(SCRAPE_POLL_USEC);
        } while ($elapsed < $maxWait);

        if ($storedCount === 0 && $resultSeen && $clickedOneButton && !$alreadyAvailable && !catalogHasBotDeliverable($title)) {
            $lateDocument = findRecentDocumentMessage($madeline, $peer, $baselineId, $title);
            if ($lateDocument) {
                $quality = extractQuality(messageFileName($lateDocument) . ' ' . messageText($lateDocument));
                $stored = storeMovieMessage($madeline, $peer, $lateDocument, $BOT_USERNAME, $title, $quality);
                if (!empty($stored['stored'])) {
                    $sessionQualities[] = $quality;
                    $storedCount++;
                    streamJsonLine([
                        'status' => 'stored',
                        'title' => $title,
                        'quality' => $quality,
                        'detail' => ($stored['method'] ?? 'stored') . ' after file scan',
                        'stored_count' => $storedCount,
                        'stored_total' => catalogItemCount($title),
                    ]);
                } elseif (!empty($stored['already'])) {
                    streamJsonLine([
                        'status' => 'already_available',
                        'title' => $title,
                        'quality' => $quality,
                        'detail' => $stored['reason'] ?? 'already stored',
                    ]);
                    $alreadyAvailable = true;
                }
            }
        }

        if ($storedCount > 0) {
            streamJsonLine([
                'status' => 'index_pending',
                'title' => $title,
                'detail' => 'saved for the next batch index post',
            ]);
        } elseif ($alreadyAvailable) {
            // Already reported above.
        } elseif ($resultSeen) {
            streamJsonLine([
                'status' => 'found_no_file',
                'title' => $title,
                'detail' => 'Result was found, but no new quality/file was stored.',
            ]);
        } elseif ($noResults) {
            streamJsonLine(['status' => 'not_found', 'title' => $title]);
        } else {
            streamJsonLine([
                'status' => 'no_response',
                'title' => $title,
                'detail' => 'No final bot answer arrived before timeout.',
            ]);
        }

        streamJsonLine([
            'status' => 'title_complete',
            'title' => $title,
            'stored_count' => $storedCount,
            'stored_total' => catalogItemCount($title),
        ]);

        usleep(120000);
    }

    if ($stopped) {
        streamJsonLine(['status' => 'stopped']);
    }
    clearStopScrape();
    streamJsonLine(['status' => 'complete']);
    exit;
}

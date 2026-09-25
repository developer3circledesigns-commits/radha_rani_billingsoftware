<?php

declare(strict_types=1);

/**
 * General-purpose helpers.
 */

// ------------------------------------------------------------------
// Output escaping / XSS protection
// ------------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ------------------------------------------------------------------
// URLs
// ------------------------------------------------------------------
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return BASE_URL . '/' . $path;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function currentUrl(): string
{
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . ($_SERVER['REQUEST_URI'] ?? '/');
}

// ------------------------------------------------------------------
// Requests
// ------------------------------------------------------------------
function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function isPost(): bool
{
    return method() === 'POST';
}

function isGet(): bool
{
    return method() === 'GET';
}

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

// ------------------------------------------------------------------
// CSRF protection
// ------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): bool
{
    $token = $token ?? post('csrf_token');
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_fail(): void
{
    http_response_code(419);
    if (isPost()) {
        api_error('Session token expired. Please refresh the page and try again.', 419);
    }
    require APP_PATH . '/views/errors/419.php';
    exit;
}

// ------------------------------------------------------------------
// Flash messages
// ------------------------------------------------------------------
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// ------------------------------------------------------------------
// JSON API helpers
// ------------------------------------------------------------------
function api_json($payload, int $status = 200): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok($data = null, string $message = 'OK'): void
{
    api_json(['success' => true, 'message' => $message, 'data' => $data]);
}

function api_error(string $message, int $status = 400, $errors = null): void
{
    $payload = ['success' => false, 'message' => $message];
    if ($errors !== null) {
        $payload['errors'] = $errors;
    }
    api_json($payload, $status);
}

// ------------------------------------------------------------------
// Validation helpers
// ------------------------------------------------------------------
function validate_required(array $data, array $fields, array &$errors): void
{
    foreach ($fields as $field => $label) {
        if (empty(trim((string) ($data[$field] ?? '')))) {
            $errors[$field] = $label . ' is required.';
        }
    }
}

function validate_email(array $data, array $fields, array &$errors): void
{
    foreach ($fields as $field => $label) {
        if (!empty($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
            $errors[$field] = $label . ' must be a valid email address.';
        }
    }
}

// ------------------------------------------------------------------
// Formatting helpers
// ------------------------------------------------------------------
function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    $now = time();
    $diff = $now - $ts;

    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d', $now)) {
        return 'Today at ' . date('h:i A', $ts);
    }
    if ($diff < 172800 && date('Y-m-d', $ts) === date('Y-m-d', $now - 86400)) {
        return 'Yesterday at ' . date('h:i A', $ts);
    }
    return date('d M Y, h:i A', $ts);
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    return date('d M Y', strtotime($date));
}

function format_time(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    return date('h:i A', strtotime($datetime));
}

// ------------------------------------------------------------------
// Misc
// ------------------------------------------------------------------
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function user_agent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
}

function sanitize_filename(string $name): string
{
    $name = preg_replace('/[^\w.()-]/u', '_', basename($name));
    $name = preg_replace('/_{2,}/', '_', $name);
    return trim($name, '_') ?: 'document';
}

function secure_rand_hex(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}
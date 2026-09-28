<?php

declare(strict_types=1);

/**
 * General-purpose helpers.
 */

// ------------------------------------------------------------------
// Translations
// ------------------------------------------------------------------
// Thin wrappers so templates read as e(t('nav.bills')) instead of
// e(Lang::t('nav.bills')). Both return plain text and still need e().
function t(string $key, array $replace = []): string
{
    return Lang::t($key, $replace);
}

function tn(string $key, int $n, array $replace = []): string
{
    return Lang::tn($key, $n, $replace);
}

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
// Origin helpers
// ------------------------------------------------------------------

/**
 * Reduce a URL or a Host header to a comparable "host:port" origin.
 *
 * The port is part of an origin, so it has to be in the comparison: the same
 * host on :8080 is a different origin from the same host on :443. An explicit
 * default port is dropped so http://x/ and http://x:80/ agree, which is what a
 * browser means by the same origin.
 *
 * $fromUrl is true for a Referer/Origin (parse it as a URL) and false for a
 * Host header (already just the authority). Returns null when the input has no
 * usable host, so callers can treat "unknown" as "do not enforce" rather than
 * silently accepting everything.
 */
function request_origin(string $value, bool $fromUrl): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if ($fromUrl) {
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    } else {
        // A Host header is "host" or "host:port", and may be an IPv6 literal in
        // brackets, which has to keep its brackets to be unambiguous.
        $authority = $value;
        $port = null;
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                return null;
            }
            $host = substr($authority, 0, $close + 1);
            $rest = substr($authority, $close + 1);
            if ($rest !== '' && $rest[0] === ':') {
                $port = (int) substr($rest, 1);
            }
        } elseif (substr_count($authority, ':') === 1) {
            [$host, $portPart] = explode(':', $authority, 2);
            $port = $portPart === '' ? null : (int) $portPart;
        } else {
            $host = $authority;
        }
        if ($host === '') {
            return null;
        }

        // A Host header carries no scheme, so the port can only be defaulted
        // from the connection the request actually arrived on.
        $scheme = request_is_https() ? 'https' : 'http';
    }

    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }

    $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
    if ($port === null || $port === $default) {
        $port = $default ?? $port;
    }

    // Host names are case-insensitive; the port is not meaningful to case.
    $host = strtolower($host);

    // IPv6 literals are compared on their expanded form so ::1 and 0:0:0:0:0:0:0:1
    // are not treated as different origins.
    if (str_starts_with($host, '[') && function_exists('inet_pton')) {
        $packed = @inet_pton(trim($host, '[]'));
        if ($packed !== false) {
            $unpacked = @inet_ntop($packed);
            if (is_string($unpacked)) {
                $host = '[' . strtolower($unpacked) . ']';
            }
        }
    }

    return $host . ':' . ($port ?? '');
}

/** True when the current request arrived over TLS. */
function request_is_https(): bool
{
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Behind a TLS-terminating proxy the backend sees plain HTTP.
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return false;
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
    csrf_fail_log();

    http_response_code(419);
    if (isPost()) {
        api_error(t('api.csrf_expired'), 419);
    }
    require APP_PATH . '/views/errors/419.php';
    exit;
}

/**
 * JSON-API counterpart of csrf_fail().
 *
 * Every API endpoint used to inline "if (!csrf_verify()) api_error(...419)",
 * which meant a CSRF rejection in the API was invisible to the SIEM. Routing
 * them through one function keeps the response byte-for-byte identical while
 * giving all of them the same reporting.
 */
function csrf_fail_api(string $message = ''): void
{
    csrf_fail_log();
    api_error($message !== '' ? $message : t('api.csrf_expired_short'), 419);
}

/**
 * Report a CSRF rejection without terminating the request. Used by the
 * endpoints that answer 419 in their own way.
 */
function csrf_fail_log(): void
{
    $actor = current_user();

    SecurityLogger::log(SecurityLogger::CSRF_VALIDATION_FAILED, [
        'user_id'  => $actor['id']       ?? null,
        'username' => $actor['username'] ?? null,
        'role'     => $actor['role']     ?? null,
        'reason'   => 'missing_or_mismatched_csrf_token',
        'result'   => 'denied',
    ]);
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

// Default arguments cannot call t(), which does not exist yet at compile
// time, so the empty string is resolved inside the body.
function api_ok($data = null, string $message = ''): void
{
    api_json([
        'success' => true,
        'message' => $message !== '' ? $message : t('api.ok'),
        'data'    => $data,
    ]);
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
// The message is assembled from a translated template with the field label
// injected, so the sentence is built by the catalogue rather than by
// concatenating an English fragment onto a label.
function validate_required(array $data, array $fields, array &$errors): void
{
    foreach ($fields as $field => $label) {
        if (empty(trim((string) ($data[$field] ?? '')))) {
            $errors[$field] = t('validation.required', ['label' => $label]);
        }
    }
}

function validate_email(array $data, array $fields, array &$errors): void
{
    foreach ($fields as $field => $label) {
        if (!empty($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
            $errors[$field] = t('validation.email', ['label' => $label]);
        }
    }
}

// ------------------------------------------------------------------
// Formatting helpers
// ------------------------------------------------------------------
// These four keep their original names and signatures so the ~90 existing
// call sites across the views, the pages and the CLI tools need no edit. The
// locale logic now lives in Lang so the old English-only bodies are gone;
// format_date previously printed a leading zero (date('d M Y')), which is
// wrong for both languages, and now honours the catalogue.
function format_bytes(int $bytes): string
{
    return Lang::bytes($bytes);
}

function format_datetime(?string $datetime): string
{
    return Lang::relative($datetime);
}

function format_date(?string $date): string
{
    return Lang::date($date);
}

function format_time(?string $datetime): string
{
    return Lang::time($datetime);
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
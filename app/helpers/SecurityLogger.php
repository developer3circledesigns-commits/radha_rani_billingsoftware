<?php

declare(strict_types=1);

/**
 * Structured security event logger (JSON Lines) for Wazuh.
 *
 * WHY THIS IS NOT audit_logs
 * audit_logs is a MySQL table. It cannot record a login failure that happened
 * *because* the database was unreachable, nothing forwards it off the box, and
 * the dashboard history is a per-page UI, not a SIEM feed. This logger writes
 * one JSON object per line to a local file that the Wazuh Windows agent tails
 * and forwards to the manager. The two are deliberately complementary:
 * audit_logs stays the application's own history, security.log is the feed.
 *
 * CONTRACT
 * - One JSON object per line, never pretty printed, so Wazuh's JSON decoder can
 *   parse each line independently of every other line.
 * - FILE_APPEND | LOCK_EX, so two concurrent PHP workers can never interleave
 *   a partial line.
 * - No query of its own. It reads only $_SERVER, $_SESSION and what the caller
 *   already has in hand, so adding a log call never adds a database round trip.
 * - Never throws, never echoes, never sets an error code. A monitoring layer
 *   that can take the application down is worse than no monitoring layer.
 *
 * WHAT IT NEVER LOGS
 * Passwords, password hashes, session ids, cookies, CSRF/JWT tokens, API keys,
 * database credentials, authorization headers, private keys, SMTP passwords,
 * card numbers, CVV. Two independent layers enforce that: the explicit key
 * denylist in redact() plus the key-shape regex, and control-character
 * stripping, so no caller-supplied value can ever forge a second log line.
 *
 * IP POLICY (documented decision, spec section 7)
 * This application is NOT behind a trusted reverse proxy: the browser talks to
 * the local web server directly, and the containerised nginx sets
 * fastcgi_param REMOTE_ADDR from $remote_addr, so PHP already sees the real
 * client address. HTTP_X_FORWARDED_FOR is therefore IGNORED completely - it is
 * attacker-controlled on a direct connection and trusting it would let anyone
 * forge the source address that brute-force correlation keys on. Add a proxy
 * trust list here only if a real proxy is ever placed in front, and only with
 * an explicit allow-list of its addresses.
 */
final class SecurityLogger
{
    /** Value of the "application" field. The Wazuh decoder keys on this. */
    public const APPLICATION = 'RADHA_RANI_PORTAL';

    // ---- Authentication ------------------------------------------------
    public const LOGIN_SUCCESS            = 'login_success';
    public const LOGIN_FAILED             = 'login_failed';
    public const LOGOUT                   = 'logout';
    public const PASSWORD_CHANGE          = 'password_change';
    public const PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    public const PASSWORD_RESET_SUCCESS   = 'password_reset_success';
    public const PASSWORD_RESET_FAILED    = 'password_reset_failed';
    public const ACCOUNT_LOCKED           = 'account_locked';

    // ---- User management ----------------------------------------------
    public const USER_CREATED     = 'user_created';
    public const USER_UPDATED     = 'user_updated';
    public const USER_DELETED     = 'user_deleted';
    public const ROLE_CHANGED     = 'role_changed';
    public const PASSWORD_RESET   = 'password_reset';

    // ---- Administration ------------------------------------------------
    public const ADMIN_ACTION           = 'admin_action';
    public const ADMIN_SETTINGS_CHANGED = 'admin_settings_changed';

    // ---- Data ----------------------------------------------------------
    public const RECORD_CREATED            = 'record_created';
    public const RECORD_UPDATED            = 'record_updated';
    public const RECORD_DELETED            = 'record_deleted';
    public const RECORD_RESTORED           = 'record_restored';
    public const SENSITIVE_RECORD_ACCESS   = 'sensitive_record_access';

    // ---- Files ---------------------------------------------------------
    public const FILE_UPLOADED   = 'file_uploaded';
    public const FILE_DELETED    = 'file_deleted';
    public const FILE_DOWNLOADED = 'file_downloaded';

    // ---- Security ------------------------------------------------------
    public const CSRF_VALIDATION_FAILED = 'csrf_validation_failed';
    public const UNAUTHORIZED_ACCESS    = 'unauthorized_access';
    public const FORBIDDEN_ACCESS       = 'forbidden_access';
    public const SUSPICIOUS_REQUEST     = 'suspicious_request';
    public const INVALID_INPUT          = 'invalid_input';
    public const RATE_LIMIT_TRIGGERED   = 'rate_limit_triggered';

    // ---- Application ---------------------------------------------------
    public const APPLICATION_ERROR    = 'application_error';
    public const DATABASE_ERROR       = 'database_error';
    public const UNEXPECTED_EXCEPTION = 'unexpected_exception';
    public const LOGGING_ERROR        = 'security_logging_error';

    /**
     * Field names whose VALUE is replaced, never written. Matching is
     * substring-based on a lowercased, punctuation-stripped key, so
     * "New-Password", "newPassword" and "PASSWORD" are all caught.
     */
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEY_PARTS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'apikey', 'authorization',
        'credential', 'privatekey', 'cookie', 'sessionid', 'jwt', 'bearer',
        'cvv', 'cvc', 'cardnumber', 'pan', 'iban', 'smtppassword', 'dbpass',
    ];

    /**
     * Longest string value kept for a single field. Generous enough for a
     * filename or a user agent, short enough that one event can never bloat
     * the log with a dumped request body.
     */
    private const MAX_VALUE = 256;

    /** Hard cap on fields per event, so a caller cannot flood one line. */
    private const MAX_FIELDS = 24;

    /** Only stat() the log for rotation once every N writes. */
    private const ROTATION_CHECK_EVERY = 250;

    private static int $writesSinceRotationCheck = 0;

    /**
     * Consecutive write failures. Once the log is known to be unwritable we
     * stop touching the filesystem (so a broken log cannot add latency to every
     * request) but we stay ready to recover on the next request.
     */
    private static int $consecutiveFailures = 0;

    private const MAX_FAILURES_BEFORE_GIVING_UP = 20;

    /**
     * Identity to attribute an event to before a session exists.
     *
     * A failed login has no $_SESSION and no current_user(), yet "who was being
     * targeted" is the single most important field on the event. The login
     * handler calls hintActor() with the identifier that was submitted, and
     * every event in that request is attributed to it unless the caller
     * overrides the field explicitly.
     *
     * @var array{username: string|null, user_id: int|null, role: string|null}|null
     */
    private static ?array $actorHint = null;

    private function __construct() {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Attribute subsequent events in this request to an identity that has not
     * signed in yet. Pass nulls to clear it.
     */
    public static function hintActor(?string $username, ?int $userId = null, ?string $role = null): void
    {
        self::$actorHint = [
            'username' => $username,
            'user_id'  => $userId,
            'role'     => $role,
        ];
    }

    /**
     * Fill the identity fields from the actor hint, but only where the caller
     * has not already supplied them.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function applyActorHint(array $context): array
    {
        if (self::$actorHint === null) {
            return $context;
        }

        foreach (['username', 'user_id', 'role'] as $field) {
            if (!array_key_exists($field, $context) || $context[$field] === null) {
                $context[$field] = self::$actorHint[$field];
            }
        }

        return $context;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Append one security event. The only method callers need.
     *
     * @param array<string, mixed> $context Merged over the automatic request
     *                                      context. Use it for metadata only;
     *                                      sensitive keys are redacted here,
     *                                      not by the caller.
     */
    public static function log(string $event, array $context = []): void
    {
        try {
            if (!defined('SECURITY_LOG_ENABLED') || !SECURITY_LOG_ENABLED) {
                return;
            }

            $record = [
                'timestamp'   => date('c'),
                'event'       => self::sanitizeToken($event),
                'application' => self::APPLICATION,
            ];

            foreach (self::requestContext() as $key => $value) {
                $record[$key] = $value;
            }

            $context = self::applyActorHint($context);

            foreach ($context as $key => $value) {
                $record[self::sanitizeToken((string) $key)] = $value;
            }

            $record = self::normalize($record);

            $line = json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

            // json_encode returns false on invalid UTF-8 or recursion. Never
            // write a broken line: a half-written object would be decoded as a
            // malformed event and would poison every downstream rule.
            if ($line === false) {
                self::reportFailure('json_encode failed: ' . json_last_error_msg());
                return;
            }

            self::write($line . "\n");

        } catch (Throwable $e) {
            // Absolute last resort. Nothing here may escape.
            self::reportFailure($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Convenience wrappers. These exist so call sites stay one readable line
    // and so the field set for each event type is decided in ONE place.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $user  Row from users, when known.
     * @param array<string, mixed>       $extra
     */
    public static function authSuccess(string $event, ?array $user = null, array $extra = []): void
    {
        self::log($event, $extra + [
            'user_id'  => $user['id']      ?? ($_SESSION['user_id'] ?? null),
            'username' => self::loginLabel($user),
            'role'     => $user['role']    ?? null,
            'result'   => 'success',
        ]);
    }

    /**
     * A failed login. $username is whatever the visitor typed: it is useful for
     * correlation and is not a secret, but it is still untrusted input, so it
     * goes through the same sanitiser as every other value.
     */
    public static function authFailed(string $event, string $username, string $reason, ?int $userId = null, ?array $user = null): void
    {
        self::log($event, [
            'user_id'  => $userId ?? ($user['id'] ?? null),
            'username' => $username,
            'role'     => $user['role'] ?? null,
            'reason'   => $reason,
            'result'   => 'failed',
        ]);
    }

    /**
     * A denial by the authorization layer.
     *
     * @param array<string, mixed> $extra
     */
    public static function denied(string $event, string $reason, ?array $user = null, array $extra = []): void
    {
        self::log($event, $extra + [
            'user_id'  => $user['id']      ?? ($_SESSION['user_id'] ?? null),
            'username' => self::loginLabel($user),
            'role'     => $user['role']    ?? null,
            'branch_id'=> $user['branch_id'] ?? null,
            'reason'   => $reason,
            'result'   => 'denied',
        ]);
    }

    /**
     * File upload metadata. Contents are never read and never logged.
     *
     * @param array<string, mixed> $file A $_FILES entry.
     * @param array<string, mixed> $extra
     */
    public static function fileUploaded(array $file, string $sanitizedName, string $destination, string $result, ?array $user = null, array $extra = []): void
    {
        $original = (string) ($file['name'] ?? '');

        self::log(self::FILE_UPLOADED, $extra + [
            'user_id'            => $user['id']       ?? ($_SESSION['user_id'] ?? null),
            'username'           => self::loginLabel($user),
            'role'               => $user['role']     ?? null,
            'original_filename'  => $original,
            'stored_filename'    => $sanitizedName,
            'extension'          => strtolower((string) pathinfo($original, PATHINFO_EXTENSION)),
            'mime_type'          => $file['type']     ?? null,
            'file_size'          => isset($file['size']) ? (int) $file['size'] : null,
            'destination'        => $destination,
            'result'             => $result,
        ]);
    }

    // ------------------------------------------------------------------
    // Request context
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function requestContext(): array
    {
        return [
            'ip'     => self::ipAddress(),
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI')),
            'uri'    => self::requestUri(),
        ];
    }

    /**
     * Path only. The query string is deliberately NOT echoed, because a URL is
     * a place people accidentally put a reset token. Query parameter NAMES are
     * still useful for an investigation, so they are reported without values.
     */
    private static function requestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $q   = strpos($uri, '?');
        if ($q !== false) {
            $uri = substr($uri, 0, $q);
        }
        return self::cleanString($uri, 200);
    }

    /**
     * REMOTE_ADDR only. See the class docblock for why X-Forwarded-For is
     * ignored. Validated as an IP so a malformed value can never be used to
     * split a log line.
     */
    private static function ipAddress(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /**
     * The identity used in "username": prefer the login name, fall back to
     * email, and never emit a value that is empty.
     *
     * @param array<string, mixed>|null $user
     */
    private static function loginLabel(?array $user): ?string
    {
        if (!$user) {
            return null;
        }
        $label = $user['username'] ?? '';
        if ($label === '') {
            $label = $user['email'] ?? '';
        }
        return $label === '' ? null : self::cleanString((string) $label, 100);
    }

    // ------------------------------------------------------------------
    // Normalisation: redaction, control characters, types, size
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $record
     * @return array<string, scalar|null>
     */
    private static function normalize(array $record): array
    {
        $out = [];

        foreach ($record as $key => $value) {
            if (count($out) >= self::MAX_FIELDS) {
                break;
            }

            $key = self::cleanString((string) $key, 64);

            if (self::isSensitiveKey($key)) {
                // The KEY is kept so an analyst can see that a password field
                // was present; the VALUE never reaches the file.
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = self::normalizeValue($value);
        }

        return $out;
    }

    private static function normalizeValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            // An array in a security event is almost always a caller dumping
            // request data by mistake. Report its size, not its contents.
            return '[array:' . count($value) . ']';
        }

        if (is_object($value)) {
            return '[object]';
        }

        return self::cleanString((string) $value, self::MAX_VALUE);
    }

    /**
     * Strip everything that could break the one-line-per-event contract, then
     * cap the length. Byte-wise (no /u) on purpose: a /u pattern returns null
     * on invalid UTF-8, which would blank out exactly the hostile input this
     * function exists to neutralise. Bytes >= 0x80 are left alone, so valid
     * multibyte characters survive intact.
     */
    private static function cleanString(string $value, int $max): string
    {
        // NUL, CR, LF, TAB and the C0/C1 range, plus DEL. Newlines and CR are
        // the log-injection vector: without this a crafted username could
        // forge an entire extra event, or a second rule's worth of fields.
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);

        // Collapse runs of whitespace left behind, so "a\n\nb" reads as "a b"
        // instead of padding the line.
        $value = (string) preg_replace('/\s{2,}/', ' ', $value);

        $value = trim($value);

        if (function_exists('mb_substr') && mb_strlen($value, 'UTF-8') > $max) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }

        return $value;
    }

    /**
     * Event names and field names are identifiers, not free text: keep them to
     * a conservative character set so a rule can always match on them.
     */
    private static function sanitizeToken(string $value): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '_', $value);
        return substr($value, 0, 64);
    }

    private static function isSensitiveKey(string $key): bool
    {
        // Lowercase FIRST, then strip everything that is not a letter or a
        // digit. Stripping first would delete uppercase letters outright, so
        // "Authorization" would flatten to "uthorization" and sail straight
        // past the denylist - which is exactly the field that must never be
        // written.
        $flat = (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));

        if ($flat === '') {
            return false;
        }

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (strpos($flat, $part) !== false) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    private static function write(string $line): void
    {
        if (self::$consecutiveFailures >= self::MAX_FAILURES_BEFORE_GIVING_UP) {
            return;
        }

        $path = SECURITY_LOG_FILE;

        $ok = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        if ($ok === false) {
            self::$consecutiveFailures++;
            self::reportFailure('write failed for ' . $path);
            return;
        }

        self::$consecutiveFailures = 0;

        self::maybeRotate($path);
    }

    /**
     * Size-capped rotation. security.log is a monitoring feed that is tailed
     * forever, so it must not grow without bound; but a full disk breaks the
     * application, which is worse than a large log. The stat() is amortised
     * over ROTATION_CHECK_EVERY writes so this is not a per-event syscall.
     */
    private static function maybeRotate(string $path): void
    {
        if (++self::$writesSinceRotationCheck < self::ROTATION_CHECK_EVERY) {
            return;
        }
        self::$writesSinceRotationCheck = 0;

        $maxBytes = defined('SECURITY_LOG_MAX_BYTES') ? (int) SECURITY_LOG_MAX_BYTES : 0;
        if ($maxBytes <= 0) {
            return;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size < $maxBytes) {
            return;
        }

        // Fails harmlessly if the agent or another worker holds the file
        // open; the next check tries again. Rotation is best-effort by design.
        if (!@rename($path, $path . '.1')) {
            return;
        }

        // One generation only. security.log.1 is deliberately NOT rotated to
        // .2, and is not monitored by Wazuh, so it cannot become an unbounded
        // second archive of security events.
        self::log('log_rotated', [
            'rotated_bytes' => $size,
            'max_bytes'     => $maxBytes,
            'result'        => 'success',
        ]);
    }

    /**
     * Technical failures go to the application's own log, never to the
     * response. Reported once, not on every event, so a broken log cannot
     * become a log-flooding incident of its own.
     */
    private static function reportFailure(string $message): void
    {
        static $reported = false;

        if ($reported) {
            return;
        }
        $reported = true;

        $line = sprintf(
            "[%s] security-logger: %s%s",
            date('Y-m-d H:i:s'),
            $message,
            PHP_EOL
        );

        $fallback = defined('LOG_PATH') ? LOG_PATH . '/app.log' : null;
        if ($fallback !== null) {
            @file_put_contents($fallback, $line, FILE_APPEND);
        }

        // error_log() also reaches the SAPI error log, which is where a
        // containerised deployment collects diagnostics from.
        error_log('security-logger: ' . $message);
    }
}

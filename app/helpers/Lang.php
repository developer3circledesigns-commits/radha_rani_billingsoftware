<?php

declare(strict_types=1);

/**
 * Bilingual catalogue (English / German).
 *
 * WHY A CLASS AND NOT A FUNCTION BAG
 * Every entry point loads app/bootstrap.php, so a single static holder is the
 * cheapest way to resolve the active locale once per request and have it
 * available to views, JSON endpoints and the CLI tools alike.
 *
 * LOCALE FILES LIVE IN app/lang/, NOT IN A TOP-LEVEL lang/
 * The documented flat deploy (repository root = web root) relies on the root
 * .htaccess to make public/ transparent. Its private-folder blocklist is
 * ^(?:app|storage|database|tools|docker|db|vendor) - "lang" is not on it, and
 * the RedirectMatch fallback that covers hosts with mod_rewrite disabled does
 * not list it either. A top-level /lang/de.php would therefore be executed by
 * Apache as a script and could be fetched directly. app/ is hard-blocked in
 * all three supported layouts, so the catalogue is unreachable over HTTP.
 *
 * NO intl EXTENSION
 * docker/Dockerfile installs pdo_mysql and fileinfo only, and setlocale() is a
 * silent no-op on a Debian image without the de_DE locale generated. So month
 * and weekday names, AM/PM and the decimal/thousands separators are plain data
 * in the locale files, and date formats are token strings interpreted by
 * Lang::formatDate(). Adding a language is therefore data-only: no code change.
 *
 * FALLBACK CHAIN
 * active locale -> English -> the key itself. A German string that is missing
 * degrades to readable English, never to a raw dotted key in the UI. Every
 * such miss is recorded so tools/check-i18n.php and dev logs can report it.
 */

final class Lang
{
    /** Cookie holding the visitor's choice. 1 year, written by public/set-language.php. */
    public const COOKIE = 'rr_lang';

    /** Session mirror. Session lifetime is only 30 min idle, so the cookie wins. */
    public const SESSION_KEY = 'rr_lang';

    /** @var string|null Frozen for the request. */
    private static ?string $code = null;

    /** @var array<string,string> Active locale strings. */
    private static array $strings = [];

    /** @var array<string,string> English strings, used when a key is missing. */
    private static array $fallback = [];

    /** @var array<string,mixed> Locale data: months, separators, date formats. */
    private static array $meta = [];

    /** @var array<string,bool> Keys that fell back to English. Dev diagnostics. */
    private static array $misses = [];

    /**
     * Resolve the active locale. Called once from app/bootstrap.php, after the
     * session is up, before anything can emit output.
     */
    public static function init(): void
    {
        try {
            $available = self::available();
            $code      = null;

            // 1. Cookie is authoritative: it outlives the 30-minute idle
            //    session timeout, so a language choice must not be lost when a
            //    session is recycled.
            $cookie = $_COOKIE[self::COOKIE] ?? null;
            if (is_string($cookie) && isset($available[$cookie])) {
                $code = $cookie;
            }

            // 2. Session, for a visitor whose cookie was cleared but whose
            //    session is still alive.
            if ($code === null) {
                $sess = $_SESSION[self::SESSION_KEY] ?? null;
                if (is_string($sess) && isset($available[$sess])) {
                    $code = $sess;
                }
            }

            // 3. First visit only: honour the browser's stated preference so a
            //    German-locale machine lands in German without a click. Runs
            //    before the default and only when neither cookie nor session
            //    expressed a choice.
            if ($code === null && AUTO_DETECT_LANGUAGE) {
                $code = self::detect($available);
            }

            if ($code === null) {
                $code = DEFAULT_LOCALE;
            }

            self::load($code, $available);
            self::$code = $code;

            // Keep the session in step with whatever the cookie decided, so a
            // later request in the same session is consistent.
            if (PHP_SAPI !== 'cli' && ($_SESSION[self::SESSION_KEY] ?? null) !== $code) {
                $_SESSION[self::SESSION_KEY] = $code;
            }

            // Defence in depth against a shared cache in front of the app: the
            // rendered language is a function of this cookie. PHP's own session
            // cache limiter already forbids caching these responses, so this is
            // belt and braces, and it is emitted here because bootstrap runs
            // before any output on every entry point.
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                header('Vary: Cookie', false);
            }
        } catch (Throwable $e) {
            // A broken catalogue must not take the portal down. Fall back to
            // English so a page still renders.
            self::$code     = DEFAULT_LOCALE;
            self::$strings  = [];
            self::$fallback = [];
            self::$meta     = self::defaultMeta();
        }
    }

    /**
     * Every locale the app can serve, as [code => display name].
     *
     * The whitelist is derived from a directory listing and each candidate must
     * match a strict pattern, because a locale code reaches a require() path.
     */
    public static function available(): array
    {
        $out = [];
        foreach ((array) glob(APP_PATH . '/lang/*.php') as $file) {
            $code = basename($file, '.php');
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) === 1) {
                $out[$code] = self::displayName($code);
            }
        }
        return $out;
    }

    /** Active locale code, e.g. 'en'. Safe to call before init(). */
    public static function code(): string
    {
        return self::$code ?? DEFAULT_LOCALE;
    }

    /**
     * Switch locale for the rest of this request and persist the choice.
     * Returns false for a code outside the whitelist, without touching state.
     */
    public static function persist(string $code): bool
    {
        $available = self::available();
        if (!isset($available[$code])) {
            return false;
        }

        if (PHP_SAPI !== 'cli') {
            $_SESSION[self::SESSION_KEY] = $code;
        }
        self::load($code, $available);
        self::$code = $code;

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            // 1 year, so the choice survives closing the browser. httponly
            // because nothing reads this from JS, SameSite=Lax so it is sent
            // on top-level navigations, Secure whenever the site is HTTPS.
            setcookie(self::COOKIE, $code, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'domain'   => '',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Translation
    // ------------------------------------------------------------------

    /**
     * Translate a key, substituting :placeholders.
     *
     * Returns plain text. It MUST still be escaped for HTML: e(t('…')).
     */
    public static function t(string $key, array $replace = []): string
    {
        return self::interpolate(self::raw($key), $replace);
    }

    /**
     * Translate a key that has plural forms.
     *
     * The catalogue stores 'singular|plural' and the form is chosen by an exact
     * match on 1. German needs no more than one/other for these counts, the same
     * as English, so a Pluralizer class would earn nothing.
     */
    public static function tn(string $key, int $n, array $replace = []): string
    {
        $forms  = explode('|', self::raw($key));
        $string = count($forms) > 1 ? ($n === 1 ? $forms[0] : $forms[1]) : $forms[0];

        $replace['n'] = $n;
        return self::interpolate($string, $replace);
    }

    /**
     * The untranslated string for a key in the active locale, without the
     * placeholder pass. Used by Lang::tn() and by the catalogue linter.
     */
    public static function raw(string $key): string
    {
        if (isset(self::$strings[$key])) {
            return self::$strings[$key];
        }
        if (isset(self::$fallback[$key])) {
            self::$misses[$key] = true;
            return self::$fallback[$key];
        }
        // Never show a dotted key to a user. If the catalogue is broken this
        // keeps the page legible. Recorded as a miss too, so a typo'd key
        // shows up in tools/check-i18n.php rather than silently shipping.
        self::$misses[$key] = true;
        return $key;
    }

    // ------------------------------------------------------------------
    // Locale-aware formatting
    // ------------------------------------------------------------------

    /**
     * Date, e.g. 28 Sep 2026 (en) / 28. Sep. 2026 (de).
     */
    public static function date(?string $datetime, string $format = 'date_format'): string
    {
        $ts = self::timestamp($datetime);
        return $ts === null ? '—' : self::formatDate($ts, self::meta($format));
    }

    /**
     * Time, e.g. 02:05 PM (en) / 14:05 (de). German does not use AM/PM.
     */
    public static function time(?string $datetime): string
    {
        $ts = self::timestamp($datetime);
        return $ts === null ? '—' : self::formatDate($ts, self::meta('time_format'));
    }

    /**
     * Date and time together, used as the absolute fallback in Lang::relative().
     */
    public static function datetime(?string $datetime): string
    {
        $ts = self::timestamp($datetime);
        return $ts === null ? '—' : self::formatDate($ts, self::meta('datetime_format'));
    }

    /**
     * Number with locale separators: 1,234.5 (en) / 1.234,5 (de).
     */
    public static function number(int|float $n, int $decimals = 0): string
    {
        return number_format(
            (float) $n,
            $decimals,
            (string) self::meta('decimal_sep'),
            (string) self::meta('thousands_sep')
        );
    }

    /**
     * File size: 1.5 MB (en) / 1,5 MB (de).
     */
    public static function bytes(int $bytes): string
    {
        $units = (array) self::meta('byte_units');
        if ($bytes >= 1048576) {
            return self::number($bytes / 1048576, 1) . ' ' . $units[2];
        }
        if ($bytes >= 1024) {
            return self::number($bytes / 1024, 0) . ' ' . $units[1];
        }
        return $bytes . ' ' . $units[0];
    }

    /**
     * Relative time, locale-aware.
     *
     * Replaces the old format_datetime(). "Today at" and "Yesterday at" are
     * separate keys rather than one key with a time glued on, because the
     * English and German word orders differ around the time of day.
     */
    public static function relative(?string $datetime): string
    {
        $ts = self::timestamp($datetime);
        if ($ts === null) {
            return '—';
        }

        $now = time();
        $diff = $now - $ts;
        if ($diff < 0) {
            $diff = 0;
        }

        if ($diff < 60) {
            return self::t('format.just_now');
        }
        if ($diff < 3600) {
            return self::tn('format.minutes_ago', (int) floor($diff / 60));
        }

        $today = date('Y-m-d', $now);
        if ($diff < 86400 && date('Y-m-d', $ts) === $today) {
            return self::t('format.today_at', ['time' => self::time($datetime)]);
        }
        if ($diff < 172800 && date('Y-m-d', $ts) === date('Y-m-d', $now - 86400)) {
            return self::t('format.yesterday_at', ['time' => self::time($datetime)]);
        }
        return self::datetime($datetime);
    }

    // ------------------------------------------------------------------
    // Diagnostics
    // ------------------------------------------------------------------

    /**
     * Keys that had no string in the active locale and fell back to English.
     * tools/check-i18n.php reads this; nothing in a request path does.
     */
    public static function misses(): array
    {
        return array_keys(self::$misses);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @param array<string,string> $available */
    private static function load(string $code, array $available): void
    {
        /** @var array{strings?:array,meta?:array} $data */
        $data = require APP_PATH . '/lang/' . $code . '.php';

        self::$strings = is_array($data['strings'] ?? null) ? $data['strings'] : [];
        self::$meta    = self::normaliseMeta($data['meta'] ?? null);

        if ($code === DEFAULT_LOCALE) {
            self::$fallback = self::$strings;
        } else {
            /** @var array{strings?:array} $base */
            $base = require APP_PATH . '/lang/' . DEFAULT_LOCALE . '.php';
            self::$fallback = is_array($base['strings'] ?? null) ? $base['strings'] : [];
        }

        self::$misses = [];
    }

    /**
     * Pick the first supported locale the browser actually asked for, honouring
     * quality values. Matches on the primary subtag, so 'de-AT' and 'de-CH'
     * both resolve to 'de'.
     *
     * @param array<string,string> $available
     */
    private static function detect(array $available): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (!is_string($header) || $header === '') {
            return null;
        }

        $ranked = [];
        foreach (explode(',', $header) as $chunk) {
            $bits = explode(';', trim($chunk));
            $tag  = strtolower(trim($bits[0]));
            if ($tag === '') {
                continue;
            }
            $q = 1.0;
            if (isset($bits[1]) && preg_match('/q\s*=\s*([0-9.]+)/', $bits[1], $m) === 1) {
                $q = (float) $m[1];
            }
            $ranked[] = ['tag' => $tag, 'q' => $q];
        }

        usort($ranked, static fn(array $a, array $b): int => $b['q'] <=> $a['q']);

        foreach ($ranked as $entry) {
            $primary = explode('-', $entry['tag'])[0];
            if (isset($available[$primary])) {
                return $primary;
            }
        }
        return null;
    }

    private static function displayName(string $code): string
    {
        $primary = explode('-', $code)[0];
        $names   = [
            'en' => 'English',
            'de' => 'Deutsch',
        ];
        return $names[$primary] ?? strtoupper($primary);
    }

    /**
     * Replace :name placeholders.
     *
     * Hand-rolled rather than strtr() so a substituted value that happens to
     * contain ":something" is not rescanned as a placeholder.
     */
    private static function interpolate(string $string, array $replace): string
    {
        if ($replace === []) {
            return $string;
        }

        $out = '';
        $len = strlen($string);
        for ($i = 0; $i < $len; $i++) {
            if ($string[$i] === ':' && preg_match('/\G:([A-Za-z0-9_]+)/', $string, $m, 0, $i) === 1) {
                if (array_key_exists($m[1], $replace)) {
                    $out .= (string) $replace[$m[1]];
                    $i   += strlen($m[0]) - 1;
                    continue;
                }
            }
            $out .= $string[$i];
        }
        return $out;
    }

    /**
     * Render a date using the locale's month/weekday names and format string.
     *
     * date() always emits English month names, so the name tokens are consumed
     * here and the remaining characters are handed to date() one at a time.
     * A backslash escapes the next character literally.
     */
    private static function formatDate(int $ts, string $format): string
    {
        $months      = (array) self::meta('months');
        $monthsShort = (array) self::meta('months_abbr');
        $days        = (array) self::meta('days');
        $daysShort   = (array) self::meta('days_abbr');
        $am          = (string) self::meta('am');
        $pm          = (string) self::meta('pm');

        $out = '';
        $len = strlen($format);

        for ($i = 0; $i < $len; $i++) {
            $char = $format[$i];

            switch ($char) {
                case '\\':
                    $i++;
                    if ($i < $len) {
                        $out .= $format[$i];
                    }
                    break;

                case 'M':
                    $out .= $monthsShort[(int) date('n', $ts)] ?? '';
                    break;

                case 'F':
                    $out .= $months[(int) date('n', $ts)] ?? '';
                    break;

                case 'D':
                    $out .= $daysShort[(int) date('w', $ts)] ?? '';
                    break;

                case 'l':
                    $out .= $days[(int) date('w', $ts)] ?? '';
                    break;

                case 'A':
                    $out .= ((int) date('G', $ts) < 12) ? $am : $pm;
                    break;

                case 'a':
                    $out .= ((int) date('G', $ts) < 12)
                        ? mb_strtolower($am, 'UTF-8')
                        : mb_strtolower($pm, 'UTF-8');
                    break;

                default:
                    $out .= date($char, $ts);
                    break;
            }
        }

        return $out;
    }

    private static function timestamp(?string $datetime): ?int
    {
        if ($datetime === null || trim($datetime) === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts === false ? null : $ts;
    }

    /** Read one locale data value. */
    private static function meta(string $key): mixed
    {
        $defaults = self::defaultMeta();
        return self::$meta[$key] ?? $defaults[$key] ?? null;
    }

    /**
     * A locale file only needs to declare what differs. Month names, AM/PM and
     * the separators are English-shaped unless it says otherwise, which keeps
     * a new locale file from having to restate 30 defaults.
     */
    private static function normaliseMeta(?array $meta): array
    {
        return array_merge(self::defaultMeta(), is_array($meta) ? $meta : []);
    }

    private static function defaultMeta(): array
    {
        return [
            'months'           => ['', 'January', 'February', 'March', 'April', 'May', 'June',
                                   'July', 'August', 'September', 'October', 'November', 'December'],
            'months_abbr'      => ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                                   'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'days'             => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'days_abbr'        => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            'am'               => 'AM',
            'pm'               => 'PM',
            'decimal_sep'      => '.',
            'thousands_sep'    => ',',
            'date_format'      => 'j M Y',
            'time_format'      => 'h:i A',
            'datetime_format'  => 'j M Y, h:i A',
            'date_axis_format' => 'j M',
            'byte_units'       => ['B', 'KB', 'MB', 'GB'],
        ];
    }
}

<?php

declare(strict_types=1);

/**
 * Application settings (key/value).
 */
class Setting
{
    /** Per-request cache so repeated get() calls do not re-query. */
    private static array $cache = [];

    public static function all(): array
    {
        $rows = Database::fetchAll('SELECT setting_key, setting_value FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $row = Database::fetch('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        $value = $row ? $row['setting_value'] : $default;

        self::$cache[$key] = $value;

        return $value;
    }

    public static function set(string $key, $value): void
    {
        Database::execute(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, (string) $value]
        );

        self::$cache[$key] = (string) $value;
    }

    /** Drop the per-request cache (used by tooling and tests). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
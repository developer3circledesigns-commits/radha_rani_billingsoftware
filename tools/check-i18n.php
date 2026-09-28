<?php

declare(strict_types=1);

/**
 * Catalogue linter.
 *
 *   php tools/check-i18n.php
 *
 * Fails (exit 1) when a locale file is missing a key the fallback locale
 * defines, when a locale file defines a key the fallback does not, or when a
 * catalogue is internally inconsistent. The point is that a half-translated
 * language is a build failure rather than something a German visitor
 * discovers in production.
 *
 * Checks, per locale:
 *   - every fallback key is present
 *   - no key is absent from the fallback (a typo, or a stale key)
 *   - no empty string
 *   - :placeholders used in the fallback are all used here, and no new ones
 *     are introduced, so a translation cannot silently drop or invent a
 *     placeholder
 *   - plural forms are either both 'singular|plural' or neither
 *   - a locale file parses to the expected ['meta', 'strings'] shape
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/Lang.php';

$fallbackCode = DEFAULT_LOCALE;
$available    = Lang::available();

if (!isset($available[$fallbackCode])) {
    fwrite(STDERR, "FATAL: fallback locale '{$fallbackCode}' has no file in app/lang/\n");
    exit(1);
}

/** @return array{strings:array<string,string>,meta:array} */
function loadCatalogue(string $code): array
{
    /** @var mixed $data */
    $data = require APP_PATH . '/lang/' . $code . '.php';

    if (!is_array($data) || !isset($data['strings']) || !is_array($data['strings'])) {
        fwrite(STDERR, "FATAL: app/lang/{$code}.php does not return ['strings' => [...]]\n");
        exit(1);
    }
    foreach ($data['strings'] as $key => $value) {
        if (!is_string($value)) {
            fwrite(STDERR, "FATAL: app/lang/{$code}.php key '{$key}' is not a string\n");
            exit(1);
        }
    }

    return ['strings' => $data['strings'], 'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : []];
}

/** Placeholders such as :id, :name, :page. */
function placeholders(string $string): array
{
    preg_match_all('/:([A-Za-z0-9_]+)/', $string, $m);
    return array_values(array_unique($m[1]));
}

$fallback = loadCatalogue($fallbackCode);
$problems = 0;
$report   = function (string $line) use (&$problems): void {
    $problems++;
    echo $line, PHP_EOL;
};

echo "i18n catalogue check (fallback: {$fallbackCode})", PHP_EOL, PHP_EOL;

foreach (array_keys($available) as $code) {
    if ($code === $fallbackCode) {
        continue;
    }

    $catalogue = loadCatalogue($code);
    $missing   = array_diff_key($fallback['strings'], $catalogue['strings']);
    $extra     = array_diff_key($catalogue['strings'], $fallback['strings']);

    foreach (array_keys($missing) as $key) {
        $report("  [{$code}] missing key        {$key}");
    }
    foreach (array_keys($extra) as $key) {
        $report("  [{$code}] unknown key       {$key}");
    }

    foreach ($fallback['strings'] as $key => $base) {
        if (!isset($catalogue['strings'][$key])) {
            continue;
        }
        $value = $catalogue['strings'][$key];

        if (trim($value) === '') {
            $report("  [{$code}] empty value       {$key}");
        }

        $baseP = placeholders($base);
        $ownP  = placeholders($value);
        $lost  = array_diff($baseP, $ownP);
        $gained = array_diff($ownP, $baseP);
        foreach ($lost as $p) {
            $report("  [{$code}] lost placeholder  {$key} (:{$p})");
        }
        foreach ($gained as $p) {
            $report("  [{$code}] stray placeholder {$key} (:{$p})");
        }

        $basePlural = substr_count($base, '|');
        $ownPlural  = substr_count($value, '|');
        if (($basePlural > 0) !== ($ownPlural > 0)) {
            $report("  [{$code}] plural mismatch   {$key}");
        }
        if ($ownPlural > 1) {
            $report("  [{$code}] too many plural forms {$key} (expected 'singular|plural')");
        }
    }

    printf(
        "  %-6s %3d keys, %d issue(s)%s",
        $code,
        count($catalogue['strings']),
        count($missing) + count($extra),
        PHP_EOL
    );
}

echo PHP_EOL, sprintf('  %-6s %3d keys (fallback)', $fallbackCode, count($fallback['strings'])), PHP_EOL, PHP_EOL;

if ($problems > 0) {
    echo "FAIL: {$problems} problem(s)", PHP_EOL;
    exit(1);
}

echo 'OK: all catalogues are complete', PHP_EOL;
exit(0);

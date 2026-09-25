<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 * Applies every file in database/migrations/*.sql that has not been applied
 * yet, in filename order, recording each in schema_migrations.
 *
 * Usage (from the project root):
 *   php tools/migrate.php
 *
 * Each migration file must contain plain SQL statements separated by semicolons.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);

require_once $root . '/app/bootstrap.php';

$db = Database::connection();

/** Track applied migrations so they never run twice. */
Database::execute(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = array_column(
    Database::fetchAll('SELECT filename FROM schema_migrations'),
    'filename'
);

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files);

/**
 * Split a SQL file into individual statements, ignoring semicolons that sit
 * inside quotes or comments.
 */
function split_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $i + 1 < $len) {
                $buffer .= $sql[++$i];
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === '-' && substr($sql, $i, 3) === '-- ') {
            $end = strpos($sql, "\n", $i);
            $i = ($end === false) ? $len : $end;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        echo "  = {$name} (already applied)\n";
        continue;
    }

    echo "  + {$name}\n";

    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "  ! cannot read {$name}\n";
        exit(1);
    }

    try {
        foreach (split_statements($sql) as $statement) {
            $db->exec($statement);
        }
    } catch (Throwable $e) {
        echo "  ! failed: " . $e->getMessage() . "\n";
        exit(1);
    }

    Database::execute('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
    $ran++;
}

echo $ran === 0
    ? "Nothing to migrate.\n"
    : "Done. {$ran} migration(s) applied.\n";

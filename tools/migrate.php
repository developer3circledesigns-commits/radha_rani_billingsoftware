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

/**
 * MySQL error codes that mean "the thing this statement was creating is already
 * there", which for a migration is the desired end state rather than a failure.
 *
 * This matters because database/init.sql is the authoritative schema and already
 * contains everything the migration files add. A database created from it has an
 * empty schema_migrations table, so the runner replays 001 and 002 - and 002 is a
 * plain ALTER TABLE ... ADD COLUMN, which aborts on the first duplicate column.
 * Without this, the documented "run php tools/migrate.php" cannot be run on a
 * database that does not need it, which is the most common case of all.
 *
 * The version-agnostic alternative, ADD COLUMN IF NOT EXISTS, needs MySQL 8.0.29+
 * and is a syntax error on the older servers shared hosting still runs, so the
 * tolerance lives here instead of in the SQL.
 */
function is_benign_schema_error(PDOException $e): bool
{
    // 1050 table exists, 1060 duplicate column, 1061 duplicate key,
    // 1062 duplicate entry for a unique key, 1826 duplicate foreign key.
    return in_array($e->errorInfo[1] ?? 0, [1050, 1060, 1061, 1062, 1826], true);
}

$ran = 0;
$skipped = 0;

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
            try {
                $db->exec($statement);
            } catch (PDOException $e) {
                if (!is_benign_schema_error($e)) {
                    throw $e;
                }
                $skipped++;
                echo "    . already present, skipped\n";
            }
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
    : "Done. {$ran} migration(s) applied.\n"
        . ($skipped > 0 ? "{$skipped} statement(s) were already in place.\n" : '');

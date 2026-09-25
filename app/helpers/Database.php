<?php

declare(strict_types=1);

/**
 * Database access - PDO singleton.
 */

final class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                $detail = 'Database connection failed: ' . $e->getMessage();
                $target = 'Database connection target: ' . DB_USER . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME;
                $credentialsFile = dirname(ROOT_PATH) . '/radha-rani-credentials.php';
                if (DB_PASS === '') {
                    $hint = 'DB_PASS is EMPTY - no credentials file was loaded. Write the DB_* defines to '
                        . $credentialsFile . ' (outside the Git checkout, so no deploy can overwrite it) '
                        . 'or set the DB_* environment variables.';
                } else {
                    $hint = 'Check the credentials in ' . $credentialsFile
                        . ' or app/config/config.local.php, or the DB_* environment variables.';
                }
                if (DB_USER === 'radha' && DB_NAME === 'radha_rani') {
                    $hint .= ' The built-in development defaults are still in use, so no credentials file was found.';
                }
                error_log($detail);
                error_log($target);
                error_log($hint);

                $logEntry = sprintf(
                    "[%s] %s | %s | %s\n",
                    date('Y-m-d H:i:s'),
                    $detail,
                    $target,
                    $hint
                );
                if (!is_dir(LOG_PATH)) {
                    @mkdir(LOG_PATH, 0775, true);
                }
                @file_put_contents(LOG_PATH . '/app.log', $logEntry, FILE_APPEND);

                // CLI tools must not print an HTML page into the terminal.
                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, $detail . PHP_EOL);
                    fwrite(STDERR, '  ' . $target . PHP_EOL);
                    fwrite(STDERR, '  ' . $hint . PHP_EOL);
                    fwrite(STDERR, '  full details: ' . LOG_PATH . '/app.log' . PHP_EOL);
                    exit(1);
                }

                http_response_code(500);
                require APP_PATH . '/views/errors/500.php';
                exit;
            }
        }

        return self::$instance;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Run a statement.
     *
     * $lobIndexes lists zero-based positions in $params that hold binary
     * data (PDF blobs). Those are bound as PDO::PARAM_LOB instead of
     * being inferred as strings, so multi-megabyte PDFs always reach a
     * LONGBLOB column intact on any PDO/driver combination.
     */
    public static function execute(string $sql, array $params = [], array $lobIndexes = []): int
    {
        $stmt = self::connection()->prepare($sql);

        if ($lobIndexes === []) {
            $stmt->execute($params);
            return $stmt->rowCount();
        }

        // Every parameter must be bound explicitly. Binding only the LOB
        // positions and then calling execute() with no arguments is rejected
        // by mysqlnd with "Invalid parameter number" (HY093), because the
        // remaining placeholders were never bound.
        foreach ($params as $index => $value) {
            $position = $index + 1;

            if (in_array($index, $lobIndexes, true)) {
                // A NULL blob must be bound as NULL, not as a LOB: PARAM_LOB
                // expects a stream and fails on null.
                $type = $value === null ? PDO::PARAM_NULL : PDO::PARAM_LOB;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } else {
                $type = PDO::PARAM_STR;
            }

            $stmt->bindValue($position, $value, $type);
        }

        $stmt->execute();
        return $stmt->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::connection()->lastInsertId();
    }
}
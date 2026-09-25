<?php

declare(strict_types=1);

/**
 * Bill PDF storage gateway.
 *
 * Every bill PDF is written to two places:
 *   1. the filesystem under storage/uploads (primary - fast, streamed)
 *   2. the database bills.pdf_bytes column (safety copy)
 *
 * Reads always try the filesystem first and fall back to the database copy,
 * so a missing, moved or damaged file never costs the user their document.
 */
final class BillStorage
{
    /**
     * Slack left for MySQL protocol + statement overhead when a PDF is sent
     * inside a single INSERT. A blob can never be as large as the packet
     * limit itself.
     */
    public const PACKET_HEADROOM = 1048576; // 1 MB

    /** Never advertise less than this, whatever the packet size is. */
    private const MIN_UPLOAD_BYTES = 1048576; // 1 MB

    /** Cached per request. */
    private static ?int $maxPacket = null;

    /**
     * MySQL's max_allowed_packet, or 0 when it cannot be determined.
     */
    public static function maxPacketBytes(): int
    {
        if (self::$maxPacket === null) {
            try {
                $row = Database::fetch('SELECT @@max_allowed_packet AS p');
                self::$maxPacket = (int) ($row['p'] ?? 0);
            } catch (Throwable $e) {
                self::$maxPacket = 0;
            }
        }

        return self::$maxPacket;
    }

    /**
     * The largest PDF that can actually be stored in the database.
     *
     * Many shared hosts cap max_allowed_packet at 16 MB, which is BELOW the
     * portal's 20 MB limit. Uploads larger than this would fail at INSERT
     * time with an opaque driver error, so the effective cap is applied
     * during validation instead.
     */
    public static function effectiveMaxUploadBytes(): int
    {
        $packet = self::maxPacketBytes();

        // Unknown (no permission to read the variable) - trust the config.
        if ($packet <= 0) {
            return MAX_FILE_SIZE;
        }

        $usable = $packet - self::PACKET_HEADROOM;
        if ($usable < self::MIN_UPLOAD_BYTES) {
            return self::MIN_UPLOAD_BYTES;
        }

        return min(MAX_FILE_SIZE, $usable);
    }

    /**
     * Root of live uploads.
     */
    public static function uploadRoot(): string
    {
        return UPLOAD_PATH;
    }

    /** Root of archived (soft-deleted) uploads. */
    public static function archiveRoot(): string
    {
        return ROOT_PATH . '/storage/archive/bills';
    }

    /**
     * Resolve a stored relative path to an absolute path, refusing anything
     * that escapes the uploads root (path traversal guard).
     */
    public static function absoluteFor(string $relativePath): ?string
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '') {
            return null;
        }

        $root = realpath(self::uploadRoot());
        $full = realpath(ROOT_PATH . '/' . $relativePath);

        if ($root === false || $full === false) {
            return null;
        }
        if (!is_file($full)) {
            return null;
        }

        // Must sit inside the uploads root (use DIRECTORY_SEPARATOR on Windows too).
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        if (strncmp($full, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        return $full;
    }

    /**
     * The primary on-disk copy for a bill, if it is present and intact.
     */
    public static function fileCopy(array $bill): ?array
    {
        $absolute = self::absoluteFor((string) ($bill['file_path'] ?? ''));
        if ($absolute === null) {
            return null;
        }

        $size = @filesize($absolute);
        if ($size === false) {
            return null;
        }

        $expected = (int) ($bill['file_size'] ?? 0);
        if ($expected > 0 && $size !== $expected) {
            // Truncated or replaced file - do not trust it, fall back to the DB copy.
            return null;
        }

        return ['path' => $absolute, 'size' => (int) $size];
    }

    /**
     * Open a readable stream for a bill's PDF, preferring the filesystem and
     * falling back to the database copy.
     *
     * Returns null when neither copy is available.
     *
     * @return array{stream: resource, size: int, source: string, path: ?string}
     */
    public static function openRead(array $bill): ?array
    {
        $file = self::fileCopy($bill);
        if ($file !== null) {
            $fp = @fopen($file['path'], 'rb');
            if ($fp !== false) {
                return [
                    'stream' => $fp,
                    'size'   => $file['size'],
                    'source' => 'file',
                    'path'   => $file['path'],
                ];
            }
        }

        return self::openFromDatabase($bill);
    }

    /**
     * Fallback: stream the database copy.
     *
     * The blob is copied into php://temp so large PDFs do not sit in PHP
     * memory, and so download.php keeps byte-range support.
     *
     * @return array{stream: resource, size: int, source: string, path: ?string}|null
     */
    public static function openFromDatabase(array $bill): ?array
    {
        $id = (int) ($bill['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $row = Database::fetch('SELECT pdf_bytes FROM bills WHERE id = ?', [$id]);
        if (!$row || $row['pdf_bytes'] === null || $row['pdf_bytes'] === '') {
            return null;
        }

        $bytes = (string) $row['pdf_bytes'];
        $size  = strlen($bytes);

        $fp = fopen('php://temp/maxmemory:' . (1024 * 1024) . '', 'r+b');
        if ($fp === false) {
            return null;
        }
        fwrite($fp, $bytes);
        rewind($fp);

        return [
            'stream' => $fp,
            'size'   => $size,
            'source' => 'db',
            'path'   => null,
        ];
    }

    /**
     * True when the database copy is usable for a bill.
     */
    public static function hasDatabaseCopy(array $bill): bool
    {
        $id = (int) ($bill['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        $row = Database::fetch('SELECT pdf_bytes IS NOT NULL AS has FROM bills WHERE id = ?', [$id]);
        return $row !== null && (int) $row['has'] === 1;
    }

    /**
     * Compute the storage_status value for a bill given what actually landed.
     */
    public static function statusFor(bool $fileSaved, bool $dbSaved): string
    {
        if ($fileSaved && $dbSaved) {
            return 'both';
        }
        if ($fileSaved) {
            return 'file_only';
        }
        if ($dbSaved) {
            return 'db_only';
        }
        return 'none';
    }

    /**
     * Make sure every directory the portal writes to exists and is writable.
     *
     * Shared hosts frequently do not preserve empty folders through FTP, so
     * this is re-checked before each upload instead of relying on the
     * deploy-time mkdir.
     *
     * @return string[] Names of the directories that are missing or not writable
     */
    public static function ensureStorageTree(): array
    {
        $bad = [];

        $dirs = [
            'storage/uploads'   => UPLOAD_PATH,
            'storage/archive'   => ROOT_PATH . '/storage/archive/bills',
            'storage/logs'      => LOG_PATH,
        ];

        foreach ($dirs as $label => $path) {
            if (!self::ensureDirectory($path)) {
                $bad[] = $label;
            }
        }

        return $bad;
    }

    /**
     * Ensure a directory exists and is writable.
     */
    public static function ensureDirectory(string $absolute): bool
    {
        if (is_dir($absolute)) {
            return is_writable($absolute);
        }
        return @mkdir($absolute, 0775, true) && is_writable($absolute);
    }

    /**
     * Build the per-branch storage path for a new upload.
     */
    public static function buildUploadPath(string $branchCode, string $paymentType, string $storedName): array
    {
        $year  = date('Y');
        $month = date('m');

        $relDir = 'storage/uploads/branches/' . $branchCode . '/' . $paymentType . '/' . $year . '/' . $month;
        $absDir = ROOT_PATH . '/' . $relDir;
        $abs    = $absDir . '/' . $storedName;
        $rel    = $relDir . '/' . $storedName;

        return ['rel' => $rel, 'abs' => $abs, 'dir' => $absDir];
    }

    /**
     * Move a bill's file copy into the archive area and return the stored
     * relative archive path (or null when there was no file to archive).
     */
    public static function archiveFile(array $bill): ?string
    {
        $absolute = self::absoluteFor((string) ($bill['file_path'] ?? ''));
        if ($absolute === null) {
            return null;
        }

        $relDir = 'storage/archive/bills/' . (int) $bill['id'];
        $absDir = ROOT_PATH . '/' . $relDir;
        if (!self::ensureDirectory($absDir)) {
            return null;
        }

        $name = basename((string) ($bill['stored_filename'] ?? ($bill['file_path'] ?? 'document.pdf')));
        $target = $absDir . '/' . $name;

        if (!@rename($absolute, $target)) {
            // Cross-device rename can fail; fall back to copy + unlink.
            if (!@copy($absolute, $target)) {
                return null;
            }
            @unlink($absolute);
        }

        return $relDir . '/' . $name;
    }

    /**
     * Move an archived file back to its original uploads location.
     * Returns true when the file was restored, false when there is no file
     * to move back (the database copy will still serve the bill).
     */
    public static function restoreFile(array $bill): bool
    {
        $archived = trim((string) ($bill['archived_path'] ?? ''));
        if ($archived === '') {
            return false;
        }

        $relDir  = dirname(ltrim((string) $bill['file_path'], '/'));
        $absDir  = ROOT_PATH . '/' . $relDir;
        $absPath = ROOT_PATH . '/' . ltrim((string) $bill['file_path'], '/');

        if (!self::ensureDirectory($absDir)) {
            return false;
        }

        $from = ROOT_PATH . '/' . ltrim($archived, '/');
        if (!is_file($from)) {
            return false;
        }

        if (@rename($from, $absPath)) {
            return true;
        }
        if (@copy($from, $absPath)) {
            @unlink($from);
            return true;
        }
        return false;
    }

    /**
     * Delete a bill's file copy (used by permanent purge).
     */
    public static function deleteFile(array $bill): void
    {
        $absolute = self::absoluteFor((string) ($bill['file_path'] ?? ''));
        if ($absolute !== null) {
            @unlink($absolute);
        }

        $archived = trim((string) ($bill['archived_path'] ?? ''));
        if ($archived !== '') {
            $abs = ROOT_PATH . '/' . ltrim($archived, '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }
}

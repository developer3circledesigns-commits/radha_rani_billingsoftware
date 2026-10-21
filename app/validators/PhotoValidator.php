<?php

declare(strict_types=1);

/**
 * Camera photo validation.
 *
 * The browser normalises every capture to JPEG before it is sent, so only
 * image/jpeg is accepted here. Pixel bounds reject both thumbnails that would
 * be unreadable as evidence and decompression bombs; the bytes themselves are
 * never decoded by PHP - they are embedded into the PDF verbatim.
 */
final class PhotoValidator
{
    public const MAX_SIZE = MAX_FILE_SIZE;
    public const MIN_DIMENSION = 320;
    public const MAX_DIMENSION = 8000;
    public const MAX_PIXELS = 40000000;
    public const ALLOWED_EXT = ['jpg', 'jpeg', 'jpe', 'jfif'];

    /**
     * @return array{ok: bool, errors: string[], width: int, height: int, channels: int, error_code: int}
     */
    public static function validate(array &$file, ?string $originalName = null): array
    {
        $errors = [];
        $errorCode = (int) ($file['error'] ?? -1);
        $fail = static function (array $messages) use ($errorCode): array {
            return ['ok' => false, 'errors' => $messages, 'width' => 0, 'height' => 0, 'channels' => 0, 'error_code' => $errorCode];
        };

        if (!isset($file['error'])) {
            return $fail([t('api.no_file_received')]);
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $fail([t('api.photo_too_large', ['size' => format_bytes(effective_upload_bytes())])]);
            case UPLOAD_ERR_PARTIAL:
                return $fail([t('api.partial_upload')]);
            case UPLOAD_ERR_NO_FILE:
                return $fail([t('api.no_photo_selected')]);
            case UPLOAD_ERR_NO_TMP_DIR:
                return $fail([t('api.photo_tmp_missing')]);
            case UPLOAD_ERR_CANT_WRITE:
                return $fail([t('api.photo_write_failed')]);
            case UPLOAD_ERR_EXTENSION:
                return $fail([t('api.photo_blocked')]);
            default:
                return $fail([t('api.upload_failed_retry')]);
        }

        if ((int) $file['size'] === 0) {
            $errors[] = t('api.empty_file');
        }

        if ((int) $file['size'] > self::MAX_SIZE) {
            $errors[] = t('api.photo_too_large', ['size' => format_bytes(self::MAX_SIZE)]);
        }

        // fileinfo is the preferred check, but some shared hosts disable it.
        // In that case fall through to the JPEG signature + getimagesize()
        // checks below instead of fatally erroring on "new finfo".
        $mime = '';
        if (class_exists('finfo')) {
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($file['tmp_name']);
            } catch (Throwable $e) {
                $mime = '';
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string) (@mime_content_type($file['tmp_name']) ?: '');
        }

        $genericMime = ['', 'application/octet-stream', 'binary/octet-stream'];
        if (!in_array($mime, $genericMime, true) && !in_array($mime, ['image/jpeg', 'image/pjpeg'], true)) {
            $errors[] = t('api.only_photo_allowed');
        }

        $ext = strtolower(pathinfo($originalName ?? $file['name'], PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, self::ALLOWED_EXT, true)) {
            $errors[] = t('api.only_photo_allowed');
        }

        $handle = @fopen($file['tmp_name'], 'rb');
        $sig = $handle ? (string) fread($handle, 3) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($sig !== "\xFF\xD8\xFF") {
            $errors[] = t('api.invalid_photo');
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            $errors[] = t('api.invalid_photo');
            return $fail(array_values(array_unique($errors)));
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $channels = (int) ($info['channels'] ?? 3);

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            $errors[] = t('api.photo_too_small', ['min' => self::MIN_DIMENSION]);
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || ($width * $height) > self::MAX_PIXELS) {
            $errors[] = t('api.photo_dimensions_too_large');
        }
        if (!in_array($channels, [1, 3, 4], true)) {
            $errors[] = t('api.only_photo_allowed');
        }

        return [
            'ok'         => empty($errors),
            'errors'     => array_values(array_unique($errors)),
            'width'      => $width,
            'height'     => $height,
            'channels'   => $channels,
            'error_code' => $errorCode,
        ];
    }
}

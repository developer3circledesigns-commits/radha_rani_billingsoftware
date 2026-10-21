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
    public const ALLOWED_EXT = ['jpg', 'jpeg'];

    /**
     * @return array{ok: bool, errors: string[], width: int, height: int, channels: int}
     */
    public static function validate(array &$file, ?string $originalName = null): array
    {
        $errors = [];

        if (!isset($file['error'])) {
            return ['ok' => false, 'errors' => [t('api.no_file_received')], 'width' => 0, 'height' => 0, 'channels' => 0];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'errors' => [t('api.photo_too_large', ['size' => format_bytes(self::MAX_SIZE)])], 'width' => 0, 'height' => 0, 'channels' => 0];
            case UPLOAD_ERR_PARTIAL:
                return ['ok' => false, 'errors' => [t('api.partial_upload')], 'width' => 0, 'height' => 0, 'channels' => 0];
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'errors' => [t('api.no_photo_selected')], 'width' => 0, 'height' => 0, 'channels' => 0];
            default:
                return ['ok' => false, 'errors' => [t('api.upload_failed_retry')], 'width' => 0, 'height' => 0, 'channels' => 0];
        }

        if ((int) $file['size'] === 0) {
            $errors[] = t('api.empty_file');
        }

        if ((int) $file['size'] > self::MAX_SIZE) {
            $errors[] = t('api.photo_too_large', ['size' => format_bytes(self::MAX_SIZE)]);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime !== 'image/jpeg') {
            $errors[] = t('api.only_photo_allowed');
        }

        $ext = strtolower(pathinfo($originalName ?? $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
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
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'width' => 0, 'height' => 0, 'channels' => 0];
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
        if ($channels !== 1 && $channels !== 3) {
            $errors[] = t('api.only_photo_allowed');
        }

        return [
            'ok'       => empty($errors),
            'errors'   => array_values(array_unique($errors)),
            'width'    => $width,
            'height'   => $height,
            'channels' => $channels,
        ];
    }
}

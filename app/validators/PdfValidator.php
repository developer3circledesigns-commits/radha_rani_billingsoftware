<?php

declare(strict_types=1);

/**
 * PDF upload validation.
 */

final class PdfValidator
{
    public const MAX_SIZE = MAX_FILE_SIZE; // bytes
    public const ALLOWED_EXT = 'pdf';

    /**
     * @return array{ok: bool, errors: string[]}
     */
    public static function validate(array &$file, string $originalName = null): array
    {
        $errors = [];

        if (!isset($file['error'])) {
            return ['ok' => false, 'errors' => [t('api.no_file_received')]];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'errors' => [t('api.file_too_large', ['size' => format_bytes(self::MAX_SIZE)])]];
            case UPLOAD_ERR_PARTIAL:
                return ['ok' => false, 'errors' => [t('api.partial_upload')]];
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'errors' => [t('api.no_file_selected')]];
            default:
                return ['ok' => false, 'errors' => [t('api.upload_failed_retry')]];
        }

        if ((int) $file['size'] === 0) {
            $errors[] = t('api.empty_file');
        }

        if ((int) $file['size'] > self::MAX_SIZE) {
            $errors[] = t('api.file_too_large', ['size' => format_bytes(self::MAX_SIZE)]);
        }

        // MIME validation
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            $errors[] = t('api.only_pdf_allowed');
        }

        // Extension validation
        $ext = strtolower(pathinfo($originalName ?? $file['name'], PATHINFO_EXTENSION));
        if ($ext !== self::ALLOWED_EXT) {
            $errors[] = t('api.only_pdf_allowed');
        }

        // Signature check (first 5 bytes of a PDF: %PDF-)
        $handle = fopen($file['tmp_name'], 'rb');
        $sig = fread($handle, 5);
        fclose($handle);
        if ($sig !== '%PDF-') {
            $errors[] = t('api.invalid_pdf_document');
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }
}
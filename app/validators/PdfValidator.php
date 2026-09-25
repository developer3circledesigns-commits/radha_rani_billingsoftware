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
            return ['ok' => false, 'errors' => ['Upload error: no file received.']];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'errors' => ['PDF file is larger than the allowed limit (' . format_bytes(self::MAX_SIZE) . ').']];
            case UPLOAD_ERR_PARTIAL:
                return ['ok' => false, 'errors' => ['The file was only partially uploaded. Please retry.']];
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'errors' => ['No file was selected. Please choose a PDF.']];
            default:
                return ['ok' => false, 'errors' => ['Upload failed. Please retry.']];
        }

        if ((int) $file['size'] === 0) {
            $errors[] = 'Empty files are not allowed.';
        }

        if ((int) $file['size'] > self::MAX_SIZE) {
            $errors[] = 'PDF file is larger than the allowed limit (' . format_bytes(self::MAX_SIZE) . ').';
        }

        // MIME validation
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            $errors[] = 'Only PDF files are allowed.';
        }

        // Extension validation
        $ext = strtolower(pathinfo($originalName ?? $file['name'], PATHINFO_EXTENSION));
        if ($ext !== self::ALLOWED_EXT) {
            $errors[] = 'Only PDF files are allowed.';
        }

        // Signature check (first 5 bytes of a PDF: %PDF-)
        $handle = fopen($file['tmp_name'], 'rb');
        $sig = fread($handle, 5);
        fclose($handle);
        if ($sig !== '%PDF-') {
            $errors[] = 'The file does not appear to be a valid PDF document.';
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }
}
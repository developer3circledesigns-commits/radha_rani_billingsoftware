<?php

declare(strict_types=1);

/**
 * Wraps a JPEG photo in a single-page PDF, without GD or any extension.
 *
 * The JPEG is embedded as a DCTDecode XObject, which is the PDF-native way to
 * carry JPEG bytes: the file is copied verbatim, so there is no re-compression
 * and no image library to depend on. That matters on shared hosting where GD
 * may be missing and on the 20 MB upload limit, where decoding a phone photo
 * into raw pixels would need hundreds of megabytes of memory.
 *
 * The page orientation follows the photo: landscape photos get an A4 landscape
 * page so a till slip photographed sideways still fills the page.
 */
final class BillPhotoPdf
{
    private const A4_SHORT = 595.28;
    private const A4_LONG = 841.89;
    private const MARGIN = 18.0;

    /**
     * @param array{title?: string} $meta
     */
    public static function fromJpeg(string $jpeg, array $meta = []): string
    {
        $info = @getimagesizefromstring($jpeg);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException('Not a JPEG image.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $channels = (int) ($info['channels'] ?? 3);

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('JPEG has no usable dimensions.');
        }
        if ($channels !== 1 && $channels !== 3) {
            throw new InvalidArgumentException('Unsupported JPEG colour space.');
        }

        $colourSpace = $channels === 1 ? 'DeviceGray' : 'DeviceRGB';

        if ($width >= $height) {
            $pageW = self::A4_LONG;
            $pageH = self::A4_SHORT;
        } else {
            $pageW = self::A4_SHORT;
            $pageH = self::A4_LONG;
        }

        $maxW = $pageW - (self::MARGIN * 2);
        $maxH = $pageH - (self::MARGIN * 2);
        $scale = min($maxW / $width, $maxH / $height);
        $drawW = $width * $scale;
        $drawH = $height * $scale;
        $x = ($pageW - $drawW) / 2;
        $y = ($pageH - $drawH) / 2;

        $content = "q\n"
            . self::num($drawW) . ' 0 0 ' . self::num($drawH) . ' '
            . self::num($x) . ' ' . self::num($y) . " cm\n"
            . "/Im0 Do\n"
            . "Q\n";

        $image = '<< /Type /XObject /Subtype /Image'
            . ' /Width ' . $width
            . ' /Height ' . $height
            . ' /ColorSpace /' . $colourSpace
            . ' /BitsPerComponent 8'
            . ' /Filter /DCTDecode'
            . ' /Length ' . strlen($jpeg)
            . " >>\nstream\n" . $jpeg . "\nendstream";

        $title = self::pdfString((string) ($meta['title'] ?? 'Photo bill'));

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R'
                . ' /MediaBox [0 0 ' . self::num($pageW) . ' ' . self::num($pageH) . ']'
                . ' /Resources << /XObject << /Im0 5 0 R >> >>'
                . ' /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
            5 => $image,
            6 => '<< /Title (' . $title . ')'
                . ' /Producer (Radha Rani Hotel Portal)'
                . ' /Creator (Radha Rani Hotel Portal)'
                . ' /CreationDate (' . self::pdfDate() . ') >>',
        ];

        return self::assemble($objects);
    }

    /**
     * @param array<int,string> $objects
     */
    private static function assemble(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $count = count($objects);

        for ($i = 1; $i <= $count; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R /Info 6 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function pdfDate(): string
    {
        $offset = date('O');
        return 'D:' . date('YmdHis')
            . substr($offset, 0, 3) . "'" . substr($offset, 3, 2) . "'";
    }

    /**
     * PDF literal strings are byte-oriented; non-ASCII bytes are replaced so a
     * Unicode filename cannot corrupt the metadata object.
     */
    private static function pdfString(string $value): string
    {
        $value = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        $value = substr($value, 0, 120);
        return addcslashes($value, "\\()");
    }

    private function __construct() {}
}

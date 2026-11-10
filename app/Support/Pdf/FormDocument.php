<?php

namespace App\Support\Pdf;

use chillerlan\QRCode\QRCode;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use RuntimeException;

/**
 * Shared presentation primitives for the fixed ADASI print forms (reference
 * PNR261178): embedded font, measured line wrapping, form number formatting,
 * barcode/QR rendering and the letterhead block.
 *
 * Extracted from PurchaseOrderPdf so the QC report, the payment voucher and
 * the receipts share one geometry. Values returned here must stay identical to
 * the previous PO implementation; PurchaseOrderPdfTest locks the PO output.
 */
final class FormDocument
{
    /** Points per millimetre; the forms are laid out in millimetres. */
    public const MM = 72 / 25.4;

    /** Body line height in millimetres. */
    public const LINE_HEIGHT = 4.1;

    /** Note/footer line height in millimetres. */
    public const NOTE_LINE_HEIGHT = 3.8;

    /** Registered family name. Kept as-is: the PO stylesheet references it. */
    public const FONT_FAMILY = 'PO Noto Sans';

    private static ?FontMetrics $metrics = null;

    /**
     * Register the embedded form font once per process and return its metrics.
     */
    public static function metrics(): FontMetrics
    {
        if (self::$metrics instanceof FontMetrics) {
            return self::$metrics;
        }

        $metrics = (new Dompdf(new Options(config('dompdf.options', []))))->getFontMetrics();
        File::ensureDirectoryExists($metrics->getOptions()->getFontDir());

        foreach (['normal' => 'Regular', 'bold' => 'Bold'] as $weight => $file) {
            $registered = $metrics->registerFont(
                ['family' => self::FONT_FAMILY, 'style' => 'normal', 'weight' => $weight],
                self::asset("fonts/noto-sans/NotoSans-{$file}.ttf")
            );

            if (! $registered) {
                throw new RuntimeException('Unable to load the local form PDF font.');
            }
        }

        return self::$metrics = $metrics;
    }

    /**
     * Absolute font paths for the @font-face rules in the form stylesheet.
     *
     * @return array{font_regular: string, font_bold: string}
     */
    public static function fonts(): array
    {
        self::metrics();

        return [
            'font_regular' => self::asset('fonts/noto-sans/NotoSans-Regular.ttf'),
            'font_bold' => self::asset('fonts/noto-sans/NotoSans-Bold.ttf'),
        ];
    }

    /** Absolute filesystem path to a public asset, with forward slashes for dompdf. */
    public static function asset(string $path): string
    {
        return str_replace('\\', '/', public_path('assets/'.$path));
    }

    /**
     * Form number formatting: thousands separated, trailing zeros trimmed
     * above two decimals, '-' for a missing value.
     */
    public static function number(mixed $value, int $precision = 4): string
    {
        if ($value === null) {
            return '-';
        }

        $text = number_format((float) $value, $precision, '.', ',');

        if ($precision > 2) {
            $text = rtrim($text, '0');
            $fraction = strlen(substr(strrchr($text, '.'), 1));
            $text .= str_repeat('0', max(0, 2 - $fraction));
        }

        return $text;
    }

    /**
     * Break text into lines that fit $width millimetres at $size points,
     * measured with the embedded font. Long unbroken words split by character
     * so a value can never overflow its cell.
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $width, float $size = 9): array
    {
        $metrics = self::metrics();
        $font = $metrics->getFont(self::FONT_FAMILY, 'normal');
        $lines = [];

        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $candidate = $line.$word;
                if ($line !== '' && $metrics->getTextWidth($candidate, $font, $size) > $width * self::MM) {
                    $lines[] = rtrim($line);
                    $line = '';
                }
                foreach (mb_str_split($word) as $character) {
                    $candidate = $line.$character;
                    if ($line !== '' && $metrics->getTextWidth($candidate, $font, $size) > $width * self::MM) {
                        $lines[] = rtrim($line);
                        $line = '';
                    }
                    $line .= $character;
                }
                $line .= ' ';
            }
            $lines[] = rtrim($line);
        }

        return $lines ?: ['-'];
    }

    /**
     * Code 128 barcode for a document number, as an inline SVG data URI plus
     * the printed width in millimetres.
     *
     * @return array{barcode: string, barcode_width: float}
     */
    public static function barcode(string $value): array
    {
        $barcode = (new TypeCode128)->getBarcode($value);
        $svg = (new SvgRenderer)
            ->setSvgType(SvgRenderer::TYPE_SVG_INLINE)
            ->render($barcode, max(125, $barcode->getWidth()), 34);

        return [
            'barcode' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'barcode_width' => max(33.08, $barcode->getWidth() * 0.22),
        ];
    }

    /**
     * QR code data URI for a signed verification URL. Options match the ones
     * already used on the on-screen receipts so both surfaces scan the same.
     */
    public static function qr(string $url): string
    {
        return (new QRCode(['outputBase64' => true, 'scale' => 4]))->render($url);
    }

    /**
     * Letterhead block shared by every form.
     *
     * @return array{name: string, address: string, phone: ?string, email: ?string, website: ?string, header_image: ?string}
     */
    public static function letterhead(): array
    {
        $image = (string) config('company.header_image', '');
        $path = $image === '' ? null : str_replace('\\', '/', public_path($image));

        return [
            'name' => (string) config('company.name', ''),
            'address' => (string) config('company.address', ''),
            'phone' => config('company.phone'),
            'email' => config('company.email'),
            'website' => config('company.website'),
            'header_image' => $path !== null && is_file($path) ? $path : null,
        ];
    }

    /** Controlled-document number for a form, or null when not yet assigned. */
    public static function formCode(string $form): ?string
    {
        $code = config('company.forms.'.$form.'.code');

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }
}

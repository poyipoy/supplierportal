<?php

namespace Tests\Unit\FileSecurity;

use App\Services\FileSecurity\FileInspectionService;
use App\Services\FileSecurity\PdfCompressionService;
use Tests\TestCase;

class PdfCompressionServiceTest extends TestCase
{
    public function test_returns_null_for_empty_non_pdf_corrupt_oversized_encrypted_signed_and_objstm_pdfs(): void
    {
        $service = app(PdfCompressionService::class);

        $this->assertNull($service->compressBytes(''));
        $this->assertNull($service->compressBytes('<html>fake</html>'));
        $this->assertNull($service->compressBytes("%PDF-1.4\ncorrupt-payload%%%EOF"));

        // > 20 MiB oversized file must be skipped by compressToTempFile.
        $tempOversized = tempnam(sys_get_temp_dir(), 'oversized-pdf-');
        $this->assertIsString($tempOversized);
        try {
            $handle = fopen($tempOversized, 'wb');
            fwrite($handle, "%PDF-1.4\n");
            fseek($handle, PdfCompressionService::MAX_INPUT_BYTES + 16);
            fwrite($handle, '0');
            fclose($handle);
            $this->assertNull($service->compressToTempFile($tempOversized));
        } finally {
            @unlink($tempOversized);
        }

        // Encrypted PDF (/Encrypt in trailer) must be skipped untouched.
        $jpeg = $this->makeNoisyRgbJpeg(1800, 1800, 95);
        $rgbPdf = $this->buildPdfWithImage($jpeg, 1800, 1800, 'DeviceRGB');
        $encryptedPdf = str_replace('trailer\n<<', "trailer\n<<", str_replace('/Root 1 0 R >>', '/Root 1 0 R /Encrypt 6 0 R >>', $rgbPdf));
        $this->assertNull($service->compressBytes($encryptedPdf));

        // Digitally signed PDF (/ByteRange or /Type /Sig) must never be rewritten.
        $signedPdf = $this->buildPdfWithImage($jpeg, 1800, 1800, 'DeviceRGB', null, '/Type /Sig /ByteRange [0 100 200 300]');
        $this->assertNull($service->compressBytes($signedPdf));

        // PDF with ObjStm must fall back cleanly.
        $objStmPdf = $this->buildPdfWithImage($jpeg, 1800, 1800, 'DeviceRGB', null, '/Type /ObjStm');
        $this->assertNull($service->compressBytes($objStmPdf));
    }

    public function test_skips_pdfs_above_25000_objects(): void
    {
        $service = app(PdfCompressionService::class);

        $out = "%PDF-1.4\n";
        $offsets = [0];
        $offsets[] = strlen($out);
        $out .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offsets[] = strlen($out);
        $out .= "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $count = PdfCompressionService::MAX_PDF_OBJECTS + 1;
        for ($i = 3; $i <= $count; $i++) {
            $offsets[] = strlen($out);
            $out .= "{$i} 0 obj\n{$i}\nendobj\n";
        }
        $xref = strlen($out);
        $size = $count + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $this->assertNull($service->compressBytes($out));
    }

    public function test_preserves_cmyk_smask_mask_and_small_streams_untouched(): void
    {
        $service = app(PdfCompressionService::class);

        $cmykPayload = str_repeat("\xAB", 130_000);
        $cmykPdf = $this->buildPdfWithImage($cmykPayload, 2000, 2000, 'DeviceCMYK');
        $this->assertNull($service->compressBytes($cmykPdf));

        $largeJpeg = $this->makeNoisyRgbJpeg(600, 600, 95);
        $this->assertGreaterThan(PdfCompressionService::MIN_RECOMPRESS_STREAM_BYTES, strlen($largeJpeg));

        $smaskPdf = $this->buildPdfWithImage($largeJpeg, 600, 600, 'DeviceRGB', 'SMask');
        $this->assertNull($service->compressBytes($smaskPdf));

        $maskPdf = $this->buildPdfWithImage($largeJpeg, 600, 600, 'DeviceRGB', 'Mask');
        $this->assertNull($service->compressBytes($maskPdf));

        $smallJpeg = $this->makeNoisyRgbJpeg(120, 120, 75);
        $this->assertLessThan(PdfCompressionService::MIN_RECOMPRESS_STREAM_BYTES, strlen($smallJpeg));
        $smallPdf = $this->buildPdfWithImage($smallJpeg, 120, 120, 'DeviceRGB');
        $this->assertNull($service->compressBytes($smallPdf));
    }

    public function test_recompresses_large_rgb_and_gray_jpeg_streams_with_valid_xref(): void
    {
        $service = app(PdfCompressionService::class);

        $largeRgb = $this->makeNoisyRgbJpeg(2200, 2200, 95);
        $this->assertGreaterThan(PdfCompressionService::MIN_RECOMPRESS_STREAM_BYTES, strlen($largeRgb));
        $rgbPdf = $this->buildPdfWithImage($largeRgb, 2200, 2200, 'DeviceRGB');

        $compressedRgb = $service->compressBytes($rgbPdf, 1000);
        $this->assertNotNull($compressedRgb);
        $this->assertStringStartsWith('%PDF-', $compressedRgb);
        $this->assertLessThan(strlen($rgbPdf), strlen($compressedRgb));
        $this->assertValidEnvelopeBytes($compressedRgb);

        $this->assertMatchesRegularExpression('/\/Width 1000\b/', $compressedRgb);
        $this->assertMatchesRegularExpression('/\/Height 1000\b/', $compressedRgb);
        $this->assertMatchesRegularExpression('/\/ColorSpace \/DeviceRGB\b/', $compressedRgb);
        $this->assertSame("\xFF\xD8\xFF", substr($this->extractFirstImageStream($compressedRgb), 0, 3));

        // High-res grayscale JPEG (> 120 KB) scales down and syncs /ColorSpace to actual output JPEG channels.
        $largeGray = $this->makeNoisyGrayJpeg(2200, 2200, 95);
        $this->assertGreaterThan(PdfCompressionService::MIN_RECOMPRESS_STREAM_BYTES, strlen($largeGray));
        $grayPdf = $this->buildPdfWithImage($largeGray, 2200, 2200, 'DeviceGray');

        $compressedGray = $service->compressBytes($grayPdf, 1000);
        $this->assertNotNull($compressedGray);
        $this->assertLessThan(strlen($grayPdf), strlen($compressedGray));
        $this->assertValidEnvelopeBytes($compressedGray);

        $outJpeg = $this->extractFirstImageStream($compressedGray);
        $info = getimagesizefromstring($outJpeg);
        $this->assertSame(1000, $info[0]);
        $this->assertSame(1000, $info[1]);
        $expectedColorSpace = ($info['channels'] ?? 3) === 1 ? 'DeviceGray' : 'DeviceRGB';
        $this->assertMatchesRegularExpression("/\/ColorSpace \/{$expectedColorSpace}\b/", $compressedGray);
    }

    public function test_handles_indirect_length_and_compresses_raw_uncompressed_bitmap_images(): void
    {
        $service = app(PdfCompressionService::class);

        $rawBitmap = str_repeat("\x10\x20\x30", 5000);
        $pdf = $this->buildRawBitmapPdfWithIndirectLength($rawBitmap);

        $compressed = $service->compressBytes($pdf);
        $this->assertNotNull($compressed);
        $this->assertLessThan(strlen($pdf), strlen($compressed));
        $this->assertValidEnvelopeBytes($compressed);
        $this->assertMatchesRegularExpression('/\/Filter \/FlateDecode\b/', $compressed);
        $this->assertSame($rawBitmap, gzuncompress($this->extractFirstImageStream($compressed)));
    }

    public function test_compress_to_temp_file_happy_path_and_boundary_guards(): void
    {
        $service = app(PdfCompressionService::class);
        $this->assertNull($service->compressToTempFile(sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonexistent-'.uniqid().'.pdf'));

        $emptyPath = tempnam(sys_get_temp_dir(), 'empty-pdf-');
        $this->assertIsString($emptyPath);
        try {
            $this->assertNull($service->compressToTempFile($emptyPath));
        } finally {
            @unlink($emptyPath);
        }

        $bloated = $this->buildPdfWithImage($this->makeNoisyRgbJpeg(2200, 2200, 95), 2200, 2200, 'DeviceRGB');
        $sourcePath = tempnam(sys_get_temp_dir(), 'src-pdf-');
        $this->assertIsString($sourcePath);
        $tempCompressed = null;
        try {
            file_put_contents($sourcePath, $bloated);
            $tempCompressed = $service->compressToTempFile($sourcePath);
            $this->assertIsString($tempCompressed);
            $expectedPrefix = DIRECTORY_SEPARATOR === '\\' ? 'ada' : 'adasi-pdf-';
            $this->assertStringStartsWith($expectedPrefix, basename($tempCompressed));
            $this->assertLessThan(strlen($bloated), filesize($tempCompressed));
            FileInspectionService::assertPdfEnvelope($tempCompressed);
        } finally {
            @unlink($sourcePath);
            if (is_string($tempCompressed)) {
                @unlink($tempCompressed);
            }
        }
    }

    public function test_protects_signed_efaktur_variants_decompression_bombs_and_scalar_endobj_and_cr_comments(): void
    {
        $service = app(PdfCompressionService::class);
        $jpeg = $this->makeNoisyRgbJpeg(1800, 1800, 95);

        // Signature variants without explicit /Type /Sig (e.g. /FT /Sig, /SigFlags, /DocMDP, /Perms, leading % comment).
        foreach ([
            '/FT /Sig /SubFilter /adbe.pkcs7.detached',
            '/SigFlags 3',
            '/DocMDP << /P 2 >>',
            '/Perms << /DocMDP 6 0 R >>',
        ] as $sigFragment) {
            $signed = $this->buildPdfWithImage($jpeg, 1800, 1800, 'DeviceRGB', null, $sigFragment);
            $this->assertNull($service->compressBytes($signed), "Failed to protect {$sigFragment}");
        }

        // Leading % comment before signature dictionary must still parse dict and abort compression.
        $basePdf = $this->buildPdfWithImage($jpeg, 1800, 1800, 'DeviceRGB');
        $commentedSigPdf = str_replace(
            '<< /Type /Catalog /Pages 2 0 R >>',
            "% e-Faktur catalog comment\n<< /Type /Catalog /Pages 2 0 R /Perms << /DocMDP 9 0 R >> >>",
            $basePdf
        );
        // Recompute xref offsets cleanly via helper when modifying catalog length:
        $this->assertNull($service->compressBytes($this->rebuildValidXref($commentedSigPdf)));

        // Decompression bomb: JPEG declaring width/height above MAX_DECODE_PIXELS (e.g. 5000x4000 = 20MP > 16MP).
        $bombIm = imagecreate(5000, 4000);
        imagecolorallocate($bombIm, 10, 20, 30);
        ob_start();
        imagejpeg($bombIm, null, 75);
        $smallBombJpeg = ob_get_clean();
        unset($bombIm);
        $this->assertIsString($smallBombJpeg);
        // Inject valid JPEG COM segment (0xFF 0xFE) before SOS so byte size > 120 KB triggers recompression check.
        $comPayload = str_repeat('A', 60_000);
        $comSegment = "\xFF\xFE".pack('n', strlen($comPayload) + 2).$comPayload;
        $bloatedBombJpeg = substr($smallBombJpeg, 0, 2).$comSegment.$comSegment.substr($smallBombJpeg, 2);
        $bombPdf = $this->buildPdfWithImage($bloatedBombJpeg, 5000, 4000, 'DeviceRGB');
        $this->assertNull($service->compressBytes($bombPdf));

        // Standalone \r comment inside dictionary + indirect scalar string containing literal "endobj" must still compress cleanly.
        $crCommentPdf = $this->buildPdfWithImageAndCommentScalar($this->makeNoisyRgbJpeg(2200, 2200, 95));
        $compressedCr = $service->compressBytes($crCommentPdf, 900);
        $this->assertNotNull($compressedCr);
        $this->assertStringContainsString('(note endobj inside string)', $compressedCr);
        $this->assertValidEnvelopeBytes($compressedCr);

        // Indirect /SMask 7 0 R protecting a large DeviceGray JPEG object must preserve object 7 stream intact.
        $grayMask = $this->makeNoisyGrayJpeg(1800, 1800, 95);
        $rgbMain = $this->makeNoisyRgbJpeg(2200, 2200, 95);
        $smaskPdf = $this->buildPdfWithIndirectGraySmask($rgbMain, $grayMask);
        $compressedSmask = $service->compressBytes($smaskPdf, 900);
        $this->assertNotNull($compressedSmask);
        $this->assertStringContainsString($grayMask, $compressedSmask);
    }

    public function test_handles_incremental_prev_xref_chain_circular_loop_and_dim_clamping(): void
    {
        $service = app(PdfCompressionService::class);
        $largeRgb = $this->makeNoisyRgbJpeg(2600, 2600, 95);
        $basePdf = $this->buildPdfWithImage($largeRgb, 2600, 2600, 'DeviceRGB');

        // Clamping: 400 -> 800, 5000 -> 2400.
        $clampedMin = $service->compressBytes($basePdf, 400);
        $this->assertNotNull($clampedMin);
        $this->assertMatchesRegularExpression('/\/Width 800\b/', $clampedMin);

        $clampedMax = $service->compressBytes($basePdf, 5000);
        $this->assertNotNull($clampedMax);
        $this->assertMatchesRegularExpression('/\/Width 2400\b/', $clampedMax);

        // Append an incremental update pointing /Prev back to the base xref offset.
        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $basePdf, $m));
        $baseXrefOffset = (int) $m[1];
        $appendObjPos = strlen($basePdf);
        $incremental = $basePdf."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $incXrefOffset = strlen($incremental);
        $incremental .= "xref\n1 1\n".sprintf("%010d 00000 n \n", $appendObjPos);
        $incremental .= "trailer\n<< /Size 6 /Root 1 0 R /Prev {$baseXrefOffset} >>\nstartxref\n{$incXrefOffset}\n%%EOF\n";

        $compressedInc = $service->compressBytes($incremental, 1000);
        $this->assertNotNull($compressedInc);
        $this->assertStringNotContainsString('/Prev', $compressedInc);
        $this->assertValidEnvelopeBytes($compressedInc);

        // Circular /Prev loop pointing to itself must abort cleanly returning null.
        $circular = str_replace("/Prev {$baseXrefOffset}", "/Prev {$incXrefOffset}", $incremental);
        $this->assertNull($service->compressBytes($circular));
    }

    private function assertValidEnvelopeBytes(string $pdfBytes): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'assert-pdf-');
        $this->assertIsString($tmp);
        try {
            file_put_contents($tmp, $pdfBytes);
            FileInspectionService::assertPdfEnvelope($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function extractFirstImageStream(string $pdf): string
    {
        $pos = strpos($pdf, '/Subtype /Image');
        $this->assertNotFalse($pos);
        $dictSlice = substr($pdf, max(0, $pos - 100), 400);
        $this->assertMatchesRegularExpression('/\/Length\s+(\d+)/', $dictSlice, $dictSlice);
        preg_match('/\/Length\s+(\d+)/', $dictSlice, $match);
        $length = (int) $match[1];
        $streamKeyword = strpos($pdf, "stream\n", $pos);
        $this->assertNotFalse($streamKeyword);

        return substr($pdf, $streamKeyword + 7, $length);
    }

    private function makeNoisyRgbJpeg(int $width, int $height, int $quality): string
    {
        $im = imagecreatetruecolor($width, $height);
        // Generate deterministic colorful noisy vertical stripes & blocks so GD JPEG > 120 KB cleanly and quickly.
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorallocate(
                $im,
                ($x * 37) % 256,
                ($x * 53 + 41) % 256,
                ($x * 71 + 97) % 256,
            );
            imageline($im, $x, 0, $x, $height - 1, $color);
        }
        for ($y = 0; $y < $height; $y += 7) {
            $color = imagecolorallocate($im, ($y * 19) % 256, ($y * 43) % 256, ($y * 89) % 256);
            imageline($im, 0, $y, $width - 1, $y, $color);
        }

        ob_start();
        imagejpeg($im, null, $quality);
        $bytes = ob_get_clean();
        unset($im);

        $this->assertIsString($bytes);

        return $bytes;
    }

    private function makeNoisyGrayJpeg(int $width, int $height, int $quality): string
    {
        $im = imagecreate($width, $height);
        $palette = [];
        for ($i = 0; $i < 256; $i++) {
            $palette[$i] = imagecolorallocate($im, $i, $i, $i);
        }
        for ($x = 0; $x < $width; $x++) {
            imageline($im, $x, 0, $x, $height - 1, $palette[($x * 41) % 256]);
        }
        for ($y = 0; $y < $height; $y += 7) {
            imageline($im, 0, $y, $width - 1, $y, $palette[($y * 29) % 256]);
        }

        ob_start();
        imagejpeg($im, null, $quality);
        $bytes = ob_get_clean();
        unset($im);

        $this->assertIsString($bytes);

        return $bytes;
    }

    private function buildPdfWithImage(
        string $imageBytes,
        int $width,
        int $height,
        string $colorSpace,
        ?string $extraMaskKey = null,
        string $extraDictFragment = '',
    ): string {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
        ];

        $imgDict = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /{$colorSpace} /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($imageBytes);
        if ($extraMaskKey !== null) {
            $objects[6] = "<< /Length 4 >>\nstream\n\xFF\xFF\xFF\xFF\nendstream";
            $imgDict .= " /{$extraMaskKey} 6 0 R";
        }
        if ($extraDictFragment !== '') {
            $imgDict .= " {$extraDictFragment}";
        }
        $imgDict .= ' >>';

        $objects[4] = "{$imgDict}\nstream\n{$imageBytes}\nendstream";
        $content = 'q 100 0 0 100 0 0 cm /Im0 Do Q';
        $objects[5] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";

        ksort($objects);
        $out = "%PDF-1.5\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $maxId = max(array_keys($objects));
        $xrefOffset = strlen($out);
        $size = $maxId + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function buildRawBitmapPdfWithIndirectLength(string $rawBitmap): string
    {
        $content = 'q 100 0 0 100 0 0 cm /Im0 Do Q';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => "<< /Type /XObject /Subtype /Image /Width 100 /Height 50 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length 6 0 R >>\nstream\n{$rawBitmap}\nendstream",
            5 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            6 => (string) strlen($rawBitmap),
        ];

        $out = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefOffset = strlen($out);
        $out .= "xref\n0 7\n0000000000 65535 f \n";
        for ($id = 1; $id <= 6; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out."trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function rebuildValidXref(string $pdf): string
    {
        preg_match_all('/^(\d+)\s+0\s+obj\b/m', $pdf, $matches, PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($matches[1] as $index => $match) {
            $offsets[(int) $match[0]] = $matches[0][$index][1];
        }
        $maxId = max(array_keys($offsets));
        $xrefPos = strpos($pdf, "\nxref\n");
        $prefix = substr($pdf, 0, $xrefPos + 1);
        $newXref = strlen($prefix);
        $size = $maxId + 1;
        $table = "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $table .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $prefix.$table."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$newXref}\n%%EOF\n";
    }

    private function buildPdfWithImageAndCommentScalar(string $imageBytes): string
    {
        $content = 'q 100 0 0 100 0 0 cm /Im0 Do Q';
        $objects = [
            1 => "<< % standalone CR comment\r/Type /Catalog /Pages 2 0 R /Note 6 0 R >>",
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /XObject /Subtype /Image /Width 2200 /Height 2200 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($imageBytes)." >>\nstream\n{$imageBytes}\nendstream",
            5 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            6 => '(note endobj inside string)',
        ];

        $out = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefOffset = strlen($out);
        $out .= "xref\n0 7\n0000000000 65535 f \n";
        for ($id = 1; $id <= 6; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out."trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function buildPdfWithIndirectGraySmask(string $rgbBytes, string $grayMaskBytes): string
    {
        $content = 'q 100 0 0 100 0 0 cm /Im0 Do Q';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R /ImMask 7 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /XObject /Subtype /Image /Width 2200 /Height 2200 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /SMask 7 0 R /Length '.strlen($rgbBytes)." >>\nstream\n{$rgbBytes}\nendstream",
            5 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            6 => '<< /Type /XObject /Subtype /Image /Width 2200 /Height 2200 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($rgbBytes)." >>\nstream\n{$rgbBytes}\nendstream",
            7 => '<< /Type /XObject /Subtype /Image /Width 1800 /Height 1800 /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($grayMaskBytes)." >>\nstream\n{$grayMaskBytes}\nendstream",
        ];

        $out = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefOffset = strlen($out);
        $out .= "xref\n0 8\n0000000000 65535 f \n";
        for ($id = 1; $id <= 7; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out."trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}

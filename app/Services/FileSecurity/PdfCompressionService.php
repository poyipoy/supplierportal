<?php

namespace App\Services\FileSecurity;

use Throwable;

/**
 * Pure PHP PDF stream compressor using ext-gd and ext-zlib.
 *
 * Parses classic xref PDFs safely without scanning binary streams with regex,
 * recompresses large DCTDecode (JPEG) image streams, deflates raw uncompressed
 * bitmap image streams (/Subtype /Image), rebuilds canonical 20-byte xref tables,
 * and falls back transparently when compression does not reduce file size or when
 * the PDF uses encryption, digital signatures, or compressed object/xref streams.
 */
final class PdfCompressionService
{
    public const MAX_INPUT_BYTES = 20 * 1024 * 1024;

    public const MAX_PDF_OBJECTS = 25_000;

    public const MIN_RECOMPRESS_STREAM_BYTES = 120_000;

    public const MIN_FLATE_STREAM_BYTES = 512;

    public const DEFAULT_MAX_DIM = 1600;

    public const MIN_DIM_CLAMP = 800;

    public const MAX_DIM_CLAMP = 2400;

    public const MAX_DECODE_IMAGE_DIM = 8_000;

    public const MAX_DECODE_PIXELS = 16_000_000;

    public const MAX_TOTAL_DECODE_PIXELS = 32_000_000;

    public const JPEG_RECOMPRESS_QUALITY = 80;

    private const MAX_XREF_REVISIONS = 64;

    /**
     * Compress an already-validated PDF into a temporary file when doing so
     * strictly reduces byte size; returns null if the original file should stay.
     */
    public function compressToTempFile(string $sourcePath, int $maxDim = self::DEFAULT_MAX_DIM): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('gzcompress')) {
            return null;
        }
        if (! is_file($sourcePath) || is_link($sourcePath) || ! is_readable($sourcePath)) {
            return null;
        }

        $originalSize = filesize($sourcePath);
        if (! is_int($originalSize) || $originalSize <= 0 || $originalSize > self::MAX_INPUT_BYTES) {
            return null;
        }

        $tempPath = null;
        try {
            $pdf = file_get_contents($sourcePath);
            if (! is_string($pdf) || strlen($pdf) !== $originalSize) {
                return null;
            }

            $compressed = $this->compressBytes($pdf, $maxDim);
            if ($compressed === null || strlen($compressed) >= $originalSize) {
                return null;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'adasi-pdf-');
            if (! is_string($tempPath)) {
                return null;
            }

            if (file_put_contents($tempPath, $compressed) !== strlen($compressed)) {
                @unlink($tempPath);

                return null;
            }
            FileInspectionService::assertPdfEnvelope($tempPath);

            return $tempPath;
        } catch (Throwable) {
            if (is_string($tempPath) && is_file($tempPath)) {
                @unlink($tempPath);
            }

            return null;
        }
    }

    /**
     * Compress PDF bytes in memory; returns null cleanly on unsupported structures or no reduction.
     */
    public function compressBytes(string $pdf, int $maxDim = self::DEFAULT_MAX_DIM): ?string
    {
        if (! preg_match('/^%PDF-(1\.[0-7]|2\.0)[\r\n ]/', $pdf, $headerMatch)) {
            return null;
        }

        $parsedXref = $this->parseXrefChain($pdf);
        if ($parsedXref === null) {
            return null;
        }

        [$entries, $primaryTrailer] = $parsedXref;
        if ($entries === [] || count($entries) > self::MAX_PDF_OBJECTS) {
            return null;
        }
        if ($this->dictionaryHasUnsafeFeatures($primaryTrailer)) {
            return null;
        }

        $objects = $this->parseActiveObjects($pdf, $entries);
        if ($objects === null) {
            return null;
        }

        $protectedMaskIds = [];
        foreach ($objects as $object) {
            $inspectText = $object['dict'] ?? $object['raw_body'];
            if ($inspectText !== null && $this->dictionaryHasUnsafeFeatures($inspectText)) {
                return null;
            }
            if ($object['dict'] !== null) {
                $this->collectMaskTargetIds($object['dict'], $protectedMaskIds);
            }
        }

        $safeDim = max(self::MIN_DIM_CLAMP, min(self::MAX_DIM_CLAMP, $maxDim));
        $remainingPixelBudget = self::MAX_TOTAL_DECODE_PIXELS;
        $changed = false;

        foreach ($objects as $objNum => &$object) {
            if ($object['dict'] === null || $object['stream'] === null) {
                continue;
            }
            if ($this->optimizeStreamObject($objNum, $object, $safeDim, $protectedMaskIds, $remainingPixelBudget)) {
                $changed = true;
            }
        }
        unset($object);

        if (! $changed) {
            return null;
        }

        $rebuilt = $this->serializePdf($headerMatch[1], $entries, $objects, $primaryTrailer);
        if ($rebuilt === null || strlen($rebuilt) >= strlen($pdf)) {
            return null;
        }

        return $rebuilt;
    }

    /**
     * Parse classic xref sections and follow any /Prev incremental chain.
     *
     * @return array{0: array<int, array{gen: int, in_use: bool, offset: int}>, 1: string}|null
     */
    private function parseXrefChain(string $pdf): ?array
    {
        $length = strlen($pdf);
        $tailLen = min($length, 65536);
        $tail = substr($pdf, $length - $tailLen);
        if (! preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $tail, $match)) {
            return null;
        }

        $offset = (int) $match[1];
        $entries = [];
        $primaryTrailer = null;
        $visited = [];
        $revisions = 0;

        while ($offset > 0) {
            if (++$revisions > self::MAX_XREF_REVISIONS || isset($visited[$offset]) || $offset >= $length) {
                return null;
            }
            $visited[$offset] = true;

            $section = $this->parseSingleXrefSection($pdf, $offset, $entries);
            if ($section === null) {
                return null;
            }

            [$trailerDict, $prevOffset] = $section;
            $primaryTrailer ??= $trailerDict;

            if (count($entries) > self::MAX_PDF_OBJECTS) {
                return null;
            }
            if ($this->dictionaryHasAnyKey($trailerDict, ['Encrypt', 'XRefStm'])) {
                return null;
            }

            $offset = $prevOffset ?? 0;
        }

        if ($primaryTrailer === null) {
            return null;
        }

        return [$entries, $primaryTrailer];
    }

    /**
     * @param  array<int, array{gen: int, in_use: bool, offset: int}>  $entries
     * @return array{0: string, 1: ?int}|null
     */
    private function parseSingleXrefSection(string $pdf, int $offset, array &$entries): ?array
    {
        $slice = substr($pdf, $offset, 4096);
        if (! preg_match('/^xref[ \t]*(?:\r\n|\r|\n)/', $slice, $xrefMatch)) {
            return null;
        }

        $pos = $offset + strlen($xrefMatch[0]);
        $pdfLen = strlen($pdf);

        while ($pos < $pdfLen) {
            $pos = $this->skipHorizontalAndNewlines($pdf, $pos);
            if ($pos >= $pdfLen) {
                return null;
            }
            if (preg_match('/\Gtrailer\b/', $pdf, $m, 0, $pos)) {
                $dictStart = strpos($pdf, '<<', $pos + 7);
                if ($dictStart === false) {
                    return null;
                }
                $dictEnd = $this->findBalancedDictionaryEnd($pdf, $dictStart);
                if ($dictEnd === null) {
                    return null;
                }
                $trailerDict = substr($pdf, $dictStart, $dictEnd - $dictStart);
                $prev = preg_match('/\/Prev\s+(\d+)\b/', $trailerDict, $prevMatch) ? (int) $prevMatch[1] : null;

                return [$trailerDict, $prev];
            }

            $lineEnd = $this->readLine($pdf, $pos, $line);
            if ($lineEnd === null || ! preg_match('/^(\d+)\s+(\d+)$/', trim($line), $subMatch)) {
                return null;
            }
            $pos = $lineEnd;

            $startId = (int) $subMatch[1];
            $count = (int) $subMatch[2];
            if ($count < 0 || $count > self::MAX_PDF_OBJECTS || count($entries) + $count > self::MAX_PDF_OBJECTS) {
                return null;
            }

            for ($i = 0; $i < $count; $i++) {
                $nextPos = $this->readLine($pdf, $pos, $entryLine);
                if ($nextPos === null || ! preg_match('/^(\d{10})\s+(\d{5})\s+([nf])$/', trim($entryLine), $rowMatch)) {
                    return null;
                }
                $pos = $nextPos;
                $id = $startId + $i;
                if (! isset($entries[$id])) {
                    $entries[$id] = [
                        'offset' => (int) $rowMatch[1],
                        'gen' => (int) $rowMatch[2],
                        'in_use' => $rowMatch[3] === 'n',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{gen: int, in_use: bool, offset: int}>  $entries
     * @return array<int, array{id: int, gen: int, dict: ?string, stream: ?string, raw_body: ?string}>|null
     */
    private function parseActiveObjects(string $pdf, array $entries): ?array
    {
        $objects = [];
        $pdfLen = strlen($pdf);

        foreach ($entries as $id => $entry) {
            if ($id === 0 || ! $entry['in_use']) {
                continue;
            }

            $offset = $entry['offset'];
            if ($offset <= 0 || $offset >= $pdfLen) {
                return null;
            }

            if (! preg_match('/\G\s*(\d+)\s+(\d+)\s+obj\b/s', $pdf, $objHeader, 0, $offset)) {
                return null;
            }
            if ((int) $objHeader[1] !== $id || (int) $objHeader[2] !== $entry['gen']) {
                return null;
            }

            $cursor = $this->skipPdfWhitespaceAndComments($pdf, $offset + strlen($objHeader[0]), $pdfLen);

            if ($cursor + 1 >= $pdfLen || substr($pdf, $cursor, 2) !== '<<') {
                $endPos = $this->findNonDictionaryEndObj($pdf, $cursor, $pdfLen);
                if ($endPos === null) {
                    return null;
                }
                $objects[$id] = [
                    'id' => $id,
                    'gen' => $entry['gen'],
                    'dict' => null,
                    'stream' => null,
                    'raw_body' => rtrim(substr($pdf, $cursor, $endPos - $cursor)),
                ];

                continue;
            }

            $dictEnd = $this->findBalancedDictionaryEnd($pdf, $cursor);
            if ($dictEnd === null) {
                return null;
            }
            $dict = substr($pdf, $cursor, $dictEnd - $cursor);

            $afterDict = $dictEnd;
            while ($afterDict < $pdfLen && ctype_space($pdf[$afterDict])) {
                $afterDict++;
            }

            if (! preg_match('/\Gstream(?:\r\n|\n)/', $pdf, $streamMatch, 0, $afterDict)) {
                if (! preg_match('/\Gendobj\b/', $pdf, $endMatch, 0, $afterDict)) {
                    return null;
                }
                $objects[$id] = [
                    'id' => $id,
                    'gen' => $entry['gen'],
                    'dict' => $dict,
                    'stream' => null,
                    'raw_body' => null,
                ];

                continue;
            }

            $streamLen = $this->resolveStreamLength($pdf, $dict, $entries);
            $streamStart = $afterDict + strlen($streamMatch[0]);
            if ($streamLen === null || $streamLen < 0 || $streamStart + $streamLen > $pdfLen) {
                return null;
            }

            $streamBytes = substr($pdf, $streamStart, $streamLen);
            $afterStream = $streamStart + $streamLen;
            if (! preg_match('/\G(?:\r\n|\r|\n)?endstream\s+endobj\b/s', $pdf, $endStreamMatch, 0, $afterStream)) {
                return null;
            }

            $objects[$id] = [
                'id' => $id,
                'gen' => $entry['gen'],
                'dict' => $dict,
                'stream' => $streamBytes,
                'raw_body' => null,
            ];
        }

        return $objects;
    }

    /**
     * @param  array<int, array{gen: int, in_use: bool, offset: int}>  $entries
     */
    private function resolveStreamLength(string $pdf, string $dict, array $entries): ?int
    {
        if (preg_match('/\/Length\s+(\d+)\s+(\d+)\s+R\b/', $dict, $refMatch)) {
            $refId = (int) $refMatch[1];
            $refGen = (int) $refMatch[2];
            $refEntry = $entries[$refId] ?? null;
            if ($refEntry === null || ! $refEntry['in_use'] || $refEntry['gen'] !== $refGen) {
                return null;
            }
            if (! preg_match('/\G\s*\d+\s+\d+\s+obj\s+(\d+)\s+endobj\b/s', $pdf, $scalarMatch, 0, $refEntry['offset'])) {
                return null;
            }

            return (int) $scalarMatch[1];
        }

        if (preg_match('/\/Length\s+(\d+)\b/', $dict, $directMatch)) {
            return (int) $directMatch[1];
        }

        return null;
    }

    /**
     * @param  array{id: int, gen: int, dict: ?string, stream: ?string, raw_body: ?string}  $object
     * @param  array<int, bool>  $protectedMaskIds
     */
    private function optimizeStreamObject(int $objNum, array &$object, int $safeDim, array $protectedMaskIds, int &$remainingPixelBudget): bool
    {
        $dict = $object['dict'];
        $stream = $object['stream'];
        if ($dict === null || $stream === null) {
            return false;
        }

        if ($this->isEligibleJpegImage($objNum, $dict, $stream, $protectedMaskIds)) {
            return $this->recompressJpegStream($object, $safeDim, $remainingPixelBudget);
        }

        return $this->deflateUncompressedImageStream($object);
    }

    /**
     * @param  array<int, bool>  $protectedMaskIds
     */
    private function isEligibleJpegImage(int $objNum, string $dict, string $stream, array $protectedMaskIds): bool
    {
        if (strlen($stream) <= self::MIN_RECOMPRESS_STREAM_BYTES) {
            return false;
        }
        if (! preg_match('/\/Subtype\s*\/Image\b/', $dict)) {
            return false;
        }
        if (! preg_match('/\/Filter\s*(?:\/DCTDecode\b|\[\s*\/DCTDecode\s*\])/', $dict)) {
            return false;
        }
        if ($this->dictionaryHasAnyKey($dict, ['SMask', 'Mask', 'Decode', 'DecodeParms', 'F'])) {
            return false;
        }
        if (! preg_match('/\/ColorSpace\s*\/(DeviceRGB|DeviceGray)\b/', $dict, $colorMatch)) {
            return false;
        }
        if ($colorMatch[1] === 'DeviceGray' && isset($protectedMaskIds[$objNum])) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{id: int, gen: int, dict: string, stream: string, raw_body: ?string}  $object
     */
    private function recompressJpegStream(array &$object, int $safeDim, int &$remainingPixelBudget): bool
    {
        $info = @getimagesizefromstring($object['stream']);
        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            return false;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1
            || $width > self::MAX_DECODE_IMAGE_DIM
            || $height > self::MAX_DECODE_IMAGE_DIM
            || $width > intdiv(self::MAX_DECODE_PIXELS, $height)) {
            return false;
        }

        $pixels = $width * $height;
        if ($pixels > $remainingPixelBudget) {
            return false;
        }

        $targetW = $width;
        $targetH = $height;
        if ($width > $safeDim || $height > $safeDim) {
            if ($width >= $height) {
                $targetW = $safeDim;
                $targetH = max(1, (int) round($height * ($safeDim / $width)));
            } else {
                $targetH = $safeDim;
                $targetW = max(1, (int) round($width * ($safeDim / $height)));
            }
        }

        if (! $this->hasMemoryHeadroom($width, $height, $targetW, $targetH)) {
            return false;
        }

        $remainingPixelBudget -= $pixels;

        $decoded = @imagecreatefromstring($object['stream']);
        if (! $decoded instanceof \GdImage) {
            return false;
        }

        $scaled = null;
        $working = null;
        try {
            $working = $decoded;
            if (! imageistruecolor($working)) {
                imagepalettetotruecolor($working);
            }

            if ($targetW !== $width || $targetH !== $height) {
                $scaled = imagecreatetruecolor($targetW, $targetH);
                if (! $scaled instanceof \GdImage) {
                    return false;
                }
                if (! imagecopyresampled($scaled, $working, 0, 0, 0, 0, $targetW, $targetH, $width, $height)) {
                    return false;
                }
                $working = $scaled;
            }

            $obLevel = ob_get_level();
            ob_start();
            try {
                $encodedOk = imagejpeg($working, null, self::JPEG_RECOMPRESS_QUALITY);
                $newBytes = ob_get_clean();
            } finally {
                if (ob_get_level() > $obLevel) {
                    ob_end_clean();
                }
            }

            if (! $encodedOk || ! is_string($newBytes) || $newBytes === '' || ! str_starts_with($newBytes, "\xFF\xD8\xFF")) {
                return false;
            }
            if (strlen($newBytes) >= strlen($object['stream'])) {
                return false;
            }

            $outInfo = @getimagesizefromstring($newBytes);
            $outChannels = is_array($outInfo) ? ($outInfo['channels'] ?? 3) : 3;
            if ($outChannels !== 3 && $outChannels !== 1) {
                return false;
            }

            $dict = $this->upsertDictNameOrScalar($object['dict'], 'Width', (string) $targetW);
            $dict = $this->upsertDictNameOrScalar($dict, 'Height', (string) $targetH);
            $dict = $this->upsertDictNameOrScalar($dict, 'BitsPerComponent', '8');
            $dict = $this->upsertDictNameOrScalar($dict, 'Length', (string) strlen($newBytes));
            $dict = $this->upsertDictNameOrScalar($dict, 'ColorSpace', $outChannels === 1 ? '/DeviceGray' : '/DeviceRGB');

            $object['dict'] = $dict;
            $object['stream'] = $newBytes;

            return true;
        } finally {
            $working = null;
            unset($scaled, $decoded);
        }
    }

    /**
     * Losslessly Flate-compress raw uncompressed bitmap image streams (/Subtype /Image with no /Filter).
     *
     * @param  array{id: int, gen: int, dict: string, stream: string, raw_body: ?string}  $object
     */
    private function deflateUncompressedImageStream(array &$object): bool
    {
        $dict = $object['dict'];
        $stream = $object['stream'];
        if (strlen($stream) <= self::MIN_FLATE_STREAM_BYTES) {
            return false;
        }
        if (preg_match('/\/Subtype\s*\/Image\b/', $dict) !== 1
            || $this->dictionaryHasAnyKey($dict, ['Filter', 'DecodeParms', 'F'])) {
            return false;
        }

        $deflated = @gzcompress($stream, 6);
        if (! is_string($deflated) || $deflated === '' || strlen($deflated) >= strlen($stream)) {
            return false;
        }

        $dict = $this->upsertDictNameOrScalar($dict, 'Filter', '/FlateDecode');
        $dict = $this->upsertDictNameOrScalar($dict, 'Length', (string) strlen($deflated));
        $object['dict'] = $dict;
        $object['stream'] = $deflated;

        return true;
    }

    /**
     * @param  array<int, array{gen: int, in_use: bool, offset: int}>  $entries
     * @param  array<int, array{id: int, gen: int, dict: ?string, stream: ?string, raw_body: ?string}>  $objects
     */
    private function serializePdf(string $version, array $entries, array $objects, string $primaryTrailer): ?string
    {
        $maxId = max(array_keys($entries));
        if ($maxId < 1 || $maxId > self::MAX_PDF_OBJECTS) {
            return null;
        }

        $out = "%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n";
        $newOffsets = [];

        ksort($objects);
        foreach ($objects as $id => $object) {
            $newOffsets[$id] = strlen($out);
            if ($object['stream'] !== null && $object['dict'] !== null) {
                $out .= "{$id} {$object['gen']} obj\n{$object['dict']}\nstream\n{$object['stream']}\nendstream\nendobj\n";
            } elseif ($object['dict'] !== null) {
                $out .= "{$id} {$object['gen']} obj\n{$object['dict']}\nendobj\n";
            } else {
                $out .= "{$id} {$object['gen']} obj\n{$object['raw_body']}\nendobj\n";
            }
        }

        $xrefOffset = strlen($out);
        $size = $maxId + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";

        for ($id = 1; $id <= $maxId; $id++) {
            if (isset($objects[$id], $newOffsets[$id])) {
                $out .= sprintf("%010d %05d n \n", $newOffsets[$id], $objects[$id]['gen']);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }

        $trailer = $this->cleanTrailerDictionary($primaryTrailer, $size);
        if ($trailer === null) {
            return null;
        }

        $out .= "trailer\n{$trailer}\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $out;
    }

    private function cleanTrailerDictionary(string $trailerDict, int $size): ?string
    {
        if (! preg_match('/\/Root\s+\d+\s+\d+\s+R\b/', $trailerDict)) {
            return null;
        }

        $cleaned = preg_replace('/\/(?:Prev|XRefStm|Encrypt)\s+(?:\d+\s+\d+\s+R|\d+|<<.*?>>)/s', '', $trailerDict);
        if (! is_string($cleaned)) {
            return null;
        }

        return $this->upsertDictNameOrScalar($cleaned, 'Size', (string) $size);
    }

    /**
     * Find exact byte end (exclusive) of a top-level `<< ... >>` PDF dictionary.
     */
    private function findBalancedDictionaryEnd(string $pdf, int $start): ?int
    {
        $len = strlen($pdf);
        if ($start + 1 >= $len || substr($pdf, $start, 2) !== '<<') {
            return null;
        }

        $depth = 0;
        $i = $start;
        while ($i < $len) {
            $ch = $pdf[$i];
            if ($ch === '%') {
                $i += strcspn($pdf, "\r\n", $i);

                continue;
            }
            if ($ch === '(') {
                $i = $this->skipLiteralString($pdf, $i, $len);

                continue;
            }
            if ($ch === '<' && $i + 1 < $len && $pdf[$i + 1] === '<') {
                $depth++;
                $i += 2;

                continue;
            }
            if ($ch === '<') {
                $close = strpos($pdf, '>', $i + 1);
                $i = $close === false ? $len : $close + 1;

                continue;
            }
            if ($ch === '>' && $i + 1 < $len && $pdf[$i + 1] === '>') {
                $depth--;
                $i += 2;
                if ($depth === 0) {
                    return $i;
                }

                continue;
            }
            $i++;
        }

        return null;
    }

    private function skipLiteralString(string $pdf, int $start, int $len): int
    {
        $depth = 1;
        $i = $start + 1;
        while ($i < $len && $depth > 0) {
            if ($pdf[$i] === '\\') {
                $i += 2;

                continue;
            }
            if ($pdf[$i] === '(') {
                $depth++;
            } elseif ($pdf[$i] === ')') {
                $depth--;
            }
            $i++;
        }

        return $i;
    }

    private function dictionaryHasUnsafeFeatures(string $dict): bool
    {
        return $this->dictionaryHasAnyKey($dict, ['Encrypt', 'ByteRange', 'ObjStm', 'SigFlags', 'DocMDP', 'Perms', 'UR', 'UR3', 'FieldMDP'])
            || preg_match('/\/(?:Type\s*\/(?:Sig|ObjStm|XRef)|FT\s*\/Sig|SubFilter\s*\/(?:adbe\.|ETSI\.))\b/', $dict) === 1;
    }

    private function skipPdfWhitespaceAndComments(string $pdf, int $pos, int $len): int
    {
        while ($pos < $len) {
            if (ctype_space($pdf[$pos])) {
                $pos++;

                continue;
            }
            if ($pdf[$pos] === '%') {
                $pos += strcspn($pdf, "\r\n", $pos);

                continue;
            }
            break;
        }

        return $pos;
    }

    private function findNonDictionaryEndObj(string $pdf, int $cursor, int $len): ?int
    {
        $i = $cursor;
        while ($i < $len) {
            $ch = $pdf[$i];
            if ($ch === '%') {
                $i += strcspn($pdf, "\r\n", $i);

                continue;
            }
            if ($ch === '(') {
                $i = $this->skipLiteralString($pdf, $i, $len);

                continue;
            }
            if ($ch === '<') {
                if ($i + 1 < $len && $pdf[$i + 1] === '<') {
                    $dictEnd = $this->findBalancedDictionaryEnd($pdf, $i);
                    if ($dictEnd === null) {
                        return null;
                    }
                    $i = $dictEnd;

                    continue;
                }
                $close = strpos($pdf, '>', $i + 1);
                $i = $close === false ? $len : $close + 1;

                continue;
            }
            if ($ch === 'e'
                && substr_compare($pdf, 'endobj', $i, 6) === 0
                && ($i === $cursor || ctype_space($pdf[$i - 1]) || str_contains('[]()<>/', $pdf[$i - 1]))
                && ($i + 6 >= $len || ctype_space($pdf[$i + 6]) || $pdf[$i + 6] === '%')) {
                return $i;
            }
            $i++;
        }

        return null;
    }

    private function hasMemoryHeadroom(int $srcW, int $srcH, int $dstW, int $dstH): bool
    {
        $rawLimit = trim((string) ini_get('memory_limit'));
        if ($rawLimit === '' || $rawLimit === '-1') {
            return true;
        }
        $limitBytes = $this->parseIniByteLimit($rawLimit);
        if ($limitBytes <= 0) {
            return true;
        }
        $estimatedBytes = ($srcW * $srcH * 5) + ($dstW * $dstH * 5) + 8_388_608;

        return (memory_get_usage(true) + $estimatedBytes) <= $limitBytes;
    }

    private function parseIniByteLimit(string $value): int
    {
        if (! preg_match('/^(\d+)\s*([KMG])?$/i', trim($value), $match)) {
            return 0;
        }
        $bytes = (int) $match[1];
        $unit = strtoupper($match[2] ?? '');

        return match ($unit) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }

    /**
     * @param  array<int, bool>  $maskIds
     */
    private function collectMaskTargetIds(string $dict, array &$maskIds): void
    {
        if (preg_match_all('/\/(?:SMask|Mask)\s+(\d+)\s+\d+\s+R\b/', $dict, $matches)) {
            foreach ($matches[1] as $id) {
                $maskIds[(int) $id] = true;
            }
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function dictionaryHasAnyKey(string $dict, array $keys): bool
    {
        $pattern = '/\/(?:'.implode('|', array_map(static fn (string $k) => preg_quote($k, '/'), $keys)).')\b/';

        return preg_match($pattern, $dict) === 1;
    }

    private function upsertDictNameOrScalar(string $dict, string $key, string $value): string
    {
        $quotedKey = preg_quote($key, '/');
        $pattern = '/\/'.$quotedKey.'\b(?:\s+\d+\s+\d+\s+R|\s+\d+|\s*\/[^\s\/<>()\[\]]+)/';

        if (preg_match($pattern, $dict) === 1) {
            return (string) preg_replace($pattern, "/{$key} {$value}", $dict, 1);
        }

        $lastClose = strrpos($dict, '>>');
        if ($lastClose === false) {
            return $dict;
        }

        return substr($dict, 0, $lastClose)." /{$key} {$value} ".substr($dict, $lastClose);
    }

    private function skipHorizontalAndNewlines(string $pdf, int $pos): int
    {
        $len = strlen($pdf);
        while ($pos < $len && ($pdf[$pos] === ' ' || $pdf[$pos] === "\t" || $pdf[$pos] === "\r" || $pdf[$pos] === "\n")) {
            $pos++;
        }

        return $pos;
    }

    private function readLine(string $pdf, int $pos, ?string &$line): ?int
    {
        $len = strlen($pdf);
        if ($pos >= $len) {
            return null;
        }
        $end = $pos;
        while ($end < $len && $pdf[$end] !== "\n" && $pdf[$end] !== "\r") {
            $end++;
        }
        $line = substr($pdf, $pos, $end - $pos);
        if ($end < $len && $pdf[$end] === "\r") {
            $end++;
        }
        if ($end < $len && $pdf[$end] === "\n") {
            $end++;
        }

        return $end;
    }
}

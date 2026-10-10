<?php

namespace App\Services\FileSecurity;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use ZipArchive;

/** Bounded native validation, not antivirus scanning. */
class NativeArchiveInspector
{
    private ?float $outerDeadline = null;

    public function withDeadline(float $deadline): static
    {
        $this->outerDeadline = $deadline;

        return $this;
    }

    public function inspect(string $path, string $policy = 'po_zip'): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->reject('unsupported');
        }

        $limits = $this->limits($policy);
        $size = is_file($path) ? filesize($path) : false;
        if (! is_int($size) || $size < 22 || $size > (int) config('native_file_security.zip.max_upload_bytes')) {
            $this->reject('archive');
        }
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            $this->reject('read');
        }
        $deadline = microtime(true) + $limits['max_runtime_seconds'];
        try {
            // Limit libzip's initial allocation BEFORE ZipArchive::open(). A
            // forged EOCD count alone is insufficient: walk the directory too.
            if ($this->readExact($handle, 4) !== "PK\x03\x04") {
                $this->reject('archive');
            }
            $tailLength = min($size, 65557);
            $this->seek($handle, $size - $tailLength);
            $tail = $this->readExact($handle, $tailLength);
            $position = strrpos($tail, "PK\x05\x06");
            if ($position === false || strlen($tail) - $position < 22) {
                $this->reject('archive');
            }
            $end = unpack('vdisk/vdirectory_disk/vdisk_entries/ventries/Vdirectory_size/Vdirectory_offset/vcomment', substr($tail, $position + 4, 18));
            $endOffset = $size - $tailLength + $position;
            if ($position + 22 + $end['comment'] !== strlen($tail) || $end['disk'] !== 0 || $end['directory_disk'] !== 0) {
                $this->reject('archive');
            }
            $entries = $end['entries'];
            $directorySize = $end['directory_size'];
            $directoryOffset = $end['directory_offset'];
            $directoryEnd = $endOffset;
            if ($entries === 65535 || $directorySize === 0xFFFFFFFF || $directoryOffset === 0xFFFFFFFF) {
                if (! $limits['allow_zip64'] || PHP_INT_SIZE < 8 || $endOffset < 20) {
                    $this->reject('unsupported');
                }
                $this->seek($handle, $endOffset - 20);
                $locator = $this->readExact($handle, 20);
                if (substr($locator, 0, 4) !== "PK\x06\x07" || unpack('V', substr($locator, 4, 4))[1] !== 0 || unpack('V', substr($locator, 16, 4))[1] !== 1) {
                    $this->reject('archive');
                }
                $directoryEnd = $this->uint64(substr($locator, 8, 8));
                if ($directoryEnd < 0 || $directoryEnd + 56 > $endOffset - 20) {
                    $this->reject('archive');
                }
                $this->seek($handle, $directoryEnd);
                $record = $this->readExact($handle, 56);
                $recordSize = $this->uint64(substr($record, 4, 8));
                if (substr($record, 0, 4) !== "PK\x06\x06" || $recordSize < 44 || $recordSize > $limits['max_metadata_bytes'] || $directoryEnd + 12 + $recordSize !== $endOffset - 20 || unpack('V', substr($record, 16, 4))[1] !== 0 || unpack('V', substr($record, 20, 4))[1] !== 0) {
                    $this->reject('archive');
                }
                $entries = $this->uint64(substr($record, 32, 8));
                if ($entries !== $this->uint64(substr($record, 24, 8))) {
                    $this->reject('archive');
                }
                $directorySize = $this->uint64(substr($record, 40, 8));
                $directoryOffset = $this->uint64(substr($record, 48, 8));
            } elseif ($end['disk_entries'] !== $entries) {
                $this->reject('archive');
            }
            if ($entries < 1 || $entries > $limits['max_entries'] || $directorySize > $limits['max_metadata_bytes'] || $directoryOffset < 4 || $directoryOffset + $directorySize !== $directoryEnd) {
                $this->reject('budget');
            }
            $this->seek($handle, $directoryOffset);
            $manifest = [];
            $names = [];
            $basenames = [];
            $total = 0;
            $pdfCount = 0;
            while (ftell($handle) < $directoryEnd) {
                $this->checkDeadline($deadline);
                if (count($manifest) >= $limits['max_entries'] || $directoryEnd - ftell($handle) < 46) {
                    $this->reject('budget');
                }
                $header = $this->readExact($handle, 46);
                if (substr($header, 0, 4) !== "PK\x01\x02") {
                    $this->reject('archive');
                }
                $entry = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($header, 4));
                if ($entry['name'] < 1 || $entry['name'] > $limits['max_name_bytes'] || ftell($handle) + $entry['name'] + $entry['extra'] + $entry['comment'] > $directoryEnd) {
                    $this->reject('name');
                }
                $original = $this->readExact($handle, $entry['name']);
                $extra = $this->readExact($handle, $entry['extra']);
                $this->seek($handle, ftell($handle) + $entry['comment']);
                if ($entry['size'] === 0xFFFFFFFF || $entry['compressed'] === 0xFFFFFFFF || $entry['offset'] === 0xFFFFFFFF || $entry['disk'] === 65535) {
                    if (! $limits['allow_zip64']) {
                        $this->reject('unsupported');
                    }
                    $entry = $this->zip64Entry($entry, $extra);
                }
                if ($entry['disk'] !== 0 || ($entry['flags'] & ~0x080E) !== 0 || ! in_array($entry['method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true) || $directoryOffset < $entry['offset'] + 30 + $entry['compressed']) {
                    $this->reject('unsupported');
                }
                $normalized = $this->normalize($original);
                $directory = str_ends_with($normalized, '/');
                $mode = ($entry['external'] >> 16) & 0170000;
                if (! in_array($mode, [0, 0100000, 0040000], true) || ($entry['external'] & 0x400) !== 0) {
                    $this->reject('special');
                }
                if (($mode === 0040000 || ($entry['external'] & 0x10) !== 0) && ! $directory) {
                    $this->reject('special');
                }
                $name = basename(rtrim($normalized, '/'));
                $key = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
                if (isset($names[$key])) {
                    $this->reject('duplicate');
                }
                $names[$key] = true;
                $ignored = $directory || str_starts_with($normalized, '__MACOSX/') || $name === '.DS_Store';
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! $directory && in_array($extension, ['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2'], true)) {
                    $this->reject('nested');
                }
                if ($policy === 'po_zip' && ! $ignored) {
                    if ($extension !== 'pdf') {
                        $this->reject('type');
                    }
                    $baseKey = mb_convert_case($name, MB_CASE_FOLD, 'UTF-8');
                    if (isset($basenames[$baseKey])) {
                        $this->reject('duplicate');
                    }
                    $basenames[$baseKey] = true;
                    if (++$pdfCount > $limits['max_pdfs']) {
                        $this->reject('budget');
                    }
                }
                // Count even ignored regular members: they must not conceal
                // expansion metadata outside the independently bounded budget.
                if ($entry['compressed'] > $size) {
                    $this->reject('budget');
                }
                if ($entry['size'] > $limits['max_entry_bytes'] || $entry['size'] > $limits['max_total_bytes'] - $total) {
                    $this->reject($policy === 'po_zip' ? 'archive_storage' : 'budget');
                }
                $total += $entry['size'];
                $manifest[] = ['entry_index' => count($manifest), 'entry_name' => $original, 'name' => $name, 'normalized_name' => $normalized,
                    'declared_size' => $entry['size'], 'compressed_size' => $entry['compressed'], 'crc' => sprintf('%08x', $entry['crc']),
                    'method' => $entry['method'], 'directory' => $directory, 'ignored' => $ignored];
            }
            if (count($manifest) !== $entries || ftell($handle) !== $directoryEnd || ($policy === 'po_zip' && $pdfCount === 0)) {
                $this->reject('archive');
            }

            return $manifest;
        } finally {
            fclose($handle);
        }
    }

    public function extractPdfArchive(string $path, string $stagingDirectory, ?callable $onEntry = null, ?array $entryIndexes = null): array
    {
        $manifest = $this->inspect($path);
        if (! is_dir($stagingDirectory) || is_link($stagingDirectory)) {
            $this->reject('write');
        }
        $zip = $this->open($path, count($manifest));
        $limits = $this->limits('po_zip');
        $deadline = microtime(true) + $limits['max_runtime_seconds'];
        $total = 0;
        $files = [];
        $created = [];
        try {
            foreach ($manifest as $entry) {
                if ($entry['ignored'] || ($entryIndexes !== null && ! in_array($entry['entry_index'], $entryIndexes, true))) {
                    continue;
                }
                $target = $stagingDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.pdf';
                $output = fopen($target, 'xb');
                if (! is_resource($output)) {
                    $this->reject('write');
                }
                $created[] = $target;
                try {
                    $result = $this->streamEntry($zip, $entry, $limits, $deadline, $total, function (string $chunk) use ($output, $stagingDirectory) {
                        $free = disk_free_space($stagingDirectory);
                        if ($free === false || $free < strlen($chunk) + (int) config('native_file_security.zip.minimum_free_bytes', 0)) {
                            $this->reject('disk');
                        }
                        $remaining = $chunk;
                        while ($remaining !== '') {
                            $written = fwrite($output, $remaining);
                            if ($written === false || $written === 0) {
                                $this->reject('write');
                            }
                            $remaining = substr($remaining, $written);
                        }
                    });
                    if (! fflush($output)) {
                        $this->reject('write');
                    }
                } finally {
                    fclose($output);
                }
                FileInspectionService::assertPdfEnvelope($target);
                $file = ['path' => $target, 'name' => $entry['name'], 'file_size' => $result['file_size'], 'sha256' => $result['sha256'], 'entry_index' => $entry['entry_index']];
                $files[] = $file;
                if ($onEntry !== null) {
                    $onEntry($entry, $file);
                }
            }

            return $files;
        } catch (\Throwable $exception) {
            foreach ($created as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            throw $exception;
        } finally {
            $zip->close();
        }
    }

    public function inspectOffice(string $path, string $type): void
    {
        $manifest = $this->inspect($path, 'office');
        $paths = array_column($manifest, 'normalized_name');
        foreach (['[Content_Types].xml', '_rels/.rels', $type === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml'] as $required) {
            if (! in_array($required, $paths, true)) {
                $this->reject('office');
            }
        }
        $zip = $this->open($path, count($manifest));
        $limits = $this->limits('office');
        $deadline = microtime(true) + $limits['max_runtime_seconds'];
        $total = 0;
        try {
            foreach ($manifest as $entry) {
                if ($entry['directory']) {
                    continue;
                }
                if (preg_match('~(?:vbaproject|activex|embeddings)(?:[./]|$)~i', $entry['normalized_name'])) {
                    $this->reject('office');
                }
                $carry = '';
                $xml = in_array(strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)), ['xml', 'rels'], true);
                $temporary = null;
                $output = null;
                if ($xml) {
                    $directory = storage_path('app/private/native-security/xml');
                    File::ensureDirectoryExists($directory);
                    $temporary = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.xml';
                    $output = fopen($temporary, 'xb');
                    if (! is_resource($output)) {
                        $this->reject('write');
                    }
                }
                try {
                    $this->streamEntry($zip, $entry, $limits, $deadline, $total, function (string $chunk) use (&$carry, $xml, $output, $temporary) {
                        if ($xml) {
                            $window = $carry.$chunk;
                            if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $window)) {
                                $this->reject('office');
                            }
                            $carry = substr($window, -64);
                            $free = disk_free_space(dirname($temporary));
                            if ($free === false || $free < strlen($chunk) + (int) config('native_file_security.zip.minimum_free_bytes', 0)) {
                                $this->reject('disk');
                            }
                            $remaining = $chunk;
                            while ($remaining !== '') {
                                $written = fwrite($output, $remaining);
                                if ($written === false || $written === 0) {
                                    $this->reject('write');
                                }
                                $remaining = substr($remaining, $written);
                            }
                        }
                    });
                    if ($xml) {
                        if (! fflush($output)) {
                            $this->reject('write');
                        }
                        fclose($output);
                        $output = null;
                        $this->inspectXml($temporary, $type, $deadline);
                    }
                } finally {
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    if ($temporary && is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function inspectXml(string $path, string $type, float $deadline): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (! class_exists(\XMLReader::class)) {
            $this->reject('unsupported');
        }
        $reader = new \XMLReader;
        try {
            if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->reject('office');
            }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            $cellText = 0;
            $cellDepth = null;
            while ($reader->read()) {
                $this->checkDeadline($deadline);
                if (in_array($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY, \XMLReader::ENTITY_REF], true)) {
                    $this->reject('office');
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->hasAttributes) {
                    if ($reader->moveToFirstAttribute()) {
                        do {
                            if (in_array($reader->localName, ['ContentType', 'Target', 'Type'], true)
                                && preg_match('/macroEnabled|vbaProject|activeX|oleObject/i', $reader->value)) {
                                $this->reject('office');
                            }
                        } while ($reader->moveToNextAttribute());
                        $reader->moveToElement();
                    }
                }
                if ($type === 'xlsx' && $reader->nodeType === \XMLReader::ELEMENT) {
                    if ($reader->localName === 'row') {
                        $number = $reader->getAttribute('r');
                        if ($number !== null && (! ctype_digit($number) || (int) $number < 1 || (int) $number > 1048576)) {
                            $this->reject('budget');
                        }
                    }
                    if ($reader->localName === 'c') {
                        $reference = $reader->getAttribute('r');
                        if ($reference !== null) {
                            if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/', $reference, $parts)
                                || (int) $parts[2] > 1048576 || Coordinate::columnIndexFromString($parts[1]) > 16384) {
                                $this->reject('budget');
                            }
                        }
                    }
                    if (in_array($reader->localName, ['si', 'is'], true)) {
                        $cellText = 0;
                        $cellDepth = $reader->depth;
                    }
                }
                if ($type === 'xlsx' && in_array($reader->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA], true)) {
                    if ($cellDepth !== null) {
                        $cellText += mb_strlen($reader->value, 'UTF-8');
                        if ($cellText > 32767) {
                            $this->reject('budget');
                        }
                    }
                }
                if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $cellDepth) {
                    $cellDepth = null;
                }
            }
            if (libxml_get_errors()) {
                $this->reject('office');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function streamEntry(ZipArchive $zip, array $entry, array $limits, float $deadline, int &$total, callable $consume): array
    {
        $stream = method_exists($zip, 'getStreamIndex') ? $zip->getStreamIndex($entry['entry_index']) : $zip->getStream($entry['entry_name']);
        if (! is_resource($stream)) {
            $this->reject('read');
        }
        $bytes = 0;
        $sha = hash_init('sha256');
        $crc = hash_init('crc32b');
        try {
            while (! feof($stream)) {
                $this->checkDeadline($deadline);
                $chunk = fread($stream, $limits['chunk_bytes']);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    $this->reject('read');
                }
                $length = strlen($chunk);
                if ($length > $entry['declared_size'] - $bytes) {
                    $this->reject('corrupt');
                }
                if ($length > $limits['max_entry_bytes'] - $bytes || $length > $limits['max_total_bytes'] - $total) {
                    $this->reject($limits['policy'] === 'po_zip' ? 'archive_storage' : 'budget');
                }
                $bytes += $length;
                $total += $length;
                hash_update($sha, $chunk);
                hash_update($crc, $chunk);
                $consume($chunk);
            }
        } finally {
            fclose($stream);
        }
        if ($bytes !== $entry['declared_size'] || ! hash_equals($entry['crc'], hash_final($crc))) {
            $this->reject('corrupt');
        }

        return ['file_size' => $bytes, 'sha256' => hash_final($sha)];
    }

    private function open(string $path, int $expectedCount): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            $this->reject('unsupported');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->reject('archive');
        }
        if ($zip->numFiles !== $expectedCount) {
            $zip->close();
            $this->reject('archive');
        }

        return $zip;
    }

    private function normalize(string $name): string
    {
        if (! preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f:*?<>|]/', $name) || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[a-zA-Z]:/', $name)) {
            $this->reject('name');
        }
        $name = Normalizer::normalize(str_replace('\\', '/', $name), Normalizer::FORM_C);
        if ($name === false || str_contains($name, '//') || preg_match('~(?:^|/)[.](?:/|$)~', $name)) {
            $this->reject('name');
        }

        return $name;
    }

    private function limits(string $policy): array
    {
        $limits = config('native_file_security.'.($policy === 'po_zip' ? 'zip' : 'office'));
        if (! is_array($limits)) {
            $this->reject('configuration');
        }
        $limits['policy'] = $policy;
        $limits['chunk_bytes'] = min(1024 * 1024, max(4096, (int) config('native_file_security.zip.chunk_bytes', 65536)));
        foreach (['max_entries', 'max_entry_bytes', 'max_total_bytes', 'max_metadata_bytes', 'max_name_bytes', 'max_runtime_seconds'] as $key) {
            if (! isset($limits[$key]) || $limits[$key] <= 0) {
                $this->reject('configuration');
            }
        }

        return $limits;
    }

    private function readExact($stream, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $part = fread($stream, min(65536, $length - strlen($result)));
            if ($part === false || $part === '') {
                $this->reject('read');
            }
            $result .= $part;
        }

        return $result;
    }

    private function seek($stream, int $offset): void
    {
        if ($offset < 0 || fseek($stream, $offset) !== 0) {
            $this->reject('read');
        }
    }

    private function uint64(string $bytes): int
    {
        $value = unpack('Vlow/Vhigh', $bytes);
        if (PHP_INT_SIZE < 8 || $value['high'] > 0x7FFFFFFF) {
            $this->reject('budget');
        }

        return ($value['high'] << 32) | $value['low'];
    }

    private function zip64Entry(array $entry, string $extra): array
    {
        $offset = 0;
        $data = null;
        while ($offset + 4 <= strlen($extra)) {
            $field = unpack('vid/vsize', substr($extra, $offset, 4));
            $offset += 4;
            if ($offset + $field['size'] > strlen($extra)) {
                $this->reject('archive');
            }
            if ($field['id'] === 1) {
                $data = substr($extra, $offset, $field['size']);
                break;
            }
            $offset += $field['size'];
        }
        if ($data === null) {
            $this->reject('archive');
        }
        foreach (['size', 'compressed', 'offset'] as $key) {
            if ($entry[$key] === 0xFFFFFFFF) {
                if (strlen($data) < 8) {
                    $this->reject('archive');
                }
                $entry[$key] = $this->uint64(substr($data, 0, 8));
                $data = substr($data, 8);
            }
        }
        if ($entry['disk'] === 65535) {
            if (strlen($data) < 4) {
                $this->reject('archive');
            }
            $entry['disk'] = unpack('V', substr($data, 0, 4))[1];
        }

        return $entry;
    }

    private function checkDeadline(float $deadline): void
    {
        if (microtime(true) > min($deadline, $this->outerDeadline ?? $deadline)) {
            $this->reject('timeout');
        }
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['file' => __('file_security.errors.'.$reason)]);
    }
}

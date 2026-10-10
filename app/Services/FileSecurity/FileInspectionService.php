<?php

namespace App\Services\FileSecurity;

use App\Data\FileSecurity\FileInspectionResult;
use App\Models\FileInspection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Native, bounded format admission. This does not scan for malware. */
class FileInspectionService
{
    private ?Request $scopeRequest = null;

    private float $scopeStarted = 0;

    private array $uploadedPaths = [];

    public function __construct(private NativeArchiveInspector $archives) {}

    public function inspectUpload(UploadedFile $file, string $profile, string $field = 'file'): FileInspectionResult
    {
        if (! $file->isValid()) {
            $this->reject($field, 'read');
        }

        $this->scopeBudget($field);
        $this->uploadedPaths[$file->getPathname()] = true;
        $maxFiles = (int) (config('native_file_security.requests.max_files') ?? ini_get('max_file_uploads'));
        if ($maxFiles < 1 || count($this->uploadedPaths) > $maxFiles) {
            $this->reject($field, 'budget');
        }

        return $this->inspectPath($file->getPathname(), $file->getClientOriginalName(), $profile, $field);
    }

    public function inspectPath(string $path, string $originalName, string $profile, string $field = 'file'): FileInspectionResult
    {
        $this->scopeBudget($field);
        try {
            $result = $this->inspectPathUnchecked($path, $originalName, $profile, $field);
            $this->scopeBudget($field);

            return $result;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => array_values($exception->errors())[0]]);
        }
    }

    private function scopeBudget(string $field): void
    {
        $request = request();
        if ($this->scopeRequest !== $request) {
            $this->scopeRequest = $request;
            $this->scopeStarted = microtime(true);
            $this->uploadedPaths = [];
        }
        $seconds = (int) config('native_file_security.requests.max_inspection_seconds', 60);
        if ($seconds < 1 || microtime(true) > $this->scopeStarted + $seconds) {
            $this->reject($field, 'timeout');
        }
        $this->archives->withDeadline($this->scopeStarted + $seconds);
    }

    private function inspectPathUnchecked(string $path, string $originalName, string $profile, string $field): FileInspectionResult
    {
        $policy = config('native_file_security.profiles.'.$profile);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! is_array($policy) || (int) ($policy['max_bytes'] ?? 0) < 1 || ! in_array($extension, $policy['extensions'], true)) {
            $this->reject($field, 'type');
        }
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            $this->reject($field, 'read');
        }
        $bytes = filesize($path);
        if (! is_int($bytes) || $bytes <= 0 || $bytes > $policy['max_bytes']) {
            $this->reject($field, 'budget');
        }
        // Bound container metadata before native MIME/container readers see it.
        if (in_array($extension, ['zip', 'xlsx', 'docx'], true)) {
            $this->archives->inspect($path, $extension === 'zip' ? 'po_zip' : 'office');
        } elseif (in_array($extension, ['xls', 'doc'], true)) {
            app(OleContainerInspector::class)->inspect($path, $extension);
        }
        if (! class_exists(\finfo::class)) {
            $this->reject($field, 'unsupported');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $types = [
            'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
            'csv' => ['text/plain', 'text/csv', 'text/tab-separated-values', 'application/csv', 'application/octet-stream'],
        ];
        if (! is_string($mime) || ! in_array($mime, $types[$extension] ?? [], true)) {
            $this->reject($field, 'type');
        }
        try {
            if ($extension === 'pdf') {
                self::assertPdfEnvelope($path);
            } elseif (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                $image = @getimagesize($path);
                $width = $image[0] ?? 0;
                $height = $image[1] ?? 0;
                $expected = $extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
                if ($width < 1 || $height < 1 || ($image[2] ?? null) !== $expected
                    || $width > (int) config('native_file_security.images.max_width')
                    || $height > (int) config('native_file_security.images.max_height')
                    || $width > intdiv((int) config('native_file_security.images.max_pixels'), $height)) {
                    $this->reject($field, 'image');
                }
            } elseif (in_array($extension, ['xlsx', 'docx'], true)) {
                $this->archives->inspectOffice($path, $extension);
                $mime = $types[$extension][0];
            } elseif ($extension === 'zip') {
                $this->archives->inspect($path);
                $mime = 'application/zip';
            } else {
                $stream = fopen($path, 'rb');
                if (! is_resource($stream)) {
                    $this->reject($field, 'read');
                }
                try {
                    $header = fread($stream, min($bytes, 65536));
                    if ($header === false) {
                        $this->reject($field, 'read');
                    }
                    if (in_array($extension, ['xls', 'doc'], true)) {
                        if (! str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") || $bytes < 512) {
                            $this->reject($field, 'office');
                        }
                        app(OleContainerInspector::class)->inspect($path, $extension);
                        $mime = $types[$extension][0];
                    } elseif ((str_starts_with($header, "PK\x03\x04") || str_starts_with($header, "PK\x05\x06")) || str_starts_with($header, '%PDF-')
                        || str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
                        || preg_match('/^\s*(?:<!doctype\b|<html\b|<table\b|<\?xml\b|<\?php\b)/i', $header)
                        || (str_contains($header, "\0") && ! str_starts_with($header, "\xFF\xFE") && ! str_starts_with($header, "\xFE\xFF"))) {
                        $this->reject($field, 'type');
                    } else {
                        $mime = 'text/csv';
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => array_values($exception->errors())[0]]);
        }
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            $this->reject($field, 'read');
        }

        return new FileInspectionResult($profile, $mime, $bytes, $hash, config('native_file_security.policy_version'), $profile === 'po_zip' ? 'PENDING' : 'VALIDATED');
    }

    public static function assertPdfEnvelope(string $path): void
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw ValidationException::withMessages(['file' => __('file_security.errors.read')]);
        }
        try {
            $size = filesize($path);
            $header = fread($handle, 16);
            $tailBytes = min($size, max(1024, min(65536, (int) config('native_file_security.pdf.tail_bytes', 65536))));
            if (! is_int($size) || ! is_string($header) || ! preg_match('/^%PDF-(?:1\.[0-7]|2\.0)[\r\n ]/', $header)
                || fseek($handle, $size - $tailBytes) !== 0) {
                throw ValidationException::withMessages(['file' => __('file_security.errors.pdf')]);
            }
            $tail = fread($handle, $tailBytes);
            if (! is_string($tail) || ! preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $tail, $match)
                || strlen($match[1]) > 18 || (int) $match[1] >= $size || (int) $match[1] < 8
                || fseek($handle, (int) $match[1]) !== 0) {
                throw ValidationException::withMessages(['file' => __('file_security.errors.pdf')]);
            }
            $xref = fread($handle, 64);
            if (! is_string($xref) || ! preg_match('/^(?:xref\s|\d+\s+\d+\s+obj\b)/', $xref)) {
                throw ValidationException::withMessages(['file' => __('file_security.errors.pdf')]);
            }
        } finally {
            fclose($handle);
        }
    }

    public function storeUpload(UploadedFile $file, string $profile, string $desiredPrivatePath, string $field = 'file'): array
    {
        $result = $this->inspectUpload($file, $profile, $field);

        return $this->storeInspected($file->getPathname(), $file->getClientOriginalName(), $result, $desiredPrivatePath, $field);
    }

    public function storePath(string $path, string $name, string $profile, string $desiredPrivatePath, string $field = 'file'): array
    {
        return $this->storeInspected($path, $name, $this->inspectPath($path, $name, $profile, $field), $desiredPrivatePath, $field);
    }

    private function storeInspected(string $source, string $name, FileInspectionResult $result, string $path, string $field): array
    {
        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\') || preg_match('/[\x00-\x1f:]/', $path)) {
            $this->reject($field, 'name');
        }
        $disk = Storage::disk('private');
        if ($disk->exists($path)) {
            $this->reject($field, 'write');
        }
        $input = fopen($source, 'rb');
        if (! is_resource($input)) {
            $this->reject($field, 'read');
        }
        $inspection = null;
        try {
            $inspection = FileInspection::create(['profile' => $result->profile, 'status' => 'PENDING',
                'policy_version' => $result->policyVersion, 'file_path' => $path, 'file_size' => $result->fileSize,
                'file_type' => $result->mimeType, 'sha256' => $result->sha256]);
            if (! $disk->put($path, $input)) {
                $this->reject($field, 'write');
            }
            $stored = $disk->path($path);
            if (! is_file($stored) || filesize($stored) !== $result->fileSize || ! hash_equals($result->sha256, (string) hash_file('sha256', $stored))) {
                $this->reject($field, 'corrupt');
            }
            $inspection->update(['status' => $result->profile === 'po_zip' ? 'PENDING' : 'VALIDATED', 'inspected_at' => now()]);

            return ['file_path' => $path, 'file_name' => basename(str_replace('\\', '/', $name)), 'file_type' => $result->mimeType,
                'file_size' => $result->fileSize, 'file_inspection_id' => $inspection->id];
        } catch (\Throwable $exception) {
            if ($inspection !== null) {
                try {
                    if (! $disk->delete($path)) {
                        report(new \RuntimeException('Native upload compensation failed.'));
                    }
                } catch (\Throwable $cleanupFailure) {
                    report($cleanupFailure);
                }
                try {
                    $inspection->update(['status' => 'ERROR', 'error_code' => 'storage_failure']);
                } catch (\Throwable $trackingFailure) {
                    report($trackingFailure);
                }
            }
            throw $exception;
        } finally {
            fclose($input);
        }
    }

    private function reject(string $field, string $reason): never
    {
        throw ValidationException::withMessages([$field => __('file_security.errors.'.$reason)]);
    }
}

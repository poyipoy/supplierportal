<?php

namespace App\Support;

use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

final class SpreadsheetImportReader
{
    public static function import(object $import, UploadedFile $uploadedFile): void
    {
        app(FileInspectionService::class)->inspectUpload($uploadedFile, 'spreadsheet_preview');
        $sourcePath = $uploadedFile->getPathname();

        if ($sourcePath === '' || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException(__('local_procurement.import.unreadable'));
        }

        $directory = storage_path('framework/cache/import-previews');
        File::ensureDirectoryExists($directory);

        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'tmp';
        $temporaryPath = $directory.DIRECTORY_SEPARATOR.'import-'.Str::uuid().'.'.$extension;

        try {
            if (! File::copy($sourcePath, $temporaryPath)) {
                throw new RuntimeException(__('local_procurement.import.prepare_failed'));
            }

            Excel::import($import, $temporaryPath);
        } finally {
            File::delete($temporaryPath);
        }
    }
}

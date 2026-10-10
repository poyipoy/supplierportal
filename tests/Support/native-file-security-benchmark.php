<?php

use App\Services\FileSecurity\NativeArchiveInspector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Tests\Support\NativeFileFixtures;

// Controlled, small synthetic fixtures only. Never invoke against hosting.
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'adasi_portal_test') {
    throw new RuntimeException('This benchmark requires the isolated testing configuration.');
}
require_once __DIR__.'/NativeFileFixtures.php';
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adasi-native-benchmark-'.bin2hex(random_bytes(12));
mkdir($directory, 0700);
$results = [];
try {
    foreach ([1, 100, 250, 500, 750] as $count) {
        config(['native_file_security.zip.max_entries' => $count, 'native_file_security.zip.max_pdfs' => $count]);
        $archive = $directory.DIRECTORY_SEPARATOR.'input-'.$count.'.zip';
        $staging = $directory.DIRECTORY_SEPARATOR.'stage-'.$count;
        mkdir($staging, 0700);
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create fixture.');
        }
        for ($index = 0; $index < $count; $index++) {
            $zip->addFromString('PO-'.$index.'.pdf', NativeFileFixtures::pdf('Synthetic PO '.$index));
        }
        $zip->close();
        memory_reset_peak_usage();
        $started = hrtime(true);
        $manifest = app(NativeArchiveInspector::class)->inspect($archive);
        $inspectionSeconds = (hrtime(true) - $started) / 1e9;
        $started = hrtime(true);
        $files = app(NativeArchiveInspector::class)->extractPdfArchive($archive, $staging);
        $processingSeconds = (hrtime(true) - $started) / 1e9;
        $expanded = array_sum(array_column($files, 'file_size'));
        $results[] = ['synthetic_pdfs' => $count, 'archive_bytes' => filesize($archive), 'raw_entries' => count($manifest),
            'expanded_bytes' => $expanded, 'largest_pdf_bytes' => max(array_column($files, 'file_size')),
            'php_peak_bytes' => memory_get_peak_usage(true), 'metadata_seconds' => $inspectionSeconds,
            'extraction_and_pdf_validation_seconds' => $processingSeconds, 'temp_files_at_peak' => count($files) + 1,
            'temp_bytes_at_peak' => $expanded + filesize($archive), 'production_capacity_authorized' => false];
        foreach ($files as $file) {
            unlink($file['path']);
        }
        rmdir($staging);
        unlink($archive);
    }
    echo json_encode(['environment' => 'isolated local testing', 'php' => PHP_VERSION, 'workloads' => $results], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    File::deleteDirectory($directory);
}

<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

// XLS fixture creation is isolated so the writer's memory is not attributed to
// the application import worker. This helper never opens a database connection.
require __DIR__.'/../../vendor/autoload.php';
$storageRoot = getenv('LARAVEL_STORAGE_PATH') ?: __DIR__.'/../../storage';
$root = realpath($storageRoot.'/framework/testing/disks/private');
$metadataPath = realpath($argv[1] ?? '');
if (! $root || ! $metadataPath || ! str_starts_with($metadataPath, $root.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Unsafe fixture metadata path.');
}
$metadata = json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
$outputDirectory = realpath(dirname($metadata['output']));
if ($outputDirectory !== $root || ! in_array($metadata['kind'], ['PO', 'GR'], true) || $metadata['count'] < 1 || $metadata['count'] > 65535) {
    throw new RuntimeException('Unsafe fixture arguments.');
}
$book = new Spreadsheet;
$sheet = $book->getActiveSheet();
for ($index = 0; $index < $metadata['count']; $index++) {
    $values = $metadata['kind'] === 'PO'
        ? ['E' => $metadata['prefix'].'NEW-PO-'.$index, 'G' => $metadata['supplier_name'], 'J' => '2026-10-01', 'K' => '999999999999.99']
        : ['I' => 'Material specification with unique text '.$index, 'J' => $metadata['prefix'].'NEW-GR-'.$index,
            'L' => $metadata['parent'], 'P' => '10.125', 'Q' => 'KG', 'T' => '2026-10-01'];
    foreach ($values as $column => $value) {
        $sheet->setCellValueExplicit($column.($index + 2), $value, DataType::TYPE_STRING);
    }
}
$writer = new Xls($book);
$writer->setPreCalculateFormulas(false);
$writer->save($metadata['output']);
$book->disconnectWorksheets();

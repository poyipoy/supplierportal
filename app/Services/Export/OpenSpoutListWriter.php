<?php

namespace App\Services\Export;

use App\Models\ExportJob;
use App\Services\ExportProgressService;
use App\Support\SpreadsheetCellSanitizer;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateIntervalCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

class OpenSpoutListWriter
{
    private const HEADER_BACKGROUND_HEX = '9C4A0F';

    private const HEADER_FONT_SIZE = 11;

    private const HEADER_HEIGHT_PT = 18.0; // 18 pt = 24 px at 96 DPI

    /**
     * Stream a FromQuery list export directly to a private temporary file via OpenSpout
     * and atomically move it to the final destination path.
     *
     * @return bool False if the export job was cancelled mid-stream; true when finalized on disk.
     */
    public function write(
        ExportJob $record,
        FromQuery $export,
        string $finalRelativePath,
        string $disk,
        ExportProgressService $progress,
    ): bool {
        $format = ($record->format ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $storage = Storage::disk($disk);
        $tmpRoot = $storage->path('exports/.tmp');
        $this->ensureDirectory($tmpRoot);

        $runToken = $record->getKey().'_'.Str::random(12);
        $tmpFilePath = $tmpRoot.DIRECTORY_SEPARATOR.$runToken.'.'.$format;
        $workTmpDir = $format === 'xlsx' ? $tmpRoot.DIRECTORY_SEPARATOR.'work_'.$runToken : null;

        if ($workTmpDir !== null) {
            $this->ensureDirectory($workTmpDir);
        }

        $moved = false;

        try {
            if ($this->isCancelled((int) $record->getKey())) {
                return false;
            }

            $completed = $format === 'csv'
                ? $this->writeCsv($record, $export, $tmpFilePath, $progress)
                : $this->writeXlsx($record, $export, $tmpFilePath, (string) $workTmpDir, $progress);

            if (! $completed || $this->isCancelled((int) $record->getKey())) {
                return false;
            }

            $finalFullPath = $storage->path($finalRelativePath);
            $this->ensureDirectory(dirname($finalFullPath));
            $this->atomicMove($tmpFilePath, $finalFullPath);
            $moved = true;

            return true;
        } finally {
            if (! $moved && is_file($tmpFilePath)) {
                @unlink($tmpFilePath);
            }

            if ($workTmpDir !== null && is_dir($workTmpDir)) {
                $this->removeDirectoryRecursively($workTmpDir);
            }
        }
    }

    private function writeXlsx(
        ExportJob $record,
        FromQuery $export,
        string $tmpFilePath,
        string $workTmpDir,
        ExportProgressService $progress,
    ): bool {
        $options = new XlsxOptions;
        // OpenSpout's SharedStringsManager streams directly to disk with O(1) memory
        // and keeps PhpSpreadsheet/Excel readers returning plain strings instead of RichText.
        $options->SHOULD_USE_INLINE_STRINGS = false;
        $options->setTempFolder($workTmpDir);
        $options->DEFAULT_ROW_STYLE->setFontSize(self::HEADER_FONT_SIZE);

        $writer = new XlsxWriter($options);
        $writer->openToFile($tmpFilePath);
        $writerOpen = true;

        try {
            $presentationEnabled = method_exists($export, 'exportPresentationEnabled')
                ? (bool) $export->exportPresentationEnabled()
                : false;

            $headings = $export instanceof WithHeadings ? array_values($export->headings()) : [];
            $columnCount = count($headings);
            $sheet = $writer->getCurrentSheet();

            if ($presentationEnabled && $export instanceof WithColumnWidths) {
                foreach ($export->columnWidths() as $columnLetter => $width) {
                    $colIndex = Coordinate::columnIndexFromString((string) $columnLetter);
                    $sheet->setColumnWidth((float) $width, $colIndex);
                }
            }

            if ($presentationEnabled && $columnCount > 0) {
                $sheet->setSheetView((new SheetView)->setFreezeRow(2));
            }

            if ($columnCount > 0) {
                $headerStyle = $presentationEnabled ? $this->buildHeaderStyle() : null;
                $headerCells = array_map(
                    fn (mixed $heading): Cell => $this->buildSafeStringCell((string) $heading, $headerStyle),
                    $headings,
                );
                $headerRow = new Row($headerCells);
                if ($presentationEnabled) {
                    $headerRow->setHeight(self::HEADER_HEIGHT_PT);
                }
                $writer->addRow($headerRow);
            }

            $columnStyles = $presentationEnabled ? $this->buildColumnStyles($export, $columnCount) : [];
            $dataRowsWritten = 0;

            $completed = $this->streamQueryChunks(
                $record,
                $export,
                $progress,
                function (array $mappedRow) use ($writer, $columnStyles, &$dataRowsWritten): void {
                    $cells = [];
                    foreach (array_values($mappedRow) as $colIndex => $value) {
                        $cells[] = $this->buildCell($value, $columnStyles[$colIndex] ?? null);
                    }
                    $writer->addRow(new Row($cells));
                    $dataRowsWritten++;
                },
            );

            if (! $completed) {
                return false;
            }

            if ($presentationEnabled && $columnCount > 0) {
                $lastRow = max(1, $dataRowsWritten + 1);
                $sheet->setAutoFilter(new AutoFilter(0, 1, $columnCount - 1, $lastRow));
            }

            $writer->close();
            $writerOpen = false;

            return true;
        } finally {
            if ($writerOpen) {
                $writer->close();
            }
        }
    }

    private function writeCsv(
        ExportJob $record,
        FromQuery $export,
        string $tmpFilePath,
        ExportProgressService $progress,
    ): bool {
        $delimiter = method_exists($export, 'exportCsvDelimiter')
            ? (string) $export->exportCsvDelimiter()
            : (string) config('exports.csv.delimiter', ',');

        if (! in_array($delimiter, [',', ';'], true)) {
            $delimiter = ',';
        }

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = $delimiter;
        $options->FIELD_ENCLOSURE = (string) config('exports.csv.enclosure', '"');
        $options->SHOULD_ADD_BOM = (bool) config('exports.csv.use_bom', true);
        $options->FLUSH_THRESHOLD = $this->resolveChunkSize($export);

        $writer = new CsvWriter($options);
        $writer->openToFile($tmpFilePath);
        $writerOpen = true;

        try {
            if ($export instanceof WithHeadings) {
                $headings = array_values($export->headings());
                if ($headings !== []) {
                    $headerCells = array_map(
                        fn (mixed $heading): Cell => $this->buildSafeStringCell((string) $heading, null),
                        $headings,
                    );
                    $writer->addRow(new Row($headerCells));
                }
            }

            $completed = $this->streamQueryChunks(
                $record,
                $export,
                $progress,
                function (array $mappedRow) use ($writer): void {
                    $cells = array_map(
                        fn (mixed $value): Cell => $this->buildCell($value, null),
                        array_values($mappedRow),
                    );
                    $writer->addRow(new Row($cells));
                },
            );

            if (! $completed) {
                return false;
            }

            $writer->close();
            $writerOpen = false;

            return true;
        } finally {
            if ($writerOpen) {
                $writer->close();
            }
        }
    }

    /**
     * @param  callable(array<int, mixed>): void  $writeRow
     */
    private function streamQueryChunks(
        ExportJob $record,
        FromQuery $export,
        ExportProgressService $progress,
        callable $writeRow,
    ): bool {
        $query = $export->query();
        if (! $query instanceof EloquentBuilder) {
            throw new RuntimeException('OpenSpout list streaming requires an Eloquent query builder.');
        }

        $exportJobId = (int) $record->getKey();
        $chunkSize = $this->resolveChunkSize($export);

        $handleChunk = function (EloquentCollection $rows, int $chunkIndex) use ($export, $exportJobId, $progress, $writeRow): bool {
            if ($this->isCancelled($exportJobId)) {
                return false;
            }

            $writtenInChunk = 0;
            foreach ($rows as $row) {
                $mapped = $export instanceof WithMapping ? $export->map($row) : $row->toArray();
                $writeRow($mapped);
                $writtenInChunk++;
            }

            if ($this->isCancelled($exportJobId)) {
                return false;
            }

            $progress->recordChunk($exportJobId, 'openspout:chunk:'.$chunkIndex, $writtenInChunk);

            return ! $this->isCancelled($exportJobId);
        };

        $baseOrders = $query->getQuery()->orders ?? [];
        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $qualifiedKey = $model->getQualifiedKeyName();
        $singlePkDirection = $this->singlePrimaryKeyOrderDirection($baseOrders, $keyName, $qualifiedKey);

        if ($singlePkDirection !== null) {
            $lastId = null;
            $chunkIndex = 0;
            $operator = $singlePkDirection === 'desc' ? '<' : '>';

            while (true) {
                $chunkQuery = clone $query;
                if ($lastId !== null) {
                    $chunkQuery->where($qualifiedKey, $operator, $lastId);
                }

                $rows = $chunkQuery->limit($chunkSize)->get();
                if ($rows->isEmpty()) {
                    break;
                }

                $chunkIndex++;
                if (! $handleChunk($rows, $chunkIndex)) {
                    return false;
                }

                if ($rows->count() < $chunkSize) {
                    break;
                }

                $lastId = $rows->last()->getKey();
                unset($rows);
            }

            return true;
        }

        $orderedQuery = clone $query;
        if (! $this->ordersIncludeColumn($baseOrders, $keyName, $qualifiedKey)) {
            $orderedQuery->orderBy($qualifiedKey);
        }

        $page = 1;
        while (true) {
            $rows = (clone $orderedQuery)->forPage($page, $chunkSize)->get();
            if ($rows->isEmpty()) {
                break;
            }

            if (! $handleChunk($rows, $page)) {
                return false;
            }

            if ($rows->count() < $chunkSize) {
                break;
            }

            unset($rows);
            $page++;
        }

        return true;
    }

    private function buildHeaderStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setFontSize(self::HEADER_FONT_SIZE)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::HEADER_BACKGROUND_HEX)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText(false)
            ->setShouldShrinkToFit(true);
    }

    /**
     * @return array<int, Style|null>
     */
    private function buildColumnStyles(FromQuery $export, int $columnCount): array
    {
        $wrapped = method_exists($export, 'exportWrappedColumns')
            ? array_flip($export->exportWrappedColumns())
            : [];
        $numberFormats = method_exists($export, 'exportNumberFormats')
            ? $export->exportNumberFormats()
            : [];

        $styles = [];
        for ($index = 0; $index < $columnCount; $index++) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $isWrapped = isset($wrapped[$letter]);
            $formatCode = $numberFormats[$letter] ?? null;

            if (! $isWrapped && $formatCode === null) {
                $styles[$index] = null;

                continue;
            }

            $style = new Style;
            if ($isWrapped) {
                $style->setShouldWrapText(true)
                    ->setCellVerticalAlignment(CellVerticalAlignment::TOP);
            }
            if ($formatCode !== null) {
                $style->setFormat($formatCode);
            }
            $styles[$index] = $style;
        }

        return $styles;
    }

    private function buildCell(mixed $value, ?Style $style): Cell
    {
        if (is_bool($value)) {
            return new BooleanCell($value, $style);
        }

        if ($value === null || $value === '') {
            return new EmptyCell(null, $style);
        }

        if (is_int($value) || is_float($value)) {
            return new NumericCell($value, $style);
        }

        if ($value instanceof DateTimeInterface) {
            return new DateTimeCell($value, $style);
        }

        if ($value instanceof DateInterval) {
            return new DateIntervalCell($value, $style);
        }

        return $this->buildSafeStringCell((string) $value, $style);
    }

    /**
     * Always emit StringCell (never FormulaCell) and apply defense-in-depth
     * formula sanitization while preserving the intentional '-' placeholder.
     */
    private function buildSafeStringCell(string $value, ?Style $style): Cell
    {
        if ($value === '') {
            return new EmptyCell(null, $style);
        }

        if ($value !== '-' && preg_match('/^(?:[\s\v\0\x{FEFF}]*[\t\r=+\-@])/u', $value) === 1) {
            $value = SpreadsheetCellSanitizer::text($value, '', preserveWhitespace: true);
        }

        return new StringCell($value, $style);
    }

    private function resolveChunkSize(FromQuery $export): int
    {
        $configured = (int) config('exports.stream_chunk_size', 0);
        if ($configured > 0) {
            return max(1, min(1000, $configured));
        }

        if ($export instanceof WithCustomChunkSize) {
            return max(1, min(1000, $export->chunkSize()));
        }

        return 500;
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     */
    private function singlePrimaryKeyOrderDirection(array $orders, string $keyName, string $qualifiedKey): ?string
    {
        if (count($orders) !== 1 || isset($orders[0]['type']) || ! is_string($orders[0]['column'] ?? null)) {
            return null;
        }

        $column = $orders[0]['column'];
        if ($column !== $keyName && $column !== $qualifiedKey) {
            return null;
        }

        return strtolower((string) ($orders[0]['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     */
    private function ordersIncludeColumn(array $orders, string $keyName, string $qualifiedKey): bool
    {
        foreach ($orders as $order) {
            if (isset($order['type']) || ! is_string($order['column'] ?? null)) {
                continue;
            }
            $column = $order['column'];
            if ($column === $keyName || $column === $qualifiedKey) {
                return true;
            }
        }

        return false;
    }

    private function isCancelled(int $exportJobId): bool
    {
        return ExportJob::query()->whereKey($exportJobId)->value('status') === ExportJob::STATUS_CANCELLED;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create export directory.');
        }
    }

    private function atomicMove(string $sourcePath, string $targetPath): void
    {
        if (is_file($targetPath)) {
            @unlink($targetPath);
        }

        if (@rename($sourcePath, $targetPath)) {
            return;
        }

        if (! @copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Unable to move streamed export file to its final location.');
        }

        @unlink($sourcePath);
    }

    private function removeDirectoryRecursively(string $directory): void
    {
        $items = @scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($fullPath)) {
                $this->removeDirectoryRecursively($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($directory);
    }
}

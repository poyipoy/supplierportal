<?php

namespace App\Services\LocalInvoice;

use Generator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\OLERead;

final class LocalProcurementXlsReader
{
    public array $warnings = [];

    public function rows(string $path, array $columns): Generator
    {
        $calendar = Date::getExcelCalendar();
        $this->warnings = [];
        try {
            $this->assertUnencrypted($path);
            $reader = new Xls;
            if (! $reader->canRead($path)) {
                throw new \RuntimeException('Invalid XLS workbook.');
            }
            $sheets = $reader->listWorksheetInfo($path);
            if ($sheets === [] || (int) $sheets[0]['totalRows'] > 65536) {
                throw new \RuntimeException('Invalid XLS worksheet dimensions.');
            }
            if (count($sheets) > 1) {
                $this->warnings[] = ['row' => null, 'column' => 'worksheet', 'message' => __('purchasing.imports.additional_sheets', ['count' => count($sheets) - 1])];
            }
            $sheetName = $sheets[0]['worksheetName'];
            $lastRow = (int) $sheets[0]['totalRows'];
            $letters = array_map(fn ($index) => Coordinate::stringFromColumnIndex($index + 1), $columns);
            for ($start = 2; $start <= max(2, $lastRow); $start += 2000) {
                $end = min($start + 1999, $lastRow);
                $reader = new Xls;
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                $reader->setLoadSheetsOnly($sheetName);
                $reader->setReadFilter(new class($start, $end, $letters) implements IReadFilter
                {
                    public function __construct(private int $start, private int $end, private array $columns) {}

                    public function readCell($columnAddress, $row, $worksheetName = ''): bool
                    {
                        return ($row === 1 || ($row >= $this->start && $row <= $this->end)) && in_array($columnAddress, $this->columns, true);
                    }
                });
                $workbook = $reader->load($path);
                try {
                    $sheet = $workbook->getSheet(0);
                    $workbookCalendar = Date::getExcelCalendar();
                    $numbers = $start === 2 ? array_merge([1], range($start, max($start, $end))) : range($start, $end);
                    foreach ($numbers as $number) {
                        $values = $formulas = [];
                        foreach ($columns as $index) {
                            $address = Coordinate::stringFromColumnIndex($index + 1).$number;
                            if (! $sheet->cellExists($address)) {
                                $values[$index] = null;

                                continue;
                            }
                            $cell = $sheet->getCell($address);
                            $values[$index] = $cell->getValue();
                            if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                                $formulas[] = $index;
                            }
                        }
                        yield [$number, $values, $formulas, $workbookCalendar];
                    }
                } finally {
                    $workbook->disconnectWorksheets();
                    unset($sheet, $cell, $workbook, $reader);
                    gc_collect_cycles();
                    Date::setExcelCalendar($calendar);
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['import_file' => __('local_procurement.formats.invalid_file')]);
        } finally {
            Date::setExcelCalendar($calendar);
        }
    }

    private function assertUnencrypted(string $path): void
    {
        $ole = new OLERead;
        $ole->read($path);
        if ($ole->wrkbook === null) {
            throw new \RuntimeException('Missing XLS workbook stream.');
        }
        $data = $ole->getStream($ole->wrkbook);
        $size = strlen($data);
        for ($offset = 0; $offset + 4 <= $size;) {
            $header = unpack('vcode/vlength', substr($data, $offset, 4));
            if ($header['code'] === 0x002F) {
                throw new \RuntimeException('Encrypted XLS files are not supported.');
            }
            if ($header['code'] === 0x000A) {
                break;
            }
            if ($offset + 4 + $header['length'] > $size) {
                throw new \RuntimeException('Invalid XLS record length.');
            }
            $offset += 4 + $header['length'];
        }
    }
}

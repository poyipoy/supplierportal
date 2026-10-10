<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Bounded logical records, including quoted multiline CSV cells. */
final class BoundedCsvReader
{
    public function read($stream, string $delimiter): array|false
    {
        if (! is_resource($stream) || strlen($delimiter) !== 1) {
            $this->reject();
        }
        $maxRecord = (int) config('native_file_security.csv.max_record_bytes', 50 * 1024 * 1024);
        $maxCell = (int) config('native_file_security.csv.max_cell_bytes', 50 * 1024 * 1024);
        $maxColumns = (int) config('native_file_security.csv.max_columns', 16384);
        if (min($maxRecord, $maxCell, $maxColumns) < 1) {
            $this->reject();
        }
        $record = '';
        $quoted = false;
        $fieldStart = true;
        $columns = 1;
        while (! feof($stream)) {
            $remaining = $maxRecord - strlen($record);
            $line = fgets($stream, $remaining + 2);
            if ($line === false) {
                if (! feof($stream)) {
                    $this->reject();
                }
                break;
            }
            if (strlen($line) > $remaining) {
                $this->reject();
            }
            $record .= $line;
            for ($index = 0, $length = strlen($line); $index < $length; $index++) {
                $character = $line[$index];
                if ($quoted) {
                    if ($character === '"') {
                        if (($line[$index + 1] ?? null) === '"') {
                            $index++;
                        } else {
                            $quoted = false;
                        }
                    }
                } elseif ($character === $delimiter) {
                    if (++$columns > $maxColumns) {
                        $this->reject();
                    }
                    $fieldStart = true;
                } elseif ($character === '"' && $fieldStart) {
                    $quoted = true;
                    $fieldStart = false;
                } elseif (! ($fieldStart && ($character === ' ' || $character === "\t"))) {
                    $fieldStart = false;
                }
            }
            if (! $quoted) {
                break;
            }
        }
        if ($quoted) {
            $this->reject();
        }
        if ($record === '') {
            return false;
        }
        $cells = str_getcsv(rtrim($record, "\r\n"), $delimiter, '"', '');
        foreach ($cells as $cell) {
            if ($cell !== null && strlen($cell) > $maxCell) {
                $this->reject();
            }
        }

        return $cells;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['import_file' => __('file_security_resources.csv_invalid')]);
    }
}

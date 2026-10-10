<?php

namespace App\Services\LocalInvoice;

use App\Support\BoundedCsvReader;
use Generator;
use Illuminate\Validation\ValidationException;

final class LocalProcurementCsvReader
{
    public array $warnings = [];

    public function rows(string $path, int $minimumColumns): Generator
    {
        [$encoding, $skip] = $this->encoding($path);
        $delimiter = $this->delimiter($path, $encoding, $skip, $minimumColumns);
        $stream = $this->open($path, $encoding, $skip);
        try {
            $number = 0;
            $records = new BoundedCsvReader;
            while (($cells = $records->read($stream, $delimiter)) !== false) {
                foreach ($cells as $value) {
                    if ($value !== null && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value))) {
                        $this->invalid('encoding_invalid');
                    }
                }
                yield [++$number, $cells, [], 1900];
            }
        } finally {
            fclose($stream);
        }
    }

    private function encoding(string $path): array
    {
        $this->warnings = [];
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            $this->invalid('invalid_file');
        }
        try {
            $bom = fread($stream, 4);
            if (! is_string($bom)) {
                $this->invalid('invalid_file');
            }
            if (str_starts_with($bom, "\xEF\xBB\xBF")) {
                return ['UTF-8', 3];
            }
            if (str_starts_with($bom, "\xFF\xFE")) {
                return ['UTF-16LE', 2];
            }
            if (str_starts_with($bom, "\xFE\xFF")) {
                return ['UTF-16BE', 2];
            }
            if (! rewind($stream)) {
                $this->invalid('invalid_file');
            }
            $carry = '';
            while (! feof($stream)) {
                $read = fread($stream, 65536);
                if ($read === false || ($read === '' && ! feof($stream))) {
                    $this->invalid('invalid_file');
                }
                $block = $carry.$read;
                $carry = '';
                if (mb_check_encoding($block, 'UTF-8')) {
                    continue;
                }
                $boundary = false;
                for ($tail = 1; $tail <= min(3, strlen($block)); $tail++) {
                    if (mb_check_encoding(substr($block, 0, -$tail), 'UTF-8')) {
                        $carry = substr($block, -$tail);
                        $boundary = true;
                        break;
                    }
                }
                if (! $boundary || (feof($stream) && $carry !== '')) {
                    $this->warnings[] = ['row' => null, 'column' => 'encoding', 'message' => __('local_procurement.formats.legacy_encoding')];

                    return ['Windows-1252', 0];
                }
            }

            return ['UTF-8', 0];
        } finally {
            fclose($stream);
        }
    }

    private function delimiter(string $path, string $encoding, int $skip, int $minimumColumns): string
    {
        $candidates = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $stream = $this->open($path, $encoding, $skip);
            try {
                $valid = 0;
                $invalid = false;
                $records = new BoundedCsvReader;
                for ($sample = 0; $sample < 20 && ($row = $records->read($stream, $delimiter)) !== false; $sample++) {
                    if ($row === [null]) {
                        continue;
                    }
                    if (count($row) < $minimumColumns) {
                        $invalid = true;
                        break;
                    }
                    $valid++;
                }
                if ($valid && ! $invalid) {
                    $candidates[] = $delimiter;
                }
            } finally {
                fclose($stream);
            }
        }
        if (count($candidates) !== 1) {
            $this->invalid('delimiter_invalid');
        }

        return $candidates[0];
    }

    private function open(string $path, string $encoding, int $skip)
    {
        $stream = fopen($path, 'rb');
        if (! $stream) {
            $this->invalid('invalid_file');
        }
        if ($skip && fseek($stream, $skip) !== 0) {
            fclose($stream);
            $this->invalid('invalid_file');
        }
        if ($encoding !== 'UTF-8' && ! stream_filter_append($stream, 'convert.iconv.'.$encoding.'/UTF-8', STREAM_FILTER_READ)) {
            fclose($stream);
            $this->invalid('encoding_invalid');
        }

        return $stream;
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['import_file' => __('local_procurement.formats.'.$key)]);
    }
}

<?php

namespace Tests\Unit;

use App\Support\BoundedCsvReader;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BoundedCsvReaderTest extends TestCase
{
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function test_quoted_multiline_and_escaped_quotes_preserve_record_boundaries(): void
    {
        $stream = $this->stream("one,\"two\nthree \"\"quoted\"\"\",\nnext,row,last\n");
        try {
            $reader = new BoundedCsvReader;
            $this->assertSame(['one', "two\nthree \"quoted\"", ''], $reader->read($stream, ','));
            $this->assertSame(['next', 'row', 'last'], $reader->read($stream, ','));
            $this->assertFalse($reader->read($stream, ','));
        } finally {
            fclose($stream);
        }
    }

    public function test_logical_record_budget_is_enforced_before_csv_allocation(): void
    {
        config(['native_file_security.csv.max_record_bytes' => 64]);
        $stream = $this->stream('"'.str_repeat("abcdefgh\n", 20).'",x');
        try {
            $this->expectException(ValidationException::class);
            (new BoundedCsvReader)->read($stream, ',');
        } finally {
            fclose($stream);
        }
    }

    public function test_column_and_cell_budgets_are_enforced(): void
    {
        foreach ([['max_columns', 2, "a,b,c\n"], ['max_cell_bytes', 3, "long,b\n"]] as [$key, $limit, $contents]) {
            config(['native_file_security.csv.'.$key => $limit]);
            $stream = $this->stream($contents);
            try {
                (new BoundedCsvReader)->read($stream, ',');
                $this->fail('Expected a bounded CSV rejection.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            } finally {
                fclose($stream);
                config(['native_file_security.csv.'.$key => 50 * 1024 * 1024]);
            }
        }
    }

    public function test_incomplete_quoted_record_is_rejected(): void
    {
        $stream = $this->stream("a,\"unfinished\n");
        try {
            $this->expectException(ValidationException::class);
            (new BoundedCsvReader)->read($stream, ',');
        } finally {
            fclose($stream);
        }
    }
}

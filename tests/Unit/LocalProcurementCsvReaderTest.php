<?php

namespace Tests\Unit;

use App\Services\LocalInvoice\LocalProcurementStreamingReader;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalProcurementCsvReaderTest extends TestCase
{
    private function csv(string $delimiter, string $encoding = 'UTF-8', string $bom = ''): string
    {
        $stream = fopen('php://temp', 'w+');
        $header = array_fill(0, 21, '');
        $header[0] = 'PK';
        fputcsv($stream, $header, $delimiter, '"', '');
        $row = array_fill(0, 21, '');
        $row[8] = "Material café\nlong specification";
        $row[9] = '00001234';
        $row[11] = 'PO-0001';
        $row[15] = '10.125';
        $row[16] = 'KG';
        $row[19] = '2026-10-08';
        fputcsv($stream, $row, $delimiter, '"', '');
        rewind($stream);
        $text = stream_get_contents($stream);
        fclose($stream);
        $path = storage_path('framework/cache/csv-reader-'.uniqid().'.csv');
        file_put_contents($path, $bom.iconv('UTF-8', $encoding, $text));

        return $path;
    }

    public function test_csv_delimiters_encodings_empty_columns_and_multiline_are_preserved(): void
    {
        foreach ([',', ';', "\t"] as $delimiter) {
            foreach ([['UTF-8', ''], ['UTF-8', "\xEF\xBB\xBF"], ['UTF-16LE', "\xFF\xFE"], ['UTF-16BE', "\xFE\xFF"], ['Windows-1252', '']] as [$encoding, $bom]) {
                $path = $this->csv($delimiter, $encoding, $bom);
                try {
                    $reader = app(LocalProcurementStreamingReader::class);
                    $rows = iterator_to_array($reader->rows($path, 'GR'));
                    $this->assertCount(1, $rows);
                    $this->assertSame('00001234', $rows[0]['gr_number']);
                    $this->assertSame('KG', $rows[0]['uom']);
                    $this->assertSame('10.125', $rows[0]['qty']);
                    $this->assertSame("Material café\nlong specification", $rows[0]['description']);
                    $this->assertSame(2, $rows[0]['_row']);
                    $this->assertSame($encoding === 'Windows-1252' ? 1 : 0, count($reader->warnings));
                } finally {
                    unlink($path);
                }
            }
        }
    }

    public function test_binary_or_ambiguous_csv_is_rejected(): void
    {
        foreach (["PK\x03\x04not a csv", 'a,b;c', str_repeat(',', 20).str_repeat(';', 20), "header\0binary"] as $text) {
            $path = storage_path('framework/cache/invalid-'.uniqid().'.csv');
            file_put_contents($path, $text);
            try {
                iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($path, 'GR'));
                $this->fail('Expected CSV rejection');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            } finally {
                unlink($path);
            }
        }
    }
}

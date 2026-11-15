<?php

namespace Tests\Unit\Erp;

use App\Services\Erp\CanonicalRowHasher;
use App\Support\SpreadsheetCellSanitizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalRowHasherTest extends TestCase
{
    /**
     * Exact parity vectors copied from rust-portal-wasm/src/hash.rs tests.
     *
     * @return array<string, array{0: string, 1: string, 2: int|float|string, 3: int|float|string, 4: string, 5: string, 6: string}>
     */
    public static function rustParityVectors(): array
    {
        return [
            'standard po row' => [
                'PO/05/2025/001',
                'mat-001',
                '10.0000',
                '1500.50',
                'active',
                'PO/05/2025/001|MAT-001|10.0000|1500.50|ACTIVE',
                '5cd229126f3d88b4',
            ],
            'surrounding ascii spaces tabs and newlines' => [
                "  po-001 \t\n",
                '  mat-01  ',
                ' 0.0000 ',
                "\r25.00 ",
                '  draft ',
                'PO-001|MAT-01|0.0000|25.00|DRAFT',
                '28bcf663890cb0c4',
            ],
            'four and two decimal precision boundaries' => [
                'PO-DEC',
                'ITEM-9',
                '12345.6789',
                '0.01',
                'PENDING',
                'PO-DEC|ITEM-9|12345.6789|0.01|PENDING',
                'f2befcb3982dcd91',
            ],
            'symbols in identifiers and status' => [
                'PO#2026/A&B+1',
                'MAT_X-99/1',
                '100.2500',
                '999999.99',
                'ON-HOLD',
                'PO#2026/A&B+1|MAT_X-99/1|100.2500|999999.99|ON-HOLD',
                '3ec524af74202815',
            ],
            'unicode text accents eszett and cjk' => [
                'PO-PT. Baja — 日本語',
                'mat-é-ß-€',
                '5.5000',
                '42.10',
                'approved',
                'PO-PT. BAJA — 日本語|MAT-É-SS-€|5.5000|42.10|APPROVED',
                '11955466a98c0560',
            ],
            'canonical uppercase baseline' => [
                'PO-001',
                'MAT-01',
                '10.0000',
                '1500.50',
                'ACTIVE',
                'PO-001|MAT-01|10.0000|1500.50|ACTIVE',
                '34956c47a415bb9e',
            ],
            'numeric ints floats and comma strings normalize to same hash' => [
                "\u{00A0}po-001\u{2003}",
                'mat-01',
                10,
                ' 1,500.5 ',
                'active',
                'PO-001|MAT-01|10.0000|1500.50|ACTIVE',
                '34956c47a415bb9e',
            ],
            'negative zero normalizes to positive zero' => [
                'po-001',
                'mat-01',
                '-0.0',
                '-0',
                'draft',
                'PO-001|MAT-01|0.0000|0.00|DRAFT',
                'd407195459623424',
            ],
        ];
    }

    #[DataProvider('rustParityVectors')]
    public function test_canonical_payload_and_xxh3_hash_match_rust_wasm(
        string $docNo,
        string $itemCode,
        int|float|string $qty,
        int|float|string $price,
        string $status,
        string $expectedCanonical,
        string $expectedHex,
    ): void {
        $canonical = CanonicalRowHasher::canonicalPayload($docNo, $itemCode, $qty, $price, $status);
        $this->assertSame($expectedCanonical, $canonical);

        $hash = CanonicalRowHasher::hash($docNo, $itemCode, $qty, $price, $status);
        $this->assertSame($expectedHex, $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
    }

    public function test_rejects_negative_or_non_finite_numeric_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalRowHasher::normalizeDecimal('-1.25', 2);
    }

    public function test_rejects_non_numeric_string_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CanonicalRowHasher::normalizeDecimal('not-a-number', 4);
    }

    public function test_spreadsheet_cell_sanitizer_escapes_leading_tab_and_carriage_return(): void
    {
        $this->assertSame("'=CMD()", SpreadsheetCellSanitizer::text("\t=CMD()"));
        $this->assertSame("'ABC", SpreadsheetCellSanitizer::text("\tABC"));
        $this->assertSame("'ABC", SpreadsheetCellSanitizer::text("\rABC"));
        $this->assertSame("'\tABC", SpreadsheetCellSanitizer::text("\tABC", preserveWhitespace: true));
        $this->assertSame("'\rABC", SpreadsheetCellSanitizer::text("\rABC", preserveWhitespace: true));
        $this->assertSame("'\u{00A0}=CMD()", SpreadsheetCellSanitizer::text("\u{00A0}=CMD()"));
        $this->assertSame("'\u{2003}@SUM(A1)", SpreadsheetCellSanitizer::text("\u{2003}@SUM(A1)", preserveWhitespace: true));
        $this->assertSame("'=Untrusted", SpreadsheetCellSanitizer::text('=Untrusted'));
        $this->assertSame('Safe Text', SpreadsheetCellSanitizer::text('  Safe Text  '));
    }
}

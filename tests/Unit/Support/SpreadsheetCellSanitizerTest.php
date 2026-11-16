<?php

namespace Tests\Unit\Support;

use App\Support\SpreadsheetCellSanitizer;
use PHPUnit\Framework\TestCase;

class SpreadsheetCellSanitizerTest extends TestCase
{
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

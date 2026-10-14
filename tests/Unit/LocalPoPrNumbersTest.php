<?php

namespace Tests\Unit;

use App\Support\LocalPoPrNumber;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalPoPrNumbersTest extends TestCase
{
    public function test_optional_number_preserves_display_and_leading_zeros(): void
    {
        $this->assertSame('00123', LocalPoPrNumber::normalize(' 00123 '));
        $this->assertSame('PR-A', LocalPoPrNumber::normalize(' PR-A '));
        $this->assertSame('pr-a', LocalPoPrNumber::key(' PR-A '));
        $this->assertNull(LocalPoPrNumber::normalize(null));
        $this->assertNull(LocalPoPrNumber::normalize('   '));
    }

    #[DataProvider('invalidNumbers')]
    public function test_only_one_valid_number_is_accepted(mixed $number): void
    {
        $this->expectException(ValidationException::class);
        LocalPoPrNumber::normalize($number);
    }

    public static function invalidNumbers(): array
    {
        return [['=SUM(1,2)'], ["PR\n001"], ['PR-A;PR-B'], ['PR-A,PR-B'], [str_repeat('A', 101)], [1.23], [['PR-A']], [true], ["PR\0A"]];
    }
}

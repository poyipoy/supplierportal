<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_normalize_returns_two_scale_decimal_strings(): void
    {
        $this->assertSame('0.01', Money::normalize('0.01'));
        $this->assertSame('0.10', Money::normalize('0.1'));
        $this->assertSame('0.29', Money::normalize('0.29'));
        $this->assertSame('0.00', Money::normalize(null));
        $this->assertSame('0.00', Money::normalize(''));
        $this->assertSame('2500.00', Money::normalize(2500));
    }

    public function test_normalize_rounds_half_away_from_zero(): void
    {
        $this->assertSame('0.01', Money::normalize('0.005'));
        $this->assertSame('-0.01', Money::normalize('-0.005'));
        $this->assertSame('1.01', Money::normalize('1.005'));
        $this->assertSame('0.29', Money::normalize('0.294'));
        $this->assertSame('0.30', Money::normalize('0.295'));
    }

    public function test_normalize_absorbs_binary_float_error(): void
    {
        // 0.1 + 0.2 === 0.30000000000000004 as a float.
        $this->assertSame('0.30', Money::normalize(0.1 + 0.2));
        $this->assertSame('1.01', Money::normalize(1.005));
    }

    public function test_normalize_rejects_non_decimal_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::normalize('1.0e6');
    }

    public function test_addition_and_summation_are_exact(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('0.03', Money::sum(['0.01', '0.01', '0.01']));
        $this->assertSame('0.00', Money::sum([]));

        // Float accumulation of 0.01 a hundred times drifts; this must not.
        $hundredCents = array_fill(0, 100, '0.01');
        $this->assertSame('1.00', Money::sum($hundredCents));
    }

    public function test_arithmetic_survives_eighteen_digit_amounts(): void
    {
        $this->assertSame(
            '123456789012345679.00',
            Money::add('123456789012345678.99', '0.01')
        );
        $this->assertSame(
            '999999999999999998.99',
            Money::subtract('999999999999999999.00', '0.01')
        );
    }

    public function test_multiplication_keeps_working_precision_for_rates(): void
    {
        // PPh 23 at 2% of a typical DPP.
        $this->assertSame('170650.00', Money::multiply('8532500', '0.02'));
        $this->assertSame('0.03', Money::multiply('0.25', '0.1'));
    }

    public function test_comparison_detects_one_cent_under_and_overpayment(): void
    {
        $this->assertSame(-1, Money::compare('99.99', '100.00'));
        $this->assertSame(0, Money::compare('100.00', '100.000'));
        $this->assertSame(1, Money::compare('100.01', '100.00'));
    }

    public function test_at_least_zero_clamps_without_losing_positive_cents(): void
    {
        $this->assertSame('0.00', Money::atLeastZero('-0.01'));
        $this->assertSame('0.00', Money::atLeastZero('-2500.00'));
        $this->assertSame('0.01', Money::atLeastZero('0.01'));
    }

    public function test_bank_fee_deduction_matches_drp_group_expectations(): void
    {
        // Non-BCA group: subtotal minus the Rp 2.500 fee.
        $subtotal = Money::sum(['10900000.00', '2500000.50']);
        $this->assertSame('13400000.50', $subtotal);

        $net = Money::atLeastZero(Money::subtract($subtotal, Money::normalize(2500)));
        $this->assertSame('13397500.50', $net);

        // A fee larger than the subtotal clamps to zero rather than going negative.
        $this->assertSame('0.00', Money::atLeastZero(Money::subtract('1000.00', '2500.00')));
    }
}

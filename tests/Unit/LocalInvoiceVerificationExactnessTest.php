<?php

namespace Tests\Unit;

use App\Models\LocalInvoiceVerification;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Locks the boundary between Eloquent's `decimal:2` cast and Money's exact
 * decimal strings.
 *
 * netPayableExact()/totalWithholdingExact() read attributes that Eloquent has
 * already passed through `decimal:2`, then feed them to Money. If those two
 * rounding implementations ever disagree, money silently shifts by a cent on
 * the write path. These cases pin the agreement at the edges the cast is most
 * likely to diverge on: 3+ decimal input, exact halves, negatives, zero and
 * null.
 *
 * No database: the decimal cast is applied by the attribute accessor, so an
 * unsaved model exercises the same code path.
 */
class LocalInvoiceVerificationExactnessTest extends TestCase
{
    private function verification(array $attributes): LocalInvoiceVerification
    {
        $model = new LocalInvoiceVerification;
        $model->forceFill($attributes);

        return $model;
    }

    public function test_decimal_cast_and_money_normalize_agree_on_rounding_edges(): void
    {
        // Values chosen because the binary-float representations of 1.005 and
        // 2.675 sit just below the true half.
        foreach (['1.005', '2.675', '0.005', '-1.005', '-2.675', '1.0049999999999999', '0', '-0.004'] as $raw) {
            $model = $this->verification(['pph_23_amount' => $raw]);

            $this->assertSame(
                Money::normalize($raw),
                Money::normalize($model->pph_23_amount),
                "decimal:2 cast and Money::normalize disagree for [{$raw}]."
            );
        }
    }

    public function test_total_withholding_sums_only_applicable_components_exactly(): void
    {
        $model = $this->verification([
            'pph_23_applicable' => true,
            'pph_23_amount' => '10.005',
            'pph_4_2_applicable' => false,
            'pph_4_2_amount' => '999.99',
            'pph_21_applicable' => true,
            'pph_21_amount' => '0.005',
        ]);

        // 10.005 -> 10.01 and 0.005 -> 0.01, and the non-applicable 999.99 is
        // excluded entirely rather than subtracted later.
        $this->assertSame('10.02', $model->totalWithholdingExact());
    }

    public function test_null_and_zero_components_do_not_poison_the_sum(): void
    {
        $model = $this->verification([
            'pph_23_applicable' => true,
            'pph_23_amount' => null,
            'pph_4_2_applicable' => true,
            'pph_4_2_amount' => '0',
            'pph_21_applicable' => false,
            'pph_21_amount' => null,
        ]);

        $this->assertSame('0.00', $model->totalWithholdingExact());
    }

    public function test_net_payable_is_exact_for_dpp_plus_ppn_minus_withholding(): void
    {
        $model = $this->verification([
            'verified_ppn' => '1100.005',
            'pph_23_applicable' => true,
            'pph_23_amount' => '200.004',
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);

        // ppn 1100.005 -> 1100.01, withholding 200.004 -> 200.00
        // 10000.00 + 1100.01 - 200.00
        $this->assertSame('10900.01', $model->netPayableExact('10000.00'));
    }

    public function test_net_payable_stays_exact_when_withholding_exceeds_gross(): void
    {
        $model = $this->verification([
            'verified_ppn' => '0',
            'pph_23_applicable' => true,
            'pph_23_amount' => '500.00',
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);

        // Deliberately NOT clamped here: netPayableExact reports the true
        // arithmetic and the caller decides the clamping policy.
        $this->assertSame('-400.00', $model->netPayableExact('100.00'));
    }

    public function test_deprecated_float_wrappers_agree_with_the_exact_strings(): void
    {
        $model = $this->verification([
            'verified_ppn' => '1100.01',
            'pph_23_applicable' => true,
            'pph_23_amount' => '200.00',
            'pph_4_2_applicable' => true,
            'pph_4_2_amount' => '0.01',
            'pph_21_applicable' => false,
        ]);

        $this->assertSame(
            $model->totalWithholdingExact(),
            Money::normalize($model->totalWithholding()),
            'Float wrapper drifted from the exact withholding total.'
        );
        $this->assertSame(
            $model->netPayableExact('10000.00'),
            Money::normalize($model->calculateNetPayable(10000.00)),
            'Float wrapper drifted from the exact net payable.'
        );
    }

    public function test_large_amounts_survive_the_cast_and_the_sum(): void
    {
        // decimal(20,2) permits 18 integer digits; a float loses precision well
        // below this, so this is the case the old float path could not hold.
        $model = $this->verification([
            'verified_ppn' => '0',
            'pph_23_applicable' => true,
            'pph_23_amount' => '123456789012345.67',
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);

        $this->assertSame('123456789012345.67', $model->totalWithholdingExact());
        $this->assertSame('876543210987654.33', $model->netPayableExact('1000000000000000.00'));
    }
}

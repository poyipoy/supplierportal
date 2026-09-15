<?php

namespace App\Services\Payment;

use App\Models\PaymentGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentVoucherService
{
    /**
     * Assign voucher number and date to a payment group.
     */
    public function assignVoucher(PaymentGroup $group, string $voucherNumber, string $voucherDate, User $actor): PaymentGroup
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only Finance or Admin can assign payment vouchers.');
        }

        return DB::transaction(function () use ($group, $voucherNumber, $voucherDate) {
            /** @var PaymentGroup $grp */
            $grp = PaymentGroup::where('id', $group->id)->lockForUpdate()->firstOrFail();

            $grp->update([
                'voucher_number' => trim($voucherNumber),
                'voucher_date' => $voucherDate,
            ]);

            return $grp->fresh();
        });
    }

    /**
     * Convert a number to Indonesian terbilang.
     */
    public function terbilang(?float $number): string
    {
        if ($number === null || $number <= 0) {
            return 'Nol';
        }

        return trim(preg_replace('/\s+/', ' ', $this->rawTerbilang($number)));
    }

    protected function rawTerbilang(float $number): string
    {
        $number = abs((int) floor($number));
        $huruf = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

        if ($number < 12) {
            return $huruf[$number];
        }
        if ($number < 20) {
            return $this->rawTerbilang($number - 10).' Belas';
        }
        if ($number < 100) {
            return $this->rawTerbilang((int) floor($number / 10)).' Puluh '.$huruf[$number % 10];
        }
        if ($number < 200) {
            return 'Seratus '.$this->rawTerbilang($number - 100);
        }
        if ($number < 1000) {
            return $this->rawTerbilang((int) floor($number / 100)).' Ratus '.$this->rawTerbilang($number % 100);
        }
        if ($number < 2000) {
            return 'Seribu '.$this->rawTerbilang($number - 1000);
        }
        if ($number < 1000000) {
            return $this->rawTerbilang((int) floor($number / 1000)).' Ribu '.$this->rawTerbilang($number % 1000);
        }
        if ($number < 1000000000) {
            return $this->rawTerbilang((int) floor($number / 1000000)).' Juta '.$this->rawTerbilang($number % 1000000);
        }
        if ($number < 1000000000000) {
            return $this->rawTerbilang((int) floor($number / 1000000000)).' Miliar '.$this->rawTerbilang(fmod($number, 1000000000));
        }

        return $this->rawTerbilang((int) floor($number / 1000000000000)).' Triliun '.$this->rawTerbilang(fmod($number, 1000000000000));
    }
}

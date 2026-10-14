<?php

namespace App\Services\Payment;

use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Services\LocalInvoice\MakerCheckerService;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaymentVoucherService
{
    public function nextVoucherNumber(): string
    {
        $period = BusinessTime::now()->format('ym');
        DB::table('payment_voucher_sequences')->insertOrIgnore(['period' => $period, 'last_number' => 0]);
        $sequence = DB::table('payment_voucher_sequences')->where('period', $period)->lockForUpdate()->first();
        $number = (int) $sequence->last_number + 1;
        DB::table('payment_voucher_sequences')->where('period', $period)->update(['last_number' => $number]);

        return 'VC/'.$period.'/'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Assign voucher number and date to a payment group.
     */
    public function assignVoucher(PaymentGroup $group, string $voucherNumber, string $voucherDate, User $actor, ?string $overrideReason = null): PaymentGroup
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException(__('finance.validation.voucher_role'));
        }

        return DB::transaction(function () use ($group, $voucherNumber, $voucherDate, $actor) {
            /** @var PaymentGroup $grp */
            $grp = PaymentGroup::where('id', $group->id)->lockForUpdate()->firstOrFail();
            $batch = $grp->batch()->lockForUpdate()->firstOrFail();
            if ($grp->status !== PaymentGroup::STATUS_UNPAID || ! in_array($batch->status, [PaymentBatch::STATUS_FINALIZED, PaymentBatch::STATUS_PARTIALLY_PAID], true)) {
                throw ValidationException::withMessages(['voucher' => __('finance_controls.voucher_status')]);
            }
            app(MakerCheckerService::class)->assertActorsRecorded('voucher_settlement', $batch, $actor, [$batch->finalized_by], $batch->actor_tracking_required);
            $before = $grp->only(['voucher_number', 'voucher_date']);

            $grp->update([
                'voucher_number' => trim($voucherNumber),
                'voucher_date' => $voucherDate,
            ]);
            app(LocalFinanceAuditService::class)->record($grp, 'group_voucher_assigned', $actor, $before, $grp->only(array_keys($before)));

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

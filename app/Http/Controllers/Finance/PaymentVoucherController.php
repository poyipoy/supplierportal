<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\HandlesManualPaymentVouchers;
use App\Http\Controllers\Controller;

class PaymentVoucherController extends Controller
{
    use HandlesManualPaymentVouchers;

    protected function routePrefix(): string
    {
        return 'finance.payment-vouchers';
    }
}

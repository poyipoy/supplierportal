<?php

namespace App\Policies;

use App\Models\LocalInvoiceVoucher;
use App\Models\User;

class LocalInvoiceVoucherPolicy
{
    public function view(User $user, LocalInvoiceVoucher $voucher): bool
    {
        return (bool) $user->is_active
            && ($user->isFinance() || $user->isPurchasing() || $user->isAdmin());
    }
}

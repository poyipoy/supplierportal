<?php

namespace App\Policies;

use App\Models\PaymentVoucher;
use App\Models\User;

class PaymentVoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canOperate($user);
    }

    public function view(User $user, PaymentVoucher $voucher): bool
    {
        return $this->canOperate($user);
    }

    public function create(User $user): bool
    {
        return $this->canOperate($user);
    }

    public function print(User $user, PaymentVoucher $voucher): bool
    {
        return $this->canOperate($user);
    }

    public function viewDocument(User $user, PaymentVoucher $voucher): bool
    {
        return $this->canOperate($user);
    }

    public function void(User $user, PaymentVoucher $voucher): bool
    {
        if (! $this->canOperate($user) || ! $voucher->isFinal()) {
            return false;
        }

        if ($user->isAdmin() || $user->isFinance()) {
            return true;
        }

        return $user->isPurchasing()
            && $voucher->source_department === PaymentVoucher::DEPT_PURCHASING;
    }

    private function canOperate(User $user): bool
    {
        return (bool) $user->is_active
            && ($user->isFinance() || $user->isPurchasing() || $user->isAdmin());
    }
}

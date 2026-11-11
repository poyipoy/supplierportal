<?php

namespace App\Policies;

use App\Models\PaymentVoucherDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class PaymentVoucherDocumentPolicy
{
    public function view(User $user, PaymentVoucherDocument $document): bool
    {
        return $document->voucher !== null
            && Gate::forUser($user)->allows('viewDocument', $document->voucher);
    }
}

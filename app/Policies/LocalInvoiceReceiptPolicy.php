<?php

namespace App\Policies;

use App\Models\LocalInvoiceReceipt;
use App\Models\User;

class LocalInvoiceReceiptPolicy
{
    public function view(User $user, LocalInvoiceReceipt $receipt): bool
    {
        return $receipt->invoice && $user->can('view', $receipt->invoice);
    }
}

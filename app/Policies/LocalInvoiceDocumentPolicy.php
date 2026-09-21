<?php

namespace App\Policies;

use App\Models\LocalInvoiceDocument;
use App\Models\User;

class LocalInvoiceDocumentPolicy
{
    public function view(User $user, LocalInvoiceDocument $document): bool
    {
        return $document->invoice && $user->can('view', $document->invoice);
    }
}

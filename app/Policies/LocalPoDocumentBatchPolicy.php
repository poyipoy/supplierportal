<?php

namespace App\Policies;

use App\Models\LocalPoDocumentBatch;
use App\Models\LocalPurchaseOrder;
use App\Models\User;

class LocalPoDocumentBatchPolicy
{
    public function view(User $user, LocalPoDocumentBatch $batch): bool
    {
        return $user->account_status === User::ACCOUNT_STATUS_ACTIVE
            && $user->can('uploadDocument', LocalPurchaseOrder::class) && (int) $batch->user_id === (int) $user->id;
    }

    public function retry(User $user, LocalPoDocumentBatch $batch): bool
    {
        return $this->view($user, $batch) && $batch->status === 'FAILED';
    }
}

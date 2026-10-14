<?php

namespace App\Services\LocalInvoice;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class MakerCheckerService
{
    public function __construct(private LocalFinanceAuditService $audit) {}

    public function assertDifferent(string $control, Model $subject, User $actor, array $previousActorIds, ?string $overrideReason = null, bool $requireActors = false): void
    {
        if (! config("finance.maker_checker.{$control}", true)) {
            return;
        }

        $this->assertActorsRecorded($control, $subject, $actor, $previousActorIds, $requireActors);

        $sameActor = in_array((int) $actor->id, array_map('intval', $previousActorIds), true);
        if (! $sameActor) {
            return;
        }

        $reason = trim((string) $overrideReason);
        if (! $actor->isAdmin() || ! config('finance.maker_checker.allow_admin_override', false) || $reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['maker_checker' => __('finance_controls.different_actor')]);
        }

        $this->audit->record($subject, 'maker_checker_override', $actor, metadata: ['control' => $control, 'reason' => $reason, 'previous_actor_ids' => $previousActorIds]);
    }

    public function assertActorsRecorded(string $control, Model $subject, User $actor, array $previousActorIds, bool $requireActors = false): void
    {
        if (! config("finance.maker_checker.{$control}", true)) {
            return;
        }

        $missing = in_array(null, $previousActorIds, true) || in_array(0, $previousActorIds, true);
        if ($missing && $requireActors) {
            throw ValidationException::withMessages(['maker_checker' => __('finance_controls.missing_actor')]);
        }
        if ($missing) {
            $this->audit->record($subject, 'historical_actor_unknown', $actor, metadata: ['control' => $control]);
        }
    }
}

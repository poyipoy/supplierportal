<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\Supplier;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use App\Models\User;
use App\Services\SupplierAudit\SupplierAuditAnswerService;
use App\Services\SupplierAudit\SupplierAuditAssignmentService;
use Database\Seeders\SupplierAuditTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class SupplierAuditTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->seed(SupplierAuditTemplateSeeder::class);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'name' => 'Purchasing User']);
    }

    /** Supplier dengan scope eksplisit; factory menambah scope import secara default sehingga dihapus dulu. */
    protected function supplier(array $scopes = ['local'], array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            $user->supplierScopes()->create(['scope' => $scope]);
        }
        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'Vendor '.$user->id,
            'address' => 'Jakarta',
            'phone' => '123',
            'npwp' => '123',
            'category' => 'Parts',
            'payment_term_days' => 30,
            'is_pkp' => true,
        ]);

        return $user->fresh();
    }

    protected function assign(User $supplier, ?string $dueDate = null, string $period = '2026 Semester II'): SupplierAudit
    {
        $result = app(SupplierAuditAssignmentService::class)->assign($this->purchasing, [$supplier->id], $period, $dueDate);

        return $result['created']->first()->fresh();
    }

    /** @return array<string, array{answer: string, score: ?int}> */
    protected function completeAnswers(SupplierAudit $audit, string $answer = 'YES', ?int $score = 4): array
    {
        return $audit->answers()->pluck('supplier_audit_criterion_id')
            ->mapWithKeys(fn ($id) => [(string) $id => ['answer' => $answer, 'score' => $answer === 'YES' ? $score : null]])
            ->all();
    }

    protected function submitComplete(SupplierAudit $audit, User $supplier): SupplierAudit
    {
        return app(SupplierAuditAnswerService::class)->save($supplier, $audit, $this->completeAnswers($audit), true)->fresh();
    }

    protected function firstCriterionId(SupplierAudit $audit): int
    {
        return (int) $audit->answers()->orderBy('sort_order_snapshot')->value('supplier_audit_criterion_id');
    }

    protected function answerFor(SupplierAudit $audit, int $criterionId): SupplierAuditAnswer
    {
        return $audit->answers()->where('supplier_audit_criterion_id', $criterionId)->firstOrFail();
    }
}

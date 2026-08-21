<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ExportJob;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\NotificationUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationUrlResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_normalizes_absolute_and_legacy_pr_urls_for_each_role(): void
    {
        [$admin, $purchasing, $supplier, $qc, $pr, $quotation, $po] = $this->procurementData();
        $resolver = app(NotificationUrlResolver::class);

        $absolute = $this->notification($purchasing, [
            'url' => 'https://old-host.test:9999'.route('purchasing.requisitions.show', $pr, absolute: false).'?tab=quotation',
        ]);
        $this->assertSame(
            route('purchasing.requisitions.show', $pr, absolute: false).'?tab=quotation',
            $resolver->resolve($absolute, $purchasing),
        );

        $legacy = $this->notification($admin, [
            'url' => '/purchasing/requirements/'.$pr->getRouteKey(),
        ]);
        $this->assertSame(route('admin.requisitions.show', $pr, absolute: false), $resolver->resolve($legacy, $admin));

        $supplierFallback = $this->notification($supplier, ['url' => '#', 'quotation_id' => $quotation->id]);
        $this->assertSame(route('supplier.quotations.show', $quotation, absolute: false), $resolver->resolve($supplierFallback, $supplier));

        $qcFallback = $this->notification($qc, ['url' => '#', 'po_id' => $po->id]);
        $this->assertSame(route('qc.inspections.create', $po, absolute: false), $resolver->resolve($qcFallback, $qc));
    }

    public function test_resolver_rejects_dangerous_deleted_and_cross_supplier_targets(): void
    {
        [, , $supplier, , $pr, $quotation, $po] = $this->procurementData();
        $otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $resolver = app(NotificationUrlResolver::class);

        foreach (['javascript:alert(1)', 'data:text/html,test', '//evil.test/path', "\x01/supplier/dashboard"] as $url) {
            $notification = $this->notification($supplier, ['url' => $url]);
            $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($notification, $supplier));
        }

        $crossQuotation = $this->notification($otherSupplier, [
            'url' => route('supplier.quotations.show', $quotation, absolute: false),
            'quotation_id' => $quotation->id,
        ]);
        $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($crossQuotation, $otherSupplier));

        $crossPo = $this->notification($otherSupplier, [
            'url' => route('supplier.purchase-orders.show', $po, absolute: false),
            'po_id' => $po->id,
        ]);
        $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($crossPo, $otherSupplier));

        $quotation->delete();
        $deleted = $this->notification($supplier, ['url' => '#', 'quotation_id' => $quotation->id, 'pr_id' => null]);
        $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($deleted, $supplier));

        $this->assertTrue($pr->isVisibleToSupplier($supplier->id));
    }

    public function test_supplier_claim_and_conversation_fallbacks_enforce_membership(): void
    {
        [, $purchasing, $supplier, $qc, , , $po] = $this->procurementData();
        $otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $purchasing->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'description' => 'Claim',
        ]);
        $conversation = Conversation::create([
            'conversable_type' => PurchaseOrder::class,
            'conversable_id' => $po->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
        $resolver = app(NotificationUrlResolver::class);

        $ownClaim = $this->notification($supplier, ['url' => '#', 'claim_id' => $claim->id]);
        $this->assertSame(route('supplier.claims.show', $claim, absolute: false), $resolver->resolve($ownClaim, $supplier));

        $ownConversation = $this->notification($supplier, ['url' => '#', 'conversation_id' => $conversation->id]);
        $this->assertSame(route('supplier.conversations.show', $conversation, absolute: false), $resolver->resolve($ownConversation, $supplier));

        $foreign = $this->notification($otherSupplier, ['url' => '#', 'conversation_id' => $conversation->id, 'claim_id' => $claim->id]);
        $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($foreign, $otherSupplier));
    }

    public function test_numeric_legacy_paths_are_canonicalized_without_metadata(): void
    {
        [, $purchasing, $supplier, $qc, , , $po] = $this->procurementData();
        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $purchasing->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'description' => 'Legacy URL claim',
        ]);
        $conversation = Conversation::create([
            'conversable_type' => PurchaseOrder::class,
            'conversable_id' => $po->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
        $resolver = app(NotificationUrlResolver::class);

        $cases = [
            [$purchasing, "/purchasing/conversations/{$conversation->id}?tab=messages#latest", route('purchasing.conversations.show', $conversation, absolute: false).'?tab=messages#latest'],
            [$supplier, "/supplier/conversations/{$conversation->id}", route('supplier.conversations.show', $conversation, absolute: false)],
            [$purchasing, "/purchasing/purchase-orders/{$po->id}", route('purchasing.purchase-orders.show', $po, absolute: false)],
            [$supplier, "/supplier/purchase-orders/{$po->id}", route('supplier.purchase-orders.show', $po, absolute: false)],
            [$purchasing, "/purchasing/claims/{$claim->id}", route('purchasing.claims.show', $claim, absolute: false)],
            [$supplier, "/supplier/claims/{$claim->id}", route('supplier.claims.show', $claim, absolute: false)],
            [$purchasing, "/purchasing/claims/create/{$inspection->id}", route('purchasing.claims.create', $inspection, absolute: false)],
            [$qc, "/qc/inspections/{$po->id}/create", route('qc.inspections.create', $po, absolute: false)],
        ];

        foreach ($cases as [$user, $legacyUrl, $expected]) {
            $notification = $this->notification($user, ['url' => $legacyUrl]);
            $resolved = $resolver->resolve($notification, $user);

            $this->assertSame($expected, $resolved);
        }

        $missing = $this->notification($supplier, ['url' => '/supplier/conversations/999999']);
        $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($missing, $supplier));
    }

    public function test_export_urls_are_owner_scoped_and_canonicalized_to_hashids(): void
    {
        [, $purchasing] = $this->procurementData();
        $otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        Storage::fake('private');
        $exportJob = ExportJob::create([
            'user_id' => $purchasing->id,
            'label' => 'Notification export',
            'export_class' => 'App\\Exports\\RequisitionsExport',
            'export_args' => [null, null, null],
            'file_name' => 'notification-export.xlsx',
            'file_path' => 'exports/'.$purchasing->id.'/notification/notification-export.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_COMPLETED,
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('private')->put($exportJob->file_path, 'export contents');
        $resolver = app(NotificationUrlResolver::class);

        $legacyOwnUrl = $this->notification($purchasing, [
            'url' => '/exports/'.$exportJob->id.'/download?source=notification',
        ]);
        $resolved = $resolver->resolve($legacyOwnUrl, $purchasing);

        $this->assertSame(
            route('exports.download', $exportJob, absolute: false).'?source=notification',
            $resolved,
        );
        $this->assertStringNotContainsString('/exports/'.$exportJob->id.'/download', $resolved);

        $queued = ExportJob::create([
            'user_id' => $purchasing->id,
            'label' => 'Queued export',
            'export_class' => 'App\\Exports\\RequisitionsExport',
            'export_args' => [null, null, null],
            'file_name' => 'queued.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
        ]);
        $fallback = $this->notification($purchasing, ['url' => '#', 'export_job_id' => $queued->id]);
        $this->assertSame(route('exports.index', absolute: false), $resolver->resolve($fallback, $purchasing));

        $crossUserUrl = $this->notification($otherSupplier, [
            'url' => route('exports.download', $exportJob, absolute: false),
        ]);
        $this->assertSame(route('supplier.dashboard', absolute: false), $resolver->resolve($crossUserUrl, $otherSupplier));
    }

    public function test_admin_requisition_detail_is_read_only(): void
    {
        [$admin, , , , $pr] = $this->procurementData();
        $pr->update(['notes' => 'Admin-only audit note']);

        $this->actingAs($admin)
            ->get(route('admin.requisitions.show', $pr))
            ->assertOk()
            ->assertSeeText($pr->pr_number)
            ->assertSeeText('Admin-only audit note')
            ->assertDontSee('Submit Requisition')
            ->assertDontSee('Edit Draft');
    }

    private function procurementData(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $period = Period::create(['name' => 'Notification Period', 'month' => 8, 'year' => 2026, 'status' => 'open', 'created_by' => $admin->id]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/001',
            'status' => 'submitted',
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/08/2026/001',
            'status' => 'waiting_qc',
            'created_by' => $purchasing->id,
        ]);
        $po->quotations()->attach($quotation->id);

        return [$admin, $purchasing, $supplier, $qc, $pr, $quotation, $po];
    }

    private function notification(User $user, array $data): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => SystemNotification::class,
            'data' => array_merge([
                'title' => 'Notification',
                'message' => 'Notification target',
                'url' => '#',
                'icon' => 'bell',
            ], $data),
        ]);
    }
}

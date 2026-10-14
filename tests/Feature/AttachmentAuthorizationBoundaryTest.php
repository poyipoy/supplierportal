<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\LocalPurchaseOrder;
use App\Models\Message;
use App\Models\SupplierAudit;
use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttachmentAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_attachments_require_membership_for_purchasing_and_admin(): void
    {
        $member = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create();
        $conversation = new Conversation(['purchasing_user_id' => $member->id, 'supplier_user_id' => $supplier->id]);
        $message = (new Message)->setRelation('conversation', $conversation);
        $attachment = (new Attachment(['attachable_type' => Message::class]))->setRelation('attachable', $message);
        $policy = app(AttachmentPolicy::class);

        $this->assertTrue($policy->view($member, $attachment));
        $this->assertTrue($policy->view($supplier, $attachment));
        $this->assertFalse($policy->view(User::factory()->create(['role' => 'purchasing']), $attachment));
        $this->assertFalse($policy->view(User::factory()->create(['role' => 'admin']), $attachment));
    }

    public function test_local_po_and_refund_attachments_require_current_local_scope_even_for_owner(): void
    {
        $supplier = User::factory()->create();
        $policy = app(AttachmentPolicy::class);
        foreach ([LocalPurchaseOrder::class, SupplierOverpaymentRefund::class] as $model) {
            $owner = new $model(['supplier_id' => $supplier->id]);
            $attachment = (new Attachment(['attachable_type' => $model]))->setRelation('attachable', $owner);
            $this->assertFalse($policy->view($supplier->fresh(), $attachment));
            $supplier->supplierScopes()->firstOrCreate(['scope' => 'local']);
            $this->assertTrue($policy->view($supplier->fresh(), $attachment));
            $supplier->supplierScopes()->where('scope', 'local')->delete();
            $this->assertFalse($policy->view($supplier->fresh(), $attachment));
        }
    }

    public function test_audit_attachments_use_audit_policy_before_blanket_admin_access(): void
    {
        $audit = new SupplierAudit(['supplier_id' => User::factory()->create()->id, 'status' => SupplierAudit::STATUS_SUBMITTED]);
        $attachment = (new Attachment(['attachable_type' => SupplierAudit::class]))->setRelation('attachable', $audit);
        $policy = app(AttachmentPolicy::class);

        $this->assertTrue($policy->view(User::factory()->create(['role' => 'purchasing']), $attachment));
        $this->assertFalse($policy->view(User::factory()->create(['role' => 'purchasing', 'is_active' => false]), $attachment));
        $this->assertFalse($policy->view(User::factory()->create(['role' => 'admin']), $attachment));
        $this->assertFalse($policy->view(User::factory()->create(['role' => 'finance']), $attachment));
    }
}

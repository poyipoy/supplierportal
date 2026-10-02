<?php

namespace Tests\Feature;

use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\User;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegionalMaterialClaimDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private MaterialClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(12, 0));
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $qc = User::factory()->create(['role' => 'qc']);
        $po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'currency' => 'IDR', 'po_number' => 'PO-REG-CLAIM', 'status' => 'claim_needed', 'created_by' => $this->purchasing->id]);
        $inspection = QcInspection::create(['po_id' => $po->id, 'inspected_by' => $qc->id, 'status' => 'ng', 'inspected_at' => '2026-09-28 23:35:00']);
        $this->claim = MaterialClaim::create(['inspection_id' => $inspection->id, 'po_id' => $po->id, 'submitted_by' => $this->purchasing->id, 'supplier_id' => $this->supplier->id, 'status' => 'responded', 'description' => 'Defect <script>alert(1)</script>', 'resolution_expected' => 'Replace lot', 'supplier_response' => 'Response text', 'deadline' => '2026-09-30']);
        $this->claim->forceFill(['created_at' => '2026-09-28 23:35:00', 'updated_at' => '2026-09-29 23:35:00'])->save();
    }

    public function test_system_preserves_calendar_and_event_legacy_labels(): void
    {
        foreach ($this->portals() as $portal => $user) {
            $html = $this->detail($portal, $user)->assertOk()->getContent();
            $this->hasText('30 September 2026', $html);
            $this->hasText($portal === 'purchasing' ? '28 Sep 2026, 23:35' : '28 September 2026', $html);
            $this->hasText('29 Sep 2026, 23:35', $html);
            $this->hasText('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
    }

    public function test_calendar_and_event_displays_are_distinct_and_machine_and_model_values_are_unchanged(): void
    {
        $raw = $this->claim->fresh()->getRawOriginal();
        foreach ($this->portals() as $portal => $user) {
            $legacy = $this->detail($portal, $user)->assertOk()->getContent();
            $this->preference($user);
            $html = $this->detail($portal, $user)->assertOk()->getContent();
            $this->hasText('30/09/2026', $html);
            $this->hasText($portal === 'purchasing' ? '29/09/2026, 6:35 AM WIB' : '29/09/2026 WIB', $html);
            $this->hasText('30/09/2026, 6:35 AM WIB', $html);
            $this->assertSame($this->machine($legacy), $this->machine($html));
        }
        $this->assertSame($raw, $this->claim->fresh()->getRawOriginal());
    }

    public function test_human_dmy_iso_deadlines_never_shift_and_pending_form_stays_canonical(): void
    {
        $this->claim->forceFill(['status' => 'pending'])->save();
        foreach (['human' => '30 Sep 2026', 'dmy' => '30/09/2026', 'iso' => '2026-09-30'] as $key => $label) {
            foreach ($this->portals() as $portal => $user) {
                $legacy = $this->detail($portal, $user)->assertOk()->getContent();
                $this->preference($user, ['date_format' => $key]);
                $html = $this->detail($portal, $user)->assertOk()->getContent();
                $this->hasText($label, $html);
                $this->assertSame($this->machine($legacy), $this->machine($html));
                if ($portal === 'purchasing') {
                    $this->hasText('Response deadline: '.$label.'.', $html);
                }
            }
        }
        $this->assertSame('2026-09-30', $this->claim->fresh()->getRawOriginal('deadline'));
    }

    public function test_null_deadline_has_bounded_placeholder_on_both_views(): void
    {
        $this->claim->forceFill(['deadline' => null, 'status' => 'pending'])->save();
        foreach ($this->portals() as $portal => $user) {
            $this->preference($user);
            $html = $this->detail($portal, $user)->assertOk()->getContent();
            $this->hasText($portal === 'purchasing' ? 'Response deadline: -.' : '<span>-</span>', $html);
        }
        $this->assertNull($this->claim->fresh()->getRawOriginal('deadline'));
    }

    public function test_metadata_and_response_resolve_guards_are_independent_of_preferences(): void
    {
        foreach (['2026-09-27', '2026-09-28', '2026-10-01', '2026-10-02', null] as $deadline) {
            foreach (['pending', 'responded', 'resolved', 'escalated'] as $status) {
                $this->claim->forceFill(['deadline' => $deadline, 'status' => $status])->save();
                $before = StatusHelper::claimDeadlineMeta($this->claim->deadline, $status);
                $this->preference($this->purchasing);
                $this->detail('purchasing', $this->purchasing)->assertOk();
                $after = $this->claim->fresh();
                $this->assertSame($before, StatusHelper::claimDeadlineMeta($after->deadline, $after->status));
                $this->assertSame($status, $after->status);
            }
        }
        $this->claim->forceFill(['status' => 'pending'])->save();
        $this->actingAs($this->purchasing)->post(route('purchasing.claims.resolve', $this->claim))->assertRedirect()->assertSessionHas('error');
        $this->assertSame('pending', $this->claim->fresh()->status);
        $this->claim->forceFill(['status' => 'resolved'])->save();
        $this->preference($this->supplier);
        $this->actingAs($this->supplier)->post(route('supplier.claims.respond', $this->claim), ['supplier_response' => 'must not replace'])->assertRedirect()->assertSessionHas('error');
        $this->assertSame('resolved', $this->claim->fresh()->status);
        $this->assertSame('Response text', $this->claim->fresh()->supplier_response);
    }

    public function test_one_preference_lookup_on_both_details(): void
    {
        $queries = (object) ['count' => 0];
        DB::listen(function ($query) use ($queries): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries->count++;
            }
        });
        foreach ($this->portals() as $portal => $user) {
            $this->preference($user);
            $queries->count = 0;
            $this->detail($portal, $user)->assertOk();
            $this->assertSame(1, $queries->count);
        }
    }

    public function test_ownership_import_scope_hashid_and_private_attachment_access_are_preserved(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('attachments/regional-claim.pdf', 'private evidence');
        $attachment = $this->claim->attachments()->create(['file_path' => 'attachments/regional-claim.pdf', 'file_name' => 'claim.pdf', 'file_type' => 'application/pdf', 'uploaded_by' => $this->supplier->id]);
        $this->preference($this->supplier);
        $this->detail('supplier', $this->supplier)->assertOk();
        $this->actingAs($this->supplier)->get(route('attachments.show', $attachment))->assertOk();
        $this->actingAs($this->supplier)->get(route('supplier.claims.show', $this->claim->id))->assertNotFound();
        $other = User::factory()->create(['role' => 'supplier']);
        $this->preference($other);
        $this->detail('supplier', $other)->assertForbidden();
        $this->actingAs($other)->get(route('attachments.show', $attachment))->assertForbidden();
        $this->supplier->supplierScopes()->delete();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        $this->detail('supplier', $this->supplier)->assertForbidden();
    }

    private function portals(): array
    {
        return ['purchasing' => $this->purchasing, 'supplier' => $this->supplier];
    }

    private function detail(string $portal, User $user)
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($user)->get(route($portal.'.claims.show', $this->claim));
    }

    private function preference(User $user, array $regional = []): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->updateOrCreate([], [...$defaults, 'timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', ...$regional]);
    }

    private function hasText(string $expected, string $html): void
    {
        $this->assertTrue(str_contains($html, $expected), 'Missing claim detail text: '.$expected);
    }

    private function machine(string $html): array
    {
        preg_match_all('/<(?:input|select|option|form)\b[^>]*>|\b(?:href|data-[\w-]+|datetime)="[^"]*"/', $html, $matches);

        return $matches[0];
    }
}

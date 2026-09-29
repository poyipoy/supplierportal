<?php

namespace Tests\Feature;

use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalMaterialClaimDataTablesContractTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $foreignSupplier;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(23, 35));
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->foreignSupplier = User::factory()->create(['role' => 'supplier']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_deadline_presets_preserve_raw_fields_deferred_aliases_actions_and_sla(): void
    {
        $claims = collect([
            $this->claim($this->supplier, '2026-09-27', 'pending'),
            $this->claim($this->supplier, '2026-09-28', 'pending'),
            $this->claim($this->supplier, '2026-10-01', 'responded'),
            $this->claim($this->supplier, '2026-10-02', 'resolved'),
            $this->claim($this->supplier, null, 'escalated'),
        ]);
        $attributes = $claims->map(fn ($claim) => $claim->getRawOriginal())->all();
        foreach (['purchasing', 'supplier'] as $portal) {
            $user = $portal === 'purchasing' ? $this->purchasing : $this->supplier;
            $this->preference($user, 'system');
            $baseline = $this->ajax($user, $portal)->assertOk()->json();
            foreach (['system' => '27 Sep 2026', 'human' => '27 Sep 2026', 'dmy' => '27/09/2026', 'iso' => '2026-09-27'] as $preset => $expected) {
                $this->preference($user, $preset);
                $result = $this->ajax($user, $portal)->assertOk()->json();
                $this->assertSame($baseline['recordsTotal'], $result['recordsTotal']);
                $this->assertSame($baseline['recordsFiltered'], $result['recordsFiltered']);
                $this->assertSame(array_column($baseline['data'], 'id'), array_column($result['data'], 'id'));
                $baseRows = collect($baseline['data'])->keyBy('id');
                $rows = collect($result['data'])->keyBy('id');
                $this->assertStringContainsString('<span>'.$expected.'</span>', $rows[$claims[0]->id]['deadline_display']);
                $this->assertStringContainsString('<span>-</span>', $rows[$claims[4]->id]['deadline_display']);
                foreach ($rows as $id => $row) {
                    $this->assertSame($this->withoutDeadline($baseRows[$id]), $this->withoutDeadline($row));
                    $this->assertSame($this->badgeHtml($baseRows[$id]), $this->badgeHtml($row));
                }
            }
        }
        $this->assertSame($attributes, $claims->map(fn ($claim) => $claim->fresh()->getRawOriginal())->all());
    }

    public function test_deadline_order_filters_and_pagination_preserve_canonical_results(): void
    {
        $pending = $this->claim($this->supplier, '2026-09-30', 'pending');
        $this->claim($this->supplier, '2026-10-02', 'responded');
        $this->claim($this->foreignSupplier, '2026-10-01', 'resolved');
        foreach (['purchasing', 'supplier'] as $portal) {
            $user = $portal === 'purchasing' ? $this->purchasing : $this->supplier;
            foreach ([
                ['order' => [['column' => $portal === 'purchasing' ? 4 : 3, 'dir' => 'asc']]],
                ['order' => [['column' => $portal === 'purchasing' ? 4 : 3, 'dir' => 'desc']]],
                ['columns' => $this->columns($portal, ['deadline' => '2026-09-30'])],
                ['columns' => $this->columns($portal, ['status' => 'pending'])],
                ['start' => 1, 'length' => 1],
            ] as $parameters) {
                $this->preference($user, 'system');
                $before = $this->ajax($user, $portal, $parameters)->assertOk()->json();
                if (isset($parameters['columns'])) {
                    $this->assertSame(1, $before['recordsFiltered']);
                    $this->assertSame([$pending->id], array_column($before['data'], 'id'));
                }
                if (isset($parameters['start'])) {
                    $this->assertCount(1, $before['data']);
                }
                $this->preference($user, 'dmy');
                $after = $this->ajax($user, $portal, $parameters)->assertOk()->json();
                $this->assertSame($before['recordsTotal'], $after['recordsTotal']);
                $this->assertSame($before['recordsFiltered'], $after['recordsFiltered']);
                $this->assertSame(array_map($this->withoutDeadline(...), $before['data']), array_map($this->withoutDeadline(...), $after['data']));
            }
        }
    }

    public function test_preference_lookup_is_one_for_one_and_many_rows_and_supplier_scope_is_owned(): void
    {
        $owned = $this->claim($this->supplier, '2026-09-30', 'pending');
        $foreign = $this->claim($this->foreignSupplier, '2026-10-01', 'pending');
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });
        foreach (['purchasing', 'supplier'] as $portal) {
            $user = $portal === 'purchasing' ? $this->purchasing : $this->supplier;
            $this->preference($user, 'iso');
            foreach ([1, 25] as $length) {
                $counter->count = 0;
                $response = $this->ajax($user, $portal, ['length' => $length])->assertOk();
                $this->assertSame(1, $counter->count);
                if ($portal === 'supplier') {
                    $this->assertSame([$owned->id], array_column($response->json('data'), 'id'));
                    $this->assertStringNotContainsString($foreign->claim_number, $response->getContent());
                }
            }
        }
        for ($i = 0; $i < 5; $i++) {
            $this->claim($this->supplier, '2026-10-02', 'pending');
        }
        foreach (['purchasing', 'supplier'] as $portal) {
            $counter->count = 0;
            $user = $portal === 'purchasing' ? $this->purchasing : $this->supplier;
            $this->ajax($user, $portal)->assertOk()->assertJsonPath('recordsTotal', $portal === 'purchasing' ? 7 : 6);
            $this->assertSame(1, $counter->count);
        }
    }

    public function test_local_supplier_and_unrelated_role_cannot_access_import_claim_presenters(): void
    {
        $local = User::factory()->create(['role' => 'supplier']);
        $local->supplierScopes()->delete();
        $local->supplierScopes()->create(['scope' => 'local']);
        $this->ajax($local, 'supplier')->assertForbidden();
        $this->ajax($this->supplier, 'purchasing')->assertForbidden();
    }

    public function test_action_needed_inspection_alias_actions_and_raw_response_are_unchanged(): void
    {
        // Compare the normal response contract without debug query timing/history diagnostics.
        config(['app.debug' => false]);
        // A resolved claim keeps this claim-needed PO eligible without an active claim.
        $claim = $this->claim($this->supplier, '2026-09-30', 'resolved');
        $parameters = ['draw' => 7, 'start' => 0, 'length' => 25, 'columns' => [], 'order' => [], 'search' => ['value' => '', 'regex' => 'false']];
        foreach ([
            ['po_number_display', 'po_number', true, true],
            ['supplier_name', 'supplier_name', false, true],
            ['inspection_date', 'inspection_date', false, false],
            ['status_badge', 'status', true, false],
            ['action', 'action', false, false],
        ] as [$data, $name, $orderable, $searchable]) {
            $parameters['columns'][] = ['data' => $data, 'name' => $name, 'orderable' => $orderable ? 'true' : 'false', 'searchable' => $searchable ? 'true' : 'false', 'search' => ['value' => '', 'regex' => 'false']];
        }
        $request = function () use ($parameters) {
            $this->app->forgetScopedInstances();

            return $this->actingAs($this->purchasing)->getJson(route('purchasing.claims.data-action').'?'.http_build_query($parameters), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        };
        $this->preference($this->purchasing, 'system');
        $before = $request()->json();
        $this->assertSame(1, $before['recordsTotal']);
        $this->assertSame(1, $before['recordsFiltered']);
        $this->assertSame($claim->po_id, $before['data'][0]['id']);
        $this->assertNotSame('-', $before['data'][0]['inspection_date']);
        $this->assertStringContainsString(route('purchasing.claims.create', $claim->inspection, absolute: false), $before['data'][0]['action']);
        $this->preference($this->purchasing, 'iso');
        $after = $request()->json();
        $this->assertSame($before, $after);
    }

    private function columns(string $portal, array $searches = []): array
    {
        $mapping = ['claim_id' => 'id', 'po_number' => 'po_number'];
        if ($portal === 'purchasing') {
            $mapping['supplier_name'] = 'supplier_name';
        }
        $mapping += ['created_date' => 'created_at', 'deadline_display' => 'deadline', 'status_badge' => 'status', 'action' => 'action'];
        $columns = [];
        foreach ($mapping as $data => $name) {
            // Ordinary UI status searching is disabled. This crafted column-search case
            // explicitly enables it to prove the canonical status predicate stays stable.
            $searchable = ! in_array($data, ['status_badge', 'action'], true) || ($name === 'status' && isset($searches['status']));
            $orderable = ! in_array($data, ['po_number', 'supplier_name', 'action'], true);
            $columns[] = ['data' => $data, 'name' => $name, 'searchable' => $searchable ? 'true' : 'false', 'orderable' => $orderable ? 'true' : 'false', 'search' => ['value' => $searches[$name] ?? '', 'regex' => 'false']];
        }

        return $columns;
    }

    private function ajax(User $user, string $portal, array $extra = [])
    {
        $this->app->forgetScopedInstances();
        $parameters = $extra + ['draw' => 7, 'start' => 0, 'length' => 25, 'columns' => $this->columns($portal), 'order' => [], 'search' => ['value' => '', 'regex' => 'false']];
        $route = $portal === 'purchasing' ? 'purchasing.claims.data-history' : 'supplier.claims.index';

        return $this->actingAs($user)->withSession(['supplier_context' => 'import'])->getJson(route($route).'?'.http_build_query($parameters), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function preference(User $user, string $preset): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->updateOrCreate([], [...$defaults, 'timezone' => 'Asia/Jakarta', 'date_format' => $preset]);
    }

    private function withoutDeadline(array $row): array
    {
        unset($row['deadline_display']);

        return $row;
    }

    private function badgeHtml(array $row): string
    {
        return preg_replace('/<span>.*?<\/span>/', '<span>DATE</span>', $row['deadline_display'], 1);
    }

    private function claim(User $supplier, ?string $deadline, string $status): MaterialClaim
    {
        $this->sequence++;
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'IDR', 'po_number' => 'PO-REG-CLAIM-'.$this->sequence, 'status' => 'claim_needed', 'created_by' => $this->purchasing->id]);
        $inspection = QcInspection::create(['po_id' => $po->id, 'inspected_by' => $this->purchasing->id, 'status' => 'ng', 'inspected_at' => '2026-09-28 23:35:00']);
        $claim = MaterialClaim::create(['inspection_id' => $inspection->id, 'po_id' => $po->id, 'supplier_id' => $supplier->id, 'submitted_by' => $this->purchasing->id, 'status' => $status, 'description' => 'Regional boundary claim', 'resolution_expected' => 'Replacement', 'deadline' => $deadline]);
        $claim->forceFill(['created_at' => now()->addSeconds($this->sequence)])->save();

        return $claim->fresh();
    }
}

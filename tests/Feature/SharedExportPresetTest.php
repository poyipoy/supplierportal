<?php

namespace Tests\Feature;

use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedExportPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingLead;

    private User $purchasingColleague;

    private User $supplier;

    private User $otherSupplier;

    private User $finance;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasingLead = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->purchasingColleague = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->accounting = User::factory()->create(['role' => 'accounting', 'is_active' => true]);
    }

    public function test_internal_team_presets_are_shared_within_authorized_key_and_can_be_duplicated(): void
    {
        $created = $this->actingAs($this->purchasingLead)
            ->postJson(route('export-presets.store'), [
                'export_key' => 'purchasing.po',
                'scope' => 'team',
                'name' => 'Monthly Ops Standard',
                'columns' => ['po_number', 'supplier', 'status'],
                'format' => 'csv',
                'csv_delimiter' => ';',
                'filters' => ['status' => 'active'],
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scope', 'team')
            ->assertJsonPath('csv_delimiter', ';')
            ->assertJsonPath('is_default', false)
            ->assertJsonPath('can_edit', true)
            ->json();

        $teamPreset = ExportPreset::where('name', 'Monthly Ops Standard')->firstOrFail();

        // Colleague in same authorized internal role sees team preset in team group, read-only
        $this->actingAs($this->purchasingColleague)
            ->getJson(route('export-presets.index', ['export_key' => 'purchasing.po']))
            ->assertOk()
            ->assertJsonCount(0, 'personal')
            ->assertJsonCount(1, 'team')
            ->assertJsonPath('team.0.id', $created['id'])
            ->assertJsonPath('team.0.scope', 'team')
            ->assertJsonPath('team.0.can_edit', false)
            ->assertJsonPath('team.0.can_delete', false)
            ->assertJsonPath('data.0.id', $created['id']);

        // Colleague cannot overwrite, delete, or set default on someone else's team preset
        $this->actingAs($this->purchasingColleague)
            ->putJson(route('export-presets.update', $teamPreset), [
                'name' => 'Hijacked',
                'columns' => ['po_number'],
                'format' => 'xlsx',
            ])
            ->assertForbidden();
        $this->actingAs($this->purchasingColleague)
            ->deleteJson(route('export-presets.destroy', $teamPreset))
            ->assertForbidden();
        $this->actingAs($this->purchasingColleague)
            ->postJson(route('export-presets.default', $teamPreset))
            ->assertForbidden();

        // Colleague CAN duplicate team preset into their own personal scope
        $duplicated = $this->actingAs($this->purchasingColleague)
            ->postJson(route('export-presets.duplicate', $teamPreset))
            ->assertCreated()
            ->assertJsonPath('scope', 'personal')
            ->assertJsonPath('csv_delimiter', ';')
            ->assertJsonPath('columns', ['po_number', 'supplier', 'status'])
            ->assertJsonPath('can_edit', true)
            ->json();

        $this->assertNotSame($created['id'], $duplicated['id']);
        $this->assertStringContainsString('Monthly Ops Standard', $duplicated['name']);

        // Team preset remains untouched
        $this->assertSame('Monthly Ops Standard', $teamPreset->fresh()->name);
    }

    public function test_suppliers_are_strictly_forbidden_from_creating_viewing_or_duplicating_team_presets(): void
    {
        // Supplier cannot create a team preset even for supplier.po
        $this->actingAs($this->supplier)
            ->postJson(route('export-presets.store'), [
                'export_key' => 'supplier.po',
                'scope' => 'team',
                'name' => 'Leaked Team Preset',
                'columns' => ['po_number'],
                'format' => 'xlsx',
                'filters' => [],
            ])
            ->assertForbidden();

        // Even if a corrupted team preset row existed for supplier.po, index never exposes it and policy denies access
        $corrupted = ExportPreset::create([
            'user_id' => $this->otherSupplier->id,
            'export_key' => 'supplier.po',
            'scope' => ExportPreset::SCOPE_TEAM,
            'name' => 'Corrupted Supplier Team',
            'columns' => ['po_number'],
            'format' => 'xlsx',
            'csv_delimiter' => ',',
            'filters' => [],
            'is_default' => false,
        ]);

        $this->actingAs($this->supplier)
            ->getJson(route('export-presets.index', ['export_key' => 'supplier.po']))
            ->assertOk()
            ->assertJsonCount(0, 'team')
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('can_manage_team', false);

        $this->actingAs($this->supplier)
            ->postJson(route('export-presets.duplicate', $corrupted))
            ->assertForbidden();

        // Supplier also cannot duplicate an internal team preset
        $internalTeam = ExportPreset::create([
            'user_id' => $this->purchasingLead->id,
            'export_key' => 'purchasing.po',
            'scope' => ExportPreset::SCOPE_TEAM,
            'name' => 'Internal Secret Preset',
            'columns' => ['po_number'],
            'format' => 'xlsx',
            'csv_delimiter' => ',',
            'filters' => [],
            'is_default' => false,
        ]);

        $this->actingAs($this->supplier)
            ->postJson(route('export-presets.duplicate', $internalTeam))
            ->assertForbidden();
    }

    public function test_non_manager_internal_role_can_view_and_duplicate_but_not_create_team_presets(): void
    {
        // Finance creates a team preset for accounting.local-invoices
        $this->actingAs($this->finance)
            ->postJson(route('export-presets.store'), [
                'export_key' => 'accounting.local-invoices',
                'scope' => 'team',
                'name' => 'Standard Register',
                'columns' => ['submission', 'invoice'],
                'format' => 'xlsx',
                'filters' => ['report' => 'register'],
            ])
            ->assertCreated();

        $teamPreset = ExportPreset::where('name', 'Standard Register')->firstOrFail();

        // Accounting role cannot create a team preset
        $this->actingAs($this->accounting)
            ->postJson(route('export-presets.store'), [
                'export_key' => 'accounting.local-invoices',
                'scope' => 'team',
                'name' => 'Accounting Attempt',
                'columns' => ['submission'],
                'format' => 'xlsx',
                'filters' => ['report' => 'register'],
            ])
            ->assertForbidden();

        // Accounting CAN list and duplicate to personal
        $this->actingAs($this->accounting)
            ->getJson(route('export-presets.index', ['export_key' => 'accounting.local-invoices']))
            ->assertOk()
            ->assertJsonCount(1, 'team')
            ->assertJsonPath('can_manage_team', false);

        $this->actingAs($this->accounting)
            ->postJson(route('export-presets.duplicate', $teamPreset))
            ->assertCreated()
            ->assertJsonPath('scope', 'personal');
    }

    public function test_legacy_preset_without_scope_or_delimiter_is_presented_safely(): void
    {
        $legacy = ExportPreset::create([
            'user_id' => $this->purchasingLead->id,
            'export_key' => 'purchasing.po',
            'name' => 'Legacy Preset',
            'columns' => ['po_number', 'status'],
            'format' => 'csv',
            'filters' => [],
            'is_default' => false,
        ]);

        $this->actingAs($this->purchasingLead)
            ->getJson(route('export-presets.index', ['export_key' => 'purchasing.po']))
            ->assertOk()
            ->assertJsonPath('personal.0.id', $legacy->getRouteKey())
            ->assertJsonPath('personal.0.scope', 'personal')
            ->assertJsonPath('personal.0.csv_delimiter', ',');
    }
}

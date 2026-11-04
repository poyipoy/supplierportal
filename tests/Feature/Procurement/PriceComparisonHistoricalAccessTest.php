<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Support\HashidCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 1 AC10: Historical (legacy and PR mode, HTML, JSON and drawer) is Purchasing-only,
 * admin included in the deny list (RoleMiddleware has no superuser), and every identifier
 * is hash-only: raw, invalid, foreign or soft-deleted references return 404.
 */
class PriceComparisonHistoricalAccessTest extends TestCase
{
    use QuotationHistoryFixtures;
    use RefreshDatabase;

    private $pr;

    private User $supplierUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationHistory();
        $this->supplierUser = $this->supplierNamed('S-A');
        $this->pr = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($this->pr, $this->supplierUser, '2026-10-15', 'USD', 'USD', [3.80]);
    }

    public function test_non_purchasing_roles_are_forbidden_including_admin(): void
    {
        foreach (['supplier', 'finance', 'qc', 'ga', 'accounting', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            foreach ($this->urls() as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_purchasing_can_open_every_pr_mode_surface(): void
    {
        foreach ($this->urls() as $url) {
            $this->actingAs($this->purchasingUser)->get($url)->assertOk();
        }
    }

    public function test_raw_invalid_foreign_and_deleted_references_are_not_found(): void
    {
        $other = $this->prAt('2026-10-02', [self::skd()]);
        $deleted = $this->prAt('2026-10-03', [self::skd()]);
        $deletedHash = $deleted->getRouteKey();
        $deleted->delete();
        $base = ['pr_id' => $this->pr->getRouteKey()];

        foreach ([
            ['pr_id' => (string) $this->pr->id],
            ['pr_id' => 'not-a-hash'],
            ['pr_id' => $deletedHash],
            $base + ['pr_item' => (string) $this->pr->items[0]->id],
            $base + ['pr_item' => HashidCodec::encode($other->items[0]->id)],
            $base + ['supplier_id' => (string) $this->supplierUser->id],
            $base + ['view' => 'json', 'history' => 1, 'pr_item' => HashidCodec::encode($this->pr->items[0]->id), 'supplier_id' => $this->supplierNamed('Outsider')->getRouteKey()],
        ] as $query) {
            $this->actingAs($this->purchasingUser)
                ->get(route('purchasing.comparison.historical', $query))
                ->assertNotFound();
        }
    }

    private function urls(): array
    {
        $item = HashidCodec::encode($this->pr->items[0]->id);

        return [
            route('purchasing.comparison.historical'),
            route('purchasing.comparison.historical', ['pr_id' => $this->pr->getRouteKey()]),
            route('purchasing.comparison.historical', ['pr_id' => $this->pr->getRouteKey(), 'view' => 'json']),
            route('purchasing.comparison.historical', ['pr_id' => $this->pr->getRouteKey(), 'view' => 'json', 'history' => 1, 'pr_item' => $item, 'supplier_id' => $this->supplierUser->getRouteKey()]),
        ];
    }
}

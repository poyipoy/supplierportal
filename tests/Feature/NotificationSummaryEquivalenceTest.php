<?php

namespace Tests\Feature;

use App\Models\SupplierScope;
use App\Models\User;
use App\Services\NotificationSummaryService;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The summary classifies each notification once instead of once per category.
 * Counts, category totals and groups must equal the per-category reference for
 * every role, including legacy rows without an explicit category, silent rows
 * and rows from another domain.
 */
class NotificationSummaryEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_matches_the_per_category_reference_for_each_role(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'import']);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'local']);
        $users = [
            User::factory()->create(['role' => 'purchasing', 'is_active' => true]),
            User::factory()->create(['role' => 'finance', 'is_active' => true]),
            User::factory()->create(['role' => 'admin', 'is_active' => true]),
            User::findOrFail($supplier->id),
        ];
        foreach ($users as $user) {
            $this->seedNotifications($user);
        }

        $service = app(NotificationSummaryService::class);
        foreach ($users as $user) {
            $user = User::findOrFail($user->id);
            $expected = $this->reference($user);
            $summary = $service->forUser($user);

            $this->assertSame($expected['count'], $summary['count'], $user->role);
            $this->assertSame($expected['category_counts'], $summary['category_counts'], $user->role);
            $this->assertSame($expected['groups'], $summary['groups']->map(fn ($group) => $group->pluck('id')->all())->all(), $user->role);
            $this->assertSame(
                ['count' => $expected['count'], 'category_counts' => $expected['category_counts']],
                $service->countsForUser($user),
                $user->role,
            );
        }
    }

    /** Per-category filtering exactly as the service did before the change. */
    private function reference(User $user): array
    {
        $categories = NotificationCategory::optionsForUser($user);
        $domains = NotificationDomain::allowedDomainsForUser($user);
        $inDomain = fn ($n) => in_array(NotificationDomain::forNotification($n), $domains, true);
        $loaded = $user->notifications()->latest()->take(30)->get(['id', 'data', 'read_at', 'created_at'])->filter($inDomain)->values();
        $unread = $user->unreadNotifications()->get(['id', 'data', 'read_at', 'created_at'])->filter($inDomain)->values()
            ->reject(fn ($n) => ($n->data['silent'] ?? false) === true)->values();
        $byCategory = fn ($collection, string $key) => $key === NotificationCategory::ALL
            ? $collection
            : $collection->filter(fn ($n) => NotificationCategory::key($n) === $key);

        $counts = [];
        $groups = [];
        foreach (array_keys($categories) as $key) {
            $unreadCount = $byCategory($unread, $key)->count();
            $counts[$key] = ['total' => max($unreadCount, $byCategory($loaded, $key)->count()), 'unread' => $unreadCount];
            $groups[$key] = $byCategory($loaded, $key)->values()->pluck('id')->all();
        }

        return ['count' => $unread->count(), 'category_counts' => $counts, 'groups' => $groups];
    }

    private function seedNotifications(User $user): void
    {
        $variants = [
            ['event' => 'quotation.submitted', 'category' => 'quotation', 'title' => 'New quotation'],
            ['event' => 'chat.message', 'category' => 'chat', 'title' => 'New message'],
            ['event' => 'local_invoice.submitted', 'title' => 'Invoice submitted'],
            ['local_invoice_id' => 9, 'title' => 'Legacy invoice'],
            ['title' => 'Quotation revision requested', 'message' => 'Please revise the quotation'],
            ['title' => 'Document uploaded', 'message' => 'Shipment document available'],
            ['category' => 'not-a-category', 'title' => 'Unknown'],
            ['event' => 'quotation.submitted', 'category' => 'quotation', 'silent' => true, 'title' => 'Silent'],
            ['event' => 'local_invoice.paid', 'category' => 'invoice', 'domain' => 'local', 'title' => 'Paid'],
            ['event' => 'export.completed', 'category' => 'other', 'domain' => 'global', 'title' => 'Export ready'],
            ['event' => 'po.created', 'category' => 'document', 'domain' => 'import', 'title' => 'PO created'],
        ];
        $rows = [];
        for ($i = 0; $i < 66; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\SystemNotification',
                'notifiable_type' => User::class, 'notifiable_id' => $user->id,
                'data' => json_encode($variants[$i % count($variants)]),
                'read_at' => $i % 4 === 0 ? now() : null,
                'created_at' => now()->subMinutes($i), 'updated_at' => now()];
        }
        DB::table('notifications')->insert($rows);
    }
}

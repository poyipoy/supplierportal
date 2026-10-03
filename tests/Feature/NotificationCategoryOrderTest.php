<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registry_category_appears_in_category_order_source(): void
    {
        $registryCategories = array_values(array_unique(array_column(config('notification_preferences'), 'category')));
        $orderCategories = config('notification_categories.order', []);

        $this->assertNotEmpty($orderCategories, 'Category order must not be empty.');

        foreach ($registryCategories as $category) {
            $this->assertContains(
                $category,
                $orderCategories,
                "Registry category '{$category}' must appear in config/notification_categories.php"
            );
        }
    }

    public function test_category_order_source_has_no_categories_lacking_in_registry(): void
    {
        $registryCategories = array_values(array_unique(array_column(config('notification_preferences'), 'category')));
        $orderCategories = config('notification_categories.order', []);

        foreach ($orderCategories as $category) {
            $this->assertContains(
                $category,
                $registryCategories,
                "Order category '{$category}' does not exist in any registry entry."
            );
        }

        $this->assertCount(count($registryCategories), $orderCategories);
    }

    public function test_page_events_view_data_order_equals_the_expected_literal_list(): void
    {
        $expectedOrder = [
            'Purchase requisitions',
            'Quotations',
            'Conversations',
            'Purchase orders',
            'Documents',
            'Shipments and QC',
            'Material claims',
            'Local invoices',
            'Supplier registration',
            'Exports',
            'Security',
        ];

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get(route('profile.notifications'));
        $response->assertOk();

        $response->assertViewHas('events', function (array $events) use ($expectedOrder): bool {
            $actualCategories = array_values(array_unique(array_column($events, 'category')));

            return $actualCategories === array_values(array_intersect($expectedOrder, $actualCategories));
        });

        $this->assertSame($expectedOrder, config('notification_categories.order'));
    }

    public function test_notification_preferences_registry_has_exactly_forty_entries_and_no_new_keys(): void
    {
        $registry = config('notification_preferences');

        $this->assertCount(40, $registry, 'Registry must contain exactly 40 top-level entries.');

        $allowedKeys = [
            'class',
            'source_event',
            'label',
            'description',
            'category',
            'roles',
            'supplier_scopes',
            'default',
            'eligibility',
            'priority',
            'priority_roles',
        ];

        foreach ($registry as $eventKey => $config) {
            $this->assertIsArray($config, "Registry entry '{$eventKey}' must be an array.");
            $extraKeys = array_diff(array_keys($config), $allowedKeys);
            $this->assertEmpty($extraKeys, "Registry entry '{$eventKey}' has unexpected keys: ".implode(', ', $extraKeys));
        }
    }
}

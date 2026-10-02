<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationPreferencesUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_component_markup_and_accessibility_attributes(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());

        $checkboxes = $xpath->query('//input[@type="checkbox" and starts-with(@name, "notification_preferences[")]');
        $this->assertGreaterThan(0, $checkboxes->length);

        /** @var \DOMElement $checkbox */
        foreach ($checkboxes as $checkbox) {
            $name = $checkbox->getAttribute('name');
            $id = $checkbox->getAttribute('id');

            $this->assertSame('switch', $checkbox->getAttribute('role'));
            $this->assertSame('1', $checkbox->getAttribute('value'));
            $this->assertNotEmpty($checkbox->getAttribute('aria-describedby'));

            // Exactly one label for the checkbox
            $labels = $xpath->query('//label[@for="'.$id.'"]');
            $this->assertCount(1, $labels);

            $label = $labels->item(0);
            $this->assertNotNull($label);
            $this->assertCount(1, $xpath->query('.//span[contains(@class, "tw-sr-only")]', $label));
            $this->assertCount(1, $xpath->query('.//span[@aria-hidden="true"]', $label));

            // Exactly one hidden input with value 0
            $hiddenInputs = $xpath->query('//input[@type="hidden" and @name="'.$name.'" and @value="0"]');
            $this->assertCount(1, $hiddenInputs);
        }
    }

    public function test_action_required_badge_and_note_rendered_for_matching_roles(): void
    {
        // Finance user: local_invoice_submitted has priority_roles ['finance', 'accounting']
        $finance = User::factory()->create(['role' => 'finance']);
        $financeResponse = $this->actingAs($finance)->get(route('profile.notifications'))->assertOk();
        $financeXpath = $this->xpathFromHtml($financeResponse->getContent());

        // Assert fieldset has data-priority="action_required"
        $fieldset = $financeXpath->query('//fieldset[@data-event-key="local_invoice_submitted"]')->item(0);
        $this->assertNotNull($fieldset);
        $this->assertSame('action_required', $fieldset->getAttribute('data-priority'));

        // Assert Action needed status chip exists for finance
        $this->assertGreaterThan(0, $financeXpath->query('.//*[contains(normalize-space(.), "Action needed")]', $fieldset)->length);

        // Assert pinned XPath for legend text is strictly unmodified
        $this->assertCount(1, $financeXpath->query('//fieldset/legend[normalize-space(.)="Invoice diajukan"]'));

        // Assert inline note exists
        $note = $financeXpath->query('.//p[contains(@class, "action-required-note")]', $fieldset)->item(0);
        $this->assertNotNull($note);
        $this->assertStringContainsString('This notification requires your action.', $note->textContent);

        // Supplier user: local_invoice_submitted priority_roles does not include supplier
        $supplier = $this->localSupplier();
        $supplierResponse = $this->actingAs($supplier)->get(route('profile.notifications'))->assertOk();
        $supplierXpath = $this->xpathFromHtml($supplierResponse->getContent());

        $supplierFieldset = $supplierXpath->query('//fieldset[@data-event-key="local_invoice_submitted"]')->item(0);
        $this->assertNotNull($supplierFieldset);
        $this->assertSame('info', $supplierFieldset->getAttribute('data-priority'));
        $this->assertCount(0, $supplierXpath->query('.//*[contains(normalize-space(.), "Action needed")]', $supplierFieldset));
    }

    public function test_presets_and_bulk_controls_and_toolbar_rendered_for_high_event_counts(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        // Presets
        $response->assertSeeText('Presets:');
        $response->assertSeeText('Everything');
        $response->assertSeeText('Action needed only');

        // Toolbar search and filters
        $response->assertSee('placeholder="Search notifications..."', false);
        $response->assertSeeText('All');
        $response->assertSeeText('Enabled');
        $response->assertSeeText('Muted');

        // Summary chip
        $response->assertSeeText('enabled');

        // Bulk controls
        $response->assertSeeText('Turn all on');
        $response->assertSeeText('Turn all off');
    }

    public function test_toolbar_and_summary_and_bulk_buttons_omitted_for_low_event_counts(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $response = $this->actingAs($ga)->get(route('profile.notifications'))->assertOk();

        $response->assertDontSeeText('Presets:');
        $response->assertDontSee('placeholder="Search notifications..."', false);
        $response->assertDontSeeText('Turn all on');
        $response->assertDontSeeText('Turn all off');
        $response->assertDontSeeText('Expand all');

        // The single switch is still rendered
        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertCount(1, $xpath->query('//input[@type="checkbox" and @role="switch"]'));
    }

    public function test_scope_tabs_rendered_for_dual_scope_supplier_and_omitted_for_single_scope(): void
    {
        // Dual-scope supplier
        $dualSupplier = $this->localSupplier();
        $dualSupplier->supplierScopes()->firstOrCreate(['scope' => 'import']);
        $response = $this->actingAs($dualSupplier)->get(route('profile.notifications'))->assertOk();

        $response->assertSeeText('All Portals');
        $response->assertSeeText('Import');
        $response->assertSeeText('Local');
        $response->assertSeeText('General');
        $response->assertSeeText('Notification preferences are account-wide and apply to both Import and Local portals.');

        // Single-scope supplier
        $singleSupplier = $this->localSupplier();
        $singleResponse = $this->actingAs($singleSupplier)->get(route('profile.notifications'))->assertOk();
        $singleResponse->assertDontSeeText('All Portals');

        // Non-supplier (purchasing)
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $purchasingResponse = $this->actingAs($purchasing)->get(route('profile.notifications'))->assertOk();
        $purchasingResponse->assertDontSeeText('All Portals');
    }

    public function test_sticky_action_bar_and_discard_button_present(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertSeeText('Save Changes');
        $response->assertSeeText('Discard');
        $response->assertSee('x-bind:disabled="dirtyCount === 0"', false);
    }

    public function test_reset_to_defaults_confirmation_dialog_and_delete_form(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertSeeText('Reset to defaults');
        $response->assertSee('open-ui-dialog', false);
        $response->assertSee('reset-notification-preferences', false);

        $xpath = $this->xpathFromHtml($response->getContent());
        $resetForm = $xpath->query('//form[@id="reset-preferences-form" and @action="'.route('profile.notifications.reset').'"]');
        $this->assertCount(1, $resetForm);
        $this->assertCount(1, $xpath->query('.//input[@name="_method" and @value="DELETE"]', $resetForm->item(0)));
    }

    public function test_get_notifications_page_executes_at_most_one_user_preferences_query(): void
    {
        $user = $this->localSupplier();
        $user->preference()->create([
            ...config('user_preferences.defaults'),
            'notification_preferences' => ['local_invoice_submitted' => false],
        ]);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount): void {
            if (str_contains(strtolower($query->sql), 'user_preferences') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queryCount++;
            }
        });

        $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $this->assertLessThanOrEqual(1, $queryCount);
    }

    private function localSupplier(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'supplier', ...$attributes]);
        $user->supplierScopes()->delete();
        $user->supplierScopes()->firstOrCreate(['scope' => 'local']);

        return $user;
    }

    private function xpathFromHtml(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}

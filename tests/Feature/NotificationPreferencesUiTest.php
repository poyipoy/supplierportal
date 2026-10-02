<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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

        // Assert inline note exists with accessibility attributes and is hidden by default when enabled
        $note = $financeXpath->query('.//p[contains(@class, "action-required-note")]', $fieldset)->item(0);
        $this->assertNotNull($note);
        $this->assertStringContainsString('This notification requires your action.', $note->textContent);
        $this->assertSame('notification-local_invoice_submitted-warning', $note->getAttribute('id'));
        $this->assertSame('alert', $note->getAttribute('role'));
        $this->assertTrue($note->hasAttribute('style'));
        $this->assertStringContainsString('display: none', $note->getAttribute('style'));

        // Switch has warning ID in aria-describedby
        $switch = $financeXpath->query('.//input[@id="notification-local_invoice_submitted"]', $fieldset)->item(0);
        $this->assertNotNull($switch);
        $this->assertStringContainsString('notification-local_invoice_submitted-warning', $switch->getAttribute('aria-describedby'));

        // When action-required event is muted, note is visible without display: none
        $financeMuted = User::factory()->create(['role' => 'finance']);
        $pref = $financeMuted->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => ['local_invoice_submitted' => false]])->save();
        $mutedResponse = $this->actingAs($financeMuted)->get(route('profile.notifications'))->assertOk();
        $mutedXpath = $this->xpathFromHtml($mutedResponse->getContent());
        $mutedFieldset = $mutedXpath->query('//fieldset[@data-event-key="local_invoice_submitted"]')->item(0);
        $mutedNote = $mutedXpath->query('.//p[contains(@class, "action-required-note")]', $mutedFieldset)->item(0);
        $this->assertNotNull($mutedNote);
        $this->assertFalse($mutedNote->hasAttribute('style'));

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

        $xpath = $this->xpathFromHtml($response->getContent());
        $filterGroup = $xpath->query('//div[@role="group" and @aria-label="Filter notifications"]')->item(0);
        $this->assertNotNull($filterGroup);
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="All" and @*[name()=":aria-pressed"]="activeFilter === \'all\' ? \'true\' : \'false\'"]', $filterGroup));
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Enabled" and @*[name()=":aria-pressed"]="activeFilter === \'enabled\' ? \'true\' : \'false\'"]', $filterGroup));
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Muted" and @*[name()=":aria-pressed"]="activeFilter === \'muted\' ? \'true\' : \'false\'"]', $filterGroup));

        // Summary chip with specific format "X of Y enabled"
        $chips = $xpath->query('//span[contains(text(), " of ") and contains(text(), "enabled")]');
        $this->assertGreaterThan(0, $chips->length);

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
        $hintText = 'Notification preferences are account-wide and apply to both Import and Local portals.';

        // Dual-scope supplier
        $dualSupplier = $this->localSupplier();
        $dualSupplier->supplierScopes()->firstOrCreate(['scope' => 'import']);
        $response = $this->actingAs($dualSupplier)->get(route('profile.notifications'))->assertOk();

        $response->assertDontSeeText('All Portals');
        $xpath = $this->xpathFromHtml($response->getContent());
        $tabNav = $xpath->query('//nav[@aria-label="Supplier portal scope filter"]')->item(0);
        $this->assertNotNull($tabNav);
        $this->assertCount(1, $xpath->query('.//button[@role="tab" and normalize-space(.)="Import" and @aria-controls="notification-preferences-panel"]', $tabNav));
        $this->assertCount(1, $xpath->query('.//button[@role="tab" and normalize-space(.)="Local" and @aria-controls="notification-preferences-panel"]', $tabNav));
        $this->assertCount(1, $xpath->query('.//button[@role="tab" and normalize-space(.)="General" and @aria-controls="notification-preferences-panel"]', $tabNav));
        $this->assertCount(1, $xpath->query('//div[@id="notification-preferences-panel" and @role="tabpanel"]'));
        $response->assertSeeText($hintText);

        // Single-scope supplier (import only or local only)
        $singleSupplier = $this->localSupplier();
        $singleResponse = $this->actingAs($singleSupplier)->get(route('profile.notifications'))->assertOk();
        $singleResponse->assertDontSeeText('All Portals');
        $singleResponse->assertDontSeeText($hintText);

        // Non-supplier (purchasing)
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $purchasingResponse = $this->actingAs($purchasing)->get(route('profile.notifications'))->assertOk();
        $purchasingResponse->assertDontSeeText('All Portals');
        $purchasingResponse->assertDontSeeText($hintText);
    }

    public function test_sticky_action_bar_and_discard_button_present(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertSeeText('Save Changes');
        $response->assertSeeText('Discard');
        $response->assertSee('x-bind:disabled="dirtyCount === 0"', false);

        $xpath = $this->xpathFromHtml($response->getContent());
        $saveBtn = $xpath->query('//button[normalize-space(.)="Save Changes"]')->item(0);
        $this->assertNotNull($saveBtn);
        $this->assertFalse($saveBtn->hasAttribute('disabled'));

        // All inputs in form remain enabled (no disabled attribute) regardless of category details open state
        $inputs = $xpath->query('//form[@action="'.route('profile.notifications.update').'"]//input');
        $this->assertGreaterThan(0, $inputs->length);
        foreach ($inputs as $input) {
            $this->assertFalse($input->hasAttribute('disabled'));
        }
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

    public function test_local_supplier_has_thirteen_checkboxes_and_no_required_wording(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertDontSeeText('Required notifications');

        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertCount(13, $xpath->query('//input[@type="checkbox"]'));

        // Confirm bulk controls are button elements, never inputs
        $bulkOn = $xpath->query('//button[normalize-space(.)="Turn all on"]');
        $this->assertGreaterThan(0, $bulkOn->length);
        foreach ($bulkOn as $btn) {
            $this->assertSame('button', $btn->getAttribute('type'));
        }

        $bulkOff = $xpath->query('//button[normalize-space(.)="Turn all off"]');
        $this->assertGreaterThan(0, $bulkOff->length);
        foreach ($bulkOff as $btn) {
            $this->assertSame('button', $btn->getAttribute('type'));
        }
    }

    public function test_data_scope_attribute_values_on_event_rows(): void
    {
        $dualSupplier = $this->localSupplier();
        $dualSupplier->supplierScopes()->firstOrCreate(['scope' => 'import']);
        $response = $this->actingAs($dualSupplier)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertGreaterThan(0, $xpath->query('//fieldset[@data-scope="import"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//fieldset[@data-scope="local"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//fieldset[@data-scope="general"]')->length);
    }

    public function test_category_collapse_and_open_initial_state_rules(): void
    {
        // 1. User with <= 12 events (ga has 1 event): category is open
        $ga = User::factory()->create(['role' => 'ga']);
        $gaResponse = $this->actingAs($ga)->get(route('profile.notifications'))->assertOk();
        $gaXpath = $this->xpathFromHtml($gaResponse->getContent());
        $gaDetails = $gaXpath->query('//details[@data-category]');
        $this->assertCount(1, $gaDetails);
        $this->assertTrue($gaDetails->item(0)->hasAttribute('open'));

        // 2. User with > 12 events and > 2 categories (purchasing has >20 events):
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $purResponse = $this->actingAs($purchasing)->get(route('profile.notifications'))->assertOk();
        $purXpath = $this->xpathFromHtml($purResponse->getContent());
        $details = $purXpath->query('//details[@data-category]');
        $this->assertGreaterThan(2, $details->length);

        // First category is open by default
        $this->assertTrue($details->item(0)->hasAttribute('open'));
        // Second category is closed by default
        $this->assertFalse($details->item(1)->hasAttribute('open'));

        // 3. User with a muted event in a later category: that category opens
        $secondCatName = $details->item(1)->getAttribute('data-category');
        $firstEventInSecondCat = $purXpath->query('.//fieldset[@data-event-key]', $details->item(1))->item(0);
        $eventKey = $firstEventInSecondCat->getAttribute('data-event-key');

        $mutedUser = User::factory()->create(['role' => 'purchasing']);
        $pref = $mutedUser->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => [$eventKey => false]])->save();

        $mutedResponse = $this->actingAs($mutedUser)->get(route('profile.notifications'))->assertOk();
        $mutedXpath = $this->xpathFromHtml($mutedResponse->getContent());
        $mutedDetail = $mutedXpath->query('//details[@data-category="'.$secondCatName.'"]')->item(0);
        $this->assertNotNull($mutedDetail);
        $this->assertTrue($mutedDetail->hasAttribute('open'));

        // 4. Validation error in a category forces that category open
        $errorUser = User::factory()->create(['role' => 'purchasing']);
        $errorBag = new MessageBag(['notification_preferences.'.$eventKey => 'Invalid value']);
        $viewErrors = (new ViewErrorBag)->put('default', $errorBag);

        $errorResponse = $this->actingAs($errorUser)
            ->withSession(['errors' => $viewErrors])
            ->get(route('profile.notifications'))
            ->assertOk();
        $errorXpath = $this->xpathFromHtml($errorResponse->getContent());
        $errorDetail = $errorXpath->query('//details[@data-category="'.$secondCatName.'"]')->item(0);
        $this->assertNotNull($errorDetail);
        $this->assertTrue($errorDetail->hasAttribute('open'));
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

    public function test_silent_delivery_select_rendered_with_correct_semantics_and_no_label(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());

        // Select exists for each event
        $selects = $xpath->query('//select[starts-with(@name, "notification_delivery[")]');
        $this->assertCount(13, $selects);

        /** @var \DOMElement $select */
        foreach ($selects as $select) {
            $id = $select->getAttribute('id');

            // Outside legend, inside fieldset
            $fieldset = $xpath->query('ancestor::fieldset', $select)->item(0);
            $this->assertNotNull($fieldset);
            $legend = $xpath->query('legend', $fieldset)->item(0);
            $this->assertNotNull($legend);
            $this->assertCount(0, $xpath->query('.//select', $legend), 'Select must sit outside legend');

            // No label tag for the select
            if ($id) {
                $this->assertCount(0, $xpath->query('//label[@for="'.$id.'"]'), 'Select must have no label tag');
            }
            $this->assertNotEmpty($select->getAttribute('aria-label'), 'Select must have aria-label');
            $this->assertStringContainsString('delivery mode', $select->getAttribute('aria-label'));

            // Options: normal and silent
            $options = $xpath->query('.//option', $select);
            $this->assertCount(2, $options);
            $this->assertSame('normal', $options->item(0)->getAttribute('value'));
            $this->assertSame('silent', $options->item(1)->getAttribute('value'));
        }

        // Local supplier still has exactly 13 checkboxes
        $this->assertCount(13, $xpath->query('//input[@type="checkbox"]'));
    }

    public function test_silent_filter_button_and_quiet_mode_preset_rendered(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());

        // Silent filter button in toolbar
        $filterGroup = $xpath->query('//div[@role="group" and @aria-label="Filter notifications"]')->item(0);
        $this->assertNotNull($filterGroup);
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Silent" and @*[name()=":aria-pressed"]="activeFilter === \'silent\' ? \'true\' : \'false\'"]', $filterGroup));

        // Quiet mode preset button
        $response->assertSeeText('Quiet mode');
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Quiet mode"]'));

        // Summary chip contains enabled, silent, and off counts
        $response->assertSeeText('enabled · 0 silent · 0 off');
    }

    public function test_silent_delivery_reflects_stored_preference_in_selected_option(): void
    {
        $user = User::factory()->create(['role' => 'finance']);
        $pref = $user->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => ['local_invoice_resubmitted' => 'silent']])->save();

        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();
        $xpath = $this->xpathFromHtml($response->getContent());

        $resubmittedSelect = $xpath->query('//select[@name="notification_delivery[local_invoice_resubmitted]"]')->item(0);
        $this->assertNotNull($resubmittedSelect);
        $silentOption = $xpath->query('.//option[@value="silent"]', $resubmittedSelect)->item(0);
        $this->assertNotNull($silentOption);
        $this->assertTrue($silentOption->hasAttribute('selected'));

        $submittedSelect = $xpath->query('//select[@name="notification_delivery[local_invoice_submitted]"]')->item(0);
        $this->assertNotNull($submittedSelect);
        $normalOption = $xpath->query('.//option[@value="normal"]', $submittedSelect)->item(0);
        $this->assertNotNull($normalOption);
        $this->assertTrue($normalOption->hasAttribute('selected'));
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

<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class NotificationPreferencesUiTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = '//input[@type="checkbox" and starts-with(@name, "notification_preferences[")]';

    private const POPUP = '//input[@type="checkbox" and starts-with(@name, "notification_delivery[")]';

    public function test_inbox_and_popup_checkbox_markup_and_accessibility_attributes(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());

        $inboxBoxes = $xpath->query(self::INBOX);
        $popupBoxes = $xpath->query(self::POPUP);
        $this->assertCount(19, $inboxBoxes);
        $this->assertCount(19, $popupBoxes);

        /** @var DOMElement $checkbox */
        foreach ($inboxBoxes as $checkbox) {
            $name = $checkbox->getAttribute('name');
            $id = $checkbox->getAttribute('id');

            // Plain checkboxes, not switches
            $this->assertFalse($checkbox->hasAttribute('role'), $name.' must not be a role=switch control');
            $this->assertSame('1', $checkbox->getAttribute('value'));
            $this->assertNotEmpty($checkbox->getAttribute('aria-describedby'));

            // Exactly one label for the checkbox, wrapping it, with a mobile-visible channel name
            $labels = $xpath->query('//label[@for="'.$id.'"]');
            $this->assertCount(1, $labels);
            $label = $labels->item(0);
            $this->assertNotNull($label);
            $this->assertCount(1, $xpath->query('ancestor::label', $checkbox));
            $channel = $xpath->query('.//span[contains(@class, "tw:md:sr-only")]', $label);
            $this->assertCount(1, $channel);
            $this->assertSame('Inbox', trim($channel->item(0)->textContent));

            // Exactly one hidden fallback input with value 0
            $this->assertCount(1, $xpath->query('//input[@type="hidden" and @name="'.$name.'" and @value="0"]'));
        }

        /** @var DOMElement $checkbox */
        foreach ($popupBoxes as $checkbox) {
            $name = $checkbox->getAttribute('name');
            $id = $checkbox->getAttribute('id');

            $this->assertFalse($checkbox->hasAttribute('role'), $name.' must not be a role=switch control');
            $this->assertSame('normal', $checkbox->getAttribute('value'));
            $this->assertStringEndsWith('-popup', $id);
            $this->assertNotEmpty($checkbox->getAttribute('aria-describedby'));

            $labels = $xpath->query('//label[@for="'.$id.'"]');
            $this->assertCount(1, $labels);
            $label = $labels->item(0);
            $this->assertNotNull($label);
            $this->assertCount(1, $xpath->query('ancestor::label', $checkbox));
            $channel = $xpath->query('.//span[contains(@class, "tw:md:sr-only")]', $label);
            $this->assertCount(1, $channel);
            $this->assertSame('Popup', trim($channel->item(0)->textContent));

            $this->assertCount(1, $xpath->query('//input[@type="hidden" and @name="'.$name.'" and @value="silent"]'));
        }

        // Every event row is a labelled group whose accessible name is the event label
        $rows = $xpath->query('//div[@role="group" and @data-event-key]');
        $this->assertCount(19, $rows);
        /** @var DOMElement $row */
        foreach ($rows as $row) {
            $labelId = $row->getAttribute('aria-labelledby');
            $this->assertSame('notification-'.$row->getAttribute('data-event-key').'-label', $labelId);
            $this->assertCount(1, $xpath->query('//span[@id="'.$labelId.'"]'));
        }
    }

    public function test_action_required_marker_and_note_rendered_for_matching_roles(): void
    {
        // Finance user: local_invoice_submitted has priority_roles ['finance', 'accounting']
        $finance = User::factory()->create(['role' => 'finance']);
        $financeResponse = $this->actingAs($finance)->get(route('profile.notifications'))->assertOk();
        $financeXpath = $this->xpathFromHtml($financeResponse->getContent());

        $row = $financeXpath->query('//div[@data-event-key="local_invoice_submitted"]')->item(0);
        $this->assertNotNull($row);
        $this->assertSame('action_required', $row->getAttribute('data-priority'));

        // "Action needed" marker is plain text with an icon, not a chip
        $this->assertGreaterThan(0, $financeXpath->query('.//*[contains(normalize-space(.), "Action needed")]', $row)->length);

        // The event label renders in the default English locale
        $this->assertCount(1, $financeXpath->query('//span[@id="notification-local_invoice_submitted-label" and normalize-space(.)="Invoice submitted"]'));

        // Inline note exists with accessibility attributes and is hidden by default when the inbox is on
        $note = $financeXpath->query('.//p[contains(@class, "action-required-note")]', $row)->item(0);
        $this->assertNotNull($note);
        $this->assertStringContainsString('This notification requires your action.', $note->textContent);
        $this->assertSame('notification-local_invoice_submitted-warning', $note->getAttribute('id'));
        $this->assertSame('alert', $note->getAttribute('role'));
        $this->assertTrue($note->hasAttribute('style'));
        $this->assertStringContainsString('display: none', $note->getAttribute('style'));

        // The Inbox checkbox points at the warning through aria-describedby
        $inbox = $financeXpath->query('.//input[@id="notification-local_invoice_submitted"]', $row)->item(0);
        $this->assertNotNull($inbox);
        $this->assertStringContainsString('notification-local_invoice_submitted-warning', $inbox->getAttribute('aria-describedby'));

        // When the action-required event is muted, the note is visible without display: none
        $financeMuted = User::factory()->create(['role' => 'finance']);
        $pref = $financeMuted->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => ['local_invoice_submitted' => false]])->save();
        $mutedResponse = $this->actingAs($financeMuted)->get(route('profile.notifications'))->assertOk();
        $mutedXpath = $this->xpathFromHtml($mutedResponse->getContent());
        $mutedRow = $mutedXpath->query('//div[@data-event-key="local_invoice_submitted"]')->item(0);
        $this->assertNotNull($mutedRow);
        $mutedNote = $mutedXpath->query('.//p[contains(@class, "action-required-note")]', $mutedRow)->item(0);
        $this->assertNotNull($mutedNote);
        $this->assertFalse($mutedNote->hasAttribute('style'));
        $mutedInbox = $mutedXpath->query('.//input[@id="notification-local_invoice_submitted"]', $mutedRow)->item(0);
        $this->assertNotNull($mutedInbox);
        $this->assertStringContainsString('notification-local_invoice_submitted-warning', $mutedInbox->getAttribute('aria-describedby'));

        // Supplier user: local_invoice_submitted priority_roles does not include supplier
        $supplier = $this->localSupplier();
        $supplierResponse = $this->actingAs($supplier)->get(route('profile.notifications'))->assertOk();
        $supplierXpath = $this->xpathFromHtml($supplierResponse->getContent());

        $supplierRow = $supplierXpath->query('//div[@data-event-key="local_invoice_submitted"]')->item(0);
        $this->assertNotNull($supplierRow);
        $this->assertSame('info', $supplierRow->getAttribute('data-priority'));
        $this->assertCount(0, $supplierXpath->query('.//*[contains(normalize-space(.), "Action needed")]', $supplierRow));
        $this->assertCount(0, $supplierXpath->query('.//p[contains(@class, "action-required-note")]', $supplierRow));
    }

    public function test_toolbar_quick_setup_summary_and_bulk_controls_rendered_for_high_event_counts(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        // Toolbar search
        $response->assertSee('placeholder="Search notifications..."', false);

        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertCount(1, $xpath->query('//input[@id="notification-search" and @type="search"]'));
        $this->assertCount(1, $xpath->query('//label[@for="notification-search"]'));

        // Filter group: All / Off / Inbox only, with static and Alpine aria-pressed
        $filterGroup = $xpath->query('//div[@role="group" and @aria-label="Filter notifications"]')->item(0);
        $this->assertNotNull($filterGroup);
        $this->assertCount(3, $xpath->query('.//button', $filterGroup));
        foreach ([['All', 'all', 'true', '19'], ['Off', 'off', 'false', '0'], ['Inbox only', 'inbox', 'false', '0']] as [$label, $value, $initial, $count]) {
            $button = $xpath->query('.//button[normalize-space(.)="'.$label.' '.$count.'" and @*[name()=":aria-pressed"]="filter === \''.$value.'\' ? \'true\' : \'false\'"]', $filterGroup);
            $this->assertCount(1, $button, 'Missing filter button '.$label.' with its count '.$count);
            $this->assertSame($initial, $button->item(0)->getAttribute('aria-pressed'));

            // The selected look must come from aria-pressed only. A static "selected" utility next to the idle ones
            // used to win some properties and lose others, leaving no segment visibly selected.
            $classes = $button->item(0)->getAttribute('class');
            $this->assertDoesNotMatchRegularExpression('/(^|\s)tw:(bg-primary|text-on-primary|border-primary)(\s|$)/', $classes);
            $this->assertStringContainsString('tw:aria-pressed:bg-primary', $classes);
            $this->assertStringContainsString('tw:aria-pressed:text-on-primary', $classes);
            $this->assertStringContainsString('tw:aria-pressed:border-primary', $classes);
            $this->assertFalse($button->item(0)->hasAttribute(':class'), 'Alpine must not toggle state classes next to the static ones');
        }
        $this->assertCount(0, $xpath->query('.//button[normalize-space(.)="Enabled" or normalize-space(.)="Muted" or normalize-space(.)="Silent"]', $filterGroup));

        // Quick setup disclosure with the three presets
        $quickSetup = $xpath->query('//details[summary[contains(normalize-space(.), "Quick setup")]]')->item(0);
        $this->assertNotNull($quickSetup);
        $this->assertCount(3, $xpath->query('.//button[@type="button"]', $quickSetup));
        foreach (['Everything', 'Action needed only', 'Quiet mode'] as $preset) {
            $this->assertCount(1, $xpath->query('.//button[.//span[normalize-space(.)="'.$preset.'"]]', $quickSetup), 'Missing preset '.$preset);
        }

        // Server-rendered summary: X of Y on · N inbox only · M off
        $response->assertSeeText('19 of 19 on · 0 inbox only · 0 off');

        // Header bulk checkboxes: two per category (Inbox and Popup) with descriptive names
        $sections = $xpath->query('//section[@data-category]');
        $this->assertGreaterThan(1, $sections->length);
        $bulk = $xpath->query('//section[@data-category]//input[@type="checkbox" and @data-ignore-dirty="true"]');
        $this->assertSame($sections->length * 2, $bulk->length);
        $this->assertCount(1, $xpath->query('//input[@data-ignore-dirty="true" and @aria-label="Inbox for every Local invoices notification"]'));
        $this->assertCount(1, $xpath->query('//input[@data-ignore-dirty="true" and @aria-label="Popup for every Local invoices notification"]'));

        // Removed copy from the previous layout
        foreach (['Presets:', 'Turn all on', 'Turn all off', 'Expand all'] as $removed) {
            $this->assertCount(0, $xpath->query('//button[contains(normalize-space(.), "'.$removed.'")]'), $removed.' controls must be gone');
        }
        $response->assertDontSeeText('Presets:');
    }

    public function test_toolbar_and_bulk_controls_omitted_for_low_event_counts(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $response = $this->actingAs($ga)->get(route('profile.notifications'))->assertOk();

        $response->assertDontSee('placeholder="Search notifications..."', false);
        $response->assertDontSeeText('Quick setup');

        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertCount(0, $xpath->query('//input[@id="notification-search"]'));
        $this->assertCount(0, $xpath->query('//div[@role="group" and @aria-label="Filter notifications"]'));
        $this->assertCount(0, $xpath->query('//details[summary[contains(normalize-space(.), "Quick setup")]]'));
        $this->assertCount(0, $xpath->query('//button[normalize-space(.)="Quiet mode" or contains(normalize-space(.), "Action needed only")]'));
        $this->assertCount(0, $xpath->query('//button[contains(normalize-space(.), "Turn all") or contains(normalize-space(.), "Expand all")]'));
        $this->assertCount(0, $xpath->query('//input[@data-ignore-dirty]'));

        // The single event still renders its Inbox and Popup checkboxes
        $this->assertCount(1, $xpath->query(self::INBOX));
        $this->assertCount(1, $xpath->query(self::POPUP));

        // Column titles are plain text (not labelled bulk controls) when there is no bulk UI
        $section = $xpath->query('//section[@data-category]')->item(0);
        $this->assertNotNull($section);
        $this->assertCount(1, $xpath->query('.//span[@aria-hidden="true" and normalize-space(.)="Inbox"]', $section));
        $this->assertCount(1, $xpath->query('.//span[@aria-hidden="true" and normalize-space(.)="Popup"]', $section));
        $this->assertCount(0, $xpath->query('.//*[@aria-label and contains(@aria-label, "for every")]', $section));

        // Reset to defaults is available to every user, in the action bar
        $this->assertCount(1, $xpath->query('//button[normalize-space(.)="Reset to defaults" and @*[name()="x-on:click"]]'));
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
        $singleXpath = $this->xpathFromHtml($singleResponse->getContent());
        $this->assertCount(0, $singleXpath->query('//nav[@aria-label="Supplier portal scope filter"]'));
        $this->assertCount(1, $singleXpath->query('//div[@id="notification-preferences-panel" and not(@role)]'));

        // Non-supplier (purchasing)
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $purchasingResponse = $this->actingAs($purchasing)->get(route('profile.notifications'))->assertOk();
        $purchasingResponse->assertDontSeeText('All Portals');
        $purchasingResponse->assertDontSeeText($hintText);
    }

    public function test_sticky_action_bar_discard_and_reset_buttons_present(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertSeeText('Save Changes');
        $response->assertSeeText('Discard');
        $response->assertSeeText('No unsaved changes');
        $response->assertSee('x-bind:disabled="dirtyCount === 0"', false);

        $xpath = $this->xpathFromHtml($response->getContent());
        $saveBtn = $xpath->query('//button[normalize-space(.)="Save Changes"]')->item(0);
        $this->assertNotNull($saveBtn);
        $this->assertFalse($saveBtn->hasAttribute('disabled'));

        // Reset to defaults is a ghost button on the left side of the action bar
        $left = $xpath->query('//div[contains(@class, "ui-action-bar__left")]')->item(0);
        $this->assertNotNull($left);
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Reset to defaults"]', $left));
        $right = $xpath->query('//div[contains(@class, "ui-action-bar__right")]')->item(0);
        $this->assertNotNull($right);
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Discard"]', $right));
        $this->assertCount(1, $xpath->query('.//button[normalize-space(.)="Save Changes"]', $right));
        $this->assertCount(0, $xpath->query('.//button[normalize-space(.)="Reset to defaults"]', $right));

        // With nothing muted, no control inside the save form is statically disabled
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

        // The action-bar button opens the dialog; the dialog button submits the DELETE form
        $this->assertCount(1, $xpath->query('//button[normalize-space(.)="Reset to defaults" and contains(@*[name()="x-on:click"], "reset-notification-preferences")]'));
        $this->assertCount(1, $xpath->query('//button[normalize-space(.)="Reset to defaults" and @form="reset-preferences-form"]'));
    }

    public function test_local_supplier_includes_audit_notifications_without_required_wording(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertDontSeeText('Required notifications');

        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertCount(19, $xpath->query(self::INBOX));
        $this->assertCount(19, $xpath->query(self::POPUP));

        // Header bulk checkboxes are the only other checkboxes and carry no name
        $unnamed = $xpath->query('//input[@type="checkbox" and not(@name)]');
        $this->assertGreaterThan(0, $unnamed->length);
        $this->assertSame(
            $xpath->query('//section[@data-category]')->length * 2,
            $unnamed->length,
        );
        $this->assertCount(19 + 19 + $unnamed->length, $xpath->query('//input[@type="checkbox"]'));

        // Supplier audit events are part of the local supplier audience
        foreach (['supplier_audit_assigned', 'supplier_audit_result_published', 'supplier_audit_invoice_blocked'] as $key) {
            $this->assertCount(1, $xpath->query('//div[@data-event-key="'.$key.'"]'), $key.' must be listed');
        }
    }

    public function test_data_scope_attribute_values_on_event_rows(): void
    {
        $dualSupplier = $this->localSupplier();
        $dualSupplier->supplierScopes()->firstOrCreate(['scope' => 'import']);
        $response = $this->actingAs($dualSupplier)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());
        $this->assertGreaterThan(0, $xpath->query('//div[@data-event-key and @data-scope="import"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//div[@data-event-key and @data-scope="local"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//div[@data-event-key and @data-scope="general"]')->length);
    }

    public function test_categories_are_always_open_sections_in_registry_order(): void
    {
        // 1. A user with a single event gets a single section and no collapsible details
        $ga = User::factory()->create(['role' => 'ga']);
        $gaResponse = $this->actingAs($ga)->get(route('profile.notifications'))->assertOk();
        $gaXpath = $this->xpathFromHtml($gaResponse->getContent());
        $this->assertCount(1, $gaXpath->query('//section[@data-category]'));
        $this->assertCount(0, $gaXpath->query('//details[@data-category]'));
        $this->assertCount(1, $gaXpath->query('//section[@data-category]//div[@data-event-key]'));

        // 2. A user with many categories: every category is rendered visible, none inside <details>
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $purResponse = $this->actingAs($purchasing)->get(route('profile.notifications'))->assertOk();
        $purXpath = $this->xpathFromHtml($purResponse->getContent());
        $sections = $purXpath->query('//section[@data-category]');
        $this->assertGreaterThan(2, $sections->length);
        $this->assertCount(0, $purXpath->query('//details[@data-category]'));
        $this->assertCount(0, $purXpath->query('//section[@data-category]//details'));

        /** @var DOMElement $section */
        foreach ($sections as $section) {
            $this->assertFalse($section->hasAttribute('open'));
            $this->assertStringNotContainsString('display: none', $section->getAttribute('style'));
            $this->assertNotSame('', $section->getAttribute('aria-labelledby'));
            $this->assertCount(1, $purXpath->query('//h2[@id="'.$section->getAttribute('aria-labelledby').'"]'));
        }

        // Every event row sits inside a category section and is initially visible
        $this->assertSame(
            $purXpath->query('//div[@data-event-key]')->length,
            $purXpath->query('//section[@data-category]//div[@data-event-key]')->length,
        );
        $this->assertCount(0, $purXpath->query('//div[@data-event-key and contains(@style, "display: none")]'));

        // Sections follow the registry category order
        /** @var array<string, array<string, mixed>> $events */
        $events = $purResponse->viewData('events');
        $expectedLabels = array_values(array_unique(array_map(
            fn (array $event): string => trans($event['category'], [], 'en'),
            array_values($events),
        )));
        $actualLabels = [];
        foreach ($sections as $section) {
            $actualLabels[] = $section->getAttribute('data-category');
        }
        $this->assertSame($expectedLabels, $actualLabels);

        // 3. A muted event keeps its category rendered, with the row unchecked
        $secondCategory = $sections->item(1);
        $this->assertNotNull($secondCategory);
        $secondCatName = $secondCategory->getAttribute('data-category');
        $firstEventInSecondCat = $purXpath->query('.//div[@data-event-key]', $secondCategory)->item(0);
        $eventKey = $firstEventInSecondCat->getAttribute('data-event-key');

        $mutedUser = User::factory()->create(['role' => 'purchasing']);
        $pref = $mutedUser->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => [$eventKey => false]])->save();

        $mutedResponse = $this->actingAs($mutedUser)->get(route('profile.notifications'))->assertOk();
        $mutedXpath = $this->xpathFromHtml($mutedResponse->getContent());
        $mutedSection = $mutedXpath->query('//section[@data-category="'.$secondCatName.'"]')->item(0);
        $this->assertNotNull($mutedSection);
        $this->assertStringNotContainsString('display: none', $mutedSection->getAttribute('style'));
        $mutedInbox = $mutedXpath->query('.//input[@id="notification-'.$eventKey.'"]', $mutedSection)->item(0);
        $this->assertNotNull($mutedInbox);
        $this->assertFalse($mutedInbox->hasAttribute('checked'));

        // 4. A validation error keeps the category visible and is attached to the Inbox checkbox
        $errorUser = User::factory()->create(['role' => 'purchasing']);
        $errorBag = new MessageBag(['notification_preferences.'.$eventKey => 'Invalid value']);
        $viewErrors = (new ViewErrorBag)->put('default', $errorBag);

        $errorResponse = $this->actingAs($errorUser)
            ->withSession(['errors' => $viewErrors])
            ->get(route('profile.notifications'))
            ->assertOk();
        $errorXpath = $this->xpathFromHtml($errorResponse->getContent());
        $errorSection = $errorXpath->query('//section[@data-category="'.$secondCatName.'"]')->item(0);
        $this->assertNotNull($errorSection);
        $this->assertStringNotContainsString('display: none', $errorSection->getAttribute('style'));
        $errorMessage = $errorXpath->query('.//p[@id="notification-'.$eventKey.'-error"]', $errorSection)->item(0);
        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('Invalid value', $errorMessage->textContent);
        $errorInbox = $errorXpath->query('.//input[@id="notification-'.$eventKey.'"]', $errorSection)->item(0);
        $this->assertNotNull($errorInbox);
        $this->assertSame('true', $errorInbox->getAttribute('aria-invalid'));
        $this->assertStringContainsString('notification-'.$eventKey.'-error', $errorInbox->getAttribute('aria-describedby'));
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

    public function test_delivery_is_a_popup_checkbox_not_a_select(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $xpath = $this->xpathFromHtml($response->getContent());

        $this->assertCount(0, $xpath->query('//select[starts-with(@name, "notification_delivery[")]'));
        $this->assertCount(0, $xpath->query('//form[@action="'.route('profile.notifications.update').'"]//select'));
        $this->assertCount(0, $xpath->query('//option[@value="normal" or @value="silent"]'));
        $this->assertCount(19, $xpath->query(self::POPUP));

        // The old option wording is gone, and this page's own copy contains no em dash
        $response->assertDontSee('Normal —', false);
        $response->assertDontSee('Silent —', false);
        $response->assertDontSeeText('Normal — popup + inbox');
        $response->assertDontSeeText('Silent — inbox only');

        $form = $xpath->query('//form[@action="'.route('profile.notifications.update').'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertStringNotContainsString('—', $form->textContent);
        $reset = $xpath->query('//form[@id="reset-preferences-form"]')->item(0);
        $this->assertNotNull($reset);
        $this->assertStringNotContainsString('—', $reset->textContent);
    }

    public function test_popup_checkbox_state_reflects_muted_silent_and_default_events(): void
    {
        $user = User::factory()->create(['role' => 'finance']);
        $pref = $user->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => [
            'local_invoice_resubmitted' => 'silent',
            'supplier_registration_submitted' => false,
        ]])->save();

        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();
        $xpath = $this->xpathFromHtml($response->getContent());

        // Default event: Inbox and Popup both checked and enabled
        $defaultInbox = $xpath->query('//input[@id="notification-local_invoice_submitted"]')->item(0);
        $defaultPopup = $xpath->query('//input[@id="notification-local_invoice_submitted-popup"]')->item(0);
        $this->assertNotNull($defaultInbox);
        $this->assertNotNull($defaultPopup);
        $this->assertTrue($defaultInbox->hasAttribute('checked'));
        $this->assertTrue($defaultPopup->hasAttribute('checked'));
        $this->assertFalse($defaultPopup->hasAttribute('disabled'));

        // Stored silent: Inbox checked, Popup unchecked but still enabled
        $silentInbox = $xpath->query('//input[@id="notification-local_invoice_resubmitted"]')->item(0);
        $silentPopup = $xpath->query('//input[@id="notification-local_invoice_resubmitted-popup"]')->item(0);
        $this->assertNotNull($silentInbox);
        $this->assertNotNull($silentPopup);
        $this->assertTrue($silentInbox->hasAttribute('checked'));
        $this->assertFalse($silentPopup->hasAttribute('checked'));
        $this->assertFalse($silentPopup->hasAttribute('disabled'));

        // Muted: Inbox unchecked, Popup unchecked and disabled
        $mutedInbox = $xpath->query('//input[@id="notification-supplier_registration_submitted"]')->item(0);
        $mutedPopup = $xpath->query('//input[@id="notification-supplier_registration_submitted-popup"]')->item(0);
        $this->assertNotNull($mutedInbox);
        $this->assertNotNull($mutedPopup);
        $this->assertFalse($mutedInbox->hasAttribute('checked'));
        $this->assertFalse($mutedPopup->hasAttribute('checked'));
        $this->assertTrue($mutedPopup->hasAttribute('disabled'));

        // Summary counts the muted and the inbox-only rows
        $total = count($response->viewData('events'));
        $response->assertSeeText(($total - 1).' of '.$total.' on · 1 inbox only · 1 off');
    }

    public function test_hidden_fallback_inputs_precede_their_checkboxes_in_document_order(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();
        $html = $response->getContent();
        $xpath = $this->xpathFromHtml($html);

        // PHP keeps the last value for a repeated key, so the checkbox must come after the hidden fallback
        /** @var DOMElement $popup */
        foreach ($xpath->query(self::POPUP) as $popup) {
            $name = $popup->getAttribute('name');
            $this->assertCount(
                1,
                $xpath->query('preceding::input[@type="hidden" and @name="'.$name.'" and @value="silent"]', $popup),
                'Hidden silent fallback must precede '.$name,
            );
            $this->assertCount(0, $xpath->query('following::input[@type="hidden" and @name="'.$name.'"]', $popup));
        }
        /** @var DOMElement $inbox */
        foreach ($xpath->query(self::INBOX) as $inbox) {
            $name = $inbox->getAttribute('name');
            $this->assertCount(
                1,
                $xpath->query('preceding::input[@type="hidden" and @name="'.$name.'" and @value="0"]', $inbox),
                'Hidden 0 fallback must precede '.$name,
            );
            $this->assertCount(0, $xpath->query('following::input[@type="hidden" and @name="'.$name.'"]', $inbox));
        }

        // Same guarantee on the raw markup for one concrete event
        $hiddenAt = strpos($html, '<input type="hidden" name="notification_delivery[local_invoice_submitted]" value="silent">');
        $boxAt = strpos($html, 'id="notification-local_invoice_submitted-popup"');
        $this->assertNotFalse($hiddenAt);
        $this->assertNotFalse($boxAt);
        $this->assertLessThan($boxAt, $hiddenAt);

        // Simulate the browser submit: a checked Popup posts silent then normal, and PHP keeps normal
        parse_str('notification_delivery[local_invoice_submitted]=silent&notification_delivery[local_invoice_submitted]=normal', $checked);
        $this->assertSame('normal', $checked['notification_delivery']['local_invoice_submitted']);
        parse_str('notification_delivery[local_invoice_submitted]=silent', $unchecked);
        $this->assertSame('silent', $unchecked['notification_delivery']['local_invoice_submitted']);
    }

    public function test_category_header_count_text_is_server_rendered_per_category(): void
    {
        $user = $this->localSupplier();
        // notification_preferences is not mass assignable, so it must be forced
        $preference = $user->preference()->create(config('user_preferences.defaults'));
        $preference->forceFill(['notification_preferences' => ['local_invoice_submitted' => false]])->save();

        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();
        $xpath = $this->xpathFromHtml($response->getContent());

        /** @var array<string, array<string, mixed>> $events */
        $events = $response->viewData('events');
        $mutedCategory = $events['local_invoice_submitted']['category'];

        $totals = [];
        foreach ($events as $event) {
            $totals[$event['category']] = ($totals[$event['category']] ?? 0) + 1;
        }
        $this->assertGreaterThan(1, count($totals));
        $this->assertGreaterThan(1, $totals[$mutedCategory]);

        foreach ($totals as $category => $total) {
            $label = trans($category, [], 'en');
            $on = $category === $mutedCategory ? $total - 1 : $total;

            $counter = $xpath->query('//section[@data-category="'.$label.'"]//span[starts-with(@x-text, "categoryCountText(")]');
            $this->assertCount(1, $counter, 'Missing count text for '.$label);
            $this->assertSame($on.' of '.$total.' on', trim(preg_replace('/\s+/', ' ', $counter->item(0)->textContent)), 'Wrong count text for '.$label);
        }

        // The muted category specifically reads N-1 of N on
        $mutedLabel = trans($mutedCategory, [], 'en');
        $this->assertSame('Local invoices', $mutedLabel);
        $this->assertCount(1, $xpath->query('//section[@data-category="'.$mutedLabel.'"]//span[normalize-space(.)="'.($totals[$mutedCategory] - 1).' of '.$totals[$mutedCategory].' on"]'));
    }

    public function test_header_bulk_checkboxes_are_ignored_by_dirty_tracking_and_form_post(): void
    {
        $user = $this->localSupplier();
        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();
        $xpath = $this->xpathFromHtml($response->getContent());

        $sections = $xpath->query('//section[@data-category]');
        $this->assertGreaterThan(1, $sections->length);

        /** @var DOMElement $section */
        foreach ($sections as $section) {
            $bulk = $xpath->query('.//input[@type="checkbox" and @data-ignore-dirty="true"]', $section);
            $this->assertCount(2, $bulk, 'Each category header carries one Inbox and one Popup bulk checkbox');

            /** @var DOMElement $checkbox */
            foreach ($bulk as $checkbox) {
                $this->assertFalse($checkbox->hasAttribute('name'), 'Bulk checkbox must not be posted');
                $this->assertNotEmpty($checkbox->getAttribute('aria-label'));
                $this->assertStringContainsString($section->getAttribute('data-category'), $checkbox->getAttribute('aria-label'));
            }

            // Event checkboxes are never opted out of the dirty tracker
            $this->assertCount(0, $xpath->query('.//div[@data-event-key]//input[@data-ignore-dirty]', $section));
        }

        // Every ignored checkbox is a nameless header bulk control, and every named control is tracked
        $this->assertSame(
            $xpath->query('//input[@data-ignore-dirty="true"]')->length,
            $xpath->query('//input[@type="checkbox" and not(@name)]')->length,
        );
        $this->assertCount(0, $xpath->query('//input[@data-ignore-dirty and @name]'));
    }

    public function test_indonesian_locale_renders_channel_and_filter_copy_in_indonesian(): void
    {
        $user = $this->localSupplier();
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);

        $response = $this->actingAs($user)->get(route('profile.notifications'))->assertOk();

        $response->assertSeeText('Kotak masuk');
        $response->assertSeeText('Pop-up');
        $response->assertSeeText('Hanya kotak masuk');
        $response->assertSeeText('Pengaturan cepat');
        $response->assertSeeText('19 dari 19 aktif · 0 hanya kotak masuk · 0 nonaktif');
        $response->assertDontSeeText('Inbox only');
        $response->assertDontSeeText('Quick setup');
        $response->assertDontSee('placeholder="Search notifications..."', false);

        $xpath = $this->xpathFromHtml($response->getContent());
        $filterGroup = $xpath->query('//div[@role="group" and @aria-label="Filter notifications"]');
        $this->assertCount(0, $filterGroup, 'The filter group label must be translated');

        // Channel names inside each event row follow the locale
        $label = $xpath->query('//label[@for="notification-local_invoice_submitted"]//span[contains(@class, "tw:md:sr-only")]')->item(0);
        $this->assertNotNull($label);
        $this->assertSame('Kotak masuk', trim($label->textContent));
        $popup = $xpath->query('//label[@for="notification-local_invoice_submitted-popup"]//span[contains(@class, "tw:md:sr-only")]')->item(0);
        $this->assertNotNull($popup);
        $this->assertSame('Pop-up', trim($popup->textContent));

        // Bulk header checkbox names are translated too
        $this->assertGreaterThan(0, $xpath->query('//input[@data-ignore-dirty="true" and contains(@aria-label, "Kotak masuk untuk semua notifikasi")]')->length);
        $this->assertGreaterThan(0, $xpath->query('//input[@data-ignore-dirty="true" and contains(@aria-label, "Pop-up untuk semua notifikasi")]')->length);

        $form = $xpath->query('//form[@action="'.route('profile.notifications.update').'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertStringNotContainsString('—', $form->textContent);
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

<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalGaClaimDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $ga;

    private User $finance;

    private GaClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ga = User::factory()->create(['role' => 'ga']);
        $this->finance = User::factory()->create(['role' => 'finance']);
        foreach ([$this->ga, $this->finance] as $user) {
            $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        }
        $employee = Employee::create([
            'name' => 'Regional Employee', 'department' => 'GA', 'bank_name' => 'BCA',
            'account_number' => '1234567890', 'account_holder_name' => 'Regional Employee', 'is_active' => true,
        ]);
        $this->claim = $this->makeClaim($employee, '1250000.50');
        $this->claim->receipt()->create(['receipt_number' => 'TT-GA-REGIONAL-1', 'issued_at' => '2026-09-28 23:35:00']);
        $this->claim->statusHistories()->create([
            'from_status' => null, 'to_status' => GaClaim::STATUS_SUBMITTED,
            'actor_id' => $this->ga->id, 'event' => 'submitted', 'notes' => 'Original event',
            'created_at' => '2026-09-28 23:35:00',
        ]);
        $this->claim->documents()->create([
            'document_type' => 'supporting', 'revision_number' => 1, 'file_path' => 'regional/claim.pdf',
            'original_filename' => 'claim.pdf', 'mime_type' => 'application/pdf', 'file_size' => 125000,
            'uploaded_by' => $this->ga->id,
        ]);
    }

    public function test_system_preserves_each_claim_surface_legacy_date_and_zero_decimal_amount(): void
    {
        foreach ($this->surfaces() as [$user, $name, $parameters, $legacyDate]) {
            $html = $this->request($user, $name, $parameters);
            $this->assertStringContainsString($legacyDate, $this->claimDateText($html, str_ends_with($name, '.index')));
            $this->assertStringContainsString('Rp 1.250.001', $html);
            $this->assertStringNotContainsString('Rp 1.250.001,00', $html);
        }
        $this->assertSame('1250000.50', $this->claim->fresh()->amount);
    }

    public function test_regional_presets_change_only_claim_calendar_label_and_final_amount_separators(): void
    {
        $this->preference($this->ga, 'dmy', 'international');
        $this->preference($this->finance, 'dmy', 'international');
        foreach ($this->surfaces() as [$user, $name, $parameters]) {
            $html = $this->request($user, $name, $parameters);
            $this->assertSame('28/09/2026', $this->claimDateText($html, str_ends_with($name, '.index')));
            $this->assertStringContainsString('Rp 1,250,001', $html);
            $this->assertStringNotContainsString('Rp 1,250,001.00', $html);
            $this->assertStringContainsString(route(str_starts_with($name, 'finance.') ? 'finance.ga-claims.index' : 'ga.claims.index'), $html);
        }
        foreach ([$this->ga, $this->finance] as $user) {
            $this->preference($user, 'iso', 'indonesian');
        }
        foreach ($this->surfaces() as [$user, $name, $parameters]) {
            $html = $this->request($user, $name, $parameters);
            $this->assertSame('2026-09-28', $this->claimDateText($html, str_ends_with($name, '.index')));
            $this->assertStringContainsString('Rp 1.250.001', $html);
        }
        $this->assertSame('2026-09-28', $this->claim->fresh()->claim_date->toDateString());
        $this->assertSame('1250000.50', $this->claim->fresh()->amount);
    }

    public function test_zero_negative_integer_and_fractional_values_keep_legacy_zero_decimal_rounding(): void
    {
        $employee = $this->claim->employee;
        $cases = ['0.00' => '0', '-1250.50' => '-1,251', '1000.00' => '1,000', '1250.49' => '1,250'];
        foreach ($cases as $amount => $display) {
            $claim = $this->makeClaim($employee, $amount);
            foreach ([$this->ga, $this->finance] as $user) {
                $this->preference($user, 'human', 'international');
                $prefix = $user->role === 'ga' ? 'ga.claims' : 'finance.ga-claims';
                $html = $this->request($user, $prefix.'.show', [$claim]);
                $this->assertTrue(str_contains($html, 'Rp '.$display), 'Expected final zero-decimal amount: Rp '.$display);
                $this->assertSame($amount, $claim->fresh()->amount);
            }
        }
    }

    public function test_claim_forms_history_documents_and_authoritative_state_remain_identical(): void
    {
        foreach ([[$this->ga, 'ga.claims.show', GaClaim::STATUS_SUBMITTED], [$this->finance, 'finance.ga-claims.show', GaClaim::STATUS_BASIC_VERIFIED]] as [$user, $name, $status]) {
            $this->claim->forceFill(['status' => $status])->save();
            $beforeAttributes = $this->claim->fresh()->getAttributes();
            $baseline = $this->request($user, $name, [$this->claim]);
            $this->preference($user, 'iso', 'international');
            $regional = $this->request($user, $name, [$this->claim]);
            $this->assertSame($this->forms($baseline), $this->forms($regional));
            $this->assertSame(['28 Sep 23:35'], $this->historyTimes($baseline));
            $this->assertSame(['2026-09-29 06:35 WIB'], $this->historyTimes($regional));
            $this->assertStringContainsString('Original event', $regional);
            $this->assertStringContainsString(route('ga-claim-documents.show', $this->claim->documents->first()), $regional);
            $this->assertStringContainsString(route('ga.claims.receipt', $this->claim), $regional);
            $this->assertSame($beforeAttributes, $this->claim->fresh()->getAttributes());
            if ($user->role === 'finance') {
                $this->assertStringContainsString('122.1 KB', $regional);
                $this->assertStringContainsString('name="approve" value="1"', $regional);
                $this->assertStringContainsString('name="approve" value="0"', $regional);
            }
        }
    }

    public function test_history_instants_keep_system_parity_and_returned_order_without_mutating_claim_or_history(): void
    {
        $this->claim->statusHistories()->create([
            'from_status' => GaClaim::STATUS_SUBMITTED, 'to_status' => GaClaim::STATUS_BASIC_VERIFIED,
            'actor_id' => $this->finance->id, 'event' => 'basic_verified', 'notes' => 'Earlier instant added second',
            'created_at' => '2026-09-28 22:15:00',
        ]);
        $this->claim->statusHistories()->create([
            'from_status' => GaClaim::STATUS_BASIC_VERIFIED, 'to_status' => GaClaim::STATUS_READY_TO_PAY,
            'actor_id' => $this->ga->id, 'event' => 'ready_to_pay', 'notes' => 'Later instant added third',
            'created_at' => '2026-09-29 00:05:00',
        ]);
        $attributes = $this->claim->fresh()->getAttributes();
        $histories = $this->claim->statusHistories()->get();
        $historyAttributes = $histories->map(fn ($history) => $history->getAttributes())->all();
        $ids = $histories->modelKeys();
        foreach ([[$this->ga, 'ga.claims.show'], [$this->finance, 'finance.ga-claims.show']] as [$user, $name]) {
            $system = $this->request($user, $name, [$this->claim]);
            $this->assertSame(['28 Sep 23:35', '28 Sep 22:15', '29 Sep 00:05'], $this->historyTimes($system));
            $this->preference($user, 'dmy', 'international');
            $regional = $this->request($user, $name, [$this->claim]);
            $this->assertSame(['29/09/2026 06:35 WIB', '29/09/2026 05:15 WIB', '29/09/2026 07:05 WIB'], $this->historyTimes($regional));
            $this->assertSame($this->forms($system), $this->forms($regional));
            $this->assertStringContainsString('Oleh: '.$this->ga->name, $regional);
            $this->assertStringContainsString('Oleh: '.$this->finance->name, $regional);
            $this->assertTrue(strpos($regional, 'Original event') < strpos($regional, 'Earlier instant added second'));
            $this->assertTrue(strpos($regional, 'Earlier instant added second') < strpos($regional, 'Later instant added third'));
            $this->assertSame($ids, $this->claim->statusHistories()->get()->modelKeys());
            $this->assertSame($historyAttributes, $this->claim->statusHistories()->get()->map(fn ($history) => $history->getAttributes())->all());
            $this->assertSame($attributes, $this->claim->fresh()->getAttributes());
            $user->preference()->update(['time_format' => '12h']);
            $twelveHour = $this->request($user, $name, [$this->claim]);
            $this->assertSame(['29/09/2026 6:35 pagi WIB', '29/09/2026 5:15 pagi WIB', '29/09/2026 7:05 pagi WIB'], $this->historyTimes($twelveHour));
            $this->assertSame($this->forms($system), $this->forms($twelveHour));
        }
    }

    public function test_history_preference_lookup_stays_one_for_one_and_multiple_events_on_both_roles(): void
    {
        $this->preference($this->ga, 'dmy', 'international');
        $this->preference($this->finance, 'dmy', 'international');
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });
        foreach ([1, 6] as $eventCount) {
            while ($this->claim->statusHistories()->count() < $eventCount) {
                $this->claim->statusHistories()->create([
                    'from_status' => GaClaim::STATUS_SUBMITTED, 'to_status' => GaClaim::STATUS_SUBMITTED,
                    'actor_id' => $this->ga->id, 'event' => 'submitted', 'notes' => 'Repeated event',
                    'created_at' => '2026-09-28 23:35:00',
                ]);
            }
            foreach ([[$this->ga, 'ga.claims.show'], [$this->finance, 'finance.ga-claims.show']] as [$user, $name]) {
                $counter->count = 0;
                $html = $this->request($user, $name, [$this->claim]);
                $this->assertSame(1, $counter->count, $name.' must reuse preferences across history events.');
                $this->assertCount($eventCount, $this->historyTimes($html));
                $this->assertSame(array_fill(0, $eventCount, '29/09/2026 06:35 WIB'), $this->historyTimes($html));
            }
        }
    }

    public function test_preferences_do_not_change_claim_order_search_status_or_canonical_filter_values(): void
    {
        $other = $this->makeClaim($this->claim->employee, '900.00');
        $other->forceFill(['status' => GaClaim::STATUS_READY_TO_PAY])->save();
        $parameters = ['search' => $this->claim->claim_number, 'status' => GaClaim::STATUS_SUBMITTED, 'employee_id' => $this->claim->employee_id];
        $baseline = $this->request($this->finance, 'finance.ga-claims.index', $parameters);
        $this->preference($this->finance, 'iso', 'international');
        $regional = $this->request($this->finance, 'finance.ga-claims.index', $parameters);
        $this->assertSame($this->forms($baseline), $this->forms($regional));
        $this->assertStringContainsString($this->claim->claim_number, $regional);
        $this->assertStringNotContainsString($other->claim_number, $regional);
        $this->assertStringContainsString(route('finance.ga-claims.show', $this->claim), $regional);
    }

    public function test_preference_query_count_stays_one_for_single_and_multiple_claim_rows_and_details(): void
    {
        $this->preference($this->ga, 'dmy', 'international');
        $this->preference($this->finance, 'dmy', 'international');
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });
        foreach ($this->surfaces() as [$user, $name, $parameters]) {
            $counter->count = 0;
            $this->request($user, $name, $parameters);
            $this->assertSame(1, $counter->count, $name.' should resolve preferences once.');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->makeClaim($this->claim->employee, '1234.50');
        }
        foreach ([[$this->ga, 'ga.claims.index'], [$this->finance, 'finance.ga-claims.index']] as [$user, $name]) {
            $counter->count = 0;
            $this->request($user, $name);
            $this->assertSame(1, $counter->count, $name.' must not query preferences per row.');
        }
    }

    public function test_role_boundaries_and_hashed_claim_links_remain_intact(): void
    {
        $this->actingAs($this->ga)->get(route('finance.ga-claims.index'))->assertForbidden();
        $this->actingAs($this->finance)->get(route('ga.claims.index'))->assertForbidden();
        $supplier = User::factory()->create(['role' => 'supplier']);
        foreach (['ga.claims.show', 'finance.ga-claims.show'] as $name) {
            $this->actingAs($supplier)->get(route($name, $this->claim))->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['ga.claims.show', 'finance.ga-claims.show'] as $name) {
            $this->request($admin, $name, [$this->claim]);
        }
        $this->assertStringEndsWith('/'.$this->claim->hash, route('ga.claims.show', $this->claim));
    }

    public function test_receipt_semantic_values_do_not_follow_personal_regional_preferences(): void
    {
        $this->actingAs($this->ga);
        $this->claim->load(['employee', 'receipt']);
        $baseline = view('ga.claims.receipt', ['claim' => $this->claim])->render();
        $this->preference($this->ga, 'iso', 'international');
        app()->forgetScopedInstances();
        $regional = view('ga.claims.receipt', ['claim' => $this->claim])->render();
        preg_match('/<dl\b[^>]*>.*?<\/dl>/s', $baseline, $before);
        preg_match('/<dl\b[^>]*>.*?<\/dl>/s', $regional, $after);
        $this->assertNotEmpty($before);
        $this->assertSame($before[0], $after[0]);
        $this->assertStringContainsString('28 Sep 2026', $after[0]);
        $this->assertStringContainsString('Rp 1.250.001', $after[0]);
    }

    private function makeClaim(Employee $employee, string $amount): GaClaim
    {
        return GaClaim::create([
            'claim_number' => 'CLM-REGIONAL-'.(GaClaim::count() + 1), 'employee_id' => $employee->id,
            'claim_type' => GaClaim::TYPE_BUSINESS_TRAVEL, 'claim_date' => '2026-09-28', 'amount' => $amount,
            'description' => 'Display fixture', 'status' => GaClaim::STATUS_SUBMITTED,
            'submitted_by' => $this->ga->id, 'submitted_at' => '2026-09-28 23:35:00',
        ]);
    }

    private function preference(User $user, string $date, string $number): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->updateOrCreate([], [...$defaults, 'locale' => 'id', 'timezone' => 'Asia/Jakarta', 'date_format' => $date, 'number_format' => $number]);
    }

    private function surfaces(): array
    {
        return [
            [$this->ga, 'ga.claims.index', [], '28 Sep 2026'],
            [$this->ga, 'ga.claims.show', [$this->claim], '28 Sep 2026'],
            [$this->finance, 'finance.ga-claims.index', [], '28/09/2026'],
            [$this->finance, 'finance.ga-claims.show', [$this->claim], '28 Sep 2026'],
        ];
    }

    private function request(User $user, string $name, array $parameters = []): string
    {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->get(route($name, $parameters))->assertOk()->getContent();
    }

    private function claimDateText(string $html, bool $isIndex): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        if ($isIndex) {
            $column = 1 + $xpath->query('//th[normalize-space(.)="Tanggal"]/preceding-sibling::th')->length;
            $query = '//tr[td[contains(., "'.$this->claim->claim_number.'")]]/td['.$column.']';
        } else {
            $query = '//span[contains(., "Tanggal Pengajuan:")]/following-sibling::strong[1]';
        }
        $node = $xpath->query($query)->item(0);
        $this->assertNotNull($node, 'Claim date cell must exist.');

        return trim($node->textContent);
    }

    private function historyTimes(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $times = [];
        foreach ($xpath->query('//section[header//h2[normalize-space(.)="Jejak Status Klaim" or normalize-space(.)="Jejak Aktivitas Status"]]//div[strong and span]/span') as $node) {
            $times[] = trim($node->textContent);
        }

        return $times;
    }

    private function forms(string $html): array
    {
        preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $html, $matches);

        return $matches[0];
    }
}

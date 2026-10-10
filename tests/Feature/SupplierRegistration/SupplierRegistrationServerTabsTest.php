<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class SupplierRegistrationServerTabsTest extends TestCase
{
    use RefreshDatabase;

    private const FRAGMENT_HEADERS = [
        'X-Adasi-Server-Tabs' => '1',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->reviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
    }

    private function pendingAttempt(): SupplierRegistrationAttempt
    {
        return app(SupplierRegistrationService::class)->submitInitialRegistration(
            data: [
                'company_title' => 'PT',
                'company_name' => 'PT Logam Jaya Perkasa',
                'address' => 'Kawasan Industri KIIC, Karawang',
                'phone' => '0267-123456',
                'category' => 'Steel',
                'nib' => '8888888888888',
                'npwp' => '88.888.888.8-888.000',
                'pic_name' => 'Hendro',
                'pic_email' => 'hendro@logamjaya.com',
                'pic_phone' => '081288888888',
                'bank_name' => 'BCA',
                'account_number' => '888000111222',
                'account_holder_name' => 'PT Logam Jaya Perkasa',
                'email' => 'contact@logamjaya.com',
                'password' => 'Password123!',
            ],
            files: [
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            ],
        )['attempt'];
    }

    public function test_full_page_exposes_the_server_tabs_contract(): void
    {
        $this->pendingAttempt();

        $this->actingAs($this->reviewer)->get(route('supplier-registrations.index'))
            ->assertOk()
            ->assertSee('id="supplierRegistrationContainer"', false)
            ->assertSee('data-server-tabs-container', false)
            ->assertSee('data-server-tabs-nav', false)
            ->assertSee('data-server-tabs-content', false)
            ->assertSee('data-server-tabs-form', false)
            ->assertSee('data-tab-name="PENDING"', false)
            ->assertSee('data-tab-name="APPROVED"', false);
    }

    public function test_fragment_request_returns_the_status_table_and_a_fresh_nav(): void
    {
        $this->pendingAttempt();
        $url = route('supplier-registrations.index', ['status' => 'PENDING']);

        $response = $this->actingAs($this->reviewer)->withHeaders(self::FRAGMENT_HEADERS)->get($url);

        $response->assertOk()->assertJsonStructure(['html', 'nav', 'tab', 'url']);
        $this->assertSame('PENDING', $response->json('tab'));
        $this->assertSame($url, $response->json('url'));
        $this->assertStringContainsString('PT Logam Jaya Perkasa', $response->json('html'));
        $this->assertStringContainsString('name="search"', $response->json('html'));
        $this->assertStringContainsString('data-server-tabs-form', $response->json('html'));
        $this->assertStringNotContainsString('<html', $response->json('html'));
        $this->assertMatchesRegularExpression('/data-tab-name="PENDING"[^>]*aria-current="page"/s', $response->json('nav'));

        $approved = $this->actingAs($this->reviewer)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('supplier-registrations.index', ['status' => 'APPROVED']));
        $this->assertSame('APPROVED', $approved->json('tab'));
        $this->assertStringNotContainsString('PT Logam Jaya Perkasa', $approved->json('html'));
    }

    public function test_nav_links_carry_the_search_so_they_stay_correct_after_an_ajax_search(): void
    {
        $this->pendingAttempt();

        $response = $this->actingAs($this->reviewer)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('supplier-registrations.index', ['status' => 'ALL', 'search' => 'Logam']));

        $response->assertOk();
        $this->assertSame(5, substr_count($response->json('nav'), 'search=Logam'), 'every status link keeps the search term');
    }

    public function test_a_bare_xhr_without_the_header_still_reaches_the_legacy_datatables_branch(): void
    {
        $this->pendingAttempt();

        $this->actingAs($this->reviewer)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get(route('supplier-registrations.index', ['draw' => 1]))
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_fragment_requests_are_authorized_like_the_page(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $this->actingAs($supplier)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('supplier-registrations.index'))->assertForbidden();
        $this->actingAs($qc)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('supplier-registrations.index'))->assertForbidden();

        auth()->logout();
        $this->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('supplier-registrations.index'))->assertUnauthorized();
    }
}

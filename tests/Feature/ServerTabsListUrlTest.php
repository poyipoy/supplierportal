<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerTabsListUrlTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_KEY = 'purchasing.index_urls.purchasing.supplier-audits.index';

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
    }

    public function test_a_server_tabs_fragment_request_updates_the_remembered_list_url(): void
    {
        $url = route('purchasing.supplier-audits.index', ['queue' => 'late']);

        $this->actingAs($this->purchasing)
            ->withHeaders(['X-Adasi-Server-Tabs' => '1', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get($url);

        $this->assertSame($url, session(self::SESSION_KEY), 'back-to-list must return to the tab and filters the user was looking at');
    }

    public function test_other_json_requests_still_do_not_overwrite_the_remembered_list_url(): void
    {
        $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.supplier-audits.index', ['queue' => 'late']));

        $this->assertNull(session(self::SESSION_KEY));
    }
}

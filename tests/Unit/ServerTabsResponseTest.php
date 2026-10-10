<?php

namespace Tests\Unit;

use App\Support\ServerTabsResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class ServerTabsResponseTest extends TestCase
{
    public function test_only_requests_sent_by_the_server_tabs_controller_are_fragment_requests(): void
    {
        $fragment = Request::create('/list', 'GET', server: ['HTTP_X_ADASI_SERVER_TABS' => '1']);
        $dataTables = Request::create('/list', 'GET', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
        $plain = Request::create('/list', 'GET');

        $this->assertTrue(ServerTabsResponse::wants($fragment));
        $this->assertFalse(ServerTabsResponse::wants($dataTables), 'a bare XHR must keep reaching the existing DataTables branches');
        $this->assertFalse(ServerTabsResponse::wants($plain));
    }

    public function test_the_fragment_payload_carries_html_tab_url_and_any_extras(): void
    {
        $request = Request::create('/list?queue=late&period=2026', 'GET', server: ['HTTP_X_ADASI_SERVER_TABS' => '1']);

        $payload = ServerTabsResponse::make('<table></table>', 'late', $request, ['nav' => '<a>nav</a>'])->getData(true);

        $this->assertSame([
            'html' => '<table></table>',
            'tab' => 'late',
            'url' => 'http://localhost/list?period=2026&queue=late',
            'nav' => '<a>nav</a>',
        ], $payload);
    }
}

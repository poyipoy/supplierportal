<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RustHybridSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_remove_wasm_unsafe_eval_and_worker_src_from_csp(): void
    {
        $response = $this->get('/login')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('wasm-unsafe-eval', $csp);
        $this->assertStringNotContainsString('worker-src', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'unsafe-eval' 'nonce-[^']+'/", $csp);
    }

    public function test_rust_crates_wasm_assets_and_erp_delta_sync_are_decommissioned(): void
    {
        $deltaRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => is_string($name) && str_contains($name, 'erp-delta'))
            ->values()
            ->all();

        $this->assertSame([], $deltaRoutes);
        $this->assertFalse(Schema::hasTable('erp_delta_rows'));

        foreach ([
            'rust-portal-wasm',
            'rust-inode-guard',
            'bin/inode-guard',
            'public/wasm',
            'public/js/workers/portal-wasm.worker.js',
            'resources/js/pdf-wasm-compress.js',
        ] as $removedPath) {
            $this->assertFileDoesNotExist(base_path($removedPath));
        }
    }
}

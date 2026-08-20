<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomAdasiToastTest extends TestCase
{
    public function test_toast_container_renders_on_application_and_authentication_layouts(): void
    {
        foreach ([
            'layouts/app.blade.php',
            'layouts/auth.blade.php',
            'layouts/guest.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString('x-ui.toast-container', $contents, "Toast container is missing from {$view}");
        }

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('x-data="adasiToastCenter"', false);
        $response->assertSee('aria-label="Notifications"', false);
        $response->assertSee('aria-label="Dismiss notification"', false);
        $response->assertSee('role="progressbar"', false);
    }

    public function test_public_api_exposes_all_required_toast_methods_and_accessibility_contracts(): void
    {
        $runtime = file_get_contents(resource_path('js/app.js'));

        foreach ([
            'show',
            'success',
            'error',
            'warning',
            'info',
            'progress',
            'update',
            'dismiss',
            'clear',
        ] as $method) {
            $this->assertMatchesRegularExpression('/\b'.preg_quote($method, '/').'\s*:/', $runtime);
        }

        $this->assertStringContainsString('window.AdasiToast = Object.freeze({', $runtime);
        $this->assertStringContainsString("type === 'action'", $runtime);
        $this->assertStringContainsString('const maxVisibleToasts = 4', $runtime);
        $this->assertStringContainsString('pauseToast', $runtime);
        $this->assertStringContainsString('resumeToast', $runtime);
        $this->assertStringContainsString("type === 'error' ? 'assertive' : 'polite'", file_get_contents(resource_path('views/components/ui/toast-container.blade.php')));
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', file_get_contents(resource_path('css/app.css')));
    }

    public function test_flash_messages_map_once_to_toasts_and_validation_remains_inline(): void
    {
        $message = '<img src=x onerror=alert(1)>';
        $response = $this
            ->withSession([
                'success' => $message,
                'warning' => 'Check the quotation period.',
                'info' => 'Exchange rate refreshed.',
            ])
            ->get('/login');

        $response->assertOk();
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        $response->assertDontSee('data-message="<img', false);
        $response->assertSee('data-title="Operation completed"', false);
        $response->assertSee('data-title="Attention required"', false);
        $response->assertSee('data-title="Update"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-message="&lt;img src=x onerror=alert(1)&gt;"'));

        $runtime = file_get_contents(public_path('assets/js/adasi-alert.js'));
        $this->assertSame(1, substr_count($runtime, "document.querySelectorAll('[data-adasi-flash]')"));
        $this->assertStringContainsString('window.AdasiToast.show({', $runtime);

        $partial = file_get_contents(resource_path('views/partials/alerts.blade.php'));
        $this->assertStringContainsString('$errors->any()', $partial);
        $this->assertStringContainsString('alert alert-danger', $partial);
    }

    public function test_legacy_transient_alert_calls_delegate_without_removing_blocking_confirmations(): void
    {
        $runtime = file_get_contents(public_path('assets/js/adasi-alert.js'));

        $this->assertStringContainsString('if (window.AdasiToast)', $runtime);
        $this->assertStringContainsString('window.AdasiToast.show({', $runtime);
        $this->assertStringContainsString('confirm: (options) => confirm(options, false)', $runtime);
        $this->assertStringContainsString('confirmDanger: (options) => confirm(options, true)', $runtime);
        $this->assertStringContainsString('prompt: prompt', $runtime);
        $this->assertStringContainsString('window.AdasiAlert = Object.freeze(AdasiAlert)', $runtime);
    }

    public function test_async_export_uses_one_indeterminate_progress_toast_and_real_completion_state(): void
    {
        $runtime = file_get_contents(public_path('assets/js/async-export.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('window.AdasiAlert.confirm(options)', $runtime);
        $this->assertStringContainsString('window.AdasiToast.progress({', $runtime);
        $this->assertStringContainsString("title: 'Starting export'", $runtime);
        $this->assertStringContainsString("message: 'Submitting the export request...'", $runtime);
        $this->assertStringContainsString('window.AdasiToast?.update(state.toastId, changes)', $runtime);
        $this->assertStringContainsString('indeterminate: true', $runtime);
        $this->assertStringContainsString('progress: 100', $runtime);
        $this->assertStringNotContainsString('progress: 32', $runtime);
        $this->assertStringNotContainsString('progress: 64', $runtime);
        $this->assertStringNotContainsString('progress: 85', $runtime);
        $this->assertStringContainsString("label: 'View jobs'", $runtime);
        $this->assertStringContainsString('window.AdasiAsyncExport = Object.freeze({', $runtime);
        $this->assertStringContainsString('isTrackingNotification', $runtime);
        $this->assertStringContainsString('completedExportRetentionMs = 30000', $runtime);
        $this->assertStringNotContainsString('border-inline-start: 3px solid var(--adasi-toast-accent)', $styles);

        $startExportPosition = strpos($runtime, 'const startExport = async (control) =>');
        $startingToastPosition = strpos($runtime, 'toastId: createStartingToast()', $startExportPosition);
        $requestPosition = strpos($runtime, 'const response = await window.fetch(requestUrl', $startExportPosition);

        $this->assertIsInt($startExportPosition);
        $this->assertIsInt($startingToastPosition);
        $this->assertIsInt($requestPosition);
        $this->assertLessThan($requestPosition, $startingToastPosition);
    }

    public function test_persistent_notification_center_remains_separate_from_transient_delivery(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertTrue(Route::has('notifications.index'));
        $this->assertTrue(Route::has('notifications.mark-all-read'));
        $this->assertFileExists(resource_path('views/notifications/index.blade.php'));
        $this->assertStringContainsString('insertNotification(', $layout);
        $this->assertStringContainsString("type: 'message'", $layout);
        $this->assertStringContainsString("label: 'View'", $layout);
        $this->assertStringContainsString('markReadAndRedirect(', $layout);
        $this->assertStringContainsString('deliverTransientNotification(', $layout);
        $this->assertStringContainsString('shouldSuppressTransientNotification(', $layout);
        $this->assertStringContainsString('window.AdasiAsyncExport', $layout);
        $this->assertStringContainsString("'export.completed'", $layout);
    }
}

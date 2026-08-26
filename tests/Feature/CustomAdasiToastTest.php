<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
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
        $this->assertStringContainsString('window.AdasiToast.show(payload)', $runtime);
        $this->assertStringContainsString('window.__adasiToastQueue.push(payload)', $runtime);

        $partial = file_get_contents(resource_path('views/partials/alerts.blade.php'));
        $this->assertStringContainsString('$errors->any()', $partial);
        $this->assertStringContainsString('<x-ui.alert tone="error"', $partial);
        $this->assertStringNotContainsString('alert alert-danger', $partial);
    }

    public function test_legacy_transient_alert_calls_delegate_without_removing_blocking_confirmations(): void
    {
        $runtime = file_get_contents(public_path('assets/js/adasi-alert.js'));

        $this->assertStringContainsString('if (window.AdasiToast)', $runtime);
        $this->assertStringContainsString('window.AdasiToast.show(payload)', $runtime);
        $this->assertStringContainsString('window.__adasiToastQueue.push(payload)', $runtime);
        $this->assertStringNotContainsString('toast: true', $runtime);
        $this->assertStringContainsString('confirm: (options) => confirm(options, false)', $runtime);
        $this->assertStringContainsString('confirmDanger: (options) => confirm(options, true)', $runtime);
        $this->assertStringContainsString('prompt: prompt', $runtime);
        $this->assertStringContainsString('window.AdasiAlert = Object.freeze(AdasiAlert)', $runtime);
    }

    public function test_async_export_uses_one_realtime_row_progress_toast_and_real_completion_state(): void
    {
        $runtime = file_get_contents(public_path('assets/js/async-export.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('window.AdasiAlert.confirm(options)', $runtime);
        $this->assertStringContainsString('window.AdasiToast.progress({', $runtime);
        $this->assertStringContainsString("title: 'Starting export'", $runtime);
        $this->assertStringContainsString("message: 'Submitting the export request...'", $runtime);
        $this->assertStringContainsString('window.AdasiToast?.update(state.toastId, changes)', $runtime);
        $this->assertStringContainsString('progress: 100', $runtime);
        $this->assertStringContainsString('processed_rows', $runtime);
        $this->assertStringContainsString('total_rows', $runtime);
        $this->assertStringContainsString('rowProgressLabel(processedRows, totalRows, rowLabel)', $runtime);
        $this->assertStringContainsString('Processed ${rowProgressLabel(processedRows, totalRows, rowLabel)}.', $runtime);
        $this->assertStringContainsString("rowLabel.replace(/\\brows$/i, 'row')", $runtime);
        $this->assertStringContainsString('handleProgress', $runtime);
        $this->assertStringContainsString('progressStageLabels', $runtime);
        $this->assertStringContainsString("label: 'View jobs'", $runtime);
        $this->assertStringContainsString("label: 'Cancel'", $runtime);
        $this->assertStringContainsString("label: 'Dismiss'", $runtime);
        $this->assertStringContainsString("variant: 'danger'", $runtime);
        $this->assertStringContainsString('maxActions: 3', $runtime);
        $this->assertStringContainsString('dismiss: false', $runtime);
        $this->assertStringContainsString('window.AdasiAsyncExport = Object.freeze({', $runtime);
        $this->assertStringContainsString('isTrackingNotification', $runtime);
        $this->assertStringContainsString('completedExportRetentionMs = 30000', $runtime);
        $toast = file_get_contents(resource_path('views/components/ui/toast-container.blade.php'));
        $this->assertStringContainsString('adasi-toast__progress-ring', $toast);
        $this->assertStringContainsString('50.265 * (1 - toast.progress / 100)', $toast);
        $this->assertStringContainsString('toast.indeterminate', $toast);
        $this->assertStringNotContainsString('border-inline-start: 3px solid var(--adasi-toast-accent)', $styles);

        $startExportPosition = strpos($runtime, 'const startExport = async (control) =>');
        $startingToastPosition = strpos($runtime, 'toastId: createStartingToast(toastId)', $startExportPosition);
        $requestPosition = strpos($runtime, 'const response = await window.fetch(requestUrl', $startExportPosition);

        $this->assertIsInt($startExportPosition);
        $this->assertIsInt($startingToastPosition);
        $this->assertIsInt($requestPosition);
        $this->assertLessThan($requestPosition, $startingToastPosition);
    }

    public function test_async_export_dismissal_is_persisted_without_suppressing_terminal_result(): void
    {
        $runtime = file_get_contents(public_path('assets/js/async-export.js'));
        $toastRuntime = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("new CustomEvent('adasi:toast-dismissed'", $toastRuntime);
        $this->assertStringContainsString("window.addEventListener('adasi:toast-dismissed', handleToastDismissed)", $runtime);
        $this->assertStringContainsString('progressDismissed: state.progressDismissed === true', $runtime);
        $this->assertStringContainsString('terminalDismissed: state.terminalDismissed === true', $runtime);
        $this->assertStringContainsString('exportsUrl: state.exportsUrl', $runtime);
        $this->assertStringContainsString('cancelUrl: state.cancelUrl', $runtime);
        $this->assertStringContainsString('if (terminal && state.terminalDismissed) return;', $runtime);
        $this->assertStringContainsString('if (!terminal && state.progressDismissed) return;', $runtime);
        $this->assertStringContainsString('const terminal = changes.terminal === true;', $runtime);
        $this->assertStringContainsString('terminal: true', $runtime);
        $this->assertStringContainsString('rehydrateToast: !restoredProgressDismissed && !restoredTerminalStatus', $runtime);
        $this->assertStringContainsString('actions: progressActionsForState(state)', $runtime);
        $this->assertStringContainsString('const isManualDismissReason = (reason) =>', $runtime);
        $this->assertStringContainsString("['manual', 'action', 'clear']", $runtime);
        $this->assertStringContainsString('View jobs', $runtime);
        $this->assertStringContainsString('Dismiss', $runtime);
        $this->assertStringContainsString('cancelExport', $runtime);
    }

    public function test_async_export_presentation_uses_explicit_filtered_source_counts_and_persisted_output_units(): void
    {
        $runtime = file_get_contents(public_path('assets/js/async-export.js'));

        $this->assertStringContainsString('const dataTableSourceCount = (control) =>', $runtime);
        $this->assertStringContainsString('control?.dataset?.exportCountTable', $runtime);
        $this->assertStringContainsString('DataTable().page.info().recordsDisplay', $runtime);
        $this->assertStringNotContainsString('dataTable.tables(true)', $runtime);
        $this->assertStringNotContainsString('.page.info().recordsTotal', $runtime);
        $this->assertStringContainsString('sourceCount === null', $runtime);
        $this->assertStringNotContainsString('data rows to Excel', $runtime);
        $this->assertStringContainsString('rowLabel: state.rowLabel', $runtime);
        $this->assertStringContainsString("cleanPresentationText(record.rowLabel, 'rows')", $runtime);
        $this->assertStringContainsString('Each material item will be written as a separate Excel row.', file_get_contents(resource_path('views/purchasing/pr/index.blade.php')));
        $this->assertStringContainsString('data-export-count-table="#historyTable"', file_get_contents(resource_path('views/qc/inspections/index.blade.php')));

        $controlCount = 0;
        foreach (File::allFiles(resource_path('views')) as $view) {
            $contents = $view->getContents();
            if (! str_contains($contents, 'data-async-export')) {
                continue;
            }

            $offset = 0;
            while (($position = strpos($contents, 'data-async-export', $offset)) !== false) {
                $controlCount++;
                $relativePath = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $view->getPathname());
                $controlContext = substr($contents, max(0, $position - 600), 1600);
                $this->assertStringContainsString('data-export-source-singular=', $controlContext, "Missing singular source label in {$relativePath}");
                $this->assertStringContainsString('data-export-source-plural=', $controlContext, "Missing plural source label in {$relativePath}");
                $this->assertStringContainsString('data-export-row-label=', $controlContext, "Missing workbook row label in {$relativePath}");
                $this->assertStringContainsString('data-export-row-explanation=', $controlContext, "Missing row explanation in {$relativePath}");
                $offset = $position + strlen('data-async-export');
            }
        }

        $this->assertSame(15, $controlCount);
    }

    public function test_async_export_toast_rehydrates_before_polling_with_scoped_view_transition(): void
    {
        $exportRuntime = file_get_contents(public_path('assets/js/async-export.js'));
        $toastRuntime = file_get_contents(resource_path('js/app.js'));
        $toastView = file_get_contents(resource_path('views/components/ui/toast-container.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("pendingExportStorageKey = 'adasi:pending-export-jobs:v1'", $exportRuntime);
        $this->assertStringContainsString('toastId: state.toastId', $exportRuntime);
        $this->assertStringContainsString('const showOrQueueProgressToast = (options) =>', $exportRuntime);
        $this->assertStringContainsString('window.__adasiToastQueue.push({', $exportRuntime);
        $this->assertStringContainsString('restored: true', $exportRuntime);
        $this->assertStringContainsString('[401, 403, 404, 410]', $exportRuntime);

        $resumePosition = strpos($exportRuntime, 'const resumePersistedExports = () =>');
        $hydratePosition = strpos($exportRuntime, 'state.toastId = showOrQueueProgressToast({', $resumePosition);
        $pollPosition = strpos($exportRuntime, 'pollStatus(state);', $resumePosition);

        $this->assertIsInt($resumePosition);
        $this->assertIsInt($hydratePosition);
        $this->assertIsInt($pollPosition);
        $this->assertLessThan($pollPosition, $hydratePosition);

        $this->assertStringContainsString('if (options?.id)', $toastRuntime);
        $this->assertStringContainsString('restored: options.restored === true', $toastRuntime);
        $this->assertStringContainsString('adasi-toast--restored', $toastView);
        $this->assertStringContainsString('@view-transition', $styles);
        $this->assertStringContainsString('view-transition-name: adasi-toast-region', $styles);
        $this->assertStringContainsString('::view-transition-group(adasi-toast-region)', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
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
        $this->assertStringContainsString("'.export.progress'", $layout);
        $this->assertStringContainsString('handleProgress?.(progress)', $layout);
    }
}

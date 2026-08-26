<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class SurfaceHierarchyTest extends TestCase
{
    public function test_internal_workspace_uses_layered_surface_tokens_without_changing_auth_background(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $authLayout = file_get_contents(resource_path('views/layouts/auth.blade.php'));

        $this->assertStringContainsString('--md-background: #F8FAFC;', $styles);
        $this->assertStringContainsString('--ui-workspace-bg: #E9EEF4;', $styles);
        $this->assertStringContainsString('--ui-surface-primary: var(--md-surface);', $styles);
        $this->assertStringContainsString('--ui-surface-secondary: var(--md-surface-container);', $styles);
        $this->assertStringContainsString('--ui-surface-subtle: var(--md-surface-container-low);', $styles);
        $this->assertStringContainsString('--ui-surface-border: var(--md-outline);', $styles);
        $this->assertStringContainsString('--ui-surface-divider: var(--md-outline-variant);', $styles);
        $this->assertStringContainsString('--ui-control-border: var(--md-outline-strong);', $styles);
        $this->assertStringContainsString('background-color: var(--ui-workspace-bg);', $styles);
        $this->assertStringContainsString('background: var(--md-background);', $authLayout);
    }

    public function test_shared_static_components_use_strong_outer_boundaries_and_no_default_elevation(): void
    {
        $componentPaths = [
            'views/components/ui/card.blade.php',
            'views/components/ui/data-table.blade.php',
            'views/components/ui/form-section.blade.php',
            'views/components/ui/metric-card.blade.php',
            'views/components/ui/toolbar.blade.php',
            'views/components/purchasing/comparison-tabs.blade.php',
        ];

        foreach ($componentPaths as $componentPath) {
            $source = file_get_contents(resource_path($componentPath));

            $this->assertStringContainsString('tw-border-outline', $source, "Missing strong outer border in {$componentPath}");
            $this->assertStringNotContainsString('tw-shadow-ui-1', $source, "Static elevation remains in {$componentPath}");
        }

        $dataTable = Blade::render('<x-ui.data-table title="Records"><table><tbody><tr><td>One</td></tr></tbody></table></x-ui.data-table>');
        $formSection = Blade::render('<x-ui.form-section title="Details"><x-ui.input name="name" label="Name" /></x-ui.form-section>');
        $metric = Blade::render('<x-ui.metric-card label="Open records" value="12" icon="clipboard-list" />');

        $this->assertStringContainsString('tw-bg-surface-container', $dataTable);
        $this->assertStringContainsString('tw-shadow-none', $dataTable);
        $this->assertStringContainsString('tw-bg-surface-container', $formSection);
        $this->assertStringContainsString('tw-border-outline-strong', $formSection);
        $this->assertStringContainsString('tw-bg-surface-container', $metric);
        $this->assertStringContainsString('tw-shadow-none', $metric);
    }

    public function test_active_internal_views_do_not_bypass_surface_tokens_or_restore_static_card_shadows(): void
    {
        $directories = [
            'views/admin',
            'views/purchasing',
            'views/supplier',
            'views/qc',
            'views/profile',
            'views/conversations',
            'views/notifications',
            'views/exports',
        ];

        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($directory)));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                $relativePath = str_replace(resource_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

                $this->assertDoesNotMatchRegularExpression(
                    '/\b(?:tw-)?bg-white\b|(?:background|background-color)\s*:\s*(?:white|#fff(?:fff)?)(?:\s|;|!)/i',
                    $source,
                    "Raw white surface bypass remains in {$relativePath}",
                );
                $this->assertStringNotContainsString(
                    'tw-shadow-ui-1',
                    $source,
                    "Default static-container shadow remains in {$relativePath}",
                );
            }
        }
    }
}

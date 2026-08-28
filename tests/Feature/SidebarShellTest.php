<?php

namespace Tests\Feature;

use Tests\TestCase;

class SidebarShellTest extends TestCase
{
    public function test_shell_preserves_stable_sidebar_contracts(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $sidebar = file_get_contents(resource_path('views/partials/sidebar.blade.php'));
        $navbar = file_get_contents(resource_path('views/partials/navbar.blade.php'));

        $this->assertStringContainsString("localStorage.getItem('sidebarCollapsed')", $layout);
        $this->assertStringContainsString('x-on:ui-sidebar-toggle.window', $layout);
        $this->assertStringContainsString('id="sidebarOverlay"', $layout);
        $this->assertStringContainsString('id="mainWrapper"', $layout);
        $this->assertStringContainsString('id="sidebar"', $sidebar);
        $this->assertStringContainsString('class="sidebar-control"', $sidebar);
        $this->assertStringContainsString('sidebar-toggle--desktop', $sidebar);
        $this->assertStringContainsString('sidebar-toggle-label', $sidebar);
        $this->assertStringContainsString('brand-text sidebar-type-text', $sidebar);
        $this->assertStringContainsString('sidebar-toggle-label sidebar-type-text', $sidebar);
        $this->assertStringContainsString('sidebar-heading-label sidebar-type-text', $sidebar);
        $this->assertStringContainsString('Collapse sidebar', $sidebar);
        $this->assertStringContainsString('sidebar-toggle-icon--collapse', $sidebar);
        $this->assertStringContainsString('sidebar-toggle-icon--expand', $sidebar);
        $this->assertStringContainsString('sidebar-toggle-icon--collapse', $navbar);
        $this->assertStringContainsString('sidebar-toggle-icon--expand', $navbar);
        $this->assertStringContainsString('sidebar-toggle--mobile', $navbar);
        $sidebarItem = file_get_contents(resource_path('views/components/ui/sidebar-item.blade.php'));
        $this->assertStringContainsString('data-sidebar-tooltip', $sidebarItem);
        $this->assertStringContainsString('sidebar-link-label sidebar-type-text', $sidebarItem);
        $this->assertStringContainsString('html_entity_decode', $sidebarItem);
        $this->assertStringContainsString('mb_strlen($visibleLabel)', $sidebarItem);
        $this->assertStringContainsString('--sidebar-type-steps:', $sidebarItem);
        $this->assertStringContainsString('x-bind:aria-expanded', $navbar);
        $this->assertStringContainsString('panel-left-close', $navbar);
        $this->assertStringContainsString('panel-left-open', $navbar);
    }

    public function test_shared_sidebar_keeps_every_role_and_accessible_unread_state(): void
    {
        $sidebar = file_get_contents(resource_path('views/partials/sidebar.blade.php'));

        foreach (['purchasing', 'supplier', 'qc', 'admin'] as $role) {
            $this->assertStringContainsString("\$role === '{$role}'", $sidebar);
        }

        $this->assertStringContainsString('aria-label="Unread conversations:', $sidebar);
        $this->assertStringContainsString('closeMobileSidebar(false)', $sidebar);
    }

    public function test_shell_runtime_handles_persistence_responsive_state_and_focus(): void
    {
        $runtime = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("matchMedia('(min-width: 992px)')", $runtime);
        $this->assertStringContainsString("localStorage.setItem('sidebarCollapsed'", $runtime);
        $this->assertStringContainsString('syncSidebarTooltips()', $runtime);
        $this->assertStringContainsString('sidebarReturnFocus', $runtime);
        $this->assertStringContainsString('trapSidebarFocus(event)', $runtime);
        $this->assertStringContainsString("document.querySelector('.sidebar-toggle--mobile')", $runtime);
        $this->assertStringContainsString('dataset.sidebarMotionReady', $runtime);
        $this->assertStringContainsString('document.documentElement.dataset.sidebarCollapsed', $runtime);
    }

    public function test_sidebar_styles_keep_compact_dimensions_and_reduced_motion_support(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--sidebar-width: 256px', $styles);
        $this->assertStringContainsString('--sidebar-width-collapsed: 64px', $styles);
        $this->assertStringContainsString('--sidebar-rail-center: 32px', $styles);
        $this->assertStringContainsString('--ui-motion-sidebar: 280ms', $styles);
        $this->assertStringContainsString('--ui-motion-sidebar-fade: 160ms', $styles);
        $this->assertStringContainsString('--ui-motion-sidebar-icon: 140ms', $styles);
        $this->assertStringContainsString('--ui-motion-sidebar-text: 180ms', $styles);
        $this->assertStringContainsString('--ui-easing-sidebar: cubic-bezier(.22, .61, .36, 1)', $styles);
        $this->assertStringContainsString('--ui-sidebar-motion-duration: 0ms', $styles);
        $this->assertStringContainsString('--ui-sidebar-motion-text-duration: 0ms', $styles);
        $this->assertStringContainsString('@media (max-width: 991.98px)', $styles);
        $this->assertStringContainsString('.sidebar-control', $styles);
        $this->assertStringContainsString('min-height: 44px', $styles);
        $this->assertStringContainsString('width: 100% !important', $styles);
        $this->assertStringContainsString('.sidebar-toggle-label', $styles);
        $this->assertStringContainsString('height: 2.625rem', $styles);
        $this->assertStringContainsString('.sidebar-heading::after', $styles);
        $this->assertStringContainsString('.sidebar-type-text', $styles);
        $this->assertStringContainsString('clip-path var(--ui-sidebar-motion-text-duration) steps(var(--sidebar-type-steps), end)', $styles);
        $this->assertStringContainsString('clip-path: inset(0 100% 0 0)', $styles);
        $this->assertStringContainsString('transition-delay: 0s, var(--ui-sidebar-motion-text-duration)', $styles);
        $this->assertStringContainsString('inset-inline-start: calc(var(--sidebar-rail-center)', $styles);
        $this->assertStringContainsString('opacity var(--ui-sidebar-motion-icon-duration)', $styles);
        $this->assertStringContainsString('pointer-events: none', $styles);
        $this->assertStringNotContainsString("html[data-sidebar-collapsed=\"true\"] .sidebar .sidebar-link-label {\n        display: none;", $styles);
        $this->assertStringNotContainsString("html[data-sidebar-collapsed=\"true\"] .sidebar .sidebar-toggle-label {\n        display: none;", $styles);
        $this->assertStringNotContainsString('.sidebar.collapsed', $styles);
        $this->assertStringNotContainsString('.main-wrapper.expanded', $styles);
        $this->assertStringNotContainsString('font-size var(--ui-sidebar-motion', $styles);
        $this->assertStringNotContainsString('gap var(--ui-sidebar-motion-duration)', $styles);
        $this->assertStringNotContainsString('padding var(--ui-sidebar-motion-duration)', $styles);
        $this->assertStringNotContainsString('margin-inline var(--ui-sidebar-motion-duration)', $styles);
        $this->assertDoesNotMatchRegularExpression('/\.sidebar-type-text\s*\{[^}]*opacity:/s', $styles);
        $this->assertDoesNotMatchRegularExpression('/\.sidebar-type-text\s*\{[^}]*transform:/s', $styles);
        $this->assertStringNotContainsString('@keyframes sidebar-type', $styles);
        $this->assertStringContainsString('.sidebar-toggle--mobile', $styles);
        $this->assertStringContainsString('.sidebar-nav-tooltip', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('html[data-sidebar-collapsed="true"]', $styles);
    }
}

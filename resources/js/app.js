import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('adasiShell', () => ({
    desktopCollapsed: false,
    mobileOpen: false,
    viewportIsDesktop: false,
    sidebarReturnFocus: null,

    init() {
        this.syncViewport();
        window.addEventListener('resize', () => this.syncViewport(), { passive: true });
    },

    syncViewport() {
        this.viewportIsDesktop = window.innerWidth > 992;

        if (this.viewportIsDesktop) {
            this.mobileOpen = false;
            this.desktopCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        } else {
            this.desktopCollapsed = false;
        }
    },

    toggleSidebar() {
        if (this.viewportIsDesktop) {
            this.desktopCollapsed = !this.desktopCollapsed;
            localStorage.setItem('sidebarCollapsed', String(this.desktopCollapsed));
            return;
        }

        this.mobileOpen = !this.mobileOpen;

        if (this.mobileOpen) {
            this.sidebarReturnFocus = document.activeElement;
            this.$nextTick(() => document.querySelector('#sidebar a[href]')?.focus());
        } else {
            this.$nextTick(() => this.sidebarReturnFocus?.focus?.());
        }
    },

    closeMobileSidebar() {
        if (!this.viewportIsDesktop && this.mobileOpen) {
            this.mobileOpen = false;
            this.$nextTick(() => this.sidebarReturnFocus?.focus?.());
        }
    },
}));

Alpine.start();

<aside
    class="sidebar"
    id="sidebar"
    x-bind:class="{ 'collapsed': desktopCollapsed, 'show': mobileOpen }"
    x-bind:data-state="viewportIsDesktop ? (desktopCollapsed ? 'collapsed' : 'expanded') : (mobileOpen ? 'open' : 'closed')"
    x-bind:aria-hidden="viewportIsDesktop ? 'false' : (!mobileOpen).toString()"
    x-bind:inert="!viewportIsDesktop && !mobileOpen"
    x-on:click="if (!viewportIsDesktop && $event.target.closest('a[href]')) closeMobileSidebar(false)"
    aria-label="Primary navigation"
>
    <div class="sidebar-brand">
        <a href="{{ $dashboardUrl }}" class="sidebar-brand-link" aria-label="ADASI Supplier Portal — Dashboard">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" class="sidebar-brand-logo">
            <span class="brand-text sidebar-type-text" style="--sidebar-type-steps: 15;">
                <strong>ADASI</strong>
                <small>Supplier Portal</small>
            </span>
        </a>
    </div>

    <div class="sidebar-control" aria-label="Sidebar controls">
        <x-ui.icon-button
            icon="panel-left"
            label="Toggle sidebar"
            size="lg"
            class="sidebar-toggle sidebar-toggle--desktop tw-text-on-surface-variant"
            x-on:click="$dispatch('ui-sidebar-toggle', { trigger: $el })"
            x-bind:aria-label="sidebarToggleLabel"
            x-bind:title="sidebarToggleLabel"
            x-bind:aria-expanded="(!desktopCollapsed).toString()"
            aria-controls="sidebar"
        >
            <x-slot:visual>
                <span class="sidebar-toggle-icons" x-bind:class="sidebarIsExpanded ? 'is-expanded' : 'is-collapsed'" aria-hidden="true">
                    <span class="sidebar-toggle-icon sidebar-toggle-icon--collapse">
                        <x-ui.icon name="panel-left-close" size="md" />
                    </span>
                    <span class="sidebar-toggle-icon sidebar-toggle-icon--expand">
                        <x-ui.icon name="panel-left-open" size="md" />
                    </span>
                </span>
                <span class="sidebar-toggle-label sidebar-type-text" style="--sidebar-type-steps: 16;">Collapse sidebar</span>
            </x-slot:visual>
        </x-ui.icon-button>
    </div>

    <nav class="sidebar-menu" aria-label="{{ ucfirst(auth()->user()->role) }} navigation">
        @php $role = auth()->user()->role; @endphp

        @if($role === 'purchasing')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">Overview</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" icon="gauge" :active="request()->routeIs('purchasing.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 11;">Procurement</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.periods.index')" icon="calendar-days" :active="request()->routeIs('purchasing.periods.*')" label="Period Management">Period Management</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.requisitions.index')" icon="clipboard-list" :active="request()->routeIs('purchasing.requisitions.*')" label="Purchase Requisition">Purchase Requisition</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.quotations.index')" icon="tags" :active="request()->routeIs('purchasing.quotations.*')" label="Quotation">Quotation</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.comparison.inter-supplier')" icon="chart-no-axes-combined" :active="request()->routeIs('purchasing.comparison.*')" label="Price Comparison">Price Comparison</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.purchase-orders.index')" icon="receipt" :active="request()->routeIs('purchasing.purchase-orders.*')" label="Purchase Order">Purchase Order</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.shipments.index')" icon="truck" :active="request()->routeIs('purchasing.shipments.*')" label="Shipments and Logistics">Shipments &amp; Logistics</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 13;">Collaboration</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.conversations.index')" icon="message-circle-more" :active="request()->routeIs('purchasing.conversations.*')" label="Negotiation and Chat">
                Negotiation &amp; Chat
                <x-slot:trailing>
                    <span class="chat-badge tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground {{ $initChatCount > 0 ? '' : 'd-none' }}" aria-label="Unread conversations: {{ $initChatCount }}">{{ $initChatCount }}</span>
                </x-slot:trailing>
            </x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.claims.index')" icon="shield-alert" :active="request()->routeIs('purchasing.claims.*')" label="Material Claim">Material Claim</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 9;">Reporting</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.reports.index')" icon="file-chart-column" :active="request()->routeIs('purchasing.reports.*')" label="Report">Report</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" label="Export History">Export History</x-ui.sidebar-item>

        @elseif($role === 'supplier')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">Overview</span></div>
            <x-ui.sidebar-item :href="route('supplier.dashboard')" icon="gauge" :active="request()->routeIs('supplier.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">Business</span></div>
            <x-ui.sidebar-item :href="route('supplier.quotations.index')" icon="calendar-days" :active="request()->routeIs('supplier.quotations.*')" label="Quotation Period">Quotation Period</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.purchase-orders.index')" icon="receipt" :active="request()->routeIs('supplier.purchase-orders.*')" label="Purchase Order">Purchase Order</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.shipments.index')" icon="truck" :active="request()->routeIs('supplier.shipments.*')" label="Shipments and Deliveries">Shipments &amp; Deliveries</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.conversations.index')" icon="message-circle-more" :active="request()->routeIs('supplier.conversations.*')" label="Negotiation and Chat">
                Negotiation &amp; Chat
                <x-slot:trailing>
                    <span class="chat-badge tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground {{ $initChatCount > 0 ? '' : 'd-none' }}" aria-label="Unread conversations: {{ $initChatCount }}">{{ $initChatCount }}</span>
                </x-slot:trailing>
            </x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.claims.index')" icon="shield-alert" :active="request()->routeIs('supplier.claims.*')" label="Material Claim">Material Claim</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.price-history.index')" icon="trending-up" :active="request()->routeIs('supplier.price-history.*')" label="Price History">Price History</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" label="Export History">Export History</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 11;">Information</span></div>
            <x-ui.sidebar-item :href="route('supplier.announcements.index')" icon="info" :active="request()->routeIs('supplier.announcements.*')" label="ADASI Information">ADASI Information</x-ui.sidebar-item>

        @elseif($role === 'qc')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">Overview</span></div>
            <x-ui.sidebar-item :href="route('qc.dashboard')" icon="gauge" :active="request()->routeIs('qc.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 15;">Quality Control</span></div>
            <x-ui.sidebar-item :href="route('qc.inspections.index')" icon="clipboard-check" :active="request()->routeIs('qc.inspections.*')" label="QC Inspection">QC Inspection</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" label="Export History">Export History</x-ui.sidebar-item>

        @elseif($role === 'admin')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">Overview</span></div>
            <x-ui.sidebar-item :href="route('admin.dashboard')" icon="gauge" :active="request()->routeIs('admin.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">Administration</span></div>
            <x-ui.sidebar-item :href="route('admin.users.index')" icon="users" :active="request()->routeIs('admin.users.*')" label="Users">Users</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.exchange-rates.index')" icon="badge-dollar-sign" :active="request()->routeIs('admin.exchange-rates.*')" label="Exchange Rates">Exchange Rates</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.material-hs-code.index')" icon="boxes" :active="request()->routeIs('admin.material-hs-code.*', 'admin.material-masters.*', 'admin.hs-code-rules.*', 'admin.master-data-quality.*')" label="Materials and HS Code">Materials and HS Code</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.auth-audit-logs.index')" icon="shield-check" :active="request()->routeIs('admin.auth-audit-logs.*')" label="Authentication Audit">Authentication Audit</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 7;">Content</span></div>
            <x-ui.sidebar-item :href="route('admin.announcements.index')" icon="megaphone" :active="request()->routeIs('admin.announcements.*')" label="Announcements">Announcements</x-ui.sidebar-item>
        @endif
    </nav>
</aside>

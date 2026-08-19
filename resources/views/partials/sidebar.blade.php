<aside
    class="sidebar"
    id="sidebar"
    x-bind:class="{ 'collapsed': desktopCollapsed, 'show': mobileOpen }"
    x-bind:aria-hidden="viewportIsDesktop ? 'false' : (!mobileOpen).toString()"
    x-bind:inert="!viewportIsDesktop && !mobileOpen"
    aria-label="Navigasi utama"
>
    <div class="sidebar-brand">
        <a href="{{ $dashboardUrl }}" class="sidebar-brand-link" aria-label="ADASI Supplier Portal — Dashboard">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" class="sidebar-brand-logo">
            <span class="brand-text">
                <strong>ADASI</strong>
                <small>Supplier Portal</small>
            </span>
        </a>
    </div>

    <nav class="sidebar-menu" aria-label="Menu {{ ucfirst(auth()->user()->role) }}">
        @php $role = auth()->user()->role; @endphp

        @if($role === 'purchasing')
            <div class="sidebar-heading">Overview</div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" icon="bi-speedometer2" :active="request()->routeIs('purchasing.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading">Procurement</div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.periods.index')" icon="bi-calendar3" :active="request()->routeIs('purchasing.periods.*')" label="Period Management">Period Management</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.requisitions.index')" icon="bi-clipboard-data" :active="request()->routeIs('purchasing.requisitions.*')" label="Purchase Requisition">Purchase Requisition</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.quotations.index')" icon="bi-tags" :active="request()->routeIs('purchasing.quotations.*')" label="Quotation">Quotation</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.comparison.inter-supplier')" icon="bi-bar-chart-line" :active="request()->routeIs('purchasing.comparison.*')" label="Price Comparison">Price Comparison</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.purchase-orders.index')" icon="bi-receipt" :active="request()->routeIs('purchasing.purchase-orders.*')" label="Purchase Order">Purchase Order</x-ui.sidebar-item>

            <div class="sidebar-heading">Collaboration</div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.conversations.index')" icon="bi-chat-dots" :active="request()->routeIs('purchasing.conversations.*')" label="Negotiation & Chat">
                Negotiation &amp; Chat
                <x-slot:trailing>
                    <span class="chat-badge badge bg-danger rounded-pill {{ $initChatCount > 0 ? '' : 'd-none' }}">{{ $initChatCount }}</span>
                </x-slot:trailing>
            </x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.claims.index')" icon="bi-shield-exclamation" :active="request()->routeIs('purchasing.claims.*')" label="Material Claim">Material Claim</x-ui.sidebar-item>

            <div class="sidebar-heading">Reporting</div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.reports.index')" icon="bi-file-earmark-bar-graph" :active="request()->routeIs('purchasing.reports.*')" label="Report">Report</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="bi-file-earmark-spreadsheet" :active="request()->routeIs('exports.*')" label="Export Saya">Export Saya</x-ui.sidebar-item>

        @elseif($role === 'supplier')
            <div class="sidebar-heading">Overview</div>
            <x-ui.sidebar-item :href="route('supplier.dashboard')" icon="bi-speedometer2" :active="request()->routeIs('supplier.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading">Business</div>
            <x-ui.sidebar-item :href="route('supplier.quotations.index')" icon="bi-calendar-event" :active="request()->routeIs('supplier.quotations.*')" label="Quotation Period">Quotation Period</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.purchase-orders.index')" icon="bi-receipt" :active="request()->routeIs('supplier.purchase-orders.*')" label="Purchase Order">Purchase Order</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.conversations.index')" icon="bi-chat-dots" :active="request()->routeIs('supplier.conversations.*')" label="Negotiation & Chat">
                Negotiation &amp; Chat
                <x-slot:trailing>
                    <span class="chat-badge badge bg-danger rounded-pill {{ $initChatCount > 0 ? '' : 'd-none' }}">{{ $initChatCount }}</span>
                </x-slot:trailing>
            </x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.claims.index')" icon="bi-shield-exclamation" :active="request()->routeIs('supplier.claims.*')" label="Material Claim">Material Claim</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier.price-history.index')" icon="bi-graph-up-arrow" :active="request()->routeIs('supplier.price-history.*')" label="Price History">Price History</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="bi-file-earmark-spreadsheet" :active="request()->routeIs('exports.*')" label="Export Saya">Export Saya</x-ui.sidebar-item>

            <div class="sidebar-heading">Information</div>
            <x-ui.sidebar-item :href="route('supplier.announcements.index')" icon="bi-info-circle" :active="request()->routeIs('supplier.announcements.*')" label="ADASI Information">ADASI Information</x-ui.sidebar-item>

        @elseif($role === 'qc')
            <div class="sidebar-heading">Overview</div>
            <x-ui.sidebar-item :href="route('qc.dashboard')" icon="bi-speedometer2" :active="request()->routeIs('qc.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading">Quality Control</div>
            <x-ui.sidebar-item :href="route('qc.inspections.index')" icon="bi-clipboard-check" :active="request()->routeIs('qc.inspections.*')" label="QC Inspection">QC Inspection</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="bi-file-earmark-spreadsheet" :active="request()->routeIs('exports.*')" label="Export Saya">Export Saya</x-ui.sidebar-item>

        @elseif($role === 'admin')
            <div class="sidebar-heading">Overview</div>
            <x-ui.sidebar-item :href="route('admin.dashboard')" icon="bi-speedometer2" :active="request()->routeIs('admin.dashboard')" label="Dashboard">Dashboard</x-ui.sidebar-item>

            <div class="sidebar-heading">Administration</div>
            <x-ui.sidebar-item :href="route('admin.users.index')" icon="bi-people" :active="request()->routeIs('admin.users.*')" label="User Management">User Management</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.exchange-rates.index')" icon="bi-currency-exchange" :active="request()->routeIs('admin.exchange-rates.*')" label="Exchange Rate Management">Exchange Rate Management</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.material-hs-code.index')" icon="bi-boxes" :active="request()->routeIs('admin.material-hs-code.*', 'admin.material-masters.*', 'admin.hs-code-rules.*', 'admin.master-data-quality.*')" label="Master Material & HS Code">Master Material &amp; HS Code</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.auth-audit-logs.index')" icon="bi-shield-check" :active="request()->routeIs('admin.auth-audit-logs.*')" label="Authentication Audit">Authentication Audit</x-ui.sidebar-item>

            <div class="sidebar-heading">Content</div>
            <x-ui.sidebar-item :href="route('admin.announcements.index')" icon="bi-megaphone" :active="request()->routeIs('admin.announcements.*')" label="Announcement">Announcement</x-ui.sidebar-item>
        @endif
    </nav>
</aside>

<aside
    class="sidebar"
    id="sidebar"
    x-bind:class="{ 'collapsed': desktopCollapsed, 'show': mobileOpen }"
    x-bind:data-state="viewportIsDesktop ? (desktopCollapsed ? 'collapsed' : 'expanded') : (mobileOpen ? 'open' : 'closed')"
    x-bind:aria-hidden="viewportIsDesktop ? 'false' : (!mobileOpen).toString()"
    x-bind:inert="!viewportIsDesktop && !mobileOpen"
    x-on:click="if (!viewportIsDesktop && $event.target.closest('a[href]')) closeMobileSidebar(false)"
    aria-label="{{ __('navigation.primary') }}"
>
    <div class="sidebar-brand">
        <a href="{{ $dashboardUrl }}" class="sidebar-brand-link" aria-label="{{ __('navigation.portal_dashboard') }}">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" class="sidebar-brand-logo" draggable="false">
            <span class="brand-text sidebar-type-text" style="--sidebar-type-steps: 15;">
                <strong>ADASI</strong>
                <small>{{ __('common.review.supplier_portal') }}</small>
            </span>
        </a>
    </div>

    <div class="sidebar-control" aria-label="{{ __('navigation.sidebar_controls') }}">
        <x-ui.icon-button
            icon="panel-left"
            :label="__('navigation.toggle_sidebar')"
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
                <span class="sidebar-toggle-label sidebar-type-text" style="--sidebar-type-steps: 16;">{{ __('navigation.collapse_sidebar') }}</span>
            </x-slot:visual>
        </x-ui.icon-button>
    </div>

    @if(\App\Support\PortalContext::isDualScope())
        @php
            $currentScope = \App\Support\PortalContext::current();
            $currentLabel = \App\Support\PortalContext::label($currentScope);
            $currentIcon = $currentScope === 'local' ? 'receipt-text' : 'globe';
            $typingSteps = max(6, min(24, mb_strlen((string) ($currentLabel ?: __('navigation.portal')))));
        @endphp
        <div class="sidebar-portal-switcher dropdown">
            <button
                class="btn sidebar-portal-switcher-btn dropdown-toggle ui-focus-ring"
                type="button"
                id="portalContextSwitcherDropdown"
                data-bs-toggle="dropdown"
                data-bs-auto-close="true"
                aria-expanded="false"
                data-sidebar-tooltip
                data-bs-title="{{ $currentLabel ?: __('navigation.switch_portal') }}"
                title="{{ $currentLabel ?: __('navigation.switch_portal') }}"
                aria-label="{{ __('navigation.current_portal', ['portal' => $currentLabel ?: __('navigation.portal')]) }}"
            >
                <span class="sidebar-portal-switcher-icon" aria-hidden="true">
                    <span class="tw-w-6 tw-h-6 tw-rounded tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                        <x-ui.icon :name="$currentIcon" size="sm" />
                    </span>
                </span>
                <span class="sidebar-portal-switcher-label sidebar-type-text" style="--sidebar-type-steps: {{ $typingSteps }};">{{ $currentLabel ?: __('navigation.portal') }}</span>
                <span class="sidebar-portal-switcher-chevron" aria-hidden="true">
                    <x-ui.icon name="chevron-down" size="xs" />
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-start tw-shadow-xl tw-border tw-border-outline-variant tw-rounded-xl tw-p-1.5 tw-min-w-[270px] tw-bg-surface tw-animate-in tw-fade-in-0 tw-zoom-in-95" aria-labelledby="portalContextSwitcherDropdown">
                <div class="tw-px-2.5 tw-pt-1.5 tw-pb-2 tw-flex tw-items-center tw-justify-between">
                    <span class="tw-text-[10px] tw-font-bold tw-text-on-surface-variant tw-uppercase tw-tracking-wider">{{ __('navigation.choose_portal') }}</span>
                    <span class="tw-text-[10px] tw-text-on-surface-variant/70 tw-font-mono">{{ __('navigation.dual_scope') }}</span>
                </div>
                <div class="tw-space-y-1">
                    {{-- Local Supplier Option --}}
                    <form method="POST" action="{{ route('supplier-context.store') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="context" value="local">
                        <button
                            type="submit"
                            class="dropdown-item tw-w-full tw-flex tw-items-center tw-justify-between tw-gap-3 tw-p-2.5 tw-rounded-lg tw-transition-colors tw-text-start {{ $currentScope === 'local' ? 'tw-bg-primary/10 tw-border tw-border-primary/20' : 'tw-bg-transparent hover:tw-bg-surface-container tw-border tw-border-transparent' }}"
                        >
                            <div class="tw-flex tw-items-center tw-gap-3">
                                <span class="tw-w-8 tw-h-8 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-shrink-0 {{ $currentScope === 'local' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-bg-surface-container-high tw-text-on-surface-variant' }}">
                                    <x-ui.icon name="receipt-text" size="sm" />
                                </span>
                                <div class="tw-flex tw-flex-col">
                                    <span class="tw-font-semibold tw-text-ui-sm {{ $currentScope === 'local' ? 'tw-text-primary' : 'tw-text-on-surface' }}">{{ __('navigation.local_supplier') }}</span>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('navigation.local_summary') }}</span>
                                </div>
                            </div>
                            @if($currentScope === 'local')
                                <span class="tw-w-5 tw-h-5 tw-rounded-full tw-bg-primary tw-text-on-primary tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-shadow-xs">
                                    <x-ui.icon name="check" size="xs" />
                                </span>
                            @endif
                        </button>
                    </form>

                    {{-- Material Procurement Option --}}
                    <form method="POST" action="{{ route('supplier-context.store') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="context" value="import">
                        <button
                            type="submit"
                            class="dropdown-item tw-w-full tw-flex tw-items-center tw-justify-between tw-gap-3 tw-p-2.5 tw-rounded-lg tw-transition-colors tw-text-start {{ $currentScope === 'import' ? 'tw-bg-primary/10 tw-border tw-border-primary/20' : 'tw-bg-transparent hover:tw-bg-surface-container tw-border tw-border-transparent' }}"
                        >
                            <div class="tw-flex tw-items-center tw-gap-3">
                                <span class="tw-w-8 tw-h-8 tw-rounded-lg tw-flex tw-items-center tw-justify-center tw-shrink-0 {{ $currentScope === 'import' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-bg-surface-container-high tw-text-on-surface-variant' }}">
                                    <x-ui.icon name="globe" size="sm" />
                                </span>
                                <div class="tw-flex tw-flex-col">
                                    <span class="tw-font-semibold tw-text-ui-sm {{ $currentScope === 'import' ? 'tw-text-primary' : 'tw-text-on-surface' }}">{{ __('navigation.material_procurement') }}</span>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('navigation.import_summary') }}</span>
                                </div>
                            </div>
                            @if($currentScope === 'import')
                                <span class="tw-w-5 tw-h-5 tw-rounded-full tw-bg-primary tw-text-on-primary tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-shadow-xs">
                                    <x-ui.icon name="check" size="xs" />
                                </span>
                            @endif
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <nav class="sidebar-menu" aria-label="{{ __('navigation.role_navigation', ['role' => __('navigation.roles.'.auth()->user()->role)]) }}">
        @php $role = auth()->user()->role; @endphp
        @if(!empty($quickAccessItems))
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 12;">{{ __('navigation.quick_access') }}</span></div>
            @foreach($quickAccessItems as $quickAccessItem)
                <x-ui.sidebar-item
                    :href="$quickAccessItem['url']"
                    :icon="$quickAccessItem['icon']"
                    :active="$quickAccessItem['active']"
                    :label="$quickAccessItem['label']"
                    data-quick-access
                >{{ $quickAccessItem['label'] }}</x-ui.sidebar-item>
            @endforeach
        @endif
        @if($role === 'supplier')
            @php $activePortalScope = \App\Support\PortalContext::current(); @endphp
            @if($activePortalScope === \App\Support\PortalContext::SCOPE_LOCAL)
                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 13;">{{ __('navigation.overview') }}</span></div>
                <x-ui.sidebar-item :href="route('local-supplier.dashboard')" icon="gauge" :active="request()->routeIs('local-supplier.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>
                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 13;">{{ __('navigation.local_invoice') }}</span></div>
                <x-ui.sidebar-item :href="route('local-supplier.invoices.create')" icon="file-plus" :active="request()->routeIs('local-supplier.invoices.create')" :label="__('navigation.submit_invoice')">{{ __('navigation.submit_invoice') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('local-supplier.invoices.index')" icon="receipt" :active="request()->routeIs('local-supplier.invoices.index', 'local-supplier.invoices.show', 'local-supplier.invoices.revision')" :label="__('navigation.invoice_register')">{{ __('navigation.invoice_register') }}</x-ui.sidebar-item>
                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.supplier_information') }}</span></div>
                <x-ui.sidebar-item :href="route('local-supplier.purchase-orders.index')" icon="package-check" :active="request()->routeIs('local-supplier.purchase-orders.*')" :label="__('navigation.purchase_orders')">{{ __('navigation.purchase_orders') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('local-supplier.vendor-profile.show')" icon="building-2" :active="request()->routeIs('local-supplier.vendor-profile.*')" :label="__('navigation.vendor_profile')">{{ __('navigation.vendor_profile') }}</x-ui.sidebar-item>
                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.adasi_information') }}</span></div>
                <x-ui.sidebar-item :href="route('local-supplier.information')" icon="info" :active="request()->routeIs('local-supplier.information')" :label="__('navigation.adasi_information')">{{ __('navigation.announcements') }}</x-ui.sidebar-item>
            @elseif($activePortalScope === \App\Support\PortalContext::SCOPE_IMPORT)
                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">{{ __('navigation.overview') }}</span></div>
                <x-ui.sidebar-item :href="route('supplier.dashboard')" icon="gauge" :active="request()->routeIs('supplier.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>

                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">{{ __('navigation.business') }}</span></div>
                <x-ui.sidebar-item :href="route('supplier.quotations.index')" icon="calendar-days" :active="request()->routeIs('supplier.quotations.*')" :label="__('navigation.quotation_period')">{{ __('navigation.quotation_period') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('supplier.purchase-orders.index')" icon="receipt" :active="request()->routeIs('supplier.purchase-orders.*')" :label="__('navigation.purchase_order')">{{ __('navigation.purchase_order') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('supplier.shipments.index')" icon="truck" :active="request()->routeIs('supplier.shipments.*')" :label="__('navigation.shipments_deliveries')">{{ __('navigation.shipments_deliveries_short') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('supplier.conversations.index')" icon="message-circle-more" :active="request()->routeIs('supplier.conversations.*')" :label="__('navigation.chat_title')">
                    {{ __('common.chat.title') }}
                    <x-slot:trailing>
                        <span class="chat-badge tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground {{ $initChatCount > 0 ? '' : 'd-none' }}" aria-label="{{ __('navigation.unread_chats', ['count' => $initChatCount]) }}">{{ $initChatCount }}</span>
                    </x-slot:trailing>
                </x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('supplier.claims.index')" icon="shield-alert" :active="request()->routeIs('supplier.claims.*')" :label="__('navigation.material_claim')">{{ __('navigation.material_claim') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('supplier.price-history.index')" icon="trending-up" :active="request()->routeIs('supplier.price-history.*')" :label="__('navigation.price_history')">{{ __('navigation.price_history') }}</x-ui.sidebar-item>
                <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" :label="__('navigation.export_history')">{{ __('navigation.export_history') }}</x-ui.sidebar-item>

                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 11;">{{ __('common.information') }}</span></div>
                <x-ui.sidebar-item :href="route('supplier.announcements.index')" icon="info" :active="request()->routeIs('supplier.announcements.*')" :label="__('navigation.adasi_information')">{{ __('navigation.adasi_information') }}</x-ui.sidebar-item>
            @else
                <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 6;">{{ __('navigation.portal') }}</span></div>
                <div class="px-3 py-3 tw-text-ui-xs tw-text-on-surface-variant d-flex flex-column align-items-center text-center tw-gap-2">
                    <x-ui.icon name="info" size="sm" class="tw-text-on-surface-variant tw-shrink-0" />
                    <span>{{ __('navigation.choose_first') }}</span>
                </div>
            @endif
        @elseif($role === 'finance')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.overview') }}</span></div>
            <x-ui.sidebar-item :href="route('finance.dashboard')" icon="gauge" :active="request()->routeIs('finance.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.finance') }}</span></div>
            <x-ui.sidebar-item :href="route('finance.invoices.index')" icon="receipt" :active="request()->routeIs('finance.invoices.*')" :label="__('navigation.invoice_register')">{{ __('navigation.invoice_register') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.ga-claims.index')" icon="file-text" :active="request()->routeIs('finance.ga-claims.*')" :label="__('navigation.ga_claims')">{{ __('navigation.ga_claims') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.drp.supplier')" icon="wallet" :active="request()->routeIs('finance.drp.supplier', 'finance.drp.show')" :label="__('navigation.drp_supplier')">{{ __('navigation.drp_supplier') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.drp.ga')" icon="credit-card" :active="request()->routeIs('finance.drp.ga')" :label="__('navigation.drp_ga')">{{ __('navigation.drp_ga') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.drp.paid.index')" icon="badge-check" :active="request()->routeIs('finance.drp.paid.*')" :label="__('navigation.drp_paid')">{{ __('navigation.drp_paid') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.overpayments.index')" icon="circle-dollar-sign" :active="request()->routeIs('finance.overpayments.*')" :label="__('navigation.supplier_overpayment')">{{ __('navigation.supplier_overpayment') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier-registrations.index')" icon="user-plus" :active="request()->routeIs('supplier-registrations.*')" :label="__('navigation.supplier_registrations')">{{ __('navigation.supplier_registrations') }}</x-ui.sidebar-item>
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.master_data') }}</span></div>
            <x-ui.sidebar-item :href="route('finance.local-procurement.index')" icon="package-check" :active="request()->routeIs('finance.local-procurement.*')" :label="__('navigation.local_po_gr')">{{ __('navigation.master_po_gr') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.master-invoices')" icon="database" :active="request()->routeIs('finance.master-invoices')" :label="__('navigation.master_invoices')">{{ __('navigation.master_invoices') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('finance.vendor-master.index')" icon="building-2" :active="request()->routeIs('finance.vendor-master.*')" :label="__('navigation.master_vendor')">{{ __('navigation.master_vendor') }}</x-ui.sidebar-item>
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.reporting') }}</span></div>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" :label="__('navigation.export_history')">{{ __('navigation.export_history') }}</x-ui.sidebar-item>
        @elseif($role === 'ga')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 16;">{{ __('navigation.general_affairs') }}</span></div>
            <x-ui.sidebar-item :href="route('ga.dashboard')" icon="gauge" :active="request()->routeIs('ga.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('ga.claims.index')" icon="receipt" :active="request()->routeIs('ga.claims.index', 'ga.claims.show', 'ga.claims.receipt')" :label="__('navigation.ga_claims')">{{ __('navigation.ga_claims') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('ga.claims.create')" icon="file-plus" :active="request()->routeIs('ga.claims.create')" :label="__('navigation.submit_claim')">{{ __('navigation.submit_claim') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('ga.employees.index')" icon="users" :active="request()->routeIs('ga.employees.*')" :label="__('navigation.employee_master')">{{ __('navigation.employee_master') }}</x-ui.sidebar-item>
        @elseif(auth()->user()->isLocalOperator())
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 21;">{{ __('navigation.invoice_control') }}</span></div>
            <x-ui.sidebar-item :href="route('accounting.dashboard')" icon="gauge" :active="request()->routeIs('accounting.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('accounting.invoices.index')" icon="receipt" :active="request()->routeIs('accounting.invoices.*')" :label="__('navigation.invoice_register')">{{ __('navigation.invoice_register') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('accounting.physical-verification')" icon="clipboard-check" :active="request()->routeIs('accounting.physical-verification')" :label="__('navigation.physical_verification')">{{ __('navigation.physical_verification') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('accounting.payment-schedule')" icon="calendar-days" :active="request()->routeIs('accounting.payment-schedule')" :label="__('navigation.payment_schedule')">{{ __('navigation.payment_schedule') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('accounting.reports')" icon="file-chart-column" :active="request()->routeIs('accounting.reports*')" :label="__('navigation.reports')">{{ __('navigation.reports') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" :label="__('navigation.export_history')">{{ __('navigation.export_history') }}</x-ui.sidebar-item>
        @elseif($role === 'purchasing')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">{{ __('navigation.overview') }}</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" icon="gauge" :active="request()->routeIs('purchasing.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 11;">{{ __('navigation.procurement') }}</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.periods.index')" icon="calendar-days" :active="request()->routeIs('purchasing.periods.*')" :label="__('navigation.period_management')">{{ __('navigation.period_management') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.requisitions.index')" icon="clipboard-list" :active="request()->routeIs('purchasing.requisitions.*')" :label="__('navigation.requisition')">{{ __('navigation.requisition') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier-registrations.index')" icon="user-plus" :active="request()->routeIs('supplier-registrations.*')" :label="__('navigation.supplier_registrations')">{{ __('navigation.supplier_registrations') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.quotations.index')" icon="tags" :active="request()->routeIs('purchasing.quotations.*')" :label="__('navigation.quotation')">{{ __('navigation.quotation') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.comparison.inter-supplier')" icon="chart-no-axes-combined" :active="request()->routeIs('purchasing.comparison.*')" :label="__('navigation.price_comparison')">{{ __('navigation.price_comparison') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.purchase-orders.index')" icon="receipt" :active="request()->routeIs('purchasing.purchase-orders.*')" :label="__('navigation.purchase_order')">{{ __('navigation.purchase_order') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.shipments.index')" icon="truck" :active="request()->routeIs('purchasing.shipments.*')" :label="__('navigation.shipment_logistics')">{{ __('navigation.shipment_logistics_short') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 13;">{{ __('navigation.local_invoice') }}</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.local-vendors.index')" icon="building-2" :active="request()->routeIs('purchasing.local-vendors.*')" :label="__('navigation.local_vendors')">{{ __('navigation.local_vendors') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.local-procurement.index')" icon="package-check" :active="request()->routeIs('purchasing.local-procurement.*')" :label="__('navigation.local_po_gr')">{{ __('navigation.local_po_gr') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.supplier')" icon="wallet" :active="request()->routeIs('purchasing.drp.supplier', 'purchasing.drp.show')" :label="__('navigation.drp_supplier')">{{ __('navigation.drp_supplier') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.ga')" icon="credit-card" :active="request()->routeIs('purchasing.drp.ga')" :label="__('navigation.drp_ga')">{{ __('navigation.drp_ga') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.paid.index')" icon="badge-check" :active="request()->routeIs('purchasing.drp.paid.*')" :label="__('navigation.drp_paid')">{{ __('navigation.drp_paid') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 13;">{{ __('navigation.collaboration') }}</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.conversations.index')" icon="message-circle-more" :active="request()->routeIs('purchasing.conversations.*')" :label="__('navigation.chat_title')">
                {{ __('common.chat.title') }}
                <x-slot:trailing>
                    <span class="chat-badge tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground {{ $initChatCount > 0 ? '' : 'd-none' }}" aria-label="{{ __('navigation.unread_chats', ['count' => $initChatCount]) }}">{{ $initChatCount }}</span>
                </x-slot:trailing>
            </x-ui.sidebar-item>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.claims.index')" icon="shield-alert" :active="request()->routeIs('purchasing.claims.*')" :label="__('navigation.material_claim')">{{ __('navigation.material_claim') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 9;">{{ __('navigation.reporting') }}</span></div>
            <x-ui.sidebar-item :href="\App\Support\PurchasingNavigation::listUrl('purchasing.reports.index')" icon="file-chart-column" :active="request()->routeIs('purchasing.reports.*')" :label="__('navigation.report')">{{ __('navigation.report') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" :label="__('navigation.export_history')">{{ __('navigation.export_history') }}</x-ui.sidebar-item>
        @elseif($role === 'qc')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">{{ __('navigation.overview') }}</span></div>
            <x-ui.sidebar-item :href="route('qc.dashboard')" icon="gauge" :active="request()->routeIs('qc.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 15;">{{ __('navigation.quality_control') }}</span></div>
            <x-ui.sidebar-item :href="route('qc.inspections.index')" icon="clipboard-check" :active="request()->routeIs('qc.inspections.*')" :label="__('navigation.qc_inspection')">{{ __('navigation.qc_inspection') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('exports.index')" icon="file-spreadsheet" :active="request()->routeIs('exports.*')" :label="__('navigation.export_history')">{{ __('navigation.export_history') }}</x-ui.sidebar-item>

        @elseif($role === 'admin')
            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 8;">{{ __('navigation.overview') }}</span></div>
            <x-ui.sidebar-item :href="route('admin.dashboard')" icon="gauge" :active="request()->routeIs('admin.dashboard')" :label="__('navigation.dashboard')">{{ __('navigation.dashboard') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 14;">{{ __('navigation.administration') }}</span></div>
            <x-ui.sidebar-item :href="route('admin.users.index')" icon="users" :active="request()->routeIs('admin.users.*')" :label="__('navigation.users')">{{ __('navigation.users') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('supplier-registrations.index')" icon="user-plus" :active="request()->routeIs('supplier-registrations.*')" :label="__('navigation.supplier_registrations')">{{ __('navigation.supplier_registrations') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.exchange-rates.index')" icon="badge-dollar-sign" :active="request()->routeIs('admin.exchange-rates.*')" :label="__('navigation.exchange_rates')">{{ __('navigation.exchange_rates') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.material-hs-code.index')" icon="boxes" :active="request()->routeIs('admin.material-hs-code.*', 'admin.material-masters.*', 'admin.hs-code-rules.*', 'admin.master-data-quality.*')" :label="__('navigation.materials')">{{ __('navigation.materials') }}</x-ui.sidebar-item>
            <x-ui.sidebar-item :href="route('admin.auth-audit-logs.index')" icon="shield-check" :active="request()->routeIs('admin.auth-audit-logs.*')" :label="__('navigation.auth_audit')">{{ __('navigation.auth_audit') }}</x-ui.sidebar-item>

            <div class="sidebar-heading"><span class="sidebar-heading-label sidebar-type-text" style="--sidebar-type-steps: 7;">{{ __('common.fields.content') }}</span></div>
            <x-ui.sidebar-item :href="route('admin.announcements.index')" icon="megaphone" :active="request()->routeIs('admin.announcements.*')" :label="__('navigation.announcements')">{{ __('navigation.announcements') }}</x-ui.sidebar-item>
        @endif
    </nav>
</aside>

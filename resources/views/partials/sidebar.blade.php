<aside class="sidebar" id="sidebar">
    {{-- Brand --}}
    <div class="sidebar-brand">
        <div class="d-flex align-items-center gap-2">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width: 40px; height: auto;">
            <div class="brand-text">
                <h5 class="mb-0">ADASI</h5>
                <small>{{ __('Supplier Portal') }}</small>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="sidebar-menu">
        @php $role = auth()->user()->role; @endphp

        {{-- ═══════════════════════════════════════════
        PURCHASING
        ═══════════════════════════════════════════ --}}
        @if($role === 'purchasing')
            <div class="sidebar-heading">{{ __('Menu Utama') }}</div>

            <a href="{{ route('purchasing.dashboard') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> <span>{{ __('Dashboard') }}</span>
            </a>
            <a href="{{ route('purchasing.periods.index') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.periods.*') ? 'active' : '' }}">
                <i class="bi bi-calendar3"></i> <span>{{ __('Manajemen Periode') }}</span>
            </a>
            <a href="{{ route('purchasing.requirements.index') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.requirements.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-data"></i> <span>{{ __('Permintaan Material') }}</span>
            </a>
            <a href="{{ route('purchasing.quotations.index') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.quotations.*') ? 'active' : '' }}">
                <i class="bi bi-tags"></i> <span>{{ __('Penawaran') }}</span>
            </a>
            <a href="{{ route('purchasing.comparison.inter-supplier') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.comparison.*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart-line"></i> <span>{{ __('Perbandingan Harga') }}</span>
            </a>
            <a href="{{ route('purchasing.purchase-orders.index') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.purchase-orders.*') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i> <span>{{ __('Purchase Order') }}</span>
            </a>
            <a href="{{ route('purchasing.conversations.index') }}" data-chat-drawer
                class="sidebar-link {{ request()->routeIs('purchasing.conversations.*') ? 'active' : '' }}">
                <i class="bi bi-chat-dots"></i> <span>{{ __('Negosiasi & Chat') }}</span>
                <span class="chat-badge badge bg-danger rounded-pill {{ $initChatCount > 0 ? '' : 'd-none' }} ms-auto" style="font-size:0.7rem;">{{ $initChatCount }}</span>
            </a>

            <div class="sidebar-heading">{{ __('Quality & Claims') }}</div>
            <a href="{{ route('purchasing.claims.index') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.claims.*') ? 'active' : '' }}">
                <i class="bi bi-shield-exclamation"></i> <span>{{ __('Klaim Material') }}</span>
            </a>

            <div class="sidebar-heading">{{ __('Lainnya') }}</div>
            <a href="{{ route('purchasing.reports.index') }}"
                class="sidebar-link {{ request()->routeIs('purchasing.reports.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-bar-graph"></i> <span>{{ __('Laporan') }}</span>
            </a>

            {{-- ═══════════════════════════════════════════
            SUPPLIER
            ═══════════════════════════════════════════ --}}
        @elseif($role === 'supplier')
            <div class="sidebar-heading">{{ __('Menu Utama') }}</div>

            <a href="{{ route('supplier.dashboard') }}"
                class="sidebar-link {{ request()->routeIs('supplier.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> <span>{{ __('Dashboard') }}</span>
            </a>
            <a href="{{ route('supplier.quotations.index') }}"
                class="sidebar-link {{ request()->routeIs('supplier.quotations.*') ? 'active' : '' }}">
                <i class="bi bi-calendar-event"></i> <span>{{ __('Periode Penawaran') }}</span>
            </a>
            <a href="{{ route('supplier.purchase-orders.index') }}"
                class="sidebar-link {{ request()->routeIs('supplier.purchase-orders.*') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i> <span>{{ __('Purchase Order') }}</span>
            </a>
            <a href="{{ route('supplier.conversations.index') }}" data-chat-drawer
                class="sidebar-link {{ request()->routeIs('supplier.conversations.*') ? 'active' : '' }}">
                <i class="bi bi-chat-dots"></i> <span>{{ __('Negosiasi & Chat') }}</span>
                <span class="chat-badge badge bg-danger rounded-pill {{ $initChatCount > 0 ? '' : 'd-none' }} ms-auto" style="font-size:0.7rem;">{{ $initChatCount }}</span>
            </a>
            <a href="{{ route('supplier.claims.index') }}"
                class="sidebar-link {{ request()->routeIs('supplier.claims.*') ? 'active' : '' }}">
                <i class="bi bi-shield-exclamation"></i> <span>{{ __('Klaim Material') }}</span>
            </a>

            <div class="sidebar-heading">{{ __('Informasi') }}</div>
            <a href="{{ route('supplier.announcements.index') }}"
               class="sidebar-link {{ request()->routeIs('supplier.announcements.*') ? 'active' : '' }}">
                <i class="bi bi-info-circle"></i> <span>{{ __('Informasi ADASI') }}</span>
            </a>

            {{-- ═══════════════════════════════════════════
            QC (Quality Control)
            ═══════════════════════════════════════════ --}}
        @elseif($role === 'qc')
            <div class="sidebar-heading">{{ __('Menu Utama') }}</div>

            <a href="{{ route('qc.dashboard') }}"
                class="sidebar-link {{ request()->routeIs('qc.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> <span>{{ __('Dashboard') }}</span>
            </a>
            <a href="{{ route('qc.inspections.index') }}"
                class="sidebar-link {{ request()->routeIs('qc.inspections.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-check"></i> <span>{{ __('Inspeksi QC') }}</span>
            </a>

            {{-- ═══════════════════════════════════════════
            ADMIN
            ═══════════════════════════════════════════ --}}
        @elseif($role === 'admin')
            <div class="sidebar-heading">{{ __('Menu Utama') }}</div>

            <a href="{{ route('admin.dashboard') }}"
                class="sidebar-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> <span>{{ __('Dashboard') }}</span>
            </a>
            <a href="{{ route('admin.users.index') }}" 
                class="sidebar-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> <span>{{ __('Manajemen User') }}</span>
            </a>
            <a href="{{ route('admin.exchange-rates.index') }}" 
                class="sidebar-link {{ request()->routeIs('admin.exchange-rates.*') ? 'active' : '' }}">
                <i class="bi bi-currency-exchange"></i> <span>{{ __('Manajemen Kurs') }}</span>
            </a>

            <div class="sidebar-heading">{{ __('Konten') }}</div>
            <a href="{{ route('admin.announcements.index') }}"
               class="sidebar-link {{ request()->routeIs('admin.announcements.*') ? 'active' : '' }}">
                <i class="bi bi-megaphone"></i> <span>{{ __('Pengumuman') }}</span>
            </a>
        @endif
    </nav>
</aside>

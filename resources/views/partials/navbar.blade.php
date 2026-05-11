<nav class="top-navbar d-flex align-items-center justify-content-between">
    {{-- Left: Mobile toggle + Page title --}}
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-sm btn-outline-secondary sidebar-toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <h6 class="mb-0 fw-semibold text-dark">@yield('page-title', 'Dashboard')</h6>
    </div>

    {{-- Right: User info + Logout --}}
    <div class="d-flex align-items-center gap-3">
        {{-- Chat Icon (Only for Purchasing and Supplier) --}}
        @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
            <a href="{{ route(auth()->user()->role . '.conversations.index') }}" class="btn btn-sm btn-light position-relative" title="Chat & Negosiasi" data-chat-drawer>
                <i class="bi bi-chat-dots" style="font-size:1.2rem;"></i>
                <span class="chat-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger {{ $initChatCount > 0 ? '' : 'd-none' }}" style="font-size:0.6rem;">
                    {{ $initChatCount }}
                </span>
            </a>
        @endif

        {{-- Notification Icon --}}
        <a href="{{ route('notifications.index') }}" class="btn btn-sm btn-light position-relative" title="Notifikasi">
            <i class="bi bi-bell" style="font-size:1.2rem;"></i>
            <span class="notif-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger {{ $initNotifCount > 0 ? '' : 'd-none' }}" style="font-size:0.6rem;">
                {{ $initNotifCount }}
            </span>
        </a>
        {{-- Role Badge --}}
        <span class="role-badge role-badge-{{ auth()->user()->role }}">
            {{ ucfirst(auth()->user()->role) }}
        </span>

        {{-- User Dropdown --}}
        <div class="dropdown">
            <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2" type="button"
                data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle" style="font-size:1.2rem;"></i>
                <span class="d-none d-sm-inline">{{ auth()->user()->name }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <span class="dropdown-item-text small text-muted">
                        {{ auth()->user()->email }}
                    </span>
                </li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth
    <title>@yield('title', 'ADASI Supplier Portal')</title>

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">

    <!-- ADASI Alert Theme -->
    <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">

    <!-- Tailwind design foundation + Alpine entry (hybrid compatibility phase) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Custom CSS -->
    <style>
        /* Shared tokens live in resources/css/app.css and load before this compatibility layer. */

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--md-on-surface);
            overflow-x: hidden;
        }

        /* Skeleton Loading */
        .skeleton {
            background: var(--md-surface-container-high);
            background-image: linear-gradient(90deg, rgba(var(--md-surface-rgb), 0), rgba(var(--md-surface-rgb), 0.4), rgba(var(--md-surface-rgb), 0));
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite linear;
            border-radius: 4px;
            display: inline-block;
            color: transparent !important;
            min-height: 1em;
            pointer-events: none;
        }

        .skeleton * {
            visibility: hidden;
        }

        .skeleton-text {
            width: 100%;
            height: 1.1em;
            margin-bottom: 0.25rem;
        }

        .skeleton-text.short {
            width: 60%;
        }

        .skeleton-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .skeleton-button {
            width: 100px;
            height: 36px;
            border-radius: 4px;
        }

        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }

            100% {
                background-position: 200% 0;
            }
        }

        /* ── ADASI Full-Screen Loader Overlay ── */
        .adasi-loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(var(--md-background-rgb), 0.6);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            display: none;
            /* Tersembunyi secara default */
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.2s ease;
        }

        .adasi-loader-overlay.active {
            display: flex;
        }

        .adasi-loader-card {
            background: var(--md-surface);
            border-radius: 16px;
            padding: 28px 32px;
            box-shadow: var(--md-elevation-2);
            border: 1px solid rgba(var(--md-primary-rgb), 0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            animation: loaderFadeIn 0.25s ease-out;
        }

        @keyframes loaderFadeIn {
            from {
                opacity: 0;
                transform: scale(0.92);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Logo ring container */
        .adasi-loader-ring {
            width: 72px;
            height: 72px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Shimmer ring */
        .adasi-loader-ring::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3px solid var(--md-surface-container-high);
            border-top-color: var(--md-outline-strong);
            /* Silver */
            border-right-color: var(--adasi-red);
            animation: adasiSpin 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        }

        /* Logo ADASI di tengah */
        .adasi-loader-logo {
            width: 36px;
            height: 36px;
            background-image: url("data:image/png;base64,{{ base64_encode(file_get_contents(public_path('assets/images/logo-adasi.png'))) }}");
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            z-index: 2;
        }

        .adasi-loader-text {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--adasi-blue);
            letter-spacing: 0.5px;
        }

        @keyframes adasiSpin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Sembunyikan DataTables default processing sepenuhnya */
        div.dataTables_wrapper div.dataTables_processing {
            display: none !important;
        }

        /* Micro-animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .card {
            --bs-card-bg: var(--md-surface);
            --bs-card-border-color: var(--md-outline-variant);
            --bs-card-border-radius: var(--md-shape-md);
            background-color: var(--md-surface);
            border: 1px solid var(--md-outline-variant);
            border-radius: var(--md-shape-md);
            box-shadow: var(--md-elevation-1);
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }

        .card:hover {
            box-shadow: var(--md-elevation-2) !important;
        }

        /* Action button consistency in tables */
        .table .btn {
            padding: 0.25rem 0.6rem;
            font-size: 0.85rem;
            border-radius: 4px;
        }

        .table .btn i {
            margin-right: 0.2rem;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background-color: var(--md-surface);
            box-shadow: var(--md-elevation-1);
            z-index: var(--ui-z-drawer);
            transition: width var(--ui-motion-standard) var(--ui-easing-standard), transform var(--ui-motion-standard) var(--ui-easing-standard);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
        }

        .sidebar-brand {
            padding: 1.5rem;
            border-bottom: 1px solid var(--md-outline-variant);
            display: flex;
            align-items: center;
            height: 70px;
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-brand {
            padding: 1rem;
            justify-content: center;
        }

        .sidebar.collapsed .brand-text {
            display: none;
        }

        .sidebar-menu {
            padding: 1rem 0;
            overflow-y: auto;
            overflow-x: hidden;
            flex-grow: 1;
        }

        .sidebar-heading {
            padding: 0.5rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--md-on-surface-variant);
            margin-top: 1rem;
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-heading {
            display: none;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            margin: 0.1rem 0.75rem;
            padding: 0.7rem 0.75rem;
            border-radius: var(--md-shape-full);
            color: var(--md-on-surface-variant);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
            font-weight: 500;
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-link {
            margin-inline: 0.5rem;
            padding: 0.75rem 0;
            justify-content: center;
        }

        .sidebar-link:hover {
            background-color: var(--md-surface-container-high);
            color: var(--adasi-blue);
        }

        .sidebar-link i {
            margin-right: 10px;
            font-size: 1.2rem;
            color: var(--md-on-surface-variant);
            transition: color 0.2s;
            min-width: 24px;
            text-align: center;
        }

        .sidebar.collapsed .sidebar-link i {
            margin-right: 0;
        }

        .sidebar-link span {
            transition: opacity 0.3s;
        }

        .sidebar.collapsed .sidebar-link span {
            display: none;
        }

        .sidebar-link:hover i {
            color: var(--adasi-blue);
        }

        .sidebar-link.active {
            background-color: var(--md-primary-container);
            color: var(--md-on-primary-container);
            border-right: 0;
        }

        .sidebar-link.active i {
            color: var(--md-on-primary-container);
        }

        /* Main Content */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-inline-start var(--ui-motion-standard) var(--ui-easing-standard);
        }

        .main-wrapper.expanded {
            margin-left: var(--sidebar-width-collapsed);
        }

        .top-navbar {
            background-color: var(--md-surface);
            height: 70px;
            padding: 0 2rem;
            box-shadow: var(--md-elevation-1);
            border-bottom: 1px solid var(--md-outline-variant);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: var(--ui-z-sticky);
        }

        /* Material global component styling */
        .btn {
            --bs-btn-padding-x: 0.875rem;
            --bs-btn-padding-y: 0.5rem;
            --bs-btn-font-weight: 600;
            --bs-btn-border-radius: var(--md-shape-sm);
            --bs-btn-disabled-opacity: var(--md-state-disabled-opacity);
            border-radius: var(--md-shape-sm);
            min-height: 40px;
            transition: background-color 160ms ease, border-color 160ms ease, color 160ms ease, box-shadow 160ms ease;
        }

        .btn-sm {
            --bs-btn-padding-x: 0.625rem;
            --bs-btn-padding-y: 0.375rem;
            --bs-btn-font-size: 0.8rem;
            min-height: 32px;
        }

        .btn-lg {
            --bs-btn-padding-x: 1.25rem;
            --bs-btn-padding-y: 0.75rem;
            min-height: 48px;
        }

        .btn-primary,
        .btn-info {
            --md-button-container: var(--md-primary);
            --md-button-label: var(--md-on-primary);
            --md-button-label-rgb: var(--md-on-primary-rgb);
            --md-button-focus-rgb: var(--md-primary-rgb);
        }

        .btn-secondary {
            --md-button-container: var(--md-secondary);
            --md-button-label: var(--md-on-secondary);
            --md-button-label-rgb: var(--md-on-secondary-rgb);
            --md-button-focus-rgb: var(--md-secondary-rgb);
        }

        .btn-success {
            --md-button-container: var(--md-success);
            --md-button-label: var(--md-on-success);
            --md-button-label-rgb: var(--md-on-success-rgb);
            --md-button-focus-rgb: var(--md-success-rgb);
        }

        .btn-danger {
            --md-button-container: var(--md-error);
            --md-button-label: var(--md-on-error);
            --md-button-label-rgb: var(--md-on-error-rgb);
            --md-button-focus-rgb: var(--md-error-rgb);
        }

        .btn-warning {
            --md-button-container: var(--md-warning);
            --md-button-label: var(--md-on-warning);
            --md-button-label-rgb: var(--md-on-warning-rgb);
            --md-button-focus-rgb: var(--md-warning-rgb);
        }

        .btn-light {
            --md-button-container: var(--md-surface-container);
            --md-button-label: var(--md-on-surface);
            --md-button-label-rgb: var(--md-on-surface-rgb);
            --md-button-focus-rgb: var(--md-primary-rgb);
        }

        .btn-primary,
        .btn-info,
        .btn-secondary,
        .btn-success,
        .btn-danger,
        .btn-warning,
        .btn-light {
            --bs-btn-color: var(--md-button-label);
            --bs-btn-bg: var(--md-button-container);
            --bs-btn-border-color: var(--md-button-container);
            --bs-btn-hover-color: var(--md-button-label);
            --bs-btn-hover-bg: var(--md-button-container);
            --bs-btn-hover-border-color: var(--md-button-container);
            --bs-btn-active-color: var(--md-button-label);
            --bs-btn-active-bg: var(--md-button-container);
            --bs-btn-active-border-color: var(--md-button-container);
            --bs-btn-disabled-color: var(--md-button-label);
            --bs-btn-disabled-bg: var(--md-button-container);
            --bs-btn-disabled-border-color: var(--md-button-container);
            --bs-btn-focus-shadow-rgb: var(--md-button-focus-rgb);
            --md-button-state-rgb: var(--md-button-label-rgb);
        }

        .btn-outline-primary,
        .btn-outline-info {
            --md-button-label: var(--md-primary);
            --md-button-label-rgb: var(--md-primary-rgb);
        }

        .btn-outline-secondary {
            --md-button-label: var(--md-secondary);
            --md-button-label-rgb: var(--md-secondary-rgb);
        }

        .btn-outline-success {
            --md-button-label: var(--md-success);
            --md-button-label-rgb: var(--md-success-rgb);
        }

        .btn-outline-danger {
            --md-button-label: var(--md-error);
            --md-button-label-rgb: var(--md-error-rgb);
        }

        .btn-outline-warning {
            --md-button-label: var(--md-on-warning-container);
            --md-button-label-rgb: 95, 61, 8;
        }

        .btn-outline-primary,
        .btn-outline-info,
        .btn-outline-secondary,
        .btn-outline-success,
        .btn-outline-danger,
        .btn-outline-warning {
            --bs-btn-color: var(--md-button-label);
            --bs-btn-bg: transparent;
            --bs-btn-border-color: var(--md-button-label);
            --bs-btn-hover-color: var(--md-button-label);
            --bs-btn-hover-bg: transparent;
            --bs-btn-hover-border-color: var(--md-button-label);
            --bs-btn-active-color: var(--md-button-label);
            --bs-btn-active-bg: transparent;
            --bs-btn-active-border-color: var(--md-button-label);
            --bs-btn-disabled-color: var(--md-button-label);
            --bs-btn-disabled-bg: transparent;
            --bs-btn-disabled-border-color: var(--md-button-label);
            --bs-btn-focus-shadow-rgb: var(--md-button-label-rgb);
            --md-button-state-rgb: var(--md-button-label-rgb);
        }

        .btn:not(.btn-link):not(:disabled):not(.disabled):hover {
            background-image: linear-gradient(
                rgba(var(--md-button-state-rgb, var(--md-on-surface-rgb)), var(--md-state-hover-opacity)),
                rgba(var(--md-button-state-rgb, var(--md-on-surface-rgb)), var(--md-state-hover-opacity))
            );
        }

        .btn:focus-visible {
            box-shadow: 0 0 0 0.25rem rgba(var(--md-primary-rgb), .24);
        }

        .btn-check:checked + .btn:not(.btn-link),
        .btn:not(.btn-link):not(:disabled):not(.disabled):active,
        .btn:not(.btn-link).active,
        .btn:not(.btn-link).show {
            background-image: linear-gradient(
                rgba(var(--md-button-state-rgb, var(--md-on-surface-rgb)), var(--md-state-pressed-opacity)),
                rgba(var(--md-button-state-rgb, var(--md-on-surface-rgb)), var(--md-state-pressed-opacity))
            );
        }

        .btn:disabled,
        .btn.disabled {
            background-image: none;
        }

        .badge {
            --bs-badge-padding-x: 0.65em;
            --bs-badge-padding-y: 0.38em;
            --bs-badge-font-size: 0.65rem;
            --bs-badge-font-weight: 600;
            --bs-badge-border-radius: var(--md-shape-full);
            border: 1px solid transparent;
            border-radius: var(--md-shape-full);
            letter-spacing: 0.02em;
            line-height: 1.2;
        }

        .badge.bg-primary,
        .badge.text-bg-primary,
        .badge.bg-info,
        .badge.text-bg-info {
            background-color: var(--md-primary-container) !important;
            border-color: var(--md-outline-variant) !important;
            color: var(--md-on-primary-container) !important;
        }

        .badge.bg-secondary,
        .badge.text-bg-secondary {
            background-color: var(--md-secondary-container) !important;
            border-color: var(--md-outline-variant) !important;
            color: var(--md-on-secondary-container) !important;
        }

        .badge.bg-success,
        .badge.text-bg-success {
            background-color: var(--md-success-container) !important;
            border-color: var(--md-outline-variant) !important;
            color: var(--md-on-success-container) !important;
        }

        .badge.bg-danger,
        .badge.text-bg-danger {
            background-color: var(--md-error-container) !important;
            border-color: var(--md-outline-variant) !important;
            color: var(--md-on-error-container) !important;
        }

        .badge.bg-warning,
        .badge.text-bg-warning {
            background-color: var(--md-warning-container) !important;
            border-color: var(--md-outline-variant) !important;
            color: var(--md-on-warning-container) !important;
        }

        .badge.bg-light,
        .badge.text-bg-light {
            background-color: var(--md-surface-container-low) !important;
            border-color: var(--md-outline-variant) !important;
            color: var(--md-on-surface-variant) !important;
        }

        .badge.bg-dark,
        .badge.text-bg-dark {
            background-color: var(--md-surface-container-high) !important;
            border-color: var(--md-outline) !important;
            color: var(--md-on-surface) !important;
        }

        .form-control,
        .form-select {
            background-color: var(--md-surface);
            border-color: var(--md-outline);
            border-radius: var(--md-shape-sm);
            color: var(--md-on-surface);
            transition: border-color 160ms ease, box-shadow 160ms ease, background-color 160ms ease;
        }

        .form-control::placeholder {
            color: var(--md-on-surface-variant);
            opacity: 1;
        }

        .form-control:not(.is-valid):not(.is-invalid):focus,
        .form-select:not(.is-valid):not(.is-invalid):focus {
            background-color: var(--md-surface);
            border-color: var(--md-primary);
            box-shadow: 0 0 0 0.25rem rgba(var(--md-primary-rgb), .18);
            color: var(--md-on-surface);
        }

        .form-control:disabled,
        .form-select:disabled {
            background-color: var(--md-surface-container);
            color: rgba(var(--md-on-surface-rgb), var(--md-state-disabled-opacity));
        }

        .form-check-input {
            border-color: var(--md-outline);
        }

        .form-check-input:checked {
            background-color: var(--md-primary);
            border-color: var(--md-primary);
        }

        .form-check-input:not(.is-valid):not(.is-invalid):focus {
            border-color: var(--md-primary);
            box-shadow: 0 0 0 0.25rem rgba(var(--md-primary-rgb), .18);
        }

        .input-group-text {
            background-color: var(--md-surface-container);
            border-color: var(--md-outline);
            color: var(--md-on-surface-variant);
        }

        .dropdown-menu {
            --bs-dropdown-bg: var(--md-surface);
            --bs-dropdown-color: var(--md-on-surface);
            --bs-dropdown-border-color: var(--md-outline-variant);
            --bs-dropdown-border-radius: var(--md-shape-md);
            --bs-dropdown-link-hover-bg: var(--md-surface-container-high);
            --bs-dropdown-link-hover-color: var(--md-on-surface);
            --bs-dropdown-link-active-bg: var(--md-primary-container);
            --bs-dropdown-link-active-color: var(--md-on-primary-container);
            background-color: var(--md-surface);
            border: 1px solid var(--md-outline-variant);
            border-radius: var(--md-shape-md);
            box-shadow: var(--md-elevation-2);
            color: var(--md-on-surface);
        }

        .dropdown-item {
            border-radius: var(--md-shape-sm);
        }

        .dropdown-item:hover,
        .dropdown-item:focus {
            background-color: var(--md-surface-container-high);
            color: var(--md-on-surface);
        }

        .dropdown-item.active,
        .dropdown-item:active {
            background-color: var(--md-primary-container);
            color: var(--md-on-primary-container);
        }

        .modal-content {
            --bs-modal-bg: var(--md-surface);
            --bs-modal-border-color: var(--md-outline-variant);
            --bs-modal-border-radius: var(--md-shape-md);
            background-color: var(--md-surface);
            border: 1px solid var(--md-outline-variant);
            border-radius: var(--md-shape-md);
            box-shadow: var(--md-elevation-3);
            color: var(--md-on-surface);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--md-outline-variant);
        }

        .content-area {
            padding: 2rem;
            flex-grow: 1;
            animation: fadeInContent 0.25s ease-out;
            scroll-margin-top: 70px;
        }

        @keyframes fadeInContent {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Role Badge */
        .role-badge {
            padding: 0.35rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .role-badge-admin {
            background-color: var(--md-primary-container);
            color: var(--md-on-primary-container);
        }

        .role-badge-purchasing {
            background-color: var(--md-success-container);
            color: var(--md-on-success-container);
        }

        .role-badge-supplier {
            background-color: var(--md-warning-container);
            color: var(--md-on-warning-container);
        }

        .role-badge-qc {
            background-color: var(--md-error-container);
            color: var(--md-on-error-container);
        }

        /* Global Table Styling */
        .table {
            border: 1px solid var(--md-outline) !important;
            /* Slate-300 untuk border lebih tegas */
            border-collapse: collapse !important;
            background-color: var(--md-surface);
        }

        .table thead th {
            background-color: var(--md-surface-container) !important;
            /* Slate-100 */
            border-bottom: 2px solid var(--md-outline-strong) !important;
            /* Slate-400 */
            color: var(--md-on-surface) !important;
            /* Slate-800 */
            font-weight: 700 !important;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.025em;
        }

        .table td,
        .table th {
            border: 1px solid var(--md-outline) !important;
            /* Slate-300 */
            vertical-align: middle !important;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: var(--md-surface-container-low) !important;
            /* Slate-50 */
        }

        .table-hover tbody tr:hover {
            background-color: rgba(var(--md-primary-rgb), 0.05) !important;
        }

        /* Sticky Table Header - keeps column context visible when scrolling */
        .table-responsive {
            max-height: none;
            /* allow natural scroll */
        }

        .table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
        }

        /* When table is inside content-area (below sticky navbar), offset for navbar */
        .content-area .card .table thead th {
            top: 0;
            /* relative to card scroll container */
        }

        /* Action button sizing - consistent minimum touch target */
        .table .btn-sm {
            padding: 0.3rem 0.55rem;
            font-size: 0.78rem;
            min-width: 32px;
            min-height: 30px;
        }

        /* Clickable KPI cards */
        a.kpi-card {
            text-decoration: none;
            color: inherit;
            display: block;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        a.kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--md-elevation-2) !important;
        }

        a.kpi-card .kpi-arrow {
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        a.kpi-card:hover .kpi-arrow {
            opacity: 1;
        }

        /* DataTables Adjustment */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--adasi-blue) !important;
            color: var(--md-on-primary) !important;
            border: 1px solid var(--adasi-blue) !important;
        }

        /* Chat Drawer */
        .chat-drawer {
            width: 430px !important;
            max-width: 100vw;
        }

        .chat-drawer .offcanvas-body {
            background-color: var(--md-background);
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 0;
        }

        .chat-drawer-pane {
            display: flex;
            flex: 1;
            flex-direction: column;
            min-height: 0;
        }

        .chat-thread-list,
        .chat-message-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        .chat-message-list {
            min-height: 220px;
        }

        .chat-thread-button {
            background: var(--md-surface);
            border: 0;
            border-bottom: 1px solid var(--md-outline-variant);
            color: inherit;
            display: block;
            padding: 0.9rem 1rem;
            text-align: left;
            width: 100%;
        }

        .chat-thread-button:hover {
            background: rgba(var(--md-primary-rgb), 0.06);
        }

        .chat-message-row {
            display: flex;
            margin-bottom: 0.85rem;
            width: 100%;
        }

        .chat-message-stack {
            display: flex;
            flex-direction: column;
            max-width: min(72%, 320px);
            min-width: 0;
        }

        .chat-message-bubble {
            border-radius: 0.72rem;
            display: inline-block;
            padding: 0.52rem 0.68rem;
            word-break: break-word;
            width: fit-content;
            max-width: 100%;
            line-height: 1.35;
            font-size: 0.92rem;
        }

        .chat-message-text {
            white-space: pre-wrap;
        }

        .chat-message-bubble.is-me {
            background: var(--adasi-blue);
            color: var(--md-on-primary);
            border-bottom-right-radius: 0.28rem;
        }

        .chat-message-bubble.is-partner {
            background: var(--md-surface);
            border: 1px solid var(--md-outline-variant);
            color: var(--md-on-surface);
            border-bottom-left-radius: 0.28rem;
        }

        .chat-message-meta {
            align-items: center;
            color: var(--md-on-surface-variant);
            display: flex;
            font-size: 0.72rem;
            gap: 0.25rem;
            line-height: 1.2;
            margin-top: 0.25rem;
            padding-inline: 0.2rem;
        }

        .chat-message-meta.text-end {
            justify-content: flex-end;
        }

        .chat-message-meta.text-start {
            justify-content: flex-start;
        }

        .chat-context-panel {
            flex: 0 0 auto;
            padding: 0.75rem 1rem !important;
        }

        .chat-context-grid {
            display: grid;
            gap: 0.4rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            max-height: 145px;
            overflow-y: auto;
            font-size: 0.78rem;
        }

        .chat-context-field {
            min-width: 0;
            border: 1px solid var(--md-outline-variant);
            border-radius: 0.45rem;
            background: var(--md-surface-container-low);
            padding: 0.35rem 0.5rem;
        }

        .chat-action-panel,
        .chat-template-strip {
            gap: 0.4rem;
        }

        .chat-action-panel {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 0.4rem !important;
            white-space: nowrap;
        }

        .chat-action-panel .btn {
            flex: 0 0 auto;
        }

        .chat-template-menu {
            max-height: 220px;
            max-width: 340px;
            min-width: 280px;
            overflow-y: auto;
        }

        .chat-composer-tools {
            min-height: 32px;
        }

        #chatDrawerInput {
            min-height: 58px;
        }

        .chat-attachment-stack {
            display: grid;
            gap: 0.4rem;
        }

        .chat-attachment-link {
            align-items: center;
            background: rgba(var(--md-on-primary-rgb), 0.16);
            border: 1px solid rgba(var(--md-on-primary-rgb), 0.28);
            border-radius: 0.45rem;
            color: inherit;
            display: flex;
            max-width: 100%;
            padding: 0.42rem 0.55rem;
            text-decoration: none;
        }

        .chat-message-bubble.is-partner .chat-attachment-link {
            background: var(--md-surface-container-low);
            border-color: var(--md-outline-variant);
            color: var(--adasi-blue);
        }

        .chat-read-receipt {
            color: var(--md-outline-strong);
            display: inline-flex;
            font-size: 0.82rem;
            line-height: 1;
            vertical-align: -0.08rem;
        }

        .chat-read-receipt.is-read {
            color: var(--md-primary);
        }

        .chat-fullpage-shell {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            height: calc(100vh - 105px);
            min-height: 620px;
            overflow: hidden;
            width: 100%;
        }

        .chat-fullpage-back,
        .chat-fullpage-context {
            flex: 0 0 auto;
        }

        .chat-fullpage-context .card-body {
            padding: 0.65rem 1rem;
        }

        .chat-fullpage-context-details summary {
            cursor: pointer;
            list-style: none;
            width: fit-content;
        }

        .chat-fullpage-context-details summary::-webkit-details-marker {
            display: none;
        }

        .chat-fullpage-context-details summary::after {
            content: " ▾";
            font-size: 0.75rem;
        }

        .chat-fullpage-context-details[open] summary::after {
            content: " ▴";
        }

        .chat-fullpage-context-details .row.g-2 {
            max-height: 96px;
            overflow-y: auto;
        }

        .chat-fullpage-context:not(:has(.chat-fullpage-context-details[open])) {
            overflow: visible;
        }

        .chat-fullpage-card {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
        }

        .chat-fullpage-card .card-header,
        .chat-fullpage-card .card-footer {
            flex: 0 0 auto;
        }

        .chat-fullpage-card .card-header {
            min-height: 58px;
            padding: 0.55rem 1rem !important;
        }

        .chat-fullpage-card .card-footer {
            padding: 0.6rem 1rem !important;
        }

        .chat-fullpage-card #chat-messages {
            flex: 1 1 auto !important;
            min-height: 0;
            padding: 1.25rem 1.5rem !important;
        }

        .chat-fullpage-avatar {
            height: 36px;
            width: 36px;
        }

        .chat-fullpage-avatar i {
            font-size: 1.1rem !important;
        }

        .chat-fullpage-card .chat-message-stack {
            max-width: min(88%, 1180px);
        }

        .chat-fullpage-card .chat-message-bubble {
            font-size: 0.96rem;
            line-height: 1.42;
        }

        .chat-fullpage-card #message-body {
            min-height: 46px;
            max-height: 92px;
            flex: 1 1 auto;
        }

        .notification-dropdown {
            border: 0;
            border-radius: 0.75rem;
            box-shadow: var(--md-elevation-3);
            overflow: hidden;
            padding: 0;
            width: min(780px, calc(100vw - 2rem));
        }

        .notification-panel {
            display: grid;
            grid-template-columns: 210px minmax(0, 1fr);
            height: min(620px, calc(100vh - 110px));
            min-height: 380px;
            overflow: hidden;
        }

        .notification-menu {
            background: var(--md-surface-container-low);
            border-right: 1px solid var(--md-outline-variant);
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto;
            padding: 0.85rem;
        }

        .notification-menu-heading {
            color: var(--md-on-surface-variant);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin-bottom: 0.65rem;
            text-transform: uppercase;
        }

        .notification-menu .nav-link {
            align-items: center;
            border-radius: 0.55rem;
            color: var(--md-on-surface-variant);
            display: flex;
            font-size: 0.82rem;
            font-weight: 600;
            flex: 0 0 auto;
            gap: 0.5rem;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            padding: 0.55rem 0.65rem;
            text-align: left;
            width: 100%;
        }

        .notification-menu .nav-link.active {
            background: rgba(var(--md-primary-rgb), 0.1);
            color: var(--adasi-blue);
        }

        .notification-list-pane {
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
            overflow: hidden;
        }

        .notification-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        .notification-item {
            color: inherit;
            display: block;
            padding: 0.85rem 1rem;
            text-decoration: none;
        }

        .notification-item:hover {
            background: rgba(var(--md-primary-rgb), 0.05);
        }

        .min-w-0 {
            min-width: 0;
        }

        .notification-page-menu {
            background: var(--md-surface-container-low);
            border-radius: 0.75rem;
            padding: 0.85rem;
        }

        .notification-page-menu .list-group-item {
            align-items: center;
            border: 0;
            border-radius: 0.55rem;
            color: var(--md-on-surface-variant);
            display: flex;
            font-size: 0.88rem;
            font-weight: 600;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            padding: 0.7rem 0.75rem;
        }

        .notification-page-menu .list-group-item.active {
            background: rgba(var(--md-primary-rgb), 0.1);
            color: var(--adasi-blue);
        }

        /* Responsive */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width) !important;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .content-area {
                padding: 1.5rem;
            }

            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100vh;
                background: var(--ui-dialog-scrim);
                z-index: 1039;
            }

            .sidebar-overlay.show {
                display: block;
            }

            .chat-drawer {
                width: 100vw !important;
            }

            .notification-panel {
                grid-template-columns: 1fr;
                height: min(620px, calc(100vh - 95px));
            }

            .notification-menu {
                border-bottom: 1px solid var(--md-outline-variant);
                border-right: 0;
                display: flex;
                flex-direction: row;
                gap: 0.35rem;
                overflow-x: auto;
                overflow-y: hidden;
            }

            .notification-menu-heading {
                align-items: center;
                display: flex;
                flex: 0 0 auto;
                margin-bottom: 0;
                margin-right: 0.35rem;
            }

            .notification-menu .nav-link {
                flex: 0 0 auto;
                margin-bottom: 0;
                white-space: nowrap;
                width: auto;
            }
        }

        .cursor-pointer {
            cursor: pointer !important;
        }
    </style>
    @stack('styles')
</head>

<body
    x-data="adasiShell"
    x-on:ui-sidebar-toggle.window="toggleSidebar()"
    x-on:keydown.escape.window="closeMobileSidebar()"
    x-on:keydown.tab.window="trapSidebarFocus($event)"
    x-effect="document.body.classList.toggle('ui-nav-open', mobileOpen)"
>
    <a href="#main-content" class="ui-skip-link">Langsung ke konten utama</a>
    @php
        $initNotifCount = 0;
        $initChatCount = 0;
        $dashboardUrl = url('/');
        if (auth()->check()) {
            $roleDashboardRoute = auth()->user()->role
                ? auth()->user()->role . '.dashboard'
                : 'dashboard';
            $dashboardUrl = \Illuminate\Support\Facades\Route::has($roleDashboardRoute)
                ? route($roleDashboardRoute)
                : route('dashboard');
            if (in_array(auth()->user()->role, ['purchasing', 'supplier'])) {
                $initChatCount = \App\Models\Conversation::forUser(auth()->id())
                    ->withCount([
                        'messages' => function ($q) {
                            $q->where('sender_id', '!=', auth()->id())->whereNull('read_at');
                        }
                    ])
                    ->get()
                    ->sum('messages_count');
            }
        }
    @endphp
    {{-- Sidebar --}}
    @include('partials.sidebar')

    {{-- Mobile Overlay --}}
    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
        x-bind:class="{ 'show': mobileOpen }"
        x-on:click="closeMobileSidebar()"
        aria-hidden="true"
    ></div>

    {{-- Main Wrapper --}}
    <div class="main-wrapper" id="mainWrapper" x-bind:class="{ 'expanded': desktopCollapsed }">
        {{-- Navbar --}}
        @include('partials.navbar')

        {{-- Content Area --}}
        <main class="content-area" id="main-content" tabindex="-1">
            <div class="content-container">
                @include('partials.alerts')
                @yield('content')
            </div>
        </main>
    </div>

    @include('partials.chat-drawer')
    <x-ui.toast-container context="app" />

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        window.initAdasiTooltips = function (root = document) {
            if (!window.bootstrap?.Tooltip) {
                return;
            }

            root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
                bootstrap.Tooltip.getOrCreateInstance(element);
            });
        };

        document.addEventListener('DOMContentLoaded', () => window.initAdasiTooltips());
    </script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // ADASI Loader — Inject overlay ke body
        const isDataTableRequest = (options = {}) => {
            const data = options.data;

            if (data && typeof data === 'object') {
                return Object.prototype.hasOwnProperty.call(data, 'draw');
            }

            return typeof data === 'string' && /(?:^|&)draw(?:=|%5B|\[)/i.test(data);
        };

        $.ajaxPrefilter((options) => {
            if (isDataTableRequest(options)) {
                options.global = false;
            }
        });

        $(function () {
            // Buat overlay loader sekali saja
            $('body').append(
                '<div class="adasi-loader-overlay" id="adasiLoader">' +
                '<div class="adasi-loader-card">' +
                '<div class="adasi-loader-ring">' +
                '<div class="adasi-loader-logo"></div>' +
                '</div>' +
                '<span class="adasi-loader-text">Loading...</span>' +
                '</div>' +
                '</div>'
            );

            // Tampilkan saat AJAX mulai (termasuk DataTables)
            $(document).ajaxStart(function () {
                $('#adasiLoader').addClass('active');
            });

            // Sembunyikan saat AJAX selesai
            $(document).ajaxStop(function () {
                $('#adasiLoader').removeClass('active');
            });
        });
    </script>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="{{ asset('assets/js/adasi-alert.js') }}"></script>

    <!-- Ngrok browser warning bypass for internal async requests -->
    <script>
        (() => {
            const headerName = 'ngrok-skip-browser-warning';
            const headerValue = 'true';

            const isInternalUrl = (url) => {
                try {
                    return new URL(url, window.location.href).origin === window.location.origin;
                } catch (error) {
                    return false;
                }
            };

            const mergeHeaders = (...headerSets) => {
                const headers = new Headers();

                headerSets
                    .filter(Boolean)
                    .forEach((headerSet) => {
                        new Headers(headerSet).forEach((value, key) => {
                            headers.set(key, value);
                        });
                    });

                if (!headers.has(headerName)) {
                    headers.set(headerName, headerValue);
                }

                return headers;
            };

            if (window.fetch) {
                const originalFetch = window.fetch.bind(window);

                window.fetch = (input, init = {}) => {
                    const targetUrl = input instanceof Request ? input.url : input;

                    if (!isInternalUrl(targetUrl)) {
                        return originalFetch(input, init);
                    }

                    return originalFetch(input, {
                        ...init,
                        headers: mergeHeaders(input instanceof Request ? input.headers : null, init.headers),
                    });
                };
            }

            if (window.jQuery) {
                $.ajaxPrefilter((options, originalOptions, jqXHR) => {
                    if (isInternalUrl(options.url || window.location.href)) {
                        jqXHR.setRequestHeader(headerName, headerValue);
                    }
                });
            }
        })();
    </script>

    <!-- Custom JS -->
    <script>
        @auth
            function updateBadges() {
                // Notification badge
                fetch("{{ route('notifications.unread-count') }}")
                    .then(r => r.json())
                    .then(data => {
                        document.querySelectorAll('.notif-badge').forEach(badge => {
                            if (data.count > 0) {
                                badge.textContent = data.count;
                                badge.classList.remove('d-none');
                            } else {
                                badge.classList.add('d-none');
                            }
                        });

                        if (typeof updateNotificationCategoryBadges === 'function') {
                            updateNotificationCategoryBadges(data.category_counts);
                        }
                    });

                // Chat badge
                @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
                    fetch("{{ route('conversations.unread-count') }}")
                        .then(r => r.json())
                        .then(data => {
                            document.querySelectorAll('.chat-badge').forEach(badge => {
                                if (data.count > 0) {
                                    badge.textContent = data.count;
                                    badge.classList.remove('d-none');
                                } else {
                                    badge.classList.add('d-none');
                                }
                            });
                        });
                @endif
                }

            // Run immediately on load
            updateBadges();

            // Polling every 30 seconds
            setInterval(updateBadges, 30000);
        @endauth
    </script>
    @auth
        @if(auth()->user()->role === 'purchasing')
            <script>
                document.addEventListener('click', (event) => {
                    const link = event.target.closest('a[href]');

                    if (!link || event.defaultPrevented || event.button !== 0) {
                        return;
                    }

                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    if (
                        link.target && link.target !== '_self'
                        || link.hasAttribute('download')
                        || link.closest('.sidebar-menu')
                        || link.hasAttribute('data-chat-drawer')
                        || link.hasAttribute('data-open-chat-conversation')
                        || link.hasAttribute('data-bs-toggle')
                    ) {
                        return;
                    }

                    const href = link.getAttribute('href');

                    if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                        return;
                    }

                    const targetUrl = new URL(href, window.location.origin);
                    const listPaths = new Set(@json(\App\Support\PurchasingNavigation::listRoutePaths()));

                    if (
                        targetUrl.origin !== window.location.origin
                        || !targetUrl.pathname.startsWith('/purchasing/')
                        || targetUrl.pathname.startsWith('/purchasing/export/')
                        || listPaths.has(targetUrl.pathname)
                        || targetUrl.searchParams.has('return_url')
                    ) {
                        return;
                    }

                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.delete('return_url');
                    targetUrl.searchParams.set('return_url', currentUrl.toString());
                    link.href = targetUrl.toString();
                }, true);
            </script>
        @endif
    @endauth
    {{-- Global: Pencegahan Double Submit --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('submit', function (e) {
                const form = e.target;
                if (!(form instanceof HTMLFormElement)) return;

                // Skip jika form already di-tag submitting
                if (form.dataset.submitting === 'true') {
                    e.preventDefault();
                    return;
                }

                form.dataset.submitting = 'true';

                // Disable all tombol submit di dalam form
                const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                buttons.forEach(function (btn) {
                    btn.disabled = true;

                    // Save teks asli & ganti dengan spinner
                    if (btn.tagName === 'BUTTON') {
                        btn.dataset.originalHtml = btn.innerHTML;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Loading...';
                    }
                });

                // Safety reset setelah 10 detik (jika request gagal/timeout)
                setTimeout(function () {
                    form.dataset.submitting = 'false';
                    buttons.forEach(function (btn) {
                        btn.disabled = false;
                        if (btn.tagName === 'BUTTON' && btn.dataset.originalHtml) {
                            btn.innerHTML = btn.dataset.originalHtml;
                        }
                    });
                }, 10000);
            });
        });
    </script>

    {{-- Async export: server-side generation, automatic download, no page refresh. --}}
    <script src="{{ asset('assets/js/async-export.js') }}?v={{ file_exists(public_path('assets/js/async-export.js')) ? filemtime(public_path('assets/js/async-export.js')) : '1' }}"></script>

    {{-- Global Script untuk PDF Preview --}}
    <script>
        document.addEventListener('click', function (e) {
            const pdfBtn = e.target.closest('a[href*="/pdf/"][data-pdf-confirm]');
            if (!pdfBtn || e.defaultPrevented || e.button !== 0) {
                return;
            }

            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            e.preventDefault();

            if (window.pdfConfirmationOpen) {
                return;
            }

            window.pdfConfirmationOpen = true;

            AdasiAlert.confirm({
                title: 'Download PDF Document?',
                text: 'The PDF document will be downloaded. Do you want to continue?',
                confirmText: 'Yes, Download',
                cancelText: 'Cancel'
            }).then((result) => {
                window.pdfConfirmationOpen = false;

                if (!result.isConfirmed) {
                    return;
                }

                const target = pdfBtn.getAttribute('target');
                if (target === '_blank') {
                    window.open(pdfBtn.href, '_blank', 'noopener,noreferrer');
                } else {
                    window.location.href = pdfBtn.href;
                }
            }).catch(() => {
                window.pdfConfirmationOpen = false;
            });
        });
    </script>

    {{-- Global Script untuk Excel Export Preview --}}
    <script>
        document.addEventListener('click', function (e) {
            const exportBtn = e.target.closest('a[href*="/export/"][data-export-confirm]');
            if (!exportBtn || e.defaultPrevented || e.button !== 0) {
                return;
            }

            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            e.preventDefault();

            if (window.exportConfirmationOpen) {
                return;
            }

            window.exportConfirmationOpen = true;

            let recordsTotal = 'all';

            AdasiAlert.confirm({
                title: 'Export Data to Excel?',
                text: 'The data will be exported based on current filters. Do you want to continue?',
                confirmText: 'Yes, Export',
                cancelText: 'Cancel'
            }).then((result) => {
                window.exportConfirmationOpen = false;

                if (!result.isConfirmed) {
                    return;
                }

                window.location.href = exportBtn.href;
            }).catch(() => {
                window.exportConfirmationOpen = false;
            });
        });
    </script>

    {{-- Keyboard Shortcuts --}}
    <script>
        document.addEventListener('keydown', function (e) {
            // Abaikan jika fokus ada pada input/textarea
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
                return;
            }

            // Alt + D -> Dashboard
            if (e.altKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                window.location.href = @json($dashboardUrl);
            }

            // ? -> Modal Shortcut
            if (e.key === '?') {
                e.preventDefault();
                AdasiAlert.info({
                    title: 'Keyboard Shortcuts',
                    html: `
                        <div class="text-start">
                            <table class="table table-borderless table-sm mb-0">
                                <tr>
                                    <td width="40%"><kbd>Alt + D</kbd></td>
                                    <td>Back to Dashboard</td>
                                </tr>
                                <tr>
                                    <td><kbd>?</kbd></td>
                                    <td>Open This Help</td>
                                </tr>
                            </table>
                        </div>
                    `,
                    confirmText: 'Close'
                });
            }
        });
    </script>

    @php
    $pusherClient = config('broadcasting.connections.pusher', []);
    $pusherOptions = $pusherClient['options'] ?? [];

    $pusherClientReady = config('broadcasting.default') === 'pusher'
        && filled($pusherClient['key'] ?? null)
        && filled($pusherOptions['cluster'] ?? null);
@endphp

@auth
    @if($pusherClientReady)
        <!-- Laravel Echo + Pusher Channels -->
        <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.19.0/dist/echo.iife.js"></script>

        <script>
            (() => {
                const csrfToken =
                    document.querySelector('meta[name="csrf-token"]')?.content;

                const userId =
                    document.querySelector('meta[name="user-id"]')?.content;

                const readUrlBase =
                    @json(url('/notifications/__NOTIFICATION_ID__/read'));

                const allowedCategories = new Set(
                    @json(array_keys(\App\Support\NotificationCategory::options()))
                );

                if (!window.Echo || !window.Pusher || !csrfToken || !userId) {
                    return;
                }

                const readUrlFor = (id) => id
                    ? readUrlBase.replace(
                        '__NOTIFICATION_ID__',
                        encodeURIComponent(String(id))
                    )
                    : null;

                const markReadAndRedirect = async (readUrl) => {
                    if (!readUrl) return;

                    try {
                        const response = await fetch(readUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        const data = response.ok
                            ? await response.json()
                            : null;

                        if (data?.redirect) {
                            window.location.href = data.redirect;
                        }
                    } catch (error) {
                        // Polling tetap menjadi fallback.
                    }
                };

                const createNotificationItem = (
                    notification,
                    category,
                    readUrl
                ) => {
                    const item = document.createElement('div');

                    item.className = 'notification-item bg-light';
                    item.setAttribute('role', 'button');
                    item.dataset.notificationItem = '';
                    item.dataset.notificationCategory = category;
                    item.dataset.notificationUnread = '1';
                    item.dataset.notificationReadUrl = readUrl;
                    item.dataset.notificationId = String(notification.id);
                    item.style.cursor = 'pointer';

                    const row = document.createElement('div');
                    row.className = 'd-flex gap-3';

                    const iconWrap = document.createElement('div');

                    iconWrap.className =
                        'bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0';

                    iconWrap.style.cssText =
                        'width:34px;height:34px;';

                    const icon = document.createElement('i');

                    const iconName = String(
                        notification.icon || 'bi-bell'
                    )
                        .split(/\s+/)
                        .find(
                            (part) =>
                                /^bi-[a-z0-9-]+$/.test(part)
                        ) || 'bi-bell';

                    icon.className =
                        `bi ${iconName} text-primary`;

                    iconWrap.append(icon);

                    const content =
                        document.createElement('div');

                    content.className =
                        'min-w-0 flex-grow-1';

                    const heading =
                        document.createElement('div');

                    heading.className =
                        'd-flex justify-content-between gap-2';

                    const title =
                        document.createElement('div');

                    title.className =
                        'fw-semibold small text-truncate';

                    title.textContent =
                        String(
                            notification.title ||
                            'Notification'
                        );

                    const newBadge =
                        document.createElement('span');

                    newBadge.className =
                        'badge bg-danger flex-shrink-0';

                    newBadge.style.fontSize = '.55rem';
                    newBadge.dataset.notificationNewBadge = '';
                    newBadge.textContent = 'New';

                    heading.append(title, newBadge);

                    const message =
                        document.createElement('div');

                    message.className = 'text-muted';
                    message.style.fontSize = '.76rem';

                    message.textContent =
                        String(
                            notification.message || '-'
                        );

                    const time =
                        document.createElement('div');

                    time.className =
                        'text-muted mt-2';

                    time.style.fontSize = '.68rem';
                    time.textContent = 'Just now';

                    content.append(
                        heading,
                        message,
                        time
                    );

                    row.append(iconWrap, content);
                    item.append(row);

                    return item;
                };

                const insertNotification = (
                    notification,
                    readUrl
                ) => {
                    const category =
                        allowedCategories.has(
                            notification.category
                        ) &&
                        notification.category !== 'all'
                            ? notification.category
                            : 'other';

                    ['all', category].forEach(
                        (paneCategory) => {
                            const pane =
                                document.querySelector(
                                    `#notif-pane-${paneCategory}`
                                );

                            if (
                                !pane ||
                                pane.querySelector(
                                    `[data-notification-id="${CSS.escape(
                                        String(notification.id)
                                    )}"]`
                                )
                            ) {
                                return;
                            }

                            pane
                                .querySelector(
                                    '.text-center.text-muted.py-5'
                                )
                                ?.remove();

                            pane.prepend(
                                createNotificationItem(
                                    notification,
                                    category,
                                    readUrl
                                )
                            );
                        }
                    );
                };

                const isExportLifecycleNotification = (
                    notification
                ) => [
                    'export.completed',
                    'export.failed',
                ].includes(notification.event) &&
                    Boolean(notification.export_job_id);

                const shouldSuppressTransientNotification = (
                    notification
                ) => Boolean(
                    window.AdasiAsyncExport
                        ?.isTrackingNotification?.(notification)
                );

                const showTransientNotification = (
                    notification,
                    readUrl
                ) => {
                    if (shouldSuppressTransientNotification(notification)) {
                        return;
                    }

                    if (window.AdasiToast) {
                        AdasiToast.show({
                            type: 'message',
                            title:
                                notification.title ||
                                'New Notification',
                            message:
                                notification.message ||
                                '',
                            timestamp: 'Just now',
                            icon:
                                notification.icon ||
                                'bi-bell-fill',
                            actions: [
                                {
                                    label: 'Dismiss',
                                    variant: 'secondary',
                                },
                                {
                                    label: 'View',
                                    variant: 'primary',
                                    onClick: () =>
                                        markReadAndRedirect(
                                            readUrl
                                        ),
                                },
                            ],
                        });
                    } else if (window.AdasiAlert) {
                        AdasiAlert.notification({
                            title:
                                notification.title ||
                                'New Notification',
                            text:
                                notification.message ||
                                '',
                            onClick: () =>
                                markReadAndRedirect(
                                    readUrl
                                ),
                        });
                    }
                };

                const deliverTransientNotification = (
                    notification,
                    readUrl
                ) => {
                    if (isExportLifecycleNotification(notification)) {
                        window.setTimeout(
                            () => showTransientNotification(
                                notification,
                                readUrl
                            ),
                            750
                        );
                        return;
                    }

                    showTransientNotification(notification, readUrl);
                };

                try {
                    window.Echo = new Echo({
                        broadcaster: 'pusher',

                        key: @json($pusherClient['key']),

                        cluster: @json(
                            $pusherOptions['cluster']
                        ),

                        forceTLS: true,

                        enabledTransports: [
                            'ws',
                            'wss'
                        ],

                        authEndpoint:
                            '/broadcasting/auth',

                        auth: {
                            headers: {
                                'X-CSRF-TOKEN':
                                    csrfToken
                            }
                        }
                    });

                    window.Echo
                        .private(
                            'App.Models.User.' + userId
                        )
                        .notification(
                            (notification) => {
                                const readUrl =
                                    readUrlFor(
                                        notification.id
                                    );

                                if (!readUrl) return;

                                insertNotification(
                                    notification,
                                    readUrl
                                );

                                updateBadges();
                                deliverTransientNotification(
                                    notification,
                                    readUrl
                                );
                            }
                        );

                } catch (error) {
                    console.error(
                        'Pusher/Echo initialization failed:',
                        error
                    );

                    window.Echo = null;
                }
            })();
        </script>
    @endif
@endauth

    @stack('scripts')
</body>

</html>

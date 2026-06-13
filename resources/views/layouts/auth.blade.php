<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Login - ADASI Supplier Portal')</title>

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">

    {{-- Google Fonts: Inter --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    {{-- Bootstrap 5 CSS --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- Bootstrap Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --adasi-blue: #1F5FA6;
            --adasi-blue-dark: #174A85;
            --adasi-red: #C0392B;
            --auth-bg: #0f172a;
            --auth-text: #162033;
            --auth-muted: #667085;
        }

        * {
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            align-items: center;
            background: var(--auth-bg);
            color: var(--auth-text);
            display: flex;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
            overflow: hidden;
            padding: 1.5rem;
            position: relative;
        }

        body::before {
            animation: authPhotoDrift 22s ease-in-out infinite alternate;
            background-image: url('{{ asset('assets/images/adasi-login-bg.jpg') }}');
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
            content: "";
            filter: blur(4px) saturate(1.08) contrast(0.96);
            inset: -18px;
            opacity: 0.95;
            pointer-events: none;
            position: fixed;
            transform: scale(1.04);
            z-index: 0;
        }

        body::after {
            background:
                linear-gradient(90deg, rgba(2, 6, 23, 0.72) 0%, rgba(31, 95, 166, 0.46) 48%, rgba(2, 6, 23, 0.34) 100%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, rgba(15, 23, 42, 0.44) 100%);
            content: "";
            inset: 0;
            opacity: 1;
            pointer-events: none;
            position: fixed;
            z-index: 0;
        }

        .auth-shell {
            align-items: center;
            display: flex;
            justify-content: center;
            min-height: calc(100vh - 3rem);
            position: relative;
            width: 100%;
            z-index: 1;
        }

        .auth-shell::before {
            -webkit-backdrop-filter: blur(1.5px);
            backdrop-filter: blur(1.5px);
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0.08)),
                linear-gradient(45deg, rgba(31, 95, 166, 0.16), rgba(192, 57, 43, 0.08));
            border: 1px solid rgba(255, 255, 255, 0.22);
            content: "";
            height: min(78vh, 620px);
            max-width: 1080px;
            opacity: 0.78;
            pointer-events: none;
            position: absolute;
            transform: skewY(-7deg) rotate(-1deg);
            width: min(86vw, 940px);
        }

        .auth-card {
            -webkit-backdrop-filter: blur(18px) saturate(145%);
            backdrop-filter: blur(18px) saturate(145%);
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(255, 255, 255, 0.72);
            border-radius: 22px;
            box-shadow:
                0 28px 90px rgba(2, 6, 23, 0.34),
                inset 0 1px 0 rgba(255, 255, 255, 0.72);
            max-width: 430px;
            padding: 2.5rem 2rem;
            position: relative;
            width: 100%;
        }

        .auth-card::before {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.86), transparent 42%);
            border-radius: inherit;
            content: "";
            inset: 1px;
            opacity: 0.42;
            pointer-events: none;
            position: absolute;
        }

        .auth-card > * {
            position: relative;
            z-index: 1;
        }

        .auth-logo {
            margin-bottom: 2rem;
            text-align: center;
        }

        .auth-logo h4 {
            color: var(--adasi-blue);
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .auth-logo p {
            color: var(--auth-muted);
            font-size: 0.82rem;
            margin: 0;
        }

        .auth-logo img {
            display: block;
            filter: drop-shadow(0 10px 24px rgba(31, 95, 166, 0.18));
            margin: 0 auto 1rem;
        }

        .auth-card .form-label,
        .auth-card .form-check-label {
            color: #243044;
        }

        .auth-card .input-group-text,
        .auth-card .form-control {
            background: rgba(255, 255, 255, 0.78) !important;
            border-color: rgba(255, 255, 255, 0.9);
            color: #172033;
        }

        .auth-card .input-group-text {
            color: var(--adasi-blue);
        }

        .auth-card .form-control::placeholder {
            color: rgba(45, 55, 72, 0.52);
        }

        .auth-card .form-control:focus {
            background: rgba(255, 255, 255, 0.9) !important;
            border-color: rgba(31, 95, 166, 0.72);
            box-shadow: 0 0 0 0.2rem rgba(31, 95, 166, 0.16);
        }

        .btn-password-toggle {
            background: rgba(255, 255, 255, 0.78) !important;
            border-color: rgba(255, 255, 255, 0.9) !important;
            border-left: transparent !important;
            color: var(--auth-muted);
            box-shadow: none !important;
        }

        .btn-password-toggle:hover,
        .btn-password-toggle:focus {
            background: rgba(255, 255, 255, 0.9) !important;
            color: var(--adasi-blue);
        }

        .auth-card .form-check-input:checked {
            background-color: var(--adasi-blue);
            border-color: var(--adasi-blue);
        }

        .btn-login {
            background: linear-gradient(135deg, var(--adasi-blue), #164a85);
            border-color: transparent;
            border-radius: 10px;
            box-shadow: 0 12px 26px rgba(31, 95, 166, 0.24);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.68rem;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .btn-login:hover,
        .btn-login:focus {
            background: linear-gradient(135deg, #164a85, #0f3664);
            border-color: transparent;
            color: #fff;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 18px 36px rgba(31, 95, 166, 0.35);
        }

        /* Card Animation */
        .auth-card {
            animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(30px);
        }

        @keyframes slideUpFade {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-footer {
            color: rgba(36, 48, 68, 0.68);
            font-size: 0.75rem;
            margin-top: 1.5rem;
            text-align: center;
        }

        @keyframes authPhotoDrift {
            from {
                transform: translate3d(-0.8%, -0.6%, 0) scale(1.04);
            }
            to {
                transform: translate3d(0.8%, 0.6%, 0) scale(1.08);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            body::before {
                animation: none;
            }
        }

        @media (max-width: 576px) {
            body {
                overflow-y: auto;
                padding: 1rem;
            }

            .auth-shell {
                min-height: calc(100vh - 2rem);
            }

            .auth-shell::before {
                width: 94vw;
            }

            .auth-card {
                border-radius: 18px;
                padding: 2rem 1.35rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Top Left Logo -->
    <div class="position-absolute top-0 start-0 ps-4 pt-3 d-flex align-items-center gap-3" style="z-index: 10;">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="height: 80px; width: auto; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1)); transform: translateY(-3px);">
        <img src="{{ asset('assets/images/text-adasi.png') }}" alt="PT. Astra Daido Steel Indonesia" style="height: 100px; width: auto; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
    </div>

    <main class="auth-shell">
        <div class="auth-card">
            @include('partials.alerts')
            @yield('content')
        </div>
    </main>

    {{-- Bootstrap 5 JS --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @yield('scripts')
    @stack('scripts')
</body>
</html>

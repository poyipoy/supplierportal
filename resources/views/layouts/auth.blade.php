<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Login - ADASI Supplier Portal')</title>

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
            --adasi-bg: #d5d5d5ff;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            align-items: center;
            background-color: var(--adasi-bg);
            display: flex;
            justify-content: center;
            min-height: 100vh;
        }

        .auth-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            max-width: 420px;
            padding: 2.5rem 2rem;
            width: 100%;
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
            color: #6c757d;
            font-size: 0.8rem;
            margin: 0;
        }

        .auth-logo img {
            display: block;
            margin: 0 auto 1rem;
        }

        .btn-login {
            background-color: var(--adasi-blue);
            border-color: var(--adasi-blue);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.6rem;
        }

        .btn-login:hover {
            background-color: var(--adasi-blue-dark);
            border-color: var(--adasi-blue-dark);
            color: #fff;
        }

        .form-control:focus {
            border-color: var(--adasi-blue);
            box-shadow: 0 0 0 0.2rem rgba(31, 95, 166, 0.15);
        }

        .auth-footer {
            color: #adb5bd;
            font-size: 0.75rem;
            margin-top: 1.5rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        @include('partials.alerts')
        @yield('content')
    </div>

    {{-- Bootstrap 5 JS --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

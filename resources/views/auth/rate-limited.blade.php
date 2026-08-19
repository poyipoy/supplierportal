@php
    $retryAfter = max(0, (int) ($retryAfter ?? 0));
    $waitLabel = $retryAfter > 60
        ? (int) ceil($retryAfter / 60).' '.((int) ceil($retryAfter / 60) === 1 ? 'minute' : 'minutes')
        : ($retryAfter > 0 ? $retryAfter.' '.($retryAfter === 1 ? 'second' : 'seconds') : 'a moment');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Please Wait | ADASI Supplier Portal</title>
    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --adasi-blue: #1F5FA6; --adasi-blue-dark: #174a85; --adasi-red: #C0392B; --auth-bg: #0f172a; --text: #172033; --muted: #667085; }
        * { box-sizing: border-box; }
        body { align-items: center; background: var(--auth-bg); color: var(--text); display: flex; font-family: 'Inter', sans-serif; justify-content: center; margin: 0; min-height: 100vh; overflow: hidden; padding: 1.5rem; position: relative; }
        body::before { animation: authPhotoDrift 22s ease-in-out infinite alternate; background: url('{{ asset('assets/images/adasi-login-bg.jpg') }}') center / cover no-repeat; content: ''; filter: blur(4px) saturate(1.08) contrast(0.96); inset: -18px; opacity: 0.95; pointer-events: none; position: fixed; transform: scale(1.04); z-index: 0; }
        body::after { background: linear-gradient(90deg, rgba(2, 6, 23, 0.72) 0%, rgba(31, 95, 166, 0.46) 48%, rgba(2, 6, 23, 0.34) 100%), linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, rgba(15, 23, 42, 0.44) 100%); content: ''; inset: 0; pointer-events: none; position: fixed; z-index: 0; }
        .rate-limit-card { -webkit-backdrop-filter: blur(18px) saturate(145%); backdrop-filter: blur(18px) saturate(145%); background: rgba(255, 255, 255, 0.78); border: 1px solid rgba(255, 255, 255, 0.72); border-radius: 22px; box-shadow: 0 28px 90px rgba(2, 6, 23, 0.34), inset 0 1px 0 rgba(255, 255, 255, 0.72); max-width: 540px; overflow: hidden; padding: 2.5rem; position: relative; text-align: center; width: 100%; z-index: 1; }
        .rate-limit-card::before { background: linear-gradient(90deg, var(--adasi-blue), var(--adasi-red)); content: ''; height: 5px; inset: 0 0 auto; position: absolute; }
        .brand { margin-bottom: 1.8rem; } .brand img { height: 56px; width: auto; }
        .status-code { color: var(--adasi-red); font-size: 0.78rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
        .status-icon { align-items: center; background: rgba(31, 95, 166, 0.1); border: 1px solid rgba(31, 95, 166, 0.14); border-radius: 50%; color: var(--adasi-blue); display: inline-flex; font-size: 2rem; height: 78px; justify-content: center; margin: 1rem 0 1.25rem; width: 78px; }
        h1 { font-size: clamp(1.45rem, 4vw, 1.9rem); letter-spacing: -0.03em; margin: 0 0 0.75rem; }
        p { color: var(--muted); font-size: 0.98rem; line-height: 1.65; margin: 0 auto; max-width: 420px; }
        .wait-message { background: #f7fafc; border: 1px solid #e5edf5; border-radius: 12px; color: #40516a; font-size: 0.9rem; margin: 1.5rem 0 1.75rem; padding: 0.9rem 1rem; } .wait-message strong { color: var(--adasi-blue-dark); }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; }
        .button { align-items: center; border-radius: 10px; display: inline-flex; font-size: 0.92rem; font-weight: 600; gap: 0.5rem; justify-content: center; min-height: 44px; padding: 0.65rem 1rem; text-decoration: none; transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease; }
        .button-primary { background: linear-gradient(135deg, var(--adasi-blue), var(--adasi-blue-dark)); box-shadow: 0 10px 22px rgba(31, 95, 166, 0.22); color: #fff; } .button-primary:hover, .button-primary:focus { box-shadow: 0 14px 28px rgba(31, 95, 166, 0.3); color: #fff; transform: translateY(-1px); }
        .button-secondary { background: #fff; border: 1px solid #d7e0ea; color: #40516a; } .button-secondary:hover, .button-secondary:focus { background: #f7fafc; color: var(--adasi-blue-dark); }
        .support-note { color: #8a97aa; font-size: 0.76rem; margin-top: 1.5rem; }
        @keyframes authPhotoDrift { from { transform: translate3d(-0.8%, -0.6%, 0) scale(1.04); } to { transform: translate3d(0.8%, 0.6%, 0) scale(1.08); } }
        @media (prefers-reduced-motion: reduce) { body::before { animation: none; } }
        @media (max-width: 480px) { body { overflow-y: auto; padding: 1rem; } .rate-limit-card { border-radius: 18px; padding: 2rem 1.35rem; } .actions { flex-direction: column-reverse; } .button { width: 100%; } }
    </style>
</head>
<body>
    <main class="rate-limit-card" aria-labelledby="rate-limit-title">
        <div class="brand"><img src="{{ asset('assets/images/logo-adasi.png') }}" alt="ADASI"></div>
        <div class="status-code">429 &middot; Request Limited</div>
        <div class="status-icon" aria-hidden="true"><i class="bi bi-shield-exclamation"></i></div>
        <h1 id="rate-limit-title">Please wait a moment</h1>
        <p>To help protect your account, this action is temporarily limited because too many requests were made.</p>
        <div class="wait-message" role="status">
            Please wait <strong id="retry-countdown" data-seconds="{{ $retryAfter }}">{{ $waitLabel }}</strong> before trying again.
        </div>
        <div class="actions">
            <button type="button" class="button button-secondary" id="go-back"><i class="bi bi-arrow-left"></i> Go Back</button>
            <a class="button button-primary" href="{{ $returnUrl }}"><i class="bi bi-person-circle"></i> {{ $returnLabel }}</a>
        </div>
        <p class="support-note">If the issue continues, please contact your system administrator.</p>
    </main>
    <script>
        const countdown = document.getElementById('retry-countdown');
        let seconds = Number(countdown.dataset.seconds || 0);
        const formatWait = (value) => value > 60 ? `${Math.ceil(value / 60)} ${Math.ceil(value / 60) === 1 ? 'minute' : 'minutes'}` : value > 0 ? `${value} ${value === 1 ? 'second' : 'seconds'}` : 'a moment';
        if (seconds > 0) {
            window.setInterval(() => {
                seconds = Math.max(0, seconds - 1);
                countdown.textContent = formatWait(seconds);
            }, 1000);
        }
        document.getElementById('go-back').addEventListener('click', () => {
            if (window.history.length > 1) { window.history.back(); return; }
            window.location.assign(@json($returnUrl));
        });
    </script>
</body>
</html>

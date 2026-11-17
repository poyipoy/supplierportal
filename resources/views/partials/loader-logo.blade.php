{{-- Logo loader ADASI dan token durasi navigasi UI (page fade & slow loader threshold).
     Wajib di-include setiap layout/view yang memuat resources/js/app.js (dijaga tests/Feature/FrontendAssetLoadingTest.php).
     Logika overlay: resources/js/adasi-loader.js; gaya: resources/css/app.css. Asset tetap lewat asset() agar aman di deploy subfolder. --}}
@php
    $uiPageFadeMs = max(0, (int) config('ui.navigation.page_fade_ms', 500));
    $uiSlowLoaderThresholdMs = max(0, (int) config('ui.navigation.slow_loader_threshold_ms', 2500));
@endphp
<style>:root { --adasi-loader-logo: url('{{ asset('assets/images/logo-adasi.png') }}'); --ui-page-fade-duration: {{ $uiPageFadeMs }}ms; }</style>
<script>
    window.AdasiUiConfig = Object.freeze({
        pageFadeMs: {{ $uiPageFadeMs }},
        slowLoaderThresholdMs: {{ $uiSlowLoaderThresholdMs }},
    });
</script>

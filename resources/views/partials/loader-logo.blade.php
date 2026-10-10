{{-- Logo loader ADASI. Wajib di-include setiap layout/view yang memuat resources/js/app.js
     (dijaga tests/Feature/FrontendAssetLoadingTest.php). Logika overlay: resources/js/adasi-loader.js;
     gaya: .adasi-loader-logo di resources/css/app.css. Asset tetap lewat asset() agar aman di deploy subfolder. --}}
<style>:root { --adasi-loader-logo: url('{{ asset('assets/images/logo-adasi.png') }}'); }</style>

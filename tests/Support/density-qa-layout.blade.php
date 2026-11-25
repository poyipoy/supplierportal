<!doctype html>
<html lang="en" data-density="comfortable" data-theme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Density QA — rendered production components</title>
@vite(['resources/css/app.css'])
</head><body>
<aside class="sidebar"><nav class="sidebar-menu"><x-ui.sidebar-item href="#fixture" icon="package">Fixture navigation</x-ui.sidebar-item></nav></aside>
<div class="main-wrapper"><main class="content-area" id="main-content">@yield('content')</main></div>
<script>
// Metadata-only fixture adapter. No authentication, persistence, or application requests.
window.AdasiPreferences = Object.freeze({density:'comfortable',previewDensity(value){if(['spacious','comfortable','balanced','compact','dense'].includes(value))document.documentElement.dataset.density=value;}});
</script>
@stack('scripts')
</body></html>

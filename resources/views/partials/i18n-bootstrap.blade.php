@php
    $adasiI18nPath = public_path('assets/js/adasi-i18n.js');
    $adasiI18nInline = null;
    $adasiI18nVersion = null;
    try {
        if (\Illuminate\Support\Facades\File::isReadable($adasiI18nPath)) {
            $adasiI18nInline = \Illuminate\Support\Facades\File::get($adasiI18nPath);
        }
    } catch (\Throwable) {
        $adasiI18nInline = null;
    }
    if (! is_string($adasiI18nInline) || $adasiI18nInline === '') {
        $adasiI18nVersion = is_file($adasiI18nPath) ? @filemtime($adasiI18nPath) : null;
    }
@endphp
<script id="adasi-i18n" type="application/json">{!! json_encode(\App\Support\JsTranslations::payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
@if (is_string($adasiI18nInline) && $adasiI18nInline !== '')
    <script data-adasi-i18n-runtime>{!! $adasiI18nInline !!}</script>
@else
    <script src="{{ asset('assets/js/adasi-i18n.js') }}{{ $adasiI18nVersion ? '?v='.$adasiI18nVersion : '' }}"></script>
@endif

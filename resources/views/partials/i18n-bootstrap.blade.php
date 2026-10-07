<script id="adasi-i18n" type="application/json">{!! json_encode(\App\Support\JsTranslations::payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
<script src="{{ asset('assets/js/adasi-i18n.js') }}?v={{ filemtime(public_path('assets/js/adasi-i18n.js')) }}"></script>

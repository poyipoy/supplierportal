<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-pagination-label="{{ __('pagination.navigation') }}">
<body>
    <a href="#main-content" class="ui-skip-link">{{ __('navigation.skip') }}</a>
    @include('partials.sidebar')
    @include('partials.navbar')
    @include('partials.workspace-session-bar')
    <main class="content-area workspace-stage" id="main-content" data-workspace-stage tabindex="-1">
        @include('partials.workspace-shortcuts-panel', ['isShortcutsInitial' => true])
    </main>
    @include('partials.chat-drawer')
    <x-ui.smart-panel />
    <x-ui.image-lightbox />
</body>
</html>

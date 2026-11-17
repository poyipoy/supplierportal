@extends('layouts.app')

@section('title', ($initialTitle ?? 'ADASI Portal').' - ADASI Portal')
@section('page-title', $initialTitle ?? 'Dashboard')

@php
    $isWorkspaceShell = true;
@endphp

@section('content')
    <div
        class="workspace-stage__progress"
        data-workspace-progress
        role="progressbar"
        aria-label="{{ __('navigation.workspace_shell.loading_module') }}"
        aria-valuetext="{{ __('navigation.workspace_shell.loading_module') }}"
        aria-hidden="false"
    >
        <span class="workspace-stage__progress-bar"></span>
    </div>

    <div class="workspace-stage__frames" data-workspace-frames>
        {{-- Permanent native Shortcuts & Frequently Used fallback panel --}}
        <section
            class="workspace-panel workspace-shortcuts-panel"
            id="workspace-panel-shortcuts"
            role="tabpanel"
            aria-labelledby="workspace-tab-shortcuts"
            hidden
            data-workspace-panel="shortcuts"
        >
            <div class="workspace-shortcuts__container">
                <header class="workspace-shortcuts__header">
                    <div class="workspace-shortcuts__title-group">
                        <h1 class="workspace-shortcuts__title">{{ __('navigation.workspace_shell.frequently_used') }}</h1>
                    </div>
                    <div class="workspace-shortcuts__search-wrap">
                        <label for="workspaceShortcutsSearch" class="visually-hidden">
                            {{ __('navigation.workspace_shell.search_shortcuts') }}
                        </label>
                        <span class="workspace-shortcuts__search-icon" aria-hidden="true">
                            <x-ui.icon name="search" size="xs" />
                        </span>
                        <input
                            id="workspaceShortcutsSearch"
                            type="search"
                            class="form-control form-control-sm workspace-shortcuts__search"
                            placeholder="{{ __('navigation.workspace_shell.search_shortcuts') }}"
                            autocomplete="off"
                            data-shortcuts-search
                        >
                        <button
                            type="button"
                            class="workspace-shortcuts__clear ui-focus-ring"
                            hidden
                            aria-label="{{ __('navigation.workspace_shell.clear_search') }}"
                            title="{{ __('navigation.workspace_shell.clear_search') }}"
                            data-shortcuts-clear
                        >
                            <x-ui.icon name="x" size="xs" />
                        </button>
                    </div>
                </header>

                <div class="workspace-shortcuts__body">
                    <div class="workspace-shortcuts__section" data-shortcuts-frequent-section>
                        <ul class="workspace-shortcuts__list" data-shortcuts-frequent data-locale-ignore></ul>
                    </div>

                    <div class="workspace-shortcuts__section" data-shortcuts-catalog-section>
                        <h2 class="workspace-shortcuts__section-title">{{ __('navigation.workspace_shell.all_modules') }}</h2>
                        <div class="workspace-shortcuts__groups" data-shortcuts-catalog data-locale-ignore></div>
                    </div>

                    <div class="workspace-shortcuts__empty" hidden data-shortcuts-empty>
                        <p class="workspace-shortcuts__empty-text">{{ __('navigation.workspace_shell.no_shortcuts_match') }}</p>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-shortcuts-empty-clear>
                            {{ __('navigation.workspace_shell.clear_search') }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        {{-- Initial SSR active keep-alive iframe panel --}}
        <div
            class="workspace-panel is-active"
            id="workspace-panel-initial"
            role="tabpanel"
            aria-labelledby="workspace-tab-initial"
            aria-busy="true"
            data-workspace-panel="initial"
        >
            <iframe
                class="workspace-frame"
                name="workspace-frame-initial"
                src="{{ $initialEmbedUrl }}"
                title="{{ $initialTitle ?? 'ADASI Portal' }}"
                loading="eager"
                referrerpolicy="same-origin"
                data-workspace-frame="initial"
            ></iframe>
        </div>
    </div>
@endsection

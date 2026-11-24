@extends('layouts.app')

@section('title', ($initialTitle ?? 'ADASI Portal').' - ADASI Portal')
@section('page-title', $initialTitle ?? 'Dashboard')

@php
    $isWorkspaceShell = true;
    $isShortcutsInitial = ($initialMode ?? 'frame') === 'shortcuts' || empty($initialEmbedUrl);
@endphp

@section('content')
    <div
        class="workspace-stage__progress"
        data-workspace-progress
        role="progressbar"
        aria-label="{{ __('navigation.workspace_shell.loading_module') }}"
        aria-valuetext="{{ __('navigation.workspace_shell.loading_module') }}"
        aria-hidden="{{ $isShortcutsInitial ? 'true' : 'false' }}"
    >
        <span class="workspace-stage__progress-bar"></span>
    </div>

    <div class="workspace-stage__frames" data-workspace-frames>
        {{-- Permanent native Infor LN-style 3-pane Shortcuts panel with dual vertical splitters --}}
        @php
            $shortcutsPanes = [
                [
                    'key' => 'favorites',
                    'title' => __('navigation.workspace_shell.favorites'),
                    'defaultWeight' => 32,
                    'splitterAfter' => 'favorites-bookmarks',
                    'nextKey' => 'bookmarks',
                    'nextTitle' => __('navigation.workspace_shell.bookmarks'),
                ],
                [
                    'key' => 'bookmarks',
                    'title' => __('navigation.workspace_shell.bookmarks'),
                    'defaultWeight' => 32,
                    'splitterAfter' => 'bookmarks-frequent',
                    'nextKey' => 'frequent',
                    'nextTitle' => __('navigation.workspace_shell.frequently_used'),
                ],
                [
                    'key' => 'frequent',
                    'title' => __('navigation.workspace_shell.frequently_used'),
                    'defaultWeight' => 36,
                    'splitterAfter' => null,
                    'nextKey' => null,
                    'nextTitle' => null,
                ],
            ];
        @endphp
        <section
            class="workspace-panel workspace-shortcuts-panel {{ $isShortcutsInitial ? 'is-active' : '' }}"
            id="workspace-panel-shortcuts"
            role="tabpanel"
            aria-labelledby="workspace-session-shortcuts"
            @unless($isShortcutsInitial) hidden @endunless
            data-workspace-panel="shortcuts"
        >
            <div class="workspace-shortcuts-board" data-shortcuts-board>
                @foreach($shortcutsPanes as $pane)
                    @php($minimizeLabel = __('navigation.workspace_shell.minimize_pane', ['pane' => $pane['title']]))
                    <section
                        class="workspace-shortcuts-pane"
                        id="workspace-shortcuts-pane-{{ $pane['key'] }}"
                        aria-labelledby="workspace-shortcuts-title-{{ $pane['key'] }}"
                        data-shortcuts-pane="{{ $pane['key'] }}"
                        style="--shortcuts-pane-weight: {{ $pane['defaultWeight'] }};"
                    >
                        <header class="workspace-shortcuts-pane__header">
                            <h2 class="workspace-shortcuts-pane__title" id="workspace-shortcuts-title-{{ $pane['key'] }}">
                                {{ $pane['title'] }}
                            </h2>
                            <button
                                type="button"
                                class="workspace-shortcuts-pane__toggle ui-focus-ring"
                                aria-expanded="true"
                                aria-controls="workspace-shortcuts-body-{{ $pane['key'] }}"
                                aria-label="{{ $minimizeLabel }}"
                                title="{{ $minimizeLabel }}"
                                data-shortcuts-pane-toggle="{{ $pane['key'] }}"
                            >
                                <span class="workspace-shortcuts-pane__toggle-icon" aria-hidden="true">
                                    <x-ui.icon name="minimize-2" size="xs" />
                                </span>
                            </button>
                        </header>
                        <div class="workspace-shortcuts-pane__body" id="workspace-shortcuts-body-{{ $pane['key'] }}">
                            <ul
                                class="workspace-shortcuts__list"
                                data-shortcuts-{{ $pane['key'] }}
                                data-locale-ignore
                            ></ul>
                        </div>
                    </section>

                    @if($pane['splitterAfter'])
                        @php($resizePairLabel = __('navigation.workspace_shell.resize_pane_pair', ['left' => $pane['title'], 'right' => $pane['nextTitle']]))
                        <x-ui.splitter
                            role="separator"
                            class="workspace-shortcuts-splitter"
                            line-class="workspace-shortcuts-splitter__line"
                            grip-class="workspace-shortcuts-splitter__grip"
                            :controls="'workspace-shortcuts-pane-'.$pane['key'].' workspace-shortcuts-pane-'.$pane['nextKey']"
                            :label="$resizePairLabel"
                            :data-shortcuts-splitter="$pane['splitterAfter']"
                        />
                    @endif
                @endforeach
            </div>

            <div
                class="workspace-shortcuts-dock"
                role="toolbar"
                aria-label="{{ __('navigation.workspace_shell.minimized_panels') }}"
                hidden
                data-shortcuts-dock
            >
                @foreach($shortcutsPanes as $pane)
                    @php($expandLabel = __('navigation.workspace_shell.expand_pane', ['pane' => $pane['title']]))
                    <button
                        type="button"
                        class="workspace-shortcuts-dock__item ui-focus-ring"
                        hidden
                        aria-label="{{ $expandLabel }}"
                        title="{{ $expandLabel }}"
                        data-shortcuts-pane-restore="{{ $pane['key'] }}"
                    >
                        <span class="workspace-shortcuts-dock__icon" aria-hidden="true">
                            <x-ui.icon name="maximize-2" size="xs" />
                        </span>
                        <span class="workspace-shortcuts-dock__label">{{ $pane['title'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        @if(! $isShortcutsInitial && ! empty($initialEmbedUrl))
            {{-- Initial SSR active keep-alive iframe panel --}}
            <div
                class="workspace-panel is-active"
                id="workspace-panel-initial"
                role="tabpanel"
                aria-labelledby="workspace-session-initial"
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
        @endif
    </div>
@endsection

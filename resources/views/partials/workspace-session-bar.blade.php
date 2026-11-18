@php
    $shortcutsSession = $workspaceConfig['shortcutsSession'] ?? $workspaceConfig['shortcutsTab'] ?? [
        'key' => 'shortcuts',
        'label' => __('navigation.workspace_shell.shortcuts'),
        'pinned' => true,
        'closable' => false,
    ];
    $initialSession = $workspaceConfig['initialSession'] ?? $workspaceConfig['initialTab'] ?? [
        'key' => $initialModuleKey ?? 'dashboard',
        'label' => $initialTitle ?? 'Dashboard',
        'url' => $initialCleanUrl ?? request()->fullUrl(),
        'embedUrl' => $initialEmbedUrl ?? \App\Support\WorkspaceShell::appendEmbedParam(request()->fullUrl()),
        'pinned' => false,
        'closable' => true,
    ];
@endphp

<div
    class="workspace-sessionbar"
    data-workspace-sessionbar
    role="region"
    aria-label="{{ __('navigation.workspace_shell.sessions_label') }}"
>
    <button
        type="button"
        class="workspace-sessionbar__sidebar-toggle ui-focus-ring"
        x-on:click="$dispatch('ui-sidebar-toggle', { trigger: $el })"
        x-bind:aria-label="sidebarToggleLabel"
        x-bind:title="sidebarToggleLabel"
        x-bind:aria-expanded="viewportIsDesktop ? (!desktopCollapsed).toString() : mobileOpen.toString()"
        aria-controls="sidebar"
        data-workspace-sidebar-toggle
    >
        <x-ui.icon name="menu" size="sm" />
    </button>

    {{-- Permanent fixed Shortcuts fallback session --}}
    <div
        id="workspace-session-group-shortcuts"
        class="workspace-session-group workspace-session-group--shortcuts"
        role="presentation"
        data-workspace-group="shortcuts"
        data-workspace-module="shortcuts"
        data-pinned="true"
        data-locale-ignore
    >
        <div
            class="workspace-session workspace-session--shortcuts"
            role="presentation"
            data-workspace-session="shortcuts"
            data-dirty="false"
            data-stale="false"
            data-pinned="true"
        >
            <button
                type="button"
                role="tab"
                id="workspace-session-shortcuts"
                class="workspace-session__trigger ui-focus-ring"
                aria-selected="false"
                aria-controls="workspace-panel-shortcuts"
                tabindex="-1"
                data-workspace-shortcuts-select
            >
                <span class="workspace-session__icon" aria-hidden="true">
                    <x-ui.icon name="bookmark" size="xs" />
                </span>
                <span class="workspace-session__label">{{ $shortcutsSession['label'] }}</span>
            </button>
        </div>
    </div>

    <button
        type="button"
        class="workspace-sessionbar__scroll-btn ui-focus-ring"
        hidden
        aria-label="{{ __('navigation.workspace_shell.scroll_sessions_left') }}"
        title="{{ __('navigation.workspace_shell.scroll_sessions_left') }}"
        data-workspace-scroll="prev"
    >
        <x-ui.icon name="chevron-left" size="xs" />
    </button>

    <div class="workspace-sessionbar__viewport" data-workspace-session-viewport>
        <div
            class="workspace-sessionbar__list"
            role="tablist"
            aria-label="{{ __('navigation.workspace_shell.sessions_label') }}"
            aria-owns="workspace-session-group-shortcuts"
            data-workspace-sessionlist
            data-locale-ignore
        >
        {{-- Initial SSR active workspace session group --}}
        <div
            class="workspace-session-group is-active"
            role="presentation"
            data-workspace-group="initial"
            data-workspace-module="{{ $initialSession['key'] }}"
            data-pinned="false"
        >
            <div
                class="workspace-session is-active"
                role="presentation"
                data-workspace-session="initial"
                data-dirty="false"
                data-stale="false"
                data-hibernated="false"
                data-queued="false"
                data-loading="false"
                data-pinned="false"
            >
                <button
                    type="button"
                    role="tab"
                    id="workspace-session-initial"
                    class="workspace-session__trigger ui-focus-ring"
                    aria-selected="true"
                    aria-controls="workspace-panel-initial"
                    tabindex="0"
                    data-workspace-session-select="initial"
                >
                    <span class="workspace-session__status-dot" aria-hidden="true"></span>
                    <span class="workspace-session__label">{{ $initialSession['label'] }}</span>
                </button>

                <button
                    type="button"
                    class="workspace-session__close ui-focus-ring"
                    aria-label="{{ __('navigation.workspace_shell.close_session') }}: {{ $initialSession['label'] }}"
                    title="{{ __('navigation.workspace_shell.close_session') }}"
                    data-workspace-session-close="initial"
                >
                    <x-ui.icon name="x" size="xs" />
                </button>
            </div>
        </div>
        </div>
    </div>

    <button
        type="button"
        class="workspace-sessionbar__scroll-btn ui-focus-ring"
        hidden
        aria-label="{{ __('navigation.workspace_shell.scroll_sessions_right') }}"
        title="{{ __('navigation.workspace_shell.scroll_sessions_right') }}"
        data-workspace-scroll="next"
    >
        <x-ui.icon name="chevron-right" size="xs" />
    </button>

    <div class="workspace-sessionbar__controls">
        <button
            type="button"
            class="workspace-sessionbar__stale-pill ui-focus-ring"
            hidden
            title="{{ __('navigation.workspace_shell.stale_hint') }}"
            data-workspace-stale-action
        >
            <x-ui.icon name="refresh-cw" size="xs" />
            <span>{{ __('navigation.workspace_shell.stale_badge') }}</span>
        </button>

        <button
            type="button"
            class="workspace-sessionbar__switcher ui-focus-ring"
            hidden
            aria-haspopup="menu"
            aria-expanded="false"
            aria-label="{{ __('navigation.workspace_shell.open_workspaces') }}"
            title="{{ __('navigation.workspace_shell.open_workspaces') }}"
            data-workspace-switcher
        >
            <x-ui.icon name="layers" size="xs" />
            <span class="workspace-sessionbar__switcher-count" data-workspace-switcher-count>1</span>
        </button>

        <button
            type="button"
            class="workspace-sessionbar__refresh ui-focus-ring"
            aria-label="{{ __('navigation.workspace_shell.refresh_session') }}"
            title="{{ __('navigation.workspace_shell.refresh_session') }}"
            data-workspace-refresh
        >
            <x-ui.icon name="refresh-cw" size="sm" />
        </button>
    </div>

    <div
        class="workspace-session-menu"
        hidden
        role="menu"
        aria-label="{{ __('navigation.workspace_shell.session_actions') }}"
        data-workspace-session-menu
        data-locale-ignore
    ></div>

    <template data-workspace-icon="x">
        <x-ui.icon name="x" size="xs" />
    </template>
    <template data-workspace-icon="layers">
        <x-ui.icon name="layers" size="xs" />
    </template>
    <template data-workspace-icon="square-stack">
        <x-ui.icon name="square-stack" size="xs" />
    </template>
    <template data-workspace-icon="chevron-down">
        <x-ui.icon name="chevron-down" size="xs" />
    </template>
    <template data-workspace-icon="chevron-right">
        <x-ui.icon name="chevron-right" size="xs" />
    </template>
    <template data-workspace-icon="copy">
        <x-ui.icon name="copy" size="xs" />
    </template>
    <template data-workspace-icon="refresh-cw">
        <x-ui.icon name="refresh-cw" size="xs" />
    </template>
    <template data-workspace-icon="external-link">
        <x-ui.icon name="external-link" size="xs" />
    </template>
    <template data-workspace-icon="compass">
        <x-ui.icon name="compass" size="sm" />
    </template>
    <template data-workspace-icon="star">
        <x-ui.icon name="star" size="xs" />
    </template>
    <template data-workspace-icon="bookmark">
        <x-ui.icon name="bookmark" size="xs" />
    </template>

    <span
        class="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-workspace-live-region
    ></span>
</div>

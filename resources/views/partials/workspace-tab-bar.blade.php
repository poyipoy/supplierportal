@php
    $shortcutsTab = $workspaceConfig['shortcutsTab'] ?? [
        'key' => 'shortcuts',
        'label' => __('navigation.workspace_shell.shortcuts'),
        'pinned' => true,
        'closable' => false,
    ];
    $initialTab = $workspaceConfig['initialTab'] ?? [
        'key' => $initialModuleKey ?? 'dashboard',
        'label' => $initialTitle ?? 'Dashboard',
        'url' => $initialCleanUrl ?? request()->fullUrl(),
        'embedUrl' => $initialEmbedUrl ?? \App\Support\WorkspaceShell::appendEmbedParam(request()->fullUrl()),
        'pinned' => false,
        'closable' => true,
    ];
@endphp

<div
    class="workspace-tabbar"
    data-workspace-tabbar
    role="region"
    aria-label="{{ __('navigation.workspace_shell.tabs_label') }}"
>
    {{-- Permanent fixed Shortcuts fallback tab --}}
    <div
        id="workspace-tab-group-shortcuts"
        class="workspace-tab-group workspace-tab-group--shortcuts"
        role="presentation"
        data-workspace-group="shortcuts"
        data-workspace-module="shortcuts"
        data-pinned="true"
        data-locale-ignore
    >
        <div
            class="workspace-tab workspace-tab--shortcuts"
            role="presentation"
            data-workspace-tab="shortcuts"
            data-dirty="false"
            data-stale="false"
            data-pinned="true"
        >
            <button
                type="button"
                role="tab"
                id="workspace-tab-shortcuts"
                class="workspace-tab__trigger ui-focus-ring"
                aria-selected="false"
                aria-controls="workspace-panel-shortcuts"
                tabindex="-1"
                data-workspace-shortcuts-select
            >
                <span class="workspace-tab__icon" aria-hidden="true">
                    <x-ui.icon name="bookmark" size="xs" />
                </span>
                <span class="workspace-tab__label">{{ $shortcutsTab['label'] }}</span>
            </button>
        </div>
    </div>

    <button
        type="button"
        class="workspace-tabbar__scroll-btn ui-focus-ring"
        hidden
        aria-label="{{ __('navigation.workspace_shell.scroll_tabs_left') }}"
        title="{{ __('navigation.workspace_shell.scroll_tabs_left') }}"
        data-workspace-scroll="prev"
    >
        <x-ui.icon name="chevron-left" size="xs" />
    </button>

    <div class="workspace-tabbar__viewport" data-workspace-viewport>
        <div
            class="workspace-tabbar__list"
            role="tablist"
            aria-label="{{ __('navigation.workspace_shell.tabs_label') }}"
            aria-owns="workspace-tab-group-shortcuts"
            data-workspace-tablist
            data-locale-ignore
        >
        {{-- Initial SSR active workspace group --}}
        <div
            class="workspace-tab-group is-active"
            role="presentation"
            data-workspace-group="initial"
            data-workspace-module="{{ $initialTab['key'] }}"
            data-pinned="false"
        >
            <div
                class="workspace-tab is-active"
                role="presentation"
                data-workspace-tab="initial"
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
                    id="workspace-tab-initial"
                    class="workspace-tab__trigger ui-focus-ring"
                    aria-selected="true"
                    aria-controls="workspace-panel-initial"
                    tabindex="0"
                    data-workspace-tab-select="initial"
                >
                    <span class="workspace-tab__status-dot" aria-hidden="true"></span>
                    <span class="workspace-tab__label">{{ $initialTab['label'] }}</span>
                </button>

                <button
                    type="button"
                    class="workspace-tab__close ui-focus-ring"
                    aria-label="{{ __('navigation.workspace_shell.close_tab') }}: {{ $initialTab['label'] }}"
                    title="{{ __('navigation.workspace_shell.close_tab') }}"
                    data-workspace-tab-close="initial"
                >
                    <x-ui.icon name="x" size="xs" />
                </button>
            </div>
        </div>
        </div>
    </div>

    <button
        type="button"
        class="workspace-tabbar__scroll-btn ui-focus-ring"
        hidden
        aria-label="{{ __('navigation.workspace_shell.scroll_tabs_right') }}"
        title="{{ __('navigation.workspace_shell.scroll_tabs_right') }}"
        data-workspace-scroll="next"
    >
        <x-ui.icon name="chevron-right" size="xs" />
    </button>

    <div class="workspace-tabbar__controls">
        <button
            type="button"
            class="workspace-tabbar__stale-pill ui-focus-ring"
            hidden
            title="{{ __('navigation.workspace_shell.stale_hint') }}"
            data-workspace-stale-action
        >
            <x-ui.icon name="refresh-cw" size="xs" />
            <span>{{ __('navigation.workspace_shell.stale_badge') }}</span>
        </button>

        <button
            type="button"
            class="workspace-tabbar__switcher ui-focus-ring"
            hidden
            aria-haspopup="menu"
            aria-expanded="false"
            aria-label="{{ __('navigation.workspace_shell.open_workspaces') }}"
            title="{{ __('navigation.workspace_shell.open_workspaces') }}"
            data-workspace-switcher
        >
            <x-ui.icon name="layers" size="xs" />
            <span class="workspace-tabbar__switcher-count" data-workspace-switcher-count>1</span>
        </button>

        <button
            type="button"
            class="workspace-tabbar__refresh ui-focus-ring"
            aria-label="{{ __('navigation.workspace_shell.refresh_tab') }}"
            title="{{ __('navigation.workspace_shell.refresh_tab') }}"
            data-workspace-refresh
        >
            <x-ui.icon name="refresh-cw" size="sm" />
        </button>
    </div>

    <div
        class="workspace-tab-menu"
        hidden
        role="menu"
        aria-label="{{ __('navigation.workspace_shell.tab_actions') }}"
        data-workspace-tab-menu
        data-locale-ignore
    ></div>

    <template data-workspace-icon="x">
        <x-ui.icon name="x" size="xs" />
    </template>
    <template data-workspace-icon="layers">
        <x-ui.icon name="layers" size="xs" />
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

    <span
        class="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-workspace-live-region
    ></span>
</div>

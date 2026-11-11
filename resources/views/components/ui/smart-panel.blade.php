@auth
<div
    id="adasi-smart-panel"
    class="tw:fixed tw:inset-0 tw:z-drawer tw:hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="smart-panel-title"
    data-smart-panel
>
    {{-- Backdrop --}}
    <div
        class="ui-scrim tw:fixed tw:inset-0 tw:transition-opacity"
        data-smart-panel-close
        aria-hidden="true"
    ></div>

    {{-- Slide-over Panel --}}
    <div class="tw:fixed tw:inset-y-0 tw:end-0 tw:flex tw:max-w-full tw:ps-0 tw:sm:ps-6">
        <div
            id="smart-panel-dialog"
            tabindex="-1"
            class="tw:w-screen tw:max-w-xl tw:bg-surface tw:border-s tw:border-outline-variant tw:shadow-ui-2 tw:flex tw:flex-col tw:h-full tw:transition-all tw:duration-standard"
        >
            {{-- Header --}}
            <header class="tw:shrink-0 tw:flex tw:items-start tw:justify-between tw:gap-3 tw:px-4 tw:sm:px-5 tw:py-4 tw:border-b tw:border-outline-variant tw:bg-surface-container">
                <div>
                    <span id="smart-panel-subtitle" class="tw:block tw:text-[11px] tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">
                        {{ __('smart_panel.eyebrow') }}
                    </span>
                    <div class="tw:flex tw:items-center tw:gap-2 tw:mt-0.5">
                        <h2 id="smart-panel-title" class="tw:m-0 tw:text-ui-base tw:font-bold tw:text-on-surface ui-tabular-nums">
                            {{ __('smart_panel.title') }}
                        </h2>
                        <span id="smart-panel-status" class="tw:inline-flex tw:items-center tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-[11px] tw:font-semibold tw:bg-surface-high tw:text-on-surface-variant"></span>
                    </div>
                </div>
                <button
                    type="button"
                    class="ui-focus-ring tw:inline-flex tw:h-9 tw:w-9 tw:items-center tw:justify-center tw:rounded-ui-sm tw:text-on-surface-variant tw:hover:bg-surface-high tw:hover:text-on-surface"
                    data-smart-panel-close
                    aria-label="{{ __('smart_panel.close') }}"
                >
                    <x-ui.icon name="x" size="sm" />
                </button>
            </header>

            {{-- Tabs --}}
            <nav class="tw:shrink-0 tw:flex tw:flex-wrap tw:border-b tw:border-outline-variant tw:bg-surface tw:px-4 tw:sm:px-5" role="tablist" aria-label="{{ __('smart_panel.title') }}">
                <button
                    type="button"
                    id="smart-panel-tab-chain"
                    role="tab"
                    aria-selected="true"
                    aria-controls="smart-panel-pane-chain"
                    tabindex="0"
                    data-smart-panel-tab="chain"
                    class="ui-focus-ring tw:min-h-[36px] tw:py-2 tw:px-3 tw:text-ui-xs tw:font-semibold tw:border-b-2 tw:border-primary tw:text-primary"
                >
                    {{ __('smart_panel.tabs.chain') }}
                </button>
                <button
                    type="button"
                    id="smart-panel-tab-preview"
                    role="tab"
                    aria-selected="false"
                    aria-controls="smart-panel-pane-preview"
                    tabindex="-1"
                    data-smart-panel-tab="preview"
                    class="ui-focus-ring tw:min-h-[36px] tw:py-2 tw:px-3 tw:text-ui-xs tw:font-semibold tw:border-b-2 tw:border-transparent tw:text-on-surface-variant tw:hover:text-on-surface"
                >
                    {{ __('smart_panel.tabs.preview') }}
                </button>
                <button
                    type="button"
                    id="smart-panel-tab-sla"
                    role="tab"
                    aria-selected="false"
                    aria-controls="smart-panel-pane-sla"
                    tabindex="-1"
                    data-smart-panel-tab="sla"
                    class="ui-focus-ring tw:min-h-[36px] tw:py-2 tw:px-3 tw:text-ui-xs tw:font-semibold tw:border-b-2 tw:border-transparent tw:text-on-surface-variant tw:hover:text-on-surface"
                >
                    {{ __('smart_panel.tabs.sla') }}
                </button>
            </nav>

            {{-- Body --}}
            <div class="tw:min-h-0 tw:flex-1 tw:flex tw:flex-col tw:overflow-y-auto tw:p-3 tw:sm:p-5">
                {{-- Loading / Error state --}}
                <div id="smart-panel-loading" role="status" aria-live="polite" class="tw:py-8 tw:text-center tw:text-ui-xs tw:text-on-surface-variant">
                    {{ __('smart_panel.loading') }}
                </div>
                <div id="smart-panel-error" role="alert" class="tw:hidden tw:p-4 tw:rounded-ui-sm tw:bg-error/10 tw:text-error tw:text-ui-xs">
                    {{ __('smart_panel.error') }}
                </div>

                {{-- Tab 1: Document Chain --}}
                <div
                    id="smart-panel-pane-chain"
                    role="tabpanel"
                    aria-labelledby="smart-panel-tab-chain"
                    tabindex="0"
                    data-smart-panel-pane="chain"
                    class="ui-focus-ring tw:hidden tw:space-y-3"
                ></div>

                {{-- Tab 2: Split PDF Preview --}}
                <div
                    id="smart-panel-pane-preview"
                    role="tabpanel"
                    aria-labelledby="smart-panel-tab-preview"
                    tabindex="0"
                    data-smart-panel-pane="preview"
                    class="ui-focus-ring tw:hidden tw:flex-col tw:flex-1 tw:min-h-0 tw:gap-3"
                >
                    <div class="tw:shrink-0 tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                        <div id="smart-panel-doc-list" class="tw:flex tw:flex-wrap tw:gap-2"></div>
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                            <button
                                type="button"
                                id="smart-panel-expand-toggle"
                                aria-pressed="false"
                                aria-controls="smart-panel-dialog"
                                data-expand-label="{{ __('smart_panel.expand_preview') }}"
                                data-collapse-label="{{ __('smart_panel.collapse_preview') }}"
                                class="ui-focus-ring tw:min-h-[36px] tw:inline-flex tw:items-center tw:gap-1.5 tw:px-2.5 tw:py-1.5 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:text-ui-xs tw:font-medium tw:text-on-surface tw:hover:bg-surface-container"
                            >
                                <span data-smart-panel-expand-icon class="tw:inline-flex">
                                    <x-ui.icon name="maximize-2" size="xs" />
                                </span>
                                <span data-smart-panel-collapse-icon class="tw:hidden">
                                    <x-ui.icon name="minimize-2" size="xs" />
                                </span>
                                <span data-smart-panel-expand-text>{{ __('smart_panel.expand_preview') }}</span>
                            </button>
                            <a
                                id="smart-panel-open-external"
                                href="#"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ui-focus-ring tw:hidden tw:min-h-[36px] tw:inline-flex tw:items-center tw:gap-1.5 tw:px-2.5 tw:py-1.5 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:text-ui-xs tw:font-medium tw:text-primary tw:hover:bg-surface-container"
                            >
                                <x-ui.icon name="external-link" size="xs" />
                                <span>{{ __('smart_panel.open_in_new_tab') }}</span>
                            </a>
                        </div>
                    </div>
                    <div id="smart-panel-preview-frame-wrap" class="tw:hidden tw:flex-1 tw:min-h-[65vh] tw:rounded-ui-sm tw:border tw:border-outline-variant tw:overflow-hidden tw:bg-surface-low">
                        <iframe id="smart-panel-iframe" title="{{ __('smart_panel.tabs.preview') }}" class="tw:w-full tw:h-full tw:border-0"></iframe>
                    </div>
                    <div id="smart-panel-no-docs" class="tw:hidden tw:py-8 tw:text-center tw:text-ui-xs tw:text-on-surface-variant">
                        {{ __('smart_panel.empty_documents') }}
                    </div>
                </div>

                {{-- Tab 3: SLA & Status Summary --}}
                <div
                    id="smart-panel-pane-sla"
                    role="tabpanel"
                    aria-labelledby="smart-panel-tab-sla"
                    tabindex="0"
                    data-smart-panel-pane="sla"
                    class="ui-focus-ring tw:hidden tw:space-y-2.5"
                ></div>
            </div>
        </div>
    </div>
</div>
@endauth

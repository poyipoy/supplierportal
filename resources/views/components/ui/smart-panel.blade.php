@auth
<div
    id="adasi-smart-panel"
    class="tw:fixed tw:inset-0 tw:z-drawer tw:hidden tw:overscroll-contain"
    role="dialog"
    aria-modal="true"
    aria-labelledby="smart-panel-title"
    data-smart-panel
    data-default-title="{{ __('smart_panel.title') }}"
    data-default-subtitle="{{ __('smart_panel.eyebrow') }}"
    data-label-current="{{ __('smart_panel.current_badge') }}"
    data-label-read-only="{{ __('smart_panel.read_only_hint') }}"
    data-label-open-detail="{{ __('smart_panel.open_detail_label') }}"
    data-label-empty-chain="{{ __('smart_panel.empty_chain') }}"
    data-label-empty-sla="{{ __('smart_panel.empty_sla') }}"
    hidden
>
    {{-- Backdrop --}}
    <div
        class="ui-scrim tw:fixed tw:inset-0"
        data-smart-panel-close
        aria-hidden="true"
    ></div>

    {{-- Slide-over Panel --}}
    <div class="tw:fixed tw:inset-y-0 tw:end-0 tw:flex tw:max-w-full tw:ps-0 tw:sm:ps-6">
        <div
            id="smart-panel-dialog"
            tabindex="-1"
            class="ui-smart-panel-dialog tw:w-screen tw:max-w-xl tw:bg-dialog tw:border-s tw:border-outline tw:shadow-ui-2 tw:flex tw:flex-col tw:h-full"
        >
            {{-- Header --}}
            <header class="tw:shrink-0 tw:flex tw:items-start tw:justify-between tw:gap-3 tw:px-4 tw:sm:px-5 tw:py-3.5 tw:border-b tw:border-outline-variant">
                <div class="tw:min-w-0 tw:flex-1">
                    <span id="smart-panel-subtitle" class="tw:block tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">
                        {{ __('smart_panel.eyebrow') }}
                    </span>
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:mt-0.5">
                        <h2 id="smart-panel-title" class="tw:m-0 tw:text-ui-base tw:font-bold tw:text-on-surface ui-tabular-nums tw:break-words">
                            {{ __('smart_panel.title') }}
                        </h2>
                        <span id="smart-panel-status" class="ui-status-chip ui-status-chip--neutral tw:hidden" hidden></span>
                    </div>
                </div>
                <button
                    type="button"
                    class="ui-focus-ring ui-smart-panel-icon-btn tw:shrink-0 tw:inline-flex tw:h-11 tw:w-11 tw:items-center tw:justify-center tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container-high tw:hover:text-on-surface"
                    data-smart-panel-close
                    aria-label="{{ __('smart_panel.close') }}"
                >
                    <x-ui.icon name="x" size="sm" />
                </button>
            </header>

            {{-- Tabs --}}
            <nav class="tw:shrink-0 tw:flex tw:flex-wrap tw:gap-1 tw:border-b tw:border-outline-variant tw:px-4 tw:sm:px-5" role="tablist" aria-label="{{ __('smart_panel.title') }}">
                <button
                    type="button"
                    id="smart-panel-tab-chain"
                    role="tab"
                    aria-selected="true"
                    aria-controls="smart-panel-pane-chain"
                    tabindex="0"
                    data-smart-panel-tab="chain"
                    class="ui-focus-ring tw:inline-flex tw:items-center tw:gap-1.5 tw:min-h-[42px] tw:py-2 tw:px-3 tw:text-ui-xs tw:font-semibold tw:border-0 tw:border-b-2 tw:border-primary tw:bg-transparent tw:text-primary-text"
                >
                    <span>{{ __('smart_panel.tabs.chain') }}</span>
                    <span data-smart-panel-tab-count="chain" class="ui-smart-panel-tab-count ui-tabular-nums tw:hidden" hidden></span>
                </button>
                <button
                    type="button"
                    id="smart-panel-tab-preview"
                    role="tab"
                    aria-selected="false"
                    aria-controls="smart-panel-pane-preview"
                    tabindex="-1"
                    data-smart-panel-tab="preview"
                    class="ui-focus-ring tw:inline-flex tw:items-center tw:gap-1.5 tw:min-h-[42px] tw:py-2 tw:px-3 tw:text-ui-xs tw:font-semibold tw:border-0 tw:border-b-2 tw:border-transparent tw:bg-transparent tw:text-on-surface-variant tw:hover:text-on-surface"
                >
                    <span>{{ __('smart_panel.tabs.preview') }}</span>
                    <span data-smart-panel-tab-count="preview" class="ui-smart-panel-tab-count ui-tabular-nums tw:hidden" hidden></span>
                </button>
                <button
                    type="button"
                    id="smart-panel-tab-sla"
                    role="tab"
                    aria-selected="false"
                    aria-controls="smart-panel-pane-sla"
                    tabindex="-1"
                    data-smart-panel-tab="sla"
                    class="ui-focus-ring tw:inline-flex tw:items-center tw:gap-1.5 tw:min-h-[42px] tw:py-2 tw:px-3 tw:text-ui-xs tw:font-semibold tw:border-0 tw:border-b-2 tw:border-transparent tw:bg-transparent tw:text-on-surface-variant tw:hover:text-on-surface"
                >
                    <span>{{ __('smart_panel.tabs.sla') }}</span>
                    <span data-smart-panel-tab-count="sla" class="ui-smart-panel-tab-count ui-tabular-nums tw:hidden" hidden></span>
                </button>
            </nav>

            {{-- Body --}}
            <div class="tw:min-h-0 tw:flex-1 tw:flex tw:flex-col tw:overflow-y-auto tw:overscroll-contain tw:p-3 tw:sm:p-5">
                {{-- Loading / Error state --}}
                <x-ui.inline-loader id="smart-panel-loading" :label="__('smart_panel.loading')" class="tw:py-8 tw:hidden" hidden />
                <div id="smart-panel-error" role="alert" class="tw:hidden tw:p-4 tw:rounded-ui-md tw:border tw:border-error/40 tw:bg-error-container tw:text-error-container-foreground tw:text-ui-xs" hidden>
                    <div class="tw:flex tw:items-start tw:gap-2.5">
                        <x-ui.icon name="circle-alert" size="sm" class="tw:shrink-0 tw:mt-0.5" />
                        <div class="tw:min-w-0 tw:flex-1 tw:space-y-3">
                            <p class="tw:m-0 tw:text-ui-sm tw:font-semibold">{{ __('smart_panel.error') }}</p>
                            <x-ui.button
                                type="button"
                                id="smart-panel-retry"
                                variant="outline"
                                size="sm"
                                class="tw:min-h-[40px]"
                            >
                                <x-slot:leading><x-ui.icon name="refresh-cw" size="xs" /></x-slot:leading>
                                {{ __('smart_panel.retry') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>

                {{-- Tab 1: Document Chain --}}
                <div
                    id="smart-panel-pane-chain"
                    role="tabpanel"
                    aria-labelledby="smart-panel-tab-chain"
                    tabindex="0"
                    data-smart-panel-pane="chain"
                    class="ui-focus-ring tw:hidden tw:space-y-3"
                    hidden
                ></div>

                {{-- Tab 2: Split PDF Preview --}}
                <div
                    id="smart-panel-pane-preview"
                    role="tabpanel"
                    aria-labelledby="smart-panel-tab-preview"
                    tabindex="0"
                    data-smart-panel-pane="preview"
                    class="ui-focus-ring tw:hidden tw:flex-col tw:flex-1 tw:min-h-0 tw:gap-3"
                    hidden
                >
                    <div class="tw:shrink-0 tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                        <div id="smart-panel-doc-list" class="tw:flex tw:flex-wrap tw:gap-2 tw:min-w-0"></div>
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                            <button
                                type="button"
                                id="smart-panel-expand-toggle"
                                aria-pressed="false"
                                aria-controls="smart-panel-dialog"
                                data-expand-label="{{ __('smart_panel.expand_preview') }}"
                                data-collapse-label="{{ __('smart_panel.collapse_preview') }}"
                                class="ui-focus-ring tw:min-h-[40px] tw:inline-flex tw:items-center tw:gap-1.5 tw:px-3 tw:py-1.5 tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:hover:bg-surface-container"
                            >
                                <span data-smart-panel-expand-icon class="tw:inline-flex">
                                    <x-ui.icon name="maximize-2" size="xs" />
                                </span>
                                <span data-smart-panel-collapse-icon class="tw:hidden" hidden>
                                    <x-ui.icon name="minimize-2" size="xs" />
                                </span>
                                <span data-smart-panel-expand-text>{{ __('smart_panel.expand_preview') }}</span>
                            </button>
                            <a
                                id="smart-panel-open-external"
                                href="#"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ui-focus-ring tw:hidden tw:min-h-[40px] tw:inline-flex tw:items-center tw:gap-1.5 tw:px-3 tw:py-1.5 tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-ui-xs tw:font-semibold tw:text-primary-text tw:hover:bg-surface-container"
                                hidden
                            >
                                <x-ui.icon name="external-link" size="xs" />
                                <span>{{ __('smart_panel.open_in_new_tab') }}</span>
                            </a>
                        </div>
                    </div>
                    <div id="smart-panel-preview-frame-wrap" class="tw:relative tw:hidden tw:flex-1 tw:min-h-[65vh] tw:rounded-ui-md tw:border tw:border-outline-variant tw:overflow-hidden tw:bg-surface-low" hidden>
                        <x-ui.inline-loader id="smart-panel-preview-loading" :compact="true" :label="__('smart_panel.loading_preview')" class="ui-smart-panel-preview-loading tw:hidden" hidden />
                        <iframe id="smart-panel-iframe" title="{{ __('smart_panel.tabs.preview') }}" class="tw:w-full tw:h-full tw:border-0"></iframe>
                    </div>
                    <div id="smart-panel-no-docs" class="tw:hidden tw:flex tw:flex-col tw:items-center tw:justify-center tw:gap-2 tw:py-10 tw:px-4 tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-low tw:text-center tw:text-ui-xs tw:text-on-surface-variant" hidden>
                        <x-ui.icon name="file-text" size="md" class="tw:text-on-surface-variant" />
                        <p class="tw:m-0 tw:max-w-sm">{{ __('smart_panel.empty_documents') }}</p>
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
                    hidden
                ></div>
            </div>
        </div>
    </div>

    {{-- Server-rendered Lucide icon templates cloned safely in JS without innerHTML --}}
    <template data-smart-panel-icon="neutral"><x-ui.icon name="circle" size="xs" /></template>
    <template data-smart-panel-icon="info"><x-ui.icon name="info" size="xs" /></template>
    <template data-smart-panel-icon="success"><x-ui.icon name="circle-check" size="xs" /></template>
    <template data-smart-panel-icon="warning"><x-ui.icon name="triangle-alert" size="xs" /></template>
    <template data-smart-panel-icon="error"><x-ui.icon name="circle-x" size="xs" /></template>
    <template data-smart-panel-icon="chevron"><x-ui.icon name="chevron-right" size="sm" /></template>
    <template data-smart-panel-icon="lock"><x-ui.icon name="lock" size="xs" /></template>
    <template data-smart-panel-icon="file"><x-ui.icon name="file-text" size="xs" /></template>
    <template data-smart-panel-icon="inbox"><x-ui.icon name="inbox" size="md" /></template>
    <template data-smart-panel-icon="clock"><x-ui.icon name="clock" size="md" /></template>
</div>
@endauth
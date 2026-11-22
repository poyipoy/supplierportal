@php
    $sessionModalCopy = [
        'title' => __('security.session_modal.title'),
        'reasonIdle' => __('security.session_modal.reason_idle'),
        'reasonAbsolute' => __('security.session_modal.reason_absolute'),
        'reasonRevoked' => __('security.session_modal.reason_revoked'),
        'reasonDefault' => __('security.session_modal.reason_default'),
        'descriptionClean' => __('security.session_modal.description_clean'),
        'descriptionDirty' => __('security.session_modal.description_dirty'),
        'descriptionNonReconnectable' => __('security.session_modal.description_non_reconnectable'),
        'dirtyHeading' => __('security.session_modal.dirty_heading'),
        'dirtyHelp' => __('security.session_modal.dirty_help'),
        'actionReconnect' => __('security.session_modal.action_reconnect'),
        'actionReconnecting' => __('security.session_modal.action_reconnecting'),
        'actionSignIn' => __('security.session_modal.action_sign_in'),
        'actionChecking' => __('security.session_modal.action_checking'),
        'actionStayCopy' => __('security.session_modal.action_stay_copy'),
        'reminderBarText' => __('security.session_modal.reminder_bar_text'),
        'reminderBarAction' => __('security.session_modal.reminder_bar_action'),
        'failed' => __('security.session_reconnect.failed'),
    ];
@endphp

<div
    x-data="adasiSessionModal(@js($sessionModalCopy))"
    x-on:keydown.escape.window="handleEscape($event)"
    data-session-expired-host
>
    {{-- Persistent top reminder bar shown only when the operator chose "Stay to Copy Data" on a dirty page --}}
    <div
        x-show="showReminderBar && !open"
        x-cloak
        role="status"
        aria-live="polite"
        data-session-expired-bar
        class="ui-session-expired-bar tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:px-4 tw:py-2.5"
    >
        <div class="tw:flex tw:min-w-0 tw:items-center tw:gap-2.5">
            <span class="tw:inline-flex tw:shrink-0 tw:items-center" aria-hidden="true">
                <x-ui.icon name="shield-alert" size="sm" />
            </span>
            <p class="tw:m-0 tw:text-ui-sm tw:font-medium">
                {{ __('security.session_modal.reminder_bar_text') }}
            </p>
        </div>

        <x-ui.button
            type="button"
            variant="primary"
            size="sm"
            x-ref="reminderAction"
            x-on:click="reopenFromReminderBar()"
            class="tw:min-h-11 tw:sm:min-h-[var(--ui-control-height-sm)] tw:shrink-0"
            data-session-expired-bar-action
        >
            <x-slot:leading>
                <x-ui.icon name="refresh-cw" size="sm" />
            </x-slot:leading>
            {{ __('security.session_modal.reminder_bar_action') }}
        </x-ui.button>
    </div>

    {{-- Blocking ERP Session Ended modal teleported to body to sit cleanly above drawers and Bootstrap modals --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            data-session-expired-modal
            class="ui-session-modal tw:fixed tw:inset-0 tw:overflow-y-auto tw:p-4"
        >
            <div class="ui-scrim tw:fixed tw:inset-0" aria-hidden="true"></div>

            <div class="tw:flex tw:min-h-full tw:items-center tw:justify-center">
                <section
                    x-ref="panel"
                    x-show="open"
                    x-on:keydown.tab="trapFocus($event)"
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="adasi-session-expired-title"
                    aria-describedby="adasi-session-expired-desc"
                    tabindex="-1"
                    class="ui-session-modal__panel tw:relative tw:w-full tw:max-w-lg tw:overflow-hidden tw:rounded-ui-md"
                    x-transition:enter="ui-session-modal__transition"
                    x-transition:enter-start="tw:opacity-0"
                    x-transition:enter-end="tw:opacity-100"
                    x-transition:leave="ui-session-modal__transition"
                    x-transition:leave-start="tw:opacity-100"
                    x-transition:leave-end="tw:opacity-0"
                >
                    <header class="tw:flex tw:items-start tw:gap-3.5 tw:border-b tw:border-outline-variant tw:p-5">
                        <div class="ui-session-modal__icon tw:inline-flex tw:h-10 tw:w-10 tw:shrink-0 tw:items-center tw:justify-center tw:rounded-ui-sm" aria-hidden="true">
                            <span x-show="isTimeoutReason">
                                <x-ui.icon name="clock-alert" size="md" />
                            </span>
                            <span x-show="!isTimeoutReason">
                                <x-ui.icon name="shield-alert" size="md" />
                            </span>
                        </div>

                        <div class="tw:min-w-0 tw:flex-1">
                            <h2
                                id="adasi-session-expired-title"
                                class="tw:m-0 tw:text-ui-lg tw:font-bold tw:text-on-surface"
                            >
                                {{ __('security.session_modal.title') }}
                            </h2>
                            <p
                                class="tw:m-0 tw:mt-1 tw:text-ui-sm tw:font-medium tw:text-on-surface-variant"
                                x-text="reasonText"
                            ></p>
                        </div>
                    </header>

                    <div class="tw:space-y-4 tw:p-5">
                        <p
                            id="adasi-session-expired-desc"
                            class="tw:m-0 tw:text-ui-sm tw:leading-relaxed tw:text-on-surface-variant"
                            x-text="descriptionText"
                        ></p>

                        {{-- Shown only when unsaved input is detected in the top page or any Workspace Shell iframe --}}
                        <div
                            x-show="isDirty"
                            x-cloak
                            data-session-expired-dirty-box
                            class="ui-session-modal__dirty tw:rounded-ui-sm tw:p-3.5"
                        >
                            <div class="tw:flex tw:items-start tw:gap-2.5">
                                <span class="tw:mt-0.5 tw:inline-flex tw:shrink-0 tw:items-center" aria-hidden="true">
                                    <x-ui.icon name="triangle-alert" size="sm" />
                                </span>
                                <div class="tw:min-w-0 tw:flex-1">
                                    <p class="tw:m-0 tw:text-ui-sm tw:font-semibold">
                                        {{ __('security.session_modal.dirty_heading') }}
                                    </p>
                                    <p class="tw:m-0 tw:mt-1 tw:text-ui-xs">
                                        {{ __('security.session_modal.dirty_help') }}
                                    </p>
                                    <ul
                                        x-show="dirtyTabs.length > 0"
                                        class="tw:m-0 tw:mt-2 tw:list-disc tw:space-y-1 tw:pl-4 tw:text-ui-xs tw:font-medium"
                                    >
                                        <template x-for="(tab, idx) in dirtyTabs" :key="tab.frameId || idx">
                                            <li class="tw:break-words" x-text="tab.title"></li>
                                        </template>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        {{-- Inline error box for offline / 429 / 5xx check failures so operator can retry --}}
                        <div
                            x-show="errorMessage !== ''"
                            x-cloak
                            role="alert"
                            aria-live="assertive"
                            data-session-expired-error
                            class="ui-session-modal__error tw:flex tw:items-start tw:gap-2.5 tw:rounded-ui-sm tw:p-3 tw:text-ui-xs tw:font-medium"
                        >
                            <span class="tw:mt-0.5 tw:inline-flex tw:shrink-0 tw:items-center" aria-hidden="true">
                                <x-ui.icon name="circle-alert" size="sm" />
                            </span>
                            <span x-text="errorMessage"></span>
                        </div>
                    </div>

                    <footer class="tw:flex tw:flex-col-reverse tw:flex-wrap tw:gap-2 tw:border-t tw:border-outline-variant tw:p-4 tw:sm:flex-row tw:sm:items-center tw:sm:justify-end">
                        <template x-if="isDirty">
                            <x-ui.button
                                type="button"
                                variant="ghost"
                                x-on:click="dismissToReminderBar()"
                                x-bind:disabled="busy"
                                data-session-modal-dismiss
                                class="tw:min-h-11 tw:sm:min-h-[var(--ui-control-height-md)]"
                            >
                                {{ __('security.session_modal.action_stay_copy') }}
                            </x-ui.button>
                        </template>

                        <template x-if="reconnectable">
                            <x-ui.button
                                type="button"
                                variant="outline"
                                x-on:click="triggerLoginRedirect()"
                                x-bind:disabled="busy"
                                data-session-modal-login
                                class="tw:min-h-11 tw:sm:min-h-[var(--ui-control-height-md)]"
                            >
                                <x-slot:leading>
                                    <x-ui.icon name="log-in" size="sm" />
                                </x-slot:leading>
                                {{ __('security.session_modal.action_sign_in') }}
                            </x-ui.button>
                        </template>

                        <x-ui.button
                            type="button"
                            variant="primary"
                            x-ref="reconnectButton"
                            x-on:click="triggerPrimaryAction()"
                            x-bind:disabled="busy"
                            x-bind:aria-busy="busy ? 'true' : 'false'"
                            data-session-modal-reconnect
                            class="tw:min-h-11 tw:sm:min-h-[var(--ui-control-height-md)]"
                        >
                            <span x-show="busy" class="ui-spinner" aria-hidden="true"></span>
                            <span x-show="!busy && reconnectable" class="tw:inline-flex tw:shrink-0 tw:items-center" aria-hidden="true">
                                <x-ui.icon name="refresh-cw" size="sm" />
                            </span>
                            <span x-show="!busy && !reconnectable" class="tw:inline-flex tw:shrink-0 tw:items-center" aria-hidden="true">
                                <x-ui.icon name="log-in" size="sm" />
                            </span>
                            <span x-text="primaryActionLabel">
                                {{ __('security.session_modal.action_reconnect') }}
                            </span>
                        </x-ui.button>
                    </footer>
                </section>
            </div>
        </div>
    </template>
</div>

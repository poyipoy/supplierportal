@props([
    'context' => 'default',
])

<section
    x-data="adasiToastCenter"
    class="adasi-toast-region {{ $context === 'app' ? 'adasi-toast-region--app' : '' }}"
    aria-label="Notifications"
    aria-relevant="additions text"
>
    <template x-for="toast in state.visible" :key="toast.id">
        <article
            x-show="toast.visible"
            x-transition:enter="adasi-toast-enter"
            x-transition:enter-start="adasi-toast-enter-start"
            x-transition:enter-end="adasi-toast-enter-end"
            x-transition:leave="adasi-toast-leave"
            x-transition:leave-start="adasi-toast-leave-start"
            x-transition:leave-end="adasi-toast-leave-end"
            class="adasi-toast"
            :class="`adasi-toast--${toast.type}`"
            :role="toast.type === 'error' ? 'alert' : 'status'"
            :aria-live="toast.type === 'error' ? 'assertive' : 'polite'"
            aria-atomic="true"
            @mouseenter="pause(toast.id)"
            @mouseleave="resume(toast.id)"
            @focusin="pause(toast.id)"
            @focusout="resume(toast.id)"
        >
            <div class="adasi-toast__header">
                <span class="adasi-toast__icon" aria-hidden="true">
                    <x-ui.icon name="circle-check" x-show="toast.icon === 'circle-check'" />
                    <x-ui.icon name="circle-x" x-show="toast.icon === 'circle-x'" />
                    <x-ui.icon name="triangle-alert" x-show="toast.icon === 'triangle-alert'" />
                    <x-ui.icon name="info" x-show="toast.icon === 'info'" />
                    <x-ui.icon name="message-square-text" x-show="toast.icon === 'message-square-text'" />
                    <span
                        x-show="toast.icon === 'loader-circle'"
                        class="adasi-toast__progress-ring"
                        :class="{ 'adasi-toast__progress-ring--indeterminate': toast.indeterminate }"
                    >
                        <svg viewBox="0 0 20 20" focusable="false">
                            <circle class="adasi-toast__progress-ring-track" cx="10" cy="10" r="8"></circle>
                            <circle
                                class="adasi-toast__progress-ring-value"
                                cx="10"
                                cy="10"
                                r="8"
                                :style="toast.indeterminate ? null : `stroke-dashoffset: ${50.265 * (1 - toast.progress / 100)}`"
                            ></circle>
                        </svg>
                    </span>
                </span>

                <div class="adasi-toast__heading">
                    <p class="adasi-toast__title" x-text="toast.title"></p>
                    <time x-show="toast.timestamp" class="adasi-toast__timestamp" x-text="toast.timestamp"></time>
                </div>

                <button
                    type="button"
                    class="adasi-toast__close ui-focus-ring"
                    @click="dismiss(toast.id)"
                    aria-label="Dismiss notification"
                >
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>

            <p x-show="toast.message" class="adasi-toast__message" x-text="toast.message"></p>

            <div x-show="toast.hasProgress" class="adasi-toast__progress-block">
                <div class="adasi-toast__progress-meta">
                    <span x-text="toast.progressLabel"></span>
                    <span
                        x-show="!toast.indeterminate"
                        class="adasi-toast__progress-value ui-tabular-nums"
                        x-text="`${toast.progress}%`"
                    ></span>
                </div>

                <div
                    class="adasi-toast__progress"
                    :class="{ 'adasi-toast__progress--indeterminate': toast.indeterminate }"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="toast.indeterminate ? null : toast.progress"
                    :aria-label="toast.progressLabel || toast.title"
                >
                    <span
                        class="adasi-toast__progress-bar"
                        :style="toast.indeterminate ? null : `transform: scaleX(${toast.progress / 100})`"
                    ></span>
                </div>
            </div>

            <div x-show="toast.actions.length" class="adasi-toast__actions">
                <template x-for="(action, index) in toast.actions" :key="`${toast.id}-action-${index}`">
                    <button
                        type="button"
                        class="adasi-toast__action ui-focus-ring"
                        :class="`adasi-toast__action--${action.variant}`"
                        @click="runAction(toast.id, action)"
                        x-text="action.label"
                    ></button>
                </template>
            </div>
        </article>
    </template>
</section>

@props(['kind' => 'list'])

{{-- Stands in for a panel that is hidden, or whose data is not loaded because it was hidden at page load. --}}
<div class="tw-grid tw-gap-2 tw-rounded-b-ui-sm tw-border tw-border-dashed tw-border-outline tw-bg-surface tw-p-3" data-editor-ghost hidden>
    <x-ui.widget-skeleton :kind="$kind" />
    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant" data-editor-ghost-status></p>
</div>

{{--
    E10: revision marker and version history of one shipment document.
    @param \App\Models\ShipmentDocument $doc  attachments relation loaded (oldest first by id)
--}}
@php
    $revisionCount = (int) ($doc->revision_count ?? 0);
    $versions = $doc->relationLoaded('attachments') ? $doc->attachments->sortBy('id')->values() : collect();
@endphp
@if($revisionCount > 0)
    <div class="tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-1">
        <x-ui.status-chip tone="warning" size="sm">{{ __('shipments.copy.revised_badge', ['count' => $revisionCount]) }}</x-ui.status-chip>
        @if($doc->last_revised_at)
            <span class="tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums">
                {{ __('shipments.copy.last_revised_at', ['date' => $regionalFormatter->timestamp($doc->last_revised_at, 'datetime')]) }}
            </span>
        @endif
    </div>
@endif
@if($versions->count() > 1)
    <details class="tw:mt-1 tw:text-ui-xs">
        <summary class="tw:cursor-pointer tw:text-primary tw:font-medium">{{ __('shipments.copy.document_version_history') }} ({{ $versions->count() }})</summary>
        <ol class="tw:mt-1 tw:mb-0 tw:ps-4 tw:grid tw:gap-0.5">
            @foreach($versions as $index => $version)
                <li>
                    <span class="tw:font-semibold">{{ $index === 0 ? __('shipments.copy.document_version_original') : __('shipments.copy.document_version_label', ['number' => $index]) }}</span>
                    <a href="{{ route('attachments.show', $version) }}" target="_blank" rel="noopener" class="tw:text-primary tw:no-underline">{{ $version->file_name }}</a>
                    <span class="tw:text-on-surface-variant ui-tabular-nums">@bizdt($version->created_at)</span>
                </li>
            @endforeach
        </ol>
    </details>
@endif

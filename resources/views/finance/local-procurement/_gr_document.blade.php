@php
    $document = $gr->latestGrDocument();
@endphp
@if($document)
    <x-ui.icon-button
        :href="route('attachments.show', $document)"
        icon="file-text"
        size="sm"
        variant="outline"
        target="_blank"
        rel="noopener"
        :label="__('local_procurement.gr_documents.view', ['number' => $gr->gr_number])"
    />
@else
    <span class="tw:text-on-surface-variant">{{ __('local_procurement.gr_documents.empty') }}</span>
@endif


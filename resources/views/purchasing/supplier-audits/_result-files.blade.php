{{-- Riwayat file hasil; yang teratas adalah file terbaru yang dilihat supplier. Input: $audit (resultAttachments.uploader dimuat). --}}
<div class="tw-grid tw-content-start tw-gap-2">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.labels.results') }}</p>
    <ul class="tw-m-0 tw-grid tw-list-none tw-gap-2 tw-p-0" data-supplier-audit-results>
        @foreach($audit->resultAttachments as $attachment)
            <li class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-px-3 tw-py-2">
                <span class="tw-min-w-0">
                    <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener" class="tw-block tw-truncate tw-text-ui-sm tw-font-medium">{{ $attachment->file_name }}</a>
                    <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">
                        {{ __('supplier_audit.labels.uploaded_by', ['name' => $attachment->uploader?->name ?? '-']) }} · {{ $regionalFormatter->timestamp($attachment->created_at) }}
                    </span>
                </span>
                @if($loop->first)
                    <x-ui.status-chip tone="success" size="sm">{{ __('supplier_audit.labels.latest_file') }}</x-ui.status-chip>
                @endif
            </li>
        @endforeach
    </ul>
</div>

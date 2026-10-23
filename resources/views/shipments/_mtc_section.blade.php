{{--
    Mission 05 (Q6B): Mill Test Certificates attached to one shipment.
    Draft: add + remove. Submitted: add only. Arrived/Cancelled/other: read only
    (fail-closed, T-6). The server re-checks every rule under the shipment lock.

    @param \App\Models\Shipment $shipment   mtcAttachments.fileInspection + uploader loaded
    @param bool                 $manageable Supplier owner view (upload/remove controls)
--}}
@php
    $mtcFiles = $shipment->mtcAttachments;
    $canUpload = $manageable && in_array($shipment->status, \App\Models\Shipment::MTC_UPLOAD_STATUSES, true);
    $canRemove = $manageable && in_array($shipment->status, \App\Models\Shipment::MTC_DELETE_STATUSES, true);
@endphp
<x-ui.card :title="__('shipments.mtc.section_title')" :description="__('shipments.mtc.section_description')" id="shipmentMtc" data-shipment-mtc>
    @if($manageable)
        <x-ui.alert tone="info" class="tw:mb-3">
            @if($shipment->status === \App\Models\Shipment::STATUS_DRAFT)
                {{ __('shipments.mtc.required_hint') }}
            @elseif($shipment->status === \App\Models\Shipment::STATUS_SUBMITTED)
                {{ __('shipments.mtc.submitted_note') }}
            @else
                {{ __('shipments.mtc.locked_note') }}
            @endif
        </x-ui.alert>
    @endif
    @error('mtc')<x-ui.alert tone="error" class="tw:mb-3">{{ $message }}</x-ui.alert>@enderror

    @if($mtcFiles->isEmpty())
        <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('shipments.mtc.none') }}</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 tw:text-ui-xs">
                <caption class="visually-hidden">{{ __('shipments.mtc.section_title') }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('shipments.mtc.file') }}</th>
                        <th scope="col" class="text-end">{{ __('shipments.mtc.size') }}</th>
                        <th scope="col">{{ __('shipments.mtc.uploaded_by') }}</th>
                        <th scope="col">{{ __('shipments.mtc.uploaded_at') }}</th>
                        <th scope="col">{{ __('shipments.mtc.inspection') }}</th>
                        @if($canRemove)<th scope="col"><span class="visually-hidden">{{ __('shipments.mtc.remove') }}</span></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($mtcFiles as $file)
                        @php
                            $validated = $file->fileInspection?->status === \App\Models\FileInspection::STATUS_VALIDATED;
                            $bytes = $file->fileInspection?->file_size;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('attachments.show', $file) }}" target="_blank" rel="noopener" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1 fw-medium">
                                    <x-ui.icon name="file-text" size="sm" />
                                    <span class="text-truncate" style="max-width: 16rem;" title="{{ $file->file_name }}">{{ $file->file_name }}</span>
                                </a>
                            </td>
                            <td class="text-end ui-tabular-nums">{{ $bytes === null ? '—' : $regionalFormatter->number((string) max(1, (int) round($bytes / 1024)), 'integer').' KB' }}</td>
                            <td>{{ $file->uploader?->name ?? '—' }}</td>
                            <td class="ui-tabular-nums">@bizdt($file->created_at)</td>
                            <td>
                                <x-ui.status-chip :tone="$validated ? 'success' : 'warning'" size="sm">
                                    {{ $validated ? __('shipments.mtc.validated') : __('shipments.mtc.not_validated') }}
                                </x-ui.status-chip>
                            </td>
                            @if($canRemove)
                                <td class="text-end">
                                    <form method="POST" action="{{ route('supplier.shipments.mtc.destroy', [$shipment, $file]) }}" class="tw:m-0">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" :aria-label="__('shipments.mtc.remove').' '.$file->file_name">
                                            <x-ui.icon name="trash-2" size="sm" />
                                            <span>{{ __('shipments.mtc.remove') }}</span>
                                        </x-ui.button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($canUpload)
        <form method="POST" action="{{ route('supplier.shipments.mtc.store', $shipment) }}" enctype="multipart/form-data" data-async-submit class="tw:mt-3 tw:grid tw:gap-2 tw:border-t tw:border-outline-variant tw:pt-3">
            @csrf
            <x-ui.file-upload
                name="mtc_files[]"
                id="shipmentMtcFiles"
                :label="__('shipments.mtc.add')"
                :helper="__('shipments.mtc.section_description')"
                multiple
                :max-files="10"
                :max-size-mb="5"
                accept=".pdf,.jpg,.jpeg,.png"
                :allowed-extensions="['pdf', 'jpg', 'jpeg', 'png']"
                required
            />
            <div>
                <x-ui.button type="submit" size="sm">
                    <x-ui.icon name="upload" size="sm" />
                    <span>{{ __('shipments.mtc.add') }}</span>
                </x-ui.button>
            </div>
        </form>
    @endif
</x-ui.card>

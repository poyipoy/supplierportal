{{--
    Contact-person change history, shared by the supplier Company Profile and the Purchasing detail page.
    @param \Illuminate\Support\Collection<\App\Models\SupplierChangeRequest> $requests
--}}
<x-ui.card :title="__('supplier_contact.common.history_title')" padding="none">
    @if($requests->isEmpty())
        <p class="tw:m-0 tw:px-4 tw:py-6 tw:text-ui-sm tw:text-on-surface-variant">{{ __('supplier_contact.profile.history_empty') }}</p>
    @else
        <div class="ui-table-scroll">
            <table class="ui-table tw:align-middle tw:m-0 tw:text-ui-sm">
                <caption class="tw:sr-only">{{ __('supplier_contact.common.history_title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.common.submitted_at') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.common.proposed') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.common.status') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.common.reviewed') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.common.notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requests as $item)
                        @php $proposed = $item->proposed_data ?? []; @endphp
                        <tr>
                            <td class="ui-tabular-nums tw:whitespace-nowrap">{{ $regionalFormatter->timestamp($item->requested_at ?? $item->created_at, 'datetime') }}</td>
                            <td class="ui-col-data-name">
                                <span class="tw:block tw:font-medium tw:text-on-surface tw:wrap-break-word">{{ $proposed['pic_name'] ?? '' }}</span>
                                <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant tw:break-all">{{ $proposed['pic_email'] ?? '' }}</span>
                                <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ $proposed['pic_phone'] ?? '' }}</span>
                                @foreach(\App\Models\SupplierChangeRequest::COMPANY_FIELDS as $companyField)
                                    @if(filled($proposed[$companyField] ?? null))
                                        <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant tw:wrap-break-word">{{ __('supplier_contact.profile.'.$companyField) }}: {{ $proposed[$companyField] }}</span>
                                    @endif
                                @endforeach
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::contactRequestTone($item->status)">
                                    {{ \App\Support\StatusHelper::contactRequestLabel($item->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="ui-tabular-nums">
                                @if($item->reviewed_at && $item->reviewer)
                                    <span class="tw:block tw:whitespace-nowrap">{{ $regionalFormatter->timestamp($item->reviewed_at, 'datetime') }}</span>
                                    <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ __('supplier_contact.common.by', ['name' => $item->reviewer->name]) }}</span>
                                @else
                                    <span class="tw:text-on-surface-variant">&ndash;</span>
                                @endif
                            </td>
                            <td class="tw:max-w-xs tw:wrap-break-word ui-col-data-name">{{ $item->review_notes ?: '–' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>

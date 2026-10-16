@php
    $proposed = $changeRequest->proposed_data ?? [];
    $current = $changeRequest->current_data_snapshot ?? [];

    $fieldMeta = [
        // Profil Perusahaan
        'company_name' => ['label' => __('local_procurement.change_details.company'), 'icon' => 'building-2'],
        'vendor_category' => ['label' => __('local_invoice.labels.vendor_category'), 'icon' => 'tag'],
        'category' => ['label' => __('common.fields.category'), 'icon' => 'tag'],
        'npwp' => ['label' => 'NPWP', 'icon' => 'file-text'],
        'is_pkp' => ['label' => __('local_invoice.labels.tax_status'), 'icon' => 'receipt'],
        'address' => ['label' => __('local_invoice.labels.company_address'), 'icon' => 'map-pin'],
        'phone' => ['label' => __('local_invoice.labels.company_phone'), 'icon' => 'phone'],
        'payment_term_days' => ['label' => __('local_procurement.change_details.payment_term'), 'icon' => 'calendar'],

        // Kontak PIC
        'pic_name' => ['label' => __('local_procurement.change_details.pic'), 'icon' => 'user'],
        'pic_email' => ['label' => __('local_invoice.labels.pic_email'), 'icon' => 'mail'],
        'pic_phone' => ['label' => __('local_procurement.change_details.pic_phone'), 'icon' => 'phone-call'],

        // Rekening Bank
        'bank_name' => ['label' => __('local_procurement.change_details.bank'), 'icon' => 'landmark'],
        'account_number' => ['label' => __('local_procurement.change_details.account_number'), 'icon' => 'credit-card'],
        'account_holder_name' => ['label' => __('local_procurement.change_details.account_holder'), 'icon' => 'check-circle'],
    ];

    $formatVal = function ($key, $val) {
        if ($val === null || $val === '') {
            return null;
        }
        if ($key === 'is_pkp') {
            return ((string) $val === '1' || $val === true || $val === 'true') ? __('local_procurement.change_details.pkp') : __('local_procurement.change_details.non_pkp');
        }
        if ($key === 'payment_term_days') {
            return trans_choice('local_invoice.table.term_days', (int) $val);
        }
        if (is_bool($val)) {
            return $val ? __('local_procurement.change_details.yes') : __('local_procurement.change_details.no');
        }
        return (string) $val;
    };
@endphp

<div class="tw-mt-3 tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-sm tw-overflow-hidden">
    <div class="tw-px-3 tw-py-2 tw-bg-surface-container-low tw-border-b tw-border-outline-variant tw-flex tw-items-center tw-justify-between">
        <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-flex tw-items-center tw-gap-1.5">
            <x-ui.icon name="file-text" size="sm" class="tw-text-primary" />
            <span>{{ __('local_procurement.change_details.heading') }}</span>
        </span>
        <span class="tw-text-[11px] tw-text-on-surface-variant">
            {{ __('local_procurement.change_details.help') }}
        </span>
    </div>

    @if(!empty($proposed))
        <div class="table-responsive">
            <table class="table table-sm table-hover tw-m-0 tw-text-ui-xs align-middle">
                <thead class="tw-bg-surface-container-lowest tw-border-b tw-border-outline-variant/60">
                    <tr>
                        <th class="tw-py-2 tw-ps-3 tw-text-on-surface-variant tw-font-semibold" style="width: 25%;">{{ __('local_procurement.change_details.field') }}</th>
                        <th class="tw-py-2 tw-text-on-surface-variant tw-font-semibold" style="width: 35%;">{{ __('local_procurement.change_details.current') }}</th>
                        <th class="tw-py-2 tw-text-on-surface-variant tw-font-semibold" style="width: 40%;">{{ __('local_procurement.change_details.proposed') }}</th>
                    </tr>
                </thead>
                <tbody class="tw-divide-y tw-divide-outline-variant/30">
                    @foreach($proposed as $key => $newValRaw)
                        @php
                            $meta = $fieldMeta[$key] ?? [
                                'label' => ucwords(str_replace('_', ' ', $key)),
                                'icon' => 'info',
                            ];
                            $oldValRaw = $current[$key] ?? null;
                            $oldVal = $formatVal($key, $oldValRaw);
                            $newVal = $formatVal($key, $newValRaw);
                            $isChanged = ($oldValRaw !== null && (string) $oldValRaw !== (string) $newValRaw);
                            $isNew = ($oldValRaw === null);
                        @endphp
                        <tr class="{{ $isChanged ? 'tw-bg-primary/[0.03]' : '' }}">
                            <td class="tw-py-2.5 tw-ps-3 tw-text-on-surface">
                                <div class="tw-flex tw-items-center tw-gap-2">
                                    <x-ui.icon :name="$meta['icon']" size="sm" class="tw-text-on-surface-variant/70 tw-shrink-0" />
                                    <span class="tw-font-medium">{{ $meta['label'] }}</span>
                                </div>
                            </td>
                            <td class="tw-py-2.5 tw-text-on-surface-variant">
                                @if($oldVal !== null)
                                    <span class="{{ $isChanged ? 'tw-line-through tw-text-on-surface-variant/60' : '' }}">{{ $oldVal }}</span>
                                @else
                                    <span class="tw-text-on-surface-variant/40 tw-italic">{{ __('local_procurement.change_details.missing') }}</span>
                                @endif
                            </td>
                            <td class="tw-py-2.5">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-flex-wrap">
                                    @if($newVal !== null)
                                        <strong class="{{ $isChanged ? 'tw-text-primary' : 'tw-text-on-surface' }}">{{ $newVal }}</strong>
                                    @else
                                        <span class="tw-text-on-surface-variant/40 tw-italic">{{ __('local_procurement.change_details.cleared') }}</span>
                                    @endif

                                    @if($isChanged)
                                        <x-ui.status-chip tone="info" size="sm">
                                            {{ __('local_procurement.change_details.changed') }}
                                        </x-ui.status-chip>
                                    @elseif($isNew)
                                        <x-ui.status-chip tone="success" size="sm">
                                            {{ __('local_procurement.change_details.new') }}
                                        </x-ui.status-chip>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="tw-p-4 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
            {{ __('local_procurement.change_details.empty') }}
        </div>
    @endif
</div>

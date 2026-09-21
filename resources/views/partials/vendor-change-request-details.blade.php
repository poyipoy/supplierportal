@php
    $proposed = $changeRequest->proposed_data ?? [];
    $current = $changeRequest->current_data_snapshot ?? [];

    $fieldMeta = [
        // Profil Perusahaan
        'company_name' => ['label' => 'Nama Perusahaan', 'icon' => 'building-2'],
        'vendor_category' => ['label' => 'Kategori Vendor', 'icon' => 'tag'],
        'category' => ['label' => 'Kategori', 'icon' => 'tag'],
        'npwp' => ['label' => 'NPWP', 'icon' => 'file-text'],
        'is_pkp' => ['label' => 'Status Pajak', 'icon' => 'receipt'],
        'address' => ['label' => 'Alamat Perusahaan', 'icon' => 'map-pin'],
        'phone' => ['label' => 'Telepon Perusahaan', 'icon' => 'phone'],
        'payment_term_days' => ['label' => 'Payment Term', 'icon' => 'calendar'],

        // Kontak PIC
        'pic_name' => ['label' => 'Nama PIC', 'icon' => 'user'],
        'pic_email' => ['label' => 'Email PIC', 'icon' => 'mail'],
        'pic_phone' => ['label' => 'No. Telepon / WA PIC', 'icon' => 'phone-call'],

        // Rekening Bank
        'bank_name' => ['label' => 'Nama Bank', 'icon' => 'landmark'],
        'account_number' => ['label' => 'Nomor Rekening', 'icon' => 'credit-card'],
        'account_holder_name' => ['label' => 'Atas Nama Rekening', 'icon' => 'check-circle'],
    ];

    $formatVal = function ($key, $val) {
        if ($val === null || $val === '') {
            return null;
        }
        if ($key === 'is_pkp') {
            return ((string) $val === '1' || $val === true || $val === 'true') ? 'PKP (Pengusaha Kena Pajak)' : 'Non-PKP';
        }
        if ($key === 'payment_term_days') {
            return 'Net ' . $val . ' Hari';
        }
        if (is_bool($val)) {
            return $val ? 'Ya' : 'Tidak';
        }
        return (string) $val;
    };
@endphp

<div class="tw-mt-3 tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-sm tw-overflow-hidden">
    <div class="tw-px-3 tw-py-2 tw-bg-surface-container-low tw-border-b tw-border-outline-variant tw-flex tw-items-center tw-justify-between">
        <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-flex tw-items-center tw-gap-1.5">
            <x-ui.icon name="file-text" size="sm" class="tw-text-primary" />
            <span>Rincian Perbandingan Data yang Diajukan</span>
        </span>
        <span class="tw-text-[11px] tw-text-on-surface-variant">
            Periksa data sebelum mengambil keputusan
        </span>
    </div>

    @if(!empty($proposed))
        <div class="table-responsive">
            <table class="table table-sm table-hover tw-m-0 tw-text-ui-xs align-middle">
                <thead class="tw-bg-surface-container-lowest tw-border-b tw-border-outline-variant/60">
                    <tr>
                        <th class="tw-py-2 tw-ps-3 tw-text-on-surface-variant tw-font-semibold" style="width: 25%;">Informasi / Field</th>
                        <th class="tw-py-2 tw-text-on-surface-variant tw-font-semibold" style="width: 35%;">Data Saat Ini (Lama)</th>
                        <th class="tw-py-2 tw-text-on-surface-variant tw-font-semibold" style="width: 40%;">Perubahan Diajukan (Baru)</th>
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
                                    <span class="tw-text-on-surface-variant/40 tw-italic">— Belum ada data —</span>
                                @endif
                            </td>
                            <td class="tw-py-2.5">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-flex-wrap">
                                    @if($newVal !== null)
                                        <strong class="{{ $isChanged ? 'tw-text-primary' : 'tw-text-on-surface' }}">{{ $newVal }}</strong>
                                    @else
                                        <span class="tw-text-on-surface-variant/40 tw-italic">— Dikosongkan —</span>
                                    @endif

                                    @if($isChanged)
                                        <x-ui.status-chip tone="info" size="sm">
                                            Diubah
                                        </x-ui.status-chip>
                                    @elseif($isNew)
                                        <x-ui.status-chip tone="success" size="sm">
                                            Data Baru
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
            Tidak ada rincian data perubahan yang diajukan.
        </div>
    @endif
</div>

@props([
    'documents' => collect(),
])

@php
    $categories = [
        'invoice' => [
            'title' => 'Berkas Invoice Fisik / Asli',
            'subtitle' => 'Invoice tagihan resmi dari rekanan supplier',
            'icon' => 'file-text',
            'types' => ['invoice'],
            'border_l' => 'tw-border-l-4 tw-border-l-primary',
            'icon_container' => 'tw-bg-primary-container tw-text-primary-container-foreground',
            'badge_class' => 'tw-bg-primary-container tw-text-primary-container-foreground',
        ],
        'tax_invoice' => [
            'title' => 'Faktur Pajak',
            'subtitle' => 'e-Faktur PPN / Bukti potong pajak resmi',
            'icon' => 'receipt',
            'types' => ['tax_invoice'],
            'border_l' => 'tw-border-l-4 tw-border-l-success',
            'icon_container' => 'tw-bg-success-container tw-text-success-container-foreground',
            'badge_class' => 'tw-bg-success-container tw-text-success-container-foreground',
        ],
        'delivery_note' => [
            'title' => 'Surat Jalan (Delivery Note)',
            'subtitle' => 'Bukti fisik ekspedisi & serah terima barang',
            'icon' => 'truck',
            'types' => ['delivery_note', 'surat_jalan'],
            'border_l' => 'tw-border-l-4 tw-border-l-warning',
            'icon_container' => 'tw-bg-warning-container tw-text-warning-container-foreground',
            'badge_class' => 'tw-bg-warning-container tw-text-warning-container-foreground',
        ],
        'supporting' => [
            'title' => 'Dokumen Pendukung Tambahan',
            'subtitle' => 'BAP, Purchase Order, atau lampiran pelengkap',
            'icon' => 'paperclip',
            'types' => ['supporting'],
            'border_l' => 'tw-border-l-4 tw-border-l-secondary',
            'icon_container' => 'tw-bg-secondary-container tw-text-secondary-container-foreground',
            'badge_class' => 'tw-bg-secondary-container tw-text-secondary-container-foreground',
        ],
    ];
@endphp

<div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
    @foreach($categories as $key => $cat)
        @php
            $catDocs = $documents ? $documents->whereIn('document_type', $cat['types'])->values() : collect();
            $count = $catDocs->count();
        @endphp
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-overflow-hidden tw-flex tw-flex-col tw-h-full {{ $cat['border_l'] }}">
            {{-- Category Header Bar --}}
            <div class="tw-bg-surface-low tw-border-b tw-border-outline-variant tw-px-3.5 tw-py-2.5 tw-flex tw-items-center tw-justify-between tw-gap-2.5">
                <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                    <div class="tw-w-8 tw-h-8 tw-rounded-ui-sm {{ $cat['icon_container'] }} tw-border tw-border-outline-variant tw-flex tw-items-center tw-justify-center tw-shrink-0">
                        <x-ui.icon :name="$cat['icon']" size="sm" />
                    </div>
                    <div class="tw-min-w-0">
                        <span class="tw-block tw-font-bold tw-text-ui-sm tw-text-on-surface tw-truncate" title="{{ $cat['title'] }}">
                            {{ $cat['title'] }}
                        </span>
                        <span class="tw-block tw-text-[11px] tw-text-on-surface-variant tw-truncate" title="{{ $cat['subtitle'] }}">
                            {{ $cat['subtitle'] }}
                        </span>
                    </div>
                </div>
                <div class="tw-shrink-0">
                    @if($count > 0)
                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-semibold tw-border tw-border-outline-variant {{ $cat['badge_class'] }}">
                            <x-ui.icon name="files" size="sm" />
                            <span>{{ $count }} Berkas</span>
                        </span>
                    @else
                        <span class="tw-inline-flex tw-items-center tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">
                            Tidak Ada
                        </span>
                    @endif
                </div>
            </div>

            {{-- Category Body: List of Files or Empty State --}}
            <div class="tw-p-3 tw-flex-1 tw-flex tw-flex-col tw-justify-start tw-bg-surface">
                @if($count > 0)
                    <div class="tw-max-h-44 tw-overflow-y-auto tw-space-y-1.5 tw-pr-1 ui-scrollable-list">
                        @foreach($catDocs as $doc)
                            <div class="tw-flex tw-items-center tw-justify-between tw-gap-2.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-low tw-px-3 tw-py-2 tw-transition-all hover:tw-border-primary/50 hover:tw-bg-surface">
                                <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0 tw-flex-1">
                                    <div class="tw-w-7 tw-h-7 tw-rounded tw-bg-surface tw-border tw-border-outline-variant tw-flex tw-items-center tw-justify-center tw-text-primary tw-shrink-0">
                                        <x-ui.icon name="file-text" size="sm" />
                                    </div>
                                    <div class="tw-min-w-0 tw-flex-1">
                                        <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block tw-truncate" title="{{ $doc->original_filename }}">
                                            {{ $doc->original_filename }}
                                        </span>
                                        @if($doc->formatted_file_size)
                                            <span class="tw-text-[10px] tw-text-on-surface-variant tw-font-mono tw-block tw-mt-0.5">
                                                {{ $doc->formatted_file_size }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="tw-shrink-0">
                                    <x-ui.button
                                        :href="route('local-invoice-documents.show', $doc)"
                                        size="sm"
                                        variant="outline"
                                        target="_blank"
                                        class="tw-py-1 tw-px-2.5 tw-text-ui-xs"
                                    >
                                        <x-ui.icon name="external-link" size="sm" />
                                        <span>Buka</span>
                                    </x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="tw-flex-1 tw-flex tw-flex-col tw-items-center tw-justify-center tw-p-4 tw-rounded-ui-sm tw-border tw-border-dashed tw-border-outline-variant tw-bg-surface-low/50 tw-text-center tw-min-h-[100px]">
                        <x-ui.icon name="file-x" size="sm" class="tw-text-on-surface-variant/40 tw-mb-1" />
                        <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">Tidak ada dokumen terlampir</span>
                        <span class="tw-text-[10px] tw-text-on-surface-variant/70 tw-mt-0.5">Berkas tidak diunggah untuk kategori ini</span>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

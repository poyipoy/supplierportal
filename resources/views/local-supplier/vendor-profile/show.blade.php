@extends('layouts.app')
@section('title', __('local_procurement.vendor_ui.profile_title').' - ADASI Portal')
@section('page-title', __('local_procurement.vendor_ui.profile_title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_procurement.vendor_ui.profile_title')"
        :description="__('local_procurement.vendor_ui.profile_help')"
        :eyebrow="__('local_procurement.labels.vendor_partner')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('navigation.back_dashboard') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="tw-p-4 tw-rounded-lg tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-sm tw-flex tw-items-center tw-gap-3">
            <x-ui.icon name="check-circle" size="md" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if($errors->any())
        <div class="tw-p-4 tw-rounded-lg tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-sm tw-space-y-1">
            <div class="tw-font-semibold tw-flex tw-items-center tw-gap-2">
                <x-ui.icon name="alert-circle" size="sm" />
                <span>{{ __('local_invoice.labels.errors') }}</span>
            </div>
            <ul class="tw-list-disc tw-list-inside tw-text-ui-xs">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Kolom Kiri: Profil Master & Form Pengajuan --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Data Master Aktif --}}
            <x-ui.card :title="__('local_invoice.labels.active_master')">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.company') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $supplier?->company_name ?: $user->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.vendor_category') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-primary">{{ \App\Support\StatusHelper::vendorCategoryLabel($supplier?->vendor_category ?? 'Barang') }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.tax_status') }}:</span>
                        <strong class="tw-text-ui-sm {{ $supplier?->is_pkp ? 'tw-text-success' : 'tw-text-on-surface' }}">
                            {{ $supplier?->is_pkp ? 'PKP' : 'Non-PKP' }}
                        </strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">NPWP:</span>
                        <span class="tw-font-mono">{{ $supplier?->npwp ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.company_phone') }}:</span>
                        <span>{{ $supplier?->phone ?? '—' }}</span>
                    </div>

                    <div class="tw-col-span-full">
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.company_address') }}:</span>
                        <span>{{ $supplier?->address ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.pic_name') }}:</span>
                        <span>{{ $supplier?->pic_name ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.pic_email') }}:</span>
                        <span>{{ $supplier?->pic_email ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.vendor_ui.pic_phone') }}:</span>
                        <span>{{ $supplier?->pic_phone ?? '—' }}</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Rekening Bank Terdaftar --}}
            <x-ui.card :title="__('local_invoice.labels.bank_accounts')">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('local_procurement.vendor_ui.bank_name') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.bank_number') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.account_name') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.verification_status') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.usage') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($bankAccounts as $b)
                                <tr>
                                    <td><strong class="tw-text-on-surface">{{ $b->bank_name }}</strong></td>
                                    <td><span class="tw-font-mono">{{ $b->account_number }}</span></td>
                                    <td>{{ $b->account_holder_name }}</td>
                                    <td>
                                        <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($b->status)">
                                            {{ \App\Support\StatusHelper::localFinanceLabel($b->status) }}
                                        </x-ui.status-chip>
                                    </td>
                                    <td>
                                        @if($b->status === 'VERIFIED')
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-primary/10 tw-text-primary tw-font-bold">{{ __('local_procurement.vendor_ui.active_account') }}</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">{{ __('local_procurement.vendor_ui.inactive_history') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center tw-py-4 tw-text-on-surface-variant">{{ __('local_invoice.empty.bank') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            {{-- Form Pengajuan Perubahan Data (Change Request) --}}
            <x-ui.card :title="__('local_invoice.actions.request_profile_change')">
                <div class="tw-p-3 tw-mb-4 tw-rounded tw-bg-info/10 tw-border tw-border-info/20 tw-text-info tw-text-ui-xs tw-flex tw-items-start tw-gap-2">
                    <x-ui.icon name="info" size="sm" class="tw-shrink-0 tw-mt-0.5" />
                    <div>
                    {{ __('local_procurement.vendor_ui.changes_help') }}
                    </div>
                </div>

                <form action="{{ route('local-supplier.vendor-profile.change-requests.store') }}" method="POST" class="tw-space-y-4">
                    @csrf

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                        <div>
                            <label for="company_name" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_procurement.vendor_ui.company') }}</label>
                            <input type="text" id="company_name" name="company_name" class="form-control form-control-sm" value="{{ old('company_name', $supplier?->company_name) }}">
                        </div>
                        <div>
                            <label for="vendor_category" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_invoice.labels.vendor_category') }}</label>
                            <select id="vendor_category" name="vendor_category" class="form-select form-select-sm">
                                <option value="Barang" @selected(old('vendor_category', $supplier?->vendor_category) === 'Barang')>{{ __('local_procurement.vendor_ui.goods') }}</option>
                                <option value="Jasa" @selected(old('vendor_category', $supplier?->vendor_category) === 'Jasa')>{{ __('local_procurement.vendor_ui.services') }}</option>
                                <option value="Lainnya" @selected(old('vendor_category', $supplier?->vendor_category) === 'Lainnya')>{{ __('local_procurement.vendor_ui.other') }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="npwp" class="form-label tw-text-ui-xs tw-font-medium">NPWP</label>
                            <input type="text" id="npwp" name="npwp" class="form-control form-control-sm" value="{{ old('npwp', $supplier?->npwp) }}">
                        </div>
                        <div>
                            <label for="is_pkp" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_invoice.labels.tax_status') }}</label>
                            <select id="is_pkp" name="is_pkp" class="form-select form-select-sm">
                                <option value="1" @selected(old('is_pkp', $supplier?->is_pkp) == 1)>{{ __('local_procurement.vendor_ui.pkp_required') }}</option>
                                <option value="0" @selected(old('is_pkp', $supplier?->is_pkp) == 0)>Non-PKP</option>
                            </select>
                        </div>
                        <div>
                            <label for="phone" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_invoice.labels.company_phone') }}</label>
                            <input type="text" id="phone" name="phone" class="form-control form-control-sm" value="{{ old('phone', $supplier?->phone) }}">
                        </div>
                        <div class="sm:tw-col-span-2">
                            <label for="address" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_invoice.labels.company_address') }}</label>
                            <textarea id="address" name="address" rows="2" class="form-control form-control-sm">{{ old('address', $supplier?->address) }}</textarea>
                        </div>
                    </div>

                    <hr class="tw-border-outline-variant tw-my-4">
                    <h4 class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-3">{{ __('local_procurement.vendor_ui.pic_information') }}</h4>

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
                        <div>
                            <label for="pic_name" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_procurement.vendor_ui.pic_name') }}</label>
                            <input type="text" id="pic_name" name="pic_name" class="form-control form-control-sm" value="{{ old('pic_name', $supplier?->pic_name) }}">
                        </div>
                        <div>
                            <label for="pic_email" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_invoice.labels.pic_email') }}</label>
                            <input type="email" id="pic_email" name="pic_email" class="form-control form-control-sm" value="{{ old('pic_email', $supplier?->pic_email) }}">
                        </div>
                        <div>
                            <label for="pic_phone" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_procurement.vendor_ui.pic_phone') }}</label>
                            <input type="text" id="pic_phone" name="pic_phone" class="form-control form-control-sm" value="{{ old('pic_phone', $supplier?->pic_phone) }}">
                        </div>
                    </div>

                    <hr class="tw-border-outline-variant tw-my-4">
                    <h4 class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-3">{{ __('local_invoice.labels.proposed_bank') }}</h4>
                    <p class="tw-text-[11px] tw-text-on-surface-variant tw-mb-3">{{ __('local_invoice.labels.bank_change_help') }}</p>

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
                        <div>
                            <x-ui.searchable-select
                                name="bank_name"
                                id="bank_name"
                                :label="__('local_procurement.vendor_ui.bank_name')"
                                :placeholder="__('local_procurement.vendor_ui.bank_select')"
                                :search-placeholder="__('local_procurement.vendor_ui.bank_search')"
                                :options="\App\Support\BankList::options(old('bank_name'))"
                                :value="old('bank_name')"
                            />
                        </div>
                        <div>
                            <label for="account_number" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_procurement.vendor_ui.bank_number') }}</label>
                            <input type="text" id="account_number" name="account_number" class="form-control form-control-sm" placeholder="{{ __('local_procurement.vendor_ui.bank_number') }}" value="{{ old('account_number') }}">
                        </div>
                        <div>
                            <label for="account_holder_name" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_procurement.vendor_ui.account_owner') }}</label>
                            <input type="text" id="account_holder_name" name="account_holder_name" class="form-control form-control-sm" placeholder="{{ __('local_invoice.labels.account_book_name') }}" value="{{ old('account_holder_name') }}">
                        </div>
                    </div>

                    <div class="tw-pt-2 tw-flex tw-justify-end">
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="send" size="sm" />
                            <span>{{ __('local_procurement.vendor_ui.submit_change') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            {{-- Riwayat Pengajuan Perubahan --}}
            <x-ui.card :title="__('local_invoice.labels.change_history')">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('local_invoice.labels.date') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.type') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                                <th scope="col">{{ __('local_procurement.vendor_ui.proposed') }}</th>
                                <th scope="col">{{ __('local_invoice.labels.review_notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($changeRequests as $cr)
                                <tr>
                                    <td class="tw-whitespace-nowrap">{{ $regionalFormatter->timestamp($cr->requested_at, 'datetime') ?: $regionalFormatter->timestamp($cr->created_at, 'datetime') }}</td>
                                    <td><span>{{ match($cr->change_type) { 'profile' => __('local_procurement.vendor_ui.change_profile'), 'bank_account' => __('local_procurement.vendor_ui.change_bank'), default => ucwords(str_replace('_', ' ', $cr->change_type)) } }}</span></td>
                                    <td>
                                        <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($cr->status)">
                                            {{ \App\Support\StatusHelper::localFinanceLabel($cr->status) }}
                                        </x-ui.status-chip>
                                    </td>
                                    <td>
                                        <div class="tw-text-[11px] tw-space-y-0.5 tw-max-w-xs">
                                            @php
                                                $fieldLabels = [
                                                    'company_name' => __('local_procurement.vendor_ui.company'),
                                                    'vendor_category' => __('local_invoice.labels.vendor_category'),
                                                    'category' => __('local_invoice.labels.vendor_category'),
                                                    'npwp' => 'NPWP',
                                                    'is_pkp' => __('local_invoice.labels.tax_status'),
                                                    'address' => __('local_invoice.labels.company_address'),
                                                    'phone' => __('local_invoice.labels.company_phone'),
                                                    'payment_term_days' => __('local_procurement.change_details.payment_term'),
                                                    'pic_name' => __('local_procurement.vendor_ui.pic_name'),
                                                    'pic_email' => __('local_invoice.labels.pic_email'),
                                                    'pic_phone' => __('local_procurement.vendor_ui.pic_phone'),
                                                    'bank_name' => __('local_procurement.vendor_ui.bank_name'),
                                                    'account_number' => __('local_procurement.vendor_ui.bank_number'),
                                                    'account_holder_name' => __('local_procurement.vendor_ui.account_owner'),
                                                ];
                                            @endphp
                                            @foreach($cr->proposed_data ?? [] as $key => $val)
                                                @php
                                                    $displayVal = is_bool($val) ? ($val ? __('local_procurement.change_details.yes') : __('local_procurement.change_details.no')) : $val;
                                                    if ($key === 'is_pkp') {
                                                        $displayVal = ((string) $val === '1' || $val === true || $val === 'true') ? 'PKP' : 'Non-PKP';
                                                    }
                                                @endphp
                                                <div><span class="tw-text-on-surface-variant">{{ $fieldLabels[$key] ?? ucwords(str_replace('_', ' ', $key)) }}:</span> <strong>{{ $displayVal }}</strong></div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @if($cr->review_notes)
                                            <span class="tw-text-ui-xs tw-text-on-surface">{{ $cr->review_notes }}</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center tw-py-4 tw-text-on-surface-variant">{{ __('local_invoice.empty.change_history') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        {{-- Kolom Kanan: Dokumen Legalitas Master --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Form Upload Dokumen Legalitas --}}
            <x-ui.card :title="__('local_invoice.actions.upload_legal')">
                <form action="{{ route('local-supplier.vendor-profile.documents.upload') }}" method="POST" enctype="multipart/form-data" data-async-submit class="tw-space-y-3">
                    @csrf
                    <div>
                        <label for="document_type" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_invoice.labels.document_type') }}</label>
                        <select id="document_type" name="document_type" class="form-select form-select-sm" required>
                            <option value="">{{ __('local_procurement.vendor_ui.document_select') }}</option>
                            <option value="NIB">{{ __('local_procurement.vendor_ui.document_types.nib') }}</option>
                            <option value="NPWP">{{ __('local_procurement.vendor_ui.company_npwp') }}</option>
                            <option value="SPPKP">{{ __('local_procurement.vendor_ui.document_types.sppkp') }}</option>
                            <option value="SURAT_PERNYATAAN_REKENING">{{ __('local_procurement.vendor_ui.bank_declaration') }}</option>
                            <option value="OTHER">{{ __('local_invoice.labels.other_legal') }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="document" class="form-label tw-text-ui-xs tw-font-medium">{{ __('local_procurement.vendor_ui.file_limit') }}</label>
                        <input type="file" id="document" name="document" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>

                    <div class="tw-pt-1">
                        <x-ui.button type="submit" variant="primary" size="sm" class="w-100">
                            <x-ui.icon name="upload" size="sm" />
                            <span>{{ __('local_invoice.actions.upload') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            {{-- Daftar Dokumen Tersimpan --}}
            <x-ui.card :title="__('local_invoice.labels.legal_documents')">
                <div class="tw-space-y-3">
                    @forelse($documents as $doc)
                        <div class="tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-items-center tw-justify-between">
                            <div class="tw-overflow-hidden tw-pr-2">
                                <strong class="tw-text-ui-xs tw-text-on-surface tw-block">{{ __('local_procurement.vendor_ui.document_types.'.strtolower($doc->document_type)) }}</strong>
                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-block" title="{{ $doc->original_filename }}">{{ $doc->original_filename }}</span>
                                <span class="tw-text-[10px] tw-text-on-surface-variant">{{ number_format($doc->file_size / 1024, 1) }} KB · {{ $regionalFormatter->date($doc->created_at) }}</span>
                            </div>
                            <a href="{{ route('supplier-master-documents.show', $doc) }}" target="_blank" class="btn btn-xs btn-outline-primary tw-shrink-0">
                                {{ __('local_invoice.actions.download') }}
                            </a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                            {{ __('local_invoice.empty.legal') }}
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection

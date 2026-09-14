@extends('layouts.app')
@section('title', 'Siapkan DRP GA Draft - General Affairs')
@section('page-title', 'Siapkan DRP GA Draft')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Siapkan Draft DRP GA"
        description="Pilih klaim karyawan yang telah disetujui Ready to Pay untuk disatukan dalam paket DRP Draft sebelum diserahkan ke Finance untuk dieksekusi."
        eyebrow="General Affairs Operations"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Register</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card
        title="Klaim Karyawan Siap Bayar (Ready to Pay)"
        description="Centang klaim yang akan dimasukkan ke dalam batch draft DRP GA baru."
    >
        <form method="POST" action="{{ route('ga.drp-draft.store') }}">
            @csrf
            <div class="tw-space-y-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 40px;">Pilih</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">No. Klaim</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Karyawan Penerima</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tipe Klaim</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Rekening Bank</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Nominal (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($eligibleClaims as $claim)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" class="form-check-input">
                                    </td>
                                    <td>
                                        <strong class="tw-font-mono tw-text-on-surface">{{ $claim->claim_number }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $claim->claim_date?->format('d M Y') }}</span>
                                    </td>
                                    <td>
                                        <strong class="tw-text-on-surface">{{ $claim->employee?->name }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $claim->employee?->department }}</span>
                                    </td>
                                    <td>
                                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                            {{ $claim->claim_type }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="tw-font-medium">{{ $claim->employee?->bank_name }}</span>
                                        <span class="tw-block tw-font-mono tw-text-ui-xs">{{ $claim->employee?->account_number }} a.n {{ $claim->employee?->account_holder_name }}</span>
                                    </td>
                                    <td class="text-end tw-font-mono tw-font-bold tw-text-primary">
                                        Rp {{ number_format($claim->amount, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                        Tidak ada klaim berstatus Ready to Pay yang tersedia untuk DRP baru.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($eligibleClaims->isNotEmpty())
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3">
                        <div class="tw-flex-1">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Catatan pengajuan batch DRP GA (opsional)...">
                        </div>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="check" size="xs" />
                            <span>Kirim Draft DRP ke Finance</span>
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </form>
    </x-ui.card>
</div>
@endsection

@extends('layouts.app')
@section('title', __('finance.closure.ga_verify_title', ['number' => $claim->claim_number]))
@section('page-title', __('ga.verification.title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('ga.detail.title', ['number' => $claim->claim_number])"
        :description="__('ga.detail.description', ['name' => $claim->employee?->name, 'department' => $claim->employee?->department, 'type' => \App\Models\GaClaim::claimTypeLabel($claim->claim_type)])"
        :eyebrow="__('finance.copy_review.finance_ga')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.ga-claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('ga.detail.back') }}</span>
            </x-ui.button>
            @if($claim->receipt)
                <x-ui.button :href="route('ga.claims.receipt', $claim)" variant="outline" size="sm" target="_blank">
                    <x-ui.icon name="printer" size="sm" />
                    <span>{{ __('common.final_copy.print_ga_receipt') }}</span>
                </x-ui.button>
            @endif
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
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Left: Details & Documents --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.card :title="__('ga.labels.details_full')">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.detail.number') }}:</span>
                        <strong class="tw-font-mono tw-text-ui-sm tw-text-on-surface">{{ $claim->claim_number }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.labels.submission_date') }}:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $regionalFormatter->date($claim->claim_date, 'human') }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.labels.claim_status') }}:</span>
                        <x-ui.status-chip :tone="match($claim->status) { 'PAID' => 'success', 'READY_TO_PAY' => 'success', 'NEED_REVISION' => 'error', 'BASIC_VERIFIED' => 'info', default => 'warning' }">
                            {{ \App\Support\StatusHelper::gaClaimLabel($claim->status) }}
                        </x-ui.status-chip>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.labels.employee_payee') }}:</span>
                        <strong class="tw-text-on-surface">{{ $claim->employee?->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.department') }}:</span>
                        <span>{{ $claim->employee?->department }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.labels.claim_type') }}:</span>
                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                            {{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}
                        </span>
                    </div>
                    <div class="tw-col-span-full tw-border-t tw-border-outline-variant tw-pt-3">
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.labels.claim_amount') }}:</span>
                        <span class="tw-font-mono tw-font-bold tw-text-ui-lg tw-text-primary">
                            Rp {{ $regionalFormatter->number(number_format($claim->amount, 0, ',', '.'), 'indonesian') }}
                        </span>
                    </div>
                    @if($claim->description)
                        <div class="tw-col-span-full tw-bg-surface-container tw-p-3 tw-rounded">
                            <span class="tw-text-on-surface-variant tw-block tw-font-semibold">{{ __('common.labels_review.purpose') }}</span>
                            <span class="tw-text-on-surface">{{ $claim->description }}</span>
                        </div>
                    @endif
                    @if($claim->revision_reason)
                        <div class="tw-col-span-full tw-bg-error/10 tw-border tw-border-error/20 tw-p-3 tw-rounded tw-text-error">
                            <span class="tw-block tw-font-semibold">{{ __('finance.copy_review.revision_notes') }}</span>
                            <span>{{ $claim->revision_reason }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Dokumen Pendukung --}}
            <x-ui.card :title="__('ga.verification.supporting_private')">
                <div class="tw-space-y-3">
                    @forelse($claim->documents as $doc)
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <x-ui.icon name="file-text" size="md" class="tw-text-primary" />
                                <div>
                                    <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block">{{ $doc->original_filename }}</span>
                                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ $doc->document_type === 'supporting' ? __('ga.claims.document_supporting') : $doc->document_type }} · {{ __('local_invoice.labels.revision') }} {{ $doc->revision_number }} · {{ number_format($doc->file_size / 1024, 1) }} KB</span>
                                </div>
                            </div>
                            <a href="{{ route('ga-claim-documents.show', $doc) }}" target="_blank" class="btn btn-sm btn-outline-primary tw-text-xs">
                                {{ __('local_invoice.actions.download') }}
                            </a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                            {{ __('ga.verification.empty_documents') }}
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        {{-- Right: Destination Bank & Finance Verification --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Destination Bank --}}
            <x-ui.card :title="__('ga.verification.bank_destination')">
                <div class="tw-space-y-2 tw-text-ui-xs">
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('common.labels_review.bank_label') }}</span>
                        <strong class="tw-text-on-surface">{{ $claim->employee?->bank_name }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('ga.detail.bank_number') }}:</span>
                        <strong class="tw-font-mono tw-text-on-surface">{{ $claim->employee?->account_number }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.account_name') }}:</span>
                        <strong class="tw-text-on-surface">{{ $claim->employee?->account_holder_name }}</strong>
                    </div>
                    <div class="tw-pt-2 tw-text-[11px] tw-text-on-surface-variant">
                        {{ __('ga.form.bank_snapshot') }}
                    </div>
                </div>
            </x-ui.card>

            {{-- Verification Action --}}
            <x-ui.card :title="__('ga.verification.actions')">
                @if($claim->status === \App\Models\GaClaim::STATUS_BASIC_VERIFIED)
                    <div class="tw-space-y-4">
                        <p class="tw-text-ui-xs tw-text-on-surface-variant">
                            {{ __('ga.verification.help') }}
                        </p>

                        {{-- Approve Button Form --}}
                        <form method="POST" action="{{ route('finance.ga-claims.verify', $claim) }}" onsubmit="event.preventDefault(); window.AdasiAlert.confirm({title: {{ json_encode(__('ga.verification.approve_title'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, text: {{ json_encode(__('ga.verification.approve_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, confirmText: {{ json_encode(__('ga.verification.approve_yes'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}, cancelText: {{ json_encode(__('local_invoice.actions.cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}}).then(r => { if (r.isConfirmed) this.submit(); });">
                            @csrf
                            <input type="hidden" name="approve" value="1">
                            <x-ui.button type="submit" variant="primary" size="sm" class="w-100">
                                <x-ui.icon name="check-circle" size="sm" />
                                <span>{{ __('ga.verification.approve') }}</span>
                            </x-ui.button>
                        </form>

                        <hr class="tw-border-outline-variant tw-my-2">

                        {{-- Request Revision Form --}}
                        <div>
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm w-100"
                                data-bs-toggle="collapse"
                                data-bs-target="#revisionCollapse"
                            >
                                <x-ui.icon name="alert-circle" size="sm" />
                                <span>{{ __('ga.verification.request_revision') }}</span>
                            </button>

                            <div class="collapse tw-mt-3" id="revisionCollapse">
                                <form method="POST" action="{{ route('finance.ga-claims.verify', $claim) }}" class="tw-space-y-2">
                                    @csrf
                                    <input type="hidden" name="approve" value="0">
                                    <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.labels.revision_required') }} <span class="text-danger">*</span></label>
                                    <textarea name="reason" rows="3" class="form-control form-control-sm" required placeholder="{{ __('ga.verification.reason_example') }}"></textarea>
                                    <button type="submit" class="btn btn-danger btn-sm w-100">
                                        {{ __('ga.verification.send_revision') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_SUBMITTED)
                    <div class="tw-p-3 tw-rounded tw-bg-warning/10 tw-border tw-border-warning/20 tw-text-warning tw-text-ui-xs">
                        <strong>{{ __('ga.verification.await_basic') }}</strong>
                        <p class="tw-m-0 tw-mt-1">{{ __('ga.verification.await_basic_help') }}</p>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_READY_TO_PAY)
                    <div class="tw-p-3 tw-rounded tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-xs">
                        <strong>{{ __('local_invoice.labels.ready_to_pay') }}</strong>
                        <p class="tw-m-0 tw-mt-1">{{ __('ga.verification.ready_help') }}</p>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_PAID)
                    <div class="tw-p-3 tw-rounded tw-bg-primary/10 tw-border tw-border-primary/20 tw-text-primary tw-text-ui-xs">
                        <strong>{{ __('ga.verification.paid') }}</strong>
                        <p class="tw-m-0 tw-mt-1">{{ __('ga.verification.paid_help') }}</p>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_NEED_REVISION)
                    <div class="tw-p-3 tw-rounded tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-xs">
                        <strong>{{ __('ga.verification.await_revision') }}</strong>
                        <p class="tw-m-0 tw-mt-1">{{ __('ga.verification.revision_help') }}</p>
                    </div>
                @endif
            </x-ui.card>

            {{-- Timeline --}}
            <x-ui.card :title="__('local_invoice.labels.status_history')">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    @forelse($claim->statusHistories as $hist)
                        <div class="tw-p-2 tw-rounded tw-bg-surface-container">
                            <div class="tw-flex tw-justify-between">
                                <strong>{{ \App\Models\GaClaim::eventLabel($hist->event) }}</strong>
                                <span class="tw-text-on-surface-variant">{{ $regionalFormatter->timestamp($hist->created_at, 'short_datetime') }}</span>
                            </div>
                            <div class="tw-text-on-surface-variant">{{ __('ga.detail.actor', ['name' => $hist->actor?->name ?? __('ga.detail.system')]) }}</div>
                            @if($hist->notes)
                                <div class="tw-mt-1 tw-text-[11px] tw-italic">{{ $hist->notes }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="tw-text-on-surface-variant">{{ __('local_invoice.empty.activity') }}</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection

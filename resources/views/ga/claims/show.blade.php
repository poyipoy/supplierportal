@extends('layouts.app')
@section('title', __('ga.detail.title', ['number' => $claim->claim_number]).' - GA')
@section('page-title', __('ga.labels.claim_details'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('ga.detail.title', ['number' => $claim->claim_number])"
        :description="__('ga.detail.description', ['name' => $claim->employee?->name, 'department' => $claim->employee?->department, 'type' => \App\Models\GaClaim::claimTypeLabel($claim->claim_type)])"
        :eyebrow="__('ga.review.detail_eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
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

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Left: Information & Documents --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.card :title="__('ga.labels.details')">
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
                        <x-ui.status-chip :tone="\App\Support\StatusHelper::gaClaimTone($claim->status)">
                            {{ \App\Support\StatusHelper::gaClaimLabel($claim->status) }}
                        </x-ui.status-chip>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">{{ __('ga.labels.employee') }}:</span>
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
                            <span class="tw-text-on-surface-variant tw-block tw-font-semibold">{{ __('ga.detail.remarks') }}:</span>
                            <span class="tw-text-on-surface">{{ $claim->description }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Dokumen Pendukung --}}
            <x-ui.card :title="__('ga.labels.supporting_documents')">
                <div class="tw-space-y-3">
                    @forelse($claim->documents as $doc)
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <x-ui.icon name="file-text" size="md" class="tw-text-primary" />
                                <div>
                                    <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block">{{ $doc->original_filename }}</span>
                                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ $doc->document_type === 'supporting' ? __('ga.claims.document_supporting') : $doc->document_type }} · {{ __('local_invoice.labels.revision') }} {{ $doc->revision_number }}</span>
                                </div>
                            </div>
                            <a href="{{ route('ga-claim-documents.show', $doc) }}" target="_blank" class="btn btn-sm btn-outline-primary tw-text-xs">
                                {{ __('local_invoice.actions.download') }}
                            </a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                            {{ __('local_invoice.empty.documents') }}
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        {{-- Right: Destination Bank & Actions --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Rekening Tujuan --}}
            <x-ui.card :title="__('ga.labels.bank_account')">
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
                </div>
            </x-ui.card>

            {{-- Action for GA: Need Revision Resubmit --}}
            @if($claim->status === \App\Models\GaClaim::STATUS_NEED_REVISION)
                <x-ui.card :title="__('ga.detail.revision_request')">
                    <div class="tw-space-y-3">
                        <div class="tw-p-3 tw-rounded tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-xs">
                            <strong>{{ __('local_invoice.labels.revision_reason') }}:</strong>
                            <p class="tw-m-0 tw-mt-1">{{ $claim->revision_reason ?: __('ga.detail.revision_hint') }}</p>
                        </div>
                        <x-ui.button :href="route('ga.claims.revision', $claim)" variant="primary" size="sm" class="w-100">
                            <x-ui.icon name="edit" size="sm" />
                            <span>{{ __('ga.detail.resubmit') }}</span>
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endif

            {{-- Basic Verification Action for GA --}}
            @if($claim->status === \App\Models\GaClaim::STATUS_SUBMITTED)
                <x-ui.card :title="__('ga.verification.basic')">
                    <form method="POST" action="{{ route('ga.claims.basic-verify', $claim) }}">
                        @csrf
                        <div class="tw-space-y-3">
                            <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                {{ __('ga.detail.basic_hint') }}
                            </p>
                            <div>
                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="{{ __('ga.verification.notes_optional') }}">
                            </div>
                            <x-ui.button type="submit" variant="primary" size="sm" class="w-100">
                                <x-ui.icon name="check" size="sm" />
                                <span>{{ __('ga.detail.confirm_basic') }}</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- Status Timeline --}}
            <x-ui.card :title="__('ga.labels.claim_history')">
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

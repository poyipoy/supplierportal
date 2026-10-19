@extends('layouts.app')

@php $companyName = $profile?->company_name ?: $vendor->name; @endphp
@section('title', $companyName)
@section('page-title', __('supplier_contact.directory.title'))

@section('content')
@php
    $fieldLabels = [
        'pic_name' => __('supplier_contact.fields.name'),
        'pic_email' => __('supplier_contact.fields.email'),
        'pic_phone' => __('supplier_contact.fields.phone'),
    ];
    $notSet = __('supplier_contact.common.not_set');
@endphp
<div class="tw-grid tw-gap-4">
    <x-ui.breadcrumb :items="[
        __('supplier_contact.directory.back') => \App\Support\PurchasingNavigation::listUrl('purchasing.import-suppliers.index'),
        $companyName => null,
    ]" />

    <x-ui.page-header :title="$companyName" :description="$vendor->email">
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.import-suppliers.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_contact.directory.back') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($pendingRequest)
        @php $proposed = $pendingRequest->proposed_data ?? []; @endphp
        <section aria-labelledby="pending-contact-title" class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-warning tw-bg-surface">
            <header class="tw-flex tw-flex-col tw-gap-1 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
                <h2 id="pending-contact-title" class="ui-text-balance tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">{{ __('supplier_contact.directory.pending_title') }}</h2>
                <span class="ui-tabular-nums tw-text-ui-xs tw-text-on-surface-variant">
                    {{ __('supplier_contact.directory.pending_meta', ['date' => $regionalFormatter->timestamp($pendingRequest->requested_at, 'datetime')]) }}
                </span>
            </header>

            <div class="table-responsive">
                <table class="table align-middle tw-m-0 tw-text-ui-sm">
                    <caption class="tw-sr-only">{{ __('supplier_contact.directory.pending_title') }}</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="tw-w-1/4 tw-text-ui-xs tw-font-semibold"><span class="tw-sr-only">{{ __('supplier_contact.common.contact_title') }}</span></th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_contact.directory.current_title') }}</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_contact.common.proposed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($fieldLabels as $field => $label)
                            @php
                                $currentValue = $profile?->{$field};
                                $proposedValue = $proposed[$field] ?? null;
                                $changed = (string) $currentValue !== (string) $proposedValue;
                            @endphp
                            <tr>
                                <th scope="row" class="tw-font-normal tw-text-on-surface-variant">{{ $label }}</th>
                                <td class="tw-break-all {{ $changed ? 'tw-text-on-surface-variant' : '' }}">{{ $currentValue ?: $notSet }}</td>
                                <td class="tw-break-all">
                                    <span class="{{ $changed ? 'tw-font-semibold tw-text-on-surface' : '' }}">{{ $proposedValue ?: $notSet }}</span>
                                    @if($changed)
                                        <x-ui.status-chip tone="info" size="sm" class="tw-ms-2">{{ __('local_procurement.change_details.changed') }}</x-ui.status-chip>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="tw-flex tw-flex-col tw-gap-3 tw-border-t tw-border-outline-variant tw-px-4 tw-py-3 sm:tw-flex-row sm:tw-items-start sm:tw-justify-end">
                <details class="sm:tw-order-1" @if($errors->has('notes')) open @endif>
                    <summary class="ui-focus-ring tw-inline-flex tw-min-h-[var(--ui-control-height-md)] tw-cursor-pointer tw-list-none tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-px-3.5 tw-text-ui-sm tw-font-medium tw-text-error hover:tw-bg-surface-container">
                        <x-ui.icon name="x" size="sm" />
                        <span>{{ __('supplier_contact.directory.reject') }}</span>
                    </summary>
                    <form method="POST" action="{{ route('purchasing.import-suppliers.contact-requests.reject', $pendingRequest) }}" class="tw-mt-3 tw-grid tw-gap-3 sm:tw-w-96" aria-label="{{ __('supplier_contact.directory.reject_title') }}">
                        @csrf
                        <x-ui.textarea name="notes" id="reject-contact-notes" :label="__('supplier_contact.fields.reject_notes')" :placeholder="__('supplier_contact.placeholders.reject_notes')" rows="3" maxlength="1000" required />
                        <div class="tw-flex tw-justify-end">
                            <x-ui.button type="submit" variant="danger">{{ __('supplier_contact.directory.reject_submit') }}</x-ui.button>
                        </div>
                    </form>
                </details>
                <form
                    method="POST"
                    action="{{ route('purchasing.import-suppliers.contact-requests.approve', $pendingRequest) }}"
                    class="sm:tw-order-2"
                    onsubmit="event.preventDefault(); window.AdasiAlert.confirm({title: @js(__('supplier_contact.directory.approve_title')), text: @js(__('supplier_contact.directory.approve_body')), confirmText: @js(__('supplier_contact.directory.approve')), cancelText: @js(__('supplier_contact.directory.cancel'))}).then(r => { if (r.isConfirmed) this.submit(); });"
                >
                    @csrf
                    <x-ui.button type="submit" variant="primary">
                        <x-ui.icon name="check" size="sm" />
                        <span>{{ __('supplier_contact.directory.approve') }}</span>
                    </x-ui.button>
                </form>
            </div>
        </section>
    @endif

    <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-2">
        <x-ui.card :title="__('supplier_contact.directory.current_title')">
            @include('supplier-contact._contact', ['profile' => $profile])
        </x-ui.card>

        <x-ui.card :title="__('supplier_contact.directory.edit_title')" :description="__('supplier_contact.directory.edit_help')">
            <form method="POST" action="{{ route('purchasing.import-suppliers.contact.update', $vendor) }}" class="tw-grid tw-gap-3" novalidate>
                @csrf
                @method('PATCH')
                <x-ui.input name="pic_name" id="direct-pic-name" :label="__('supplier_contact.fields.name')" :value="$profile?->pic_name" :placeholder="__('supplier_contact.placeholders.name')" autocomplete="off" maxlength="100" required />
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2">
                    <x-ui.input name="pic_email" id="direct-pic-email" type="email" :label="__('supplier_contact.fields.email')" :value="$profile?->pic_email" :placeholder="__('supplier_contact.placeholders.email')" autocomplete="off" maxlength="100" required />
                    <x-ui.input name="pic_phone" id="direct-pic-phone" type="tel" :label="__('supplier_contact.fields.phone')" :value="$profile?->pic_phone" :placeholder="__('supplier_contact.placeholders.phone')" autocomplete="off" maxlength="30" required />
                </div>
                <x-ui.textarea name="reason" id="direct-contact-reason" :label="__('supplier_contact.fields.reason')" :placeholder="__('supplier_contact.placeholders.reason')" rows="2" maxlength="1000" required />
                <div class="tw-flex tw-justify-end">
                    <x-ui.button type="submit" variant="outline">{{ __('supplier_contact.directory.save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    @include('supplier-contact._history', ['requests' => $contactRequests])
</div>
@endsection

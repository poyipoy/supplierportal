@extends('layouts.app')

@section('title', __('supplier_contact.profile.title'))
@section('page-title', __('supplier_contact.profile.title'))

@section('content')
@php
    $hasContact = filled($profile?->pic_name);
    $formSource = $pendingRequest?->proposed_data ?? [
        'pic_name' => $profile?->pic_name,
        'pic_email' => $profile?->pic_email,
        'pic_phone' => $profile?->pic_phone,
    ];
@endphp
<div class="tw:grid tw:gap-4">
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('supplier_contact.profile.title') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier_contact.profile.title')"
        :description="__('supplier_contact.profile.description')"
    />

    @if($pendingRequest)
        <x-ui.alert tone="info" :title="__('supplier_contact.profile.pending_title')">
            {{ __('supplier_contact.profile.pending_body', ['date' => $regionalFormatter->timestamp($pendingRequest->requested_at, 'datetime')]) }}
        </x-ui.alert>
    @elseif(! $hasContact)
        <x-ui.alert tone="warning" :title="__('supplier_contact.profile.missing_title')">
            {{ __('supplier_contact.profile.missing_body') }}
        </x-ui.alert>
    @endif

    <div class="tw:grid tw:items-start tw:gap-4 tw:lg:grid-cols-2">
        <x-ui.card :title="__('supplier_contact.profile.company_title')" :description="__('supplier_contact.profile.company_help')">
            <dl class="tw:m-0 tw:grid tw:grid-cols-[auto_minmax(0,1fr)] tw:gap-x-4 tw:gap-y-2 tw:text-ui-sm">
                <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('supplier_contact.profile.company_name') }}</dt>
                <dd class="tw:m-0 tw:font-semibold tw:text-on-surface tw:wrap-break-word">{{ $profile?->company_name ?: $user->name }}</dd>
                <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('supplier_contact.profile.address') }}</dt>
                <dd class="tw:m-0 tw:wrap-break-word">{{ $profile?->address ?: '–' }}</dd>
                <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('supplier_contact.profile.phone') }}</dt>
                <dd class="tw:m-0">{{ $profile?->phone ?: '–' }}</dd>
                <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('supplier_contact.profile.account_email') }}</dt>
                <dd class="tw:m-0 tw:break-all">{{ $user->email }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('supplier_contact.common.contact_title')">
            @include('supplier-contact._contact', ['profile' => $profile])

            <form
                method="POST"
                action="{{ route('supplier.company-profile.contact-requests.store') }}"
                class="tw:mt-4 tw:grid tw:gap-3 tw:border-t tw:border-outline-variant tw:pt-4"
                aria-labelledby="contact-propose-title"
                data-async-submit
                novalidate
            >
                @csrf
                <div>
                    <h3 id="contact-propose-title" class="ui-text-balance tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('supplier_contact.profile.propose_title') }}</h3>
                    <p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('supplier_contact.profile.propose_help') }}</p>
                </div>
                <x-ui.input name="pic_name" id="company-pic-name" :label="__('supplier_contact.fields.name')" :value="$formSource['pic_name'] ?? null" :placeholder="__('supplier_contact.placeholders.name')" autocomplete="name" maxlength="100" required />
                <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                    <x-ui.input name="pic_email" id="company-pic-email" type="email" :label="__('supplier_contact.fields.email')" :value="$formSource['pic_email'] ?? null" :placeholder="__('supplier_contact.placeholders.email')" autocomplete="email" maxlength="100" required />
                    <x-ui.input name="pic_phone" id="company-pic-phone" type="tel" :label="__('supplier_contact.fields.phone')" :value="$formSource['pic_phone'] ?? null" :placeholder="__('supplier_contact.placeholders.phone')" autocomplete="tel" maxlength="30" required />
                </div>
                <div class="tw:flex tw:justify-end">
                    <x-ui.button type="submit" variant="primary">
                        <x-ui.icon name="send" size="sm" />
                        <span>{{ __('supplier_contact.profile.submit') }}</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    @include('supplier-contact._history', ['requests' => $contactRequests])
</div>
@endsection

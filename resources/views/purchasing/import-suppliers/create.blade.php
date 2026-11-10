@extends('layouts.app')

@section('title', __('supplier_contact.register.title'))
@section('page-title', __('supplier_contact.directory.title'))

@section('content')
<div class="tw:grid tw:gap-4">
    <x-ui.breadcrumb :items="[
        __('supplier_contact.directory.back') => \App\Support\PurchasingNavigation::listUrl('purchasing.import-suppliers.index'),
        __('supplier_contact.register.title') => null,
    ]" />

    <x-ui.page-header :title="__('supplier_contact.register.title')" :description="__('supplier_contact.register.description')" />

    <form method="POST" action="{{ route('purchasing.import-suppliers.store') }}" class="tw:max-w-2xl" novalidate>
        @csrf
        <x-ui.card :title="__('supplier_contact.register.account_title')" :description="__('supplier_contact.register.account_help')">
            <div class="tw:grid tw:gap-4">
                <x-ui.input
                    name="company_name"
                    id="register-company-name"
                    :label="__('supplier_contact.profile.company_name')"
                    :placeholder="__('supplier_contact.placeholders.company_name')"
                    autocomplete="organization"
                    maxlength="255"
                    required
                />
                <x-ui.input
                    name="email"
                    id="register-email"
                    type="email"
                    :label="__('supplier_contact.profile.account_email')"
                    :placeholder="__('supplier_contact.placeholders.email')"
                    autocomplete="email"
                    maxlength="255"
                    required
                />
                <div class="tw:grid tw:items-start tw:gap-4 tw:sm:grid-cols-2">
                    <x-ui.input
                        name="password"
                        id="register-password"
                        type="password"
                        :label="__('supplier_contact.register.password')"
                        :helper="__('supplier_contact.register.password_help')"
                        autocomplete="new-password"
                        required
                    />
                    <x-ui.input
                        name="password_confirmation"
                        id="register-password-confirmation"
                        type="password"
                        :label="__('supplier_contact.register.password_confirmation')"
                        autocomplete="new-password"
                        required
                    />
                </div>
            </div>

            <x-slot:footer>
                <div class="tw:flex tw:items-center tw:justify-end tw:gap-2.5">
                    <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.import-suppliers.index')" variant="ghost">
                        {{ __('supplier_contact.directory.cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary">
                        <x-ui.icon name="user-plus" size="sm" />
                        <span>{{ __('supplier_contact.register.submit') }}</span>
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>
@endsection

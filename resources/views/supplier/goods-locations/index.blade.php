@extends('layouts.app')

@section('title', __('shipments.preshipment.locations_title').' - ADASI Portal')
@section('page-title', __('shipments.preshipment.locations_title'))

@section('content')
@php
    $atLimit = $activeCount >= $maxLocations;
    $hasFormErrors = $errors->any();
    $oldFormMode = old('_location_form_mode', 'create');
    $oldEditHash = old('_location_edit_hash', '');
    $initialCountry = \App\Support\CountryCatalog::normalizeCode(old('country', '')) ?? '';
    $countryOptions = collect($countries)->map(fn (string $label, string $code) => [
        'value' => $code,
        'label' => $label,
        'sublabel' => $code,
        'searchKeywords' => "{$label} {$code}",
    ])->values()->all();
@endphp

<div
    class="tw:grid tw:gap-4"
    x-data="{
        mode: @js($oldFormMode),
        formAction: @js($oldFormMode === 'edit' && $oldEditHash ? route('supplier.goods-locations.update', $oldEditHash) : route('supplier.goods-locations.store')),
        editHash: @js($oldEditHash),
        fields: {
            name: @js(old('name', '')),
            contact: @js(old('contact', '')),
            address: @js(old('address', '')),
            city: @js(old('city', '')),
            country: @js($initialCountry),
            country_unmapped: @js(''),
            telephone: @js(old('telephone', '')),
            mobile: @js(old('mobile', '')),
            fax: @js(old('fax', '')),
            email: @js(old('email', '')),
            is_default: @js((bool) old('is_default', false)),
        },
        clearValidation() {
            const form = document.getElementById('goodsLocationModalForm');
            if (!form) return;
            form.querySelectorAll('.invalid-feedback, [data-async-error]').forEach((el) => el.remove());
            form.querySelectorAll('.is-invalid, [data-async-invalid]').forEach((el) => {
                el.classList.remove('is-invalid');
                el.removeAttribute('aria-invalid');
                el.removeAttribute('data-async-invalid');
            });
        },
        syncCountrySelect(value) {
            window.dispatchEvent(new CustomEvent('set-select-value', {
                detail: { name: 'country', value: String(value || ''), silent: true },
            }));
        },
        openCreate() {
            this.clearValidation();
            this.mode = 'create';
            this.editHash = '';
            this.formAction = @js(route('supplier.goods-locations.store'));
            this.fields = { name: '', contact: '', address: '', city: '', country: '', country_unmapped: '', telephone: '', mobile: '', fax: '', email: '', is_default: false };
            this.syncCountrySelect('');
            window.AdasiUnsaved?.resetSnapshot?.(document.getElementById('goodsLocationModalForm'));
            this.$dispatch('open-ui-dialog', 'goods-location-form');
        },
        openEdit(loc) {
            this.clearValidation();
            this.mode = 'edit';
            this.editHash = loc.hash;
            this.formAction = loc.update_url;
            this.fields = {
                name: loc.name || '',
                contact: loc.contact || '',
                address: loc.address || '',
                city: loc.city || '',
                country: loc.country || '',
                country_unmapped: loc.country_unmapped || '',
                telephone: loc.telephone || '',
                mobile: loc.mobile || '',
                fax: loc.fax || '',
                email: loc.email || '',
                is_default: Boolean(loc.is_default),
            };
            this.syncCountrySelect(this.fields.country);
            window.AdasiUnsaved?.resetSnapshot?.(document.getElementById('goodsLocationModalForm'));
            this.$dispatch('open-ui-dialog', 'goods-location-form');
        }
    }"
    x-on:close-ui-dialog.window="if ($event.detail === 'goods-location-form' || $event.detail?.name === 'goods-location-form') {
        clearValidation();
        window.AdasiUnsaved?.resetSnapshot?.(document.getElementById('goodsLocationModalForm'));
    }"
>
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.shipments') => route('supplier.shipments.index'),
        __('shipments.preshipment.locations_title') => null,
    ]" />

    <x-ui.page-header
        :title="__('shipments.preshipment.locations_title')"
        :eyebrow="__('shipments.preshipment.title')"
        :description="__('shipments.preshipment.locations_description')"
    >
        <x-slot:actions>
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">
                    {{ __('shipments.preshipment.locations_count', ['count' => $activeCount, 'max' => $maxLocations]) }}
                </span>
                <x-ui.button :href="route('supplier.shipments.index')" variant="outline" size="sm">
                    <x-slot:leading><x-ui.icon name="arrow-left" size="sm" /></x-slot:leading>
                    <span>{{ __('shipments.copy.back_to_shipments') }}</span>
                </x-ui.button>
                <x-ui.button type="button" variant="primary" size="sm" @click="openCreate()" :disabled="$atLimit">
                    <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                    <span>{{ __('shipments.preshipment.locations_add') }}</span>
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if($atLimit)
        <x-ui.alert tone="warning">
            {{ __('shipments.preshipment.location_limit_reached', ['max' => $maxLocations]) }}
        </x-ui.alert>
    @endif

    @php
        $goodsLocResetUrl = route('supplier.goods-locations.index');
        $goodsLocChips = [];
        if ($search !== '') {
            $goodsLocChips[] = [
                'key' => 'search',
                'label' => __('shipments.copy.search'),
                'value' => (string) $search,
                'removeUrl' => $goodsLocResetUrl,
            ];
        }
    @endphp

    <x-ui.data-table :title="__('shipments.preshipment.locations_title')">
        <x-slot:filters>
            <x-ui.filter-bar
                id="goodsLocationsFilterForm"
                :action="$goodsLocResetUrl"
                variant="embedded"
                :has-active-filters="$search !== ''"
                :reset-url="$goodsLocResetUrl"
                :reset-label="__('shipments.copy.reset')"
                :submit-label="__('shipments.copy.search')"
            >
                <x-slot:search>
                    <label for="goodsLocationsSearch" class="tw:sr-only">{{ __('shipments.copy.search') }}</label>
                    <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                    <input
                        type="search"
                        id="goodsLocationsSearch"
                        name="search"
                        value="{{ $search }}"
                        class="form-control form-control-sm ui-filter-bar__search-input"
                        placeholder="{{ __('shipments.preshipment.locations_search_placeholder') }}"
                    >
                </x-slot:search>

                <x-slot:chips>
                    <x-ui.filter-chips :items="$goodsLocChips" :clear-url="$goodsLocResetUrl" />
                </x-slot:chips>
            </x-ui.filter-bar>
        </x-slot:filters>

        @if($locations->isEmpty())
            @if($search !== '')
                <x-ui.empty-state
                    icon="search"
                    :title="__('shipments.preshipment.locations_search_empty_title')"
                    :description="__('shipments.preshipment.locations_search_empty_description')"
                >
                    <x-ui.button :href="route('supplier.goods-locations.index')" variant="outline" size="sm">
                        <span>{{ __('shipments.copy.reset') }}</span>
                    </x-ui.button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state
                    icon="map-pin"
                    :title="__('shipments.preshipment.locations_empty_title')"
                    :description="__('shipments.preshipment.locations_empty_description')"
                >
                    @unless($atLimit)
                        <x-ui.button type="button" variant="primary" size="sm" @click="openCreate()">
                            <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                            <span>{{ __('shipments.preshipment.locations_add') }}</span>
                        </x-ui.button>
                    @endunless
                </x-ui.empty-state>
            @endif
        @else
            <div class="ui-data-table__scroll">
                <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('shipments.preshipment.name') }}</th>
                            <th scope="col">{{ __('shipments.preshipment.address') }}</th>
                            <th scope="col">{{ __('shipments.preshipment.city') }} / {{ __('shipments.preshipment.country') }}</th>
                            <th scope="col">{{ __('shipments.preshipment.contact') }}</th>
                            <th scope="col" class="text-end">{{ __('shipments.copy.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($locations as $loc)
                            @php
                                $normalizedLocCountry = \App\Support\CountryCatalog::normalizeCode($loc->country);
                                $editPayload = [
                                    'hash' => $loc->getRouteKey(),
                                    'update_url' => route('supplier.goods-locations.update', $loc),
                                    'name' => $loc->name,
                                    'contact' => $loc->contact,
                                    'address' => $loc->address,
                                    'city' => $loc->city,
                                    'country' => $normalizedLocCountry ?? '',
                                    'country_unmapped' => $loc->country && $normalizedLocCountry === null ? $loc->country : '',
                                    'telephone' => $loc->telephone,
                                    'mobile' => $loc->mobile,
                                    'fax' => $loc->fax,
                                    'email' => $loc->email,
                                    'is_default' => (bool) $loc->is_default,
                                ];
                            @endphp
                            <tr>
                                <td>
                                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-1.5">
                                        <span class="tw:font-semibold tw:text-on-surface">{{ $loc->name }}</span>
                                        @if($loc->is_default)
                                            <x-ui.status-chip tone="info" size="sm">{{ __('shipments.preshipment.location_default_tag') }}</x-ui.status-chip>
                                        @endif
                                    </div>
                                </td>
                                <td class="tw:text-ui-xs tw:text-on-surface-variant" style="max-width: 24rem;">
                                    <div class="tw:break-words">{{ $loc->address }}</div>
                                </td>
                                <td class="tw:text-ui-xs tw:text-on-surface">
                                    {{ $loc->city }}, {{ \App\Support\CountryCatalog::label($loc->country) }}
                                </td>
                                <td class="tw:text-ui-xs tw:text-on-surface-variant">
                                    <div>{{ $loc->contact ?: '-' }}</div>
                                    @if($loc->telephone || $loc->email)
                                        <div class="tw:text-on-surface-variant">{{ implode(' · ', array_filter([$loc->telephone, $loc->email])) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center justify-content-end gap-1">
                                        <button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring" @click="openEdit(@js($editPayload))">
                                            {{ __('shipments.preshipment.locations_edit') }}
                                        </button>
                                        <div class="dropdown">
                                            <button
                                                type="button"
                                                class="ui-data-action ui-focus-ring dropdown-toggle"
                                                data-bs-toggle="dropdown"
                                                data-bs-popper-config='{"strategy":"fixed"}'
                                                aria-expanded="false"
                                                aria-label="{{ __('shipments.copy.more') }} {{ $loc->name }}"
                                            >
                                                {{ __('shipments.copy.more') }}
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                @unless($loc->is_default)
                                                    <li>
                                                        <form method="POST" action="{{ route('supplier.goods-locations.default', $loc) }}" class="tw:m-0">
                                                            @csrf
                                                            <button type="submit" class="dropdown-item">
                                                                {{ __('shipments.preshipment.locations_set_default') }}
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endunless
                                                <li>
                                                    <form method="POST" action="{{ route('supplier.goods-locations.destroy', $loc) }}" class="tw:m-0 goods-location-delete-form">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="button" class="dropdown-item text-danger btn-delete-location">
                                                            {{ __('shipments.mtc.remove') }}
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($locations->hasPages())
                <div class="tw:p-3 tw:border-t tw:border-outline-variant">
                    {{ $locations->links() }}
                </div>
            @endif
        @endif
    </x-ui.data-table>

    <x-ui.dialog
        name="goods-location-form"
        :title="__('shipments.preshipment.locations_title')"
        max-width="2xl"
        :show="$hasFormErrors"
    >
        <x-slot:header>
            <h2
                id="goods-location-form-title"
                class="tw:m-0 tw:text-ui-xl tw:font-semibold tw:text-on-surface"
                x-text="mode === 'edit' ? @js(__('shipments.preshipment.locations_edit')) : @js(__('shipments.preshipment.locations_add'))"
            ></h2>
        </x-slot:header>

        <form method="POST" :action="formAction" id="goodsLocationModalForm" class="tw:grid tw:gap-3">
            @csrf
            <template x-if="mode === 'edit'">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="_location_form_mode" :value="mode">
            <input type="hidden" name="_location_edit_hash" :value="editHash">

            <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                <div class="tw:sm:col-span-2">
                    <label for="loc_name" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.name') }} <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>
                    </label>
                    <input type="text" name="name" id="loc_name" x-model="fields.name" maxlength="191" required aria-required="true"
                        @error('name') aria-invalid="true" aria-describedby="loc_name_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('name') is-invalid @enderror">
                    @error('name')<div id="loc_name_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div class="tw:sm:col-span-2">
                    <label for="loc_contact" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.contact') }}
                    </label>
                    <input type="text" name="contact" id="loc_contact" x-model="fields.contact" maxlength="150"
                        @error('contact') aria-invalid="true" aria-describedby="loc_contact_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('contact') is-invalid @enderror">
                    @error('contact')<div id="loc_contact_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div class="tw:sm:col-span-2">
                    <label for="loc_address" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.address') }} <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>
                    </label>
                    <textarea name="address" id="loc_address" x-model="fields.address" rows="2" maxlength="1000" required aria-required="true"
                        @error('address') aria-invalid="true" aria-describedby="loc_address_error" @enderror
                        class="form-control form-control-sm @error('address') is-invalid @enderror"></textarea>
                    @error('address')<div id="loc_address_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div @change="if ($event.target?.tagName === 'SELECT') { fields.country = $event.target.value; fields.country_unmapped = ''; }">
                    <x-ui.searchable-select
                        name="country"
                        id="loc_country"
                        :label="__('shipments.preshipment.country')"
                        label-class="form-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                        :value="$initialCountry"
                        :options="$countryOptions"
                        :placeholder="__('shipments.preshipment.select_country')"
                        :required="true"
                        :show-sublabel-on-trigger="false"
                        :error="$errors->first('country')"
                    />
                    <p x-show="fields.country_unmapped" x-cloak class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-warning"
                       x-text="@js(__('shipments.preshipment.legacy_country_notice', ['value' => ':val'])).replace(':val', fields.country_unmapped)"></p>
                </div>

                <div>
                    <label for="loc_city" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.city') }} <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>
                    </label>
                    <input type="text" name="city" id="loc_city" x-model="fields.city" maxlength="120" required aria-required="true"
                        @error('city') aria-invalid="true" aria-describedby="loc_city_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('city') is-invalid @enderror">
                    @error('city')<div id="loc_city_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="loc_telephone" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.telephone') }}
                    </label>
                    <input type="tel" inputmode="tel" name="telephone" id="loc_telephone" x-model="fields.telephone" maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('telephone') aria-invalid="true" aria-describedby="loc_telephone_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('telephone') is-invalid @enderror">
                    @error('telephone')<div id="loc_telephone_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="loc_mobile" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.mobile') }}
                    </label>
                    <input type="tel" inputmode="tel" name="mobile" id="loc_mobile" x-model="fields.mobile" maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('mobile') aria-invalid="true" aria-describedby="loc_mobile_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('mobile') is-invalid @enderror">
                    @error('mobile')<div id="loc_mobile_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="loc_fax" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.fax') }}
                    </label>
                    <input type="tel" inputmode="tel" name="fax" id="loc_fax" x-model="fields.fax" maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('fax') aria-invalid="true" aria-describedby="loc_fax_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('fax') is-invalid @enderror">
                    @error('fax')<div id="loc_fax_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label for="loc_email" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.email') }}
                    </label>
                    <input type="email" name="email" id="loc_email" x-model="fields.email" maxlength="191"
                        @error('email') aria-invalid="true" aria-describedby="loc_email_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('email') is-invalid @enderror">
                    @error('email')<div id="loc_email_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-check tw:mb-0 tw:pt-2 tw:border-t tw:border-outline-variant">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" class="form-check-input" name="is_default" id="loc_is_default" value="1" x-model="fields.is_default">
                <label class="form-check-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:cursor-pointer" for="loc_is_default">
                    {{ __('shipments.preshipment.set_location_default') }}
                </label>
            </div>
        </form>

        <x-slot:actions>
            <x-ui.button type="button" variant="outline" size="sm" @click="closeDialog()">
                {{ __('shipments.copy.cancel') }}
            </x-ui.button>
            <x-ui.button type="submit" form="goodsLocationModalForm" variant="primary" size="sm">
                <x-slot:leading><x-ui.icon name="save" size="sm" /></x-slot:leading>
                {{ __('shipments.preshipment.locations_save') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.dialog>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-delete-location').forEach((button) => {
        button.addEventListener('click', () => {
            const form = button.closest('form');
            if (!form) return;

            const title = @js(__('shipments.preshipment.locations_delete_confirm_title'));
            const text = @js(__('shipments.preshipment.locations_delete_confirm_body'));

            if (window.AdasiAlert) {
                window.AdasiAlert.confirm({
                    title,
                    text,
                    confirmText: @js(__('shipments.mtc.remove')),
                    cancelText: @js(__('shipments.copy.cancel')),
                    confirmTone: 'danger',
                }, true).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            } else if (confirm(`${title}\n\n${text}`)) {
                form.submit();
            }
        });
    });
});
</script>
@endpush

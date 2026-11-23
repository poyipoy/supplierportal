{{--
    Pre-Shipment Inspection (PSI) form block: Exporter + Location of Goods.
    Supports multi-location prefill, "Same as Exporter" live mirror with restore,
    explicit master-default opt-in (Draft only), ISO country select, and supplier-scoped
    Port of Loading suggestions.

    @param array<string, mixed> $preshipment
    @param bool                 $allowMasterControls    Show master default/location save controls (Draft only)
    @param bool                 $isPostSubmit           True inside post-submit Edit PSI dialog
    @param bool                 $requireCompleteFields  Show required markers & aria-required on PSI inputs
    @param bool                 $embedded               Omit outer card wrapper when rendered inside a dialog
    @param string               $idPrefix               Unique DOM ID prefix (prevents duplicate IDs)
--}}
@php
    $allowMasterControls = $allowMasterControls ?? true;
    $isPostSubmit = $isPostSubmit ?? false;
    $requireCompleteFields = $requireCompleteFields ?? true;
    $embedded = $embedded ?? false;
    $idPrefix = $idPrefix ?? 'preshipment';

    $baseValues = $preshipment['values'] ?? [];
    $resolvedValues = [];
    foreach (\App\Models\SupplierPreshipmentProfile::FIELDS as $fieldKey) {
        $resolvedValues[$fieldKey] = old("preshipment.{$fieldKey}", $baseValues[$fieldKey] ?? '');
    }

    $sameAsExporter = filter_var(
        old('preshipment.same_as_exporter', $preshipment['same_as_exporter'] ?? false),
        FILTER_VALIDATE_BOOLEAN
    );
    $selectedLocation = (string) old('preshipment.goods_location', $preshipment['selected_location_hash'] ?? '');
    $defaultSaveExporter = (bool) ($preshipment['default_save_exporter'] ?? false);
    $saveExporterDefault = old('preshipment') !== null
        ? filter_var(old('preshipment.save_exporter_default', false), FILTER_VALIDATE_BOOLEAN)
        : $defaultSaveExporter;
    $hasAnyLocation = (bool) ($preshipment['has_any_location'] ?? false);
    $locations = $preshipment['locations'] ?? [];
    $activeLocationsCount = (int) ($preshipment['active_locations_count'] ?? count($locations));
    $maxLocations = (int) ($preshipment['max_locations'] ?? \App\Models\SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER);
    $canCreateLocation = $activeLocationsCount < $maxLocations;
    $locationAction = (string) old(
        'preshipment.location_action',
        (! $sameAsExporter && ! $hasAnyLocation && $canCreateLocation) ? 'create' : 'none'
    );
    $setLocationDefault = old('preshipment') !== null
        ? filter_var(old('preshipment.set_location_default', false), FILTER_VALIDATE_BOOLEAN)
        : (! $hasAnyLocation && $canCreateLocation);

    $countries = $preshipment['countries'] ?? \App\Support\CountryCatalog::options();
    $countryOptions = collect($countries)->map(fn (string $label, string $code) => [
        'value' => $code,
        'label' => $label,
        'sublabel' => $code,
        'searchKeywords' => "{$label} {$code}",
    ])->values()->all();
    $legacyUnmappedCountries = $preshipment['legacy_unmapped_countries'] ?? [];
    $portSuggestions = $preshipment['port_suggestions'] ?? [];
    $portDatalistId = $idPrefix.'_port_of_loading_suggestions';

    $manageCountrySelectName = '_psi_manage_country_'.$idPrefix;
    $psiConfig = [
        'idPrefix' => $idPrefix,
        'manageCountrySelectName' => $manageCountrySelectName,
        'urls' => [
            'options' => \App\Support\PortalPath::routePath('supplier.goods-locations.options'),
            'store' => \App\Support\PortalPath::routePath('supplier.goods-locations.store'),
        ],
        'values' => $resolvedValues,
        'locations' => $locations,
        'activeLocationsCount' => $activeLocationsCount,
        'maxLocations' => $maxLocations,
        'sameAsExporter' => $sameAsExporter,
        'selectedLocation' => $selectedLocation,
        'saveExporterDefault' => $saveExporterDefault,
        'hasAnyLocation' => $hasAnyLocation,
        'locationAction' => $locationAction,
        'setLocationDefault' => $setLocationDefault,
        'messages' => [
            'announcedOn' => __('shipments.preshipment.same_as_exporter_announced_on'),
            'announcedOff' => __('shipments.preshipment.same_as_exporter_announced_off'),
            'locationLoaded' => __('shipments.preshipment.location_loaded_announced'),
            'locationsRefreshed' => __('shipments.preshipment.locations_refreshed'),
            'locationsSaveFailed' => __('shipments.preshipment.locations_save_failed'),
            'sessionExpired' => __('shipments.preshipment.locations_session_expired'),
            'rateLimited' => __('shipments.preshipment.locations_rate_limited'),
        ],
    ];
@endphp

<div
    x-data="psiForm(@js($psiConfig))"
    @adasi:reset-psi-state.window="if (!$event.detail?.idPrefix || $event.detail.idPrefix === '{{ $idPrefix }}') { reset(); }"
    @close-ui-dialog.window="const target = $event.detail?.name ?? $event.detail; if (['shipment-psi-form', 'edit-shipment-psi'].includes(target) && panelMode === 'manage') { closeManageLocations(); }"
    class="tw:grid tw:gap-4"
>
    <div class="tw:sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></div>

    <div x-show="panelMode === 'psi'" class="tw:grid tw:gap-4">
    @if($isPostSubmit)
        <x-ui.alert tone="warning">{{ __('shipments.preshipment.post_submit_revision_notice') }}</x-ui.alert>
    @elseif(! $requireCompleteFields)
        <x-ui.alert tone="info">{{ __('shipments.preshipment.draft_optional_hint') }}</x-ui.alert>
    @elseif($preshipment['reused'] ?? false)
        <x-ui.alert tone="info">{{ __('shipments.preshipment.reused_hint') }} {{ __('shipments.preshipment.snapshot_hint') }}</x-ui.alert>
    @endif

    @if(! empty($legacyUnmappedCountries))
        <x-ui.alert tone="warning">
            @foreach($legacyUnmappedCountries as $legacyValue)
                <div>{{ __('shipments.preshipment.legacy_country_notice', ['value' => $legacyValue]) }}</div>
            @endforeach
        </x-ui.alert>
    @endif

    <div class="tw:grid tw:gap-5 tw:lg:grid-cols-2">
        {{-- Block 1: Exporter --}}
        <fieldset class="tw:m-0 tw:border tw:border-outline-variant tw:rounded-ui-md tw:p-4 tw:bg-surface tw:grid tw:gap-3 tw:content-start">
            <legend class="tw:sr-only">{{ __('shipments.preshipment.exporter_title') }}</legend>
            <div class="tw:grid tw:gap-0.5 tw:border-b tw:border-outline-variant tw:pb-2.5">
                <h3 class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                    {{ __('shipments.preshipment.exporter_short_title') }}
                </h3>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                    {{ __('shipments.preshipment.exporter_role_subtitle') }}
                </p>
            </div>

            <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                {{-- Name --}}
                <div class="tw:sm:col-span-2" data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_name" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.name') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <input
                        type="text"
                        name="preshipment[exporter_name]"
                        id="{{ $idPrefix }}_exporter_name"
                        x-model="exporter.name"
                        @input="syncFromExporter()"
                        maxlength="191"
                        autocomplete="organization"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.exporter_name') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_name_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_name') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_name')<div id="{{ $idPrefix }}_exporter_name_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Contact --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_contact" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.contact') }}
                    </label>
                    <input
                        type="text"
                        name="preshipment[exporter_contact]"
                        id="{{ $idPrefix }}_exporter_contact"
                        x-model="exporter.contact"
                        @input="syncFromExporter()"
                        maxlength="150"
                        autocomplete="name"
                        @error('preshipment.exporter_contact') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_contact_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_contact') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_contact')<div id="{{ $idPrefix }}_exporter_contact_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Port of Loading --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_port_of_loading" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.port_of_loading') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <input
                        type="text"
                        name="preshipment[exporter_port_of_loading]"
                        id="{{ $idPrefix }}_exporter_port_of_loading"
                        x-model="exporter.port_of_loading"
                        list="{{ $portDatalistId }}"
                        maxlength="150"
                        placeholder="{{ __('shipments.preshipment.port_placeholder') }}"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.exporter_port_of_loading') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_port_of_loading_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_port_of_loading') is-invalid @enderror"
                    >
                    <datalist id="{{ $portDatalistId }}">
                        @foreach($portSuggestions as $portOption)
                            <option value="{{ $portOption }}"></option>
                        @endforeach
                    </datalist>
                    @error('preshipment.exporter_port_of_loading')<div id="{{ $idPrefix }}_exporter_port_of_loading_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Address --}}
                <div class="tw:sm:col-span-2" data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_address" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.address') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <textarea
                        name="preshipment[exporter_address]"
                        id="{{ $idPrefix }}_exporter_address"
                        x-model="exporter.address"
                        @input="syncFromExporter()"
                        rows="2"
                        maxlength="1000"
                        autocomplete="street-address"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.exporter_address') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_address_error" @enderror
                        class="form-control form-control-sm @error('preshipment.exporter_address') is-invalid @enderror"
                    ></textarea>
                    @error('preshipment.exporter_address')<div id="{{ $idPrefix }}_exporter_address_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Country (Left - mirrors Block 2 layout) --}}
                <div data-async-error-anchor @change="if ($event.target?.tagName === 'SELECT') { exporter.country = $event.target.value; syncFromExporter(); }">
                    <x-ui.searchable-select
                        name="preshipment[exporter_country]"
                        :id="$idPrefix.'_exporter_country'"
                        :label="__('shipments.preshipment.country')"
                        label-class="form-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                        :value="$resolvedValues['exporter_country']"
                        :options="$countryOptions"
                        :placeholder="__('shipments.preshipment.select_country')"
                        :required="$isPostSubmit || $requireCompleteFields"
                        :show-sublabel-on-trigger="false"
                        :error="$errors->first('preshipment.exporter_country')"
                    />
                </div>

                {{-- City (Right - mirrors Block 2 layout) --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_city" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.city') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <input
                        type="text"
                        name="preshipment[exporter_city]"
                        id="{{ $idPrefix }}_exporter_city"
                        x-model="exporter.city"
                        @input="syncFromExporter()"
                        maxlength="120"
                        autocomplete="address-level2"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.exporter_city') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_city_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_city') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_city')<div id="{{ $idPrefix }}_exporter_city_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Zip Code --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_zip_code" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.zip_code') }}
                    </label>
                    <input
                        type="text"
                        name="preshipment[exporter_zip_code]"
                        id="{{ $idPrefix }}_exporter_zip_code"
                        x-model="exporter.zip_code"
                        maxlength="40"
                        autocomplete="postal-code"
                        @error('preshipment.exporter_zip_code') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_zip_code_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_zip_code') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_zip_code')<div id="{{ $idPrefix }}_exporter_zip_code_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Telephone --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_telephone" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.telephone') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        name="preshipment[exporter_telephone]"
                        id="{{ $idPrefix }}_exporter_telephone"
                        x-model="exporter.telephone"
                        @input="syncFromExporter()"
                        maxlength="30"
                        autocomplete="tel"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('preshipment.exporter_telephone') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_telephone_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_telephone') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_telephone')<div id="{{ $idPrefix }}_exporter_telephone_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Mobile --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_mobile" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.mobile') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        name="preshipment[exporter_mobile]"
                        id="{{ $idPrefix }}_exporter_mobile"
                        x-model="exporter.mobile"
                        @input="syncFromExporter()"
                        maxlength="30"
                        autocomplete="tel"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('preshipment.exporter_mobile') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_mobile_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_mobile') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_mobile')<div id="{{ $idPrefix }}_exporter_mobile_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Fax --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_fax" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.fax') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        name="preshipment[exporter_fax]"
                        id="{{ $idPrefix }}_exporter_fax"
                        x-model="exporter.fax"
                        @input="syncFromExporter()"
                        maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('preshipment.exporter_fax') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_fax_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_fax') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_fax')<div id="{{ $idPrefix }}_exporter_fax_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Email --}}
                <div class="tw:sm:col-span-2" data-async-error-anchor>
                    <label for="{{ $idPrefix }}_exporter_email" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.email') }}
                    </label>
                    <input
                        type="email"
                        name="preshipment[exporter_email]"
                        id="{{ $idPrefix }}_exporter_email"
                        x-model="exporter.email"
                        @input="syncFromExporter()"
                        maxlength="191"
                        autocomplete="email"
                        @error('preshipment.exporter_email') aria-invalid="true" aria-describedby="{{ $idPrefix }}_exporter_email_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.exporter_email') is-invalid @enderror"
                    >
                    @error('preshipment.exporter_email')<div id="{{ $idPrefix }}_exporter_email_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>
            </div>

            @if($allowMasterControls)
                <div class="tw:pt-3 tw:border-t tw:border-outline-variant">
                    <div class="form-check tw:mb-0">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            name="preshipment[save_exporter_default]"
                            id="{{ $idPrefix }}_save_exporter_default"
                            value="1"
                            x-model="saveExporterDefault"
                        >
                        <label class="form-check-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:cursor-pointer" for="{{ $idPrefix }}_save_exporter_default">
                            {{ __('shipments.preshipment.save_exporter_default') }}
                        </label>
                        <div class="form-text tw:text-ui-xs tw:text-on-surface-variant tw:mt-0.5">
                            {{ __('shipments.preshipment.save_exporter_default_help') }}
                        </div>
                    </div>
                </div>
            @endif
        </fieldset>

        {{-- Block 2: Supplier / Location of Goods --}}
        <fieldset class="tw:m-0 tw:border tw:border-outline-variant tw:rounded-ui-md tw:p-4 tw:bg-surface tw:grid tw:gap-3 tw:content-start">
            <legend class="tw:sr-only">{{ __('shipments.preshipment.supplier_title') }}</legend>
            <div class="tw:grid tw:gap-0.5 tw:border-b tw:border-outline-variant tw:pb-2.5">
                <h3 class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                    {{ __('shipments.preshipment.supplier_short_title') }}
                </h3>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                    {{ __('shipments.preshipment.supplier_role_subtitle') }}
                </p>
            </div>

            {{-- Same as Exporter toggle --}}
            <div class="tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface-container-low tw:p-3">
                <div class="form-check tw:mb-0">
                    <input type="hidden" name="preshipment[same_as_exporter]" value="0">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        name="preshipment[same_as_exporter]"
                        id="{{ $idPrefix }}_same_as_exporter"
                        value="1"
                        :checked="sameAsExporter"
                        @change="toggleSameAsExporter($event.target.checked)"
                        aria-describedby="{{ $idPrefix }}_same_as_exporter_help"
                    >
                    <label class="form-check-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:cursor-pointer tw:inline-flex tw:items-center tw:gap-1.5" for="{{ $idPrefix }}_same_as_exporter">
                        <span>{{ __('shipments.preshipment.same_as_exporter') }}</span>
                        <span x-show="sameAsExporter" x-cloak class="tw:inline-flex tw:items-center tw:gap-1 tw:text-primary tw:font-semibold">
                            <x-ui.icon name="lock" size="xs" />
                        </span>
                    </label>
                    <p id="{{ $idPrefix }}_same_as_exporter_help" class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">
                        {{ __('shipments.preshipment.same_as_exporter_help') }}
                    </p>
                </div>
            </div>

            {{-- Saved Location Picker --}}
            <div class="tw:grid tw:gap-1.5" data-async-error-anchor>
                <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                    <label for="{{ $idPrefix }}_goods_location" class="form-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.select_location') }}
                    </label>
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-1.5">
                        <button
                            type="button"
                            @click="refreshLocations(@js(\App\Support\PortalPath::routePath('supplier.goods-locations.options')), $refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                            :disabled="loadingLocations || sameAsExporter"
                            class="ui-focus-ring tw:inline-flex tw:items-center tw:gap-1 tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:min-h-[44px] tw:sm:min-h-[32px] tw:px-2 tw:py-1 tw:text-ui-xs tw:font-semibold tw:text-primary tw:hover:bg-surface-container tw:cursor-pointer tw:disabled:opacity-50 tw:disabled:cursor-not-allowed"
                        >
                            <x-ui.icon name="refresh-cw" size="xs" x-bind:class="{ 'tw:animate-spin': loadingLocations }" />
                            <span>{{ __('shipments.preshipment.refresh_locations') }}</span>
                        </button>
                        <button
                            type="button"
                            x-ref="manageLocationsBtn"
                            @click="openManageLocations($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag'))); $nextTick(() => { scrollDialogTop($el); $refs.manageBackBtn?.focus(); })"
                            :disabled="sameAsExporter"
                            :aria-describedby="sameAsExporter ? '{{ $idPrefix }}_manage_locked_hint' : null"
                            class="ui-focus-ring tw:inline-flex tw:items-center tw:gap-1 tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:min-h-[44px] tw:sm:min-h-[32px] tw:px-2 tw:py-1 tw:text-ui-xs tw:font-semibold tw:text-primary tw:hover:bg-surface-container tw:cursor-pointer tw:disabled:opacity-50 tw:disabled:cursor-not-allowed"
                        >
                            <x-ui.icon name="map-pin" size="xs" />
                            <span>{{ __('shipments.preshipment.manage_locations') }}</span>
                        </button>
                    </div>
                </div>
                <select
                    name="preshipment[goods_location]"
                    id="{{ $idPrefix }}_goods_location"
                    x-ref="locationSelect"
                    x-model="selectedLocation"
                    @change="selectLocation($event.target.value)"
                    :disabled="sameAsExporter"
                    @error('preshipment.goods_location') aria-invalid="true" aria-describedby="{{ $idPrefix }}_goods_location_error" @enderror
                    class="form-select form-select-sm tw:min-h-[2.5rem] @error('preshipment.goods_location') is-invalid @enderror"
                >
                    <option value="" x-text="sameAsExporter ? @js(__('shipments.preshipment.select_location_locked')) : @js(__('shipments.preshipment.select_location_placeholder'))">{{ $sameAsExporter ? __('shipments.preshipment.select_location_locked') : __('shipments.preshipment.select_location_placeholder') }}</option>
                    @foreach($locations as $loc)
                        <option value="{{ $loc['hash'] }}">
                            {{ $loc['name'] }} ({{ $loc['city'] }}, {{ $loc['country_label'] }})@if($loc['is_default']) - {{ __('shipments.preshipment.location_default_tag') }}@endif
                        </option>
                    @endforeach
                </select>
                <p id="{{ $idPrefix }}_manage_locked_hint" x-show="sameAsExporter" x-cloak class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                    {{ __('shipments.preshipment.manage_locations_locked_hint') }}
                </p>
                @error('preshipment.goods_location')<div id="{{ $idPrefix }}_goods_location_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
            </div>

            <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                {{-- Location Name --}}
                <div class="tw:sm:col-span-2" data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_name" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.name') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <input
                        type="text"
                        name="preshipment[supplier_name]"
                        id="{{ $idPrefix }}_supplier_name"
                        x-model="supplier.name"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="191"
                        autocomplete="organization"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.supplier_name') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_name_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_name') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_name')<div id="{{ $idPrefix }}_supplier_name_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Contact --}}
                <div class="tw:sm:col-span-2" data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_contact" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.contact') }}
                    </label>
                    <input
                        type="text"
                        name="preshipment[supplier_contact]"
                        id="{{ $idPrefix }}_supplier_contact"
                        x-model="supplier.contact"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="150"
                        autocomplete="name"
                        @error('preshipment.supplier_contact') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_contact_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_contact') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_contact')<div id="{{ $idPrefix }}_supplier_contact_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Address --}}
                <div class="tw:sm:col-span-2" data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_address" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.address') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <textarea
                        name="preshipment[supplier_address]"
                        id="{{ $idPrefix }}_supplier_address"
                        x-model="supplier.address"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        rows="2"
                        maxlength="1000"
                        autocomplete="street-address"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.supplier_address') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_address_error" @enderror
                        class="form-control form-control-sm @error('preshipment.supplier_address') is-invalid @enderror"
                    ></textarea>
                    @error('preshipment.supplier_address')<div id="{{ $idPrefix }}_supplier_address_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Country --}}
                <div data-async-error-anchor @change="if ($event.target?.tagName === 'SELECT') { supplier.country = $event.target.value; }">
                    <x-ui.searchable-select
                        name="preshipment[supplier_country]"
                        :id="$idPrefix.'_supplier_country'"
                        :label="__('shipments.preshipment.country')"
                        label-class="form-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                        :value="$resolvedValues['supplier_country']"
                        :options="$countryOptions"
                        :placeholder="__('shipments.preshipment.select_country')"
                        :required="$isPostSubmit || $requireCompleteFields"
                        :disabled="$sameAsExporter"
                        :show-sublabel-on-trigger="false"
                        :error="$errors->first('preshipment.supplier_country')"
                    />
                    <input type="hidden" name="preshipment[supplier_country]" :value="supplier.country" :disabled="!sameAsExporter">
                </div>

                {{-- City --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_city" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.city') }}@if($requireCompleteFields) <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>@endif
                    </label>
                    <input
                        type="text"
                        name="preshipment[supplier_city]"
                        id="{{ $idPrefix }}_supplier_city"
                        x-model="supplier.city"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="120"
                        autocomplete="address-level2"
                        @if($requireCompleteFields) aria-required="true" @endif
                        @if($isPostSubmit) required @endif
                        @error('preshipment.supplier_city') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_city_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_city') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_city')<div id="{{ $idPrefix }}_supplier_city_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Telephone --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_telephone" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.telephone') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        name="preshipment[supplier_telephone]"
                        id="{{ $idPrefix }}_supplier_telephone"
                        x-model="supplier.telephone"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="30"
                        autocomplete="tel"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('preshipment.supplier_telephone') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_telephone_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_telephone') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_telephone')<div id="{{ $idPrefix }}_supplier_telephone_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Mobile --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_mobile" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.mobile') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        name="preshipment[supplier_mobile]"
                        id="{{ $idPrefix }}_supplier_mobile"
                        x-model="supplier.mobile"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="30"
                        autocomplete="tel"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('preshipment.supplier_mobile') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_mobile_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_mobile') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_mobile')<div id="{{ $idPrefix }}_supplier_mobile_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Fax --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_fax" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.fax') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        name="preshipment[supplier_fax]"
                        id="{{ $idPrefix }}_supplier_fax"
                        x-model="supplier.fax"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        @error('preshipment.supplier_fax') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_fax_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_fax') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_fax')<div id="{{ $idPrefix }}_supplier_fax_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>

                {{-- Email --}}
                <div data-async-error-anchor>
                    <label for="{{ $idPrefix }}_supplier_email" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.email') }}
                    </label>
                    <input
                        type="email"
                        name="preshipment[supplier_email]"
                        id="{{ $idPrefix }}_supplier_email"
                        x-model="supplier.email"
                        :readonly="sameAsExporter"
                        :aria-readonly="sameAsExporter ? 'true' : 'false'"
                        :class="{ 'tw:bg-surface-container tw:cursor-not-allowed': sameAsExporter }"
                        maxlength="191"
                        autocomplete="email"
                        @error('preshipment.supplier_email') aria-invalid="true" aria-describedby="{{ $idPrefix }}_supplier_email_error" @enderror
                        class="form-control form-control-sm tw:min-h-[2.5rem] @error('preshipment.supplier_email') is-invalid @enderror"
                    >
                    @error('preshipment.supplier_email')<div id="{{ $idPrefix }}_supplier_email_error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                </div>
            </div>

            @if($allowMasterControls)
                <div class="tw:pt-3 tw:border-t tw:border-outline-variant tw:grid tw:gap-2" x-show="!sameAsExporter" data-async-error-anchor>
                    <div class="tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('shipments.preshipment.location_save_mode') }}</div>
                    <div class="tw:grid tw:gap-1.5" role="radiogroup" aria-label="{{ __('shipments.preshipment.location_save_mode') }}">
                        <div class="form-check tw:mb-0">
                            <input type="radio" class="form-check-input" name="preshipment[location_action]" id="{{ $idPrefix }}_loc_action_none" value="none" x-model="locationAction">
                            <label class="form-check-label tw:text-ui-xs tw:text-on-surface tw:cursor-pointer" for="{{ $idPrefix }}_loc_action_none">{{ __('shipments.preshipment.location_action_none') }}</label>
                        </div>
                        <div class="form-check tw:mb-0">
                            <input type="radio" class="form-check-input" name="preshipment[location_action]" id="{{ $idPrefix }}_loc_action_update" value="update" x-model="locationAction" :disabled="!selectedLocation">
                            <label class="form-check-label tw:text-ui-xs tw:cursor-pointer" :class="selectedLocation ? 'tw:text-on-surface' : 'tw:text-on-surface-variant tw:cursor-not-allowed'" for="{{ $idPrefix }}_loc_action_update">{{ __('shipments.preshipment.location_action_update') }}</label>
                        </div>
                        <div class="form-check tw:mb-0">
                            <input type="radio" class="form-check-input" name="preshipment[location_action]" id="{{ $idPrefix }}_loc_action_create" value="create" x-model="locationAction" :disabled="!canCreateLocation">
                            <label class="form-check-label tw:text-ui-xs tw:cursor-pointer" :class="canCreateLocation ? 'tw:text-on-surface' : 'tw:text-on-surface-variant tw:cursor-not-allowed'" for="{{ $idPrefix }}_loc_action_create">{{ __('shipments.preshipment.location_action_create') }}</label>
                        </div>
                    </div>
                    <p x-show="!canCreateLocation" x-cloak class="tw:m-0 tw:text-ui-xs tw:text-warning">
                        {{ __('shipments.preshipment.location_limit_reached', ['max' => $maxLocations]) }}
                    </p>
                    <p x-show="locationAction === 'update' || locationAction === 'create'" x-cloak class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                        {{ __('shipments.preshipment.location_master_required_hint') }}
                    </p>
                    @error('preshipment.location_action')<div class="tw:text-ui-xs tw:font-medium tw:text-error" role="alert">{{ $message }}</div>@enderror
                    <div class="form-check tw:mb-0 tw:mt-1" x-show="locationAction === 'update' || locationAction === 'create'" x-cloak>
                        <input type="checkbox" class="form-check-input" name="preshipment[set_location_default]" id="{{ $idPrefix }}_set_location_default" value="1" x-model="setLocationDefault">
                        <label class="form-check-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:cursor-pointer" for="{{ $idPrefix }}_set_location_default">
                            {{ __('shipments.preshipment.set_location_default') }}
                        </label>
                    </div>
                </div>
            @endif
        </fieldset>
    </div>
    </div>

    {{-- In-Dialog Manage Locations Sub-View (never uses nested <form> tags or parent form names) --}}
    <div
        x-show="panelMode === 'manage'"
        x-cloak
        data-unsaved-ignore="true"
        role="region"
        aria-labelledby="{{ $idPrefix }}_manage_heading"
        @keydown.escape.stop="
            if (deleteConfirmHash) {
                cancelDeleteLocation();
            } else if (manageStep === 'form') {
                cancelLocationForm();
                $nextTick(() => ($refs.manageAddBtn || $refs.manageBackBtn)?.focus());
            } else {
                closeManageLocations();
                $nextTick(() => { scrollDialogTop($el); $refs.manageLocationsBtn?.focus(); });
            }
        "
        class="tw:grid tw:gap-4"
    >
        {{-- Sub-View Header --}}
        <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3 tw:border-b tw:border-outline-variant tw:pb-3">
            <div class="tw:grid tw:gap-1 tw:min-w-0">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-ref="manageBackBtn"
                    @click="closeManageLocations(); $nextTick(() => { scrollDialogTop($el); $refs.manageLocationsBtn?.focus(); })"
                    class="tw:justify-self-start tw:min-h-[44px] tw:sm:min-h-0"
                >
                    <x-slot:leading><x-ui.icon name="arrow-left" size="xs" /></x-slot:leading>
                    {{ __('shipments.preshipment.back_to_psi') }}
                </x-ui.button>
                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:mt-1">
                    <h3 id="{{ $idPrefix }}_manage_heading" class="tw:m-0 tw:text-ui-base tw:font-bold tw:text-on-surface">
                        {{ __('shipments.preshipment.locations_title') }}
                    </h3>
                    <span
                        class="ui-status-chip ui-status-chip--neutral ui-tabular-nums"
                        x-text="@js(__('shipments.preshipment.locations_count', ['count' => ':c', 'max' => ':m'])).replace(':c', activeLocationsCount).replace(':m', maxLocations)"
                    ></span>
                </div>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                    {{ __('shipments.preshipment.locations_description') }}
                </p>
            </div>

            <div class="tw:flex tw:items-center tw:gap-2" x-show="manageStep === 'list'">
                <x-ui.button
                    type="button"
                    variant="primary"
                    size="sm"
                    x-ref="manageAddBtn"
                    @click="startCreateLocation(); $nextTick(() => { scrollDialogTop($el); $refs.manageLocName?.focus(); })"
                    x-bind:disabled="!canCreateLocation || manageBusy"
                    class="tw:min-h-[44px] tw:sm:min-h-0"
                >
                    <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                    {{ __('shipments.preshipment.locations_add') }}
                </x-ui.button>
            </div>
        </div>

        {{-- Alerts --}}
        <div x-show="manageError" x-cloak role="alert">
            <x-ui.alert tone="error">
                <span x-text="manageError"></span>
            </x-ui.alert>
        </div>
        <div x-show="manageNotice && !manageError" x-cloak role="status">
            <x-ui.alert tone="success">
                <span x-text="manageNotice"></span>
            </x-ui.alert>
        </div>

        {{-- List Step --}}
        <div x-show="manageStep === 'list'" class="tw:grid tw:gap-3">
            <p x-show="!canCreateLocation" x-cloak class="tw:m-0 tw:text-ui-xs tw:font-medium tw:text-warning">
                {{ __('shipments.preshipment.location_limit_reached', ['max' => $maxLocations]) }}
            </p>

            {{-- Filter Input --}}
            <div x-show="locations.length > 0" class="tw:flex tw:items-center tw:gap-2">
                <div class="tw:relative tw:flex-1">
                    <label for="{{ $idPrefix }}_manage_search" class="tw:sr-only">{{ __('shipments.preshipment.locations_search_placeholder') }}</label>
                    <input
                        type="search"
                        id="{{ $idPrefix }}_manage_search"
                        x-model="manageSearch"
                        @keydown.enter.prevent
                        placeholder="{{ __('shipments.preshipment.locations_search_placeholder') }}"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                </div>
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-show="manageSearch.trim() !== ''"
                    x-cloak
                    @click="manageSearch = ''"
                    class="tw:min-h-[44px] tw:sm:min-h-0"
                >
                    {{ __('shipments.copy.reset') }}
                </x-ui.button>
            </div>

            {{-- Empty State: No Locations Yet --}}
            <div x-show="locations.length === 0" x-cloak class="tw:rounded-ui-md tw:border tw:border-dashed tw:border-outline-variant tw:bg-surface-low tw:p-6 tw:text-center tw:grid tw:gap-2 tw:justify-items-center">
                <x-ui.icon name="map-pin" size="lg" class="tw:text-on-surface-variant" />
                <div class="tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('shipments.preshipment.locations_empty_title') }}</div>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant tw:max-w-md">{{ __('shipments.preshipment.locations_empty_description') }}</p>
                <x-ui.button
                    type="button"
                    variant="primary"
                    size="sm"
                    x-show="canCreateLocation"
                    @click="startCreateLocation(); $nextTick(() => { scrollDialogTop($el); $refs.manageLocName?.focus(); })"
                    class="tw:mt-1 tw:min-h-[44px] tw:sm:min-h-0"
                >
                    <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                    {{ __('shipments.preshipment.locations_add') }}
                </x-ui.button>
            </div>

            {{-- Empty State: Filtered Search Has No Matches --}}
            <div x-show="locations.length > 0 && filteredLocations.length === 0" x-cloak class="tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-low tw:p-5 tw:text-center tw:grid tw:gap-2 tw:justify-items-center">
                <div class="tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('shipments.preshipment.locations_search_empty_title') }}</div>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('shipments.preshipment.locations_search_empty_description') }}</p>
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="manageSearch = ''"
                    class="tw:min-h-[44px] tw:sm:min-h-0"
                >
                    {{ __('shipments.copy.reset') }}
                </x-ui.button>
            </div>

            {{-- Responsive Location Cards --}}
            <div x-show="filteredLocations.length > 0" class="tw:grid tw:gap-2.5">
                <template x-for="loc in filteredLocations" :key="loc.hash">
                    <article
                        class="tw:rounded-ui-md tw:border tw:p-3.5 tw:grid tw:gap-3 tw:transition-colors"
                        :class="selectedLocation === loc.hash ? 'tw:border-primary tw:bg-surface-container-low' : 'tw:border-outline-variant tw:bg-surface'"
                    >
                        <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3">
                            <div class="tw:grid tw:gap-1 tw:min-w-0 tw:flex-1">
                                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-1.5">
                                    <span class="tw:text-ui-sm tw:font-bold tw:text-on-surface tw:break-words" x-text="loc.name"></span>
                                    <span x-show="loc.is_default" x-cloak class="ui-status-chip ui-status-chip--info">
                                        {{ __('shipments.preshipment.location_default_tag') }}
                                    </span>
                                    <span x-show="selectedLocation === loc.hash" x-cloak class="ui-status-chip ui-status-chip--success">
                                        {{ __('shipments.preshipment.location_in_use_tag') }}
                                    </span>
                                </div>
                                <div class="tw:text-ui-xs tw:text-on-surface tw:font-medium" x-text="[loc.city, loc.country_label].filter(Boolean).join(', ')"></div>
                                <div class="tw:text-ui-xs tw:text-on-surface-variant tw:break-words" x-text="loc.address"></div>
                                <div class="tw:text-ui-xs tw:text-on-surface-variant" x-show="loc.contact || loc.telephone || loc.email" x-text="[loc.contact, loc.telephone, loc.email].filter(Boolean).join(' · ')"></div>
                            </div>

                            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-1.5">
                                <x-ui.button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    @click="useLocationForShipment(loc.hash, $refs.locationSelect, @js(__('shipments.preshipment.location_default_tag'))); $nextTick(() => { scrollDialogTop($el); $refs.locationSelect?.focus(); })"
                                    x-bind:disabled="manageBusy"
                                    class="tw:min-h-[44px] tw:sm:min-h-0"
                                >
                                    <x-slot:leading><x-ui.icon name="check" size="xs" /></x-slot:leading>
                                    {{ __('shipments.preshipment.use_location') }}
                                </x-ui.button>
                                <x-ui.button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="startEditLocation(loc); $nextTick(() => { scrollDialogTop($el); $refs.manageLocName?.focus(); })"
                                    x-bind:disabled="manageBusy"
                                    x-bind:aria-label="@js(__('shipments.preshipment.locations_edit')) + ' ' + loc.name"
                                    class="tw:min-h-[44px] tw:sm:min-h-0"
                                >
                                    {{ __('shipments.preshipment.locations_edit') }}
                                </x-ui.button>
                                <x-ui.button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    x-show="!loc.is_default"
                                    @click="setLocationAsDefault(loc, $refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                                    x-bind:disabled="manageBusy"
                                    class="tw:min-h-[44px] tw:sm:min-h-0"
                                >
                                    {{ __('shipments.preshipment.locations_set_default') }}
                                </x-ui.button>
                                <x-ui.button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="requestDeleteLocation(loc.hash)"
                                    x-bind:disabled="manageBusy"
                                    x-bind:aria-label="@js(__('shipments.mtc.remove')) + ' ' + loc.name"
                                    class="tw:text-error hover:tw:bg-error-container/40 tw:min-h-[44px] tw:sm:min-h-0"
                                >
                                    {{ __('shipments.mtc.remove') }}
                                </x-ui.button>
                            </div>
                        </div>

                        {{-- Inline Delete Confirmation (no stacked confirmation modal over dialog) --}}
                        <div
                            x-show="deleteConfirmHash === loc.hash"
                            x-cloak
                            role="region"
                            aria-label="{{ __('shipments.preshipment.locations_delete_confirm_title') }}"
                            class="tw:rounded-ui-sm tw:border tw:border-error/40 tw:bg-error-container/30 tw:p-3 tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2"
                        >
                            <div class="tw:grid tw:gap-0.5 tw:flex-1 tw:min-w-[14rem]">
                                <div class="tw:text-ui-xs tw:font-bold tw:text-on-surface">{{ __('shipments.preshipment.locations_delete_confirm_title') }}</div>
                                <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('shipments.preshipment.locations_delete_confirm_body') }}</div>
                            </div>
                            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                                <x-ui.button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="cancelDeleteLocation()"
                                    x-bind:disabled="manageBusy"
                                    class="tw:min-h-[44px] tw:sm:min-h-0"
                                >
                                    {{ __('shipments.copy.cancel') }}
                                </x-ui.button>
                                <x-ui.button
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    @click="confirmDeleteLocation(loc, $refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                                    x-bind:disabled="manageBusy"
                                    class="tw:min-h-[44px] tw:sm:min-h-0"
                                >
                                    <span x-show="manageBusy && manageBusyAction === 'delete' && manageBusyHash === loc.hash" x-cloak class="ui-spinner" aria-hidden="true"></span>
                                    <span>{{ __('shipments.mtc.remove') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </article>
                </template>
            </div>
        </div>

        {{-- Create / Edit Location Form Step (HTML5/FormData isolated from parent form) --}}
        <div x-show="manageStep === 'form'" x-cloak class="tw:grid tw:gap-4 tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface tw:p-4">
            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:border-b tw:border-outline-variant tw:pb-2.5">
                <h4
                    class="tw:m-0 tw:text-ui-sm tw:font-bold tw:text-on-surface"
                    x-text="manageForm.mode === 'edit' ? @js(__('shipments.preshipment.locations_edit')) : @js(__('shipments.preshipment.locations_add'))"
                ></h4>
                <button
                    type="button"
                    @click="cancelLocationForm(); $nextTick(() => ($refs.manageAddBtn || $refs.manageBackBtn)?.focus())"
                    class="ui-focus-ring tw:inline-flex tw:items-center tw:gap-1 tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:min-h-[44px] tw:sm:min-h-[32px] tw:px-2 tw:text-ui-xs tw:font-semibold tw:text-primary hover:tw:bg-surface-container tw:cursor-pointer"
                >
                    <x-ui.icon name="arrow-left" size="xs" />
                    <span>{{ __('shipments.preshipment.back_to_locations') }}</span>
                </button>
            </div>

            <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                <div class="tw:sm:col-span-2">
                    <label for="{{ $idPrefix }}_manage_loc_name" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.name') }} <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>
                    </label>
                    <input
                        type="text"
                        id="{{ $idPrefix }}_manage_loc_name"
                        x-ref="manageLocName"
                        x-model="manageForm.fields.name"
                        @input="delete manageFieldErrors.name"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="191"
                        :aria-invalid="manageFieldErrors.name ? 'true' : 'false'"
                        :aria-describedby="manageFieldErrors.name ? '{{ $idPrefix }}_manage_loc_name_error' : null"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.name) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.name" x-cloak id="{{ $idPrefix }}_manage_loc_name_error" class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.name"></div>
                </div>

                <div class="tw:sm:col-span-2">
                    <label for="{{ $idPrefix }}_manage_loc_contact" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.contact') }}
                    </label>
                    <input
                        type="text"
                        id="{{ $idPrefix }}_manage_loc_contact"
                        x-model="manageForm.fields.contact"
                        @input="delete manageFieldErrors.contact"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="150"
                        :aria-invalid="manageFieldErrors.contact ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.contact) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.contact" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.contact"></div>
                </div>

                <div class="tw:sm:col-span-2">
                    <label for="{{ $idPrefix }}_manage_loc_address" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.address') }} <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>
                    </label>
                    <textarea
                        id="{{ $idPrefix }}_manage_loc_address"
                        x-model="manageForm.fields.address"
                        @input="delete manageFieldErrors.address"
                        rows="2"
                        maxlength="1000"
                        :aria-invalid="manageFieldErrors.address ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.address) }"
                        class="form-control form-control-sm"
                    ></textarea>
                    <div x-show="manageFieldErrors.address" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.address"></div>
                </div>

                <div @change="if ($event.target?.tagName === 'SELECT') { manageForm.fields.country = $event.target.value; manageForm.fields.country_unmapped = ''; delete manageFieldErrors.country; }">
                    <x-ui.searchable-select
                        :name="$manageCountrySelectName"
                        :id="$idPrefix.'_manage_loc_country'"
                        :label="__('shipments.preshipment.country')"
                        label-class="form-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                        value=""
                        :options="$countryOptions"
                        :placeholder="__('shipments.preshipment.select_country')"
                        :required="true"
                        :disabled="true"
                        :show-sublabel-on-trigger="false"
                    />
                    <p x-show="manageForm.fields.country_unmapped" x-cloak class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-warning"
                       x-text="@js(__('shipments.preshipment.legacy_country_notice', ['value' => ':val'])).replace(':val', manageForm.fields.country_unmapped)"></p>
                    <div x-show="manageFieldErrors.country" x-cloak class="tw:mt-1 tw:text-ui-xs tw:font-medium tw:text-error" role="alert" x-text="manageFieldErrors.country"></div>
                </div>

                <div>
                    <label for="{{ $idPrefix }}_manage_loc_city" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.city') }} <span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only">{{ __('common.upload.required') }}</span>
                    </label>
                    <input
                        type="text"
                        id="{{ $idPrefix }}_manage_loc_city"
                        x-model="manageForm.fields.city"
                        @input="delete manageFieldErrors.city"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="120"
                        :aria-invalid="manageFieldErrors.city ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.city) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.city" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.city"></div>
                </div>

                <div>
                    <label for="{{ $idPrefix }}_manage_loc_telephone" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.telephone') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        id="{{ $idPrefix }}_manage_loc_telephone"
                        x-model="manageForm.fields.telephone"
                        @input="delete manageFieldErrors.telephone"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        :aria-invalid="manageFieldErrors.telephone ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.telephone) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.telephone" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.telephone"></div>
                </div>

                <div>
                    <label for="{{ $idPrefix }}_manage_loc_mobile" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.mobile') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        id="{{ $idPrefix }}_manage_loc_mobile"
                        x-model="manageForm.fields.mobile"
                        @input="delete manageFieldErrors.mobile"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        :aria-invalid="manageFieldErrors.mobile ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.mobile) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.mobile" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.mobile"></div>
                </div>

                <div>
                    <label for="{{ $idPrefix }}_manage_loc_fax" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.fax') }}
                    </label>
                    <input
                        type="tel"
                        inputmode="tel"
                        id="{{ $idPrefix }}_manage_loc_fax"
                        x-model="manageForm.fields.fax"
                        @input="delete manageFieldErrors.fax"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="30"
                        placeholder="{{ __('shipments.preshipment.phone_placeholder') }}"
                        :aria-invalid="manageFieldErrors.fax ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.fax) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.fax" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.fax"></div>
                </div>

                <div>
                    <label for="{{ $idPrefix }}_manage_loc_email" class="form-label tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                        {{ __('shipments.preshipment.email') }}
                    </label>
                    <input
                        type="email"
                        id="{{ $idPrefix }}_manage_loc_email"
                        x-model="manageForm.fields.email"
                        @input="delete manageFieldErrors.email"
                        @keydown.enter.prevent="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                        maxlength="191"
                        :aria-invalid="manageFieldErrors.email ? 'true' : 'false'"
                        :class="{ 'is-invalid': Boolean(manageFieldErrors.email) }"
                        class="form-control form-control-sm tw:min-h-[2.5rem]"
                    >
                    <div x-show="manageFieldErrors.email" x-cloak class="invalid-feedback tw:block" role="alert" x-text="manageFieldErrors.email"></div>
                </div>
            </div>

            <div class="form-check tw:mb-0 tw:pt-2 tw:border-t tw:border-outline-variant">
                <input
                    type="checkbox"
                    class="form-check-input"
                    id="{{ $idPrefix }}_manage_loc_default"
                    x-model="manageForm.fields.is_default"
                >
                <label class="form-check-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:cursor-pointer" for="{{ $idPrefix }}_manage_loc_default">
                    {{ __('shipments.preshipment.set_location_default') }}
                </label>
            </div>

            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-end tw:gap-2 tw:pt-2 tw:border-t tw:border-outline-variant">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="cancelLocationForm(); $nextTick(() => ($refs.manageAddBtn || $refs.manageBackBtn)?.focus())"
                    x-bind:disabled="manageBusy"
                    class="tw:min-h-[44px] tw:sm:min-h-0"
                >
                    {{ __('shipments.copy.cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="submitLocationForm($refs.locationSelect, @js(__('shipments.preshipment.location_default_tag')))"
                    x-bind:disabled="manageBusy"
                    class="tw:min-h-[44px] tw:sm:min-h-0"
                >
                    <span x-show="manageBusy && manageBusyAction === 'save'" x-cloak class="ui-spinner" aria-hidden="true"></span>
                    <span x-text="manageForm.mode === 'edit' ? @js(__('shipments.preshipment.save_changes')) : @js(__('shipments.preshipment.save_and_use_location'))"></span>
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

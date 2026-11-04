@php
    $masterRoutePrefix = $masterRoutePrefix ?? 'admin';
    $annualManagement = $annualManagement ?? false;
    $annualEditYear = $annualEditYear ?? null;
    $annualEditing = $annualManagement && $annualEditYear !== null;
    $annualErrors = $errors->getBag('annualRates');
    $annualRecoveryYear = $annualManagement
        ? ($annualDuplicateYear ?? (($annualErrors->has('year') || $annualErrors->has('expected_revision')) ? $annualEditYear : null))
        : null;
    $annualCurrencies = $annualManagement ? \App\Models\AnnualExchangeRate::CURRENCIES : \App\Models\ExchangeRate::CURRENCIES;
@endphp

<x-ui.data-table :title="__('annual_rates.title')" :description="$annualManagement ? __('annual_rates.units') : __('annual_rates.description')">
    <x-slot:toolbar>
        @if($annualManagement)
            <x-ui.button :href="route($masterRoutePrefix.'.exchange-rates.index', [...$annualListQuery, 'annual_new' => 1])" data-exchange-rates-link :disabled="$annualEditing" :class="$annualEditing ? 'tw:opacity-50 tw:cursor-not-allowed' : ''" size="sm">
                <x-ui.icon name="plus" /> {{ __('annual_rates.add_year') }}
            </x-ui.button>
        @else
            <x-ui.button :href="route($masterRoutePrefix.'.exchange-rates.index', ['tab' => 'annual'])" variant="primary" size="sm">
                <x-ui.icon name="sliders" size="sm" />
                <span>{{ __('annual_rates.manage') }}</span>
            </x-ui.button>
        @endif
    </x-slot:toolbar>

    @if($annualManagement)
        <x-slot:filters>
            <form method="GET" action="{{ route($masterRoutePrefix.'.exchange-rates.index') }}" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" data-track-unsaved="false" data-server-tabs-form>
                <input type="hidden" name="tab" value="annual">
                <label for="annual-year-filter" class="tw:grid tw:gap-1 tw:text-ui-xs tw:font-medium">
                    {{ __('annual_rates.filter_year') }}
                    <input id="annual-year-filter" name="annual_year" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="{{ is_scalar(request('annual_year')) ? request('annual_year') : '' }}" class="form-control form-control-sm ui-tabular-nums tw:w-28" @disabled($annualEditing)>
                </label>
                <x-ui.button type="submit" variant="outline" size="sm" :disabled="$annualEditing">{{ __('admin.copy.apply_filter') }}</x-ui.button>
                @if(request()->filled('annual_year'))
                    <x-ui.button :href="route($masterRoutePrefix.'.exchange-rates.index', ['tab' => 'annual'])" data-exchange-rates-link variant="ghost" size="sm" :disabled="$annualEditing">{{ __('admin.copy.clear') }}</x-ui.button>
                @endif
            </form>
            @if($errors->getBag('annualFilters')->any())
                <x-ui.field-error :messages="$errors->getBag('annualFilters')->all()" />
            @endif
        </x-slot:filters>
    @endif

    @if($annualManagement && $annualNew && ! $annualEditing)
        <form method="GET" action="{{ route($masterRoutePrefix.'.exchange-rates.index') }}" class="tw:flex tw:flex-wrap tw:items-end tw:gap-3 tw:border-b tw:border-outline-variant tw:p-4" data-track-unsaved="false" data-server-tabs-form>
            @foreach($annualListQuery as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <input type="hidden" name="annual_new" value="1">
            <label for="annual-new-year" class="tw:grid tw:gap-1 tw:text-ui-sm tw:font-medium">
                {{ __('annual_rates.new_year') }}
                <input id="annual-new-year" name="annual_new_year" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="{{ is_scalar(request('annual_new_year')) ? request('annual_new_year') : '' }}" class="form-control ui-tabular-nums tw:w-32" required autofocus>
            </label>
            <x-ui.button type="submit" variant="outline">{{ __('annual_rates.continue') }}</x-ui.button>
            <x-ui.button :href="route($masterRoutePrefix.'.exchange-rates.index', $annualListQuery)" data-exchange-rates-link variant="ghost">{{ __('admin.copy.cancel') }}</x-ui.button>
        </form>
    @endif

    @if($annualManagement && ($annualErrors->any() || $annualDuplicateYear !== null))
        <div class="tw:p-4" id="annual-error-summary" tabindex="-1" data-exchange-rates-server-error>
            <x-ui.alert tone="error">
                @if($annualDuplicateYear !== null)<p class="tw:m-0">{{ __('annual_rates.year_exists', ['year' => $annualDuplicateYear]) }}</p>@endif
                @if($annualErrors->any())
                    <ul class="tw:m-0 tw:pl-4">
                        @foreach($annualErrors->getMessages() as $field => $messages)
                            <li><a class="ui-focus-ring" href="#{{ in_array($field, ['rates.USD', 'rates.JPY', 'rates.CNY'], true) && $annualEditing ? 'annual-rate-'.substr($field, 6).'-'.$annualEditYear : 'annual-error-summary' }}">{{ $messages[0] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                @if($annualRecoveryYear !== null)
                    @php($annualRecoveryQuery = $annualRates->get($annualRecoveryYear, collect())->isEmpty() ? ['tab' => 'annual', 'annual_new' => 1, 'annual_new_year' => $annualRecoveryYear] : ['tab' => 'annual', 'annual_edit_year' => $annualRecoveryYear])
                    <a class="ui-focus-ring tw:mt-2 tw:inline-block tw:font-semibold" data-exchange-rates-link href="{{ route($masterRoutePrefix.'.exchange-rates.index', $annualRecoveryQuery) }}#annual-rate-USD-{{ $annualRecoveryYear }}">{{ __('annual_rates.reload_latest', ['year' => $annualRecoveryYear]) }}</a>
                @endif
            </x-ui.alert>
        </div>
    @endif

    @if($annualEditing)
        <p id="annual-rate-hint" class="tw:m-0 tw:border-b tw:border-outline-variant tw:px-4 tw:py-3 tw:text-ui-xs tw:text-on-surface-variant">{{ __('annual_rates.number_hint') }} {{ __('annual_rates.finish_edit') }}</p>
        @php($annualReviewQuery = $annualNew ? ['tab' => 'annual', 'annual_new' => 1, 'annual_new_year' => $annualEditYear] : ['tab' => 'annual', 'annual_edit_year' => $annualEditYear])
        <form id="annual-rate-form" method="POST" action="{{ route($masterRoutePrefix.'.exchange-rates.annual.store') }}" data-track-unsaved="true" data-exchange-rates-save data-async-files-hint="false" data-exchange-rates-review-url="{{ route($masterRoutePrefix.'.exchange-rates.index', $annualReviewQuery) }}" data-exchange-rates-review-label="{{ __('annual_rates.reload_latest', ['year' => $annualEditYear]) }}">
            @csrf
            <input type="hidden" name="year" value="{{ $annualEditYear }}">
            <input type="hidden" name="expected_revision" value="{{ $annualRevision }}">
    @endif

    @if(empty($annualMatrixYears))
        <x-ui.empty-state icon="badge-dollar-sign" :title="__('annual_rates.empty')" :description="__('annual_rates.empty_help')" />
    @else
        <div class="tw:relative tw:isolate tw:overflow-x-auto tw:overflow-y-hidden" tabindex="0" role="region" aria-label="{{ __('annual_rates.title') }}">
            <table data-annual-rates-matrix class="table align-middle tw:m-0 tw:w-full tw:text-ui-sm" style="--bs-table-color: var(--md-on-surface); --bs-table-bg: var(--md-surface);">
                <caption class="visually-hidden">{{ __('annual_rates.description') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="tw:sticky tw:left-0 tw:z-sticky tw:min-w-24 tw:border-r tw:border-outline-variant tw:bg-surface">{{ __('admin.copy.currency') }}</th>
                        @foreach($annualMatrixYears as $matrixYear)
                            <th scope="col" data-annual-year="{{ $matrixYear }}" class="text-end tw:min-w-36 {{ $annualEditing && $annualEditYear === (int) $matrixYear ? 'tw:bg-primary-container tw:text-on-primary-container' : '' }}">
                                @if($annualManagement)
                                    <span>{{ $matrixYear }}</span>
                                    @if($annualEditing && $annualEditYear === (int) $matrixYear)
                                        <div class="tw:mt-1 tw:text-ui-xs tw:font-normal">{{ $annualNew ? __('annual_rates.unsaved_year') : __('annual_rates.editing') }}</div>
                                    @endif
                                @else
                                    @php($dashboardYearQuery = $annualRates->get($matrixYear, collect())->isEmpty() ? ['tab' => 'annual', 'annual_new' => 1, 'annual_new_year' => $matrixYear] : ['tab' => 'annual', 'annual_edit_year' => $matrixYear])
                                    <a class="ui-focus-ring" href="{{ route($masterRoutePrefix.'.exchange-rates.index', $dashboardYearQuery) }}#annual-rate-USD-{{ $matrixYear }}" aria-label="{{ __('annual_rates.edit_year', ['year' => $matrixYear]) }}">{{ $matrixYear }}</a>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($annualCurrencies as $code)
                        <tr>
                            <th scope="row" class="tw:sticky tw:left-0 tw:z-sticky tw:border-r tw:border-outline-variant tw:bg-surface">{{ $code }}</th>
                            @foreach($annualMatrixYears as $matrixYear)
                                @php($annualCell = $annualRates->get($matrixYear, collect())->firstWhere('currency', $code))
                                <td class="text-end ui-tabular-nums" @if($annualEditing && $annualEditYear === (int) $matrixYear) style="--bs-table-bg: var(--md-primary-container);" @endif>
                                    @if($annualEditing && $annualEditYear === (int) $matrixYear)
                                        @php($annualInputId = 'annual-rate-'.$code.'-'.$matrixYear)
                                        <label class="visually-hidden" for="{{ $annualInputId }}">{{ __('annual_rates.rate_label', ['currency' => $code, 'year' => $matrixYear]) }}</label>
                                        {{-- Values come from the annual error bag only, not the default old-input lookup. --}}
                                        <input id="{{ $annualInputId }}" name="rates[{{ $code }}]" type="text" inputmode="decimal" pattern="^[0-9]+(\.[0-9]{1,4})?$" autocomplete="off" placeholder="0.0000" data-annual-rate-input data-format-number="true" data-unit="IDR" onkeydown="if (!/^[0-9.,]$/.test(event.key) && !['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(event.key) && !event.ctrlKey && !event.metaKey) { event.preventDefault(); return; } if ((event.key === '.' || event.key === ',') && this.value.includes('.') && !this.value.slice(this.selectionStart, this.selectionEnd).includes('.')) { event.preventDefault(); return; } if (event.key === ',') { event.preventDefault(); var s=this.selectionStart, v=this.value; this.value=v.slice(0,s)+'.'+v.slice(this.selectionEnd); this.setSelectionRange(s+1,s+1); this.dispatchEvent(new Event('input', {bubbles:true})); }" oninput="var v=this.value.replace(/,/g,'.').replace(/[^0-9.]/g,''); var p=v.indexOf('.'); if(p!==-1){ v=v.slice(0,p+1)+v.slice(p+1).replace(/\./g,''); var d=v.split('.'); if(d[1]&&d[1].length>4) v=d[0]+'.'+d[1].slice(0,4); } if(this.value!==v) this.value=v;" value="{{ $annualInputRates[$code] }}" required aria-invalid="{{ $annualErrors->has('rates.'.$code) ? 'true' : 'false' }}" aria-describedby="annual-rate-hint{{ $annualErrors->has('rates.'.$code) ? ' '.$annualInputId.'-error' : '' }}" class="ui-motion ui-focus-ring tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:min-w-32 tw:rounded-ui-sm tw:border tw:bg-surface tw:px-3 tw:py-2 tw:text-right tw:text-ui-sm tw:text-on-surface {{ $annualErrors->has('rates.'.$code) ? 'tw:border-error' : 'tw:border-outline-strong' }}" @if($code === 'USD') autofocus @endif>
                                        <x-ui.field-error :id="$annualInputId.'-error'" :messages="$annualErrors->get('rates.'.$code)" data-exchange-rates-server-error class="tw:mt-1 tw:text-left" />
                                    @elseif($code === 'IDR')
                                        <span title="{{ __('annual_rates.identity') }}">1</span>
                                    @elseif($annualCell)
                                        <span>{{ $regionalFormatter->groupedDecimal($annualCell->rate_to_idr, 4) }}</span>
                                    @else
                                        <span class="tw:text-on-surface-variant">{{ __('annual_rates.missing') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if($annualManagement)
                    <tfoot>
                        <tr>
                            <th scope="row" class="tw:sticky tw:left-0 tw:z-sticky tw:border-r tw:border-outline-variant tw:bg-surface">{{ __('annual_rates.actions') }}</th>
                            @foreach($annualMatrixYears as $matrixYear)
                                <td class="text-end" @if($annualEditing && $annualEditYear === (int) $matrixYear) style="--bs-table-bg: var(--md-primary-container);" @endif>
                                    @if($annualEditing && $annualEditYear === (int) $matrixYear)
                                        <div class="tw:flex tw:flex-wrap tw:justify-end tw:gap-2">
                                            <x-ui.button type="submit" size="sm"><x-ui.icon name="save" /> {{ __('annual_rates.save_short') }}</x-ui.button>
                                            <x-ui.button :href="route($masterRoutePrefix.'.exchange-rates.index', $annualListQuery)" data-exchange-rates-link variant="ghost" size="sm">{{ __('admin.copy.cancel') }}</x-ui.button>
                                        </div>
                                    @else
                                        <x-ui.button :href="route($masterRoutePrefix.'.exchange-rates.index', [...$annualListQuery, 'annual_edit_year' => $matrixYear]).'#annual-rate-USD-'.$matrixYear" data-exchange-rates-link :disabled="$annualEditing" :class="$annualEditing ? 'tw:opacity-50 tw:cursor-not-allowed' : ''" variant="outline" size="sm" :aria-label="__('annual_rates.edit_year', ['year' => $matrixYear])">{{ __('annual_rates.edit') }}</x-ui.button>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    @if($annualEditing)</form>@endif

    @if($annualManagement && $annualYears->hasPages())
        <x-slot:pagination>
            @if($annualEditing)
                <nav aria-label="{{ __('annual_rates.title') }}"><ul class="pagination tw:m-0"><li class="page-item disabled"><span class="page-link" aria-disabled="true">{{ __('common.final_review.page', ['page' => $annualYears->currentPage(), 'last' => $annualYears->lastPage()]) }}</span></li></ul></nav>
            @else
                {{ $annualYears->links('pagination::bootstrap-5') }}
            @endif
        </x-slot:pagination>
    @endif
</x-ui.data-table>

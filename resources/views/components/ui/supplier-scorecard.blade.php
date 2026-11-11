@props([
    'scorecard' => null,
    'supplier' => null,
    'scope' => null,
    'title' => null,
])

@php
    if (! $scorecard instanceof \App\Data\VendorMaster\SupplierScorecardData && $supplier instanceof \App\Models\User) {
        $scorecard = app(\App\Services\VendorMaster\SupplierScorecardService::class)->forSupplier($supplier, $scope);
    }
    $cardTitle = $title ?? __('scorecard.title');
    $scopeLabel = $scorecard?->scope === 'import'
        ? __('scorecard.scopes.import')
        : __('scorecard.scopes.local');
@endphp

@if($scorecard instanceof \App\Data\VendorMaster\SupplierScorecardData)
    <section
        {{ $attributes->merge(['class' => 'tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface tw:p-4']) }}
        data-supplier-scorecard="{{ $scorecard->scope }}"
        data-scorecard-grade="{{ $scorecard->grade }}"
    >
        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:pb-3 tw:border-b tw:border-outline-variant">
            <div>
                <div class="tw:flex tw:items-center tw:gap-2">
                    <x-ui.icon name="award" size="sm" class="tw:text-primary" />
                    <h3 class="tw:m-0 tw:text-ui-sm tw:font-bold tw:text-on-surface">{{ $cardTitle }}</h3>
                    <x-ui.status-chip tone="neutral" size="sm">{{ $scopeLabel }}</x-ui.status-chip>
                </div>
                <p class="tw:mt-1 tw:mb-0 tw:text-ui-xs tw:text-on-surface-variant">
                    {{ $scorecard->hasData ? __('scorecard.subtitle') : __('scorecard.no_data_subtitle') }}
                </p>
            </div>
            <div class="tw:flex tw:items-center tw:gap-3">
                <div class="tw:text-end">
                    <span class="tw:block tw:text-[11px] tw:text-on-surface-variant">{{ __('scorecard.composite_score') }}</span>
                    <strong class="ui-tabular-nums tw:text-ui-lg tw:font-bold tw:text-on-surface">{{ $scorecard->compositeScore }}%</strong>
                </div>
                <x-ui.status-chip :tone="$scorecard->gradeTone()">
                    {{ __('scorecard.grade_badge', ['grade' => $scorecard->grade]) }}
                </x-ui.status-chip>
            </div>
        </div>

        <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-3 tw:gap-3 tw:pt-3">
            @foreach($scorecard->metrics as $metric)
                <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3">
                    <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
                        <span class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __($metric['label_key']) }}</span>
                        <strong class="ui-tabular-nums tw:text-ui-sm tw:font-bold tw:text-on-surface">
                            {{ $metric['has_data'] ? $metric['percentage'] . '%' : '—' }}
                        </strong>
                    </div>
                    <div class="tw:mt-2 tw:h-1.5 tw:w-full tw:overflow-hidden tw:rounded-full tw:bg-outline-variant">
                        <div
                            class="tw:h-full tw:rounded-full tw:bg-primary"
                            style="width: {{ $metric['has_data'] ? min(100, max(0, (float) $metric['percentage'])) : 0 }}%"
                        ></div>
                    </div>
                    <div class="tw:mt-1.5 tw:flex tw:items-center tw:justify-between tw:text-[11px] tw:text-on-surface-variant">
                        <span>{{ $metric['has_data'] ? __('scorecard.sample_ratio', ['num' => $metric['numerator'], 'den' => $metric['denominator']]) : __('scorecard.no_samples') }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif


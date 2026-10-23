{{--
    Shared public receipt verification card.
    Expects: $title, $unit, $receiptNumber, $rows ([label, value, emphasis?, numeric?]),
    $amountLabel, $amount (formatted, without currency), $statusLabel, $statement.
--}}
<article class="tw:mx-auto tw:max-w-lg tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface tw:shadow-ui-1">
    <header class="tw:flex tw:items-start tw:gap-3 tw:border-b tw:border-outline-variant tw:bg-success-container tw:px-5 tw:py-4 tw:text-success-container-foreground">
        <x-ui.icon name="shield-check" size="lg" class="tw:mt-0.5 tw:shrink-0" aria-hidden="true" />
        <div class="tw:min-w-0">
            <h1 class="tw:m-0 tw:text-ui-base tw:font-semibold [text-wrap:balance]">{{ $title }}</h1>
            <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs">{{ $unit }}</p>
        </div>
    </header>

    <div class="tw:px-5 tw:pt-5">
        <p class="tw:m-0 tw:text-ui-xs tw:font-medium tw:uppercase tw:tracking-wide tw:text-on-surface-variant">{{ rtrim(__('common.final_review.receipt_number'), ': ') }}</p>
        <p class="tw:m-0 tw:mt-1 tw:break-all tw:text-xl tw:font-semibold tw:tabular-nums tw:text-primary">{{ $receiptNumber }}</p>
    </div>

    <dl class="tw:m-0 tw:px-5 tw:py-3">
        @foreach ($rows as $row)
            <div class="tw:grid tw:gap-0.5 tw:border-b tw:border-outline-variant tw:py-2.5 tw:last:border-b-0 tw:sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] tw:sm:gap-4">
                <dt class="tw:text-ui-xs tw:text-on-surface-variant">{{ rtrim($row['label'], ': ') }}</dt>
                <dd @class([
                    'tw:m-0 tw:wrap-break-word tw:text-ui-sm tw:sm:text-right',
                    'tw:font-semibold' => $row['emphasis'] ?? false,
                    'tw:tabular-nums' => $row['numeric'] ?? false,
                ])>{{ filled($row['value']) ? $row['value'] : '—' }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="tw:mx-5 tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-2 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:px-4 tw:py-3">
        <span class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ rtrim($amountLabel, ': ') }}</span>
        <span class="tw:text-lg tw:font-semibold tw:tabular-nums tw:text-on-surface">Rp {{ $amount }}</span>
    </div>

    <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:px-5 tw:pt-4">
        <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('local_invoice.receipt.system_status') }}</span>
        <x-ui.status-chip tone="success">{{ $statusLabel }}</x-ui.status-chip>
    </div>

    <div class="tw:m-5 tw:flex tw:gap-2 tw:rounded-ui-sm tw:bg-surface-container tw:p-3 tw:text-ui-xs tw:text-on-surface-variant">
        <x-ui.icon name="info" size="sm" class="tw:mt-0.5 tw:shrink-0" aria-hidden="true" />
        <p class="tw:m-0 [text-wrap:pretty]">{{ $statement }}</p>
    </div>
</article>

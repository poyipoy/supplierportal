{{--
    Supplier contact person (Decision 10). "–" when a value is missing.
    @param \App\Models\Supplier|null $profile
--}}
@php
    $picName = $profile?->pic_name;
    $picPhone = $profile?->pic_phone;
    $picEmail = $profile?->pic_email;
@endphp
<div class="tw:mt-2 tw:border-t tw:border-outline-variant tw:pt-2 tw:text-ui-xs" data-supplier-pic>
    <div class="fw-semibold tw:uppercase tw:text-on-surface-variant tw:mb-1">{{ __('procurement.quotation.pic_title') }}</div>
    <dl class="tw:m-0 tw:grid tw:grid-cols-[auto_minmax(0,1fr)] tw:gap-x-2 tw:gap-y-0.5">
        <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('procurement.quotation.pic_name') }}</dt>
        <dd class="tw:m-0 tw:text-on-surface fw-semibold tw:wrap-break-word">{{ $picName ?: '–' }}</dd>
        <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('procurement.quotation.pic_phone') }}</dt>
        <dd class="tw:m-0 tw:wrap-break-word">
            @if($picPhone)
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $picPhone) }}" class="ui-focus-ring">{{ $picPhone }}</a>
            @else
                –
            @endif
        </dd>
        <dt class="tw:font-normal tw:text-on-surface-variant">{{ __('procurement.quotation.pic_email') }}</dt>
        <dd class="tw:m-0 tw:break-all">
            @if($picEmail)
                <a href="mailto:{{ $picEmail }}" class="ui-focus-ring">{{ $picEmail }}</a>
            @else
                –
            @endif
        </dd>
    </dl>
</div>

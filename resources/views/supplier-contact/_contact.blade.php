{{--
    Read-only contact person definition list.
    @param \App\Models\Supplier|null $profile
--}}
@php
    $notSet = __('supplier_contact.common.not_set');
    $phoneHref = $profile?->pic_phone ? preg_replace('/[^0-9+]/', '', $profile->pic_phone) : null;
@endphp
<dl class="tw-m-0 tw-grid tw-grid-cols-[auto_minmax(0,1fr)] tw-gap-x-4 tw-gap-y-2 tw-text-ui-sm">
    <dt class="tw-font-normal tw-text-on-surface-variant">{{ __('supplier_contact.fields.name') }}</dt>
    <dd class="tw-m-0 tw-font-semibold tw-text-on-surface tw-break-words">{{ $profile?->pic_name ?: $notSet }}</dd>
    <dt class="tw-font-normal tw-text-on-surface-variant">{{ __('supplier_contact.fields.email') }}</dt>
    <dd class="tw-m-0 tw-break-all">
        @if($profile?->pic_email)
            <a href="mailto:{{ $profile->pic_email }}" class="ui-focus-ring">{{ $profile->pic_email }}</a>
        @else
            <span class="tw-text-on-surface-variant">{{ $notSet }}</span>
        @endif
    </dd>
    <dt class="tw-font-normal tw-text-on-surface-variant">{{ __('supplier_contact.fields.phone') }}</dt>
    <dd class="tw-m-0 tw-break-words">
        @if($phoneHref)
            <a href="tel:{{ $phoneHref }}" class="ui-focus-ring">{{ $profile->pic_phone }}</a>
        @else
            <span class="tw-text-on-surface-variant">{{ $notSet }}</span>
        @endif
    </dd>
</dl>

{{-- Maintenance notice shown to every non-admin role: only title, description, start and estimated end. --}}
@php $noticeHeading = in_array($headingTag ?? 'h2', ['h2', 'h3'], true) ? ($headingTag ?? 'h2') : 'h2'; @endphp
<div class="tw:grid tw:gap-4">
    @foreach($notices as $index => $notice)
        <section aria-labelledby="maintenance-notice-{{ $index }}" class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-5">
            <{{ $noticeHeading }} id="maintenance-notice-{{ $index }}" class="tw:m-0 tw:text-ui-lg tw:font-bold tw:leading-tight tw:text-on-surface tw:text-balance">{{ $notice['title'] }}</{{ $noticeHeading }}>
            <p class="tw:m-0 tw:mt-2 tw:text-ui-sm tw:text-on-surface tw:whitespace-pre-line tw:text-pretty">{{ $notice['description'] }}</p>
            <dl class="tw:m-0 tw:mt-4 tw:grid tw:gap-3 tw:border-t tw:border-outline-variant tw:pt-4 tw:sm:grid-cols-2">
                <div>
                    <dt class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('maintenance.notice.start') }}</dt>
                    <dd class="tw:m-0 tw:mt-0.5 tw:text-ui-sm tw:font-semibold tw:text-on-surface ui-tabular-nums">{{ $notice['starts_at'] }}</dd>
                </div>
                <div>
                    <dt class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('maintenance.notice.estimated_end') }}</dt>
                    <dd class="tw:m-0 tw:mt-0.5 tw:text-ui-sm tw:font-semibold tw:text-on-surface ui-tabular-nums">{{ $notice['estimated_end_at'] }}</dd>
                </div>
            </dl>
        </section>
    @endforeach
</div>

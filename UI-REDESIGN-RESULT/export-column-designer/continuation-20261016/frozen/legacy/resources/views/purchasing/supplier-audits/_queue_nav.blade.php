{{-- Isi nav antrean; dirender server untuk halaman penuh maupun fragmen server tabs agar href (filter terbawa) dan hitungan selalu segar. --}}
@php
    $queueKeys = ['review', 'waiting', 'late', 'done', 'all'];
    $baseQuery = array_filter(['period' => $filters['period'] ?? null, 'supplier' => $filters['supplier'] ?? null]);
@endphp
<ul class="tw-m-0 tw-flex tw-min-w-max tw-list-none tw-gap-1 tw-border-b tw-border-outline-variant tw-p-0">
    @foreach($queueKeys as $key)
        <li>
            <a href="{{ route('purchasing.supplier-audits.index', [...$baseQuery, 'queue' => $key]) }}"
                class="ui-focus-ring tw-group tw-relative tw-flex tw-min-h-10 tw-items-center tw-gap-2 tw-px-3 tw-text-ui-sm tw-text-on-surface-variant tw-no-underline hover:tw-text-on-surface aria-[current=page]:tw-font-semibold aria-[current=page]:tw-text-on-surface"
                data-server-tab data-tab-name="{{ $key }}"
                @if($queue === $key) aria-current="page" @endif data-queue-tab="{{ $key }}">
                <span>{{ __('supplier_audit.queues.'.$key) }}</span>
                <span class="tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-px-1.5 tw-text-ui-xs {{ $key === 'late' && $queueCounts[$key] > 0 ? 'tw-bg-error-container tw-text-error' : 'tw-bg-surface-container tw-text-on-surface-variant' }}"
                    style="font-variant-numeric: tabular-nums;" data-queue-count="{{ $key }}">{{ $queueCounts[$key] }}</span>
                <span class="tw-absolute tw-inset-x-2 -tw-bottom-px tw-hidden tw-h-0.5 tw-bg-primary group-aria-[current=page]:tw-block" aria-hidden="true"></span>
            </a>
        </li>
    @endforeach
</ul>

{{-- Isi nav status; dirender server untuk halaman penuh dan fragmen server tabs agar href (search terbawa) dan hitungan selalu segar. --}}
@php
    $tabs = [
        'ALL' => ['label' => __('local_procurement.registration.all'), 'icon' => 'layers'],
        'PENDING' => ['label' => __('local_procurement.registration.pending_review'), 'icon' => 'clock'],
        'REVISION' => ['label' => __('local_procurement.registration.revision_required'), 'icon' => 'alert-circle'],
        'APPROVED' => ['label' => __('local_procurement.registration.approved'), 'icon' => 'check-circle'],
        'REJECTED' => ['label' => __('local_procurement.registration.rejected'), 'icon' => 'x-circle'],
    ];
@endphp
@foreach ($tabs as $key => $tab)
    <a
        href="{{ route('supplier-registrations.index', ['status' => $key, 'search' => request('search')]) }}"
        class="ui-focus-ring ui-motion tw-group tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3.5 tw-py-2 tw-text-ui-sm tw-font-medium tw-text-on-surface-variant tw-no-underline hover:tw-bg-surface-container aria-[current=page]:tw-border-primary aria-[current=page]:tw-bg-primary aria-[current=page]:tw-text-primary-foreground"
        data-server-tab data-tab-name="{{ $key }}"
        @if ($statusFilter === $key) aria-current="page" @endif
    >
        <x-ui.icon :name="$tab['icon']" size="xs" />
        <span>{{ $tab['label'] }}</span>
        <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-surface-container-high tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold tw-text-on-surface group-aria-[current=page]:tw-bg-primary group-aria-[current=page]:tw-text-primary-foreground" data-tab-count="{{ $key }}">
            {{ $counts[$key] ?? 0 }}
        </span>
    </a>
@endforeach

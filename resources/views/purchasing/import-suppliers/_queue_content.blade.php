{{-- Swapped by server tabs: search + table + pagination + empty state for one queue. --}}
@php
    $importResetUrl = route('purchasing.import-suppliers.index', ['contact' => $queue]);
    $importChips = [];
    if ($search !== '') {
        $importChips[] = [
            'key' => 'q',
            'label' => __('supplier_contact.directory.search_label'),
            'value' => (string) $search,
            'removeUrl' => $importResetUrl,
        ];
    }
@endphp

<x-ui.data-table :empty="$suppliers->isEmpty()">
    <x-slot:filters>
        <x-ui.filter-bar
            id="importSupplierFilterForm"
            :action="route('purchasing.import-suppliers.index')"
            variant="embedded"
            :server-tabs="true"
            :has-active-filters="$search !== ''"
            :reset-url="$importResetUrl"
            :reset-label="__('supplier_contact.directory.reset')"
            :submit-label="__('supplier_contact.directory.search')"
        >
            <x-slot:hidden>
                <input type="hidden" name="contact" value="{{ $queue }}">
            </x-slot:hidden>

            <x-slot:search>
                <label for="importSupplierSearch" class="tw:sr-only">{{ __('supplier_contact.directory.search_label') }}</label>
                <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                <input
                    id="importSupplierSearch"
                    name="q"
                    type="search"
                    value="{{ $search }}"
                    placeholder="{{ __('supplier_contact.placeholders.search') }}"
                    maxlength="100"
                    autocomplete="off"
                    class="ui-input ui-input--sm ui-filter-bar__search-input"
                />
            </x-slot:search>

            <x-slot:chips>
                <x-ui.filter-chips :items="$importChips" :clear-url="$importResetUrl" :server-tabs="true" />
            </x-slot:chips>
        </x-ui.filter-bar>
    </x-slot:filters>

    <x-slot:emptyState>
        @if($search !== '')
            <x-ui.empty-state icon="search" :title="__('supplier_contact.directory.empty_filtered')" />
        @elseif($queue === 'pending')
            <x-ui.empty-state icon="contact-round" :title="__('supplier_contact.directory.empty_pending')" :description="__('supplier_contact.directory.empty_pending_hint')"
                :action-url="route('purchasing.import-suppliers.index', ['contact' => 'missing'])" :action-text="__('supplier_contact.directory.view_missing')" action-icon="arrow-right" />
        @elseif($queue === 'missing')
            <x-ui.empty-state icon="contact-round" :title="__('supplier_contact.directory.empty_missing')" />
        @else
            <x-ui.empty-state icon="contact-round" :title="__('supplier_contact.directory.empty')" />
        @endif
    </x-slot:emptyState>

    <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-sm tw:w-full">
        <thead>
            <tr>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.directory.col_supplier') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.directory.col_contact') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.directory.col_status') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('common.table.action') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($suppliers as $vendorUser)
                @php
                    $profile = $vendorUser->supplier;
                    $companyName = $profile?->company_name ?: $vendorUser->name;
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('purchasing.import-suppliers.show', $vendorUser) }}" class="tw:font-semibold tw:text-on-surface tw:no-underline tw:hover:underline tw:wrap-break-word">{{ $companyName }}</a>
                        <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant tw:break-all">{{ $vendorUser->email }}</span>
                    </td>
                    <td>
                        @if(filled($profile?->pic_name))
                            <span class="tw:block tw:text-on-surface tw:wrap-break-word">{{ $profile->pic_name }}</span>
                            <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant tw:break-all">{{ collect([$profile->pic_email, $profile->pic_phone])->filter()->implode(' · ') }}</span>
                        @else
                            <span class="tw:text-on-surface-variant">{{ __('supplier_contact.common.not_set') }}</span>
                        @endif
                    </td>
                    <td>
                        @if($vendorUser->has_pending_contact)
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::contactRequestTone('PENDING')">
                                {{ \App\Support\StatusHelper::contactRequestLabel('PENDING') }}
                            </x-ui.status-chip>
                        @else
                            <span class="tw:text-on-surface-variant">&ndash;</span>
                        @endif
                    </td>
                    <td class="tw:text-end">
                        <x-ui.row-action
                            :href="route('purchasing.import-suppliers.show', $vendorUser)"
                            :context="$companyName"
                        />
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($suppliers->hasPages())
        <x-slot:pagination>
            {{ $suppliers->links() }}
        </x-slot:pagination>
    @endif
</x-ui.data-table>

{{-- Swapped by server tabs: search + table + pagination + empty state for one queue. --}}
<x-ui.data-table :empty="$suppliers->isEmpty()">
    <x-slot:filters>
        <form method="GET" action="{{ route('purchasing.import-suppliers.index') }}" class="tw:flex tw:w-full tw:flex-col tw:gap-2.5 tw:shell:flex-row tw:shell:flex-wrap tw:shell:items-end" role="search" data-server-tabs-form>
            <input type="hidden" name="contact" value="{{ $queue }}">
            <x-ui.input name="q" id="importSupplierSearch" type="search" :label="__('supplier_contact.directory.search_label')" :value="$search" :placeholder="__('supplier_contact.placeholders.search')" maxlength="100" class="tw:min-w-64" />
            <div class="tw:flex tw:gap-2">
                <x-ui.button type="submit" variant="outline" size="sm">
                    <x-ui.icon name="search" size="sm" />
                    <span>{{ __('supplier_contact.directory.search') }}</span>
                </x-ui.button>
                @if($search !== '')
                    <x-ui.button :href="route('purchasing.import-suppliers.index', ['contact' => $queue])" variant="ghost" size="sm">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('supplier_contact.directory.reset') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </form>
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

    <table class="table table-hover align-middle tw:m-0 tw:text-ui-sm w-100">
        <thead class="table-light">
            <tr>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.directory.col_supplier') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.directory.col_contact') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_contact.directory.col_status') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold text-end"><span class="tw:sr-only">{{ __('supplier_contact.directory.col_action') }}</span></th>
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
                    <td class="text-end">
                        <x-ui.button :href="route('purchasing.import-suppliers.show', $vendorUser)" size="sm" :variant="$vendorUser->has_pending_contact ? 'primary' : 'outline'">
                            <span>{{ __('supplier_contact.directory.open') }}</span>
                            <span class="tw:sr-only">{{ $companyName }}</span>
                        </x-ui.button>
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

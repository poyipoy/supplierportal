@extends('layouts.app')
@section('title', __('admin.copy.announcements_adasi_portal'))
@section('page-title', __('admin.copy.announcements'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('admin.copy.announcements')" :description="__('admin.copy.maintain_concise_portal_wide_notices_and_their_publication_state')" :eyebrow="__('admin.copy.admin_content')">
        <x-slot:actions><x-ui.button :href="route('admin.announcements.create')" size="sm"><x-ui.icon name="plus" /> {{ __('admin.copy.create_announcement') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar
        id="announcementsFilterBar"
        mode="live"
        :aria-label="__('admin.copy.announcement_table_controls')"
        reset-id="resetAnnouncementFilters"
        :reset-label="__('admin.copy.reset')"
    >
        <x-slot:search>
            <label for="announcementSearch" class="tw:sr-only">{{ __('admin.copy.filter_announcement_titles_on_this_page') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                name="announcement_search"
                id="announcementSearch"
                type="search"
                placeholder="{{ __('admin.copy.filter_titles_on_this_page') }}"
                aria-label="{{ __('admin.copy.filter_announcement_titles_on_this_page') }}"
                autocomplete="off"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
            />
        </x-slot:search>
        <x-slot:filters>
            <label for="announcementStatusFilter" class="tw:sr-only">{{ __('admin.copy.publication_status') }}</label>
            <select id="announcementStatusFilter" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-44">
                <option value="">{{ __('admin.copy.all_statuses') }}</option>
                <option value="published">{{ __('admin.copy.published') }}</option>
                <option value="draft">{{ __('admin.copy.draft') }}</option>
            </select>
        </x-slot:filters>
        <x-slot:chips>
            <x-ui.filter-chips id="announcementFilterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    <x-ui.data-table :title="__('admin.copy.announcement_register')" :description="__('admin.page.announcement_count', ['count' => $announcements->total()])">
        <div class="ui-data-table__scroll tw:overflow-x-auto">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:w-full tw:text-ui-sm">
                <thead><tr><th scope="col" class="tw:text-center">{{ __('admin.copy.no') }}</th><th scope="col">{{ __('admin.copy.announcement') }}</th><th scope="col">{{ __('admin.copy.owner') }}</th><th scope="col">{{ __('admin.copy.status') }}</th><th scope="col">{{ __('admin.copy.publication_date') }}</th><th scope="col" class="tw:text-end">{{ __('admin.copy.actions') }}</th></tr></thead>
                <tbody>
                    @forelse($announcements as $i => $ann)
                        <tr data-announcement-row data-title="{{ str($ann->title)->lower() }}" data-status="{{ $ann->published_at ? 'published' : 'draft' }}">
                            <td class="tw:text-center tw:text-on-surface-variant">{{ $announcements->firstItem() + $i }}</td>
                            <td><div class="tw:font-semibold">{{ $ann->title }}</div><div class="tw:mt-1 tw:max-w-2xl tw:text-ui-xs tw:text-on-surface-variant">{{ \Illuminate\Support\Str::limit(strip_tags($ann->content), 110) }}</div></td>
                            <td>{{ $ann->creator->name ?? '-' }}</td>
                            <td><x-ui.status-chip :tone="$ann->published_at ? 'success' : 'neutral'">{{ $ann->published_at ? __('admin.copy.published') : __('admin.copy.draft') }}</x-ui.status-chip></td>
                            <td class="tw:whitespace-nowrap">{{ $ann->published_at ? $regionalFormatter->timestamp($ann->published_at, 'datetime_comma') : '-' }}</td>
                            <td class="tw:text-end tw:whitespace-nowrap">
                                <div class="tw:inline-flex tw:items-center tw:gap-1">
                                    <x-ui.button :href="route('admin.announcements.edit', $ann->id)" variant="outline-primary" size="sm">{{ __('admin.copy.edit') }}</x-ui.button>
                                    <div class="dropdown">
                                        <x-ui.button type="button" variant="outline" size="sm" class="dropdown-toggle" data-ui-dropdown-trigger popovertarget="dd-ann-{{ $ann->id }}" aria-controls="dd-ann-{{ $ann->id }}" aria-haspopup="menu" aria-expanded="false" aria-label="{{ __('admin.page.more_actions', ['title' => $ann->title]) }}">{{ __('admin.copy.more') }}</x-ui.button>
                                        <ul id="dd-ann-{{ $ann->id }}" popover="auto" data-ui-dropdown-menu class="dropdown-menu dropdown-menu-end">
                                            <li><form action="{{ route('admin.announcements.toggle-publish', $ann->id) }}" method="POST">@csrf<button type="submit" class="dropdown-item">{{ $ann->published_at ? __('admin.copy.move_to_draft') : __('admin.copy.publish_announcement') }}</button></form></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><form action="{{ route('admin.announcements.destroy', $ann->id) }}" method="POST" class="delete-announcement-form">@csrf @method('DELETE')<button type="button" class="dropdown-item tw:text-error btn-delete-announcement">{{ __('admin.copy.delete_announcement') }}</button></form></li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="megaphone" :title="__('admin.copy.no_announcements_yet')" :description="__('admin.copy.create_an_announcement_when_portal_wide_information_needs_to_be_shared')" /></td></tr>
                    @endforelse
                    <tr id="announcementFilterEmpty" class="tw:hidden"><td colspan="6"><x-ui.empty-state icon="search-x" :title="__('admin.copy.no_matching_announcements')" :description="__('admin.copy.clear_the_current_page_filters_and_try_again')" /></td></tr>
                </tbody>
            </table>
        </div>
        @if($announcements->hasPages())
            <x-slot:pagination>
                {{ $announcements->links('pagination::bootstrap-5') }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('announcementSearch');
    const status = document.getElementById('announcementStatusFilter');
    const rows = Array.from(document.querySelectorAll('[data-announcement-row]'));
    const empty = document.getElementById('announcementFilterEmpty');
    function filterRows() {
        const term = search.value.trim().toLowerCase();
        const state = status.value;
        let visible = 0;
        rows.forEach((row) => { const matches = (!term || row.dataset.title.includes(term)) && (!state || row.dataset.status === state); row.classList.toggle('tw:hidden', !matches); if (matches) visible += 1; });
        empty?.classList.toggle('tw:hidden', visible !== 0 || rows.length === 0);
    }
    window.AdasiFilterBar.bindLive('#announcementsFilterBar', {
        debounceMs: 150,
        fields: [
            { key: 'search', label: @json(__('local_invoice.actions.search')), selector: '#announcementSearch', type: 'search' },
            { key: 'status', label: @json(__('admin.copy.publication_status')), selector: '#announcementStatusFilter', type: 'select' },
        ],
        onFilterChange: filterRows,
    });
    document.querySelectorAll('.btn-delete-announcement').forEach((button) => button.addEventListener('click', function () {
        const form = this.closest('form');
        AdasiAlert.confirmDanger({ title: @json(__('admin.copy.delete_this_announcement')), text: @json(__('admin.copy.the_announcement_will_be_permanently_removed_from_the_portal')), confirmText: @json(__('admin.copy.delete_announcement')), cancelText: @json(__('admin.copy.cancel')) }).then((result) => {
            if (result.isConfirmed) {
                window.AdasiButton?.startLoading(button);
                form.submit();
            }
        });
    }));
});
</script>
@endpush

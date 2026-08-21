@extends('layouts.app')
@section('title', 'Announcements - ADASI Portal')
@section('page-title', 'Announcements')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Announcements" description="Maintain concise portal-wide notices and their publication state." eyebrow="Admin Content">
        <x-slot:actions><x-ui.button :href="route('admin.announcements.create')" size="sm"><x-ui.icon name="plus" /> Create Announcement</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-ui.toolbar aria-label="Announcement table controls">
        <x-slot:search><x-ui.input name="announcement_search" id="announcementSearch" type="search" placeholder="Filter titles on this page" aria-label="Filter announcement titles on this page" autocomplete="off" /></x-slot:search>
        <x-slot:filters><label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="announcementStatusFilter">Publication Status<select id="announcementStatusFilter" class="form-select form-select-sm tw-min-w-40"><option value="">All statuses</option><option value="published">Published</option><option value="draft">Draft</option></select></label></x-slot:filters>
        <x-slot:actions><x-ui.button type="button" variant="ghost" size="sm" id="resetAnnouncementFilters"><x-ui.icon name="rotate-ccw" /> Reset</x-ui.button></x-slot:actions>
    </x-ui.toolbar>

    <x-ui.data-table title="Announcement Register" description="{{ $announcements->total() }} records. Filters apply to the currently loaded page.">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light"><tr><th scope="col" class="text-center">No</th><th scope="col">Announcement</th><th scope="col">Owner</th><th scope="col">Status</th><th scope="col">Publication Date</th><th scope="col" class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse($announcements as $i => $ann)
                        <tr data-announcement-row data-title="{{ str($ann->title)->lower() }}" data-status="{{ $ann->published_at ? 'published' : 'draft' }}">
                            <td class="text-center text-muted">{{ $announcements->firstItem() + $i }}</td>
                            <td><div class="tw-font-semibold">{{ $ann->title }}</div><div class="tw-mt-1 tw-max-w-2xl tw-text-ui-xs tw-text-on-surface-variant">{{ \Illuminate\Support\Str::limit(strip_tags($ann->content), 110) }}</div></td>
                            <td>{{ $ann->creator->name ?? '-' }}</td>
                            <td><x-ui.status-chip :tone="$ann->published_at ? 'success' : 'neutral'">{{ $ann->published_at ? 'Published' : 'Draft' }}</x-ui.status-chip></td>
                            <td class="text-nowrap">{{ $ann->published_at ? $ann->published_at->format('d M Y, H:i') : '-' }}</td>
                            <td class="text-end text-nowrap">
                                <div class="tw-inline-flex tw-items-center tw-gap-1">
                                    <x-ui.button :href="route('admin.announcements.edit', $ann->id)" variant="secondary" size="sm">Edit</x-ui.button>
                                    <div class="dropdown">
                                        <x-ui.button type="button" variant="outline" size="sm" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $ann->title }}">More</x-ui.button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><form action="{{ route('admin.announcements.toggle-publish', $ann->id) }}" method="POST">@csrf<button type="submit" class="dropdown-item">{{ $ann->published_at ? 'Move to Draft' : 'Publish Announcement' }}</button></form></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><form action="{{ route('admin.announcements.destroy', $ann->id) }}" method="POST" class="delete-announcement-form">@csrf @method('DELETE')<button type="button" class="dropdown-item text-danger btn-delete-announcement">Delete Announcement</button></form></li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="megaphone" title="No announcements yet" description="Create an announcement when portal-wide information needs to be shared." /></td></tr>
                    @endforelse
                    <tr id="announcementFilterEmpty" class="d-none"><td colspan="6"><x-ui.empty-state icon="search-x" title="No matching announcements" description="Clear the current-page filters and try again." /></td></tr>
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
        rows.forEach((row) => { const matches = (!term || row.dataset.title.includes(term)) && (!state || row.dataset.status === state); row.classList.toggle('d-none', !matches); if (matches) visible += 1; });
        empty?.classList.toggle('d-none', visible !== 0 || rows.length === 0);
    }
    search.addEventListener('input', filterRows);
    status.addEventListener('change', filterRows);
    document.getElementById('resetAnnouncementFilters').addEventListener('click', function () { search.value = ''; status.value = ''; filterRows(); });
    document.querySelectorAll('.btn-delete-announcement').forEach((button) => button.addEventListener('click', function () {
        const form = this.closest('form');
        AdasiAlert.confirmDanger({ title: @json('Delete this announcement?'), text: @json('The announcement will be permanently removed from the portal.'), confirmText: @json('Delete Announcement'), cancelText: @json('Cancel') }).then((result) => { if (result.isConfirmed) form.submit(); });
    }));
});
</script>
@endpush

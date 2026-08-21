@extends('layouts.app')
@section('title', 'Announcement Management - ADASI Portal')
@section('page-title', 'Announcement Management')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Announcements" description="Create, publish, and maintain portal-wide notices." eyebrow="Admin">
        <x-slot:actions>
            <x-ui.button :href="route('admin.announcements.create')" size="sm">
                <x-ui.icon name="plus-circle" />
                Create Announcement
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.data-table title="Announcement List" description="{{ $announcements->total() }} announcements available.">
        <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
            <thead class="table-light"><tr><th scope="col">No</th><th scope="col">Title</th><th scope="col">Created By</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col" class="text-end">Action</th></tr></thead>
            <tbody>
                @forelse($announcements as $i => $ann)
                    <tr>
                        <td>{{ $announcements->firstItem() + $i }}</td>
                        <td class="fw-medium">{{ $ann->title }}</td>
                        <td>{{ $ann->creator->name }}</td>
                        <td><x-ui.status-chip :tone="$ann->published_at ? 'success' : 'neutral'">{{ $ann->published_at ? 'Published' : 'Draft' }}</x-ui.status-chip></td>
                        <td>{{ $ann->published_at ? $ann->published_at->format('d M Y H:i') : '-' }}</td>
                        <td>
                            <div class="tw-flex tw-justify-end tw-gap-1">
                                <form action="{{ route('admin.announcements.toggle-publish', $ann->id) }}" method="POST">
                                    @csrf
                                    <x-ui.button type="submit" :variant="$ann->published_at ? 'secondary' : 'primary'" size="sm" icon-only :label="$ann->published_at ? 'Unpublish announcement' : 'Publish announcement'">
                                        <x-ui.icon :name="$ann->published_at ? 'eye-off' : 'eye'" />
                                    </x-ui.button>
                                </form>
                                <x-ui.icon-button icon="pencil" label="Edit announcement" :href="route('admin.announcements.edit', $ann->id)" variant="secondary" size="sm" />
                                <form action="{{ route('admin.announcements.destroy', $ann->id) }}" method="POST">
                                    @csrf @method('DELETE')
                                    <x-ui.button type="submit" variant="danger" size="sm" icon-only label="Delete announcement" onclick="return confirm(@json('Are you sure you want to delete?'))">
                                        <x-ui.icon name="trash" />
                                    </x-ui.button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="megaphone" title="No announcements yet" description="Create an announcement when there is portal-wide information to share." /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($announcements->hasPages())
            <x-slot:pagination>{{ $announcements->links() }}</x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection

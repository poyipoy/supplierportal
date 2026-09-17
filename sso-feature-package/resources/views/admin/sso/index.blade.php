@extends('layouts.app')
@section('title', 'SSO Connections - ADASI Portal')
@section('page-title', 'SSO Connections')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Supplier SSO Connections" description="Manage SAML/OIDC federation for suppliers that require signing in with their own company identity provider." eyebrow="Admin Security">
        <x-slot:actions><x-ui.button :href="route('admin.sso-connections.create')" size="sm"><x-ui.icon name="plus" /> Add Connection</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-ui.data-table title="Connections" description="{{ $connections->total() }} configured.">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Supplier</th>
                        <th scope="col">Domain</th>
                        <th scope="col">Protocol</th>
                        <th scope="col">Status</th>
                        <th scope="col">Enforced</th>
                        <th scope="col">Last used</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($connections as $connection)
                        <tr>
                            <td>
                                <div class="tw-font-semibold">{{ $connection->name }}</div>
                                <div class="tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ $connection->supplier?->company_name ?? '-' }}</div>
                            </td>
                            <td><code>{{ $connection->domain }}</code></td>
                            <td>{{ strtoupper($connection->protocol) }}</td>
                            <td>
                                <x-ui.status-chip :tone="$connection->is_active ? 'success' : 'neutral'">
                                    {{ $connection->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.status-chip>
                            </td>
                            <td>
                                @if($connection->enforce_sso)
                                    <x-ui.status-chip tone="warning">Enforced</x-ui.status-chip>
                                @else
                                    <span class="tw-text-on-surface-variant">Optional</span>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $connection->last_used_at?->format('d M Y, H:i') ?? 'Never' }}</td>
                            <td class="text-end text-nowrap">
                                <div class="tw-inline-flex tw-items-center tw-gap-1">
                                    <x-ui.button :href="route('admin.sso-connections.edit', $connection)" variant="secondary" size="sm">Edit</x-ui.button>
                                    @if($connection->isSaml())
                                        <x-ui.button :href="route('admin.sso-connections.saml-metadata', $connection)" variant="outline" size="sm">SP Metadata</x-ui.button>
                                    @endif
                                    <form action="{{ route('admin.sso-connections.destroy', $connection) }}" method="POST" onsubmit="return confirm('Remove this SSO connection? Users on this domain will fall back to password login.');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant">No SSO connections configured yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>

    {{ $connections->links() }}
</div>
@endsection

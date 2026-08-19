@extends('layouts.app')
@section('title', 'Negotiation & Chat - ADASI Portal')
@section('page-title', 'Negotiation with Suppliers')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Negotiation & chat"
        eyebrow="Purchasing"
        description="Follow supplier conversations in their PR or PO context and prioritize threads that need a response."
    />

    <x-ui.data-table
        title="Supplier conversations"
        description="Search the current page by document, supplier, message, or status."
    >
        <table class="table table-hover align-middle datatable">
            <thead class="table-light">
                <tr>
                    <th>Document context</th>
                    <th>Supplier</th>
                    <th>Latest message</th>
                    <th>Last activity</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($conversations as $conv)
                    @php $sla = \App\Support\ConversationPresenter::slaMeta($conv, auth()->user()); @endphp
                    <tr>
                        <td>
                            @if($conv->conversable_type === 'App\Models\PurchaseRequisition')
                                <x-ui.status-chip tone="info">PR</x-ui.status-chip>
                            @else
                                <x-ui.status-chip tone="success">PO</x-ui.status-chip>
                            @endif
                            <span class="tw-ms-2 tw-font-semibold">{{ $conv->context_label }}</span>
                        </td>
                        <td class="fw-medium">{{ $conv->supplierUser->supplier->company_name ?? $conv->supplierUser->name }}</td>
                        <td>
                            @if($conv->latestMessage)
                                @if($conv->latestMessage->sender_id === auth()->id())
                                    <i class="bi bi-reply tw-me-1 tw-text-on-surface-variant" aria-label="Your reply"></i>
                                @endif
                                {{ Str::limit($conv->latestMessage->body, 50) }}
                            @else
                                <span class="tw-text-on-surface-variant">No messages yet</span>
                            @endif
                        </td>
                        <td>
                            @if($conv->latestMessage)
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $conv->latestMessage->created_at->diffForHumans() }}</span>
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            <div class="tw-flex tw-flex-wrap tw-gap-1.5">
                                <span class="badge {{ $conv->statusBadgeClassFor(auth()->user()) }}">{{ $conv->statusLabelFor(auth()->user()) }}</span>
                                <span class="badge {{ $sla['class'] }}">{{ $sla['label'] }}</span>
                            </div>
                        </td>
                        <td class="text-end">
                            @php $unreadCount = $conv->unreadCountFor(auth()->id()); @endphp
                            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.conversations.show', $conv)" variant="ghost" size="sm" class="tw-relative">
                                <x-slot:leading><i class="bi bi-chat-text" aria-hidden="true"></i></x-slot:leading>
                                Open chat
                                @if($unreadCount > 0)
                                    <x-slot:trailing>
                                        <span class="tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-error tw-px-1.5 tw-py-0.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground">{{ $unreadCount }}</span>
                                    </x-slot:trailing>
                                @endif
                            </x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state icon="bi-chat-square-dots" title="No conversations yet" description="Negotiation threads will appear after a supplier conversation is started." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($conversations instanceof \Illuminate\Contracts\Pagination\Paginator && $conversations->hasPages())
            <x-slot:pagination>{{ $conversations->links('pagination::bootstrap-5') }}</x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection

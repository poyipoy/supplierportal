@extends('layouts.app')

@section('title', __('supplier.copy.negotiations_chat_adasi_portal'))
@section('page-title', __('supplier.copy.negotiations_with_purchasing'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.negotiations') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.copy.negotiations_with_purchasing')"
        :eyebrow="__('supplier.copy.commercial_negotiation')"
        :description="__('supplier.copy.direct_messaging_channels_linked_to_your_supplier_purchase_requisitions_and_purchase_orders')"
    />

    {{-- Conversations DataTable --}}
    <x-ui.data-table
        :title="__('supplier.copy.active_negotiation_channels')"
        :description="__('supplier.copy.prioritize_unread_messages_and_track_purchasing_sla_response_status')"
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100 datatable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('supplier.copy.document_reference') }}</th>
                    <th scope="col">{{ __('supplier.copy.purchasing_officer') }}</th>
                    <th scope="col">{{ __('supplier.copy.latest_message') }}</th>
                    <th scope="col">{{ __('supplier.copy.last_active') }}</th>
                    <th scope="col">{{ __('supplier.copy.status_sla') }}</th>
                    <th scope="col" class="tw-w-36 text-end">{{ __('supplier.copy.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($conversations as $conv)
                    @php
                        $sla = \App\Support\ConversationPresenter::slaMeta($conv, auth()->user());
                        $unreadCount = $conv->unreadCountFor(auth()->id());
                        $statusClass = $conv->statusBadgeClassFor(auth()->user());
                        $statusTone = str_contains($statusClass, 'success') ? 'success' : (str_contains($statusClass, 'warning') ? 'warning' : (str_contains($statusClass, 'danger') ? 'error' : 'neutral'));
                        $slaTone = str_contains($sla['class'], 'success') ? 'success' : (str_contains($sla['class'], 'warning') ? 'warning' : (str_contains($sla['class'], 'danger') ? 'error' : 'neutral'));
                    @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center tw-gap-1.5">
                                @if($conv->conversable_type === 'App\Models\PurchaseRequisition')
                                    <x-ui.status-chip tone="info">PR</x-ui.status-chip>
                                @else
                                    <x-ui.status-chip tone="success">PO</x-ui.status-chip>
                                @endif
                                <span class="fw-bold tw-text-on-surface">{{ $conv->context_label }}</span>
                            </div>
                        </td>
                        <td class="fw-semibold tw-text-on-surface">{{ $conv->purchasingUser->name ?? '-' }}</td>
                        <td class="tw-text-on-surface-variant">
                            @if($conv->latestMessage)
                                <div class="d-flex align-items-center gap-1">
                                    @if($conv->latestMessage->sender_id === auth()->id())
                                <x-ui.icon name="reply" size="sm" class="tw-shrink-0 tw-text-outline" />
                                    @endif
                                    <span class="text-truncate tw-max-w-64">{{ Str::limit($conv->latestMessage->body, 55) }}</span>
                                </div>
                            @else
                                <span class="tw-text-outline italic">{{ __('supplier.copy.no_messages_sent_yet') }}</span>
                            @endif
                        </td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">
                            @if($conv->latestMessage)
                                {{ $conv->latestMessage->created_at->diffForHumans() }}
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            <div class="d-flex flex-column gap-1">
                                <div><x-ui.status-chip :tone="$statusTone">{{ $conv->statusLabelFor(auth()->user()) }}</x-ui.status-chip></div>
                                <div><x-ui.status-chip :tone="$slaTone">{{ $sla['label'] }}</x-ui.status-chip></div>
                            </div>
                        </td>
                        <td class="text-end">
                            <x-ui.button
                                :href="route('supplier.conversations.show', $conv)"
                                variant="outline"
                                size="sm"
                                class="tw-relative"
                                data-open-chat-conversation="{{ $conv->getRouteKey() }}"
                            >
                                <x-slot:leading><x-ui.icon name="message-square" /></x-slot:leading>
                                {{ __('supplier.audit_ui.open_chat') }}
                                @if($unreadCount > 0)
                                    <x-slot:trailing><span class="ui-status-chip ui-status-chip--error ui-tabular-nums">{{ $unreadCount }}</span></x-slot:trailing>
                                @endif
                            </x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"><x-ui.empty-state icon="message-square-more" :title="__('supplier.copy.no_negotiations_yet')" :description="__('supplier.copy.negotiation_channels_will_appear_when_a_purchasing_conversation_is_opened')" /></td>
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

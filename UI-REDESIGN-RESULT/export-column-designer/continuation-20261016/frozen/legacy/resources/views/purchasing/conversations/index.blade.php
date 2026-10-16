@extends('layouts.app')
@section('title', __('purchasing.copy.negotiation_chat_adasi_portal'))
@section('page-title', __('purchasing.copy.negotiations_with_suppliers'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="__('purchasing.copy.negotiations_with_suppliers')"
        :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.follow_supplier_conversations_in_their_pr_or_po_context_and_prioritize_threads_that_need_a_response')"
    />

    <x-ui.data-table
        :title="__('purchasing.copy.supplier_conversations')"
        :description="__('purchasing.copy.search_the_current_page_by_document_supplier_message_or_status')"
    >
        <table class="table table-hover align-middle datatable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('purchasing.copy.document_context') }}</th>
                    <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                    <th scope="col">{{ __('purchasing.copy.latest_message') }}</th>
                    <th scope="col">{{ __('purchasing.copy.last_activity') }}</th>
                    <th scope="col">{{ __('purchasing.copy.status') }}</th>
                    <th scope="col" class="text-end">{{ __('purchasing.copy.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($conversations as $conv)
                    @php
                        $sla = \App\Support\ConversationPresenter::slaMeta($conv, auth()->user());
                        $statusClass = $conv->statusBadgeClassFor(auth()->user());
                        $statusTone = str_contains($statusClass, 'success') ? 'success' : (str_contains($statusClass, 'warning') ? 'warning' : (str_contains($statusClass, 'danger') ? 'error' : 'neutral'));
                        $slaTone = str_contains($sla['class'], 'success') ? 'success' : (str_contains($sla['class'], 'warning') ? 'warning' : (str_contains($sla['class'], 'danger') ? 'error' : 'neutral'));
                    @endphp
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
                                    <x-ui.icon name="reply" class="tw-me-1 tw-text-on-surface-variant" aria-label="{{ __('purchasing.copy.your_reply') }}" />
                                @endif
                                {{ Str::limit($conv->latestMessage->body, 50) }}
                            @else
                                <span class="tw-text-on-surface-variant">{{ __('purchasing.copy.no_messages_yet') }}</span>
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
                                <x-ui.status-chip :tone="$statusTone">{{ $conv->statusLabelFor(auth()->user()) }}</x-ui.status-chip>
                                <x-ui.status-chip :tone="$slaTone">{{ $sla['label'] }}</x-ui.status-chip>
                            </div>
                        </td>
                        <td class="text-end">
                            @php $unreadCount = $conv->unreadCountFor(auth()->id()); @endphp
                            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.conversations.show', $conv)" variant="outline" size="sm" class="tw-relative">
                                <x-slot:leading><x-ui.icon name="message-square-text" /></x-slot:leading>
                                {{ __('purchasing.audit_ui.open_chat') }}
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
                            <x-ui.empty-state icon="message-square-more" :title="__('purchasing.copy.no_conversations_yet')" :description="__('purchasing.copy.negotiation_threads_will_appear_after_a_supplier_conversation_is_started')" />
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

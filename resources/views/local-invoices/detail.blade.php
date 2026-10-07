@php
    $totalAmount = (float) $invoice->invoice_amount + (float) $invoice->tax_amount;
    $companyName = $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name;
    $currentRevision = $invoice->revisions->where('revision_number', $invoice->revision_number)->first() ?? $invoice->revisions->first();
    $remDays = $invoice->remainingDays();
    $payment = $invoice->payment;
    $isUnderpaid = $payment && $payment->status === \App\Models\LocalInvoicePayment::STATUS_CORRECTION_REQUIRED;
    $overpaymentRefund = $payment?->overpayment ?? $invoice->overpaymentRefund;
    $isOverpaid = $overpaymentRefund && $overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_OPEN;
    $isSettledOverpaid = $overpaymentRefund && $overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED;
@endphp

@if($errors->any())
    <div class="alert alert-danger tw-mb-6" role="alert">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold">
            <x-ui.icon name="alert-circle" size="sm" />
            <span>{{ $errors->first() }}</span>
        </div>
    </div>
@endif

<div class="tw-grid tw-grid-cols-1 tw-gap-6 tw-pb-16">
    {{-- Top Page Header --}}
    <x-ui.page-header
        :title="__('terms.invoice').' '.$invoice->invoice_number"
        :description="__('local_invoice.detail.reference', ['number' => $invoice->submission_number, 'po' => $invoice->po_number])"
        :eyebrow="__('local_invoice.detail.vendor', ['name' => $companyName])"
    >
        <x-slot:meta>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($invoice->status)">
                {{ \App\Support\StatusHelper::localInvoiceLabel($invoice->status) }}
            </x-ui.status-chip>
            @if($invoice->physical_verified_at)
                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-ui-xs tw-bg-success/10 tw-text-success tw-font-medium">
                    <x-ui.icon name="check-circle" size="sm" /> {{ __('local_invoice.labels.verified_physical') }}
                </span>
            @else
                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-ui-xs tw-bg-surface-container tw-text-on-surface-variant">
                    <x-ui.icon name="clock" size="sm" /> {{ __('local_invoice.detail.await_physical') }}
                </span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            {{-- Quick action for Supplier on Revision --}}
            @if($invoice->status === 'NEED_REVISION' && auth()->user()->isSupplier())
                @can('resubmit', $invoice)
                    <x-ui.button :href="route('local-supplier.invoices.revision', $invoice)" variant="primary">
                        <x-ui.icon name="file-edit" size="sm" />
                        <span>{{ __('local_invoice.detail.resubmit') }}</span>
                    </x-ui.button>
                @endcan
            @endif

            <x-ui.button :href="route($portal.'.invoices.receipt', $invoice)" variant="outline">
                <x-ui.icon name="receipt" size="sm" />
                <span>{{ __('local_invoice.detail.receipt') }}</span>
            </x-ui.button>

            @can('cancel', $invoice)
                <form method="POST" action="{{ route('local-supplier.invoices.cancel', $invoice) }}" class="tw-inline" onsubmit="event.preventDefault(); window.AdasiAlert.confirmDanger({title: 'Batalkan Invoice?', text: 'Batalkan invoice ini dan lepaskan seluruh reservasi GR?', confirmText: 'Ya, Batalkan', cancelText: 'Batal'}).then(r => { if (r.isConfirmed) this.submit(); });">
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm"><x-ui.icon name="x-circle" size="sm" /> {{ __('local_invoice.detail.cancel') }}</x-ui.button>
                </form>
            @endcan

            <x-ui.button :href="route($portal.'.invoices.index')" variant="ghost">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.actions.back') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Supplier Need Revision Alert Banner --}}
    @if($invoice->status === 'NEED_REVISION')
        @php
            $revisionReason = $invoice->statusHistories->where('event', 'revision_requested')->last()?->notes;
        @endphp
        <div class="tw-rounded-ui-md tw-border tw-border-warning-container-foreground/30 tw-bg-warning-container/20 tw-p-4">
            <div class="tw-flex tw-items-start tw-gap-3">
                <div class="tw-w-8 tw-h-8 tw-rounded-full tw-bg-warning-container tw-text-warning-container-foreground tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="alert-triangle" size="sm" />
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <h4 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('finance.labels.invoice_pending') }}</h4>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                        <strong>{{ __('local_invoice.detail.accounting_notes') }}</strong> {{ $revisionReason ?: __('local_invoice.detail.revision_hint') }}
                    </p>
                </div>
                @if(auth()->user()->isSupplier())
                    @can('resubmit', $invoice)
                        <div class="tw-shrink-0">
                            <x-ui.button :href="route('local-supplier.invoices.revision', $invoice)" variant="primary" size="sm">
                                <x-ui.icon name="file-edit" size="sm" /> {{ __('local_invoice.detail.correct_now') }}
                            </x-ui.button>
                        </div>
                    @endcan
                @endif
            </div>
        </div>
    @endif

    {{-- Underpayment Alert Banner --}}
    @if($isUnderpaid)
        @php
            $expectedNet = (float) $payment->expected_amount;
            $alreadyPaid = (float) $payment->actual_paid_total;
            $remainingPayable = max(0.0, $expectedNet - $alreadyPaid);
            $latestTransfer = $payment->transfers->sortByDesc('sequence_no')->first();
        @endphp
        <div class="tw-rounded-ui-md tw-border tw-border-warning-container-foreground/30 tw-bg-warning-container/20 tw-p-4">
            <div class="tw-flex tw-items-start tw-gap-3">
                <div class="tw-w-8 tw-h-8 tw-rounded-full tw-bg-warning-container tw-text-warning-container-foreground tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="clock" size="sm" />
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <h4 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('local_invoice.detail.underpaid_title') }}</h4>
                        <span class="tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-bold tw-bg-warning/20 tw-text-warning-container-foreground">
                            {{ __('local_invoice.detail.remaining_settlement') }}
                        </span>
                    </div>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('local_invoice.detail.underpaid_help') }}
                    </p>
                    <div class="tw-mt-3 tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-2 tw-p-2.5 tw-rounded tw-bg-surface tw-border tw-border-outline-variant tw-text-ui-xs tw-font-mono">
                        <div>
                            <span class="tw-text-on-surface-variant tw-block tw-text-[10px] tw-uppercase tw-font-sans tw-font-semibold">{{ __('local_invoice.detail.net_amount') }}</span>
                            <span class="tw-font-bold tw-text-on-surface">Rp {{ $regionalFormatter->number(number_format($expectedNet, 0, ',', '.'), 'indonesian') }}</span>
                        </div>
                        <div>
                            <span class="tw-text-success tw-block tw-text-[10px] tw-uppercase tw-font-sans tw-font-semibold">{{ __('local_invoice.labels.transferred') }}:</span>
                            <span class="tw-font-bold tw-text-success">Rp {{ $regionalFormatter->number(number_format($alreadyPaid, 0, ',', '.'), 'indonesian') }}</span>
                        </div>
                        <div>
                            <span class="tw-text-warning-container-foreground tw-block tw-text-[10px] tw-uppercase tw-font-sans tw-font-semibold">{{ __('local_invoice.labels.remaining_unpaid') }}:</span>
                            <span class="tw-font-bold tw-text-warning-container-foreground">Rp {{ $regionalFormatter->number(number_format($remainingPayable, 0, ',', '.'), 'indonesian') }}</span>
                        </div>
                    </div>
                    @if($latestTransfer?->correction_reason)
                        <div class="tw-mt-2 tw-text-ui-xs tw-text-on-surface-variant">
                            <strong>{{ __('local_invoice.detail.deduction_reason') }}</strong> {{ $latestTransfer->correction_reason }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Overpayment Alert Banner --}}
    @if($isOverpaid)
        @php
            $overAmount = (float) $overpaymentRefund->overpayment_amount;
            $expectedNet = $payment ? (float) $payment->expected_amount : (float) ($invoice->invoice_amount + $invoice->tax_amount);
            $alreadyPaid = $payment ? (float) $payment->actual_paid_total : ($expectedNet + $overAmount);
            $adasiBank = config('finance.adasi_refund_account.bank_name', 'Bank Central Asia (BCA)');
            $adasiAccNo = config('finance.adasi_refund_account.account_number', '123-456-7890');
            $adasiAccHolder = config('finance.adasi_refund_account.account_holder', 'PT Astra Daido Steel Indonesia');
            $adasiFinanceContact = config('finance.adasi_refund_account.finance_contact', 'finance@adasi.co.id');
            $adasiFinanceEmail = config('finance.adasi_refund_account.finance_email');
            if (empty($adasiFinanceEmail) && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', (string) $adasiFinanceContact, $emailMatches)) {
                $adasiFinanceEmail = $emailMatches[0];
            }
            $adasiFinanceWa = config('finance.adasi_refund_account.finance_wa');
        @endphp
        <div class="tw-rounded-ui-md tw-border tw-border-amber-500/30 tw-bg-amber-500/5 tw-p-5">
            <div class="tw-flex tw-items-start tw-gap-3.5">
                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-amber-500/10 tw-text-amber-600 dark:tw-text-amber-400 tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="alert-triangle" size="sm" />
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                        <h4 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">{{ __('local_invoice.detail.overpayment_title') }}</h4>
                        <span class="tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300">
                            {{ __('local_invoice.detail.refund_required') }}
                        </span>
                    </div>
                    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('local_invoice.detail.overpayment_help', ['amount' => $regionalFormatter->number(number_format($overAmount, 0, ',', '.'), 'indonesian')]) }}
                    </p>

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-3 tw-mt-3 tw-p-3 tw-bg-surface tw-rounded-ui-sm tw-border tw-border-outline-variant/50">
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.invoice_amount') }}</span>
                            <span class="tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-on-surface">Rp {{ $regionalFormatter->number(number_format($expectedNet, 0, ',', '.'), 'indonesian') }}</span>
                        </div>
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ __('finance.drp.total_transferred') }}</span>
                            <span class="tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-on-surface">Rp {{ $regionalFormatter->number(number_format($alreadyPaid, 0, ',', '.'), 'indonesian') }}</span>
                        </div>
                        <div>
                            <span class="tw-text-[11px] tw-text-amber-700 dark:tw-text-amber-400 tw-block tw-font-medium">{{ __('local_invoice.detail.overpayment_amount') }}</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-amber-700 dark:tw-text-amber-400">Rp {{ $regionalFormatter->number(number_format($overAmount, 0, ',', '.'), 'indonesian') }}</span>
                        </div>
                    </div>

                    <div class="tw-mt-4 tw-p-4 tw-rounded-ui-sm tw-bg-surface-container-low tw-border tw-border-outline-variant/60">
                        <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-2">
                            <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-flex tw-items-center tw-gap-1.5">
                                <x-ui.icon name="building-2" size="xs" /> {{ __('local_invoice.labels.refund_account') }}
                            </span>
                            @if($adasiAccNo)
                                <button type="button"
                                    class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-ui-sm tw-text-ui-xs tw-font-semibold tw-bg-primary/10 tw-text-primary hover:tw-bg-primary/20 tw-transition-colors"
                                    data-copy-text="{{ $adasiAccNo }}"
                                    title="{{ __('local_invoice.detail.copy_account') }}">
                                    <x-ui.icon name="copy" size="xs" />
                                    <span>{{ __('local_invoice.detail.copy_account') }}</span>
                                </button>
                            @endif
                        </div>
                        <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-3 tw-text-ui-xs">
                            <div>
                                <span class="tw-text-on-surface-variant tw-block">{{ __('common.labels_review.bank') }}</span>
                                <span class="tw-font-semibold tw-text-on-surface">{{ $adasiBank }}</span>
                            </div>
                            <div>
                                <span class="tw-text-on-surface-variant tw-block">{{ __('ga.detail.bank_number') }}</span>
                                <span class="tw-font-mono tw-font-bold tw-text-on-surface">{{ $adasiAccNo }}</span>
                            </div>
                            <div>
                                <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.account_name') }}</span>
                                <span class="tw-font-semibold tw-text-on-surface">{{ $adasiAccHolder }}</span>
                            </div>
                            <div>
                                <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.finance_contact') }}</span>
                                @if(!empty($adasiFinanceEmail) || !empty($adasiFinanceWa))
                                    <div class="tw-mt-1.5 tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                                        @if(!empty($adasiFinanceEmail))
                                            @php
                                                $mailSubject = __('local_invoice.refund_contact.subject', ['invoice' => $invoice->invoice_number ?? '', 'company' => $companyName]);
                                                $mailBody = __('local_invoice.refund_contact.body', [
                                                    'company' => $companyName,
                                                    'invoice' => $invoice->invoice_number ?? '-',
                                                    'submission' => $invoice->submission_number ?? '-',
                                                    'po' => $invoice->po_number ?? '-',
                                                    'expected' => number_format($expectedNet, 0, ',', '.'),
                                                    'actual' => number_format($alreadyPaid, 0, ',', '.'),
                                                    'overpayment' => number_format($overAmount, 0, ',', '.'),
                                                    'bank' => $adasiBank, 'account' => $adasiAccNo, 'holder' => $adasiAccHolder,
                                                ]);
                                                $fullDraftText = __('local_invoice.refund_contact.draft', ['subject' => $mailSubject, 'body' => $mailBody]);
                                                $mailUrl = 'mailto:' . $adasiFinanceEmail . '?subject=' . rawurlencode($mailSubject) . '&body=' . rawurlencode($mailBody);
                                                $gmailUrl = 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($adasiFinanceEmail) . '&su=' . rawurlencode($mailSubject) . '&body=' . rawurlencode($mailBody);
                                                $outlookUrl = 'https://outlook.office.com/mail/deeplink/compose?to=' . rawurlencode($adasiFinanceEmail) . '&subject=' . rawurlencode($mailSubject) . '&body=' . rawurlencode($mailBody);
                                            @endphp
                                            <textarea id="email-draft-text-{{ $invoice->id }}" class="d-none" aria-hidden="true">{{ $fullDraftText }}</textarea>
                                            <div class="dropdown tw-inline-block">
                                                <button type="button"
                                                    class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2 tw-py-1 tw-rounded-ui-sm tw-text-ui-xs tw-font-semibold tw-bg-primary/10 hover:tw-bg-primary/20 tw-text-primary dark:tw-text-primary-light tw-transition-colors"
                                                    data-bs-toggle="dropdown"
                                                    aria-expanded="false"
                                                    title="{{ __('local_invoice.refund_contact.email_options', ['email' => $adasiFinanceEmail]) }}">
                                                    <x-ui.icon name="mail" size="sm" class="tw-w-4 tw-h-4 tw-shrink-0" />
                                                    <span>{{ __('common.fields.email') }}</span>
                                                    <x-ui.icon name="chevron-down" size="xs" class="tw-w-3 tw-h-3 tw-shrink-0 tw-opacity-70" />
                                                </button>
                                                <ul class="dropdown-menu shadow-sm tw-text-ui-xs tw-border tw-border-outline-variant tw-rounded-ui-md tw-py-1 tw-min-w-[220px] z-[1060]">
                                                    <li>
                                                        <a class="dropdown-item tw-flex tw-items-center tw-gap-2 tw-py-1.5 tw-px-3 tw-text-on-surface hover:tw-bg-surface-container"
                                                            href="{{ $gmailUrl }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer">
                                                            <x-ui.icon name="external-link" size="xs" class="tw-text-primary tw-shrink-0" />
                                                            <span>{{ __('local_invoice.refund_contact.gmail') }}</span>
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item tw-flex tw-items-center tw-gap-2 tw-py-1.5 tw-px-3 tw-text-on-surface hover:tw-bg-surface-container"
                                                            href="{{ $outlookUrl }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer">
                                                            <x-ui.icon name="external-link" size="xs" class="tw-text-primary tw-shrink-0" />
                                                            <span>{{ __('local_invoice.refund_contact.outlook') }}</span>
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item tw-flex tw-items-center tw-gap-2 tw-py-1.5 tw-px-3 tw-text-on-surface hover:tw-bg-surface-container"
                                                            href="{{ $mailUrl }}">
                                                            <x-ui.icon name="mail" size="xs" class="tw-text-primary tw-shrink-0" />
                                                            <span>{{ __('local_invoice.refund_contact.email_app') }}</span>
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider my-1 tw-border-outline-variant/60"></li>
                                                    <li>
                                                        <button type="button"
                                                            class="dropdown-item tw-flex tw-items-center tw-gap-2 tw-py-1.5 tw-px-3 tw-text-on-surface hover:tw-bg-surface-container tw-w-full tw-text-left"
                                                            data-copy-target="#email-draft-text-{{ $invoice->id }}"
                                                            data-copy-text="{{ $fullDraftText }}"
                                                            data-copy-msg="{{ __('local_invoice.refund_contact.draft_copied') }}">
                                                            <x-ui.icon name="file-text" size="xs" class="tw-text-primary tw-shrink-0" />
                                                            <span>{{ __('local_invoice.refund_contact.copy_draft') }}</span>
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button"
                                                            class="dropdown-item tw-flex tw-items-center tw-gap-2 tw-py-1.5 tw-px-3 tw-text-on-surface hover:tw-bg-surface-container tw-w-full tw-text-left"
                                                            data-copy-text="{{ $adasiFinanceEmail }}"
                                                            data-copy-msg="{{ __('local_invoice.refund_contact.email_copied', ['email' => $adasiFinanceEmail]) }}">
                                                            <x-ui.icon name="copy" size="xs" class="tw-text-primary tw-shrink-0" />
                                                            <span>{{ __('local_invoice.refund_contact.copy_email') }}</span>
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        @endif
                                        @if(!empty($adasiFinanceWa))
                                            @php
                                                $cleanWa = preg_replace('/[^0-9]/', '', (string) $adasiFinanceWa);
                                                if (str_starts_with($cleanWa, '0')) {
                                                    $cleanWa = '62' . substr($cleanWa, 1);
                                                }
                                                $waText = __('local_invoice.refund_contact.whatsapp_body', [
                                                    'company' => $companyName, 'invoice' => $invoice->invoice_number ?? '-',
                                                    'po' => $invoice->po_number ?? '-', 'amount' => number_format($overAmount, 0, ',', '.'),
                                                    'bank' => $adasiBank, 'account' => $adasiAccNo, 'holder' => $adasiAccHolder,
                                                ]);
                                                $waUrl = 'https://wa.me/' . $cleanWa . '?text=' . rawurlencode($waText);
                                            @endphp
                                            <a href="{{ $waUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2 tw-py-1 tw-rounded-ui-sm tw-text-ui-xs tw-font-semibold tw-bg-emerald-500/10 hover:tw-bg-emerald-500/20 tw-text-emerald-700 dark:tw-text-emerald-400 dark:hover:tw-text-emerald-300 hover:tw-underline tw-transition-colors"
                                                title="{{ __('local_invoice.refund_contact.whatsapp') }}">
                                                <x-ui.icon name="whatsapp" size="sm" class="tw-w-4 tw-h-4 tw-shrink-0" />
                                                <span>WhatsApp</span>
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <p class="tw-m-0 tw-mt-3 tw-text-[11px] tw-text-on-surface-variant">
                        <strong>{{ __('local_invoice.detail.instructions') }}</strong> {{ __('local_invoice.labels.refund_help') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if($isSettledOverpaid)
        @php
            $proof = $overpaymentRefund->attachments->first();
        @endphp
        <div class="tw-rounded-ui-md tw-border tw-border-emerald-500/30 tw-bg-emerald-500/5 tw-p-5">
            <div class="tw-flex tw-items-start tw-gap-3.5">
                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-emerald-500/10 tw-text-emerald-600 dark:tw-text-emerald-400 tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="check-circle" size="sm" />
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                        <h4 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">{{ __('local_invoice.detail.refund_settlement') }}</h4>
                        <span class="tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300">
                            {{ __('local_invoice.detail.verified_settlement') }}
                        </span>
                    </div>
                    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('local_invoice.detail.refund_settled_help') }}
                    </p>

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-3 tw-mt-3 tw-p-3.5 tw-bg-surface tw-rounded-ui-sm tw-border tw-border-outline-variant/50 tw-text-ui-xs">
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.returned_amount') }}</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-emerald-700 dark:tw-text-emerald-400">
                                Rp {{ $regionalFormatter->number(number_format((float) ($overpaymentRefund->refund_amount ?? $overpaymentRefund->overpayment_amount), 0, ',', '.'), 'indonesian') }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('finance.refund.return_date') }}</span>
                            <span class="tw-font-semibold tw-text-on-surface">
                                {{ $overpaymentRefund->refund_date ? $regionalFormatter->fixedDate($overpaymentRefund->refund_date, 'd M Y') : ($overpaymentRefund->settled_at ? $regionalFormatter->businessTime($overpaymentRefund->settled_at, 'd M Y') : '-') }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.refund_reference') }}</span>
                            <span class="tw-font-mono tw-font-semibold tw-text-on-surface">
                                {{ $overpaymentRefund->refund_reference ?: '-' }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.finance_notes') }}</span>
                            <span class="tw-text-on-surface">
                                {{ $overpaymentRefund->notes ?: '-' }}
                            </span>
                        </div>
                    </div>

                    @if($proof)
                        <div class="tw-mt-3 tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-bg-surface-container-low tw-border tw-border-outline-variant/60">
                            <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0">
                                <x-ui.icon name="file-text" size="xs" class="tw-text-primary tw-shrink-0" />
                                <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface tw-truncate" title="{{ $proof->file_name }}">
                                    {{ __('local_invoice.detail.settlement_proof', ['filename' => $proof->file_name]) }}
                                </span>
                            </div>
                            <a href="{{ route('attachments.show', $proof) }}"
                                target="_blank"
                                class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-ui-sm tw-text-ui-xs tw-font-semibold tw-bg-primary/10 tw-text-primary hover:tw-bg-primary/20 tw-transition-colors tw-shrink-0">
                                <x-ui.icon name="download" size="xs" />
                                <span>{{ __('local_invoice.actions.download_proof') }}</span>
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- 2-Column Grid Layout --}}
    <div class="tw-grid tw-grid-cols-1 tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        {{-- Left Column: Main Detail & Documents --}}
        <div class="tw-min-w-0 lg:tw-col-span-8 tw-space-y-6">
            {{-- Financial & Reference Information --}}
            <x-ui.card :title="__('local_invoice.labels.payment_summary')">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_invoice.receipt.invoice_number') }}</span>
                        <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_number }}</span>
                    </div>
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.invoice_date') }}</span>
                        <span class="tw-font-medium tw-text-ui-sm tw-text-on-surface">{{ $regionalFormatter->date($invoice->invoice_date, 'human') }}</span>
                    </div>
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_invoice.receipt.po_number') }}</span>
                        <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $invoice->po_number }}</span>
                    </div>
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_procurement.labels.vendor') }}</span>
                        <span class="tw-font-medium tw-text-ui-sm tw-text-on-surface">{{ $companyName }}</span>
                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $invoice->supplier->email }}</span>
                    </div>
                    @if($invoice->tax_invoice_number)
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_invoice.detail.tax_number') }}</span>
                            <span class="tw-font-semibold tw-font-mono tw-text-ui-sm tw-text-primary">{{ $invoice->tax_invoice_number }}</span>
                        </div>
                    @endif
                </div>

                <div class="tw-border-t tw-border-outline-variant tw-mt-4 tw-pt-4">
                    <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3 tw-p-3 tw-rounded-ui-sm tw-bg-surface-container-low">
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-uppercase tw-font-semibold tw-tracking-wider">{{ __('local_invoice.detail.dpp') }}</span>
                            <span class="tw-block tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-on-surface">
                                Rp {{ $regionalFormatter->number(number_format((float) $invoice->invoice_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-font-mono tw-block" title="{{ __('local_invoice.detail.original_amount') }}">
                                IDR {{ $invoice->invoice_amount }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-uppercase tw-font-semibold tw-tracking-wider">PPN</span>
                            <span class="tw-block tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-on-surface">
                                Rp {{ $regionalFormatter->number(number_format((float) $invoice->tax_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-font-mono tw-block" title="{{ __('local_invoice.detail.original_amount') }}">
                                IDR {{ $invoice->tax_amount }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-[11px] tw-text-primary tw-uppercase tw-font-semibold tw-tracking-wider">{{ __('finance.labels.payment_total') }}</span>
                            <span class="tw-block tw-font-mono tw-font-bold tw-text-ui-base tw-text-primary">
                                Rp {{ $regionalFormatter->number(number_format((float) $totalAmount, 0, ',', '.'), 'indonesian') }}
                            </span>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            @if($invoice->localPurchaseOrder)
                <x-ui.card :title="__('local_invoice.detail.po_gr')" :description="__('local_invoice.form.po_reference_help')">
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs tw-mb-4">
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('local_invoice.receipt.po_number') }}</span><strong class="tw-font-mono tw-text-on-surface">{{ $invoice->localPurchaseOrder->po_number }}</strong></div>
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.labels.po_date') }}</span><strong class="tw-text-on-surface">{{ $invoice->localPurchaseOrder->po_date ? $regionalFormatter->date($invoice->localPurchaseOrder->po_date, 'human') : '—' }}</strong></div>
                        <div><span class="tw-text-on-surface-variant tw-block">{{ __('local_procurement.labels.po_total') }}</span><strong class="tw-font-mono tw-text-on-surface">Rp {{ $regionalFormatter->number(number_format($invoice->localPurchaseOrder->total_amount, 2, ',', '.'), 'indonesian') }}</strong></div>
                    </div>
                    <div class="table-responsive"><table class="table table-sm align-middle tw-m-0 tw-text-ui-xs"><thead><tr><th>{{ __('local_invoice.detail.gr_number') }}</th><th>{{ __('local_procurement.labels.gr_date') }}</th><th class="text-end">{{ __('common.fields.qty') }}</th><th>{{ __('local_invoice.labels.status') }}</th></tr></thead><tbody>
                        @forelse($invoice->goodsReceiptHistories->sortBy('id') as $history)
                            <tr><td class="tw-font-mono">{{ $history->gr_number_snapshot }}</td><td>{{ $history->goodsReceipt?->gr_date ? $regionalFormatter->date($history->goodsReceipt->gr_date, 'human') : '—' }}</td><td class="text-end tw-font-mono">{{ $history->gr_qty_snapshot !== null ? rtrim(rtrim(number_format((float) $history->gr_qty_snapshot, 4, ',', '.'), '0'), ',') : ($history->goodsReceipt?->qty !== null ? rtrim(rtrim(number_format((float) $history->goodsReceipt->qty, 4, ',', '.'), '0'), ',') : '—') }}</td><td><x-ui.status-chip :tone="$history->state === 'RELEASED' ? 'neutral' : ($history->state === 'CONSUMED' ? 'success' : 'warning')">{{ \App\Support\StatusHelper::localFinanceLabel($history->state) }}</x-ui.status-chip></td></tr>
                        @empty
                            <tr><td colspan="4" class="tw-text-center tw-text-on-surface-variant">{{ __('local_procurement.empty.gr_history') }}</td></tr>
                        @endforelse
                    </tbody></table></div>
                </x-ui.card>
            @endif

            {{-- Riwayat Pembayaran & Mutasi Transfer Bank --}}
            @if($payment && $payment->transfers->isNotEmpty())
                <x-ui.card
                    :title="__('local_invoice.labels.payment_history')"
                    :description="__('local_invoice.labels.bank_transfers_help')"
                >
                    <div class="table-responsive">
                        <table class="table table-sm align-middle tw-m-0 tw-text-ui-xs">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ __('finance.payment.transfer_date') }}</th>
                                    <th>{{ __('local_invoice.labels.transfer_type') }}</th>
                                    <th>{{ __('local_invoice.detail.bank_reference') }}</th>
                                    <th class="text-end">{{ __('local_invoice.detail.received_amount') }}</th>
                                    <th>{{ __('local_invoice.detail.remarks') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($payment->transfers->sortBy('sequence_no') as $transfer)
                                    <tr>
                                        <td>{{ $transfer->sequence_no }}</td>
                                        <td class="tw-font-medium">{{ $transfer->transfer_date ? $regionalFormatter->date($transfer->transfer_date, 'human') : '—' }}</td>
                                        <td>
                                            @if($transfer->transfer_type === 'primary')
                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                                    {{ __('local_invoice.labels.primary_transfer') }}
                                                </span>
                                            @else
                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-warning/10 tw-text-warning-container-foreground">
                                                    {{ __('local_invoice.labels.correction_transfer') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="tw-font-mono tw-font-semibold">{{ $transfer->transfer_reference }}</td>
                                        <td class="text-end tw-font-mono tw-font-bold tw-text-success">
                                            Rp {{ $regionalFormatter->number(number_format((float) $transfer->amount, 0, ',', '.'), 'indonesian') }}
                                        </td>
                                        <td class="tw-text-on-surface-variant">
                                            {{ $transfer->correction_reason ?: ($transfer->notes ?: '—') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="tw-border-t tw-border-outline-variant tw-bg-surface-container-low">
                                    <th colspan="4" class="tw-font-bold">{{ __('local_invoice.labels.total_received') }}:</th>
                                    <th class="text-end tw-font-mono tw-font-bold tw-text-ui-sm tw-text-success">
                                        Rp {{ $regionalFormatter->number(number_format((float) $payment->actual_paid_total, 0, ',', '.'), 'indonesian') }}
                                    </th>
                                    <th>
                                        @if($payment->status === 'FINALIZED')
                                            <span class="tw-text-success tw-font-semibold">{{ __('terms.paid') }}</span>
                                        @elseif($payment->status === 'CORRECTION_REQUIRED')
                                            <span class="tw-text-warning-container-foreground tw-font-semibold">
                                                {{ __('local_invoice.detail.remaining_amount', ['amount' => $regionalFormatter->number(number_format(max(0.0, (float) $payment->expected_amount - (float) $payment->actual_paid_total), 0, ',', '.'), 'indonesian')]) }}
                                            </span>
                                        @endif
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </x-ui.card>
            @endif

            {{-- Document Attachments (Current Revision) --}}
            <x-ui.card
                :title="__('local_invoice.detail.revision_documents', ['number' => $invoice->revision_number])"
                :description="__('common.final_review.digital_docs')"
            >
                @include('local-invoices.partials._documents_grid', [
                    'documents' => $currentRevision ? $currentRevision->documents : collect()
                ])
            </x-ui.card>

            {{-- Revision History (if multiple revisions exist) --}}
            @if($invoice->revisions->count() > 1)
                <x-ui.card :title="__('local_invoice.labels.revision_history')">
                    <div class="tw-space-y-3">
                        @foreach($invoice->revisions->sortByDesc('revision_number') as $rev)
                            <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 {{ $rev->revision_number === $invoice->revision_number ? 'tw-bg-surface-container-low' : 'tw-bg-surface' }}">
                                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-2">
                                    <div class="tw-flex tw-items-center tw-gap-2">
                                        <span class="tw-font-bold tw-text-ui-xs tw-text-on-surface">{{ __('local_invoice.detail.revision', ['number' => $rev->revision_number]) }}</span>
                                        @if($rev->revision_number === $invoice->revision_number)
                                            <span class="tw-px-2 tw-py-0.2 tw-rounded-full tw-text-[10px] tw-font-semibold tw-bg-primary/10 tw-text-primary">{{ __('local_invoice.labels.active') }}</span>
                                        @endif
                                    </div>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $regionalFormatter->timestamp($rev->created_at, 'datetime_comma') }}</span>
                                </div>
                                @if($rev->reason)
                                    <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-2">
                                        <em>{{ __('local_invoice.labels.revision_reason') }}:</em> {{ $rev->reason }}
                                    </div>
                                @endif
                                <div class="tw-flex tw-flex-wrap tw-gap-2">
                                    @foreach($rev->documents as $revDoc)
                                        <a href="{{ route('local-invoice-documents.show', $revDoc) }}" target="_blank" class="tw-text-ui-xs tw-text-primary tw-underline hover:tw-text-primary/80">
                                            {{ $docTypeLabels[$revDoc->document_type] ?? __('local_invoice.documents.types.'.$revDoc->document_type) }}
                                        </a>
                                        @if(! $loop->last) <span class="tw-text-on-surface-variant">·</span> @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>

        {{-- Right Column: Sticky Workflow Actions, Status Stepper & Information (Offset accounts for 56px navbar) --}}
        <div class="lg:tw-col-span-4 tw-space-y-6 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem);">
            {{-- Payment & Due Date Card (Internal only, hidden from Supplier) --}}
            @if(!auth()->user()?->isSupplier() && ($portal ?? '') !== 'local-supplier')
            <x-ui.card :title="__('local_invoice.labels.payment_schedule')">
                <div class="tw-space-y-3">
                    <div class="tw-flex tw-justify-between tw-text-ui-xs">
                        <span class="tw-text-on-surface-variant">{{ __('local_invoice.detail.payment_term') }}</span>
                        <span class="tw-font-semibold tw-text-on-surface">{{ trans_choice('local_invoice.table.term_days', $invoice->payment_term_days_snapshot ?? 0) }}</span>
                    </div>

                    <div class="tw-flex tw-justify-between tw-items-center tw-text-ui-xs">
                        <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.due_date') }}:</span>
                        <span class="tw-font-semibold tw-text-on-surface">{{ $invoice->due_date ? $regionalFormatter->date($invoice->due_date, 'human') : '—' }}</span>
                    </div>

                    @if($invoice->due_date && $remDays !== null)
                        <div class="tw-p-2.5 tw-rounded-ui-sm tw-text-center {{ $remDays < 0 ? 'tw-bg-error/10 tw-text-error' : ($remDays <= 3 ? 'tw-bg-warning-container tw-text-warning-container-foreground' : 'tw-bg-success/10 tw-text-success') }}">
                            <span class="tw-font-bold tw-text-ui-sm">
                                @if($remDays < 0)
                                    {{ trans_choice('local_invoice.table.overdue_days', abs($remDays)) }}
                                @elseif($remDays === 0)
                                    {{ __('local_invoice.detail.due_today') }}
                                @else
                                    {{ trans_choice('local_invoice.table.remaining_days', $remDays) }}
                                @endif
                            </span>
                        </div>
                    @endif

                    @if($invoice->scheduled_payment_date)
                        <div class="tw-flex tw-justify-between tw-text-ui-xs tw-border-t tw-border-outline-variant tw-pt-2">
                            <span class="tw-text-on-surface-variant">{{ __('local_invoice.detail.payment_schedule') }}</span>
                            <span class="tw-font-bold tw-text-primary">{{ $regionalFormatter->date($invoice->scheduled_payment_date, 'human') }}</span>
                        </div>
                    @endif

                    @if($invoice->completed_at)
                        <div class="tw-flex tw-justify-between tw-text-ui-xs tw-border-t tw-border-outline-variant tw-pt-2">
                            <span class="tw-text-on-surface-variant">{{ __('local_invoice.detail.payment_completed') }}</span>
                            <span class="tw-font-bold tw-text-success">{{ $regionalFormatter->timestamp($invoice->completed_at, 'datetime_comma') }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>
            @endif

            {{-- Operator Workflow Actions Box (Only for Accounting/Finance) --}}
            @if(auth()->user()->isLocalOperator())
                <x-ui.card
                    :title="__('finance.labels.workflow_actions')"
                    :description="__('local_invoice.detail.workflow_help')"
                >
                    @if(!empty($workflowActions))
                        <div class="tw-space-y-4">
                            @foreach($workflowActions as $action => $label)
                                @php
                                    $isDestructive = in_array($action, ['reject']);
                                    $isWarning = in_array($action, ['request-revision']);
                                    $isPositive = in_array($action, ['approve', 'physical-verification', 'complete-payment']);
                                    $btnVariant = $isDestructive ? 'danger' : ($isWarning ? 'warning' : 'primary');
                                @endphp

                                <div class="tw-p-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-low">
                                    <form
                                        method="POST"
                                        action="{{ route('accounting.invoices.'.$action, $invoice) }}"
                                        class="local-workflow-form"
                                        data-confirm="{{ $label }}?"
                                    >
                                        @csrf

                                        <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block tw-mb-2">
                                            {{ $label }}
                                        </span>

                                        @if(in_array($action, ['request-revision', 'reject', 'physical-verification', 'schedule-payment', 'complete-payment']))
                                            <div class="tw-mb-2">
                                                <label for="notes-{{ $action }}" class="form-label tw-text-[11px] tw-text-on-surface-variant">
                                                    {{ __(in_array($action, ['request-revision', 'reject']) ? 'local_invoice.closure.notes_required' : 'local_invoice.closure.notes_optional') }}
                                                    @if(in_array($action, ['request-revision', 'reject'])) <span class="text-danger">*</span> @endif
                                                </label>
                                                <textarea
                                                    class="form-control form-control-sm"
                                                    id="notes-{{ $action }}"
                                                    name="notes"
                                                    rows="2"
                                                    maxlength="5000"
                                                    placeholder="{{ __('ga.verification.notes') }}"
                                                    @required(in_array($action, ['request-revision', 'reject']))
                                                >{{ old('notes') }}</textarea>
                                            </div>
                                        @endif

                                        @if($action === 'schedule-payment')
                                            <div class="tw-mb-3">
                                                <label for="scheduled-payment-date" class="form-label tw-text-[11px] tw-text-on-surface-variant">
                                                    {{ __('common.final_review.payment_schedule_date') }} <span class="text-danger">*</span>
                                                </label>
                                                <x-ui.date-picker
                                                    name="scheduled_payment_date"
                                                    id="scheduled-payment-date"
                                                    :value="old('scheduled_payment_date', $invoice->due_date?->format('Y-m-d'))"
                                                    required
                                                />
                                            </div>
                                        @endif

                                        <x-ui.button
                                            type="submit"
                                            :variant="$btnVariant"
                                            size="sm"
                                            class="w-100 tw-shadow-sm"
                                        >
                                            @if($isPositive) <x-ui.icon name="check" size="sm" />
                                            @elseif($isWarning) <x-ui.icon name="file-edit" size="sm" />
                                            @elseif($isDestructive) <x-ui.icon name="x-circle" size="sm" />
                                            @else <x-ui.icon name="play" size="sm" /> @endif
                                            <span>{{ __('local_invoice.detail.confirm_action', ['action' => $label]) }}</span>
                                        </x-ui.button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="tw-text-center tw-py-4">
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">
                                {{ __('local_invoice.detail.no_actions') }}
                            </span>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Status & Verification Timeline Stepper (Balanced in Right Column) --}}
            <x-ui.card :title="__('local_invoice.labels.verification_history')">
                <div class="tw-relative tw-ps-6 tw-space-y-6 before:tw-absolute before:tw-left-2.5 before:tw-top-2 before:tw-bottom-2 before:tw-w-0.5 before:tw-bg-outline-variant">
                    @forelse($invoice->statusHistories as $history)
                        <div class="tw-relative">
                            <div class="tw-absolute -tw-left-6 tw-top-0.5 tw-w-5 tw-h-5 tw-rounded-full tw-bg-surface tw-border-2 tw-border-primary tw-flex tw-items-center tw-justify-center">
                                <div class="tw-w-1.5 tw-h-1.5 tw-rounded-full tw-bg-primary"></div>
                            </div>
                            <div>
                                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2">
                                    <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">
                                        {{ \App\Models\LocalInvoice::eventLabel($history->event) }}
                                    </span>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                        {{ $regionalFormatter->timestamp($history->created_at, 'datetime_comma') }}
                                    </span>
                                </div>
                                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                        {{ __('ga.detail.actor', ['name' => $history->actor->name]) }}
                                    @if($history->to_status)
                                        · {{ __('common.labels_review.status') }} <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($history->to_status)">
                                            {{ \App\Support\StatusHelper::localInvoiceLabel($history->to_status) }}
                                        </x-ui.status-chip>
                                    @endif
                                </div>
                                @if($history->notes)
                                    <div class="tw-mt-2 tw-p-2.5 tw-rounded tw-bg-surface-container tw-text-ui-xs tw-text-on-surface">
                                        {{ $history->notes }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_invoice.empty.activity') }}</div>
                    @endforelse
                </div>
            </x-ui.card>

            {{-- Physical Submission Guidelines Card --}}
            <x-ui.card :title="__('local_invoice.detail.physical_guidelines')">
                <div class="tw-space-y-3 tw-text-ui-xs tw-text-on-surface-variant">
                    <div class="tw-flex tw-gap-2.5 tw-items-start">
                        <x-ui.icon name="map-pin" size="sm" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                        <div>
                            <strong class="tw-text-on-surface tw-block">{{ __('local_invoice.detail.counter') }}</strong>
                            {{ __('local_invoice.detail.counter_address') }}
                        </div>
                    </div>
                    <div class="tw-flex tw-gap-2.5 tw-items-start">
                        <x-ui.icon name="file-badge" size="sm" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                        <div>
                            <strong class="tw-text-on-surface tw-block">{{ __('local_invoice.detail.stamp') }}</strong>
                            {{ __('local_invoice.detail.stamp_help') }}
                        </div>
                    </div>
                    <div class="tw-flex tw-gap-2.5 tw-items-start">
                        <x-ui.icon name="receipt" size="sm" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                        <div>
                            <strong class="tw-text-on-surface tw-block">{{ __('local_invoice.detail.receipt_proof') }}</strong>
                            {{ __('local_invoice.receipt.required') }}
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>

@include('local-invoices.scripts')

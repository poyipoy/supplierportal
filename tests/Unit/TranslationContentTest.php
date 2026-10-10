<?php

namespace Tests\Unit;

use App\Models\LocalInvoice;
use App\Support\JsTranslations;
use App\Support\StatusHelper;
use Illuminate\Support\Arr;
use Tests\TestCase;

class TranslationContentTest extends TestCase
{
    public function test_application_translation_values_remain_plain_text(): void
    {
        foreach (['en', 'id'] as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) as $path) {
                foreach (Arr::dot(require $path) as $key => $value) {
                    $this->assertIsString($value, basename($path).':'.$key);
                    $this->assertDoesNotMatchRegularExpression('/<\/?[a-z][^>]*>/i', $value, basename($path).':'.$key);
                    $this->assertDoesNotMatchRegularExpression('/(?:â€”|â€“|Ã—|Â·)/u', $value, basename($path).':'.$key);
                    $this->assertStringNotContainsString("\0", $value);
                }
            }
        }
    }

    public function test_canonical_status_glossary_and_machine_values_are_preserved(): void
    {
        $this->assertSame('Ready to Pay', __('status.local_invoice.ready_to_pay', [], 'en'));
        $this->assertSame('Siap Dibayar', __('status.local_invoice.ready_to_pay', [], 'id'));
        $this->assertSame('WAITING_PHYSICAL_DOCUMENT', LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT);
        $this->assertSame(['en' => 'English', 'id' => 'Bahasa Indonesia'], config('user_preferences.locales'));
    }

    public function test_indonesian_copy_keeps_supplier_and_vendor_terms_in_english(): void
    {
        $offenders = [];

        foreach (glob(lang_path('id/*.php')) as $file) {
            $messages = require $file;
            array_walk_recursive($messages, function (mixed $value) use (&$offenders, $file): void {
                if (is_string($value) && preg_match('/\b(pemasok|rekanan)\b/i', $value)) {
                    $offenders[] = basename($file).': '.$value;
                }
            });
        }

        // Approved glossary (AGENTS.md, UI Language Policy): Supplier/Vendor terms stay in English in lang/id.
        $this->assertSame([], $offenders);
    }

    public function test_indonesian_finance_admin_registration_and_accessibility_labels_are_localized(): void
    {
        $this->assertSame('Dashboard Keuangan dan Utang Usaha - ADASI', __('finance.dashboard.title', [], 'id'));
        $this->assertSame('Dashboard Keuangan dan Utang Usaha', __('finance.dashboard.heading', [], 'id'));
        $this->assertSame('Daftar Invoice - Keuangan AP', __('finance.closure.invoice_register_title', [], 'id'));
        $this->assertSame('Urusan Umum (GA)', __('navigation.general_affairs', [], 'id'));
        $this->assertSame('Atur Ulang', __('admin.copy.reset', [], 'id'));
        $this->assertSame('Impor PO', __('local_procurement.import.po', [], 'id'));
        $this->assertSame('Impor GR', __('local_procurement.import.gr', [], 'id'));
        $this->assertSame('Legalitas, PIC & Bank', __('registration.js.step2', [], 'id'));
        $this->assertSame('Ketebalan minimal', __('materials.a11y.minimum', ['dimension' => 'Ketebalan'], 'id'));
        $this->assertSame('Supplier Registration', __('terms.supplier_registration', [], 'id'));
        $this->assertSame('Import Supplier', __('terms.import_supplier', [], 'id'));
        $this->assertSame('Local Supplier', __('terms.local_supplier', [], 'id'));
        $this->assertSame('Kode HS', __('common.fields.hs_code', [], 'id'));
        $this->assertSame('Bentuk', __('purchasing.copy.shape', [], 'id'));
        $this->assertSame('Perkiraan Kedatangan', __('purchasing.copy.estimated_arrival', [], 'id'));
        $this->assertSame('Nomor PR', __('purchasing.copy.pr_no', [], 'id'));
        $this->assertNotSame(__('finance.dashboard.title', [], 'en'), __('finance.dashboard.title', [], 'id'));
    }

    public function test_finance_and_ga_display_labels_follow_locale_with_complete_placeholders(): void
    {
        $this->assertSame('DPP Amount (Rp)', __('finance.drp_ui.dpp_amount', [], 'en'));
        $this->assertSame('Nominal DPP (Rp)', __('finance.drp_ui.dpp_amount', [], 'id'));
        $this->assertSame('PPN (Rp)', __('finance.drp_ui.ppn_amount', [], 'en'));
        $this->assertSame('PPN (Rp)', __('finance.drp_ui.ppn_amount', [], 'id'));
        $this->assertSame('Claim Amount', __('ga.labels.claim_amount', [], 'en'));
        $this->assertSame('Nominal Pengajuan', __('ga.labels.claim_amount', [], 'id'));
        $this->assertSame('Invoice / PO Number', __('local_invoice.table.invoice_po', [], 'en'));
        $this->assertSame('Nomor Invoice / PO', __('local_invoice.table.invoice_po', [], 'id'));
        $this->assertSame('Account: BCA — 123 (ADASI)', __('finance.drp_ui.bank_details', ['bank' => 'BCA', 'account' => '123', 'holder' => 'ADASI'], 'en'));
        $this->assertSame('Rekening: BCA — 123 (ADASI)', __('finance.drp_ui.bank_details', ['bank' => 'BCA', 'account' => '123', 'holder' => 'ADASI'], 'id'));
        $this->assertSame('2 pending', trans_choice('purchasing.summary.pending_count', 2, ['count' => 2], 'en'));
        $this->assertSame('No pending items', trans_choice('purchasing.summary.pending_count', 0, ['count' => 0], 'en'));
        $this->assertSame('1 pending', trans_choice('purchasing.summary.pending_count', 1, ['count' => 1], 'en'));
        $this->assertSame('Tidak ada item tertunda', trans_choice('purchasing.summary.pending_count', 0, ['count' => 0], 'id'));
        $this->assertSame('1 tertunda', trans_choice('purchasing.summary.pending_count', 1, ['count' => 1], 'id'));
        $this->assertSame('2 tertunda', trans_choice('purchasing.summary.pending_count', 2, ['count' => 2], 'id'));
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $expected = $locale === 'en'
                ? ['zero' => 'No items', 'one' => ':count item', 'other' => ':count items']
                : ['zero' => 'Tidak ada item', 'one' => ':count item', 'other' => ':count item'];
            $this->assertSame($expected, JsTranslations::payload()['messages']['purchasing.js.item_count']);
        }
    }

    public function test_supplier_quotation_import_feedback_is_localized_with_count_choices(): void
    {
        $expected = [
            'en' => [
                'field_change_count' => [
                    'zero' => 'no field changes',
                    'one' => 'one field change',
                    'other' => ':count field changes',
                ],
                'import_result' => [
                    'zero' => 'No quotation changes were applied.',
                    'one' => 'Applied :field_count across one quotation row.',
                    'other' => 'Applied :field_count across :count quotation rows.',
                ],
            ],
            'id' => [
                'field_change_count' => [
                    'zero' => 'tanpa perubahan kolom',
                    'one' => '1 perubahan kolom',
                    'other' => ':count perubahan kolom',
                ],
                'import_result' => [
                    'zero' => 'Tidak ada perubahan penawaran yang diterapkan.',
                    'one' => ':field_count diterapkan pada satu baris penawaran.',
                    'other' => ':field_count diterapkan pada :count baris penawaran.',
                ],
            ],
        ];

        $this->assertSame('Ambiguous', __('purchasing.copy.ambiguous', [], 'en'));
        $this->assertSame('Lebih dari satu kecocokan', __('purchasing.copy.ambiguous', [], 'id'));

        foreach ($expected as $locale => $messages) {
            app()->setLocale($locale);
            $payload = JsTranslations::payload()['messages'];

            $this->assertSame($messages['field_change_count'], $payload['supplier.js.field_change_count'] ?? null);
            $this->assertSame($messages['import_result'], $payload['supplier.js.import_result'] ?? null);
        }

        $quotationView = file_get_contents(resource_path('views/supplier/quotations/create.blade.php'));
        $conversationView = file_get_contents(resource_path('views/conversations/show.blade.php'));
        $requisitionImportView = file_get_contents(resource_path('views/purchasing/pr/_import.blade.php'));

        $this->assertStringContainsString("choice('supplier.js.import_result'", $quotationView);
        $this->assertStringNotContainsString('field(s) across', $quotationView);
        $this->assertStringContainsString("@json(__('common.chat.sending'))", $conversationView);
        $this->assertStringContainsString("ambiguous: @json(__('purchasing.copy.ambiguous'))", $requisitionImportView);
        $this->assertStringNotContainsString("ambiguous: 'Ambiguous'", $requisitionImportView);
        $this->assertStringContainsString("@json(__('purchasing.copy.remark_entered'))", $requisitionImportView);
    }

    public function test_active_workflow_page_titles_use_localized_domain_copy(): void
    {
        $expected = [
            'resources/views/accounting/invoices/show.blade.php' => "@section('title', __('local_invoice.form.detail_title'",
            'resources/views/admin/requisitions/show.blade.php' => "@section('title', __('admin.copy.purchase_requisition_details')",
            'resources/views/finance/local-procurement/index.blade.php' => "@section('title', __('local_procurement.register.title'))",
            'resources/views/finance/local-procurement/show.blade.php' => "@section('title', __('local_procurement.closure.po_detail_title'",
            'resources/views/finance/master-invoices/index.blade.php' => "@section('page-title', __('common.final_review.master_reporting'))",
            'resources/views/finance/vendors/index.blade.php' => "@section('page-title', __('local_procurement.labels.vendor_data'))",
            'resources/views/finance/vouchers/show.blade.php' => "@section('title', __('finance.voucher.title')",
            'resources/views/local-supplier/purchase-orders/index.blade.php' => "@section('page-title', __('local_procurement.closure.po_title'))",
            'resources/views/purchasing/pr/show.blade.php' => "@section('title', __('purchasing.copy.detail_purchase_requisition')",
        ];

        foreach ($expected as $path => $translationCall) {
            $source = file_get_contents(base_path($path));
            $this->assertIsString($source, $path);
            $this->assertStringContainsString($translationCall, $source, $path);
        }
    }

    public function test_async_export_and_document_status_feedback_follow_the_locale(): void
    {
        foreach (['en' => 'Preparing...', 'id' => 'Menyiapkan...'] as $locale => $preparing) {
            app()->setLocale($locale);
            $this->assertSame($preparing, JsTranslations::payload()['messages']['js.export.preparing']);
        }

        $exportScript = file_get_contents(public_path('assets/js/async-export.js'));
        $purchaseOrderView = file_get_contents(resource_path('views/purchasing/po/show.blade.php'));

        $this->assertStringContainsString("element.value = t('js.export.preparing')", $exportScript);
        $this->assertStringNotContainsString("element.value = 'Menyiapkan...'", $exportScript);
        $this->assertStringContainsString("@json(__('common.feedback.success'))", $purchaseOrderView);
        $this->assertStringNotContainsString("@json('Success!')", $purchaseOrderView);
    }

    public function test_invoice_history_status_prefix_uses_the_shared_translation_key(): void
    {
        $this->assertSame('Status:', __('common.labels_review.status', [], 'en'));
        $this->assertSame('Status:', __('common.labels_review.status', [], 'id'));

        $invoiceDetail = file_get_contents(resource_path('views/local-invoices/detail.blade.php'));
        $this->assertStringContainsString("__('common.labels_review.status')", $invoiceDetail);
        $this->assertStringNotContainsString('· Status:', $invoiceDetail);
    }

    public function test_material_progress_summary_uses_complete_locale_aware_count_phrases(): void
    {
        $expected = [
            'en' => [
                0 => 'No items — Stage',
                1 => '1 item — Stage',
                2 => '2 items — Stage',
            ],
            'id' => [
                0 => 'Tidak ada item — Stage',
                1 => '1 item — Stage',
                2 => '2 item — Stage',
            ],
        ];

        foreach ($expected as $locale => $outputs) {
            app()->setLocale($locale);
            foreach ($outputs as $count => $output) {
                $this->assertSame($output, trans_choice('purchasing.copy.progress_summary', $count, ['count' => $count, 'stage' => 'Stage']));
            }
        }

        $service = file_get_contents(app_path('Services/MaterialProgressService.php'));
        $this->assertStringContainsString("trans_choice('purchasing.copy.progress_summary'", $service);
        $this->assertStringNotContainsString('"{$count} {$label}"', $service);
    }

    public function test_purchase_requisition_hs_status_presentation_uses_localized_labels(): void
    {
        $expected = [
            'en' => [
                'auto_matched' => 'Auto matched',
                'ambiguous' => 'Ambiguous',
                'manual_selection' => 'Manual selection',
                'legacy_source' => 'Legacy data',
                'unmapped_material' => 'Unmapped material',
                'unresolved' => 'Unresolved',
            ],
            'id' => [
                'auto_matched' => 'Cocok otomatis',
                'ambiguous' => 'Lebih dari satu kecocokan',
                'manual_selection' => 'Pilihan manual',
                'legacy_source' => 'Data lama',
                'unmapped_material' => 'Material belum dipetakan',
                'unresolved' => 'Belum terselesaikan',
            ],
        ];

        foreach ($expected as $locale => $labels) {
            foreach ($labels as $key => $label) {
                $this->assertSame($label, __('purchasing.copy.'.$key, [], $locale));
            }
        }

        foreach ([
            'resources/views/purchasing/pr/_material_shape_script.blade.php' => "ambiguous: @json(__('purchasing.copy.ambiguous'))",
            'resources/views/purchasing/pr/_item_row.blade.php' => "__('purchasing.copy.auto_matched')",
            'resources/views/purchasing/pr/show.blade.php' => "__('purchasing.copy.legacy_source')",
        ] as $path => $translationCall) {
            $source = file_get_contents(resource_path(str_replace('resources/', '', $path)));
            $this->assertStringContainsString($translationCall, $source, $path);
            $this->assertStringNotContainsString("'Ambiguous'", $source, $path);
        }
    }

    public function test_gr_multi_select_summary_uses_locale_specific_selected_label(): void
    {
        $this->assertSame('Selected', __('common.select.selected_state', [], 'en'));
        $this->assertSame('Dipilih', __('common.select.selected_state', [], 'id'));

        $source = file_get_contents(resource_path('views/components/ui/multi-select.blade.php'));
        $this->assertStringContainsString("text: @js(__('common.select.selected_state'))", $source);
        $this->assertStringNotContainsString("text: 'Terpilih'", $source);
    }

    public function test_system_generated_local_and_ga_history_notes_have_bilingual_snapshot_copy(): void
    {
        $expected = [
            'en' => [
                'local_invoice.history.physical_received' => 'Physical documents received. Payment term: 30 days. Due date: 2026-10-10.',
                'local_invoice.history.verification_approved' => 'Invoice verification was finalized and approved as Ready to Pay.',
                'local_invoice.history.physical_verification_superseded' => 'Superseded by revision 2.',
                'local_invoice.history.partial_payment' => 'Partial payment of Rp 10 recorded (reference: REF-1). Total paid: Rp 100. Remaining balance: Rp 50.',
                'local_invoice.history.partial_payment_with_reason' => 'Partial payment of Rp 10 recorded (reference: REF-1). Total paid: Rp 100. Remaining balance: Rp 50. Reason: Short transfer.',
                'local_invoice.history.settlement_finalized' => 'Invoice settlement was finalized.',
                'local_invoice.history.overpaid' => 'An overpayment of Rp 10 was recorded as a supplier refund receivable.',
                'local_invoice.history.expired' => 'Invoice expired after missing the scheduled physical document delivery twice.',
                'local_invoice.history.delivery_missed' => 'Physical document delivery was missed on the scheduled date. Rescheduling is required.',
                'local_invoice.history.rescheduled' => 'Physical document delivery was rescheduled to Wednesday, 2026-10-07.',
                'local_invoice.history.refund_settled' => 'Overpayment refund of Rp 10 was completed by Finance ADASI.',
                'local_invoice.history.refund_settled_with_notes' => 'Overpayment refund of Rp 10 was completed by Finance ADASI. Notes: Follow-up attached.',
                'ga.history.submitted' => 'GA claim submitted on behalf of Employee.',
                'ga.history.resubmitted' => 'GA claim revised and resubmitted (revision 2).',
                'ga.history.basic_verified' => 'Basic GA claim information was verified.',
                'ga.history.approved' => 'Claim was verified and marked Ready to Pay by Finance.',
                'finance.history.payment_confirmed' => 'Payment was confirmed using transfer reference: REF-1.',
            ],
            'id' => [
                'local_invoice.history.physical_received' => 'Dokumen fisik diterima. Termin pembayaran: 30 hari. Jatuh tempo: 2026-10-10.',
                'local_invoice.history.verification_approved' => 'Verifikasi invoice selesai dan disetujui sebagai Siap Dibayar.',
                'local_invoice.history.physical_verification_superseded' => 'Digantikan oleh revisi 2.',
                'local_invoice.history.partial_payment' => 'Pembayaran parsial sebesar Rp 10 dicatat (referensi: REF-1). Total terbayar: Rp 100. Sisa tagihan: Rp 50.',
                'local_invoice.history.partial_payment_with_reason' => 'Pembayaran parsial sebesar Rp 10 dicatat (referensi: REF-1). Total terbayar: Rp 100. Sisa tagihan: Rp 50. Alasan: Short transfer.',
                'local_invoice.history.settlement_finalized' => 'Penyelesaian pembayaran invoice telah difinalisasi.',
                'local_invoice.history.overpaid' => 'Kelebihan pembayaran sebesar Rp 10 dicatat sebagai piutang pengembalian dana supplier.',
                'local_invoice.history.expired' => 'Invoice kedaluwarsa setelah dua kali melewatkan jadwal penyerahan dokumen fisik.',
                'local_invoice.history.delivery_missed' => 'Penyerahan dokumen fisik terlewat pada tanggal yang dijadwalkan. Jadwal perlu diatur ulang.',
                'local_invoice.history.rescheduled' => 'Jadwal penyerahan dokumen fisik diubah ke hari Rabu, 2026-10-07.',
                'local_invoice.history.refund_settled' => 'Pengembalian kelebihan bayar sebesar Rp 10 diselesaikan oleh Keuangan ADASI.',
                'local_invoice.history.refund_settled_with_notes' => 'Pengembalian kelebihan bayar sebesar Rp 10 diselesaikan oleh Keuangan ADASI. Catatan: Follow-up attached.',
                'ga.history.submitted' => 'Klaim GA diajukan atas nama Employee.',
                'ga.history.resubmitted' => 'Klaim GA direvisi dan diajukan ulang (revisi 2).',
                'ga.history.basic_verified' => 'Informasi dasar klaim GA telah diverifikasi.',
                'ga.history.approved' => 'Klaim diverifikasi dan ditetapkan Siap Dibayar oleh Keuangan.',
                'finance.history.payment_confirmed' => 'Pembayaran dikonfirmasi dengan referensi transfer: REF-1.',
            ],
        ];

        foreach ($expected as $locale => $messages) {
            foreach ($messages as $key => $message) {
                $this->assertSame($message, __($key, [
                    'amount' => '10', 'actual' => '100', 'date' => '2026-10-07', 'due_date' => '2026-10-10',
                    'employee' => 'Employee', 'expected' => '150', 'notes' => 'Follow-up attached', 'payment_term' => 30,
                    'reason' => 'Short transfer', 'reference' => 'REF-1', 'remaining' => '50', 'revision' => 2,
                ], $locale), $locale.':'.$key);
            }
        }

        $sources = [
            'Services/LocalInvoice/InvoicePhysicalReceiptService.php' => "__('local_invoice.history.physical_received'",
            'Services/LocalInvoice/InvoiceSubmissionService.php' => "__('local_invoice.history.physical_verification_superseded'",
            'Services/LocalInvoice/InvoiceVerificationService.php' => "__('local_invoice.history.verification_approved'",
            'Services/Payment/LocalInvoicePaymentService.php' => "'local_invoice.history.partial_payment'",
            'Services/LocalInvoice/InvoiceExpiryService.php' => "__('local_invoice.history.expired'",
            'Services/Payment/SupplierOverpaymentService.php' => "'local_invoice.history.refund_settled'",
            'Services/Ga/GaClaimService.php' => "__('ga.history.submitted'",
            'Services/Ga/GaVerificationService.php' => "__('ga.history.approved'",
            'Services/Payment/PaymentExecutionService.php' => "__('finance.history.payment_confirmed'",
        ];
        foreach ($sources as $path => $translationCall) {
            $source = file_get_contents(app_path($path));
            $this->assertTrue(str_contains($source, $translationCall), $path);
        }
    }

    public function test_inline_fetch_failure_copy_is_selected_from_locale_dictionary(): void
    {
        $sources = [
            'resources/views/finance/dashboard.blade.php' => ['Network error'],
            'resources/views/purchasing/comparison/_scripts.blade.php' => ['Failed to load material', 'Failed to load historical data'],
        ];

        foreach ($sources as $path => $literals) {
            $source = file_get_contents(resource_path(str_replace('resources/', '', $path)));
            foreach ($literals as $literal) {
                $this->assertStringNotContainsString($literal, $source, $path);
            }
        }
    }

    public function test_unknown_machine_statuses_keep_the_value_inside_localized_copy(): void
    {
        foreach ([
            'en' => 'Unrecognized status: FUTURE_STATUS',
            'id' => 'Status tidak dikenal: FUTURE_STATUS',
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $this->assertSame($expected, StatusHelper::localFinanceLabel('FUTURE_STATUS'));
            $this->assertSame('', StatusHelper::localFinanceLabel(''));
        }
    }

    public function test_supplier_availability_toggle_uses_localized_status_presentation(): void
    {
        $source = file_get_contents(resource_path('views/supplier/quotations/create.blade.php'));
        $this->assertStringContainsString("__('status.availability.available')", $source);
        $this->assertStringNotContainsString("? 'Available' :", $source);
    }
}

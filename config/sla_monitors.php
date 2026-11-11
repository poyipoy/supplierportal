<?php

return [
    'enabled' => (bool) env('SLA_WATCHDOG_ENABLED', true),

    'rules' => [
        'import_po_unacknowledged' => [
            'enabled' => true,
            'reminder_hours' => (int) env('SLA_IMPORT_PO_ACK_REMINDER_HOURS', 48),
            'escalation_hours' => (int) env('SLA_IMPORT_PO_ACK_ESCALATION_HOURS', 96),
        ],
        'pr_bidding_stale' => [
            'enabled' => true,
            'reminder_hours' => (int) env('SLA_PR_BIDDING_REMINDER_HOURS', 72),
            'escalation_hours' => (int) env('SLA_PR_BIDDING_ESCALATION_HOURS', 120),
        ],
        'qc_waiting_inspection' => [
            'enabled' => true,
            'reminder_hours' => (int) env('SLA_QC_WAITING_REMINDER_HOURS', 48),
            'escalation_hours' => (int) env('SLA_QC_WAITING_ESCALATION_HOURS', 96),
        ],
        'local_invoice_verification_pending' => [
            'enabled' => true,
            'reminder_hours' => (int) env('SLA_LOCAL_INVOICE_VERIFY_REMINDER_HOURS', 72),
            'escalation_hours' => (int) env('SLA_LOCAL_INVOICE_VERIFY_ESCALATION_HOURS', 120),
        ],
    ],
];

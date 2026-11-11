<?php

return [
    'notifications' => [
        'import_po_reminder_title' => 'Reminder: PO Acknowledgement Pending',
        'import_po_reminder_body' => 'Purchase Order :number is awaiting your acknowledgement or delivery date confirmation.',
        'import_po_escalation_title' => 'SLA Escalation: Unacknowledged Import PO',
        'import_po_escalation_body' => 'Purchase Order :number for :supplier has not been acknowledged within the SLA window.',
        'local_po_reminder_title' => 'Reminder: Local PO Acknowledgement Pending',
        'local_po_reminder_body' => 'Local Purchase Order :number is awaiting your acknowledgement.',
        'local_po_escalation_title' => 'SLA Escalation: Unacknowledged Local PO',
        'local_po_escalation_body' => 'Local Purchase Order :number for :supplier has not been acknowledged within the SLA window.',
        'pr_bidding_reminder_title' => 'Reminder: Quotation Invitation Open',
        'pr_bidding_reminder_body' => 'Purchase Requisition :number is awaiting your quotation response.',
        'pr_bidding_escalation_title' => 'SLA Escalation: Stale Bidding Requisition',
        'pr_bidding_escalation_body' => 'Purchase Requisition :number has remained in bidding beyond the SLA threshold.',
        'qc_waiting_reminder_title' => 'Reminder: QC Inspection Waiting',
        'qc_waiting_reminder_body' => 'Purchase Order :number has arrived and is awaiting QC inspection.',
        'qc_waiting_escalation_title' => 'SLA Escalation: Delayed QC Inspection',
        'qc_waiting_escalation_body' => 'Purchase Order :number has been waiting for QC inspection beyond the SLA threshold.',
        'local_invoice_reminder_title' => 'Reminder: Local Invoice Verification Pending',
        'local_invoice_reminder_body' => 'Local Invoice :number is awaiting Finance verification.',
        'local_invoice_escalation_title' => 'SLA Escalation: Local Invoice Verification Delayed',
        'local_invoice_escalation_body' => 'Local Invoice :number has been awaiting Finance verification beyond the SLA threshold.',
    ],
];

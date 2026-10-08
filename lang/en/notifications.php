<?php

return [
    'registration' => [
        'submitted' => ['title' => 'New Supplier Registration', 'message' => 'Supplier :company has submitted registration :reference.'],
        'resubmitted' => ['title' => 'Supplier Registration Resubmitted', 'message' => 'Supplier :company has resubmitted their registration with revisions.'],
        'revision_requested' => ['title' => 'Supplier Registration Revision Requested', 'message' => 'Revision requested for supplier :company by :reviewer.'],
        'rejected' => ['title' => 'Supplier Registration Rejected', 'message' => 'Registration for supplier :company was rejected by :reviewer.'],
        'approved' => ['title' => 'Supplier Registration Approved', 'message' => 'Supplier :company has been approved with portal scope(s): :scopes.'],
        'scopes' => ['import' => 'Material Procurement', 'local' => 'Local Supplier', 'both' => 'Material Procurement, Local Supplier'],
    ],

    'feedback' => ['all_read' => 'All notifications have been marked as read.', 'category_read' => ':category notifications have been marked as read.', 'saved' => 'Notification preferences saved.', 'reset' => 'Notification preferences reset to defaults.'],
    'conversation' => [
        'muted' => 'Conversation notifications muted.',
        'unmuted' => 'Conversation notifications unmuted.',
        'revise_unavailable' => 'Please revise the quotation for PR :pr_number because all items were marked as not available.',
        'revise_expired' => 'Please revise the quotation for PR :pr_number because the quotation validity has expired.',
        'with_revision_note' => ":message\n\nRevision notes: :note",
        'extend_validity' => 'Please extend the quotation validity for PR :pr_number.',
        'confirm_delivery' => 'Please confirm the latest estimated delivery for PR :pr_number.',
        'revise_price' => "Please revise the quotation price for PR :pr_number.\n\nRevision notes: :note",
        'rejected_with_note' => "Quotation for PR :pr_number was rejected by Purchasing.\n\nNotes: :note",
        'with_note' => ":message\n\nNotes: :note",
        'empty_message' => 'Enter a message or attach at least one file.',
        'purchasing_only' => 'Only Purchasing can run negotiation actions.',
        'no_quotation' => 'No related quotation was found for this conversation.',
        'note_required' => 'Notes are required for this action.',
        'quotation_in_use' => 'This quotation is used by an award or Purchase Order and cannot be changed.',
        'no_messages' => 'No messages yet',
        'user' => 'User',
        'completed_pr' => 'The PR is completed. A quotation revision cannot be requested.',
        'revision_ineligible' => 'A revision can only be requested for submitted quotations that have not been used to create a PO.',
        'cannot_accept' => 'This quotation cannot be accepted.',
        'all_unavailable' => 'This quotation cannot be accepted because all items are marked as not available by the supplier.',
        'expired' => 'This quotation has expired. Ask the supplier to submit a revision before accepting it.',
        'cannot_reject' => 'This quotation cannot be rejected.',
        'no_available_items' => 'Cannot reject a quotation that has no available items.',
    ],
    'validation' => ['available_boolean' => 'Choose only notifications available for your account and boolean values.', 'unsupported_field' => 'This field is not supported by notification preferences.'],
    'exports' => [
        'completed' => [
            'title' => 'Export Completed',
            'message' => 'Export :label is ready to download.',
        ],
        'failed' => [
            'title' => 'Export Failed',
            'message' => 'The export could not be processed. Please try again.',
        ],
    ],
    'preferences.title' => 'Notifications - ADASI Supplier Portal',
    'preferences.description' => 'Choose which in-app notifications you want to receive.',
    'preferences.review' => 'Review your notification preferences',
    'preferences.settings' => 'Notification settings',
    'preferences.no_settings' => 'There are no notification settings available for your account.',
    'preferences.search' => 'Search notifications...',
    'preferences.filter' => 'Filter notifications',
    'preferences.all' => 'All',
    'preferences.enabled' => 'Enabled',
    'preferences.muted' => 'Muted',
    'preferences.silent' => 'Silent',
    'preferences.presets' => 'Presets:',
    'preferences.everything' => 'Everything',
    'preferences.action_only' => 'Action needed only',
    'preferences.quiet' => 'Quiet mode',
    'preferences.collapse_all' => 'Collapse all',
    'preferences.expand_all' => 'Expand all',
    'preferences.panel' => 'Notification preferences',
    'preferences.all_on' => 'Turn all on',
    'preferences.all_off' => 'Turn all off',
    'preferences.action' => 'Action needed',
    'preferences.warning' => 'This notification requires your action. Muting it may cause you to miss pending tasks.',
    'preferences.normal' => 'Normal — popup + inbox',
    'preferences.inbox_only' => 'Silent — inbox only',
    'preferences.empty' => 'No notification preferences found',
    'preferences.empty_help' => 'No notification settings match your current search and filter criteria.',
    'preferences.clear_filters' => 'Clear filters',
    'preferences.no_changes' => 'No unsaved changes',
    'preferences.discard' => 'Discard',
    'preferences.reset' => 'Reset to defaults',
    'preferences.reset_title' => 'Reset notification preferences',
    'preferences.reset_help' => 'All notification settings will return to default, and any unsaved changes on this page will be discarded.',
    'preferences.on' => 'On',
    'preferences.off' => 'Off',
    'preferences.delivery' => ':label delivery mode',
    'preferences.category_summary' => ':on of :total on',
    'preferences.summary' => ':enabled of :total enabled · :silent silent · :off off',
    'preferences.unsaved' => ':count unsaved changes',
    'preferences.search_label' => 'Search notification preferences',
    'preferences.scope' => 'Supplier portal notification scope',
    'preferences.heading' => 'Preferences',
    'events' => [
        'pr_submitted' => [
            'label' => 'Purchase requisition submitted',
            'description' => 'A purchase requisition is submitted for review.',
        ],
        'quotation_submitted' => [
            'label' => 'Quotation submitted',
            'description' => 'A supplier submits a quotation.',
        ],
        'quotation_revised' => [
            'label' => 'Revised quotation submitted',
            'description' => 'A supplier resubmits a revised quotation.',
        ],
        'quotation_accepted' => [
            'label' => 'Quotation accepted',
            'description' => 'Purchasing accepts your quotation.',
        ],
        'quotation_rejected' => [
            'label' => 'Quotation rejected',
            'description' => 'Purchasing rejects your quotation.',
        ],
        'quotation_revision_requested' => [
            'label' => 'Quotation revision requested',
            'description' => 'Purchasing asks you to revise a quotation.',
        ],
        'quotation_negotiation_message' => [
            'label' => 'Negotiation request',
            'description' => 'Purchasing sends a negotiation request about your quotation.',
        ],
        'conversation_message_created' => [
            'label' => 'Conversation message',
            'description' => 'Your conversation partner sends a message or attachment.',
        ],
        'po_issued' => [
            'label' => 'Purchase order issued',
            'description' => 'A purchase order is issued for your awarded items.',
        ],
        'document_status_updated' => [
            'label' => 'Import document status updated',
            'description' => 'An import document status changes on a purchase order you created.',
        ],
        'document_all_completed' => [
            'label' => 'Import documents complete',
            'description' => 'All required import documents are complete for a purchase order you created.',
        ],
        'po_item_progress_updated' => [
            'label' => 'Material progress updated',
            'description' => 'A supplier updates material progress or estimated readiness.',
        ],
        'shipment_submitted' => [
            'label' => 'Shipment submitted',
            'description' => 'A supplier submits a shipment.',
        ],
        'po_material_arrived' => [
            'label' => 'Material ready for QC',
            'description' => 'Material arrives and is ready for quality inspection.',
        ],
        'qc_inspection_ok' => [
            'label' => 'QC inspection passed',
            'description' => 'Material passes quality inspection.',
        ],
        'qc_inspection_ng' => [
            'label' => 'QC inspection failed',
            'description' => 'Quality inspection identifies material that requires a claim.',
        ],
        'claim_created' => [
            'label' => 'Material claim created',
            'description' => 'Purchasing creates a claim for your material.',
        ],
        'claim_responded' => [
            'label' => 'Material claim response',
            'description' => 'A supplier responds to a material claim.',
        ],
        'claim_resolved' => [
            'label' => 'Material claim resolved',
            'description' => 'Purchasing completes your material claim.',
        ],
        'local_invoice_submitted' => [
            'label' => 'Invoice submitted',
            'description' => 'A new invoice submission is received.',
        ],
        'local_invoice_resubmitted' => [
            'label' => 'Invoice resubmitted',
            'description' => 'A revised invoice is resubmitted for verification.',
        ],
        'local_invoice_cancelled' => [
            'label' => 'Invoice cancelled',
            'description' => 'Your invoice submission is cancelled.',
        ],
        'local_invoice_physical_received' => [
            'label' => 'Physical documents received',
            'description' => 'The cashier records receipt of your invoice physical documents.',
        ],
        'local_invoice_approved' => [
            'label' => 'Invoice ready to pay',
            'description' => 'Your invoice is approved and ready to pay.',
        ],
        'local_invoice_revision_requested' => [
            'label' => 'Invoice revision requested',
            'description' => 'Finance requests a revision of your invoice.',
        ],
        'local_invoice_rejected' => [
            'label' => 'Invoice rejected',
            'description' => 'Finance rejects your invoice submission.',
        ],
        'local_invoice_partial_payment' => [
            'label' => 'Payment correction required',
            'description' => 'The actual transfer has not reached the expected invoice settlement amount.',
        ],
        'local_invoice_paid' => [
            'label' => 'Invoice paid',
            'description' => 'Your invoice payment settlement is complete.',
        ],
        'local_invoice_overpaid' => [
            'label' => 'Overpayment recorded',
            'description' => 'Your invoice overpayment is recorded for refund.',
        ],
        'local_invoice_refund_settled' => [
            'label' => 'Refund settled',
            'description' => 'Your overpayment refund is recorded as complete.',
        ],
        'local_invoice_physical_delivery_reminder' => [
            'label' => 'Physical delivery reminder',
            'description' => 'Your scheduled invoice physical document delivery is approaching.',
        ],
        'supplier_registration_submitted' => [
            'label' => 'Supplier registration submitted',
            'description' => 'A supplier submits a new registration.',
        ],
        'supplier_registration_resubmitted' => [
            'label' => 'Supplier registration resubmitted',
            'description' => 'A supplier resubmits a revised registration.',
        ],
        'supplier_registration_revision_requested' => [
            'label' => 'Registration revision requested',
            'description' => 'A reviewer requests changes to a supplier registration.',
        ],
        'supplier_registration_rejected' => [
            'label' => 'Supplier registration rejected',
            'description' => 'A reviewer rejects a supplier registration.',
        ],
        'supplier_registration_approved' => [
            'label' => 'Supplier registration approved',
            'description' => 'A reviewer approves a supplier registration.',
        ],
        'export_completed' => [
            'label' => 'Export completed',
            'description' => 'Your export is ready to download.',
        ],
        'export_failed' => [
            'label' => 'Export failed',
            'description' => 'Your export could not be completed.',
        ],
        'new_device_login' => [
            'label' => 'New device sign-in',
            'description' => 'Your account is signed in on a new device.',
        ],
        'repeated_lockouts_detected' => [
            'label' => 'Repeated sign-in lockouts',
            'description' => 'An account reaches the repeated sign-in lockout threshold.',
        ],
    ],
    'categories' => [
        'requisitions' => 'Purchase requisitions',
        'quotations' => 'Quotations',
        'conversations' => 'Conversations',
        'purchase_orders' => 'Purchase orders',
        'documents' => 'Documents',
        'shipments_qc' => 'Shipments and QC',
        'claims' => 'Material claims',
        'local_invoices' => 'Local invoices',
        'registration' => 'Supplier registration',
        'exports' => 'Exports',
        'security' => 'Security',
    ],
    'invoice' => [
        'events' => [
            'submitted' => [
                'title' => 'Invoice submitted',
                'message' => ':submission - Invoice :invoice is waiting for physical documents.',
            ],
            'resubmitted' => [
                'title' => 'Invoice resubmitted',
                'message' => ':submission - Revised invoice :invoice is waiting for physical documents.',
            ],
            'cancelled' => [
                'title' => 'Invoice cancelled',
                'message' => ':submission - Invoice :invoice was cancelled. :reason',
            ],
            'physical_received' => [
                'title' => 'Physical documents received',
                'message' => ':submission - Physical documents for invoice :invoice have been received. Payment term: :payment_term days. Due date: :due_date. :raw_notes',
            ],
            'approved' => [
                'title' => 'Invoice ready to pay',
                'message' => ':submission - Invoice :invoice is approved and ready to pay. Due date: :due_date.',
            ],
            'revision_requested' => [
                'title' => 'Invoice revision requested',
                'message' => ':submission - Please revise invoice :invoice. :reason',
            ],
            'rejected' => [
                'title' => 'Invoice rejected',
                'message' => ':submission - Invoice :invoice was rejected. :reason',
            ],
            'partial_payment' => [
                'title' => 'Payment correction required',
                'message' => ':submission - Invoice :invoice requires a payment correction. Actual: :actual. Expected: :expected. Remaining: :remaining. Transfer reference: :reference. :reason',
            ],
            'paid' => [
                'title' => 'Invoice paid',
                'message' => ':submission - Payment settlement for invoice :invoice is complete. Amount: :amount. Transfer reference: :reference.',
            ],
            'overpaid' => [
                'title' => 'Overpayment recorded',
                'message' => ':submission - Overpayment for invoice :invoice is recorded for refund. Amount: :amount.',
            ],
            'refund_settled' => [
                'title' => 'Refund settled',
                'message' => ':submission - Refund for invoice :invoice is complete. Amount: :amount. :raw_notes',
            ],
            'physical_delivery_reminder' => [
                'title' => 'Physical delivery reminder',
                'message' => ':submission - Your physical document delivery for invoice :invoice is approaching.',
            ],
            'delivery_missed' => [
                'title' => 'Physical delivery missed',
                'message' => ':submission - The physical document delivery date for invoice :invoice was missed.',
            ],
            'expired' => [
                'title' => 'Invoice expired',
                'message' => ':submission - Invoice :invoice has expired.',
            ],
            'rescheduled' => [
                'title' => 'Physical delivery rescheduled',
                'message' => ':submission - The physical document delivery schedule for invoice :invoice changed.',
            ],
            'physical_verified' => [
                'title' => 'Physical documents verified',
                'message' => ':submission - Physical documents for invoice :invoice have been verified.',
            ],
            'payment_scheduled' => [
                'title' => 'Payment scheduled',
                'message' => ':submission - Payment for invoice :invoice has been scheduled.',
            ],
            'completed' => [
                'title' => 'Payment complete',
                'message' => ':submission - Payment for invoice :invoice is complete.',
            ],
        ],
        'updated' => [
            'title' => 'Invoice updated',
            'message' => ':submission - Invoice :invoice has been updated.',
        ],
        'confirmation' => [
            'message' => ':submission - :invoice',
        ],
    ],
    'security' => [
        'new_device' => [
            'title' => 'New sign-in detected',
            'message' => 'Your account was signed in on a device that has not been used with this account before.',
        ],
        'lockouts' => [
            'title' => 'Repeated sign-in lockouts detected',
            'message' => 'The account ":account" was rate-limited :count times in the last hour.',
        ],
    ],
    'center' => [
        'all' => 'All',
        'all_description' => 'All notifications',
        'chat' => 'Chat',
        'chat_description' => 'Negotiation messages',
        'quotation' => 'Quotation',
        'quotation_description' => 'PR and quotations',
        'documents' => 'PO Documents',
        'document' => 'Document',
        'document_description' => 'Import document status',
        'invoice' => 'Invoice',
        'invoice_description' => 'Local invoice updates',
        'other' => 'Other',
        'other_description' => 'Other system information',
    ],
    'chat' => [
        'message_title' => 'New message from :sender',
        'message_body' => ':preview',
        'attachment_body' => 'Sent an attachment in the chat.',
    ],
    'negotiation' => [
        'revision' => [
            'title' => 'Quotation Revision Requested',
            'message' => 'Purchasing requested a quotation revision for PR :pr_number.',
        ],
        'accepted' => [
            'title' => 'Quotation Accepted',
            'message' => 'Quotation for PR :pr_number has been accepted by Purchasing.',
        ],
        'rejected' => [
            'title' => 'Quotation Rejected',
            'message' => 'Quotation for PR :pr_number was rejected by Purchasing.',
        ],
        'message' => [
            'title' => 'New Negotiation Message',
            'message' => 'Purchasing sent a negotiation message for PR :pr_number.',
        ],
    ],
];

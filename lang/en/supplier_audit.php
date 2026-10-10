<?php

return [
    'title' => 'Supplier Audit',
    'description' => 'ISO 14001, ISO 9001 & AGC AFC supplier and vendor audit checklist.',
    'eyebrow' => 'Local Supplier',
    'purchasing_description' => 'Assign the audit checklist to local suppliers, review submitted answers, and publish the assessment result.',
    'supplier_description' => 'Complete the audit checklist assigned by Purchasing and download the assessment result.',

    'empty' => [
        'no_access_title' => 'No Supplier Audit assigned',
        'no_access' => 'Purchasing has not enabled Supplier Audit for you yet.',
        'history' => 'No previous audits.',
        'purchasing' => 'No supplier audits match the current filters.',
    ],

    'fields' => [
        'supplier' => 'Supplier',
        'suppliers' => 'Suppliers',
        'period' => 'Period',
        'due_date' => 'Deadline',
        'status' => 'Status',
        'submitted_at' => 'Submitted',
        'assigned_at' => 'Assigned',
        'assigned_by' => 'Assigned by',
        'template' => 'Checklist template',
        'answer' => 'Answer',
        'score' => 'Score',
        'yes' => 'Yes',
        'no' => 'No',
        'criterion' => 'Audit criterion',
        'section' => 'Section',
        'number' => 'No.',
        'criterion_ref' => 'Section :section no. :number',
        'revision_note' => 'Revision note',
        'cancel_reason' => 'Cancellation reason',
        'result_file' => 'Assessment result file',
        'replace_reason' => 'Replacement reason',
        'progress' => 'Progress',
        'no_deadline' => 'No deadline',
    ],

    'labels' => [
        'late' => 'Overdue',
        'progress' => ':filled of :total completed',
        'latest_file' => 'Latest',
        'score_hint' => 'Score is available when the answer is Yes.',
        'score_option' => 'Score :score of 5',
        'history' => 'Status history',
        'results' => 'Assessment result',
        'summary' => 'Answer summary',
        'summary_help' => 'Counts only. Scoring and weighting are done offline by Purchasing.',
        'active_audit' => 'Current audit',
        'active_status' => 'Active audit: :status',
        'answers' => 'Answers',
        'empty_answer' => 'Not answered',
        'uploaded_by' => 'Uploaded by :name',
        'step' => 'Step :current of :total',
        'audit_history' => 'Audit history',
        'locked' => 'This form is locked because it has been submitted.',
        'yes_count' => 'Yes',
        'no_count' => 'No',
        'empty_count' => 'Blank',
        'result_pending' => 'The assessment result has not been published yet.',
        'cancelled_note' => 'This audit was cancelled by Purchasing.',
        'selected_count' => ':count selected',
    ],

    'actions' => [
        'assign' => 'Assign Audit',
        'fill' => 'Fill in Form',
        'continue' => 'Continue',
        'view' => 'View',
        'save_draft' => 'Save Draft',
        'submit' => 'Submit',
        'export' => 'Export Excel',
        'request_revision' => 'Request Revision',
        'cancel' => 'Cancel Audit',
        'upload_result' => 'Upload Result',
        'replace_result' => 'Replace Result',
        'download_result' => 'Download Result',
        'change_deadline' => 'Change Deadline',
        'back' => 'Back',
        'next' => 'Next',
        'previous' => 'Previous',
        'filter' => 'Filter',
        'reset' => 'Reset',
        'close' => 'Close',
        'back_to_list' => 'Back to list',
        'select_all' => 'Select all available',
        'clear' => 'Clear',
    ],

    'confirm' => [
        'submit_title' => 'Submit the audit form?',
        'submit_text' => 'After submitting, the form is locked until Purchasing requests a revision.',
        'submit_confirm' => 'Yes, submit',
        'cancel_title' => 'Cancel this audit?',
        'cancel_text' => 'The supplier can no longer fill in this audit.',
        'cancel_confirm' => 'Cancel audit',
    ],

    'flash' => [
        'assigned' => 'Supplier Audit assigned to :count supplier(s).',
        'rejected' => 'Not assigned: :list.',
        'draft_saved' => 'Draft saved.',
        'submitted' => 'Supplier Audit submitted. Purchasing has been notified.',
        'revision_requested' => 'Revision requested from the supplier.',
        'cancelled' => 'Supplier Audit cancelled.',
        'result_published' => 'Assessment result published.',
        'result_replaced' => 'Assessment result replaced.',
    ],

    'errors' => [
        'no_active_template' => 'No active audit checklist template is available. Run php artisan migrate to load it.',
        'none_assigned' => 'No audit was assigned: :list.',
        'invalid_supplier' => 'One of the selected suppliers is invalid.',
        'locked' => 'This audit can no longer be edited.',
        'invalid_criterion' => 'The submitted answers do not belong to this audit.',
        'score_range' => 'Score must be a whole number from 1 to 5.',
        'answer_required' => ':criterion must be answered Yes or No.',
        'score_required' => ':criterion needs a score from 1 to 5 because the answer is Yes.',
        'score_prohibited' => ':criterion cannot have a score because the answer is No.',
        'invalid_status' => 'This action is not available for the current audit status.',
        'replace_reason_required' => 'A reason is required to replace the published result.',
        'result_file' => 'The result file must be a PDF, XLSX, JPG, or PNG file of at most 10 MB.',
        'result_store' => 'The result file could not be stored. Please try again.',
    ],

    'reject_reasons' => [
        'active_audit' => 'still has an active audit',
        'not_eligible' => 'not an active local supplier',
    ],

    'history' => [
        'events' => [
            'assigned' => 'Assigned',
            'draft_started' => 'Draft started',
            'submitted' => 'Submitted',
            'revision_requested' => 'Revision requested',
            'cancelled' => 'Cancelled',
            'result_published' => 'Result published',
            'result_replaced' => 'Result replaced',
            'deadline_changed' => 'Deadline changed',
        ],
        'by' => 'by :name',
    ],

    'invoice_block' => [
        'message' => 'New invoice submission is paused because the Supplier Audit for period :period passed its deadline (:date). Complete and submit the audit form to resume.',
        'short' => 'Complete the Supplier Audit to submit new invoices.',
        'banner_title' => 'New invoice submission is paused',
        'banner_action' => 'Fill in Supplier Audit',
        'chip' => 'Invoices blocked',
        'edit_notice' => 'Your new invoice submission is paused until this form is submitted.',
    ],

    'deadline' => [
        'change_title' => 'Change deadline',
        'change_help' => 'Leave empty to remove the deadline. A supplier past the deadline cannot submit new invoices until the audit is submitted.',
        'remove' => 'Remove deadline',
        'reason' => 'Reason (optional)',
        'change_on_revision' => 'Also change the deadline',
        'revision_warning' => 'The current deadline has passed. If you keep it, the supplier is immediately blocked from submitting new invoices.',
        'flash_changed' => 'Deadline updated.',
        'flash_removed' => 'Deadline removed.',
        'save' => 'Save deadline',
    ],

    'create' => [
        'title' => 'Assign Supplier Audit',
        'description' => 'One audit is created for each selected supplier. Suppliers that still have an active audit are skipped.',
        'suppliers_help' => 'Only active local suppliers are listed.',
        'period_help' => 'Example: 2026 Semester II.',
        'due_date_help' => 'Optional. After the deadline, the supplier cannot submit new invoices until the audit is submitted.',
        'template_label' => ':title (version :version)',
        'no_template' => 'No active checklist template is available. It is loaded automatically by php artisan migrate.',
        'search_placeholder' => 'Search supplier name or email',
        'no_suppliers' => 'No active local suppliers found.',
        'no_match' => 'No suppliers match the search.',
    ],

    'revision' => [
        'title' => 'Request revision',
        'help' => 'The supplier will see this note above the form.',
        'submit' => 'Send revision request',
    ],

    'cancel' => [
        'title' => 'Cancel audit',
        'help' => 'The supplier will see the reason. A cancelled audit cannot be reopened.',
    ],

    'upload' => [
        'title' => 'Assessment result',
        'help' => 'Uploading the first file publishes the result to the supplier.',
        'replace_help' => 'The supplier only sees the latest file. Earlier files stay in the history.',
        'accepted' => 'PDF, XLSX, JPG, or PNG. Maximum 10 MB.',
    ],

    'filters' => [
        'status_all' => 'All statuses',
        'period_all' => 'All periods',
        'supplier_all' => 'All suppliers',
        'late_only' => 'Overdue only',
    ],

    'export' => [
        'label' => 'Supplier Audit :supplier (:period)',
        'vendor_header' => 'Vendor/Supplier: :supplier — Period: :period',
        'sub_total' => 'Sub Total',
        'columns' => [
            'no' => 'No',
            'criterion' => 'Audit Criterion',
            'yes' => 'Yes',
            'no_answer' => 'No',
            'score' => 'Score',
            'point' => 'Point',
            'finding' => 'Finding/Notes',
        ],
    ],

    'notify' => [
        'assigned' => [
            'title' => 'New Supplier Audit',
            'body' => 'Purchasing assigned the Supplier Audit for period :period. Deadline: :date.',
        ],
        'revision_requested' => [
            'title' => 'Supplier Audit revision requested',
            'body' => 'Purchasing asked you to revise the Supplier Audit for period :period. Deadline: :date.',
        ],
        'result_published' => [
            'title' => 'Supplier Audit result published',
            'body' => 'The assessment result for period :period is available to download.',
        ],
        'cancelled' => [
            'title' => 'Supplier Audit cancelled',
            'body' => 'Purchasing cancelled the Supplier Audit for period :period. Reason: :reason',
        ],
        'deadline_changed' => [
            'title' => 'Supplier Audit deadline changed',
            'body' => 'The Supplier Audit deadline for period :period is now :date.',
        ],
        'deadline_removed' => [
            'title' => 'Supplier Audit deadline removed',
            'body' => 'The Supplier Audit for period :period no longer has a deadline.',
        ],
        'invoice_blocked' => [
            'title' => 'New invoices paused',
            'body' => 'The Supplier Audit for period :period passed its deadline (:date). Submit the audit form to submit new invoices again.',
        ],
        'submitted' => [
            'title' => 'Supplier Audit submitted',
            'body' => ':supplier submitted the Supplier Audit for period :period.',
        ],
    ],

    'badge' => [
        'pending' => '1 audit to complete',
    ],

    'deadline_relative' => [
        'overdue' => '{1} overdue by :count day|[2,*] overdue by :count days',
        'today' => 'due today',
        'remaining' => '{1} :count day left|[2,*] :count days left',
    ],

    'queues' => [
        'review' => 'To review',
        'waiting' => 'Waiting for supplier',
        'late' => 'Overdue',
        'done' => 'Finished',
        'all' => 'All',
        'label' => 'Audit queues',
        'empty_review' => 'No submitted audits to review.',
        'empty_review_hint' => 'Audits appear here once a supplier submits the form.',
        'empty_other' => 'No audits in this queue.',
        'view_waiting' => 'View audits waiting for suppliers',
    ],

    'columns' => [
        'progress' => 'Progress',
        'action' => 'Action',
    ],

    'row_actions' => [
        'review' => 'Review',
    ],

    'flow' => [
        'label' => 'Audit progress',
        'assigned' => 'Assigned',
        'filling' => 'Supplier filling',
        'revision' => 'Revision requested',
        'submitted' => 'Submitted',
        'published' => 'Result published',
    ],

    'next_step' => [
        'title' => 'Next step',
        'waiting' => 'Waiting for the supplier to complete the form.',
        'waiting_progress' => ':filled of :total criteria completed.',
        'export' => 'Export the answers to Excel',
        'assess' => 'Score and weight the answers offline',
        'upload' => 'Upload the assessment result',
        'published' => 'The result is published. The supplier can download the latest file.',
        'cancelled' => 'This audit was cancelled.',
        'more' => 'Other actions',
    ],

    'answer_filters' => [
        'label' => 'Filter answers',
        'all' => 'All',
        'no' => 'No',
        'low' => 'Score ≤ 2',
        'empty' => 'Not answered',
        'none_match' => 'No criteria match this filter.',
        'expand_all' => 'Expand all',
        'collapse_all' => 'Collapse all',
    ],

    'score_scale' => [
        'title' => 'Score guide',
        'intro' => 'Choose a score only when the answer is Yes.',
        '1' => 'Not in place / very weak',
        '2' => 'Weak',
        '3' => 'Adequate',
        '4' => 'Good',
        '5' => 'Very good / fully documented',
    ],

    'form' => [
        'sections' => 'Sections',
        'section_picker' => 'Section :current of :total',
        'jump_unanswered' => 'Jump to next unanswered',
        'all_complete' => 'All criteria are complete. You can submit.',
        'incomplete' => '{1} :count criterion is not complete yet.|[2,*] :count criteria are not complete yet.',
        'submit_summary' => 'Yes :yes · No :no. After submitting, the form is locked until Purchasing requests a revision.',
        'shortcuts_title' => 'Keyboard shortcuts',
        'shortcuts' => 'Y = Yes · T or N = No · 1–5 = Score · Enter or ↓ = next unanswered',
        'section_done' => 'complete',
        'section_partial' => 'in progress',
        'section_empty' => 'not started',
        'section_error' => 'needs attention',
    ],

    'autosave' => [
        'idle' => 'Changes are saved automatically',
        'saving' => 'Saving…',
        'saved' => 'Saved :time',
        'failed' => 'Could not save. Retrying…',
        'retry' => 'Retry now',
        'session_expired' => 'Your session ended. Reload the page to continue; unsaved answers stay on this page until then.',
        'reload' => 'Reload',
        'unsaved' => 'Some answers are not saved yet.',
    ],

    'create_extra' => [
        'preset_14' => '+14 days',
        'preset_30' => '+30 days',
        'preset_none' => 'No deadline',
        'last_audit' => 'Last audit: :value',
        'never_audited' => 'Never audited',
        'summary' => ':selected selected · :skipped skipped (active audit)',
    ],
];

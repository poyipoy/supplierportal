<?php

return [
    'title' => 'Supplier Performance Scorecard',
    'subtitle' => 'Automated KPI evaluation based on historical delivery, quality, and compliance records.',
    'no_data_subtitle' => 'No historical transactions recorded yet. Baseline score is shown until transactions complete.',
    'composite_score' => 'Composite Score',
    'grade_badge' => 'Grade :grade',
    'sample_ratio' => ':num of :den fulfilled',
    'no_samples' => 'No recorded transactions yet',
    'scopes' => [
        'import' => 'Import Procurement',
        'local' => 'Local Supplier',
    ],
    'metrics' => [
        'otd' => 'On-Time Delivery (OTD)',
        'quality' => 'Quality Acceptance (QC)',
        'responsiveness' => 'Quotation & PO Responsiveness',
        'wednesday_punctuality' => 'Wednesday Physical Delivery',
        'invoice_ftr' => 'Invoice First-Time-Right',
        'audit_score' => 'Latest Supplier Audit Score',
    ],
];

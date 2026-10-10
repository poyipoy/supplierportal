<?php

return [
    // Exact dashboard middleware audiences: admin has no blanket bypass.
    'audiences' => [
        'admin' => ['route' => 'admin.dashboard', 'roles' => ['admin'], 'context' => null, 'widgets' => [
            'admin.summary' => ['slot' => 'summary', 'label' => 'dashboard.widgets.operational_summary', 'required' => false],
            'admin.rates' => ['slot' => 'rates', 'label' => 'dashboard.widgets.exchange_rate_administration', 'required' => true],
            'admin.shortcuts' => ['slot' => 'shortcuts', 'label' => 'dashboard.widgets.administration_shortcuts', 'required' => false],
            'admin.notifications' => ['slot' => 'notifications', 'label' => 'dashboard.widgets.recent_administrative_activity', 'required' => false],
        ]],
        'purchasing' => ['route' => 'purchasing.dashboard', 'roles' => ['purchasing'], 'context' => null, 'widgets' => [
            'purchasing.exceptions' => ['slot' => 'exceptions', 'label' => 'dashboard.widgets.operational_action_queue', 'required' => true],
            'purchasing.metrics' => ['slot' => 'metrics', 'label' => 'dashboard.widgets.purchasing_metrics', 'required' => false],
            'purchasing.analytics' => ['slot' => 'analytics', 'label' => 'dashboard.widgets.charts_and_exchange_rate_benchmark', 'required' => false],
            'purchasing.recent_requisitions' => ['slot' => 'recent_requisitions', 'label' => 'dashboard.widgets.recent_requisitions', 'required' => false],
            'purchasing.arrivals' => ['slot' => 'arrivals', 'label' => 'dashboard.widgets.upcoming_po_arrivals', 'required' => true],
        ]],
        'finance' => ['route' => 'finance.dashboard', 'roles' => ['finance', 'admin'], 'context' => null, 'widgets' => [
            'finance.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.invoice_status_actions', 'required' => true],
            'finance.forecast' => ['slot' => 'forecast', 'label' => 'dashboard.widgets.payment_forecast', 'required' => true],
            'finance.batches' => ['slot' => 'batches', 'label' => 'dashboard.widgets.recent_payment_batches', 'required' => false],
            'finance.invoices' => ['slot' => 'invoices', 'label' => 'dashboard.widgets.recent_invoice_submissions', 'required' => false],
        ]],
        'accounting' => ['route' => 'accounting.dashboard', 'roles' => ['accounting', 'finance', 'admin'], 'context' => null, 'widgets' => [
            'accounting.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.invoice_status_actions', 'required' => true],
            'accounting.lifecycle' => ['slot' => 'lifecycle', 'label' => 'dashboard.widgets.invoice_lifecycle', 'required' => false],
            'accounting.invoices' => ['slot' => 'invoices', 'label' => 'dashboard.widgets.recent_submissions', 'required' => true],
        ]],
        'qc' => ['route' => 'qc.dashboard', 'roles' => ['qc'], 'context' => null, 'widgets' => [
            'qc.waiting' => ['slot' => 'waiting', 'label' => 'dashboard.widgets.waiting_inspection_action', 'required' => true],
            'qc.queue' => ['slot' => 'queue', 'label' => 'dashboard.widgets.inspection_activity_queue', 'required' => true],
            'qc.metrics' => ['slot' => 'metrics', 'label' => 'dashboard.widgets.inspection_metrics', 'required' => false],
            'qc.charts' => ['slot' => 'charts', 'label' => 'dashboard.widgets.quality_charts', 'required' => false],
        ]],
        'ga' => ['route' => 'ga.dashboard', 'roles' => ['ga', 'admin'], 'context' => null, 'widgets' => [
            'ga.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.claim_status_actions', 'required' => true],
            'ga.claims' => ['slot' => 'claims', 'label' => 'dashboard.widgets.recent_ga_claims', 'required' => false],
        ]],
        'supplier.import' => ['route' => 'supplier.dashboard', 'roles' => ['supplier'], 'context' => 'import', 'widgets' => [
            'supplier.import.metrics' => ['slot' => 'metrics', 'label' => 'dashboard.widgets.supplier_metrics', 'required' => false],
            'supplier.import.quotations' => ['slot' => 'quotations', 'label' => 'dashboard.widgets.outstanding_quotations', 'required' => true],
            'supplier.import.orders' => ['slot' => 'orders', 'label' => 'dashboard.widgets.purchase_orders_and_claim_responses', 'required' => true],
            'supplier.import.updates' => ['slot' => 'updates', 'label' => 'dashboard.widgets.announcements_and_purchasing_support', 'required' => false],
        ]],
        'supplier.local' => ['route' => 'local-supplier.dashboard', 'roles' => ['supplier'], 'context' => 'local', 'widgets' => [
            'supplier.local.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.invoice_status', 'required' => true],
            'supplier.local.company' => ['slot' => 'company', 'label' => 'dashboard.widgets.company_and_tax_summary', 'required' => false],
            'supplier.local.invoices' => ['slot' => 'invoices', 'label' => 'dashboard.widgets.recent_invoices', 'required' => true],
        ]],
    ],
];

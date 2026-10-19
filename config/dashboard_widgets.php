<?php

// `span` is the natural width on the 12-column desktop grid (one of DashboardWidgetService::SPANS);
// the width a card actually gets after hiding/reordering is derived by the smart-span rule.
// `kind` (metrics|chart|table|list|summary) only drives the wireframe icon and skeleton.
return [
    // Exact dashboard middleware audiences: admin has no blanket bypass.
    'audiences' => [
        'admin' => ['route' => 'admin.dashboard', 'roles' => ['admin'], 'context' => null, 'widgets' => [
            'admin.summary' => ['slot' => 'summary', 'label' => 'dashboard.widgets.operational_summary', 'required' => false, 'span' => 12, 'kind' => 'metrics'],
            'admin.rates' => ['slot' => 'rates', 'label' => 'dashboard.widgets.exchange_rate_administration', 'required' => true, 'span' => 8, 'kind' => 'table'],
            'admin.shortcuts' => ['slot' => 'shortcuts', 'label' => 'dashboard.widgets.administration_shortcuts', 'required' => false, 'span' => 4, 'kind' => 'list'],
            'admin.notifications' => ['slot' => 'notifications', 'label' => 'dashboard.widgets.recent_administrative_activity', 'required' => false, 'span' => 12, 'kind' => 'list'],
        ]],
        'purchasing' => ['route' => 'purchasing.dashboard', 'roles' => ['purchasing'], 'context' => null, 'widgets' => [
            'purchasing.exceptions' => ['slot' => 'exceptions', 'label' => 'dashboard.widgets.operational_action_queue', 'required' => true, 'span' => 12, 'kind' => 'list'],
            'purchasing.metrics' => ['slot' => 'metrics', 'label' => 'dashboard.widgets.purchasing_metrics', 'required' => false, 'span' => 12, 'kind' => 'metrics'],
            'purchasing.analytics' => ['slot' => 'analytics', 'label' => 'dashboard.widgets.charts_and_exchange_rate_benchmark', 'required' => false, 'span' => 12, 'kind' => 'chart'],
            'purchasing.recent_requisitions' => ['slot' => 'recent_requisitions', 'label' => 'dashboard.widgets.recent_requisitions', 'required' => false, 'span' => 5, 'kind' => 'table'],
            'purchasing.arrivals' => ['slot' => 'arrivals', 'label' => 'dashboard.widgets.upcoming_po_arrivals', 'required' => true, 'span' => 7, 'kind' => 'table'],
        ]],
        'finance' => ['route' => 'finance.dashboard', 'roles' => ['finance', 'admin'], 'context' => null, 'widgets' => [
            'finance.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.invoice_status_actions', 'required' => true, 'span' => 12, 'kind' => 'metrics'],
            'finance.forecast' => ['slot' => 'forecast', 'label' => 'dashboard.widgets.payment_forecast', 'required' => true, 'span' => 12, 'kind' => 'chart'],
            'finance.batches' => ['slot' => 'batches', 'label' => 'dashboard.widgets.recent_payment_batches', 'required' => false, 'span' => 12, 'kind' => 'table'],
            'finance.invoices' => ['slot' => 'invoices', 'label' => 'dashboard.widgets.recent_invoice_submissions', 'required' => false, 'span' => 12, 'kind' => 'table'],
        ]],
        'accounting' => ['route' => 'accounting.dashboard', 'roles' => ['accounting', 'finance', 'admin'], 'context' => null, 'widgets' => [
            'accounting.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.invoice_status_actions', 'required' => true, 'span' => 12, 'kind' => 'metrics'],
            'accounting.lifecycle' => ['slot' => 'lifecycle', 'label' => 'dashboard.widgets.invoice_lifecycle', 'required' => false, 'span' => 12, 'kind' => 'list'],
            'accounting.invoices' => ['slot' => 'invoices', 'label' => 'dashboard.widgets.recent_submissions', 'required' => true, 'span' => 12, 'kind' => 'table'],
        ]],
        'qc' => ['route' => 'qc.dashboard', 'roles' => ['qc'], 'context' => null, 'widgets' => [
            'qc.waiting' => ['slot' => 'waiting', 'label' => 'dashboard.widgets.waiting_inspection_action', 'required' => true, 'span' => 12, 'kind' => 'list'],
            'qc.queue' => ['slot' => 'queue', 'label' => 'dashboard.widgets.inspection_activity_queue', 'required' => true, 'span' => 12, 'kind' => 'table'],
            'qc.metrics' => ['slot' => 'metrics', 'label' => 'dashboard.widgets.inspection_metrics', 'required' => false, 'span' => 12, 'kind' => 'metrics'],
            'qc.charts' => ['slot' => 'charts', 'label' => 'dashboard.widgets.quality_charts', 'required' => false, 'span' => 12, 'kind' => 'chart'],
        ]],
        'ga' => ['route' => 'ga.dashboard', 'roles' => ['ga', 'admin'], 'context' => null, 'widgets' => [
            'ga.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.claim_status_actions', 'required' => true, 'span' => 12, 'kind' => 'metrics'],
            'ga.claims' => ['slot' => 'claims', 'label' => 'dashboard.widgets.recent_ga_claims', 'required' => false, 'span' => 12, 'kind' => 'table'],
        ]],
        'supplier.import' => ['route' => 'supplier.dashboard', 'roles' => ['supplier'], 'context' => 'import', 'widgets' => [
            'supplier.import.metrics' => ['slot' => 'metrics', 'label' => 'dashboard.widgets.supplier_metrics', 'required' => false, 'span' => 12, 'kind' => 'metrics'],
            'supplier.import.quotations' => ['slot' => 'quotations', 'label' => 'dashboard.widgets.outstanding_quotations', 'required' => true, 'span' => 12, 'kind' => 'table'],
            'supplier.import.orders' => ['slot' => 'orders', 'label' => 'dashboard.widgets.purchase_orders_and_claim_responses', 'required' => true, 'span' => 8, 'kind' => 'table'],
            'supplier.import.updates' => ['slot' => 'updates', 'label' => 'dashboard.widgets.announcements_and_purchasing_support', 'required' => false, 'span' => 4, 'kind' => 'list'],
        ]],
        'supplier.local' => ['route' => 'local-supplier.dashboard', 'roles' => ['supplier'], 'context' => 'local', 'widgets' => [
            'supplier.local.statuses' => ['slot' => 'statuses', 'label' => 'dashboard.widgets.invoice_status', 'required' => true, 'span' => 12, 'kind' => 'metrics'],
            'supplier.local.company' => ['slot' => 'company', 'label' => 'dashboard.widgets.company_and_tax_summary', 'required' => false, 'span' => 12, 'kind' => 'summary'],
            'supplier.local.invoices' => ['slot' => 'invoices', 'label' => 'dashboard.widgets.recent_invoices', 'required' => true, 'span' => 12, 'kind' => 'table'],
        ]],
    ],
];

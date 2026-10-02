<?php

return [
    // Exact dashboard middleware audiences: admin has no blanket bypass.
    'audiences' => [
        'admin' => ['route' => 'admin.dashboard', 'roles' => ['admin'], 'context' => null, 'widgets' => [
            'admin.rates' => ['slot' => 'rates', 'label' => 'Exchange-rate administration', 'required' => true],
            'admin.notifications' => ['slot' => 'notifications', 'label' => 'Recent administrative activity', 'required' => false],
            'admin.shortcuts' => ['slot' => 'shortcuts', 'label' => 'Administration shortcuts', 'required' => false],
            'admin.summary' => ['slot' => 'summary', 'label' => 'Operational summary', 'required' => false],
        ]],
        'purchasing' => ['route' => 'purchasing.dashboard', 'roles' => ['purchasing'], 'context' => null, 'widgets' => [
            'purchasing.exceptions' => ['slot' => 'exceptions', 'label' => 'Operational action queue', 'required' => true],
            'purchasing.metrics' => ['slot' => 'metrics', 'label' => 'Purchasing metrics', 'required' => false],
            'purchasing.analytics' => ['slot' => 'analytics', 'label' => 'Charts and exchange-rate benchmark', 'required' => false],
            'purchasing.recent_requisitions' => ['slot' => 'recent_requisitions', 'label' => 'Recent requisitions', 'required' => false],
            'purchasing.arrivals' => ['slot' => 'arrivals', 'label' => 'Upcoming PO arrivals', 'required' => true],
        ]],
        'finance' => ['route' => 'finance.dashboard', 'roles' => ['finance', 'admin'], 'context' => null, 'widgets' => [
            'finance.statuses' => ['slot' => 'statuses', 'label' => 'Invoice status actions', 'required' => true],
            'finance.forecast' => ['slot' => 'forecast', 'label' => 'Payment forecast', 'required' => true],
            'finance.batches' => ['slot' => 'batches', 'label' => 'Recent payment batches', 'required' => false],
            'finance.invoices' => ['slot' => 'invoices', 'label' => 'Recent invoice submissions', 'required' => false],
        ]],
        'accounting' => ['route' => 'accounting.dashboard', 'roles' => ['accounting', 'finance', 'admin'], 'context' => null, 'widgets' => [
            'accounting.statuses' => ['slot' => 'statuses', 'label' => 'Invoice status actions', 'required' => true],
            'accounting.lifecycle' => ['slot' => 'lifecycle', 'label' => 'Invoice lifecycle', 'required' => false],
            'accounting.invoices' => ['slot' => 'invoices', 'label' => 'Recent submissions', 'required' => true],
        ]],
        'qc' => ['route' => 'qc.dashboard', 'roles' => ['qc'], 'context' => null, 'widgets' => [
            'qc.waiting' => ['slot' => 'waiting', 'label' => 'Waiting inspection action', 'required' => true],
            'qc.queue' => ['slot' => 'queue', 'label' => 'Inspection activity queue', 'required' => true],
            'qc.metrics' => ['slot' => 'metrics', 'label' => 'Inspection metrics', 'required' => false],
            'qc.charts' => ['slot' => 'charts', 'label' => 'Quality charts', 'required' => false],
        ]],
        'ga' => ['route' => 'ga.dashboard', 'roles' => ['ga', 'admin'], 'context' => null, 'widgets' => [
            'ga.statuses' => ['slot' => 'statuses', 'label' => 'Claim status actions', 'required' => true],
            'ga.claims' => ['slot' => 'claims', 'label' => 'Daftar klaim GA terbaru', 'required' => false],
        ]],
        'supplier.import' => ['route' => 'supplier.dashboard', 'roles' => ['supplier'], 'context' => 'import', 'widgets' => [
            'supplier.import.quotations' => ['slot' => 'quotations', 'label' => 'Outstanding quotations', 'required' => true],
            'supplier.import.metrics' => ['slot' => 'metrics', 'label' => 'Supplier metrics', 'required' => false],
            'supplier.import.orders' => ['slot' => 'orders', 'label' => 'Purchase orders and claim responses', 'required' => true],
            'supplier.import.updates' => ['slot' => 'updates', 'label' => 'Announcements and purchasing support', 'required' => false],
        ]],
        'supplier.local' => ['route' => 'local-supplier.dashboard', 'roles' => ['supplier'], 'context' => 'local', 'widgets' => [
            'supplier.local.statuses' => ['slot' => 'statuses', 'label' => 'Status invoice', 'required' => true],
            'supplier.local.company' => ['slot' => 'company', 'label' => 'Ringkasan perusahaan dan pajak', 'required' => false],
            'supplier.local.invoices' => ['slot' => 'invoices', 'label' => 'Invoice terbaru', 'required' => true],
        ]],
    ],
];

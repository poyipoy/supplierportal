<?php

return [
    'title' => 'Scorecard Kinerja Supplier',
    'subtitle' => 'Evaluasi KPI otomatis berdasarkan riwayat ketepatan pengiriman, kualitas, dan kepatuhan dokumen.',
    'no_data_subtitle' => 'Belum ada riwayat transaksi tercatat. Skor dasar ditampilkan hingga transaksi selesai.',
    'composite_score' => 'Skor Komposit',
    'grade_badge' => 'Grade :grade',
    'sample_ratio' => ':num dari :den terpenuhi',
    'no_samples' => 'Belum ada transaksi tercatat',
    'scopes' => [
        'import' => 'Pengadaan Impor',
        'local' => 'Local Supplier',
    ],
    'metrics' => [
        'otd' => 'Ketepatan Pengiriman (OTD)',
        'quality' => 'Penerimaan Kualitas (QC)',
        'responsiveness' => 'Responsivitas Penawaran & PO',
        'wednesday_punctuality' => 'Ketepatan Berkas Hari Rabu',
        'invoice_ftr' => 'Invoice Tepat Sejak Awal (FTR)',
        'audit_score' => 'Skor Audit Supplier Terakhir',
    ],
];

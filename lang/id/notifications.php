<?php

return [
    'registration' => [
        'submitted' => ['title' => 'Pendaftaran Pemasok Baru', 'message' => 'Pemasok :company telah mengajukan pendaftaran :reference.'],
        'resubmitted' => ['title' => 'Pendaftaran Pemasok Diajukan Ulang', 'message' => 'Pemasok :company telah mengajukan ulang pendaftaran dengan revisi.'],
        'revision_requested' => ['title' => 'Revisi Pendaftaran Pemasok Diminta', 'message' => 'Revisi pendaftaran pemasok :company diminta oleh :reviewer.'],
        'rejected' => ['title' => 'Pendaftaran Pemasok Ditolak', 'message' => 'Pendaftaran pemasok :company ditolak oleh :reviewer.'],
        'approved' => ['title' => 'Pendaftaran Pemasok Disetujui', 'message' => 'Pemasok :company telah disetujui dengan cakupan portal: :scopes.'],
        'scopes' => ['import' => 'Pengadaan Material', 'local' => 'Pemasok Lokal', 'both' => 'Pengadaan Material, Pemasok Lokal'],
    ],

    'feedback' => ['all_read' => 'Semua notifikasi telah ditandai sebagai dibaca.', 'category_read' => 'Notifikasi kategori :category telah ditandai sebagai dibaca.', 'saved' => 'Preferensi notifikasi disimpan.', 'reset' => 'Preferensi notifikasi dikembalikan ke pengaturan awal.'],
    'conversation' => [
        'muted' => 'Notifikasi percakapan dibisukan.',
        'unmuted' => 'Notifikasi percakapan diaktifkan kembali.',
        'revise_unavailable' => 'Mohon revisi penawaran untuk PR :pr_number karena semua item ditandai tidak tersedia.',
        'revise_expired' => 'Mohon revisi penawaran untuk PR :pr_number karena masa berlaku penawaran telah berakhir.',
        'with_revision_note' => ":message\n\nCatatan revisi: :note",
        'extend_validity' => 'Mohon perpanjang masa berlaku penawaran untuk PR :pr_number.',
        'confirm_delivery' => 'Mohon konfirmasi estimasi pengiriman terbaru untuk PR :pr_number.',
        'revise_price' => "Mohon revisi harga penawaran untuk PR :pr_number.\n\nCatatan revisi: :note",
        'rejected_with_note' => "Penawaran untuk PR :pr_number ditolak oleh Pengadaan.\n\nCatatan: :note",
        'with_note' => ":message\n\nCatatan: :note",
        'empty_message' => 'Masukkan pesan atau lampirkan setidaknya satu berkas.',
        'purchasing_only' => 'Hanya Pengadaan yang dapat menjalankan tindakan negosiasi.',
        'no_quotation' => 'Tidak ditemukan penawaran terkait percakapan ini.',
        'note_required' => 'Catatan wajib diisi untuk tindakan ini.',
        'quotation_in_use' => 'Penawaran ini telah digunakan dalam alokasi atau PO dan tidak dapat diubah.',
        'no_messages' => 'Belum ada pesan',
        'user' => 'Pengguna',
        'completed_pr' => 'PR telah selesai. Revisi penawaran tidak dapat diminta.',
        'revision_ineligible' => 'Revisi hanya dapat diminta untuk penawaran yang telah diajukan dan belum digunakan untuk membuat PO.',
        'cannot_accept' => 'Penawaran ini tidak dapat diterima.',
        'all_unavailable' => 'Penawaran ini tidak dapat diterima karena supplier menandai semua item sebagai tidak tersedia.',
        'expired' => 'Penawaran ini telah kedaluwarsa. Minta supplier mengajukan revisi sebelum menerimanya.',
        'cannot_reject' => 'Penawaran ini tidak dapat ditolak.',
        'no_available_items' => 'Penawaran tanpa item tersedia tidak dapat ditolak.',
    ],
    'validation' => ['available_boolean' => 'Pilih hanya notifikasi yang tersedia untuk akun Anda dengan nilai aktif atau nonaktif.', 'unsupported_field' => 'Kolom ini tidak didukung oleh preferensi notifikasi.'],
    'exports' => [
        'completed' => [
            'title' => 'Ekspor Selesai',
            'message' => 'Ekspor :label siap diunduh.',
        ],
        'failed' => [
            'title' => 'Ekspor Gagal',
            'message' => 'Ekspor tidak dapat diproses. Silakan coba lagi.',
        ],
    ],
    'preferences.title' => 'Notifikasi - ADASI Supplier Portal',
    'preferences.description' => 'Pilih notifikasi dalam aplikasi yang ingin Anda terima.',
    'preferences.review' => 'Periksa preferensi notifikasi Anda',
    'preferences.settings' => 'Pengaturan notifikasi',
    'preferences.no_settings' => 'Tidak ada pengaturan notifikasi untuk akun Anda.',
    'preferences.search' => 'Cari notifikasi...',
    'preferences.filter' => 'Filter notifikasi',
    'preferences.all' => 'Semua',
    'preferences.enabled' => 'Aktif',
    'preferences.muted' => 'Dinonaktifkan',
    'preferences.silent' => 'Senyap',
    'preferences.presets' => 'Preset:',
    'preferences.everything' => 'Semuanya',
    'preferences.action_only' => 'Hanya yang memerlukan tindakan',
    'preferences.quiet' => 'Mode senyap',
    'preferences.collapse_all' => 'Ciutkan semua',
    'preferences.expand_all' => 'Perluas semua',
    'preferences.panel' => 'Preferensi notifikasi',
    'preferences.all_on' => 'Aktifkan semua',
    'preferences.all_off' => 'Nonaktifkan semua',
    'preferences.action' => 'Perlu tindakan',
    'preferences.warning' => 'Notifikasi ini memerlukan tindakan Anda. Menonaktifkannya dapat membuat tugas tertunda terlewat.',
    'preferences.normal' => 'Normal — pop-up + kotak masuk',
    'preferences.inbox_only' => 'Senyap — hanya kotak masuk',
    'preferences.empty' => 'Preferensi notifikasi tidak ditemukan',
    'preferences.empty_help' => 'Tidak ada pengaturan notifikasi yang sesuai dengan pencarian dan filter Anda.',
    'preferences.clear_filters' => 'Kosongkan filter',
    'preferences.no_changes' => 'Tidak ada perubahan belum disimpan',
    'preferences.discard' => 'Batalkan',
    'preferences.reset' => 'Atur ulang ke default',
    'preferences.reset_title' => 'Atur ulang preferensi notifikasi',
    'preferences.reset_help' => 'Semua pengaturan notifikasi kembali ke default dan perubahan belum disimpan di halaman ini akan dibatalkan.',
    'preferences.on' => 'Aktif',
    'preferences.off' => 'Nonaktif',
    'preferences.delivery' => 'Mode pengiriman :label',
    'preferences.category_summary' => ':on dari :total aktif',
    'preferences.summary' => ':enabled dari :total aktif · :silent senyap · :off nonaktif',
    'preferences.unsaved' => ':count perubahan belum disimpan',
    'preferences.search_label' => 'Cari preferensi notifikasi',
    'preferences.scope' => 'Scope notifikasi portal supplier',
    'preferences.heading' => 'Preferensi',
    'events' => [
        'pr_submitted' => [
            'label' => 'Permintaan pembelian diajukan',
            'description' => 'Permintaan pembelian diajukan untuk diperiksa.',
        ],
        'quotation_submitted' => [
            'label' => 'Penawaran diajukan',
            'description' => 'Pemasok mengajukan penawaran.',
        ],
        'quotation_revised' => [
            'label' => 'Revisi penawaran diajukan',
            'description' => 'Pemasok mengajukan kembali penawaran yang direvisi.',
        ],
        'quotation_accepted' => [
            'label' => 'Penawaran diterima',
            'description' => 'Pengadaan menerima penawaran Anda.',
        ],
        'quotation_rejected' => [
            'label' => 'Penawaran ditolak',
            'description' => 'Pengadaan menolak penawaran Anda.',
        ],
        'quotation_revision_requested' => [
            'label' => 'Revisi penawaran diminta',
            'description' => 'Pengadaan meminta Anda merevisi penawaran.',
        ],
        'quotation_negotiation_message' => [
            'label' => 'Permintaan negosiasi',
            'description' => 'Pengadaan mengirim permintaan negosiasi terkait penawaran Anda.',
        ],
        'conversation_message_created' => [
            'label' => 'Pesan percakapan',
            'description' => 'Mitra percakapan mengirim pesan atau lampiran.',
        ],
        'po_issued' => [
            'label' => 'Pesanan pembelian diterbitkan',
            'description' => 'Pesanan pembelian diterbitkan untuk item yang diberikan kepada Anda.',
        ],
        'document_status_updated' => [
            'label' => 'Status dokumen impor diperbarui',
            'description' => 'Status dokumen impor berubah pada PO yang Anda buat.',
        ],
        'document_all_completed' => [
            'label' => 'Dokumen impor lengkap',
            'description' => 'Semua dokumen impor wajib pada PO yang Anda buat telah lengkap.',
        ],
        'po_item_progress_updated' => [
            'label' => 'Progres material diperbarui',
            'description' => 'Pemasok memperbarui progres material atau estimasi kesiapan.',
        ],
        'shipment_submitted' => [
            'label' => 'Pengiriman diajukan',
            'description' => 'Pemasok mengajukan pengiriman.',
        ],
        'po_material_arrived' => [
            'label' => 'Material siap untuk QC',
            'description' => 'Material tiba dan siap diperiksa mutunya.',
        ],
        'qc_inspection_ok' => [
            'label' => 'Inspeksi QC lulus',
            'description' => 'Material lulus inspeksi mutu.',
        ],
        'qc_inspection_ng' => [
            'label' => 'Inspeksi QC tidak lulus',
            'description' => 'Inspeksi mutu menemukan material yang memerlukan klaim.',
        ],
        'claim_created' => [
            'label' => 'Klaim material dibuat',
            'description' => 'Pengadaan membuat klaim untuk material Anda.',
        ],
        'claim_responded' => [
            'label' => 'Tanggapan klaim material',
            'description' => 'Pemasok menanggapi klaim material.',
        ],
        'claim_resolved' => [
            'label' => 'Klaim material diselesaikan',
            'description' => 'Pengadaan menyelesaikan klaim material Anda.',
        ],
        'local_invoice_submitted' => [
            'label' => 'Invoice diajukan',
            'description' => 'Pengajuan invoice baru diterima oleh sistem.',
        ],
        'local_invoice_resubmitted' => [
            'label' => 'Invoice diajukan ulang',
            'description' => 'Revisi invoice diajukan ulang untuk verifikasi.',
        ],
        'local_invoice_cancelled' => [
            'label' => 'Invoice dibatalkan',
            'description' => 'Pengajuan invoice Anda dibatalkan.',
        ],
        'local_invoice_physical_received' => [
            'label' => 'Berkas fisik diterima',
            'description' => 'Kasir mencatat penerimaan berkas fisik invoice Anda.',
        ],
        'local_invoice_approved' => [
            'label' => 'Invoice siap dibayar',
            'description' => 'Invoice Anda disetujui dan berstatus Siap Dibayar.',
        ],
        'local_invoice_revision_requested' => [
            'label' => 'Revisi invoice diminta',
            'description' => 'Keuangan meminta revisi invoice Anda.',
        ],
        'local_invoice_rejected' => [
            'label' => 'Invoice ditolak',
            'description' => 'Keuangan menolak pengajuan invoice Anda.',
        ],
        'local_invoice_partial_payment' => [
            'label' => 'Koreksi pembayaran diperlukan',
            'description' => 'Transfer aktual belum memenuhi nilai settlement invoice Anda.',
        ],
        'local_invoice_paid' => [
            'label' => 'Invoice dibayar',
            'description' => 'Settlement pembayaran invoice Anda selesai.',
        ],
        'local_invoice_overpaid' => [
            'label' => 'Kelebihan bayar tercatat',
            'description' => 'Kelebihan pembayaran invoice Anda dicatat sebagai pengembalian dana.',
        ],
        'local_invoice_refund_settled' => [
            'label' => 'Pengembalian dana selesai',
            'description' => 'Pengembalian kelebihan pembayaran Anda dicatat selesai.',
        ],
        'local_invoice_physical_delivery_reminder' => [
            'label' => 'Pengingat pengiriman berkas',
            'description' => 'Jadwal penyerahan berkas fisik invoice Anda sudah dekat.',
        ],
        'supplier_registration_submitted' => [
            'label' => 'Pendaftaran supplier diajukan',
            'description' => 'Pemasok mengajukan pendaftaran baru.',
        ],
        'supplier_registration_resubmitted' => [
            'label' => 'Pendaftaran supplier diajukan ulang',
            'description' => 'Pemasok mengajukan kembali pendaftaran yang direvisi.',
        ],
        'supplier_registration_revision_requested' => [
            'label' => 'Revisi pendaftaran diminta',
            'description' => 'Peninjau meminta perubahan pendaftaran pemasok.',
        ],
        'supplier_registration_rejected' => [
            'label' => 'Pendaftaran supplier ditolak',
            'description' => 'Peninjau menolak pendaftaran pemasok.',
        ],
        'supplier_registration_approved' => [
            'label' => 'Pendaftaran supplier disetujui',
            'description' => 'Peninjau menyetujui pendaftaran pemasok.',
        ],
        'export_completed' => [
            'label' => 'Ekspor selesai',
            'description' => 'Ekspor Anda siap diunduh.',
        ],
        'export_failed' => [
            'label' => 'Ekspor gagal',
            'description' => 'Ekspor Anda tidak dapat diselesaikan.',
        ],
        'new_device_login' => [
            'label' => 'Login dari perangkat baru',
            'description' => 'Akun Anda login dari perangkat baru.',
        ],
        'repeated_lockouts_detected' => [
            'label' => 'Pemblokiran login berulang',
            'description' => 'Akun mencapai batas pemblokiran login berulang.',
        ],
    ],
    'categories' => [
        'requisitions' => 'Permintaan pembelian',
        'quotations' => 'Penawaran',
        'conversations' => 'Percakapan',
        'purchase_orders' => 'Pesanan pembelian',
        'documents' => 'Dokumen',
        'shipments_qc' => 'Pengiriman dan QC',
        'claims' => 'Klaim material',
        'local_invoices' => 'Invoice lokal',
        'registration' => 'Pendaftaran supplier',
        'exports' => 'Ekspor',
        'security' => 'Keamanan',
    ],
    'invoice' => [
        'events' => [
            'submitted' => [
                'title' => 'Invoice diajukan',
                'message' => ':submission - Invoice :invoice menunggu dokumen fisik.',
            ],
            'resubmitted' => [
                'title' => 'Invoice diajukan ulang',
                'message' => ':submission - Revisi invoice :invoice menunggu dokumen fisik.',
            ],
            'cancelled' => [
                'title' => 'Invoice dibatalkan',
                'message' => ':submission - Invoice :invoice dibatalkan. :reason',
            ],
            'physical_received' => [
                'title' => 'Berkas fisik diterima',
                'message' => ':submission - Berkas fisik invoice :invoice telah diterima. Termin pembayaran: :payment_term hari. Jatuh tempo: :due_date. :raw_notes',
            ],
            'approved' => [
                'title' => 'Invoice siap dibayar',
                'message' => ':submission - Invoice :invoice disetujui dan siap dibayar. Jatuh tempo: :due_date.',
            ],
            'revision_requested' => [
                'title' => 'Revisi invoice diminta',
                'message' => ':submission - Mohon revisi invoice :invoice. :reason',
            ],
            'rejected' => [
                'title' => 'Invoice ditolak',
                'message' => ':submission - Invoice :invoice ditolak. :reason',
            ],
            'partial_payment' => [
                'title' => 'Koreksi pembayaran diperlukan',
                'message' => ':submission - Invoice :invoice memerlukan koreksi pembayaran. Aktual: :actual. Seharusnya: :expected. Kekurangan: :remaining. Referensi transfer: :reference. :reason',
            ],
            'paid' => [
                'title' => 'Invoice dibayar',
                'message' => ':submission - Penyelesaian pembayaran invoice :invoice selesai. Nilai: :amount. Referensi transfer: :reference.',
            ],
            'overpaid' => [
                'title' => 'Kelebihan bayar tercatat',
                'message' => ':submission - Lebih bayar invoice :invoice dicatat untuk pengembalian dana. Nilai: :amount.',
            ],
            'refund_settled' => [
                'title' => 'Pengembalian dana selesai',
                'message' => ':submission - Pengembalian dana invoice :invoice selesai. Nilai: :amount. Referensi: :reference. :raw_notes',
            ],
            'physical_delivery_reminder' => [
                'title' => 'Pengingat penyerahan berkas fisik',
                'message' => ':submission - Jadwal penyerahan berkas fisik invoice :invoice sudah dekat.',
            ],
            'delivery_missed' => [
                'title' => 'Penyerahan berkas terlewat',
                'message' => ':submission - Jadwal penyerahan berkas fisik invoice :invoice terlewat.',
            ],
            'expired' => [
                'title' => 'Invoice kedaluwarsa',
                'message' => ':submission - Invoice :invoice telah kedaluwarsa.',
            ],
            'rescheduled' => [
                'title' => 'Penyerahan berkas dijadwalkan ulang',
                'message' => ':submission - Jadwal penyerahan berkas fisik invoice :invoice berubah.',
            ],
            'physical_verified' => [
                'title' => 'Dokumen fisik terverifikasi',
                'message' => ':submission - Dokumen fisik invoice :invoice telah diverifikasi.',
            ],
            'payment_scheduled' => [
                'title' => 'Pembayaran dijadwalkan',
                'message' => ':submission - Pembayaran invoice :invoice telah dijadwalkan.',
            ],
            'completed' => [
                'title' => 'Pembayaran selesai',
                'message' => ':submission - Pembayaran invoice :invoice selesai.',
            ],
        ],
        'updated' => [
            'title' => 'Invoice diperbarui',
            'message' => ':submission - Invoice :invoice telah diperbarui.',
        ],
        'confirmation' => [
            'message' => ':submission - :invoice',
        ],
    ],
    'security' => [
        'new_device' => [
            'title' => 'Login baru terdeteksi',
            'message' => 'Akun Anda login dari perangkat yang belum pernah digunakan untuk akun ini.',
        ],
        'lockouts' => [
            'title' => 'Pemblokiran login berulang terdeteksi',
            'message' => 'Akun ":account" dibatasi sebanyak :count kali dalam satu jam terakhir.',
        ],
    ],
    'center' => [
        'all' => 'Semua',
        'all_description' => 'Semua notifikasi',
        'chat' => 'Percakapan',
        'chat_description' => 'Pesan negosiasi',
        'quotation' => 'Penawaran',
        'quotation_description' => 'PR dan penawaran',
        'documents' => 'Dokumen PO',
        'document' => 'Dokumen',
        'document_description' => 'Status dokumen impor',
        'invoice' => 'Invoice',
        'invoice_description' => 'Pembaruan invoice lokal',
        'other' => 'Lainnya',
        'other_description' => 'Informasi sistem lainnya',
    ],
    'chat' => [
        'message_title' => 'Pesan baru dari :sender',
        'message_body' => ':preview',
        'attachment_body' => 'Mengirim lampiran dalam percakapan.',
    ],
    'negotiation' => [
        'revision' => [
            'title' => 'Revisi Penawaran Diminta',
            'message' => 'Pengadaan meminta revisi penawaran untuk PR :pr_number.',
        ],
        'accepted' => [
            'title' => 'Penawaran Diterima',
            'message' => 'Penawaran untuk PR :pr_number telah diterima Pengadaan.',
        ],
        'rejected' => [
            'title' => 'Penawaran Ditolak',
            'message' => 'Penawaran untuk PR :pr_number ditolak Pengadaan.',
        ],
        'message' => [
            'title' => 'Pesan Negosiasi Baru',
            'message' => 'Pengadaan mengirim pesan negosiasi untuk PR :pr_number.',
        ],
    ],
];

<?php

return [
    'title' => 'Supplier Audit',
    'description' => 'Checklist audit supplier dan vendor ISO 14001, ISO 9001 & AGC AFC.',
    'eyebrow' => 'Local Supplier',
    'purchasing_description' => 'Tugaskan checklist audit ke supplier local, tinjau jawaban yang disubmit, dan terbitkan hasil penilaian.',
    'supplier_description' => 'Isi checklist audit yang ditugaskan Purchasing dan unduh hasil penilaiannya.',

    'empty' => [
        'no_access_title' => 'Belum ada Supplier Audit',
        'no_access' => 'Menu Supplier Audit belum diberi akses oleh Purchasing.',
        'history' => 'Belum ada audit sebelumnya.',
        'purchasing' => 'Tidak ada Supplier Audit yang sesuai filter.',
    ],

    'fields' => [
        'supplier' => 'Supplier',
        'suppliers' => 'Supplier',
        'period' => 'Periode',
        'due_date' => 'Deadline',
        'status' => 'Status',
        'submitted_at' => 'Disubmit',
        'assigned_at' => 'Ditugaskan',
        'assigned_by' => 'Ditugaskan oleh',
        'template' => 'Template checklist',
        'answer' => 'Jawaban',
        'score' => 'Score',
        'yes' => 'Ya',
        'no' => 'Tidak',
        'criterion' => 'Kriteria Audit',
        'section' => 'Bagian',
        'number' => 'No.',
        'criterion_ref' => 'Bagian :section no. :number',
        'revision_note' => 'Catatan revisi',
        'cancel_reason' => 'Alasan pembatalan',
        'result_file' => 'File hasil penilaian',
        'replace_reason' => 'Alasan penggantian',
        'progress' => 'Progres',
        'no_deadline' => 'Tanpa deadline',
    ],

    'labels' => [
        'late' => 'Terlambat',
        'progress' => ':filled dari :total terisi',
        'latest_file' => 'Terbaru',
        'score_hint' => 'Score aktif bila jawaban Ya.',
        'score_option' => 'Score :score dari 5',
        'history' => 'Riwayat status',
        'results' => 'Hasil penilaian',
        'summary' => 'Ringkasan jawaban',
        'summary_help' => 'Hanya hitungan. Penilaian dan bobot dilakukan Purchasing secara offline.',
        'active_audit' => 'Audit saat ini',
        'active_status' => 'Audit aktif: :status',
        'answers' => 'Jawaban',
        'empty_answer' => 'Belum dijawab',
        'uploaded_by' => 'Diunggah oleh :name',
        'step' => 'Langkah :current dari :total',
        'audit_history' => 'Riwayat audit',
        'locked' => 'Form ini terkunci karena sudah disubmit.',
        'yes_count' => 'Ya',
        'no_count' => 'Tidak',
        'empty_count' => 'Kosong',
        'result_pending' => 'Hasil penilaian belum diterbitkan.',
        'cancelled_note' => 'Audit ini dibatalkan oleh Purchasing.',
        'selected_count' => ':count dipilih',
    ],

    'actions' => [
        'assign' => 'Tugaskan Audit',
        'fill' => 'Isi Form',
        'continue' => 'Lanjutkan',
        'view' => 'Lihat',
        'save_draft' => 'Simpan Draft',
        'submit' => 'Submit',
        'export' => 'Export Excel',
        'request_revision' => 'Minta Revisi',
        'cancel' => 'Batalkan Audit',
        'upload_result' => 'Upload Hasil',
        'replace_result' => 'Ganti Hasil',
        'download_result' => 'Download Hasil',
        'change_deadline' => 'Ubah Deadline',
        'back' => 'Kembali',
        'next' => 'Lanjut',
        'previous' => 'Sebelumnya',
        'filter' => 'Filter',
        'reset' => 'Reset',
        'close' => 'Tutup',
        'back_to_list' => 'Kembali ke daftar',
        'select_all' => 'Pilih semua yang tersedia',
        'clear' => 'Kosongkan',
    ],

    'confirm' => [
        'submit_title' => 'Submit form audit?',
        'submit_text' => 'Setelah disubmit, form terkunci sampai Purchasing meminta revisi.',
        'submit_confirm' => 'Ya, submit',
        'cancel_title' => 'Batalkan audit ini?',
        'cancel_text' => 'Supplier tidak bisa lagi mengisi audit ini.',
        'cancel_confirm' => 'Batalkan audit',
    ],

    'flash' => [
        'assigned' => 'Supplier Audit ditugaskan ke :count supplier.',
        'rejected' => 'Tidak ditugaskan: :list.',
        'draft_saved' => 'Draft tersimpan.',
        'submitted' => 'Supplier Audit disubmit. Purchasing sudah diberi tahu.',
        'revision_requested' => 'Revisi diminta ke supplier.',
        'cancelled' => 'Supplier Audit dibatalkan.',
        'result_published' => 'Hasil penilaian diterbitkan.',
        'result_replaced' => 'Hasil penilaian diganti.',
    ],

    'errors' => [
        'no_active_template' => 'Belum ada template checklist audit yang aktif. Jalankan php artisan migrate untuk mengisinya.',
        'none_assigned' => 'Tidak ada audit yang ditugaskan: :list.',
        'invalid_supplier' => 'Salah satu supplier yang dipilih tidak valid.',
        'locked' => 'Audit ini tidak bisa diubah lagi.',
        'invalid_criterion' => 'Jawaban yang dikirim bukan milik audit ini.',
        'score_range' => 'Score harus bilangan bulat 1 sampai 5.',
        'answer_required' => ':criterion wajib dijawab Ya atau Tidak.',
        'score_required' => ':criterion memerlukan Score 1 sampai 5 karena jawabannya Ya.',
        'score_prohibited' => ':criterion tidak boleh punya Score karena jawabannya Tidak.',
        'invalid_status' => 'Aksi ini tidak tersedia untuk status audit saat ini.',
        'replace_reason_required' => 'Alasan wajib diisi untuk mengganti hasil yang sudah terbit.',
        'result_file' => 'File hasil harus PDF, XLSX, JPG, atau PNG dengan ukuran maksimal 10 MB.',
        'result_store' => 'File hasil gagal disimpan. Silakan coba lagi.',
    ],

    'reject_reasons' => [
        'active_audit' => 'masih punya audit aktif',
        'not_eligible' => 'bukan supplier local aktif',
    ],

    'history' => [
        'events' => [
            'assigned' => 'Ditugaskan',
            'draft_started' => 'Draft dimulai',
            'submitted' => 'Disubmit',
            'revision_requested' => 'Revisi diminta',
            'cancelled' => 'Dibatalkan',
            'result_published' => 'Hasil terbit',
            'result_replaced' => 'Hasil diganti',
            'deadline_changed' => 'Deadline diubah',
        ],
        'by' => 'oleh :name',
    ],

    'invoice_block' => [
        'message' => 'Pengajuan invoice baru dinonaktifkan karena Supplier Audit periode :period melewati deadline (:date). Selesaikan dan submit form audit untuk membukanya kembali.',
        'short' => 'Selesaikan Supplier Audit untuk mengajukan invoice baru.',
        'banner_title' => 'Pengajuan invoice baru dinonaktifkan',
        'banner_action' => 'Isi Supplier Audit',
        'chip' => 'Invoice diblokir',
        'edit_notice' => 'Pengajuan invoice baru Anda ditunda sampai form ini disubmit.',
    ],

    'deadline' => [
        'change_title' => 'Ubah deadline',
        'change_help' => 'Kosongkan untuk menghapus deadline. Supplier yang melewati deadline tidak bisa mengajukan invoice baru sampai audit disubmit.',
        'remove' => 'Hapus deadline',
        'reason' => 'Alasan (opsional)',
        'change_on_revision' => 'Ubah deadline juga',
        'revision_warning' => 'Deadline saat ini sudah lewat. Bila tidak diubah, supplier langsung terblokir mengajukan invoice baru.',
        'flash_changed' => 'Deadline diperbarui.',
        'flash_removed' => 'Deadline dihapus.',
        'save' => 'Simpan deadline',
    ],

    'create' => [
        'title' => 'Tugaskan Supplier Audit',
        'description' => 'Satu audit dibuat untuk setiap supplier yang dipilih. Supplier yang masih punya audit aktif dilewati.',
        'suppliers_help' => 'Hanya supplier local yang aktif yang ditampilkan.',
        'period_help' => 'Contoh: 2026 Semester II.',
        'due_date_help' => 'Opsional. Setelah deadline lewat, supplier tidak bisa mengajukan invoice baru sampai audit disubmit.',
        'template_label' => ':title (versi :version)',
        'no_template' => 'Belum ada template checklist yang aktif. Template terisi otomatis saat php artisan migrate dijalankan.',
        'search_placeholder' => 'Cari nama supplier atau email',
        'no_suppliers' => 'Tidak ada supplier local yang aktif.',
        'no_match' => 'Tidak ada supplier yang cocok dengan pencarian.',
    ],

    'revision' => [
        'title' => 'Minta revisi',
        'help' => 'Supplier akan melihat catatan ini di atas form.',
        'submit' => 'Kirim permintaan revisi',
    ],

    'cancel' => [
        'title' => 'Batalkan audit',
        'help' => 'Supplier akan melihat alasannya. Audit yang dibatalkan tidak bisa dibuka kembali.',
    ],

    'upload' => [
        'title' => 'Hasil penilaian',
        'help' => 'Upload file pertama langsung menerbitkan hasil ke supplier.',
        'replace_help' => 'Supplier hanya melihat file terbaru. File sebelumnya tetap tersimpan sebagai riwayat.',
        'accepted' => 'PDF, XLSX, JPG, atau PNG. Maksimal 10 MB.',
    ],

    'filters' => [
        'status_all' => 'Semua status',
        'period_all' => 'Semua periode',
        'supplier_all' => 'Semua supplier',
        'late_only' => 'Hanya yang terlambat',
    ],

    'export' => [
        'label' => 'Supplier Audit :supplier (:period)',
        'vendor_header' => 'Vendor/Supplier: :supplier — Periode: :period',
        'sub_total' => 'Sub Total',
        'columns' => [
            'no' => 'No',
            'criterion' => 'Kriteria Audit',
            'yes' => 'Ya',
            'no_answer' => 'Tidak',
            'score' => 'Score',
            'point' => 'Point',
            'finding' => 'Temuan/Catatan',
        ],
    ],

    'notify' => [
        'assigned' => [
            'title' => 'Supplier Audit baru',
            'body' => 'Purchasing menugaskan Supplier Audit periode :period. Deadline: :date.',
        ],
        'revision_requested' => [
            'title' => 'Revisi Supplier Audit diminta',
            'body' => 'Purchasing meminta Anda merevisi Supplier Audit periode :period. Deadline: :date.',
        ],
        'result_published' => [
            'title' => 'Hasil Supplier Audit terbit',
            'body' => 'Hasil penilaian periode :period sudah bisa diunduh.',
        ],
        'cancelled' => [
            'title' => 'Supplier Audit dibatalkan',
            'body' => 'Purchasing membatalkan Supplier Audit periode :period. Alasan: :reason',
        ],
        'deadline_changed' => [
            'title' => 'Deadline Supplier Audit berubah',
            'body' => 'Deadline Supplier Audit periode :period sekarang :date.',
        ],
        'deadline_removed' => [
            'title' => 'Deadline Supplier Audit dihapus',
            'body' => 'Supplier Audit periode :period tidak lagi punya deadline.',
        ],
        'invoice_blocked' => [
            'title' => 'Invoice baru ditunda',
            'body' => 'Supplier Audit periode :period melewati deadline (:date). Submit form audit agar bisa mengajukan invoice baru lagi.',
        ],
        'submitted' => [
            'title' => 'Supplier Audit disubmit',
            'body' => ':supplier mengirim Supplier Audit periode :period.',
        ],
    ],

    'badge' => [
        'pending' => '1 audit perlu diisi',
    ],

    'deadline_relative' => [
        'overdue' => '{1} lewat :count hari|[2,*] lewat :count hari',
        'today' => 'hari ini',
        'remaining' => '{1} :count hari lagi|[2,*] :count hari lagi',
    ],

    'queues' => [
        'review' => 'Perlu dinilai',
        'waiting' => 'Menunggu supplier',
        'late' => 'Terlambat',
        'done' => 'Selesai',
        'all' => 'Semua',
        'label' => 'Antrean audit',
        'empty_review' => 'Belum ada audit yang perlu dinilai.',
        'empty_review_hint' => 'Audit muncul di sini setelah supplier mengirim form.',
        'empty_other' => 'Tidak ada audit di antrean ini.',
        'view_waiting' => 'Lihat audit yang menunggu supplier',
    ],

    'columns' => [
        'progress' => 'Progres',
        'action' => 'Aksi',
    ],

    'row_actions' => [
        'review' => 'Nilai',
    ],

    'flow' => [
        'label' => 'Tahapan audit',
        'assigned' => 'Ditugaskan',
        'filling' => 'Diisi supplier',
        'revision' => 'Revisi diminta',
        'submitted' => 'Disubmit',
        'published' => 'Hasil terbit',
    ],

    'next_step' => [
        'title' => 'Langkah berikutnya',
        'waiting' => 'Menunggu supplier melengkapi form.',
        'waiting_progress' => ':filled dari :total kriteria terisi.',
        'export' => 'Export jawaban ke Excel',
        'assess' => 'Nilai dan beri bobot secara offline',
        'upload' => 'Upload hasil penilaian',
        'published' => 'Hasil sudah terbit. Supplier bisa mengunduh file terbaru.',
        'cancelled' => 'Audit ini dibatalkan.',
        'more' => 'Aksi lain',
    ],

    'answer_filters' => [
        'label' => 'Filter jawaban',
        'all' => 'Semua',
        'no' => 'Tidak',
        'low' => 'Score ≤ 2',
        'empty' => 'Belum dijawab',
        'none_match' => 'Tidak ada kriteria yang cocok dengan filter ini.',
        'expand_all' => 'Buka semua',
        'collapse_all' => 'Lipat semua',
    ],

    'score_scale' => [
        'title' => 'Panduan Score',
        'intro' => 'Pilih Score hanya bila jawabannya Ya.',
        '1' => 'Belum ada / sangat kurang',
        '2' => 'Kurang',
        '3' => 'Cukup',
        '4' => 'Baik',
        '5' => 'Sangat baik / terdokumentasi penuh',
    ],

    'form' => [
        'sections' => 'Bagian',
        'section_picker' => 'Bagian :current dari :total',
        'jump_unanswered' => 'Lompat ke yang belum diisi',
        'all_complete' => 'Semua kriteria sudah lengkap. Anda bisa submit.',
        'incomplete' => '{1} Masih ada :count kriteria yang belum lengkap.|[2,*] Masih ada :count kriteria yang belum lengkap.',
        'submit_summary' => 'Ya :yes · Tidak :no. Setelah disubmit, form terkunci sampai Purchasing meminta revisi.',
        'shortcuts_title' => 'Pintasan keyboard',
        'shortcuts' => 'Y = Ya · T atau N = Tidak · 1–5 = Score · Enter atau ↓ = berikutnya yang belum diisi',
        'section_done' => 'lengkap',
        'section_partial' => 'sebagian',
        'section_empty' => 'belum diisi',
        'section_error' => 'perlu diperbaiki',
    ],

    'autosave' => [
        'idle' => 'Perubahan tersimpan otomatis',
        'saving' => 'Menyimpan…',
        'saved' => 'Tersimpan :time',
        'failed' => 'Gagal menyimpan. Mencoba lagi…',
        'retry' => 'Coba sekarang',
        'session_expired' => 'Sesi Anda berakhir. Muat ulang halaman untuk melanjutkan; jawaban yang belum tersimpan tetap ada di halaman ini sampai itu.',
        'reload' => 'Muat ulang',
        'unsaved' => 'Ada jawaban yang belum tersimpan.',
    ],

    'create_extra' => [
        'preset_14' => '+14 hari',
        'preset_30' => '+30 hari',
        'preset_none' => 'Tanpa deadline',
        'last_audit' => 'Audit terakhir: :value',
        'never_audited' => 'Belum pernah diaudit',
        'summary' => ':selected dipilih · :skipped dilewati (audit aktif)',
    ],
];

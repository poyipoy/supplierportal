<?php

return [
    'configuration' => 'Pemrosesan PO async belum tersedia sebelum kapasitas hosting diverifikasi.',
    'capacity' => 'Kapasitas pemrosesan dokumen PO belum tersedia. Coba lagi nanti.',
    'storage_limit' => 'ZIP dan hasil ekstraksinya membutuhkan ruang penyimpanan melebihi batas atau kapasitas yang tersedia. Kurangi ukuran atau jumlah PDF, lalu unggah kembali. Jika masih gagal, hubungi administrator. Tidak ada dokumen PO baru yang diterbitkan.',
    'changed' => 'PO atau dokumen sumber berubah. Unggah kembali dokumen yang sesuai.',
    'rejected' => 'Batch ditolak. Tidak ada dokumen PO baru yang diterbitkan.',
    'failed' => 'Batch gagal. Tidak ada dokumen PO baru yang diterbitkan.',
    'timeout' => 'Batas waktu pemrosesan terlampaui.',
    'retry' => 'Ulangi pemrosesan',
    'progress' => 'Progress pemrosesan dokumen PO',
    'uploading' => 'Sedang mengunggah. Penerbitan dokumen belum selesai.',
    'offline' => 'Status sementara tidak tersedia. Buka kembali halaman untuk melanjutkan.',
    'states' => [
        'PENDING' => 'Dalam antrean validasi native.',
        'PROCESSING' => 'Memvalidasi dokumen PO.',
        'VALIDATED' => 'Validasi native berhasil. Menyiapkan penerbitan.',
        'PUBLISHING' => 'Menerbitkan seluruh batch.',
        'COMPLETED' => 'Seluruh dokumen PO telah diterbitkan.',
        'REJECTED' => 'Batch ditolak. Tidak ada dokumen yang diterbitkan.',
        'FAILED' => 'Pemrosesan gagal. Tidak ada dokumen yang diterbitkan.',
    ],
];

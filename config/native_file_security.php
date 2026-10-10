<?php

$mib = 1024 * 1024;
$documents = ['pdf', 'jpg', 'jpeg', 'png'];
$office = [...$documents, 'xlsx', 'xls', 'doc', 'docx'];
$profile = static fn (int $bytes, array $extensions): array => ['max_bytes' => $bytes, 'extensions' => $extensions];

return [
    'policy_version' => 'native-v1',
    'requests' => ['max_files' => null, 'max_inspection_seconds' => 60],
    // Native inspection is not an antivirus scan. These are finite safety ceilings,
    // not a certification of hosting capacity or permission to raise ZIP limits.
    'profiles' => [
        'registration' => $profile(5 * $mib, $documents),
        'registration_company' => $profile(10 * $mib, $documents),
        'vendor' => $profile(10 * $mib, $documents),
        'invoice' => $profile(5 * $mib, $documents),
        'ga' => $profile(10 * $mib, $office),
        'mtc' => $profile(5 * $mib, $documents),
        'shipment' => $profile(10 * $mib, array_diff($office, ['xls'])),
        'qc' => $profile(10 * $mib, ['jpg', 'jpeg', 'png']),
        'claim' => $profile(10 * $mib, array_diff($office, ['xls'])),
        'chat' => $profile(10 * $mib, $office),
        'refund' => $profile(10 * $mib, $documents),
        'audit' => $profile(10 * $mib, [...$documents, 'xlsx']),
        'po_pdf' => $profile(50 * $mib, ['pdf']),
        'po_zip_pdf' => $profile(100 * $mib, ['pdf']),
        'po_zip' => $profile(50 * $mib, ['zip']),
        'spreadsheet_preview' => $profile(10 * $mib, ['xlsx', 'xls', 'csv']),
        'spreadsheet_import' => $profile(50 * $mib, ['xlsx', 'xls', 'csv']),
        'employee_spreadsheet' => $profile(50 * $mib, ['xlsx', 'xls', 'csv']),
    ],
    'zip' => [
        'max_upload_bytes' => 50 * $mib,
        'max_entries' => 100,
        'max_pdfs' => 100,
        'max_entry_bytes' => 100 * $mib,
        'max_total_bytes' => 100 * $mib,
        'max_metadata_bytes' => 4 * $mib,
        'max_name_bytes' => 1024,
        'chunk_bytes' => 64 * 1024,
        'max_runtime_seconds' => 60,
        'max_working_bytes' => 250 * $mib,
        // Host account quota/reserve is UNKNOWN. Async enablement requires its
        // separately verified positive reserve; zero here never proves capacity.
        'minimum_free_bytes' => 0,
        'maximum_concurrent_batches' => 1,
        'allow_zip64' => false,
    ],
    'office' => [
        // Retain existing Local PO/GR XLSX metadata ceilings and now enforce
        // actual bytes before a spreadsheet parser consumes the container.
        'max_entries' => 1000,
        'max_entry_bytes' => 512 * $mib,
        'max_total_bytes' => 512 * $mib,
        'max_metadata_bytes' => 8 * $mib,
        'max_name_bytes' => 1024,
        'max_runtime_seconds' => 60,
        'allow_zip64' => false,
    ],
    'images' => ['max_width' => 20000, 'max_height' => 20000, 'max_pixels' => 40000000],
    'pdf' => ['tail_bytes' => 64 * 1024],
    'csv' => ['max_record_bytes' => 50 * $mib, 'max_cell_bytes' => 50 * $mib, 'max_columns' => 16384],
];

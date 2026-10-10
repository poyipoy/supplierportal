<?php

return [
    'errors' => [
        'archive' => 'The archive is invalid or incomplete.',
        'read' => 'The file could not be read completely.',
        'unsupported' => 'The archive format, encryption, or compression method is unsupported.',
        'budget' => 'The file exceeds a configured security resource limit.',
        'archive_storage' => 'The PDFs inside the ZIP exceed the allowed decompressed storage limit. Reduce the size or number of PDFs and upload again. No new PO documents were published.',
        'name' => 'The archive contains an unsafe filename.',
        'special' => 'Links and special archive entries are not allowed.',
        'duplicate' => 'The archive contains duplicate document names.',
        'nested' => 'Nested archives are not allowed.',
        'type' => 'The extension and detected format must match an allowed document type.',
        'write' => 'The file could not be saved completely. No document was published.',
        'disk' => 'Insufficient temporary storage is available.',
        'corrupt' => 'File integrity verification failed.',
        'configuration' => 'File security configuration is incomplete.',
        'timeout' => 'File processing exceeded its allowed duration.',
        'office' => 'The Office document structure is invalid or unsupported.',
        'pdf' => 'The PDF is incomplete or has an invalid document envelope.',
        'image' => 'The image format or dimensions exceed the allowed limits.',
    ],
];

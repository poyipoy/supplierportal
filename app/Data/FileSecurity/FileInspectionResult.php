<?php

namespace App\Data\FileSecurity;

final readonly class FileInspectionResult
{
    public string $status;

    public function __construct(
        public string $profile,
        public string $mimeType,
        public int $fileSize,
        public string $sha256,
        public string $policyVersion,
        string $status = 'VALIDATED',
    ) {
        $this->status = $status;
    }
}

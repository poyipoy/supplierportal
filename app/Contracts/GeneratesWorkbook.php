<?php

namespace App\Contracts;

interface GeneratesWorkbook
{
    /**
     * Generate the spreadsheet workbook and save it to the specified disk and path.
     */
    public function generateWorkbook(string $path, string $disk): void;
}

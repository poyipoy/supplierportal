<?php

namespace Tests\Unit;

use App\Services\FileSecurity\FileInspectionService;
use App\Services\FileSecurity\NativeArchiveInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;
use ZipArchive;

class NativeFileSecurityTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_valid_pdf_receives_native_validated_verdict(): void
    {
        $file = UploadedFile::fake()->createWithContent('PO-1.pdf', NativeFileFixtures::pdf());
        $result = app(FileInspectionService::class)->inspectUpload($file, 'po_pdf');
        $this->assertSame('VALIDATED', $result->status);
        $this->assertSame('application/pdf', $result->mimeType);
        $this->assertSame(hash_file('sha256', $file->getPathname()), $result->sha256);
    }

    public function test_pdf_header_without_pdf_envelope_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(FileInspectionService::class)->inspectUpload(UploadedFile::fake()->createWithContent('PO.pdf', "%PDF-1.4\nnot a PDF"), 'po_pdf');
    }

    public function test_client_extension_must_agree_with_detected_type(): void
    {
        $this->expectException(ValidationException::class);
        app(FileInspectionService::class)->inspectUpload(UploadedFile::fake()->createWithContent('image.pdf', NativeFileFixtures::png()), 'invoice');
    }

    public function test_qc_policy_does_not_accept_pdf(): void
    {
        $this->expectException(ValidationException::class);
        app(FileInspectionService::class)->inspectUpload(UploadedFile::fake()->createWithContent('qc.pdf', NativeFileFixtures::pdf()), 'qc');
    }

    public function test_aggregate_expansion_is_checked_before_extraction(): void
    {
        config()->set('native_file_security.zip.max_total_bytes', 100);
        $this->expectException(ValidationException::class);
        app(NativeArchiveInspector::class)->inspect($this->archive(['PO.pdf' => NativeFileFixtures::pdf()]));
    }

    public function test_duplicate_flattened_names_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(NativeArchiveInspector::class)->inspect($this->archive(['a/PO.pdf' => NativeFileFixtures::pdf(), 'b/po.PDF' => NativeFileFixtures::pdf()]));
    }

    public function test_traversal_and_nested_archives_are_rejected(): void
    {
        foreach (['../PO.pdf', 'inner.zip', '/PO.pdf', 'C:\\PO.pdf'] as $name) {
            try {
                app(NativeArchiveInspector::class)->inspect($this->archive([$name => NativeFileFixtures::pdf()]));
                $this->fail('Unsafe archive admitted: '.$name);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_symlink_entry_is_rejected(): void
    {
        $path = $this->archive(['PO.pdf' => NativeFileFixtures::pdf()]);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setExternalAttributesName('PO.pdf', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();
        $this->expectException(ValidationException::class);
        app(NativeArchiveInspector::class)->inspect($path);
    }

    public function test_actual_streamed_bytes_and_generated_paths_are_returned(): void
    {
        $path = $this->archive(['folder/PO.pdf' => NativeFileFixtures::pdf()]);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adasi-native-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $files = app(NativeArchiveInspector::class)->extractPdfArchive($path, $directory);
            $this->assertCount(1, $files);
            $this->assertSame('PO.pdf', $files[0]['name']);
            $this->assertNotSame('PO.pdf', basename($files[0]['path']));
            $this->assertSame(strlen(NativeFileFixtures::pdf()), $files[0]['file_size']);
            $this->assertSame(hash('sha256', NativeFileFixtures::pdf()), $files[0]['sha256']);
            unlink($files[0]['path']);
        } finally {
            rmdir($directory);
        }
    }

    public function test_capacity_experiments_use_isolated_limits_and_bounded_real_pdfs(): void
    {
        foreach ([1, 100, 250, 500, 750] as $count) {
            config(['native_file_security.zip.max_entries' => $count, 'native_file_security.zip.max_pdfs' => $count]);
            $entries = [];
            for ($i = 0; $i < $count; $i++) {
                $entries['PO-'.$i.'.pdf'] = NativeFileFixtures::pdf('PO '.$i);
            }
            $path = $this->archive($entries);
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adasi-capacity-'.bin2hex(random_bytes(8));
            mkdir($directory);
            try {
                $start = microtime(true);
                $files = app(NativeArchiveInspector::class)->extractPdfArchive($path, $directory);
                $this->assertCount($count, $files);
                $this->assertLessThan(60, microtime(true) - $start);
                foreach ($files as $file) {
                    unlink($file['path']);
                }
            } finally {
                rmdir($directory);
            }
        }
    }

    public function test_encrypted_unsupported_corrupt_and_unicode_colliding_entries_are_rejected(): void
    {
        $cases = [
            ['PO.pdf' => NativeFileFixtures::pdf()],
            ['PO.pdf' => NativeFileFixtures::pdf()],
            ["caf\u{00e9}.pdf" => NativeFileFixtures::pdf(), "cafe\u{0301}.pdf" => NativeFileFixtures::pdf()],
        ];
        foreach ($cases as $index => $entries) {
            $path = $this->archive($entries);
            if ($index < 2) {
                $zip = new ZipArchive;
                $zip->open($path);
                if ($index === 0) {
                    $zip->setEncryptionIndex(0, ZipArchive::EM_AES_256, 'test');
                } else {
                    $zip->setCompressionIndex(0, ZipArchive::CM_BZIP2);
                }
                $zip->close();
            }
            try {
                app(NativeArchiveInspector::class)->extractPdfArchive($path, sys_get_temp_dir());
                $this->fail('An unsupported archive was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $path = $this->archive(['PO.pdf' => NativeFileFixtures::pdf()]);
        $contents = file_get_contents($path);
        file_put_contents($path, substr($contents, 0, -12));
        $this->expectException(ValidationException::class);
        app(NativeArchiveInspector::class)->inspect($path);
    }

    public function test_zip64_is_fail_closed_by_default_and_valid_when_explicitly_enabled_in_test(): void
    {
        $pdf = NativeFileFixtures::pdf();
        $name = 'PO.pdf';
        $size = strlen($pdf);
        $extra = pack('vvPP', 1, 16, $size, $size);
        $local = pack('VvvvvvVVVvv', 0x04034B50, 45, 0, 0, 0, 0, crc32($pdf), 0xFFFFFFFF, 0xFFFFFFFF, strlen($name), strlen($extra)).$name.$extra.$pdf;
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 45, 45, 0, 0, 0, 0, crc32($pdf), 0xFFFFFFFF, 0xFFFFFFFF, strlen($name), strlen($extra), 0, 0, 0, 0, 0).$name.$extra;
        $endOffset = strlen($local) + strlen($central);
        $end64 = pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, 1, 1, strlen($central), strlen($local));
        $locator = pack('VVPV', 0x07064B50, 0, $endOffset, 1);
        $end = pack('VvvvvVVv', 0x06054B50, 0, 0, 65535, 65535, 0xFFFFFFFF, 0xFFFFFFFF, 0);
        $path = tempnam(sys_get_temp_dir(), 'adasi-zip64-');
        $this->paths[] = $path;
        file_put_contents($path, $local.$central.$end64.$locator.$end);
        try {
            app(NativeArchiveInspector::class)->inspect($path);
            $this->fail('ZIP64 needs an explicit compatibility policy.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        config(['native_file_security.zip.allow_zip64' => true]);
        $this->assertCount(1, app(NativeArchiveInspector::class)->inspect($path));
    }

    public function test_image_dimensions_and_malformed_office_structure_are_rejected(): void
    {
        config(['native_file_security.images.max_pixels' => 1]);
        try {
            app(FileInspectionService::class)->inspectUpload(UploadedFile::fake()->image('photo.png', 2, 2), 'qc');
            $this->fail('Oversized pixel dimensions were admitted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $path = $this->archive(['[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<Relationships/>',
            'xl/workbook.xml' => '<workbook>broken']);
        $file = new UploadedFile($path, 'book.xlsx', 'application/zip', null, true);
        $this->expectException(ValidationException::class);
        app(FileInspectionService::class)->inspectUpload($file, 'spreadsheet_import');
    }

    public function test_utf16_macro_package_type_is_rejected_after_decoded_xml_inspection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'adasi-office-');
        $this->paths[] = $path;
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('A1', 'Native Office fixture');
        (new Xlsx($workbook))->save($path);
        app(FileInspectionService::class)->inspectUpload(new UploadedFile($path, 'book.xlsx', 'application/zip', null, true), 'spreadsheet_import');
        $zip = new ZipArchive;
        $zip->open($path);
        $types = $zip->getFromName('[Content_Types].xml');
        $types = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $types);
        $types = str_replace('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'application/vnd.ms-excel.sheet.macroEnabled.main+xml', $types);
        $zip->addFromString('[Content_Types].xml', hex2bin('fffe').mb_convert_encoding($types, 'UTF-16LE', 'UTF-8'));
        $zip->close();
        $this->expectException(ValidationException::class);
        app(FileInspectionService::class)->inspectUpload(new UploadedFile($path, 'book.xlsx', 'application/zip', null, true), 'spreadsheet_import');
    }

    public function test_underdeclared_entry_is_rejected_without_leaving_extracted_bytes(): void
    {
        $path = $this->archive(['PO.pdf' => NativeFileFixtures::pdf()]);
        $bytes = file_get_contents($path);
        $central = strpos($bytes, hex2bin('504b0102'));
        $this->assertNotFalse($central);
        $bytes = substr_replace($bytes, pack('V', 1), $central + 24, 4);
        file_put_contents($path, $bytes);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adasi-underdeclared-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            app(NativeArchiveInspector::class)->extractPdfArchive($path, $directory);
            $this->fail('A deceptive expansion declaration was accepted.');
        } catch (ValidationException) {
            $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
        } finally {
            rmdir($directory);
        }
    }

    public function test_actual_byte_counters_enforce_tighter_runtime_budgets_after_preflight(): void
    {
        foreach (['max_entry_bytes', 'max_total_bytes'] as $budget) {
            config(['native_file_security.zip.max_entry_bytes' => 100 * 1024 * 1024, 'native_file_security.zip.max_total_bytes' => 100 * 1024 * 1024]);
            $path = $this->archive(['PO.pdf' => NativeFileFixtures::pdf()]);
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adasi-runtime-budget-'.bin2hex(random_bytes(8));
            mkdir($directory);
            $inspector = new class($budget) extends NativeArchiveInspector
            {
                public function __construct(private string $budget) {}

                public function inspect(string $path, string $policy = 'po_zip'): array
                {
                    $manifest = parent::inspect($path, $policy);
                    config(['native_file_security.zip.'.$this->budget => 32]);

                    return $manifest;
                }
            };
            try {
                $inspector->extractPdfArchive($path, $directory);
                $this->fail('The actual byte budget was not enforced.');
            } catch (ValidationException) {
                $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
            } finally {
                rmdir($directory);
            }
        }
    }

    public function test_valid_docx_uses_office_policy_and_remains_disallowed_for_invoice(): void
    {
        $path = $this->archive([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Native validated document</w:t></w:r></w:p><w:sectPr/></w:body></w:document>',
        ]);
        $file = new UploadedFile($path, 'proof.docx', 'application/zip', null, true);
        $result = app(FileInspectionService::class)->inspectUpload($file, 'chat');
        $this->assertSame('VALIDATED', $result->status);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $result->mimeType);
        $this->expectException(ValidationException::class);
        app(FileInspectionService::class)->inspectUpload($file, 'invoice');
    }

    private function archive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'adasi-zip-');
        $this->paths[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}

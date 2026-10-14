<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SupplierRegistrationSubmissionRaceTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public static function duplicateIdentities(): array
    {
        return [['email'], ['nib'], ['npwp']];
    }

    #[DataProvider('duplicateIdentities')]
    public function test_simultaneous_initial_submissions_persist_one_application_and_reject_the_duplicate(string $field): void
    {
        Storage::fake('private');
        $root = Storage::disk('private')->path('');
        $processes = [];
        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/registration-submission-worker.php'), $root, $field, (string) $index],
                base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(45);
            $processes[] = $process;
            $process->start();
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }
        sort($outcomes);
        $this->assertSame(['accepted', 'rejected:'.$field], $outcomes);
        $this->assertSame(1, User::count());
        $this->assertSame(1, SupplierRegistrationAttempt::count());
        $this->assertSame(1, SupplierRegistrationAccess::count());
    }
}

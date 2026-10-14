<?php

use App\Exceptions\DuplicateSupplierRegistration;
use App\Services\NotificationService;
use App\Services\SupplierRegistrationService;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\NativeFileFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '',
    'database.connections.mysql.url' => null, 'filesystems.disks.private.root' => $argv[1],
    'cache.default' => 'array', 'broadcasting.default' => 'null', 'queue.default' => 'sync']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
Notification::fake();
$root = $argv[1];
$field = $argv[2];
$index = (int) $argv[3];
$service = new class($root, $index) extends SupplierRegistrationService
{
    public function __construct(private string $root, private int $index)
    {
        parent::__construct(app(NotificationService::class));
    }

    public function checkDuplicates(string $email, string $nib, string $npwp, ?int $ignoreUserId = null): void
    {
        parent::checkDuplicates($email, $nib, $npwp, $ignoreUserId);
        $marker = $this->root.'/registration-race-'.$this->index.'.ready';
        if (is_file($marker)) {
            return;
        }
        file_put_contents($marker, 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($this->root.'/registration-race-'.(1 - $this->index).'.ready')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Registration race barrier timed out.');
            }
            usleep(10000);
        }
    }
};
$emailIndex = $field === 'email' ? 0 : $index;
$nibIndex = $field === 'nib' ? 0 : $index;
$npwpIndex = $field === 'npwp' ? 0 : $index;
try {
    $service->submitInitialRegistration([
        'company_name' => 'Registration Race', 'address' => 'Test', 'phone' => '1',
        'email' => 'registration-race-'.$emailIndex.'@example.test', 'password' => 'Password123!',
        'nib' => '111111111111'.$nibIndex, 'npwp' => '01.234.567.8-412.00'.$npwpIndex,
        'pic_name' => 'Test', 'pic_email' => 'race@example.test', 'pic_phone' => '1',
        'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'Registration Race',
        'questionnaire' => SupplierComplianceQuestionnaire::QUESTIONS,
    ], ['nib_file' => NativeFileFixtures::upload('nib.pdf'), 'npwp_file' => NativeFileFixtures::upload('npwp.pdf'),
        'sknr_file' => NativeFileFixtures::upload('sknr.pdf')]);
    echo 'accepted';
} catch (DuplicateSupplierRegistration $exception) {
    echo 'rejected:'.array_key_first($exception->errors());
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.':'.($exception instanceof QueryException ? ($exception->errorInfo[1] ?? '') : $exception->getCode()));
    exit(2);
}

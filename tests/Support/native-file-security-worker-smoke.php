<?php

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\LocalPoDocumentBatch;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\NativeFileFixtures;

// Actual database-queue smoke, exclusively on the isolated MySQL test schema.
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'adasi_portal_test'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
    throw new RuntimeException('Unsafe worker smoke database.');
}
$mode = $argv[1] ?? 'prepare';
$key = $argv[2] ?? bin2hex(random_bytes(12));
if (! preg_match('/^[a-f0-9]{24}$/', $key)) {
    throw new RuntimeException('Invalid isolated smoke identity.');
}
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adasi-native-worker-'.$key;
$queue = 'native-po-test-'.$key;
config(['filesystems.disks.private.root' => $root, 'queue.default' => 'database', 'po_documents.queue' => $queue,
    'po_documents.async_enabled' => true, 'po_documents.host_verified' => true,
    'po_documents.runtime_seconds' => 120, 'po_documents.slice_seconds' => 20,
    'po_documents.disk_bytes' => 16 * 1024 * 1024, 'po_documents.reserve_bytes' => 1024,
    'po_documents.max_active' => 1, 'po_documents.max_queued_per_user' => 1, 'po_documents.max_backlog' => 2,
    'po_documents.max_queue_seconds' => 3600, 'po_documents.retry_limit' => 3,
    'po_documents.timeout_seconds' => 40, 'po_documents.lease_seconds' => 60, 'po_documents.retention_seconds' => 3600]);
Storage::forgetDisk('private');
if ($mode === 'prepare') {
    mkdir($root, 0700, true);
    $actor = User::factory()->create(['role' => 'finance']);
    $supplier = User::factory()->create(['role' => 'supplier']);
    SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
    $po = LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'SMOKE-'.$key,
        'po_date' => '2026-10-09', 'total_amount' => '100.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
    require_once __DIR__.'/NativeFileFixtures.php';
    $archive = $root.DIRECTORY_SEPARATOR.'input.zip';
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($po->po_number.'.pdf', NativeFileFixtures::pdf('Isolated worker smoke'));
    $zip->close();
    $batch = app(LocalPoDocumentBatchService::class)->start($actor, $supplier,
        new UploadedFile($archive, 'input.zip', 'application/zip', null, true), (string) Str::uuid());
    file_put_contents($root.DIRECTORY_SEPARATOR.'identity.json', json_encode(['batch' => $batch->id, 'actor' => $actor->id, 'supplier' => $supplier->id, 'po' => $po->id]));
    echo json_encode(['key' => $key, 'queue' => $queue, 'batch_status' => $batch->fresh()->status,
        'queued_jobs' => DB::table('jobs')->where('queue', $queue)->count()]);
} elseif ($mode === 'worker') {
    exit($kernel->call('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--tries' => 3, '--timeout' => 40]));
} elseif ($mode === 'verify' || $mode === 'cleanup') {
    $identity = json_decode(file_get_contents($root.DIRECTORY_SEPARATOR.'identity.json'), true, 512, JSON_THROW_ON_ERROR);
    $batch = LocalPoDocumentBatch::findOrFail($identity['batch']);
    if ((int) $batch->user_id !== $identity['actor'] || (int) $batch->supplier_id !== $identity['supplier']) {
        throw new RuntimeException('Smoke ownership mismatch.');
    }
    if ($mode === 'verify') {
        $attachments = Attachment::where('attachable_type', LocalPurchaseOrder::class)->where('attachable_id', $identity['po'])->get();
        if ($batch->status !== 'COMPLETED' || $attachments->count() !== 1) {
            throw new RuntimeException('Worker publication failed.');
        }
        app(FileAccessGuard::class)->assertReadable($attachments->first());
        echo json_encode(['database' => 'adasi_portal_test', 'status' => $batch->status, 'attachments' => 1,
            'remaining_queue_jobs' => DB::table('jobs')->where('queue', $queue)->count()]);
    } else {
        $inspectionIds = $batch->entries()->pluck('file_inspection_id')->filter()->push($batch->original_file_inspection_id)->all();
        $batch->entries()->delete();
        Attachment::where('attachable_type', LocalPurchaseOrder::class)->where('attachable_id', $identity['po'])->delete();
        $batch->delete();
        FileInspection::whereIn('id', $inspectionIds)->delete();
        DB::table('local_finance_audit_logs')->where('actor_id', $identity['actor'])->delete();
        LocalPurchaseOrder::whereKey($identity['po'])->delete();
        SupplierScope::where('supplier_id', $identity['supplier'])->delete();
        User::whereIn('id', [$identity['actor'], $identity['supplier']])->delete();
        DB::table('jobs')->where('queue', $queue)->delete();
        $expectedRoot = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'adasi-native-worker-'.$key;
        if (realpath($root) !== $expectedRoot || is_link($root)) {
            throw new RuntimeException('Unsafe smoke cleanup directory.');
        }
        File::deleteDirectory($root);
        echo 'Removed only isolated smoke rows/files.';
    }
} else {
    throw new RuntimeException('Unsupported smoke action.');
}

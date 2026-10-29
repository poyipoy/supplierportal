<?php

use App\Exceptions\PoShipmentDocumentBatchRefused;
use App\Models\PurchaseOrder;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\PoShipmentDocumentStatusService;
use App\Services\ShipmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $start, $privateRoot, $payloadJson] = $argv;

config([
    'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.url' => null,
    'cache.default' => 'array',
    'filesystems.disks.private.root' => $privateRoot,
]);

DB::purge('mysql');
Storage::forgetDisk('private');

if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}

$payload = json_decode((string) base64_decode($payloadJson, true), true, 512, JSON_THROW_ON_ERROR);

while (microtime(true) < (float) $start) {
    usleep(500);
}

try {
    if ($action === 'upload') {
        $doc = ShipmentDocument::findOrFail((int) $payload['document_id']);
        $user = User::findOrFail((int) $payload['user_id']);
        $file = NativeFileFixtures::upload((string) ($payload['file_name'] ?? 'upload.pdf'), 256);
        $attachment = app(ShipmentService::class)->uploadDocument(
            $doc,
            $file,
            $user,
            $payload['document_number'] ?? 'DOC-CONC',
        );
        echo 'uploaded:'.$attachment->id;
    } elseif ($action === 'po-batch') {
        $po = PurchaseOrder::findOrFail((int) $payload['po_id']);
        $actor = User::findOrFail((int) $payload['actor_id']);
        $result = app(PoShipmentDocumentStatusService::class)->updateSelected(
            po: $po,
            docType: (string) $payload['doc_type'],
            rows: (array) $payload['rows'],
            target: (string) $payload['target'],
            reason: $payload['reason'] ?? null,
            actor: $actor,
        );
        echo sprintf('updated:%d:skipped:%d', $result['counts']['updated'], $result['counts']['skipped']);
    } else {
        exit(4);
    }
} catch (PoShipmentDocumentBatchRefused $exception) {
    $reasons = array_values(array_filter(array_map(fn (array $r) => $r['reason_code'] ?? null, $exception->rows)));
    echo 'refused:'.$exception->errorCode.':'.($reasons[0] ?? 'none');
} catch (InvalidArgumentException $exception) {
    echo 'refused:'.class_basename($exception);
} catch (QueryException $exception) {
    if (($exception->errorInfo[0] ?? null) !== '40001') {
        throw $exception;
    }
    echo 'deadlock';
}

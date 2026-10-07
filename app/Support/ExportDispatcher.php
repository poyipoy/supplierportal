<?php

namespace App\Support;

use App\Contracts\AcceptsExportOptions;
use App\Contracts\GeneratesWorkbook;
use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PaymentBatchDrpExport;
use App\Exports\PaymentBatchTransferExport;
use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Exports\SupplierPriceHistoryExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\UserPreferenceService;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportOptionsResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

class ExportDispatcher
{
    /** @var list<class-string> */
    private const SUPPORTED_EXPORT_CLASSES = [
        LocalInvoicesExport::class,
        PaymentBatchDrpExport::class,
        PaymentBatchTransferExport::class,
        RequisitionsExport::class,
        PurchaseOrdersExport::class,
        PurchaseRequisitionDetailExport::class,
        QuotationsExport::class,
        QuotationDetailExport::class,
        PurchaseOrderDetailExport::class,
        InspectionsExport::class,
        SupplierPriceHistoryExport::class,
        ShipmentsExport::class,
    ];

    public static function dispatch(string $label, string $exportClass, array $args, string $fileName, ?ExportOptions $options = null): ExportJob
    {
        $user = Auth::user();

        if ($user === null) {
            throw new LogicException('An authenticated user is required to dispatch an export.');
        }

        if (! self::isSupported($exportClass)) {
            throw new InvalidArgumentException('The requested export class is not supported.');
        }

        if ($options !== null && is_a($exportClass, GeneratesWorkbook::class, true)) {
            throw new InvalidArgumentException('Fixed workbooks do not accept advanced export options.');
        }

        $args = array_values($args);

        try {
            json_encode($args, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Export arguments must be JSON serializable.', previous: $exception);
        }

        $connection = ExportJob::query()->getModel()->getConnection();

        if ($options !== null && is_a($exportClass, AcceptsExportOptions::class, true)) {
            ExportOptionsResolver::authorizeStored($exportClass, $user, $options);
            $preview = new $exportClass(...$args);
            $preview->applyOptions($options);
            if ($preview->progressTotalRows() > (int) config('exports.max_rows')) {
                throw ValidationException::withMessages(['options' => __('exports.advanced.row_limit', ['limit' => config('exports.max_rows')])]);
            }
        }

        return $connection->transaction(function () use ($user, $label, $exportClass, $args, $fileName, $options): ExportJob {
            if ($options !== null) {
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                if (ExportJob::query()->where('user_id', $user->getKey())->whereIn('status', [ExportJob::STATUS_QUEUED, ExportJob::STATUS_PROCESSING])->count() >= (int) config('exports.max_concurrent_per_user')) {
                    throw ValidationException::withMessages(['options' => __('exports.advanced.concurrent_limit', ['limit' => config('exports.max_concurrent_per_user')])]);
                }
            }
            $record = ExportJob::create([
                'user_id' => $user->getKey(),
                'label' => $label,
                'export_class' => $exportClass,
                'export_args' => $args,
                'file_name' => self::safeFileName($fileName, $options?->format ?? 'xlsx'),
                'format' => $options?->format ?? 'xlsx',
                'export_options' => $options?->toArray(),
                'disk' => 'private',
                'status' => ExportJob::STATUS_QUEUED,
                'progress_stage' => ExportJob::STAGE_QUEUED,
                'progress' => 0,
                'total_rows' => 0,
                'processed_rows' => 0,
                'processed_chunks' => [],
            ]);

            self::assertAtomicQueueConfiguration($record);
            $activeLocale = app()->getLocale();
            $userPrefLocale = app(UserPreferenceService::class)->for($user)['locale'] ?? 'en';
            $locale = UserPreferenceService::normalizeLocale($activeLocale ?: $userPrefLocale);
            $pending = ProcessExportJob::dispatch((int) $record->getKey(), $locale)->onQueue('exports');

            // Force the root database-queue insert before the record transaction
            // commits. A failure or process termination rolls both changes back.
            unset($pending);

            return $record;
        }, 3);
    }

    public static function assertAtomicQueueConfiguration(ExportJob $record): void
    {
        $queueName = (string) config('queue.default');
        $queue = config("queue.connections.{$queueName}", []);
        $driver = $queue['driver'] ?? null;

        // PHPUnit and local queue fakes use sync. Durable async exports in this
        // project intentionally use the database driver.
        if ($driver === 'sync') {
            return;
        }

        if ($driver !== 'database') {
            throw new RuntimeException(
                'Atomic export handoff requires the database queue driver.'
            );
        }

        if (($queue['after_commit'] ?? false) !== false) {
            throw new RuntimeException(
                'Atomic export handoff requires database queue after_commit=false.'
            );
        }

        $recordConnection = $record->getConnectionName() ?: config('database.default');
        $queueConnection = ($queue['connection'] ?? null) ?: config('database.default');

        if ($recordConnection !== $queueConnection) {
            throw new RuntimeException(
                'Atomic export handoff requires ExportJob and database queue to share a connection.'
            );
        }

    }

    public static function isSupported(string $exportClass): bool
    {
        return in_array($exportClass, self::SUPPORTED_EXPORT_CLASSES, true);
    }

    private static function safeFileName(string $fileName, string $format = 'xlsx'): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        $fileName = str_replace(["\r", "\n", "\0"], '', $fileName);
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: '';

        if ($format === 'csv') {
            $stem = preg_replace('/\.(xlsx|csv)$/i', '', $fileName) ?: '';
            $stem = trim(substr($stem, 0, 235), '._-');

            return ($stem === '' ? 'export' : $stem).'.csv';
        }

        if (! str_ends_with(strtolower($fileName), '.xlsx')) {
            $fileName .= '.xlsx';
        }

        $name = substr($fileName, 0, 240);
        $name = trim($name, '._-');

        if ($name === '' || $name === 'xlsx') {
            return 'export.xlsx';
        }

        return str_ends_with(strtolower($name), '.xlsx') ? $name : $name.'.xlsx';
    }
}

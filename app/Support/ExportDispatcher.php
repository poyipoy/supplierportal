<?php

namespace App\Support;

use App\Exports\InspectionsExport;
use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\SupplierPriceHistoryExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use JsonException;
use LogicException;

class ExportDispatcher
{
    /** @var list<class-string> */
    private const SUPPORTED_EXPORT_CLASSES = [
        RequisitionsExport::class,
        PurchaseOrdersExport::class,
        PurchaseRequisitionDetailExport::class,
        QuotationsExport::class,
        QuotationDetailExport::class,
        PurchaseOrderDetailExport::class,
        InspectionsExport::class,
        SupplierPriceHistoryExport::class,
    ];

    public static function dispatch(string $label, string $exportClass, array $args, string $fileName): ExportJob
    {
        $user = Auth::user();

        if ($user === null) {
            throw new LogicException('An authenticated user is required to dispatch an export.');
        }

        if (! self::isSupported($exportClass)) {
            throw new InvalidArgumentException('The requested export class is not supported.');
        }

        $args = array_values($args);

        try {
            json_encode($args, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Export arguments must be JSON serializable.', previous: $exception);
        }

        $record = ExportJob::create([
            'user_id' => $user->getKey(),
            'label' => $label,
            'export_class' => $exportClass,
            'export_args' => $args,
            'file_name' => self::safeFileName($fileName),
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
            'progress_stage' => ExportJob::STAGE_QUEUED,
            'progress' => 0,
            'total_rows' => 0,
            'processed_rows' => 0,
            'processed_chunks' => [],
        ]);

        ProcessExportJob::dispatch((int) $record->getKey())->onQueue('exports');

        return $record;
    }

    public static function isSupported(string $exportClass): bool
    {
        return in_array($exportClass, self::SUPPORTED_EXPORT_CLASSES, true);
    }

    private static function safeFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        $fileName = str_replace(["\r", "\n", "\0"], '', $fileName);
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: '';

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

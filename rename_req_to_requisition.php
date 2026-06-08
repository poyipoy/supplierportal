<?php

/**
 * Script untuk mengganti "Requirement" menjadi "Requisition" di seluruh codebase.
 * Pendekatan: case-preserving replacement.
 * - purchaseRequirement  => purchaseRequisition
 * - purchaseRequirements => purchaseRequisitions
 * - PurchaseRequirement  => PurchaseRequisition (class names, type hints, use statements)
 * - RequirementsExport   => RequisitionsExport
 */

$basePath = __DIR__;

// File-file yang akan diubah (relative path dari root project)
$files = [
    // Models
    'app/Models/User.php',
    'app/Models/Quotation.php',
    'app/Models/PurchaseOrder.php',
    'app/Models/Period.php',
    'app/Models/PrItem.php',
    'app/Models/Conversation.php',

    // Support
    'app/Support/ConversationPresenter.php',

    // Controllers
    'app/Http/Controllers/ConversationMessageController.php',
    'app/Http/Controllers/Purchasing/QuotationListController.php',
    'app/Http/Controllers/Purchasing/PurchaseOrderController.php',
    'app/Http/Controllers/Purchasing/PrItemController.php',
    'app/Http/Controllers/Purchasing/PurchasingController.php',
    'app/Http/Controllers/Purchasing/PriceComparisonController.php',
    'app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php',
    'app/Http/Controllers/Supplier/SupplierPriceHistoryController.php',
    'app/Http/Controllers/Supplier/SupplierController.php',
    'app/Http/Controllers/Supplier/QuotationController.php',
    'app/Http/Controllers/Purchasing/ExportController.php',

    // Exports
    'app/Exports/PurchaseOrdersExport.php',
    'app/Exports/RequirementsExport.php',

    // Views
    'resources/views/purchasing/conversations/index.blade.php',
    'resources/views/purchasing/quotations/show.blade.php',
    'resources/views/purchasing/quotations/index.blade.php',
    'resources/views/purchasing/po/show.blade.php',
    'resources/views/purchasing/po/create.blade.php',
    'resources/views/supplier/quotations/show.blade.php',
    'resources/views/supplier/po/show.blade.php',
    'resources/views/supplier/dashboard.blade.php',
    'resources/views/pdf/qc-inspection-pdf.blade.php',
    'resources/views/pdf/po-pdf.blade.php',
];

// Mapping: cari => ganti (urutan penting — lebih spesifik dulu)
$replacements = [
    // Plural method names & relation chains (case-sensitive)
    'purchaseRequirements'      => 'purchaseRequisitions',
    // Singular method names & relation chains
    'purchaseRequirement'       => 'purchaseRequisition',
    // Class names in use statements, type checks, etc.
    'PurchaseRequirement'       => 'PurchaseRequisition',
    // Export class name
    'RequirementsExport'        => 'RequisitionsExport',
];

$totalFilesChanged = 0;
$totalReplacements = 0;

foreach ($files as $relativePath) {
    $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!file_exists($fullPath)) {
        echo "[SKIP] File tidak ditemukan: $relativePath\n";
        continue;
    }

    $original = file_get_contents($fullPath);
    $modified = $original;
    $fileReplacementCount = 0;

    foreach ($replacements as $search => $replace) {
        $count = substr_count($modified, $search);
        if ($count > 0) {
            $modified = str_replace($search, $replace, $modified);
            $fileReplacementCount += $count;
        }
    }

    if ($modified !== $original) {
        file_put_contents($fullPath, $modified);
        echo "[CHANGED] $relativePath ($fileReplacementCount replacements)\n";
        $totalFilesChanged++;
        $totalReplacements += $fileReplacementCount;
    } else {
        echo "[OK]      $relativePath (no changes)\n";
    }
}

echo "\n=== SELESAI ===\n";
echo "Total file diubah : $totalFilesChanged\n";
echo "Total penggantian : $totalReplacements\n";

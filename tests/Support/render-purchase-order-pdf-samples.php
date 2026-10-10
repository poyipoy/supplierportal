<?php

use App\Support\PurchaseOrderPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$make = require __DIR__.'/purchase-order-pdf-fixture.php';
$directory = base_path('UI-REDESIGN-RESULT/PO-PDF-SAMPLES');
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
$samples = ['po-reference-layout' => $make(), 'po-multiple-pages' => $make(60, 'USD'), 'po-long-content' => $make(3, 'CNY')];
foreach ($samples as $sample) {
    foreach ($sample->quotations as $quotation) {
        foreach ($quotation->items as $item) {
            $item->forceFill(['offered_weight_per_unit' => 10, 'price_per_kg' => 10550]);
            $item->prItem->weight_needed = 10;
        }
    }
}
$samples['po-long-content']->notes = str_repeat('Special delivery requirements must remain readable on continuation pages. ', 150).'FINAL_NOTE_MARKER';
$samples['po-long-content']->quotations->first()->items->first()->prItem->material_name = str_repeat('Long material description including offered specification. ', 65).'FINAL_MATERIAL_MARKER';
foreach ($samples as $name => $po) {
    $document = PurchaseOrderPdf::data($po);
    $pdf = Pdf::loadView('pdf.po-pdf', compact('po', 'document'))->setPaper('a4', 'portrait');
    $pdf->save($directory.'/'.$name.'.pdf');
    echo $name.': '.count($document['pages']).' planned pages, '.$pdf->getDomPDF()->getCanvas()->get_page_count().' rendered pages'.PHP_EOL;
}

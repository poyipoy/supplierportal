<?php

namespace App\Support;

use App\Models\PurchaseOrder;
use App\Support\Pdf\FormDocument;
use RuntimeException;

/** Presentation and measured pagination for the fixed, English PO form. */
final class PurchaseOrderPdf
{
    private const LINE_HEIGHT = FormDocument::LINE_HEIGHT;

    private const NOTE_LINE_HEIGHT = FormDocument::NOTE_LINE_HEIGHT;

    public static function data(PurchaseOrder $po): array
    {
        FormDocument::metrics();
        $wrap = fn (string $text, float $width, float $size = 9): array => FormDocument::wrap($text, $width, $size);
        $supplier = $po->supplier;
        $profile = $supplier && ($supplier->exists || $supplier->relationLoaded('supplier')) ? $supplier->supplier : null;
        $supplierLines = $wrap($profile?->company_name ?: ($supplier?->name ?: '-'), 112);
        array_push($supplierLines, ...$wrap($profile?->address ?: '-', 112));
        array_push($supplierLines, ...$wrap('t : '.($profile?->phone ?: '-'), 112));
        $supplierLines[] = 'f : -';
        $tableTop = max(79.5, 44.0 + count($supplierLines) * self::LINE_HEIGHT + 8);
        $rowCapacity = 277 - $tableTop - 8.5;
        if ($rowCapacity < 20) {
            throw new RuntimeException('Supplier details are too long for the PO PDF header.');
        }

        // I-15: quantity, KG and amount come from the single ordered resolver.
        $lines = $po->orderedLines();
        $items = $lines->map(fn ($line) => $line->quotationItem)->values();
        $quotations = $items->pluck('quotation')->unique('id')->values();
        $terms = $quotations->map(fn ($q) => trim((string) $q->payment_terms) ?: '-')->unique();
        $paymentTerm = $terms->count() <= 1 ? ($terms->first() ?? '-') : $quotations->map(fn ($q) => ($q->purchaseRequisition?->pr_number ?: '-').': '.(trim((string) $q->payment_terms) ?: '-'))->implode('; ');
        $termLines = $wrap($paymentTerm, 166, 8);
        $notes = [];
        foreach (['validity', 'msds', 'transport', 'signed_copy'] as $key) {
            array_push($notes, ...$wrap('- '.trans('documents.po.notes.'.$key, [], 'en'), 166, 8));
        }
        $customNotes = trim((string) $po->notes) === '' ? [] : $wrap('- '.trim($po->notes), 166, 8);

        $rows = [];
        foreach ($lines as $index => $line) {
            $item = $line->quotationItem;
            $prItem = $item->prItem;
            $description = $wrap($prItem?->material_name ?: '-', 92.6);
            $dimension = $item->offered_dimension_label;
            if ($dimension === '-') {
                $dimension = $prItem?->dimension_label ?: '-';
            }
            $quantity = $line->qty;
            $amount = $line->amount;
            $unitPrice = $quantity > 0
                ? Money::normalize(bcdiv(Money::normalize($amount, 4), (string) $quantity, 8), 4)
                : null;
            $weight = self::number($line->kg);
            array_push($description, ...$wrap($dimension, 92.6));
            array_push($description, ...$wrap('HS Code: '.($prItem?->hs_code ?: '-').' | '.trans('documents.po.weight', [], 'en').': '.$weight.' kg', 92.6));
            $cells = [
                [(string) ($index + 1)], $description,
                $wrap(self::number($quantity, 2).' pcs', 26),
                $wrap(self::number($unitPrice), 23.5),
                $wrap(self::number($amount, 2), 23.5),
            ];
            // An exceptionally long description can continue without repeating its amount.
            $maxLines = max(1, (int) floor(($rowCapacity - 2) / self::LINE_HEIGHT));
            $lineCount = max(array_map('count', $cells));
            for ($offset = 0; $offset < $lineCount; $offset += $maxLines) {
                $fragment = array_map(fn ($cell) => array_slice($cell, $offset, $maxLines), $cells);
                $rows[] = ['cells' => $fragment, 'height' => max(array_map('count', $fragment)) * self::LINE_HEIGHT + 2];
            }
        }

        // Reserve enough room for one line, total box, notes and empty signature boxes.
        $minimumNoteTop = $tableTop + 8.5 + 6.1 + 4 + 17 + 5;
        $footerLineCapacity = max(1, (int) floor((242.3 - 4 - $minimumNoteTop) / self::NOTE_LINE_HEIGHT));
        $overflow = [];
        if (count($termLines) + count($notes) > $footerLineCapacity) {
            foreach ($termLines as $index => $line) {
                $overflow[] = ['label' => $index === 0 ? trans('documents.po.payment_term', [], 'en') : '', 'text' => $line];
            }
            $termLines = $wrap(trans('documents.po.continued_terms', [], 'en'), 166, 8);
        }
        $footerLines = [];
        foreach ($termLines as $index => $line) {
            $footerLines[] = ['label' => $index === 0 ? trans('documents.po.payment_term', [], 'en') : '', 'text' => $line];
        }
        foreach ($notes as $index => $line) {
            $footerLines[] = ['label' => $index === 0 ? trans('documents.po.note', [], 'en') : '', 'text' => $line];
        }
        $customCapacity = max(0, $footerLineCapacity - count($footerLines));
        if (count($customNotes) > $customCapacity) {
            array_push($overflow, ...array_map(fn ($line) => ['label' => '', 'text' => $line], array_splice($customNotes, 0, count($customNotes) - $customCapacity)));
        }
        foreach ($customNotes as $line) {
            $footerLines[] = ['label' => '', 'text' => $line];
        }
        $noteTop = min(213.4, 242.3 - 4 - count($footerLines) * self::NOTE_LINE_HEIGHT);
        $finalCapacity = max(6.1, $noteTop - $tableTop - 8.5 - 4 - 17 - 5);
        $pages = [['rows' => [], 'height' => 0, 'note_lines' => []]];
        foreach ($rows as $row) {
            $last = array_key_last($pages);
            if ($pages[$last]['height'] + $row['height'] > $rowCapacity && $pages[$last]['rows'] !== []) {
                $pages[] = ['rows' => [], 'height' => 0, 'note_lines' => []];
                $last++;
            }
            $pages[$last]['rows'][] = $row;
            $pages[$last]['height'] += $row['height'];
        }
        $last = array_key_last($pages);
        if ($pages[$last]['height'] > $finalCapacity) {
            $tail = [];
            $height = 0;
            while ($pages[$last]['rows'] !== []) {
                $row = end($pages[$last]['rows']);
                if ($height + $row['height'] > $finalCapacity) {
                    break;
                }
                array_unshift($tail, array_pop($pages[$last]['rows']));
                $height += $row['height'];
                $pages[$last]['height'] -= $row['height'];
            }
            $pages[] = ['rows' => $tail, 'height' => $height, 'note_lines' => []];
        }
        if ($overflow !== []) {
            // Very long notes continue on dedicated pages; total and signatures stay last.
            $final = array_pop($pages);
            if ($final['rows'] !== []) {
                $pages[] = $final;
                $final = ['rows' => [], 'height' => 0, 'note_lines' => []];
            }
            $noteCapacity = max(1, (int) floor((277 - $tableTop - 10) / self::NOTE_LINE_HEIGHT));
            foreach (array_chunk($overflow, $noteCapacity) as $lines) {
                $pages[] = ['rows' => [], 'height' => 0, 'note_lines' => $lines];
            }
            $pages[] = $final;
        }

        return [
            'pages' => $pages, 'table_top' => $tableTop - 10, 'note_top' => $noteTop - 10,
            'footer_lines' => $footerLines, 'supplier_lines' => $supplierLines,
            ...FormDocument::barcode($po->po_number),
            'company_header' => FormDocument::asset('images/po-company-header.png'),
            ...FormDocument::fonts(),
            'po_number_lines' => $wrap($po->po_number, 67),
            'currency' => strtoupper($po->currency ?: 'USD'),
            // Q7 (released only after G-PDF): PO logistics snapshot, fixed English form; "-" when NULL.
            'port_of_discharge' => $po->port_of_discharge ? trans('procurement.ports.'.$po->port_of_discharge, [], 'en') : '-',
            'incoterm' => $po->incoterm ?: '-',
            'date' => $po->created_at ? BusinessTime::toBusiness($po->created_at)->locale('en')->translatedFormat('d M Y') : '-',
            'receipt_date' => $po->estimated_arrival?->locale('en')->translatedFormat('d M Y') ?? '-',
            'total' => self::number($po->orderedAmountTotal(), 2),
        ];
    }

    private static function number(mixed $value, int $precision = 4): string
    {
        return FormDocument::number($value, $precision);
    }
}

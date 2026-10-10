<?php

namespace App\Support;

use App\Models\PurchaseOrder;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use RuntimeException;

/** Presentation and measured pagination for the fixed, English PO form. */
final class PurchaseOrderPdf
{
    private const MM = 72 / 25.4;

    private const LINE_HEIGHT = 4.1;

    private const NOTE_LINE_HEIGHT = 3.8;

    public static function data(PurchaseOrder $po): array
    {
        $metrics = (new Dompdf(new Options(config('dompdf.options', []))))->getFontMetrics();
        File::ensureDirectoryExists($metrics->getOptions()->getFontDir());
        foreach (['normal' => 'Regular', 'bold' => 'Bold'] as $weight => $file) {
            if (! $metrics->registerFont(['family' => 'PO Noto Sans', 'style' => 'normal', 'weight' => $weight], self::asset("fonts/noto-sans/NotoSans-{$file}.ttf"))) {
                throw new RuntimeException('Unable to load the local PO PDF font.');
            }
        }
        $font = $metrics->getFont('PO Noto Sans', 'normal');
        $wrap = fn (string $text, float $width, float $size = 9): array => self::wrap($text, $width, $size, $metrics, $font);
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

        $items = $po->commercialQuotationItems();
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
        foreach ($items as $index => $item) {
            $prItem = $item->prItem;
            $description = $wrap($prItem?->material_name ?: '-', 92.6);
            $dimension = $item->offered_dimension_label;
            if ($dimension === '-') {
                $dimension = $prItem?->dimension_label ?: '-';
            }
            $quantity = $item->fulfillment_quantity;
            $amount = $item->resolved_amount;
            $unitPrice = $quantity > 0
                ? Money::normalize(bcdiv(Money::normalize($amount, 4), (string) $quantity, 8), 4)
                : null;
            $weight = self::number($item->offered_total_weight ?? $prItem?->total_weight);
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

        $barcode = (new TypeCode128)->getBarcode($po->po_number);
        $svg = (new SvgRenderer)->setSvgType(SvgRenderer::TYPE_SVG_INLINE)->render($barcode, max(125, $barcode->getWidth()), 34);

        return [
            'pages' => $pages, 'table_top' => $tableTop - 10, 'note_top' => $noteTop - 10,
            'footer_lines' => $footerLines, 'supplier_lines' => $supplierLines,
            'barcode' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'barcode_width' => max(33.08, $barcode->getWidth() * 0.22),
            'company_header' => self::asset('images/po-company-header.png'),
            'font_regular' => self::asset('fonts/noto-sans/NotoSans-Regular.ttf'),
            'font_bold' => self::asset('fonts/noto-sans/NotoSans-Bold.ttf'),
            'po_number_lines' => $wrap($po->po_number, 67),
            'currency' => strtoupper($po->currency ?: 'USD'),
            'date' => $po->created_at ? BusinessTime::toBusiness($po->created_at)->locale('en')->translatedFormat('d M Y') : '-',
            'receipt_date' => $po->estimated_arrival?->locale('en')->translatedFormat('d M Y') ?? '-',
            'total' => self::number($items->sum('resolved_amount'), 2),
        ];
    }

    private static function asset(string $path): string
    {
        return str_replace('\\', '/', public_path('assets/'.$path));
    }

    private static function number(mixed $value, int $precision = 4): string
    {
        if ($value === null) {
            return '-';
        }
        $text = number_format((float) $value, $precision, '.', ',');
        if ($precision > 2) {
            $text = rtrim($text, '0');
            $fraction = strlen(substr(strrchr($text, '.'), 1));
            $text .= str_repeat('0', max(0, 2 - $fraction));
        }

        return $text;
    }

    private static function wrap(string $text, float $width, float $size, FontMetrics $metrics, string $font): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $candidate = $line.$word;
                if ($line !== '' && $metrics->getTextWidth($candidate, $font, $size) > $width * self::MM) {
                    $lines[] = rtrim($line);
                    $line = '';
                }
                foreach (mb_str_split($word) as $character) {
                    $candidate = $line.$character;
                    if ($line !== '' && $metrics->getTextWidth($candidate, $font, $size) > $width * self::MM) {
                        $lines[] = rtrim($line);
                        $line = '';
                    }
                    $line .= $character;
                }
                $line .= ' ';
            }
            $lines[] = rtrim($line);
        }

        return $lines ?: ['-'];
    }
}

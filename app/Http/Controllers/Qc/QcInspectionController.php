<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class QcInspectionController extends Controller
{
    /**
     * Tampilkan daftar inspeksi (Menunggu vs Riwayat)
     */
    public function index()
    {
        $waitingCount = PurchaseOrder::where('status', 'waiting_qc')->count();
        $historyCount = QcInspection::count();

        return view('qc.inspections.index', compact('waitingCount', 'historyCount'));
    }

    public function dataWaiting(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'quotations.items'])
            ->where('status', 'waiting_qc')
            ->orderBy('actual_arrival', 'asc');

        return DataTables::eloquent($query)
            ->addColumn('po_number_display', fn($po) => $po->po_number)
            ->addColumn('supplier_name', fn($po) => $po->supplier->name ?? '-')
            ->addColumn('arrival_date', fn($po) => $po->actual_arrival ? $po->actual_arrival->format('d M Y') : '-')
            ->addColumn('item_count', fn($po) => $po->quotations->sum(fn($q) => $q->items->count()) . ' Item')
            ->addColumn('action', fn($po) => '<a href="' . route('qc.inspections.create', $po->id) . '" class="btn btn-sm btn-primary" style="background-color: var(--adasi-blue);"><i class="bi bi-clipboard-check me-1"></i> Mulai Inspeksi</a>')
            ->rawColumns(['action'])
            ->make(true);
    }

    public function dataHistory(Request $request)
    {
        $query = QcInspection::with(['purchaseOrder.supplier', 'inspector'])
            ->orderBy('inspected_at', 'desc');

        return DataTables::eloquent($query)
            ->addColumn('po_number', fn($i) => $i->purchaseOrder->po_number ?? '-')
            ->addColumn('supplier_name', fn($i) => $i->purchaseOrder->supplier->name ?? '-')
            ->addColumn('inspected_date', fn($i) => $i->inspected_at->format('d M Y, H:i'))
            ->addColumn('status_badge', fn($i) => $i->status === 'ok'
                ? '<span class="badge bg-success">OK</span>'
                : '<span class="badge bg-danger">NG</span>')
            ->addColumn('inspector_name', fn($i) => $i->inspector->name ?? '-')
            ->addColumn('action', fn($i) => '<a href="' . route('qc.inspections.show', $i->id) . '" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i> Detail</a>')
            ->rawColumns(['status_badge', 'action'])
            ->make(true);
    }

    /**
     * Form mulai inspeksi
     */
    public function create($po_id)
    {
        $po = PurchaseOrder::with(['supplier', 'quotations.items.prItem'])->findOrFail($po_id);

        if ($po->status !== 'waiting_qc') {
            return redirect()->route('qc.inspections.index')->with('error', 'PO ini tidak dalam status Menunggu QC.');
        }

        // Cek apakah sudah pernah diinspeksi
        if (QcInspection::where('po_id', $po->id)->exists()) {
            return redirect()->route('qc.inspections.index')->with('error', 'PO ini sudah pernah diinspeksi.');
        }

        return view('qc.inspections.create', compact('po'));
    }

    /**
     * Simpan hasil inspeksi
     */
    public function store(Request $request, $po_id)
    {
        $po = PurchaseOrder::with(['supplier', 'quotations.items.prItem'])->findOrFail($po_id);

        if ($po->status !== 'waiting_qc') {
            return redirect()->route('qc.inspections.index')->with('error', 'PO ini tidak valid untuk diinspeksi.');
        }

        $rules = [
            'items' => 'required|array',
            'items.*.pr_item_id' => 'required|exists:pr_items,id',
            'items.*.actual_thickness' => 'nullable|numeric',
            'items.*.actual_d_inner' => 'nullable|numeric',
            'items.*.actual_d_outer' => 'nullable|numeric',
            'items.*.actual_width' => 'nullable|numeric',
            'items.*.actual_length' => 'nullable|numeric',
            'items.*.actual_weight' => 'nullable|numeric',
            'items.*.status' => 'required|in:ok,ng',
            'items.*.notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'nullable|array',
            'attachments.*.*' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
        ];

        foreach ($request->input('items', []) as $index => $itemData) {
            if (($itemData['status'] ?? null) === 'ng') {
                $rules["attachments.{$index}"] = 'required|array|min:1';
                $rules["attachments.{$index}.*"] = 'required|file|mimes:jpg,jpeg,png|max:10240';
            }
        }

        $request->validate($rules, [
            'attachments.*.required' => 'Foto bukti wajib diunggah untuk setiap item berstatus NG.',
            'attachments.*.min' => 'Foto bukti wajib diunggah untuk setiap item berstatus NG.',
            'attachments.*.*.required' => 'Foto bukti wajib diunggah untuk setiap item berstatus NG.',
            'attachments.*.*.mimes' => 'Foto bukti NG harus berupa file JPG, JPEG, atau PNG.',
            'attachments.*.*.max' => 'Ukuran foto bukti NG maksimal 10MB per file.',
        ]);

        try {
            DB::beginTransaction();

            // Cek status keseluruhan
            $overallStatus = 'ok';
            foreach ($request->items as $itemData) {
                if ($itemData['status'] === 'ng') {
                    $overallStatus = 'ng';
                    break;
                }
            }

            // Buat Inspeksi
            $inspection = QcInspection::create([
                'po_id' => $po->id,
                'inspected_by' => auth()->id(),
                'status' => $overallStatus,
                'inspected_at' => now(),
            ]);

            $uploadedFiles = $request->file('attachments', []);

            // Simpan QC Items
            foreach ($request->items as $index => $itemData) {
                $qcItem = QcItem::create([
                    'inspection_id' => $inspection->id,
                    'pr_item_id' => $itemData['pr_item_id'],
                    'actual_thickness' => $itemData['actual_thickness'] ?? null,
                    'actual_d_inner' => $itemData['actual_d_inner'] ?? null,
                    'actual_d_outer' => $itemData['actual_d_outer'] ?? null,
                    'actual_width' => $itemData['actual_width'] ?? null,
                    'actual_length' => $itemData['actual_length'] ?? null,
                    'actual_weight' => $itemData['actual_weight'] ?? null,
                    'status' => $itemData['status'],
                    'notes' => $itemData['notes'] ?? null,
                ]);

                if (($itemData['status'] ?? null) === 'ng') {
                    foreach ($uploadedFiles[$index] ?? [] as $file) {
                        if (! $file || ! $file->isValid()) {
                            continue;
                        }

                        // Gunakan getPathname() untuk menghindari getRealPath() yang bernilai false di Windows
                        $fileName = $file->hashName();
                        $path = 'attachments/' . now()->format('Y/m') . '/' . $fileName;

                        $stream = fopen($file->getPathname(), 'r');
                        if ($stream) {
                            \Illuminate\Support\Facades\Storage::disk('private')->put($path, $stream);
                            fclose($stream);

                            $inspection->attachments()->create([
                                'file_path' => $path,
                                'file_name' => $file->getClientOriginalName(),
                                'file_type' => $file->getMimeType(),
                                'uploaded_by' => auth()->id(),
                            ]);
                        }
                    }
                }
            }

            if ($overallStatus === 'ng' && ! $inspection->attachments()->exists()) {
                throw new \RuntimeException('Foto bukti NG tidak terkirim. Silakan unggah ulang foto bukti sebelum menyimpan inspeksi.');
            }

            // Update PO Status & Kirim Notifikasi
            $purchasingUsers = User::where('role', 'purchasing')->get();

            if ($overallStatus === 'ok') {
                $po->update(['status' => 'completed']);
                foreach ($purchasingUsers as $pUser) {
                    /** @var User $pUser */
                    $pUser->notify(new SystemNotification(
                        'Inspeksi QC Selesai',
                        'Material dari ' . $po->po_number . ' telah lulus inspeksi QC',
                        route('purchasing.purchase-orders.show', $po->id),
                        'bi-check-circle text-success'
                    ));
                }
            } else {
                $po->update(['status' => 'claim_needed']);
                foreach ($purchasingUsers as $pUser) {
                    /** @var User $pUser */
                    $pUser->notify(new SystemNotification(
                        'Material NG Ditemukan',
                        'Material dari ' . $po->po_number . ' dinyatakan NG oleh QC. Silakan ajukan klaim ke supplier.',
                        route('purchasing.claims.create', $inspection->id),
                        'bi-exclamation-triangle text-danger'
                    ));
                }
            }

            DB::commit();

            return redirect()->route('qc.inspections.show', $inspection->id)->with('success', 'Hasil inspeksi berhasil disimpan.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Gagal menyimpan inspeksi: ' . $e->getMessage());
        }
    }

    /**
     * Tampilkan detail inspeksi
     */
    public function show($id)
    {
        $inspection = QcInspection::with([
            'purchaseOrder.supplier',
            'inspector',
            'items.prItem',
            'attachments'
        ])->findOrFail($id);

        return view('qc.inspections.show', compact('inspection'));
    }

    /**
     * Tambah foto bukti untuk inspeksi NG yang sudah tersimpan.
     */
    public function storeAttachments(Request $request, $id)
    {
        $inspection = QcInspection::findOrFail($id);

        if ($inspection->status !== 'ng') {
            return back()->with('error', 'Foto bukti hanya dapat ditambahkan untuk inspeksi berstatus NG.');
        }

        $request->validate([
            'attachments' => 'required|array|min:1',
            'attachments.*' => 'required|file|mimes:jpg,jpeg,png|max:10240',
        ], [
            'attachments.required' => 'Pilih minimal 1 foto bukti NG.',
            'attachments.min' => 'Pilih minimal 1 foto bukti NG.',
            'attachments.*.mimes' => 'Foto bukti NG harus berupa file JPG, JPEG, atau PNG.',
            'attachments.*.max' => 'Ukuran foto bukti NG maksimal 10MB per file.',
        ]);

        foreach ($request->file('attachments', []) as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            // Gunakan getPathname() untuk menghindari getRealPath() yang bernilai false di Windows
            $fileName = $file->hashName();
            $path = 'attachments/' . now()->format('Y/m') . '/' . $fileName;

            $stream = fopen($file->getPathname(), 'r');
            if ($stream) {
                \Illuminate\Support\Facades\Storage::disk('private')->put($path, $stream);
                fclose($stream);

                $inspection->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $file->getMimeType(),
                    'uploaded_by' => auth()->id(),
                ]);
            }
        }

        return back()->with('success', 'Foto bukti QC berhasil ditambahkan.');
    }
}

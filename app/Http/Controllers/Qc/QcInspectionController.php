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

class QcInspectionController extends Controller
{
    /**
     * Tampilkan daftar inspeksi (Menunggu vs Riwayat)
     */
    public function index()
    {
        // PO yang menunggu QC
        $waitingPOs = PurchaseOrder::with(['quotation.supplier', 'quotation.items'])
            ->where('status', 'waiting_qc')
            ->orderBy('actual_arrival', 'asc')
            ->get();

        // Riwayat inspeksi
        $history = QcInspection::with(['purchaseOrder.quotation.supplier', 'inspector'])
            ->orderBy('inspected_at', 'desc')
            ->get();

        return view('qc.inspections.index', compact('waitingPOs', 'history'));
    }

    /**
     * Form mulai inspeksi
     */
    public function create($po_id)
    {
        $po = PurchaseOrder::with(['quotation.supplier', 'quotation.items.prItem'])->findOrFail($po_id);

        if ($po->status !== 'waiting_qc') {
            return redirect()->route('qc.inspections.index')->with('error', __('PO ini tidak dalam status Menunggu QC.'));
        }

        // Cek apakah sudah pernah diinspeksi
        if (QcInspection::where('po_id', $po->id)->exists()) {
            return redirect()->route('qc.inspections.index')->with('error', __('PO ini sudah pernah diinspeksi.'));
        }

        return view('qc.inspections.create', compact('po'));
    }

    /**
     * Simpan hasil inspeksi
     */
    public function store(Request $request, $po_id)
    {
        $po = PurchaseOrder::with(['quotation.supplier', 'quotation.items.prItem'])->findOrFail($po_id);

        if ($po->status !== 'waiting_qc') {
            return redirect()->route('qc.inspections.index')->with('error', __('PO ini tidak valid untuk diinspeksi.'));
        }

        $request->validate([
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
            'attachments.*.*' => 'nullable|file|mimes:jpg,jpeg,png|max:10240', // Attachments dikelompokkan per index item
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
            }

            // Handle Uploads per QC Item using the general inspection as the morph root (since attachments belong to the inspection in general, but let's see. Wait, if it belongs to inspection, we can just save it morphMany on QcInspection).
            // But we need to know which attachment belongs to which item? The requirement says:
            // "Foto bukti NG ditampilkan sebagai thumbnail gallery" on show page. It's fine to attach them to the Inspection model.
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $itemIndex => $files) {
                    foreach ($files as $file) {
                        $path = $file->store('attachments/' . now()->format('Y/m'), 'private');
                        $inspection->attachments()->create([
                            'file_path' => $path,
                            'file_name' => $file->getClientOriginalName(),
                            'file_type' => $file->getMimeType(),
                            'uploaded_by' => auth()->id(),
                        ]);
                    }
                }
            }

            // Update PO Status & Kirim Notifikasi
            $purchasingUsers = User::where('role', 'purchasing')->get();

            if ($overallStatus === 'ok') {
                $po->update(['status' => 'completed']);
                foreach ($purchasingUsers as $pUser) {
                    /** @var User $pUser */
                    $pUser->notify(new SystemNotification(
                        'Inspeksi QC Selesai',
                        'Material dari :po_number telah lulus inspeksi QC',
                        route('purchasing.purchase-orders.show', $po->id),
                        'bi-check-circle text-success',
                        [],
                        ['po_number' => $po->po_number]
                    ));
                }
            } else {
                $po->update(['status' => 'claim_needed']);
                foreach ($purchasingUsers as $pUser) {
                    /** @var User $pUser */
                    $pUser->notify(new SystemNotification(
                        'Material NG Ditemukan',
                        'Material dari :po_number dinyatakan NG oleh QC. Silakan ajukan klaim ke supplier.',
                        route('purchasing.claims.create', $inspection->id),
                        'bi-exclamation-triangle text-danger',
                        [],
                        ['po_number' => $po->po_number]
                    ));
                }
            }

            DB::commit();

            return redirect()->route('qc.inspections.show', $inspection->id)->with('success', __('Hasil inspeksi berhasil disimpan.'));

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', __('Gagal menyimpan inspeksi: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Tampilkan detail inspeksi
     */
    public function show($id)
    {
        $inspection = QcInspection::with([
            'purchaseOrder.quotation.supplier',
            'inspector',
            'items.prItem',
            'attachments'
        ])->findOrFail($id);

        return view('qc.inspections.show', compact('inspection'));
    }
}

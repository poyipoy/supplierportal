<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\Procurement\OrderSelectionInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PriceComparisonAwardController extends Controller
{
    use PriceComparisonSharedTrait;

    /**
     * Save item-level awards and optionally generate PO(s).
     */
    public function saveItemAwards(
        Request $request,
        PrItemAwardService $awardService,
        PurchaseOrderGenerationService $poService
    ) {
        $request->validate([
            'pr_id' => 'required',
            'awards' => 'required|array|min:1',
            'awards.*' => 'required|integer|distinct|exists:quotation_items,id',
            'action' => 'nullable|string|in:save,generate_pos',
            'notes' => 'nullable|string',
            'estimated_arrival' => 'nullable|date',
            'order_qty' => 'nullable|array',
            'accept_spec' => 'nullable|array',
        ]);

        $pr = $this->resolveHashedQueryModel(PurchaseRequisition::class, $request->input('pr_id'));
        $orderSelections = OrderSelectionInput::fromRequest($request);

        $selections = [];
        foreach ($request->input('awards', []) as $prItemId => $quotationItemId) {
            if ($prItemId && $quotationItemId) {
                $selections[(int) $prItemId] = (int) $quotationItemId;
            }
        }

        if (empty($selections)) {
            return back()->with('error', __('purchasing.copy.please_select_at_least_one_item_offer'));
        }

        try {
            if ($request->input('action') === 'generate_pos') {
                $pos = DB::transaction(function () use ($pr, $selections, $orderSelections, $awardService, $poService, $request) {
                    $lockedPr = PurchaseRequisition::whereKey($pr->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $prItemIds = array_keys($selections);
                    sort($prItemIds);
                    $lockedPrItems = PrItem::query()
                        ->where('pr_id', $lockedPr->id)
                        ->whereIn('id', $prItemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($lockedPrItems->count() !== count($prItemIds)) {
                        throw new \InvalidArgumentException(__('purchasing.copy.one_or_more_selected_pr_items_do_not_belong_to_this_requisition'));
                    }

                    $quotationItemIds = array_values($selections);
                    sort($quotationItemIds);
                    $quotationIds = QuotationItem::query()
                        ->whereIn('id', $quotationItemIds)
                        ->pluck('quotation_id')
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    $lockedQuotations = Quotation::query()
                        ->whereIn('id', $quotationIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($lockedQuotations->count() !== count($quotationIds)) {
                        throw new \InvalidArgumentException(__('purchasing.copy.one_or_more_selected_quotations_could_not_be_found'));
                    }

                    foreach ($lockedQuotations as $quotation) {
                        if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                            throw new \InvalidArgumentException(
                                __('purchasing.comparison.ineligible_status', ['quotation' => $quotation->id, 'status' => $quotation->status])
                            );
                        }
                    }

                    $lockedQuotationItems = QuotationItem::query()
                        ->whereIn('id', $quotationItemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($lockedQuotationItems->count() !== count($quotationItemIds)) {
                        throw new \InvalidArgumentException(__('purchasing.copy.one_or_more_selected_quotation_items_could_not_be_found'));
                    }

                    PrItemAward::query()
                        ->whereIn('pr_item_id', $prItemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $awards = $awardService->awardBatch($lockedPr, $selections, auth()->user(), $orderSelections);

                    return $poService->generateFromAwards($awards, auth()->user(), [
                        'estimated_arrival' => $request->input('estimated_arrival'),
                        'notes' => $request->input('notes'),
                    ]);
                });

                if ($pos->count() === 1) {
                    return redirect()->route('purchasing.purchase-orders.show', $pos->first())
                        ->with('success', __('purchasing.feedback.selected_po', ['po' => $pos->first()->po_number]));
                }

                $poNumbers = $pos->pluck('po_number')->implode(', ');

                return redirect()->route('purchasing.purchase-orders.index')
                    ->with('success', __('purchasing.feedback.selected_pos', ['count' => $pos->count(), 'pos' => $poNumbers]));
            }

            $awardService->awardBatch($pr, $selections, auth()->user(), $orderSelections);

            return back()->with('success', __('purchasing.copy.selected_item_offers_saved_successfully'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException $e) {
            report($e);

            return back()->withInput()->with(
                'error',
                __('purchasing.copy.the_item_selection_or_purchase_order_state_changed_while_it_was_being_finalized_please_refresh_and_t')
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('purchasing.copy.failed_to_finalize_item_selections_and_purchase_orders'));
        }
    }
}

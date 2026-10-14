<div class="modal fade local-po-gr-modal" id="editPoGrModal" tabindex="-1" aria-labelledby="editPoGrTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <form method="POST" data-gr-edit-form>
            @csrf @method('PUT')
            <div class="modal-header"><h2 id="editPoGrTitle" class="modal-title tw-text-ui-sm tw-font-bold">{{ __('local_procurement.gr_table.edit_title') }} <span data-gr-modal-number></span></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button></div>
            <div class="modal-body tw-space-y-3">
                <div><label for="editPoGrNumber" class="form-label">{{ __('local_procurement.surface.gr_number') }}</label><input id="editPoGrNumber" name="gr_number" maxlength="100" class="form-control" required></div>
                <x-ui.date-picker id="editPoGrDate" name="gr_date" :label="__('local_procurement.labels.gr_date')" required="true" />
                <div><label for="editPoGrQty" class="form-label">{{ __('common.fields.qty') }}</label><input id="editPoGrQty" name="qty" type="number" step="0.001" min="0.001" class="form-control" required></div>
                <div><label for="editPoGrUom" class="form-label">{{ __('local_procurement.labels.uom') }}</label><select id="editPoGrUom" name="uom" class="form-select" required><option value="">{{ __('local_procurement.labels.choose_uom') }}</option>@foreach(\App\Models\LocalGoodsReceipt::UOMS as $uom)<option value="{{ $uom }}">{{ $uom }}</option>@endforeach</select></div>
                <div><label for="editPoGrDescription" class="form-label">{{ __('local_procurement.labels.goods_description') }}</label><textarea id="editPoGrDescription" name="description" maxlength="2000" class="form-control" rows="2"></textarea></div>
                <div><label for="editPoGrNotes" class="form-label">{{ __('local_invoice.labels.optional_notes') }}</label><textarea id="editPoGrNotes" name="notes" maxlength="2000" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button><x-ui.button type="submit">{{ __('local_invoice.actions.save_changes') }}</x-ui.button></div>
        </form>
    </div></div>
</div>
<div class="modal fade local-po-gr-modal" id="cancelPoGrModal" tabindex="-1" aria-labelledby="cancelPoGrTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" data-gr-cancel-form>
            @csrf
            <div class="modal-header"><h2 id="cancelPoGrTitle" class="modal-title tw-text-ui-sm tw-font-bold">{{ __('local_procurement.actions.cancel_gr') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button></div>
            <div class="modal-body"><p class="tw-font-semibold" data-gr-modal-number></p><p>{{ __('local_procurement.surface.cancel_gr_help') }}</p></div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button><x-ui.button type="submit" variant="danger">{{ __('local_procurement.surface.cancel_gr_yes') }}</x-ui.button></div>
        </form>
    </div></div>
</div>

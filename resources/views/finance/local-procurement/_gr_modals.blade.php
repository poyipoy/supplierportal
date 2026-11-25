<dialog id="editPoGrModal" class="ui-native-dialog local-po-gr-modal" aria-labelledby="editPoGrTitle" closedby="any">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <form method="POST" data-gr-edit-form>
            @csrf @method('PUT')
            <div class="modal-header"><h2 id="editPoGrTitle" class="modal-title tw:text-ui-sm tw:font-bold">{{ __('local_procurement.gr_table.edit_title') }} <span data-gr-modal-number></span></h2><button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('local_invoice.actions.close') }}"></button></div>
            <div class="modal-body tw:space-y-3">
                <div><label for="editPoGrNumber" class="ui-label">{{ __('local_procurement.surface.gr_number') }}</label><input id="editPoGrNumber" name="gr_number" maxlength="100" class="ui-input" required></div>
                <x-ui.date-picker id="editPoGrDate" name="gr_date" :label="__('local_procurement.labels.gr_date')" required="true" />
                <div><label for="editPoGrQty" class="ui-label">{{ __('common.fields.qty') }}</label><input id="editPoGrQty" name="qty" type="number" step="0.001" min="0.001" class="ui-input" required></div>
                <div><label for="editPoGrUom" class="ui-label">{{ __('local_procurement.labels.uom') }}</label><select id="editPoGrUom" name="uom" class="ui-select" required><option value="">{{ __('local_procurement.labels.choose_uom') }}</option>@foreach(\App\Models\LocalGoodsReceipt::UOMS as $uom)<option value="{{ $uom }}">{{ $uom }}</option>@endforeach</select></div>
                <div><label for="editPoGrDescription" class="ui-label">{{ __('local_procurement.labels.goods_description') }}</label><textarea id="editPoGrDescription" name="description" maxlength="2000" class="ui-textarea" rows="2"></textarea></div>
                <div><label for="editPoGrNotes" class="ui-label">{{ __('local_invoice.labels.optional_notes') }}</label><textarea id="editPoGrNotes" name="notes" maxlength="2000" class="ui-textarea" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-ui-modal-close>{{ __('local_invoice.actions.cancel') }}</x-ui.button><x-ui.button type="submit">{{ __('local_invoice.actions.save_changes') }}</x-ui.button></div>
        </form>
    </div></div>
</dialog>
<dialog id="cancelPoGrModal" class="ui-native-dialog local-po-gr-modal" aria-labelledby="cancelPoGrTitle" closedby="any">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" data-gr-cancel-form>
            @csrf
            <div class="modal-header"><h2 id="cancelPoGrTitle" class="modal-title tw:text-ui-sm tw:font-bold">{{ __('local_procurement.actions.cancel_gr') }}</h2><button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('local_invoice.actions.close') }}"></button></div>
            <div class="modal-body"><p class="tw:font-semibold" data-gr-modal-number></p><p>{{ __('local_procurement.surface.cancel_gr_help') }}</p></div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-ui-modal-close>{{ __('local_invoice.actions.cancel') }}</x-ui.button><x-ui.button type="submit" variant="danger">{{ __('local_procurement.surface.cancel_gr_yes') }}</x-ui.button></div>
        </form>
    </div></div>
</dialog>
<dialog id="uploadGrDocumentModal" class="ui-native-dialog local-po-gr-modal" aria-labelledby="uploadGrDocumentTitle" closedby="any">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" enctype="multipart/form-data" data-gr-document-form data-async-submit data-async-summary-skip-file-errors="true">
            @csrf
            <div class="modal-header"><h2 id="uploadGrDocumentTitle" class="modal-title tw:text-ui-sm tw:font-bold">{{ __('local_procurement.gr_documents.row_title') }} <span data-gr-modal-number></span></h2><button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('local_invoice.actions.close') }}"></button></div>
            <div class="modal-body tw:space-y-3">
                <p class="tw:text-ui-xs tw:text-on-surface-variant tw:mb-0">{{ __('local_procurement.gr_documents.row_help') }}</p>
                <x-ui.file-upload name="file" id="grRowDocumentFile" accept=".pdf" :label="__('local_procurement.gr_documents.row_file')" :helper="__('local_procurement.gr_documents.optional_help')" :max-size-mb="5" required />
            </div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-ui-modal-close>{{ __('local_invoice.actions.cancel') }}</x-ui.button><x-ui.button type="submit"><x-ui.icon name="upload" size="sm" /><span>{{ __('local_invoice.form.document_upload') }}</span></x-ui.button></div>
        </form>
    </div></div>
</dialog>

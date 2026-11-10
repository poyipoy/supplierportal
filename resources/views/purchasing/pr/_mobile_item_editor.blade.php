{{-- Mobile Material Item Editor & Adaptive Cards View for PR Create & Edit --}}
<div class="d-md-none tw:mb-4" id="prMobileItemSection">
    {{-- Header: Counter & Mode Switcher --}}
    <div class="tw:flex tw:items-center tw:justify-between tw:mb-2.5 tw:px-1">
        <div class="tw:flex tw:items-center tw:gap-2">
            <span class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">
                {{ __('purchasing.copy.material_requirements') }}
            </span>
            <span class="tw:inline-flex tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold tw:bg-primary-container tw:text-primary ui-tabular-nums" id="prMobileItemCount">
                0
            </span>
        </div>
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary tw:text-ui-xs tw:py-1 tw:px-2.5 tw:rounded-ui-xs"
            id="btnTogglePrTableView"
            aria-expanded="false"
        >
            <x-ui.icon name="table" size="xs" class="me-1" />
            <span id="btnTogglePrTableLabel">Lihat Tabel Penuh</span>
        </button>
    </div>

    {{-- Cards List Container --}}
    <div id="prMobileCardsList" class="tw:flex tw:flex-col tw:gap-3 tw:mb-3"></div>

    {{-- Empty State (Shown when no items exist) --}}
    <div id="prMobileEmptyState" class="tw:p-6 tw:text-center tw:rounded-ui-md tw:border tw:border-dashed tw:border-outline tw:bg-surface-container-low tw:mb-3">
        <div class="tw:w-10 tw:h-10 tw:rounded-full tw:bg-surface-container tw:text-on-surface-variant tw:flex tw:items-center tw:justify-center tw:mx-auto tw:mb-2">
            <x-ui.icon name="package-search" size="md" />
        </div>
        <div class="tw:text-on-surface tw:text-ui-sm tw:font-bold">Belum Ada Material Ditambahkan</div>
        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-1 tw:max-w-xs tw:mx-auto">
            Ketuk tombol tambah di bawah untuk memasukkan spesifikasi material dengan mudah.
        </div>
    </div>

    {{-- Prominent Add Button --}}
    <x-ui.button
        type="button"
        size="md"
        id="btnOpenMobileMaterialModal"
        class="tw:w-full tw:justify-center tw:min-h-12 tw:gap-2 tw:text-ui-sm tw:font-bold tw:shadow-xs"
    >
        <x-ui.icon name="plus-circle" size="md" />
        <span>{{ __('purchasing.copy.add_material') }}</span>
    </x-ui.button>
</div>

{{-- Mobile Material Modal Editor (Bottom Sheet / Fullscreen Dialog) --}}
<div class="modal fade" id="mobileMaterialModal" tabindex="-1" aria-labelledby="mobileMaterialModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-lg">
        <div class="modal-content tw:bg-surface tw:border tw:border-outline tw:rounded-ui-lg">
            {{-- Modal Header --}}
            <div class="modal-header tw:bg-surface-container tw:border-b tw:border-outline-variant tw:py-3 tw:px-4">
                <div class="tw:flex tw:items-center tw:gap-2">
                    <div class="tw:w-8 tw:h-8 tw:rounded-ui-sm tw:bg-primary-container tw:text-primary tw:flex tw:items-center tw:justify-center">
                        <x-ui.icon name="package" size="sm" />
                    </div>
                    <h5 class="modal-title tw:text-ui-base tw:font-bold tw:text-on-surface m-0" id="mobileMaterialModalTitle">
                        Tambah Material
                    </h5>
                </div>
                <button type="button" class="btn-close ui-focus-ring" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            {{-- Modal Body --}}
            <div class="modal-body tw:p-4 tw:space-y-4">
                <input type="hidden" id="mmEditingIndex" value="-1">
                <input type="hidden" id="mmMaterialMasterId" value="">
                <input type="hidden" id="mmHsCodeManualOverride" value="0">
                <input type="hidden" id="mmWeightManualOverride" value="0">

                {{-- Step 1: Material Master Search --}}
                <div class="tw:space-y-1.5">
                    <label for="mmMaterialSearch" class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                        {{ __('purchasing.copy.material') }} <span class="text-danger">*</span>
                    </label>
                    <div class="position-relative">
                        <div class="input-group">
                            <span class="input-group-text tw:bg-surface-container tw:border-outline">
                                <x-ui.icon name="search" size="xs" />
                            </span>
                            <input
                                type="text"
                                id="mmMaterialSearch"
                                class="form-control form-control-sm"
                                placeholder="{{ __('purchasing.copy.search_grade_or_name_e_g_skd11_dc53_s50c') }}"
                                autocomplete="off"
                            >
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm d-none"
                                id="mmBtnClearMaterial"
                                title="Hapus pilihan"
                            >
                                <x-ui.icon name="x" size="xs" />
                            </button>
                        </div>

                        {{-- Autocomplete Results List --}}
                        <div
                            id="mmMaterialResults"
                            class="list-group shadow-lg d-none tw:absolute tw:left-0 tw:right-0 tw:top-full tw:mt-1 tw:bg-surface tw:z-[1070] tw:max-h-56 tw:overflow-y-auto tw:rounded-ui-sm tw:border tw:border-outline"
                            role="listbox"
                        ></div>
                    </div>

                    {{-- Selected Material Feedback Box --}}
                    <div id="mmSelectedFeedback" class="d-none tw:mt-2 tw:p-2.5 tw:rounded-ui-sm tw:bg-surface-container-low tw:border tw:border-outline-variant tw:flex tw:items-center tw:justify-between">
                        <div class="tw:min-w-0">
                            <div class="tw:text-ui-xs tw:font-bold tw:text-on-surface" id="mmSelectedName"></div>
                            <div class="tw:text-[11px] tw:text-on-surface-variant" id="mmSelectedGrade"></div>
                        </div>
                        <span class="tw:inline-flex tw:items-center tw:gap-1 tw:text-[11px] tw:font-semibold tw:text-success tw:bg-success-container tw:px-2 tw:py-0.5 tw:rounded-ui-xs">
                            <x-ui.icon name="check-circle" size="xs" /> Terpilih
                        </span>
                    </div>
                </div>

                {{-- Step 2: Shape & Quantity (Grid 2-kolom) --}}
                <div class="tw:grid tw:grid-cols-2 tw:gap-3">
                    <div>
                        <label for="mmShape" class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                            {{ __('purchasing.copy.shape') }} <span class="text-danger">*</span>
                        </label>
                        <select id="mmShape" class="form-select form-select-sm tw:mt-1">
                            <option value="">{{ __('purchasing.copy.select') }}</option>
                            @foreach(\App\Models\PrItem::SHAPES as $shape)
                                <option value="{{ $shape }}">{{ $shape }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="mmQuantity" class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                            {{ __('purchasing.copy.qty') }} <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="mmQuantity" min="1" step="1" value="1" class="form-control form-control-sm text-center tw:mt-1" required>
                    </div>
                </div>

                {{-- Step 3: Dimensions Specification (Dynamic Grid) --}}
                <div class="tw:p-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container-low">
                    <div class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface tw:mb-2.5">
                        Dimensi Material (mm)
                    </div>
                    <div class="tw:grid tw:grid-cols-2 tw:sm:grid-cols-3 tw:gap-2.5" id="mmDimensionsGrid">
                        @foreach(\App\Models\PrItem::FIXED_DIMENSION_ORDER as $dim)
                            <div class="mm-dim-container" data-dim-field="{{ $dim }}">
                                <label for="mm_dim_{{ $dim }}" class="tw:text-[11px] tw:font-semibold tw:text-on-surface-variant tw:mb-1 tw:block">
                                    {{ \App\Models\PrItem::dimensionLabel($dim) }} (mm)
                                </label>
                                <input
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    id="mm_dim_{{ $dim }}"
                                    data-dim-name="{{ $dim }}"
                                    class="form-control form-control-sm text-end mm-dim-input"
                                    placeholder="0.0"
                                >
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Step 4: Weight & HS Code Preview --}}
                <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:gap-3">
                    {{-- Weight --}}
                    <div>
                        <label for="mmWeightNeeded" class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                            {{ __('purchasing.copy.weight_unit_kg_e8ab70') }}
                        </label>
                        <div class="input-group input-group-sm tw:mt-1">
                            <input type="number" step="0.0001" id="mmWeightNeeded" class="form-control text-end ui-tabular-nums" value="0.0000">
                            <span class="input-group-text">kg/unit</span>
                        </div>
                        <div class="tw:text-[11px] tw:text-on-surface-variant tw:mt-1">
                            Total: <strong id="mmTotalWeightDisplay" class="ui-tabular-nums tw:text-on-surface">0.0000 kg</strong>
                        </div>
                    </div>

                    {{-- HS Code --}}
                    <div>
                        <label for="mmHsCode" class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                            HS Code
                        </label>
                        <div class="tw:flex tw:items-center tw:gap-2 tw:mt-1">
                            <input type="text" id="mmHsCode" class="form-control form-control-sm" placeholder="7228.xx.xx">
                            <span id="mmHsBadge" class="ui-status-chip ui-status-chip--neutral tw:text-[11px] tw:whitespace-nowrap">
                                Otomatis
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Step 5: Remarks / Notes --}}
                <div>
                    <label for="mmRemark" class="tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">
                        {{ __('purchasing.copy.remark') }} (Opsional)
                    </label>
                    <textarea id="mmRemark" rows="2" class="form-control form-control-sm tw:mt-1" placeholder="Catatan toleransi, potongan, atau instruksi pengiriman..."></textarea>
                </div>
            </div>

            {{-- Modal Footer --}}
            <div class="modal-footer tw:flex tw:flex-col-reverse tw:sm:flex-row tw:gap-2 tw:border-t tw:border-outline-variant tw:bg-surface-container tw:p-3">
                <x-ui.button type="button" variant="outline" size="sm" data-bs-dismiss="modal" class="tw:w-full tw:sm:w-auto tw:justify-center">
                    {{ __('purchasing.copy.cancel') }}
                </x-ui.button>
                <x-ui.button type="button" size="md" id="btnSaveMobileMaterialItem" class="tw:w-full tw:sm:w-auto tw:justify-center tw:min-h-11 tw:font-bold">
                    <x-ui.icon name="check" size="sm" />
                    <span>Simpan ke Requisition</span>
                </x-ui.button>
            </div>
        </div>
    </div>
</div>


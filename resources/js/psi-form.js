export const EXPORTER_FIELDS = [
    'name',
    'contact',
    'address',
    'city',
    'country',
    'zip_code',
    'telephone',
    'mobile',
    'fax',
    'email',
    'port_of_loading',
];

export const SHARED_FIELDS = [
    'name',
    'contact',
    'address',
    'city',
    'country',
    'telephone',
    'mobile',
    'fax',
    'email',
];

function createEmptyManageFields(isDefault = false) {
    return {
        name: '',
        contact: '',
        address: '',
        city: '',
        country: '',
        country_unmapped: '',
        telephone: '',
        mobile: '',
        fax: '',
        email: '',
        is_default: Boolean(isDefault),
    };
}

function createEmptyManageForm(isDefault = false) {
    return {
        mode: 'create',
        editHash: '',
        updateUrl: '',
        fields: createEmptyManageFields(isDefault),
    };
}

function readCsrfToken() {
    return globalThis.document?.querySelector?.('meta[name="csrf-token"]')?.getAttribute?.('content') || '';
}

/**
 * Pure state machine for the Pre-Shipment Inspection (PSI) form and
 * in-dialog Goods Location manager. Separated from DOM/Alpine globals so
 * node:test can exercise every branch directly.
 */
export function createPsiFormState(config = {}) {
    const initialValues = config.values || {};
    const locations = Array.isArray(config.locations) ? config.locations : [];
    const messages = config.messages || {};
    const urls = config.urls || {};
    const idPrefix = String(config.idPrefix ?? 'preshipment');
    const manageCountrySelectName = String(config.manageCountrySelectName ?? `_psi_manage_country_${idPrefix}`);
    const maxLocations = Number(config.maxLocations ?? 20);
    const activeLocationsCount = Number(config.activeLocationsCount ?? locations.length);

    function syncSearchableSelect(name, value, disabled = null) {
        if (typeof globalThis.window?.dispatchEvent !== 'function' || typeof globalThis.CustomEvent !== 'function') {
            return;
        }
        globalThis.window.dispatchEvent(new globalThis.CustomEvent('set-select-value', {
            detail: { name, value: String(value ?? ''), silent: true },
        }));
        if (disabled !== null) {
            globalThis.window.dispatchEvent(new globalThis.CustomEvent('set-select-disabled', {
                detail: { name, disabled: Boolean(disabled) },
            }));
        }
    }

    function dispatchPanelModeEvent(mode) {
        if (typeof globalThis.window?.dispatchEvent !== 'function' || typeof globalThis.CustomEvent !== 'function') {
            return;
        }
        globalThis.window.dispatchEvent(new globalThis.CustomEvent('psi:panel-mode', {
            detail: { idPrefix, mode },
        }));
    }

    const exporter = {};
    for (const field of EXPORTER_FIELDS) {
        exporter[field] = String(initialValues[`exporter_${field}`] ?? '');
    }

    const supplier = {};
    for (const field of SHARED_FIELDS) {
        supplier[field] = String(initialValues[`supplier_${field}`] ?? '');
    }

    const sameAsExporter = Boolean(config.sameAsExporter);
    const initialLocation = String(config.selectedLocation ?? '');
    const initialLocationAction = String(
        config.locationAction
        ?? (!sameAsExporter && !config.hasAnyLocation && activeLocationsCount < maxLocations ? 'create' : 'none')
    );
    const initialSaveExporterDefault = Boolean(config.saveExporterDefault);
    const initialSetLocationDefault = Boolean(config.setLocationDefault ?? (!config.hasAnyLocation));
    const snapshotInitial = {
        exporter: { ...exporter },
        supplier: { ...supplier },
        sameAsExporter,
        selectedLocation: sameAsExporter ? '' : initialLocation,
        locationAction: sameAsExporter ? 'none' : initialLocationAction,
        saveExporterDefault: initialSaveExporterDefault,
        setLocationDefault: initialSetLocationDefault,
    };

    const state = {
        exporter,
        supplier,
        locations,
        activeLocationsCount,
        maxLocations,
        loadingLocations: false,
        sameAsExporter: snapshotInitial.sameAsExporter,
        selectedLocation: snapshotInitial.selectedLocation,
        saveExporterDefault: snapshotInitial.saveExporterDefault,
        locationAction: snapshotInitial.locationAction,
        setLocationDefault: snapshotInitial.setLocationDefault,
        stashedSupplier: null,
        stashedLocation: '',
        announcement: '',

        // In-dialog Manage Locations state
        panelMode: 'psi',
        manageStep: 'list',
        manageSearch: '',
        manageBusy: false,
        manageBusyAction: '',
        manageBusyHash: '',
        manageError: '',
        manageNotice: '',
        manageFieldErrors: {},
        deleteConfirmHash: '',
        manageForm: createEmptyManageForm(activeLocationsCount === 0),

        get canCreateLocation() {
            return this.activeLocationsCount < this.maxLocations;
        },

        get filteredLocations() {
            const term = String(this.manageSearch || '').trim().toLowerCase();
            if (!term) {
                return this.locations;
            }
            return this.locations.filter((loc) => {
                const haystack = [
                    loc.name,
                    loc.city,
                    loc.country_label,
                    loc.address,
                    loc.contact,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                return haystack.includes(term);
            });
        },

        dispatchPanelMode() {
            dispatchPanelModeEvent(this.panelMode);
        },

        scrollDialogTop(el) {
            const scroller = el?.closest?.('[class*="overflow-y-auto"], section');
            if (scroller && typeof scroller.scrollTo === 'function') {
                scroller.scrollTo({ top: 0 });
            }
        },

        syncManageCountrySelect(value = this.manageForm.fields.country, disabled = true) {
            syncSearchableSelect(manageCountrySelectName, value, disabled);
        },

        reset() {
            for (const field of EXPORTER_FIELDS) {
                this.exporter[field] = snapshotInitial.exporter[field];
            }
            for (const field of SHARED_FIELDS) {
                this.supplier[field] = snapshotInitial.supplier[field];
            }
            this.sameAsExporter = snapshotInitial.sameAsExporter;
            this.selectedLocation = snapshotInitial.selectedLocation;
            this.locationAction = snapshotInitial.locationAction;
            this.saveExporterDefault = snapshotInitial.saveExporterDefault;
            this.setLocationDefault = snapshotInitial.setLocationDefault;
            this.stashedSupplier = null;
            this.stashedLocation = '';
            this.announcement = '';
            this.panelMode = 'psi';
            this.manageStep = 'list';
            this.manageSearch = '';
            this.manageBusy = false;
            this.manageBusyAction = '';
            this.manageBusyHash = '';
            this.manageError = '';
            this.manageNotice = '';
            this.manageFieldErrors = {};
            this.deleteConfirmHash = '';
            this.manageForm = createEmptyManageForm(this.activeLocationsCount === 0);
            if (this.sameAsExporter) {
                this.syncFromExporter();
            }
            syncSearchableSelect('preshipment[exporter_country]', this.exporter.country);
            syncSearchableSelect('preshipment[supplier_country]', this.supplier.country, this.sameAsExporter);
            this.syncManageCountrySelect('', true);
            this.dispatchPanelMode();
        },

        init() {
            if (this.sameAsExporter) {
                this.syncFromExporter();
            }
            syncSearchableSelect('preshipment[exporter_country]', this.exporter.country);
            syncSearchableSelect('preshipment[supplier_country]', this.supplier.country, this.sameAsExporter);
            this.syncManageCountrySelect('', true);
        },

        syncFromExporter() {
            if (!this.sameAsExporter) return;
            for (const field of SHARED_FIELDS) {
                this.supplier[field] = this.exporter[field];
            }
            syncSearchableSelect('preshipment[supplier_country]', this.supplier.country, true);
        },

        toggleSameAsExporter(checked = this.sameAsExporter) {
            this.sameAsExporter = Boolean(checked);

            if (this.sameAsExporter) {
                this.stashedSupplier = { ...this.supplier };
                this.stashedLocation = this.selectedLocation;
                this.selectedLocation = '';
                this.locationAction = 'none';
                if (this.panelMode === 'manage') {
                    this.closeManageLocations();
                }
                this.syncFromExporter();
                this.announcement = messages.announcedOn || '';
            } else {
                if (this.stashedSupplier) {
                    for (const field of SHARED_FIELDS) {
                        this.supplier[field] = this.stashedSupplier[field] ?? '';
                    }
                    this.stashedSupplier = null;
                }
                if (this.stashedLocation) {
                    this.selectedLocation = this.stashedLocation;
                    this.stashedLocation = '';
                }
                syncSearchableSelect('preshipment[supplier_country]', this.supplier.country, false);
                this.announcement = messages.announcedOff || '';
            }
        },

        selectLocation(hash = this.selectedLocation) {
            this.selectedLocation = String(hash ?? '');
            if (this.sameAsExporter) return;

            if (!this.selectedLocation) {
                if (this.locationAction === 'update') {
                    this.locationAction = 'none';
                }
                return;
            }

            const found = this.locations.find((loc) => loc.hash === this.selectedLocation);
            if (!found) return;

            for (const field of SHARED_FIELDS) {
                this.supplier[field] = String(found[field] ?? '');
            }
            syncSearchableSelect('preshipment[supplier_country]', this.supplier.country, false);

            if (messages.locationLoaded) {
                this.announcement = messages.locationLoaded.replace(':name', found.name || '');
            }
        },

        syncLocationDropdown(selectEl = null, defaultTag = 'Default') {
            if (!selectEl || typeof selectEl.querySelectorAll !== 'function' || typeof globalThis.Option !== 'function') {
                return;
            }
            const placeholderOpt = selectEl.querySelector('option[value=""]');
            selectEl.replaceChildren(...(placeholderOpt ? [placeholderOpt] : []));
            for (const loc of this.locations) {
                const label = `${loc.name} (${loc.city}, ${loc.country_label})${loc.is_default ? ` - ${defaultTag}` : ''}`;
                const opt = new globalThis.Option(label, loc.hash, false, this.selectedLocation === loc.hash);
                selectEl.add(opt);
            }
            selectEl.value = this.sameAsExporter ? '' : this.selectedLocation;
        },

        applyLocationsResponse(json, selectEl = null, defaultTag = 'Default', options = {}) {
            const syncSelected = options.syncSelected !== false;
            if (Array.isArray(json?.locations)) {
                this.locations = json.locations;
            }
            if (typeof json?.active_count === 'number') {
                this.activeLocationsCount = json.active_count;
            } else {
                this.activeLocationsCount = this.locations.length;
            }
            if (typeof json?.max_locations === 'number') {
                this.maxLocations = json.max_locations;
            }
            if (!this.canCreateLocation && this.locationAction === 'create') {
                this.locationAction = 'none';
            }
            if (this.selectedLocation) {
                const stillPresent = this.locations.find((loc) => loc.hash === this.selectedLocation);
                if (!stillPresent) {
                    this.selectedLocation = '';
                    if (this.locationAction === 'update') {
                        this.locationAction = 'none';
                    }
                } else if (syncSelected && !this.sameAsExporter) {
                    this.selectLocation(this.selectedLocation);
                }
            }
            this.syncLocationDropdown(selectEl, defaultTag);
        },

        async refreshLocations(url, selectEl = null, defaultTag = 'Default', options = {}) {
            if (!url || this.loadingLocations || typeof globalThis.fetch !== 'function') return;
            this.loadingLocations = true;
            try {
                const res = await globalThis.fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const json = await res.json();
                this.applyLocationsResponse(json, selectEl, defaultTag);
                if (!options.silentAnnouncement && messages.locationsRefreshed) {
                    this.announcement = messages.locationsRefreshed;
                }
            } catch (error) {
                // Keep existing state if offline/failed
            } finally {
                this.loadingLocations = false;
            }
        },

        openManageLocations(selectEl = null, defaultTag = 'Default') {
            if (this.sameAsExporter) return;
            this.panelMode = 'manage';
            this.manageStep = 'list';
            this.deleteConfirmHash = '';
            this.manageError = '';
            this.manageNotice = '';
            this.manageFieldErrors = {};
            this.syncManageCountrySelect('', true);
            this.dispatchPanelMode();
            if (urls.options) {
                this.refreshLocations(urls.options, selectEl, defaultTag, { silentAnnouncement: true });
            }
        },

        closeManageLocations() {
            this.panelMode = 'psi';
            this.manageStep = 'list';
            this.deleteConfirmHash = '';
            this.manageError = '';
            this.manageFieldErrors = {};
            this.syncManageCountrySelect('', true);
            this.dispatchPanelMode();
        },

        startCreateLocation() {
            if (!this.canCreateLocation || this.manageBusy) return;
            this.manageError = '';
            this.manageNotice = '';
            this.manageFieldErrors = {};
            this.deleteConfirmHash = '';
            this.manageForm = createEmptyManageForm(this.activeLocationsCount === 0);
            this.manageStep = 'form';
            this.syncManageCountrySelect('', false);
        },

        startEditLocation(loc) {
            if (!loc || this.manageBusy) return;
            this.manageError = '';
            this.manageNotice = '';
            this.manageFieldErrors = {};
            this.deleteConfirmHash = '';
            this.manageForm = {
                mode: 'edit',
                editHash: String(loc.hash || ''),
                updateUrl: String(loc.update_url || ''),
                fields: {
                    name: String(loc.name ?? ''),
                    contact: String(loc.contact ?? ''),
                    address: String(loc.address ?? ''),
                    city: String(loc.city ?? ''),
                    country: String(loc.country ?? ''),
                    country_unmapped: String(loc.country_unmapped ?? ''),
                    telephone: String(loc.telephone ?? ''),
                    mobile: String(loc.mobile ?? ''),
                    fax: String(loc.fax ?? ''),
                    email: String(loc.email ?? ''),
                    is_default: Boolean(loc.is_default),
                },
            };
            this.manageStep = 'form';
            this.syncManageCountrySelect(this.manageForm.fields.country, false);
        },

        cancelLocationForm() {
            this.manageStep = 'list';
            this.manageError = '';
            this.manageFieldErrors = {};
            this.syncManageCountrySelect('', true);
        },

        useLocationForShipment(hash, selectEl = null, defaultTag = 'Default') {
            if (this.sameAsExporter || !hash) return;
            if (this.locationAction === 'create') {
                this.locationAction = 'none';
            }
            this.selectLocation(String(hash));
            this.syncLocationDropdown(selectEl, defaultTag);
            this.closeManageLocations();
        },

        requestDeleteLocation(hash) {
            if (this.manageBusy) return;
            this.deleteConfirmHash = String(hash ?? '');
            this.manageError = '';
        },

        cancelDeleteLocation() {
            this.deleteConfirmHash = '';
        },

        async requestJson(url, method, body = null) {
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            const token = readCsrfToken();
            if (token) {
                headers['X-CSRF-TOKEN'] = token;
            }
            if (body !== null) {
                headers['Content-Type'] = 'application/json';
            }

            const res = await globalThis.fetch(url, {
                method,
                headers,
                credentials: 'same-origin',
                body: body !== null ? JSON.stringify(body) : undefined,
            });

            let json = null;
            try {
                json = await res.json();
            } catch {
                json = null;
            }

            if (!res.ok) {
                const err = new Error(json?.message || '');
                err.status = res.status;
                err.payload = json;
                throw err;
            }

            return json || {};
        },

        resolveHttpErrorMessage(err) {
            if (err?.status === 419) {
                return messages.sessionExpired || 'Your session expired. Please reload the page and try again.';
            }
            if (err?.status === 429) {
                return messages.rateLimited || 'Too many requests. Please wait a moment and try again.';
            }
            return err?.payload?.message || messages.locationsSaveFailed || 'Could not save location changes. Please review and try again.';
        },

        async submitLocationForm(selectEl = null, defaultTag = 'Default') {
            if (this.manageBusy || typeof globalThis.fetch !== 'function') return false;

            const isEdit = this.manageForm.mode === 'edit';
            const url = isEdit ? this.manageForm.updateUrl : urls.store;
            if (!url) return false;

            this.manageBusy = true;
            this.manageBusyAction = 'save';
            this.manageBusyHash = isEdit ? this.manageForm.editHash : '';
            this.manageError = '';
            this.manageNotice = '';
            this.manageFieldErrors = {};

            const f = this.manageForm.fields;
            const payload = {
                name: f.name,
                contact: f.contact,
                address: f.address,
                city: f.city,
                country: f.country,
                telephone: f.telephone,
                mobile: f.mobile,
                fax: f.fax,
                email: f.email,
                is_default: f.is_default ? '1' : '0',
            };

            try {
                const json = await this.requestJson(url, isEdit ? 'PUT' : 'POST', payload);
                this.applyLocationsResponse(json, selectEl, defaultTag, { syncSelected: false });

                if (!isEdit && json.created_hash) {
                    const createdHash = String(json.created_hash);
                    if (this.locationAction === 'create') {
                        this.locationAction = 'none';
                    }
                    this.selectLocation(createdHash);
                    this.syncLocationDropdown(selectEl, defaultTag);
                    this.announcement = String(json.message || this.announcement || '');
                    this.closeManageLocations();
                    return true;
                }

                if (isEdit && json.updated_hash) {
                    const updatedHash = String(json.updated_hash);
                    if (this.selectedLocation === updatedHash && !this.sameAsExporter) {
                        this.selectLocation(updatedHash);
                        if (this.locationAction === 'update') {
                            this.locationAction = 'none';
                        }
                    }
                    this.syncLocationDropdown(selectEl, defaultTag);
                }

                this.manageStep = 'list';
                this.syncManageCountrySelect('', true);
                this.manageNotice = String(json.message || '');
                this.announcement = this.manageNotice;
                return true;
            } catch (err) {
                if (err?.status === 422 && err?.payload?.errors) {
                    const normalizedErrors = {};
                    for (const [key, msgs] of Object.entries(err.payload.errors)) {
                        normalizedErrors[key] = Array.isArray(msgs) ? String(msgs[0] ?? '') : String(msgs ?? '');
                    }
                    this.manageFieldErrors = normalizedErrors;
                    this.manageError = normalizedErrors.limit || String(err.payload.message || messages.locationsSaveFailed || '');
                } else {
                    this.manageError = this.resolveHttpErrorMessage(err);
                }
                return false;
            } finally {
                this.manageBusy = false;
                this.manageBusyAction = '';
                this.manageBusyHash = '';
            }
        },

        async setLocationAsDefault(loc, selectEl = null, defaultTag = 'Default') {
            if (!loc?.default_url || this.manageBusy || typeof globalThis.fetch !== 'function') return false;
            this.manageBusy = true;
            this.manageBusyAction = 'default';
            this.manageBusyHash = String(loc.hash || '');
            this.manageError = '';
            this.manageNotice = '';

            try {
                const json = await this.requestJson(loc.default_url, 'POST', {});
                this.applyLocationsResponse(json, selectEl, defaultTag, { syncSelected: false });
                this.manageNotice = String(json.message || '');
                this.announcement = this.manageNotice;
                return true;
            } catch (err) {
                this.manageError = this.resolveHttpErrorMessage(err);
                return false;
            } finally {
                this.manageBusy = false;
                this.manageBusyAction = '';
                this.manageBusyHash = '';
            }
        },

        async confirmDeleteLocation(loc, selectEl = null, defaultTag = 'Default') {
            if (!loc?.destroy_url || this.manageBusy || typeof globalThis.fetch !== 'function') return false;
            this.manageBusy = true;
            this.manageBusyAction = 'delete';
            this.manageBusyHash = String(loc.hash || '');
            this.manageError = '';
            this.manageNotice = '';

            try {
                const json = await this.requestJson(loc.destroy_url, 'DELETE');
                this.applyLocationsResponse(json, selectEl, defaultTag, { syncSelected: false });
                this.deleteConfirmHash = '';
                this.manageNotice = String(json.message || '');
                this.announcement = this.manageNotice;
                return true;
            } catch (err) {
                this.manageError = this.resolveHttpErrorMessage(err);
                return false;
            } finally {
                this.manageBusy = false;
                this.manageBusyAction = '';
                this.manageBusyHash = '';
            }
        },
    };

    if (sameAsExporter) {
        state.syncFromExporter();
    }

    return state;
}

export function psiForm(config = {}) {
    return createPsiFormState(config);
}

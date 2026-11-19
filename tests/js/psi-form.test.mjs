import test from 'node:test';
import assert from 'node:assert/strict';
import { createPsiFormState, EXPORTER_FIELDS, SHARED_FIELDS } from '../../resources/js/psi-form.js';

function baseConfig(overrides = {}) {
    return {
        values: {
            exporter_name: 'Shanghai Export Ltd.',
            exporter_contact: 'Ms. Lin',
            exporter_address: '100 Century Ave',
            exporter_city: 'Shanghai',
            exporter_country: 'CN',
            exporter_zip_code: '200120',
            exporter_telephone: '+86-21-5555-0100',
            exporter_mobile: '+86 138 0000 1111',
            exporter_fax: '+86-21-5555-0199',
            exporter_email: 'export@shanghai.example',
            exporter_port_of_loading: 'Shanghai Port',
            supplier_name: 'Wuxi Factory',
            supplier_contact: 'Mr. Zhou',
            supplier_address: '88 Industrial Rd',
            supplier_city: 'Wuxi',
            supplier_country: 'CN',
            supplier_telephone: '+86-510-8888-1234',
            supplier_mobile: '+86 139 0000 2222',
            supplier_fax: '+86-510-8888-9999',
            supplier_email: 'factory@wuxi.example',
        },
        locations: [
            {
                hash: 'loc-wuxi',
                name: 'Wuxi Factory',
                contact: 'Mr. Zhou',
                address: '88 Industrial Rd',
                city: 'Wuxi',
                country: 'CN',
                telephone: '+86-510-8888-1234',
                mobile: '+86 139 0000 2222',
                fax: '+86-510-8888-9999',
                email: 'factory@wuxi.example',
                is_default: true,
            },
            {
                hash: 'loc-suzhou',
                name: 'Suzhou Warehouse',
                contact: 'Ms. Chen',
                address: '22 Lake View St',
                city: 'Suzhou',
                country: 'CN',
                telephone: '+86-512-7777-0000',
                mobile: '',
                fax: '',
                email: 'suzhou@wuxi.example',
                is_default: false,
            },
        ],
        sameAsExporter: false,
        selectedLocation: 'loc-wuxi',
        saveExporterDefault: false,
        hasAnyLocation: true,
        locationAction: 'none',
        setLocationDefault: false,
        messages: {
            announcedOn: 'Location of Goods matched to Exporter and locked.',
            announcedOff: 'Location of Goods unlocked for editing.',
            locationLoaded: 'Location of Goods loaded from saved locations.',
        },
        ...overrides,
    };
}

test('field constants define 11 exporter fields and 9 shared fields excluding zip_code and port_of_loading', () => {
    assert.equal(EXPORTER_FIELDS.length, 11);
    assert.equal(SHARED_FIELDS.length, 9);
    assert.ok(!SHARED_FIELDS.includes('zip_code'));
    assert.ok(!SHARED_FIELDS.includes('port_of_loading'));
});

test('toggleSameAsExporter(true) copies 9 shared fields, clears selectedLocation, and live-mirrors Exporter edits', () => {
    const state = createPsiFormState(baseConfig());

    state.toggleSameAsExporter(true);

    assert.equal(state.sameAsExporter, true);
    assert.equal(state.selectedLocation, '');
    assert.equal(state.locationAction, 'none');
    assert.equal(state.supplier.name, 'Shanghai Export Ltd.');
    assert.equal(state.supplier.address, '100 Century Ave');
    assert.equal(state.supplier.city, 'Shanghai');
    assert.equal(state.supplier.country, 'CN');
    assert.equal(state.supplier.email, 'export@shanghai.example');
    assert.equal(state.announcement, 'Location of Goods matched to Exporter and locked.');

    // Live mirror when Exporter changes while checked
    state.exporter.name = 'Updated Shanghai Corp';
    state.exporter.city = 'Ningbo';
    state.syncFromExporter();

    assert.equal(state.supplier.name, 'Updated Shanghai Corp');
    assert.equal(state.supplier.city, 'Ningbo');
});

test('toggleSameAsExporter(false) restores previously stashed Block 2 values and selected location', () => {
    const state = createPsiFormState(baseConfig());

    state.toggleSameAsExporter(true);
    state.exporter.name = 'Changed Exporter While Locked';
    state.syncFromExporter();
    assert.equal(state.supplier.name, 'Changed Exporter While Locked');

    state.toggleSameAsExporter(false);

    assert.equal(state.sameAsExporter, false);
    assert.equal(state.supplier.name, 'Wuxi Factory');
    assert.equal(state.supplier.city, 'Wuxi');
    assert.equal(state.selectedLocation, 'loc-wuxi');
    assert.equal(state.announcement, 'Location of Goods unlocked for editing.');
});

test('selectLocation populates Block 2 fields and clearing selection resets update action to none', () => {
    const state = createPsiFormState(baseConfig({ locationAction: 'update' }));

    state.selectLocation('loc-suzhou');
    assert.equal(state.selectedLocation, 'loc-suzhou');
    assert.equal(state.supplier.name, 'Suzhou Warehouse');
    assert.equal(state.supplier.city, 'Suzhou');
    assert.equal(state.supplier.email, 'suzhou@wuxi.example');
    assert.equal(state.announcement, 'Location of Goods loaded from saved locations.');

    // Clearing selection when locationAction is 'update' falls back to 'none'
    state.selectLocation('');
    assert.equal(state.selectedLocation, '');
    assert.equal(state.locationAction, 'none');
    assert.equal(state.supplier.name, 'Suzhou Warehouse');
});

test('init() with sameAsExporter=true syncs exporter fields into supplier fields immediately', () => {
    const state = createPsiFormState(baseConfig({
        sameAsExporter: true,
        values: {
            exporter_name: 'Solo Exporter',
            exporter_city: 'Tokyo',
            exporter_country: 'JP',
            supplier_name: 'Old Stale Value',
            supplier_city: 'Osaka',
            supplier_country: 'CN',
        },
    }));
    state.init();

    assert.equal(state.supplier.name, 'Solo Exporter');
    assert.equal(state.supplier.city, 'Tokyo');
    assert.equal(state.supplier.country, 'JP');
});

test('refreshLocations updates options, respects maxLocations cap, and clears deleted selection', async () => {
    const base = baseConfig({
        selectedLocation: 'loc-wuxi',
        locationAction: 'create',
        activeLocationsCount: 19,
        maxLocations: 20,
    });
    base.messages.locationsRefreshed = 'Saved Locations of Goods list refreshed.';
    const state = createPsiFormState(base);

    assert.equal(state.canCreateLocation, true);

    const priorFetch = globalThis.fetch;
    try {
        globalThis.fetch = async () => ({
            ok: true,
            json: async () => ({
                locations: [
                    { hash: 'loc-suzhou', name: 'Suzhou Warehouse', city: 'Suzhou', country: 'CN', country_label: 'China', is_default: false },
                ],
                active_count: 20,
                max_locations: 20,
            }),
        });

        await state.refreshLocations('/supplier/goods-locations/options');
        assert.equal(state.canCreateLocation, false);
        assert.equal(state.locationAction, 'none');
        assert.equal(state.selectedLocation, '');
        assert.equal(state.announcement, 'Saved Locations of Goods list refreshed.');
    } finally {
        globalThis.fetch = priorFetch;
    }
});

test('reset() restores initial snapshot values after aborted edits', () => {
    const state = createPsiFormState(baseConfig());
    state.exporter.name = 'Edited Exporter';
    state.toggleSameAsExporter(true);
    assert.equal(state.sameAsExporter, true);

    state.reset();
    assert.equal(state.sameAsExporter, false);
    assert.equal(state.exporter.name, 'Shanghai Export Ltd.');
    assert.equal(state.supplier.name, 'Wuxi Factory');
    assert.equal(state.selectedLocation, 'loc-wuxi');
    assert.equal(state.panelMode, 'psi');
    assert.equal(state.manageStep, 'list');
});

test('in-dialog manage locations blocks opening when sameAsExporter=true and supports filtered search', async () => {
    const events = [];
    const priorWindow = globalThis.window;
    const priorCustomEvent = globalThis.CustomEvent;
    globalThis.CustomEvent = class MockEvent {
        constructor(type, init = {}) {
            this.type = type;
            this.detail = init.detail;
        }
    };
    globalThis.window = {
        dispatchEvent(ev) {
            events.push(ev);
        },
    };

    try {
        const lockedState = createPsiFormState(baseConfig({ sameAsExporter: true, idPrefix: 'dialog_psi' }));
        lockedState.openManageLocations();
        assert.equal(lockedState.panelMode, 'psi');

        const state = createPsiFormState(baseConfig({
            idPrefix: 'dialog_psi',
            urls: { store: '/supplier/goods-locations' },
        }));

        state.openManageLocations();
        assert.equal(state.panelMode, 'manage');
        assert.equal(state.manageStep, 'list');
        assert.ok(events.some((e) => e.type === 'psi:panel-mode' && e.detail.mode === 'manage'));

        state.manageSearch = 'suzhou';
        assert.equal(state.filteredLocations.length, 1);
        assert.equal(state.filteredLocations[0].hash, 'loc-suzhou');

        state.closeManageLocations();
        assert.equal(state.panelMode, 'psi');
        assert.ok(events.some((e) => e.type === 'psi:panel-mode' && e.detail.mode === 'psi'));
        assert.ok(events.some((e) => e.type === 'set-select-disabled' && e.detail.name === '_psi_manage_country_dialog_psi' && e.detail.disabled === true));
    } finally {
        globalThis.window = priorWindow;
        globalThis.CustomEvent = priorCustomEvent;
    }
});

test('submitLocationForm creates a new location, auto-selects it into Block 2, resets locationAction, and closes manage view', async () => {
    const priorFetch = globalThis.fetch;
    globalThis.fetch = async (url, init) => {
        assert.equal(url, '/supplier/goods-locations');
        assert.equal(init.method, 'POST');
        const body = JSON.parse(init.body);
        assert.equal(body.name, 'Ningbo Harbor Plant');
        assert.equal(body.country, 'CN');
        return {
            ok: true,
            status: 201,
            json: async () => ({
                ok: true,
                message: 'Location of Goods added.',
                created_hash: 'loc-ningbo',
                active_count: 3,
                max_locations: 20,
                locations: [
                    ...baseConfig().locations,
                    {
                        hash: 'loc-ningbo',
                        name: 'Ningbo Harbor Plant',
                        contact: 'Ms. Tang',
                        address: '55 Port Way',
                        city: 'Ningbo',
                        country: 'CN',
                        country_label: 'China',
                        telephone: '+86-574-8888-0001',
                        mobile: '',
                        fax: '',
                        email: 'ningbo@example.test',
                        is_default: false,
                        update_url: '/supplier/goods-locations/loc-ningbo',
                        default_url: '/supplier/goods-locations/loc-ningbo/default',
                        destroy_url: '/supplier/goods-locations/loc-ningbo',
                    },
                ],
            }),
        };
    };

    try {
        const state = createPsiFormState(baseConfig({
            idPrefix: 'dialog_psi',
            locationAction: 'create',
            urls: { store: '/supplier/goods-locations' },
        }));

        state.openManageLocations();
        state.startCreateLocation();
        assert.equal(state.manageStep, 'form');

        state.manageForm.fields.name = 'Ningbo Harbor Plant';
        state.manageForm.fields.contact = 'Ms. Tang';
        state.manageForm.fields.address = '55 Port Way';
        state.manageForm.fields.city = 'Ningbo';
        state.manageForm.fields.country = 'CN';

        const ok = await state.submitLocationForm();
        assert.equal(ok, true);
        assert.equal(state.selectedLocation, 'loc-ningbo');
        assert.equal(state.locationAction, 'none');
        assert.equal(state.supplier.name, 'Ningbo Harbor Plant');
        assert.equal(state.supplier.city, 'Ningbo');
        assert.equal(state.activeLocationsCount, 3);
        assert.equal(state.panelMode, 'psi');
        assert.equal(state.announcement, 'Location of Goods added.');
    } finally {
        globalThis.fetch = priorFetch;
    }
});

test('submitLocationForm surfaces 422 field errors and confirmDeleteLocation detaches deleted selection', async () => {
    const priorFetch = globalThis.fetch;

    try {
        globalThis.fetch = async () => ({
            ok: false,
            status: 422,
            json: async () => ({
                message: 'The name field is required.',
                errors: { name: ['The name field is required.'] },
            }),
        });

        const state = createPsiFormState(baseConfig({
            selectedLocation: 'loc-wuxi',
            locationAction: 'update',
            urls: { store: '/supplier/goods-locations' },
        }));

        state.openManageLocations();
        state.startCreateLocation();
        const failed = await state.submitLocationForm();
        assert.equal(failed, false);
        assert.equal(state.manageStep, 'form');
        assert.equal(state.manageFieldErrors.name, 'The name field is required.');

        state.cancelLocationForm();
        assert.equal(state.manageStep, 'list');

        globalThis.fetch = async (url, init) => {
            assert.equal(url, '/supplier/goods-locations/loc-wuxi');
            assert.equal(init.method, 'DELETE');
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    ok: true,
                    message: 'Location of Goods removed.',
                    deleted_hash: 'loc-wuxi',
                    active_count: 1,
                    max_locations: 20,
                    locations: [baseConfig().locations[1]],
                }),
            };
        };

        const deleted = await state.confirmDeleteLocation({
            hash: 'loc-wuxi',
            destroy_url: '/supplier/goods-locations/loc-wuxi',
        });
        assert.equal(deleted, true);
        assert.equal(state.selectedLocation, '');
        assert.equal(state.locationAction, 'none');
        assert.equal(state.activeLocationsCount, 1);
    } finally {
        globalThis.fetch = priorFetch;
    }
});

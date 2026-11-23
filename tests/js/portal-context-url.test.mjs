import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    buildHistoryProjection,
    encodeScv2Payload,
    decodeScv2Payload,
    projectShellUrl,
    resolveContextUrl,
    resolvePopstateNavigation,
} from '../../resources/js/portal-context-url.js';

const dirname = path.dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(
    fs.readFileSync(path.resolve(dirname, '../fixtures/portal-context-url-cases.json'), 'utf8')
);

test('encodeScv2Payload, decodeScv2Payload, projectShellUrl, and resolveContextUrl match shared cross-language fixture', () => {
    for (const c of fixture.validCases) {
        const encoded = encodeScv2Payload(c.description, c.action, c.expectedArgument);
        assert.equal(encoded, c.expectedFavoriteContext, `encode mismatch for ${c.id}`);

        const decoded = decodeScv2Payload(c.expectedFavoriteContext);
        assert.ok(decoded, `decode failed for ${c.id}`);
        assert.equal(decoded.action, c.action);
        assert.equal(decoded.argument, c.expectedArgument);
        assert.equal(decoded.path, null);

        const contextLinks = {
            enabled: true,
            appUuid: fixture.appUuid,
            pathPrefix: c.prefix,
            sessionMap: [
                {
                    action: c.action,
                    route: c.route,
                    logicalId: c.logicalId,
                    pathTemplate: c.pathTemplate,
                    params: c.params,
                    queryAllowlist: c.queryAllowlist,
                    hashedKeys: c.hashedKeys,
                    description: c.description,
                },
            ],
        };

        const projected = projectShellUrl(c.internalUrl, c.origin, contextLinks);
        assert.equal(projected, c.expectedContextUrl, `project mismatch for ${c.id}`);

        const resolved = resolveContextUrl(c.expectedContextUrl, c.origin, contextLinks);
        assert.ok(resolved, `resolve returned null for ${c.id}`);
        assert.equal(resolved.internalUrl, c.expectedResolvedInternalUrl, `resolve mismatch for ${c.id}`);
    }
});

test('static route beats parameterized route in matchPathAgainstIndex', () => {
    const contextLinks = {
        enabled: true,
        appUuid: fixture.appUuid,
        pathPrefix: 'v2/ADASI_PRD',
        sessionMap: [
            {
                action: 'purreqs410m000',
                route: 'purchasing.requisitions.show',
                logicalId: 'lid://adasi.portal.purchasing',
                pathTemplate: '/v2/ADASI_PRD/purchasing/requisitions/{requisition}',
                params: ['requisition'],
                queryAllowlist: [],
                hashedKeys: ['requisition'],
                description: 'Requisition Details',
            },
            {
                action: 'purreqs100m000',
                route: 'purchasing.requisitions.create',
                logicalId: 'lid://adasi.portal.purchasing',
                pathTemplate: '/v2/ADASI_PRD/purchasing/requisitions/create',
                params: [],
                queryAllowlist: [],
                hashedKeys: [],
                description: 'Create Purchase Requisition',
            },
        ],
    };

    const projected = projectShellUrl(
        'https://portal.adasi.test/v2/ADASI_PRD/purchasing/requisitions/create',
        'https://portal.adasi.test',
        contextLinks
    );
    const resolved = resolveContextUrl(projected, 'https://portal.adasi.test', contextLinks);
    assert.equal(resolved?.action, 'purreqs100m000');
    assert.equal(resolved?.route, 'purchasing.requisitions.create');
});

test('invalid attack payloads and hard length bounds are rejected identically in JS', () => {
    const validLookup = Object.fromEntries(
        fixture.validCases.map((c) => [c.action, c])
    );

    const sessionMap = [
        validLookup.purdash000m000,
        validLookup.purords410m000,
        validLookup.purords000m000,
        validLookup.purcmphs00m000,
        validLookup.supqprd000m000,
        {
            action: 'admuser000m000',
            route: 'admin.users.index',
            logicalId: 'lid://adasi.portal.admin',
            pathTemplate: '/v2/ADASI_PRD/admin/users',
            params: [],
            queryAllowlist: ['page', 'status'],
            hashedKeys: [],
            description: 'User Management',
        },
    ];

    const contextLinks = {
        enabled: true,
        appUuid: fixture.appUuid,
        pathPrefix: 'v2/ADASI_PRD',
        sessionMap,
    };

    for (const vector of fixture.invalidPayloads) {
        const json = `scv2${JSON.stringify(vector.payload)}`;
        const encoded = Buffer.from(json, 'utf8').toString('base64');
        const url = `https://portal.adasi.test/v2/ADASI_PRD/${fixture.appUuid}?favoriteContext=${encodeURIComponent(encoded)}&LogicalId=${encodeURIComponent(vector.logicalId)}`;
        assert.equal(resolveContextUrl(url, 'https://portal.adasi.test', contextLinks), null, `Expected null for ${vector.id}`);
    }

    assert.equal(decodeScv2Payload('A'.repeat(2052)), null);
    assert.equal(decodeScv2Payload('not_valid_base64!!'), null);
    assert.equal(
        decodeScv2Payload(Buffer.from(`scv1${JSON.stringify({ description: 'x', application: 'erp', type: 'session', action: 'purdash000m000', path: null, argument: '' })}`, 'utf8').toString('base64')),
        null
    );
    assert.equal(
        projectShellUrl(
            'https://evil.example/v2/ADASI_PRD/purchasing/dashboard',
            'https://portal.adasi.test',
            contextLinks
        ),
        null
    );
});

test('subdirectory basePath projects, resolves, handles popstate, and rejects sibling apps on shared host', () => {
    const origin = 'http://localhost';
    const basePath = '/adasi_portal_supplier/public';
    const contextLinks = {
        enabled: true,
        appUuid: fixture.appUuid,
        basePath,
        pathPrefix: '/v2/ADSI_PROD',
        sessionMap: [
            {
                action: 'purords000m000',
                route: 'purchasing.purchase-orders.index',
                logicalId: 'lid://adasi.portal.purchasing',
                pathTemplate: '/v2/ADSI_PROD/purchasing/purchase-orders',
                params: [],
                queryAllowlist: ['status', 'page'],
                hashedKeys: [],
                description: 'Purchase Order',
            },
        ],
    };

    const internalUrl = `${origin}${basePath}/v2/ADSI_PROD/purchasing/purchase-orders?status=open`;
    const projected = projectShellUrl(internalUrl, origin, contextLinks);
    assert.ok(projected, 'projected subdirectory context URL must not be null');
    assert.ok(
        projected.startsWith(`${origin}${basePath}/v2/ADSI_PROD/${fixture.appUuid}?favoriteContext=`),
        `expected subdirectory prefix in ${projected}`
    );

    const resolved = resolveContextUrl(projected, origin, contextLinks);
    assert.ok(resolved, 'resolveContextUrl must resolve subdirectory context URL');
    assert.equal(resolved.internalUrl, internalUrl);
    assert.equal(resolved.action, 'purords000m000');

    const historyProjection = buildHistoryProjection({
        internalUrl,
        origin,
        contextLinks,
        frameId: 'f-subdir',
        groupId: 'g-subdir',
        title: 'Purchase Order',
    });
    assert.equal(historyProjection.displayUrl, projected);

    const popstate = resolvePopstateNavigation({
        eventState: null,
        currentHref: projected,
        hash: '',
        origin,
        contextLinks,
        menuPathTitles: {
            [`${basePath}/v2/ADSI_PROD/purchasing/purchase-orders`]: 'Purchase Order',
        },
    });
    assert.equal(popstate.kind, 'open-url');
    assert.equal(popstate.url, internalUrl);
    assert.equal(popstate.label, 'Purchase Order');

    // Sibling app on shared localhost origin and bare paths outside basePath must be rejected.
    assert.equal(
        projectShellUrl(`${origin}/other_app/v2/ADSI_PROD/purchasing/purchase-orders`, origin, contextLinks),
        null
    );
    assert.equal(
        projectShellUrl(`${origin}/v2/ADSI_PROD/purchasing/purchase-orders`, origin, contextLinks),
        null
    );
    assert.equal(
        resolveContextUrl(projected.replace(basePath, '/other_app'), origin, contextLinks),
        null
    );
    assert.equal(
        resolveContextUrl(projected.replace(basePath, ''), origin, contextLinks),
        null
    );
});

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function walkFiles(dir, predicate = () => true) {
    if (!fs.existsSync(dir)) return [];
    const results = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            results.push(...walkFiles(fullPath, predicate));
        } else if (entry.isFile() && predicate(fullPath)) {
            results.push(fullPath);
        }
    }
    return results;
}

test('public/build contains manifest.json, zero source maps, and zero secret env keys or debugger traps', () => {
    const buildDir = path.join(rootDir, 'public/build');
    const manifestPath = path.join(buildDir, 'manifest.json');
    assert.equal(fs.existsSync(manifestPath), true, 'public/build/manifest.json must exist');

    const mapFiles = walkFiles(buildDir, (file) => file.endsWith('.map'));
    assert.deepEqual(mapFiles, [], 'production build must emit zero .map files');

    const forbiddenPatterns = [
        /sourceMappingURL=/,
        /\bdebugger\b/,
        /\bPUSHER_APP_SECRET\b/,
        /\bTURNSTILE_SECRET_KEY\b/,
        /\bAWS_SECRET_ACCESS_KEY\b/,
        /\bDB_PASSWORD\b/,
        /\bPASSPORT_PRIVATE_KEY\b/,
    ];

    const jsBundles = walkFiles(path.join(buildDir, 'assets'), (file) => file.endsWith('.js'));
    assert.ok(jsBundles.length > 0, 'public/build/assets must contain compiled JS bundles');

    for (const bundlePath of jsBundles) {
        const content = fs.readFileSync(bundlePath, 'utf8');
        const rel = path.relative(rootDir, bundlePath);
        for (const pattern of forbiddenPatterns) {
            assert.equal(pattern.test(content), false, `${rel} matched forbidden pattern ${pattern}`);
        }
    }
});

test('vite.config.js locks sourcemap: false and production debugger stripping without anti-F12 obscurity', () => {
    const viteConfig = fs.readFileSync(path.join(rootDir, 'vite.config.js'), 'utf8');
    assert.match(viteConfig, /sourcemap\s*:\s*false/);
    assert.match(viteConfig, /drop\s*:\s*mode\s*===\s*['"]production['"]\s*\?\s*\[\s*['"]debugger['"]\s*\]/);
    assert.match(viteConfig, /legalComments\s*:\s*['"]none['"]/);

    const assetProtection = fs.readFileSync(path.join(rootDir, 'resources/js/asset-protection.js'), 'utf8');
    assert.equal(/\bF12\b/i.test(assetProtection), false, 'must never block F12 key');
});

test('supplier registration and quotation drafts use sessionStorage and exclude sensitive tax/banking/credential fields', () => {
    const registerBlade = fs.readFileSync(
        path.join(rootDir, 'resources/views/auth/supplier-register.blade.php'),
        'utf8',
    );
    assert.equal(registerBlade.includes('localStorage.setItem'), false, 'registration draft must not write to localStorage');
    assert.equal(
        registerBlade.includes("sessionStorage.setItem('adasi_supplier_reg_draft_v2'"),
        true,
        'registration draft must write to sessionStorage v2 key',
    );

    const safePayloadMatch = registerBlade.match(/const\s+safePayload\s*=\s*\{([\s\S]*?)\};/);
    assert.ok(safePayloadMatch, 'safePayload object literal must exist in supplier-register.blade.php');
    const safePayloadBody = safePayloadMatch[1];

    for (const forbiddenField of [
        'password',
        'password_confirmation',
        'access_key',
        'nib',
        'npwp',
        'account_number',
        'account_holder_name',
    ]) {
        const propRegex = new RegExp(`\\b${forbiddenField}\\s*:`);
        assert.equal(
            propRegex.test(safePayloadBody),
            false,
            `safePayload must never persist sensitive field "${forbiddenField}"`,
        );
    }

    assert.match(
        registerBlade,
        /window\.addEventListener\(\s*['"]beforeunload['"][\s\S]*?!this\.isSubmitting\s*&&\s*!this\.draftCleared/,
        'beforeunload must not re-persist draft during or after form submission',
    );

    const quotationCreateBlade = fs.readFileSync(
        path.join(rootDir, 'resources/views/supplier/quotations/create.blade.php'),
        'utf8',
    );
    assert.equal(quotationCreateBlade.includes('localStorage.setItem'), false, 'quotation draft must not write to localStorage');
    assert.equal(quotationCreateBlade.includes('sessionStorage.setItem(draftKey'), true);
});

test('raw console.* calls are restricted strictly to resources/js/debug-logger.js behind import.meta.env.DEV', () => {
    const consoleCallRe = /\bconsole\.(?:log|debug|info|warn|error)\s*\(/;
    const allowedFile = path.normalize(path.join(rootDir, 'resources/js/debug-logger.js'));

    const jsFiles = walkFiles(path.join(rootDir, 'resources/js'), (file) => file.endsWith('.js'));
    for (const filePath of jsFiles) {
        const content = fs.readFileSync(filePath, 'utf8');
        if (path.normalize(filePath) === allowedFile) {
            assert.match(content, /import\.meta\.env\?\.DEV/);
            continue;
        }
        assert.equal(
            consoleCallRe.test(content),
            false,
            `${path.relative(rootDir, filePath)} must use AdasiDebug / debug-logger instead of raw console.*`,
        );
    }

    const bladeFiles = walkFiles(path.join(rootDir, 'resources/views'), (file) => file.endsWith('.blade.php'));
    for (const filePath of bladeFiles) {
        const content = fs.readFileSync(filePath, 'utf8');
        assert.equal(
            consoleCallRe.test(content),
            false,
            `${path.relative(rootDir, filePath)} must not call raw console.* in production templates`,
        );
    }
});

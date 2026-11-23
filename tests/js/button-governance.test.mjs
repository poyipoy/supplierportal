import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const viewsRoot = path.join(root, 'resources/views');

function walkBladeFiles(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) return walkBladeFiles(fullPath);
        return entry.name.endsWith('.blade.php') ? [fullPath] : [];
    });
}

// Quote-aware tag end scanner so `->` and `=>` inside Blade attributes never close a tag early.
function findTagEnd(source, startIndex) {
    let i = startIndex;
    let quote = null;
    while (i < source.length) {
        const ch = source[i];
        if (quote) {
            if (ch === '\\' && i + 1 < source.length) {
                i += 2;
                continue;
            }
            if (ch === quote) quote = null;
        } else if (ch === '"' || ch === "'") {
            quote = ch;
        } else if (ch === '>' && source[i - 1] !== '-' && source[i - 1] !== '=') {
            return i;
        }
        i += 1;
    }
    return -1;
}

function readAttr(tag, name) {
    const boundRe = new RegExp(`:${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)')`);
    const literalRe = new RegExp(`(?:^|\\s)${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)')`);
    const literalMatch = tag.match(literalRe);
    if (literalMatch) {
        return { dynamic: false, value: literalMatch[1] ?? literalMatch[2] ?? '' };
    }
    const boundMatch = tag.match(boundRe);
    if (boundMatch) {
        return { dynamic: true, value: boundMatch[1] ?? boundMatch[2] ?? '' };
    }
    return null;
}

function extractBlocks(source, openToken, closeToken) {
    const blocks = [];
    let cursor = 0;
    while (true) {
        const openIdx = source.indexOf(openToken, cursor);
        if (openIdx === -1) break;
        const openEnd = findTagEnd(source, openIdx + openToken.length);
        if (openEnd === -1) break;
        const closeIdx = source.indexOf(closeToken, openEnd + 1);
        if (closeIdx === -1) break;
        blocks.push({
            startOffset: openIdx,
            contentStart: openEnd + 1,
            content: source.slice(openEnd + 1, closeIdx),
        });
        cursor = closeIdx + closeToken.length;
    }
    return blocks;
}

function extractUiButtons(fragment) {
    const buttons = [];
    let cursor = 0;
    while (true) {
        const idx = fragment.indexOf('<x-ui.button', cursor);
        if (idx === -1) break;
        const end = findTagEnd(fragment, idx + '<x-ui.button'.length);
        if (end === -1) break;
        const tag = fragment.slice(idx, end + 1);
        const selfClosing = tag.endsWith('/>');
        let inner = '';
        let after = end + 1;
        if (!selfClosing) {
            const closeIdx = fragment.indexOf('</x-ui.button>', after);
            if (closeIdx !== -1) {
                inner = fragment.slice(after, closeIdx);
                after = closeIdx + '</x-ui.button>'.length;
            }
        }
        buttons.push({
            offset: idx,
            tag,
            inner,
            variant: readAttr(tag, 'variant'),
        });
        cursor = after;
    }
    return buttons;
}

function isGhostVariant(variantAttr) {
    if (!variantAttr) return false;
    if (!variantAttr.dynamic) {
        if (variantAttr.value.trim() === 'ghost') return true;
        return /['"]ghost['"]/.test(variantAttr.value);
    }
    return /['"]ghost['"]/.test(variantAttr.value);
}

function mayBePrimaryVariant(variantAttr) {
    if (!variantAttr) return true; // default variant prop on <x-ui.button> is 'primary'
    if (!variantAttr.dynamic && !variantAttr.value.includes('{{')) {
        return variantAttr.value.trim() === 'primary';
    }
    return /['"]primary['"]/.test(variantAttr.value);
}

// Compute max simultaneously visible primary buttons inside an actions slot,
// handling mutually exclusive `@if/@elseif/@else`, tab-scoped `hidden` spans,
// and coupled DRAFT predicates (e.g. `:variant="...STATUS_DRAFT ? 'outline' : 'primary'"` paired with `@if(...STATUS_DRAFT)`).
function maxVisiblePrimaryInActions(actionsHtml) {
    function evaluateForDraftState(draftState) {
        const tokenRe = /<span\b[^>]*>|@if\s*\(|@unless\s*\(|@elseif\s*\(|@else\b|@endif\b|@endunless\b|<\/span>|<x-ui\.button\b/g;
        const frames = [{ type: 'root', branchCounts: [0], active: true }];
        const hiddenDepths = [];
        let spanDepth = 0;
        let cursor = 0;

        const isDraftOnlyCond = (cond) => cond.includes('STATUS_DRAFT') && !cond.includes('!==') && !cond.includes('!') && !cond.includes('||');
        const isNotDraftCond = (cond) => /!==\s*\\?App\\Models\\PaymentBatch::STATUS_DRAFT/.test(cond);

        while (cursor < actionsHtml.length) {
            tokenRe.lastIndex = cursor;
            const match = tokenRe.exec(actionsHtml);
            if (!match) break;

            const token = match[0];
            cursor = match.index + token.length;

            if (token.startsWith('<span')) {
                const end = findTagEnd(actionsHtml, match.index + 5);
                if (end !== -1) {
                    const fullTag = actionsHtml.slice(match.index, end + 1);
                    if (!fullTag.endsWith('/>')) {
                        spanDepth += 1;
                        if (/\bhidden\b/.test(fullTag)) {
                            hiddenDepths.push(spanDepth);
                        }
                    }
                    cursor = end + 1;
                }
                continue;
            }

            if (token === '</span>') {
                if (hiddenDepths.length > 0 && hiddenDepths[hiddenDepths.length - 1] === spanDepth) {
                    hiddenDepths.pop();
                }
                spanDepth = Math.max(0, spanDepth - 1);
                continue;
            }

            if (token.startsWith('@if') || token.startsWith('@unless')) {
                const condStart = actionsHtml.indexOf('(', match.index);
                let depth = 0;
                let condEnd = condStart;
                while (condEnd < actionsHtml.length) {
                    if (actionsHtml[condEnd] === '(') depth += 1;
                    else if (actionsHtml[condEnd] === ')') {
                        depth -= 1;
                        if (depth === 0) break;
                    }
                    condEnd += 1;
                }
                const cond = actionsHtml.slice(condStart + 1, condEnd);
                cursor = condEnd + 1;
                let branchActive = true;
                if (token.startsWith('@if')) {
                    if (isDraftOnlyCond(cond)) branchActive = draftState;
                    else if (isNotDraftCond(cond)) branchActive = !draftState;
                }
                frames.push({ type: 'cond', branchCounts: [branchActive ? 0 : -1], branchActives: [branchActive] });
                continue;
            }

            if (token.startsWith('@elseif') || token === '@else') {
                const top = frames[frames.length - 1];
                if (top && top.type === 'cond') {
                    top.branchCounts.push(0);
                    top.branchActives.push(true);
                }
                continue;
            }

            if (token === '@endif' || token === '@endunless') {
                if (frames.length > 1 && frames[frames.length - 1].type === 'cond') {
                    const finished = frames.pop();
                    const activeCounts = finished.branchCounts.filter((c) => c >= 0);
                    const branchMax = activeCounts.length > 0 ? Math.max(...activeCounts) : 0;
                    const parent = frames[frames.length - 1];
                    const idx = parent.branchCounts.length - 1;
                    if (parent.branchCounts[idx] >= 0) {
                        parent.branchCounts[idx] += branchMax;
                    }
                }
                continue;
            }

            if (token === '<x-ui.button') {
                const end = findTagEnd(actionsHtml, match.index + token.length);
                if (end === -1) break;
                cursor = end + 1;
                if (hiddenDepths.length > 0) continue; // tab-scoped initially hidden button
                const tag = actionsHtml.slice(match.index, end + 1);
                const variant = readAttr(tag, 'variant');

                let countsAsPrimary = mayBePrimaryVariant(variant);
                if (variant?.dynamic && /STATUS_DRAFT\s*\?\s*'outline'\s*:\s*'primary'/.test(variant.value)) {
                    countsAsPrimary = !draftState;
                }

                if (countsAsPrimary) {
                    const current = frames[frames.length - 1];
                    const idx = current.branchCounts.length - 1;
                    if (current.branchCounts[idx] >= 0) {
                        current.branchCounts[idx] += 1;
                    }
                }
            }
        }

        while (frames.length > 1) {
            const finished = frames.pop();
            const activeCounts = finished.branchCounts.filter((c) => c >= 0);
            const branchMax = activeCounts.length > 0 ? Math.max(...activeCounts) : 0;
            const parent = frames[frames.length - 1];
            const idx = parent.branchCounts.length - 1;
            if (parent.branchCounts[idx] >= 0) {
                parent.branchCounts[idx] += branchMax;
            }
        }

        return frames[0].branchCounts[0];
    }

    return Math.max(evaluateForDraftState(true), evaluateForDraftState(false));
}

test('zero ghost buttons inside page-header actions or table cells/row partials', () => {
    const violations = [];
    const rowActionPartials = new Set([
        'resources/views/finance/local-procurement/_gr_actions.blade.php',
    ]);

    for (const file of walkBladeFiles(viewsRoot)) {
        const rel = path.relative(root, file).replace(/\\/g, '/');
        const source = readFileSync(file, 'utf8');

        for (const header of extractBlocks(source, '<x-ui.page-header', '</x-ui.page-header>')) {
            for (const actions of extractBlocks(header.content, '<x-slot:actions>', '</x-slot:actions>')) {
                for (const btn of extractUiButtons(actions.content)) {
                    if (isGhostVariant(btn.variant)) {
                        const line = source.slice(0, header.contentStart + actions.contentStart + btn.offset).split('\n').length;
                        violations.push(`header ghost: ${rel}:${line}`);
                    }
                }
            }
        }

        if (rowActionPartials.has(rel)) {
            for (const btn of extractUiButtons(source)) {
                if (isGhostVariant(btn.variant)) {
                    const line = source.slice(0, btn.offset).split('\n').length;
                    violations.push(`row partial ghost: ${rel}:${line}`);
                }
            }
        }

        for (const td of extractBlocks(source, '<td', '</td>')) {
            for (const btn of extractUiButtons(td.content)) {
                if (!isGhostVariant(btn.variant)) continue;
                // Floating popover Cancel inside remarks/notes editor beside primary Save is allow-listed:
                if (/(data-remark-cancel|data-notes-cancel)/.test(btn.tag)) continue;
                const line = source.slice(0, td.contentStart + btn.offset).split('\n').length;
                violations.push(`table cell ghost: ${rel}:${line}`);
            }
        }
    }

    assert.deepEqual(violations, []);
});

test('at most one simultaneously visible primary action button per page-header actions slot', () => {
    const violations = [];

    for (const file of walkBladeFiles(viewsRoot)) {
        const rel = path.relative(root, file).replace(/\\/g, '/');
        const source = readFileSync(file, 'utf8');

        for (const header of extractBlocks(source, '<x-ui.page-header', '</x-ui.page-header>')) {
            for (const actions of extractBlocks(header.content, '<x-slot:actions>', '</x-slot:actions>')) {
                const maxPrimary = maxVisiblePrimaryInActions(actions.content);
                if (maxPrimary > 1) {
                    const line = source.slice(0, header.startOffset).split('\n').length;
                    violations.push(`${rel}:${line} maxVisiblePrimary=${maxPrimary}`);
                }
            }
        }
    }

    assert.deepEqual(violations, []);
});

test('remaining ghost buttons conform to the Ghost Allow-List', () => {
    const allowContextTokens = [
        'cancel', 'close', 'dismiss', 'discard', 'back', 'back_list',
        'reset', 'clear', 'clear_pr', 'clear_all', 'clear_all_staged', 'select_all',
        'expand_all', 'collapse_all', 'selectall', 'clearall',
        'view_all', 'view_full_history', 'all_claims', 'all_pos', 'manage_drp', 'open_register', 'view_rate_history',
        'dashboard.all', 'resetsection', 'resetall', 'reset-notification-preferences', 'data-editor-reset',
        'data-bs-dismiss="modal"', 'close-ui-dialog', 'close-layer', 'data-filter-bar-reset', 'data-export-reset',
        'data-remark-cancel', 'data-notes-cancel', 'voidopen = false', 'manual_divider',
    ];
    const violations = [];

    for (const file of walkBladeFiles(viewsRoot)) {
        const rel = path.relative(root, file).replace(/\\/g, '/');
        const source = readFileSync(file, 'utf8');

        for (const btn of extractUiButtons(source)) {
            if (!isGhostVariant(btn.variant)) continue;
            const hay = `${btn.tag} ${btn.inner}`.toLowerCase();
            const allowed = allowContextTokens.some((token) => hay.includes(token));
            if (!allowed) {
                const line = source.slice(0, btn.offset).split('\n').length;
                violations.push(`disallowed ghost: ${rel}:${line}`);
            }
        }
    }

    assert.deepEqual(violations, []);
});

test('filter and export reset ghost buttons carry rotate-ccw icon', () => {
    const targets = [
        'resources/views/components/ui/filter-bar.blade.php',
        'resources/views/components/export/advanced-modal.blade.php',
        'resources/views/components/ui/dashboard-layout.blade.php',
        'resources/views/purchasing/comparison/_vs_best_content.blade.php',
        'resources/views/purchasing/comparison/_price_indicators.blade.php',
    ];
    const missing = [];

    for (const rel of targets) {
        const source = readFileSync(path.join(root, rel), 'utf8');
        for (const btn of extractUiButtons(source)) {
            if (!isGhostVariant(btn.variant)) continue;
            const hay = `${btn.tag} ${btn.inner}`.toLowerCase();
            if (/(reset|data-filter-bar-reset|data-export-reset|data-editor-reset)/.test(hay)) {
                if (!btn.inner.includes('rotate-ccw')) {
                    const line = source.slice(0, btn.offset).split('\n').length;
                    missing.push(`${rel}:${line}`);
                }
            }
        }
    }

    assert.deepEqual(missing, []);
});

test('ui button and icon-button components contain no legacy palette classes or weak outline fill', () => {
    const bannedPatterns = [
        /\bbg-white\b/,
        /\bslate-\d+/,
        /\bamber-\d+/,
        /\bemerald-\d+/,
        /\bred-\d+/,
        /#[0-9a-fA-F]{3,8}\b/,
        /bi\s+bi-/,
        /<x-lucide-/,
    ];

    for (const rel of ['resources/views/components/ui/button.blade.php', 'resources/views/components/ui/icon-button.blade.php']) {
        const source = readFileSync(path.join(root, rel), 'utf8');
        for (const pattern of bannedPatterns) {
            assert.doesNotMatch(source, pattern, `${rel} matched banned pattern ${pattern}`);
        }
        assert.match(source, /'outline-primary'\s*=>/);
        assert.match(source, /'danger-outline'\s*=>/);
        assert.match(source, /'outline'\s*=>[^\n]*tw:border-outline-strong[^\n]*tw:bg-surface/);
        assert.match(source, /'outline-primary'\s*=>[^\n]*tw:border-primary-text[^\n]*tw:bg-surface[^\n]*tw:text-primary-text/);
    }
});

test('JS DOM builders and non-plugin Blade views do not emit raw Bootstrap outline or solid buttons', () => {
    const jsTargets = [
        'resources/js/adasi-loader.js',
        'resources/js/local-procurement-import.js',
        'resources/js/server-tabs.js',
    ];
    for (const rel of jsTargets) {
        const source = readFileSync(path.join(root, rel), 'utf8');
        assert.doesNotMatch(source, /\bbtn-(outline-secondary|outline-danger|secondary|primary)\b/, `${rel} still uses Bootstrap btn-*`);
        assert.match(source, /\bui-data-action\b/, `${rel} must use .ui-data-action`);
    }

    const allowedPluginBootstrapButtons = new Set([
        // Radio button groups tied to Bootstrap .btn-check toggles:
        'resources/views/local-supplier/supplier-audits/edit.blade.php:244',
        'resources/views/purchasing/comparison/_historical_content.blade.php:90',
        'resources/views/purchasing/comparison/_historical_content.blade.php:93',
        'resources/views/supplier/price-history/historical.blade.php:101',
        'resources/views/supplier/price-history/historical.blade.php:104',
        // Compact append buttons inside Bootstrap .input-group:
        'resources/views/purchasing/pr/_mobile_item_editor.blade.php:94',
        'resources/views/purchasing/shipments/show.blade.php:328',
        'resources/views/supplier/shipments/create.blade.php:382',
        'resources/views/supplier/shipments/show.blade.php:383',
    ]);

    const rogueBootstrapBtns = [];
    for (const file of walkBladeFiles(viewsRoot)) {
        const rel = path.relative(root, file).replace(/\\/g, '/');
        if (rel.startsWith('resources/views/pdf/')) continue;
        const lines = readFileSync(file, 'utf8').split('\n');
        lines.forEach((lineText, idx) => {
            if (/\bbtn-(primary|secondary|success|danger|warning|info|outline-[a-z]+)\b/.test(lineText)) {
                const loc = `${rel}:${idx + 1}`;
                if (!allowedPluginBootstrapButtons.has(loc)) {
                    rogueBootstrapBtns.push(loc);
                }
            }
        });
    }

    assert.deepEqual(rogueBootstrapBtns, []);
});

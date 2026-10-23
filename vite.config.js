import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    css: {
        postcss: {
            plugins: [{
                postcssPlugin: 'adasi-tailwind-v3-compatibility',
                Rule(rule) {
                    // v4 changed spacing from following siblings to preceding
                    // children. Preserve v3 margins, including hidden children.
                    const axis = rule.selector.includes('\\:space-y-') ? 'y'
                        : rule.selector.includes('\\:space-x-') ? 'x' : null;
                    const divided = rule.selector.includes('\\:divide-');
                    if ((axis || divided) && rule.selector.includes(':not(:last-child)')) {
                        rule.selector = rule.selector
                            .replace(/:where\(([^,]+?)\s*>\s*:not\(:last-child\)\)/g, '$1 > :not([hidden]) ~ :not([hidden])')
                            .replace(/>\s*:where\(:not\(:last-child\)\)/g, '> :not([hidden]) ~ :not([hidden])');
                        if (axis) {
                            const start = rule.nodes.find(node => node.prop === (axis === 'y' ? 'margin-block-start' : 'margin-inline-start'));
                            const end = rule.nodes.find(node => node.prop === (axis === 'y' ? 'margin-block-end' : 'margin-inline-end'));
                            if (start && end) {
                                [start.value, end.value] = [end.value, start.value];
                                start.prop = axis === 'y' ? 'margin-top' : 'margin-left';
                                end.prop = axis === 'y' ? 'margin-bottom' : 'margin-right';
                            }
                        } else {
                            for (const [startProp, endProp] of [['border-top-width', 'border-bottom-width'], ['border-inline-start-width', 'border-inline-end-width']]) {
                                const start = rule.nodes.find(node => node.prop === startProp);
                                const end = rule.nodes.find(node => node.prop === endProp);
                                if (start && end) [start.value, end.value] = [end.value, start.value];
                            }
                        }
                    }
                },
                Declaration(declaration) {
                    // With Preflight disabled, v3 width utilities preserved the
                    // Bootstrap/UA border style. v4 must not turn them all solid.
                    if (declaration.prop.startsWith('border') && declaration.prop.endsWith('-style')
                        && declaration.value === 'var(--tw-border-style)') {
                        declaration.remove();
                    }
                },
                OnceExit(root) {
                    // v3 sorted hidden after the other static display utilities;
                    // responsive display variants still need to override it.
                    const hidden = root.nodes.find(node => node.type === 'rule' && node.selector === '.tw\\:hidden');
                    const displays = root.nodes.filter(node => node.type === 'rule'
                        && /^\.tw\\:[\w-]+$/.test(node.selector)
                        && node.nodes.some(child => child.prop === 'display'));
                    const last = displays.at(-1);
                    if (hidden && last && last !== hidden) {
                        hidden.remove();
                        last.after(hidden);
                    }
                },
            }],
        },
    },
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/registration-receipt.js'],
            refresh: true,
        }),
    ],
});

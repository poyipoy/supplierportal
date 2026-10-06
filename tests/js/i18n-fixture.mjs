import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export function installI18n(windowRef = {}, locale = 'en') {
    const source = readFileSync(new URL(`../../lang/${locale}/js.php`, import.meta.url), 'utf8');
    const messages = {};
    for (const match of source.matchAll(/^\s*'([^']+)'\s*=>\s*'((?:\\.|[^'])*)',/gm)) {
        messages[`js.${match[1]}`] = match[2].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
    }
    for (const match of source.matchAll(/^\s*'([^']+)'\s*=>\s*\[([^\n]+)\],/gm)) {
        messages[`js.${match[1]}`] = Object.fromEntries([...match[2].matchAll(/'(zero|one|other)'\s*=>\s*'((?:\\.|[^'])*)'/g)].map(value => [value[1], value[2].replace(/\\'/g, "'").replace(/\\\\/g, '\\')]));
    }
    const document = { getElementById: () => ({ textContent: JSON.stringify({ locale, messages }) }) };
    vm.runInNewContext(readFileSync(new URL('../../public/assets/js/adasi-i18n.js', import.meta.url), 'utf8'), { window: windowRef, document });
    return windowRef;
}

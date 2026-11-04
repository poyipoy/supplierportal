import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

function ruleBody(selector) {
    const start = css.indexOf(`\n${selector} {`);
    assert.notEqual(start, -1, `${selector} rule not found in app.css`);

    return css.slice(css.indexOf('{', start) + 1, css.indexOf('}', start));
}

test('bounded table scroll chains vertical overscroll to the page', () => {
    const body = ruleBody('.ui-table-sticky');

    assert.match(body, /overscroll-behavior-y:\s*auto/);
    assert.doesNotMatch(body, /overscroll-behavior:\s*contain/);
    assert.doesNotMatch(body, /overscroll-behavior-y:\s*contain/);
});

test('bounded table scroll keeps horizontal overscroll contained', () => {
    assert.match(ruleBody('.ui-table-sticky'), /overscroll-behavior-x:\s*contain/);
});

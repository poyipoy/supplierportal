import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function bootNumberHelper() {
    const listeners = [];
    const documentMock = {
        readyState: 'complete',
        activeElement: null,
        body: { appendChild() {} },
        addEventListener(type, handler, options) {
            listeners.push({ type, handler, options });
        },
        createElement() {
            return {
                className: '',
                classList: { add() {}, remove() {}, contains() { return false; } },
                setAttribute() {},
                style: {},
            };
        },
        querySelector() {
            return null;
        },
    };
    const windowMock = {
        AdasiI18n: { t: (k) => k },
        addEventListener() {},
        scrollY: 0,
        scrollX: 0,
        innerWidth: 1280,
    };

    const source = readFileSync(new URL('../../resources/js/number-input-helper.js', import.meta.url), 'utf8');
    vm.runInNewContext(source, { window: windowMock, document: documentMock });

    return { listeners, documentMock };
}

test('wheel blurs only focused editable number inputs in capture passive mode', () => {
    const { listeners, documentMock } = bootNumberHelper();
    const wheelEntry = listeners.find((entry) => entry.type === 'wheel');
    assert.ok(wheelEntry, 'wheel listener should be registered');
    assert.equal(wheelEntry.options?.capture, true);
    assert.equal(wheelEntry.options?.passive, true);

    let blurred = 0;
    const focusedNumberInput = {
        tagName: 'INPUT',
        type: 'number',
        readOnly: false,
        disabled: false,
        blur() {
            blurred++;
            documentMock.activeElement = null;
        },
    };
    documentMock.activeElement = focusedNumberInput;
    wheelEntry.handler({ target: focusedNumberInput });
    assert.equal(blurred, 1, 'focused number input must blur on wheel');

    // Unfocused number input should not trigger blur.
    let unfocusedBlurred = 0;
    const unfocusedNumberInput = {
        tagName: 'INPUT',
        type: 'number',
        readOnly: false,
        disabled: false,
        blur() { unfocusedBlurred++; },
    };
    documentMock.activeElement = null;
    wheelEntry.handler({ target: unfocusedNumberInput });
    assert.equal(unfocusedBlurred, 0);

    // Focused text input or readonly number input should not blur.
    let textBlurred = 0;
    const textInput = {
        tagName: 'INPUT',
        type: 'text',
        readOnly: false,
        disabled: false,
        blur() { textBlurred++; },
    };
    documentMock.activeElement = textInput;
    wheelEntry.handler({ target: textInput });
    assert.equal(textBlurred, 0);

    let readonlyBlurred = 0;
    const readonlyNumber = {
        tagName: 'INPUT',
        type: 'number',
        readOnly: true,
        disabled: false,
        blur() { readonlyBlurred++; },
    };
    documentMock.activeElement = readonlyNumber;
    wheelEntry.handler({ target: readonlyNumber });
    assert.equal(readonlyBlurred, 0);
});

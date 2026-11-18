import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, ref} from 'vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const shop = vi.hoisted(() => ({decimal: 'dot'}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => key === 'shop.decimal_separator' ? shop.decimal : fallback}}));
import PriceInput from '@/Bits/Components/Inputs/PriceInput.vue';

const mount = (cents = 431400, extra = {}) => {
    const model = ref(cents), events = [];
    const native = {selectionStart: 0, selectionEnd: 0, setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }};
    let inputProps;
    const Input = defineComponent({
        name: 'FctInput', inheritAttrs: false, props: ['modelValue'],
        setup(props, {attrs}) {
            return () => {
                inputProps = {...attrs, modelValue: props.modelValue};
                return h('div', {ref: node => {
                    if (!node) return;
                    node.querySelector = () => native;
                    node.addEventListener = (name, handler) => { native[name] = handler; };
                    node.removeEventListener = name => { delete native[name]; };
                }});
            };
        },
    });
    const Host = defineComponent({setup: () => () => h(PriceInput, {
        ...extra, modelValue: model.value,
        'onUpdate:modelValue': value => { model.value = value; },
        onChange: value => events.push(['change', value]), onBlur: value => events.push(['blur', value]),
    })});
    const app = renderer.createApp(Host);
    app.component('fct-input', Input).mount(new FakeNode('div'));
    return {
        model, events, native, app,
        get text() { return inputProps.modelValue; },
        focus() { inputProps.onFocus(); },
        async blur() { inputProps.onBlur(); await nextTick(); },
        async edit(value, start, end, inputType = 'insertText', beforeValue = inputProps.modelValue, afterCaret) {
            await nextTick();
            native.value = beforeValue;
            native.selectionStart = start; native.selectionEnd = end;
            native.beforeinput?.({target: native, inputType});
            if (afterCaret !== undefined) {
                native.value = value; native.selectionStart = afterCaret; native.selectionEnd = afterCaret;
            }
            inputProps.onInput(value);
            await nextTick(); await nextTick();
        },
    };
};

describe.each(['dot', 'comma'])('PriceInput editing (%s decimal shop)', decimal => {
    const group = decimal === 'dot' ? ',' : '.', point = decimal === 'dot' ? '.' : ',';
    it('keeps store grouping on focus and removes just one digit on Backspace', async () => {
        shop.decimal = decimal;
        const field = mount(); field.focus(); await nextTick();
        expect(field.text).toBe(`4${group}314`);
        await field.edit(`4${group}31`, 5, 5, 'deleteContentBackward');
        expect(field.text).toBe('431'); expect(field.model.value).toBe(43100);
        await field.blur(); expect(field.events).toEqual([['change', 43100], ['blur', 43100]]);
        field.app.unmount();
        expect(field.native.beforeinput).toBeUndefined();
    });
    it('formats typing inline while preserving authored separator and paste meanings', async () => {
        shop.decimal = decimal;
        const field = mount(''); field.focus();
        for (const char of `1${group}234${point}56`) {
            const at = field.text.length;
            await field.edit(field.text + char, at, at);
        }
        expect(field.text).toBe(`1${group}234${point}56`); expect(field.model.value).toBe(123456);
        await field.edit(`19${group}99`, 0, field.text.length, 'insertFromPaste');
        expect(field.model.value).toBe(1999); expect(field.text).toBe(`19${point}99`);
        field.app.unmount();
    });
    it('preserves a middle caret and skips grouping characters during deletion', async () => {
        shop.decimal = decimal;
        const field = mount(); field.focus();
        await field.edit(`4${group}14`, 2, 3, 'deleteContentForward');
        expect(field.text).toBe('414'); expect(field.native.selectionStart).toBe(1);
        await field.edit('4314', 0, 3, 'insertFromPaste');
        await field.edit('4314', 2, 2, 'deleteContentBackward');
        expect(field.text).toBe('314'); expect(field.model.value).toBe(31400);
        field.app.unmount();
    });
    it('keeps clearing, minimum, parent synchronization and blur-only change semantics', async () => {
        shop.decimal = decimal;
        const field = mount(700, {min: 5}); field.focus();
        await field.blur(); expect(field.events).toEqual([['blur', 700]]);
        field.focus(); await field.edit('0', 0, field.text.length);
        expect(field.model.value).toBe(0); await field.blur(); expect(field.model.value).toBe(500);
        field.focus(); await field.edit('', 0, field.text.length, 'deleteContentBackward');
        expect(field.model.value).toBe(''); await field.blur(); expect(field.text).toBe('');
        field.model.value = 123456; await nextTick(); expect(field.text).toBe(`1${group}234${point}56`);
        field.app.unmount();
    });
    it('uses the observed text when composition has delayed the component input event', async () => {
        shop.decimal = decimal;
        const field = mount(700); field.focus();
        await field.edit('12', 1, 1, 'insertCompositionText', '1');
        expect(field.text).toBe('12'); expect(field.model.value).toBe(1200);
        field.app.unmount();
    });
    it.each(['deleteContentBackward', 'deleteContentForward', 'deleteByCut'])('keeps the amount when only its grouping character is selected (%s)', async inputType => {
        shop.decimal = decimal;
        const field = mount(); field.focus();
        await field.edit('4314', 1, 2, inputType);
        expect(field.text).toBe(`4${group}314`); expect(field.model.value).toBe(431400);
        field.app.unmount();
    });
    it.each([['132', 2, 'insertTranspose'], ['987', 3, 'insertReplacementText']])('accepts native replacements outside a collapsed caret (%s)', async (value, caret, inputType) => {
        shop.decimal = decimal;
        const field = mount(12300); field.focus();
        await field.edit(value, caret, caret, inputType);
        expect(field.text).toBe(value); expect(field.model.value).toBe(Number(value) * 100);
        field.app.unmount();
    });
    it('accepts transposition inside a grouped integer', async () => {
        shop.decimal = decimal;
        const field = mount(123400); field.focus();
        await field.edit(`1${group}324`, 3, 3, 'insertTranspose');
        expect(field.text).toBe(`1${group}324`); expect(field.model.value).toBe(132400);
        field.app.unmount();
    });
    it('preserves the native caret when replacement keeps an unchanged suffix', async () => {
        shop.decimal = decimal;
        const field = mount(12300); field.focus();
        await field.edit('923', 3, 3, 'insertReplacementText', '123', 3);
        expect(field.text).toBe('923'); expect(field.native.selectionStart).toBe(3);
        field.app.unmount();
    });
    it.each([
        [123400, `${group}1234`, 1, 2, `1${group}234`],
        [123400, `12${group}34`, 2, 3, `1${group}234`],
        [123456, `12${group}34${point}56`, 2, 3, `1${group}234${point}56`],
        [123456700, `1${group}2345${group}67`, 6, 7, `1${group}234${group}567`],
    ])('keeps transposed grouping as formatting (%s cents)', async (cents, value, before, after, expected) => {
        shop.decimal = decimal;
        const field = mount(cents); field.focus();
        await field.edit(value, before, before, 'insertTranspose', field.text, after);
        expect(field.text).toBe(expected); expect(field.model.value).toBe(cents);
        expect(field.native.selectionStart).toBe(after);
        await field.blur(); expect(field.model.value).toBe(cents);
        field.app.unmount();
    });
});

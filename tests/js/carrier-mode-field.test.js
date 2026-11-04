import {describe, expect, it, vi} from 'vitest';
import {createSSRApp, h} from 'vue';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {renderToString} from 'vue/server-renderer';

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));

import CarrierModeField from '@/Modules/Shipping/Components/CarrierModeField.vue';
import CarrierSettingField from '@/Modules/Shipping/Components/CarrierSettingField.vue';
import {radioKeyTarget} from '@/Modules/Shipping/Components/radioKeyTarget';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const modeField = {
    key: 'sandbox_mode',
    type: 'mode',
    label: 'Connection Mode',
    hint: 'Pick one',
    options: [
        {value: 'no', label: 'Live', hint: 'Real labels', fields: [{key: 'live_token', type: 'password', label: 'Live token', hint: 'From the dashboard'}]},
        {value: 'yes', label: 'Test', fields: [{key: 'test_token', type: 'password', label: 'Test token'}]},
    ],
};

const render = (modelValue) => {
    const app = createSSRApp({
        render: () => h(CarrierModeField, {field: modeField, modelValue}, {
            default: ({option}) => option.fields.map(field => h(CarrierSettingField, {key: field.key, field, modelValue: ''})),
        }),
    });
    // Element Plus is registered globally in the admin app; plain stand-ins here.
    app.component('el-form-item', {props: ['label'], setup: (props, {slots}) => () => h('div', [h('label', props.label), slots.default?.()])});
    app.component('el-input', {setup: (props, {attrs}) => () => h('input', attrs)});
    return renderToString(app);
};

describe('Carrier mode field', () => {
    it('renders each option as a radio card and checks the stored one', async () => {
        const html = await render('yes');

        expect(html).toContain('role="radiogroup"');
        expect(html.match(/role="radio"/g)).toHaveLength(2);
        expect(html).toMatch(/role="radio"[^>]*aria-checked="false"[^>]*>[\s\S]*?Live/);
        expect(html).toMatch(/<li class="active"[^>]*>[\s\S]*?role="radio"[^>]*aria-checked="true"[^>]*>[\s\S]*?Test/);
        expect(html).toContain('id="fct-carrier-mode-hint-sandbox_mode-no"');
        expect(html).toContain('id="fct-carrier-field-hint-sandbox_mode"');
    });

    it('names each radio by its title and describes it by its hint, once each', async () => {
        const html = await render('yes');
        const liveRadio = html.match(/<div[^>]*role="radio"[^>]*>/g)[0];

        expect(liveRadio).toContain('aria-labelledby="fct-carrier-mode-title-sandbox_mode-no"');
        expect(liveRadio).toContain('aria-describedby="fct-carrier-mode-hint-sandbox_mode-no"');
        expect(html).toContain('id="fct-carrier-mode-title-sandbox_mode-no"');
        expect(html.match(/<div[^>]*role="radio"[^>]*>/g)[1]).not.toContain('aria-describedby');
    });

    it('puts only the selected radio in the tab order', async () => {
        const radios = (await render('yes')).match(/<div[^>]*role="radio"[^>]*>/g);

        expect(radios[0]).toContain('tabindex="-1"');
        expect(radios[1]).toContain('tabindex="0"');
    });

    it('puts the first radio in the tab order when none is selected', async () => {
        const radios = (await render(undefined)).match(/<div[^>]*role="radio"[^>]*>/g);

        expect(radios[0]).toContain('tabindex="0"');
        expect(radios[1]).toContain('tabindex="-1"');
    });

    it('shows only the selected option\'s fields', async () => {
        const html = await render('yes');

        expect(html).toContain('Test token');
        expect(html).not.toContain('Live token');
    });

    it('shows no option fields when the stored value matches no option', async () => {
        const html = await render(undefined);

        expect(html).not.toContain('aria-checked="true"');
        expect(html).not.toContain('Live token');
        expect(html).not.toContain('Test token');
    });

    it('renders the selected option\'s fields inside its card, outside the radio', async () => {
        const html = await render('yes');
        const cards = html.split('<li ').slice(1);
        const testCard = cards.find(card => card.includes('Test token'));

        expect(testCard).toBeDefined();
        expect(testCard).toContain('fct-radio-cards-body');
        expect(cards.find(card => card.includes('>Live<'))).not.toContain('fct-radio-cards-body');

        const radio = testCard.match(/<div class="fct-radio-cards-header"[\s\S]*?<div class="fct-radio-cards-dot-wrap"><span class="fct-radio-cards-dot"><\/span><\/div><\/div>/);
        expect(radio).not.toBeNull();
        expect(radio[0]).not.toContain('<input');
    });

    it('gives a nested field a hint id of its own key', async () => {
        const html = await render('no');

        expect(html).toContain('Live token');
        expect(html).toContain('id="fct-carrier-field-hint-live_token"');
    });
});

describe('Carrier mode field keyboard', () => {
    it('moves to the next radio on ArrowDown and ArrowRight, wrapping at the end', () => {
        expect(radioKeyTarget('ArrowDown', 0, 3)).toBe(1);
        expect(radioKeyTarget('ArrowRight', 1, 3)).toBe(2);
        expect(radioKeyTarget('ArrowDown', 2, 3)).toBe(0);
    });

    it('moves to the previous radio on ArrowUp and ArrowLeft, wrapping at the start', () => {
        expect(radioKeyTarget('ArrowUp', 2, 3)).toBe(1);
        expect(radioKeyTarget('ArrowLeft', 1, 3)).toBe(0);
        expect(radioKeyTarget('ArrowUp', 0, 3)).toBe(2);
    });

    it('jumps to the first and last radio on Home and End', () => {
        expect(radioKeyTarget('Home', 2, 3)).toBe(0);
        expect(radioKeyTarget('End', 0, 3)).toBe(2);
    });

    it('ignores every other key', () => {
        expect(radioKeyTarget('Tab', 0, 3)).toBeNull();
        expect(radioKeyTarget('a', 0, 3)).toBeNull();
        expect(radioKeyTarget('ArrowDown', 0, 0)).toBeNull();
    });

    it('wires the radio keys to the radio itself, not to the inputs in its card', () => {
        const source = fs.readFileSync(path.join(root, 'resources/admin/Modules/Shipping/Components/CarrierModeField.vue'), 'utf8');
        const radio = source.match(/<div\s+class="fct-radio-cards-header"[\s\S]*?\n\s*>/)[0];

        expect(radio).toContain('@keydown.enter.space.self.prevent="select(option)"');
        expect(radio).toMatch(/@keydown\.self="onRadioKeydown\(\$event, index\)"/);
        expect(radio).toMatch(/:ref="/);
        expect(source).toMatch(/radioKeyTarget\(event\.key, index, options\.value\.length\)/);
        expect(source).toMatch(/event\.preventDefault\(\);\s*select\(options\.value\[target\]\);\s*radioEls\[target\]\?\.focus\(\);/);
    });
});

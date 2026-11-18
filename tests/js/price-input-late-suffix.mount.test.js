import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, ref} from 'vue';
import ElementPlus from 'element-plus';
import {registerFctComponents} from '@/Bits/Components/Fct';

/**
 * PriceInput mounted for real, through the in-memory renderer, to catch
 * el-input reading its slots in a computed. A suffix or prefix that appears after mount never showed: the Create shipment
 * cost field lost its "EUR" once a carrier rate in another currency was picked.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
});

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get: (key, fallback) => {
            const values = {
                'shop.currency_sign': '$',
                'shop.decimal_separator': 'dot',
                currency_signs: {EUR: '€', USD: '$'},
                currency_decimals: {EUR: 2, USD: 2},
            };
            return key in values ? values[key] : fallback;
        },
    },
}));

import PriceInput from '@/Bits/Components/Inputs/PriceInput.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const mountHost = (currency) => {
    const Host = defineComponent({
        setup() {
            return () => h(PriceInput, {
                modelValue: 462,
                currency: currency.value,
                showCurrencySign: !!currency.value,
            }, currency.value ? {suffix: () => h('span', {class: 'iso'}, currency.value)} : {});
        },
    });
    const root = new FakeNode('div');
    renderer.createApp(Host).use(ElementPlus).use({install: registerFctComponents}).mount(root);
    return root;
};

describe('PriceInput slots that appear after mount', () => {
    it('shows the suffix once the currency becomes known', async () => {
        const currency = ref('');
        const root = mountHost(currency);

        expect(byClass(root, 'el-input__suffix')).toHaveLength(0);

        currency.value = 'EUR';
        await nextTick();
        await nextTick();

        const suffix = byClass(root, 'el-input__suffix');
        expect(suffix).toHaveLength(1);
        expect(suffix[0].textContent).toContain('EUR');
        expect(byClass(root, 'el-input--suffix')).toHaveLength(1);
        expect(byClass(root, 'el-input--prefix')).toHaveLength(1);
        expect(byClass(root, 'el-input__prefix')[0].textContent).toContain('€');
    });

    it('drops the suffix, and its padding class, once it goes away', async () => {
        const currency = ref('EUR');
        const root = mountHost(currency);

        expect(byClass(root, 'el-input__suffix')).toHaveLength(1);

        currency.value = '';
        await nextTick();
        await nextTick();

        expect(byClass(root, 'el-input__suffix')).toHaveLength(0);
        expect(byClass(root, 'el-input--suffix')).toHaveLength(0);
    });
});

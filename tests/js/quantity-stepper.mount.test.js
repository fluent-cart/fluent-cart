import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, ref} from 'vue';

/**
 * QuantityStepper keeps v-model inside min..max and disables the button at each end.
 */

vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));

import QuantityStepper from '@/Bits/Components/Inputs/QuantityStepper.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const mountStepper = (start, max) => {
    const value = ref(start);
    const root = new FakeNode('div');
    const Host = defineComponent({
        setup: () => () => h(QuantityStepper, {
            modelValue: value.value,
            'onUpdate:modelValue': (next) => { value.value = next; },
            max,
            label: 'Quantity to ship now for Vase',
            showMax: true,
        }),
    });
    renderer.createApp(Host).mount(root);
    const buttons = () => root.findAll(node => node.tagName === 'button');
    const press = async (label) => {
        buttons().filter(node => node.attrs['aria-label'] === label)[0].listeners.click();
        await nextTick();
    };
    return {root, value, buttons, press};
};

describe('QuantityStepper', () => {
    it('steps within the bounds and shows the max', async () => {
        const {root, value, press} = mountStepper(1, 2);
        expect(root.textContent).toContain('1 / 2');

        await press('Increase quantity');
        expect(value.value).toBe(2);
        await press('Decrease quantity');
        await press('Decrease quantity');
        expect(value.value).toBe(0);
    });

    it('disables decrease at the min and increase at the max', async () => {
        const {buttons, press} = mountStepper(0, 1);
        expect(buttons().map(node => node.attrs.disabled !== undefined && node.attrs.disabled !== false)).toEqual([true, false]);

        await press('Increase quantity');
        expect(buttons().map(node => node.attrs.disabled !== undefined && node.attrs.disabled !== false)).toEqual([false, true]);
    });

    it('names the group for screen readers', () => {
        const {root} = mountStepper(0, 3);
        expect(root.findAll(node => node.attrs.role === 'group')[0].attrs['aria-label']).toBe('Quantity to ship now for Vase');
    });
});

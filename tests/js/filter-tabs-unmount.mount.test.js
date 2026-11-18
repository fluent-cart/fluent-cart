import {afterEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Picking Advanced Filter while "More views" is open unmounts FilterTabs before the
 * select's close timer runs; the timer must not touch the gone tab bar.
 */

vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('element-plus', () => ({ElMessageBox: {}}));
vi.mock('@/Bits/Components/FluidTab/FluidTab.vue', () => ({
    default: defineComponent({
        setup: (props, {slots, expose}) => {
            expose({setActiveByIndex: () => {}, setActiveByActiveClass: () => {}});
            return () => h('div', slots.default && slots.default());
        },
    }),
}));
vi.mock('@/Bits/Components/FluidTab/FluidTabItem.vue', () => ({
    default: defineComponent({setup: (props, {slots}) => () => h('div', slots.default && slots.default())}),
}));

import FilterTabs from '@/Bits/Components/TableNew/FilterTabs.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const tabs = {all: 'All', paid: 'Paid', pending: 'Pending', failed: 'Failed', refunded: 'Refunded'};
const table = {
    data: {activeSavedViewId: ''},
    getTabs: () => tabs,
    getSavedViews: () => [],
    getActiveSavedViewId: () => '',
    getSelectedTab: () => 'all',
    isUsingAdvanceFilter: () => false,
    isViewOwner: () => false,
};

afterEach(() => vi.useRealTimers());

describe('FilterTabs', () => {
    it('closing More views after the tabs unmount throws nothing', async () => {
        vi.useFakeTimers();
        let select = null;
        const app = renderer.createApp(defineComponent({setup: () => () => h(FilterTabs, {table})}));
        app.component('fct-select', defineComponent({
            emits: ['visible-change'],
            setup: (props, {emit}) => {
                select = emit;
                return () => h('div');
            },
        }));
        app.mount(new FakeNode('div'));
        await nextTick();

        select('visible-change', false);
        app.unmount();

        expect(() => vi.runAllTimers()).not.toThrow();
    });
});

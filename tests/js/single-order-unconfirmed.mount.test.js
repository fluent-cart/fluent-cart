import {afterAll, beforeAll, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

/**
 * The order page takes the server's unconfirmed-label list from the Shipments
 * card and the drawer. Its deep `order` watcher counts edits for the Update
 * bar, so that list must not count as one.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({post: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/permission/Permission', () => ({default: {resolve: () => true}}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({
    _x: text => text,
    default: text => text,
    translateNumber: number => String(number),
    pluralizeTranslate: (single, plural, count) => (count === 1 ? single : plural).replace('%s', count),
}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback, onShopUpdate: () => {}}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/Theme', () => ({default: {}}));
vi.mock('echarts', () => ({}));

import Shipments from '@/Modules/Orders/Components/Shipments.vue';
import SingleOrder from '@/Modules/Orders/SingleOrder.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

// Renders the guarded button and a confirm button, in place of the teleported popover.
const PopconfirmStub = {
    props: ['confirmButtonText'],
    emits: ['confirm'],
    setup: (props, {slots, emit}) => () => h('div', [
        slots.reference && slots.reference(),
        h('button', {class: 'fct-test-confirm', onClick: () => emit('confirm')}, props.confirmButtonText),
    ]),
};

const pending = {id: 'a1', carrier: 'manual', carrier_name: 'Manual', at: 1, can_recheck: true};

// The two @unconfirmed handlers as written in the page template.
const handlers = [...readFileSync(resolve(__dirname, '../../resources/admin/Modules/Orders/SingleOrder.vue'), 'utf8')
    .matchAll(/@unconfirmed="([^"]+)"/g)].map(match => match[1]);

// SingleOrder's own watcher and methods, with one template handler wired to the card.
const mountPage = async (handler) => {
    const root = new FakeNode('div');
    const vm = renderer.createApp(defineComponent({
        data: () => ({order: {id: 7, shipments: [], unconfirmed_shipments: [pending]}, changes_made: 0}),
        watch: {order: SingleOrder.watch.order},
        methods: SingleOrder.methods,
        render() {
            const onUnconfirmed = new Function('$event', 'with (this) { ' + handler + ' }').bind(this);
            return h(Shipments, {order: this.order, onUnconfirmed});
        },
    }))
        .use(ElementPlus)
        .component('ElPopconfirm', PopconfirmStub)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await flush();
    return {root, vm};
};

describe('Order page unconfirmed-label list', () => {
    beforeAll(() => {
        globalThis.document = {activeElement: null};
    });

    afterAll(() => {
        delete globalThis.document;
    });

    it('binds both the card and the drawer', () => {
        expect(handlers).toHaveLength(2);
    });

    it.each([0, 2])('keeps the unsaved count at %i after Mark resolved', async (before) => {
        rest.post.mockResolvedValue({message: 'Marked resolved.', unconfirmed_shipments: []});
        for (const handler of handlers) {
            const {root, vm} = await mountPage(handler);
            vm.changes_made = before;

            byClass(root, 'fct-test-confirm').filter(node => node.textContent.trim() === 'Mark resolved')[0].listeners.click(new MouseEvent());
            await flush();

            expect(vm.order.unconfirmed_shipments).toEqual([]);
            expect(vm.changes_made).toBe(before);
        }
    });
});

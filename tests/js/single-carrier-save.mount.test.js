import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, reactive} from 'vue';
import ElementPlus from 'element-plus';

/**
 * The carrier settings page after a first save: the toast, and the webhook URL
 * whose secret that save issued, must show without a reload.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    // The page's SaveBar listens for beforeunload.
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    // el-button validates its click payload against this.
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({put: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn(), validationErrors: vi.fn()}));
const route = vi.hoisted(() => ({params: {slug: 'shippo'}}));
const carriers = vi.hoisted(() => ({list: []}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/KeyboardShortcut', () => ({default: () => ({bind: () => {}, unbind: () => {}})}));
vi.mock('vue-router', () => ({useRoute: () => route, useRouter: () => ({push: () => {}}), onBeforeRouteLeave: () => {}}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({
    fetchCarriers: () => Promise.resolve(carriers.list),
    invalidateCarriersCache: () => {},
}));
vi.mock('@/Modules/Shipping/CarrierZonesPanel.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/CopyToClipboard.vue', () => ({
    default: {props: ['text'], render() { return h('code', {class: 'webhook-url'}, this.text); }},
}));

import SingleCarrier from '@/Modules/Shipping/SingleCarrier.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const WEBHOOK_URL = 'https://shop.test/?fluent-cart=fct_shipping_listener_webhook&carrier=shippo&fcs_token=abc';

const shippo = (webhookUrl) => ({
    slug: 'shippo',
    meta: {name: 'Shippo', description: 'Live rates and labels.'},
    fields: [{key: 'is_active', type: 'toggle', label: 'Enable'}],
    is_enabled: false,
    settings: {is_active: 'no'},
    webhook_url: webhookUrl,
    notices: [],
});

const flush = async () => {
    for (let tick = 0; tick < 5; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    let page = null;
    const Host = defineComponent({
        setup() {
            return () => h(SingleCarrier, {ref: (instance) => { page = instance; }});
        },
    });
    renderer.createApp(Host).use(ElementPlus).mount(root);
    await flush();
    return {root, page};
};

describe('SingleCarrier first save', () => {
    beforeEach(() => {
        carriers.list = [shippo('')];
    });

    it('shows the toast and the new webhook URL without a reload', async () => {
        rest.put.mockResolvedValue({
            message: 'Carrier settings saved successfully.',
            settings: {is_active: 'no'},
            carrier: shippo(WEBHOOK_URL),
        });

        const {root} = await mountPage();
        expect(byClass(root, 'webhook-url')).toHaveLength(0);

        const saveButton = byClass(root, 'el-button--primary')[0];
        expect(saveButton).toBeTruthy();
        saveButton.listeners.click(new MouseEvent());
        await flush();

        expect(rest.put).toHaveBeenCalledTimes(1);
        expect(notify.success).toHaveBeenCalledWith('Carrier settings saved successfully.');
        const urls = byClass(root, 'webhook-url');
        expect(urls).toHaveLength(1);
        expect(urls[0].textContent).toBe(WEBHOOK_URL);
    });
});

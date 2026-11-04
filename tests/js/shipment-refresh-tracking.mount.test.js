import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Refresh tracking on a shipment row: a carrier refresh can store newer scan
 * details while the status stays the same, so the row must reload either way.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
});

const rest = vi.hoisted(() => ({post: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace('%' + (index + 1) + '$s', arg), text),
}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback}}));
vi.mock('@/Modules/Orders/Components/shipmentCost', () => ({formatShipmentCost: () => ''}));
vi.mock('@/Bits/common', () => ({
    formatDate: () => '',
    getErrorMessage: (errors, fallback) => fallback,
}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({
    default: {props: ['permission'], setup: (props, {slots}) => () => (slots.default ? slots.default() : null)},
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {name: 'IconButton', render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));

import ShipmentRecord from '@/Modules/Orders/Components/ShipmentRecord.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const shipment = {
    id: 34,
    status: 'in_transit',
    carrier_slug: 'shippo',
    carrier_name: 'Shippo',
    tracking_number: 'TRK-1',
    can_refresh_tracking: true,
    meta: {tracking_status_details: 'Left the Dhaka hub'},
};

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const findVnodes = (vnode, match, found = []) => {
    if (!vnode) {
        return found;
    }
    if (match(vnode)) {
        found.push(vnode);
    }
    if (vnode.component) {
        findVnodes(vnode.component.subTree, match, found);
    }
    if (Array.isArray(vnode.children)) {
        vnode.children.forEach(child => findVnodes(child, match, found));
    }
    return found;
};

const mountRow = () => {
    const onUpdated = vi.fn();
    const Host = defineComponent({
        setup: () => () => h(ShipmentRecord, {shipment, orderId: 12, currency: 'USD', onUpdated}),
    });
    const app = renderer.createApp(Host);
    app.use(ElementPlus)
        .component('ElPopconfirm', {render: () => null})
        .component('ElDropdown', {render: () => null})
        .component('ElPopover', {render: () => null})
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(new FakeNode('div'));
    return {app, onUpdated};
};

const clickRefresh = async (app) => {
    // The details row's Refresh tracking button; the only ElButton with a click handler.
    const button = findVnodes(app._instance.vnode, vnode => vnode.type && vnode.type.name === 'ElButton' && vnode.props && vnode.props.onClick)[0];
    button.props.onClick();
    await flush();
};

describe('Refresh tracking', () => {
    beforeEach(() => {
        rest.post.mockReset();
        notify.success.mockReset();
    });

    it('reloads the row when the carrier stored newer details under the same status', async () => {
        rest.post.mockResolvedValue({
            applied: false,
            details_updated: true,
            shipment: {...shipment, meta: {tracking_status_details: 'Arrived at the Chittagong hub'}},
            message: 'Tracking details updated from the carrier.',
        });
        const {app, onUpdated} = mountRow();

        await clickRefresh(app);

        expect(rest.post).toHaveBeenCalledWith('orders/12/shipments/34/refresh-tracking');
        expect(onUpdated).toHaveBeenCalledTimes(1);
        expect(notify.success).toHaveBeenCalledWith('Tracking details updated from the carrier.');
    });

    it('reloads the row on a status change', async () => {
        rest.post.mockResolvedValue({applied: true, shipment: {...shipment, status: 'delivered'}, message: 'Tracking updated from the carrier.'});
        const {app, onUpdated} = mountRow();

        await clickRefresh(app);

        expect(onUpdated).toHaveBeenCalledTimes(1);
    });

    it('does not reload when the refresh fails', async () => {
        rest.post.mockRejectedValue({message: 'carrier down'});
        const {app, onUpdated} = mountRow();

        await clickRefresh(app);

        expect(onUpdated).not.toHaveBeenCalled();
        expect(notify.error).toHaveBeenCalled();
    });
});

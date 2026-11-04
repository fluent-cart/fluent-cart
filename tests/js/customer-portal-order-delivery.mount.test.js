import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Every shipment shows as an accordion row: the newest update and every
 * delivery problem open, the rest collapse, and the Track link sits outside
 * the toggle button.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.fluentcart_customer_profile_vars = {datei18: {}};
});

vi.mock('../../resources/public/customer-profile/Vue/parts/ShipmentCard.vue', () => ({
    default: {name: 'ShipmentCard', props: ['shipment'], render: () => null},
}));
// The accordion animation needs a real DOM; the row logic only needs open or closed.
vi.mock('@/Bits/Components/Animation.vue', () => ({
    default: {name: 'Animation', props: ['visible', 'accordion'], setup: (props, {slots}) => () => props.visible ? slots.default() : null},
}));
vi.mock('../../resources/public/customer-profile/Vue/parts/Badge.vue', () => ({
    default: {name: 'Badge', props: ['type', 'text'], setup: props => () => h('span', {class: 'fct-badge'}, props.text)},
}));

import OrderDelivery from '../../resources/public/customer-profile/Vue/parts/OrderDelivery.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const mount = async (props) => {
    const root = new FakeNode('div');
    renderer.createApp(defineComponent({setup: () => () => h(OrderDelivery, props)})).mount(root);
    await nextTick();
    return root;
};

const shipment = (id, status, shippedAt, statusDate, url = '') => ({
    id, status, status_label: status, shipped_at: shippedAt, tracking_status_date: statusDate,
    tracking_url: url, items: [{order_item_id: id, item_name: 'Item ' + id, qty: 1}],
});

const rows = root => byClass(root, 'fct-order-delivery__row').filter(row => !String(row.className).includes('--waiting'));
const toggle = row => row.findAll(node => node.tagName === 'button')[0];
const body = row => byClass(row, 'fct-order-delivery__body')[0];

describe('order delivery accordion', () => {
    it('opens the newest update and every problem, and collapses the rest', async () => {
        const root = await mount({shipments: [
            shipment(1, 'failed', '2026-10-20 10:00:00', '2026-10-22 10:00:00'),
            shipment(2, 'delivered', '2026-10-27 09:30:00', '2026-10-31 14:05:00'),
            shipment(3, 'in_transit', '2026-11-01 16:20:00', '2026-11-02 07:15:00', 'https://track.test/3'),
        ]});

        expect(rows(root).map(row => toggle(row).attrs['aria-expanded'])).toEqual(['true', 'false', 'true']);
        expect(rows(root).map(row => Boolean(body(row)))).toEqual([true, false, true]);
        rows(root).filter(row => body(row)).forEach(row => expect(body(row).attrs.id).toBe(toggle(row).attrs['aria-controls']));
        expect(rows(root).map(row => byClass(row, 'fct-badge')[0].textContent)).toEqual(['Delivery problem', 'Delivered', 'On the way']);
    });

    it('toggles a shipment open and closed from its header button', async () => {
        const root = await mount({shipments: [
            shipment(1, 'dispatched', '2026-11-01 17:05:00', ''),
            shipment(2, 'in_transit', '2026-10-29 11:20:00', '2026-11-02 07:48:00'),
        ]});
        const first = rows(root)[0];

        expect(toggle(first).attrs['aria-expanded']).toBe('false');
        toggle(first).listeners.click();
        await nextTick();
        expect(toggle(first).attrs['aria-expanded']).toBe('true');
        expect(body(first)).toBeTruthy();
        toggle(first).listeners.click();
        await nextTick();
        expect(toggle(first).attrs['aria-expanded']).toBe('false');
        expect(body(first)).toBeUndefined();
    });

    it('keeps the Track link outside the toggle button', async () => {
        const root = await mount({shipments: [
            shipment(1, 'in_transit', '2026-11-01 16:20:00', '', 'https://track.test/1'),
            shipment(2, 'dispatched', '2026-11-01 17:05:00', ''),
        ]});
        const row = rows(root)[0];

        expect(toggle(row).findAll(node => node.tagName === 'a')).toHaveLength(0);
        expect(row.findAll(node => node.tagName === 'a' && node.attrs.href === 'https://track.test/1')).toHaveLength(1);
    });

    it('shows one shipment as an open row, and the units still to ship', async () => {
        const root = await mount({
            shipments: [shipment(1, 'in_transit', '2026-11-01 16:20:00', '')],
            unshippedItems: [{order_item_id: 9, item_name: 'Grinder', qty: 2}],
        });

        expect(rows(root)).toHaveLength(1);
        expect(toggle(rows(root)[0]).attrs['aria-expanded']).toBe('true');
        expect(body(rows(root)[0])).toBeTruthy();
        const waiting = byClass(root, 'fct-order-delivery__row--waiting')[0];
        expect(waiting.textContent).toContain('Not shipped yet');
        expect(waiting.textContent).toContain('Grinder × 2');
        expect(waiting.textContent).toContain('We will email you when it ships.');
    });
});

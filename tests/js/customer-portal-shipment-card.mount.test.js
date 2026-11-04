import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * A shipment card shows progress steps for the normal statuses, a notice with
 * a next step for a delivery problem, and neither for a status a filter added.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.fluentcart_customer_profile_vars = {datei18: {}};
});

vi.mock('../../resources/public/customer-profile/Vue/parts/Badge.vue', () => ({
    default: {name: 'Badge', props: ['type', 'text'], setup: props => () => h('span', {class: 'fct-badge'}, props.text)},
}));
vi.mock('../../resources/public/customer-profile/Vue/parts/CopyToClipboard.vue', () => ({
    default: {name: 'CopyToClipboard', props: ['text', 'buttonText', 'showMode'], setup: props => () => h('button', {class: 'copy'}, props.buttonText)},
}));

import ShipmentCard from '../../resources/public/customer-profile/Vue/parts/ShipmentCard.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const mount = async (shipment) => {
    const root = new FakeNode('div');
    renderer.createApp(defineComponent({setup: () => () => h(ShipmentCard, {shipment})})).mount(root);
    await nextTick();
    return root;
};

const base = {
    id: 1, status_label: '', carrier_name: 'USPS', service_name: 'Priority Mail', tracking_number: '9405',
    tracking_url: 'https://track.test/9405', tracking_status_details: '', tracking_status_date: '',
    shipped_at: '2026-10-29 10:00:00', notes: '', parcels: [], items: [{order_item_id: 3, item_name: 'Apron', qty: 1}],
};

describe('customer shipment card', () => {
    it('shows the three steps with the current one marked', async () => {
        const root = await mount({...base, status: 'in_transit', tracking_status_details: 'Departed Oakland'});
        const steps = byClass(root, 'fct-shipment-card__steps')[0].children.filter(node => node.tagName === 'li');

        expect(steps.map(step => String(step.className).includes('is-done'))).toEqual([true, true, false]);
        expect(steps[1].attrs['aria-current']).toBe('step');
        expect(byClass(root, 'fct-shipment-card__update')[0].textContent).toContain('Departed Oakland');
        expect(root.textContent).toContain('Track on USPS site');
        expect(root.textContent).toContain('Copy tracking number');
    });

    it('replaces the steps with a notice and a next step on a delivery problem', async () => {
        const root = await mount({...base, status: 'failed', tracking_status_details: 'Address not found'});
        const alert = byClass(root, 'fct-shipment-card__alert')[0];

        expect(byClass(root, 'fct-shipment-card__steps')).toHaveLength(0);
        expect(alert.textContent).toContain('The carrier could not deliver this parcel');
        expect(alert.textContent).toContain('Address not found');
        expect(alert.textContent).toContain('Check the carrier page, or contact us.');
    });

    it('shows no steps and no notice for a status added by a filter', async () => {
        const root = await mount({...base, status: 'held_at_customs', status_label: 'Held at customs'});

        expect(byClass(root, 'fct-shipment-card__steps')).toHaveLength(0);
        expect(byClass(root, 'fct-shipment-card__alert')).toHaveLength(0);
    });

    it('hides the tracking buttons when there is no tracking number', async () => {
        const root = await mount({...base, status: 'delivered', tracking_url: '', tracking_number: ''});

        expect(byClass(root, 'fct-shipment-card__actions')).toHaveLength(0);
        expect(root.textContent).toContain('No tracking number available');
    });
});

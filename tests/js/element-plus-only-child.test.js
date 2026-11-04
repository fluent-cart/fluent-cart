import {describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {parse} from '@vue/compiler-sfc';
import {createSSRApp, h} from 'vue';
import {renderToString} from 'vue/server-renderer';
import ElementPlus, {ID_INJECTION_KEY, ZINDEX_INJECTION_KEY} from 'element-plus';

vi.hoisted(() => {
    globalThis.window = globalThis.window || {
        fluentCartAdminApp: {app_config: {permissions: ['is_super_admin']}},
        location: {origin: 'https://example.test'},
        fluentCartRestVars: {rest: {url: ''}},
    };
});

vi.mock('@/utils/translator/Translator', () => ({default: text => text, _x: text => text, pluralizeTranslate: text => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => null, onShopUpdate: () => {}}}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => 'placeholder.png', getUrl: () => ''}}));
vi.mock('@/utils/http/Rest', () => ({default: {}}));
vi.mock('@/utils/Notify', () => ({default: {}}));
vi.mock('@/Bits/common', () => ({formatDate: () => '2026-10-22', getErrorMessage: () => '', getOrderItemImageUrl: () => ''}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: () => {}}}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({fetchDispatchCarriers: () => Promise.resolve([])}));
vi.mock('@/Modules/Orders/Components/shipmentCost', () => ({
    formatShipmentCost: () => '',
    formatShipmentCostTotal: () => '',
    formatOrderCurrencyEstimate: () => '',
    withOrderCurrencyEstimate: text => text,
}));

import ShipmentRecord from '@/Modules/Orders/Components/ShipmentRecord.vue';
import ShipmentForm from '@/Modules/Orders/Components/ShipmentForm.vue';
import Shipments from '@/Modules/Orders/Components/Shipments.vue';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

// Each of these renders its trigger slot through ElOnlyChild, which warns on more than one child.
const TRIGGER_SLOTS = {'el-tooltip': 'default', 'el-popover': 'reference', 'el-popconfirm': 'reference', 'el-dropdown': 'default'};

const isSlotTemplate = (node, name) => node.type === 1 && node.tag === 'template'
    && node.props.some(prop => prop.type === 7 && prop.name === 'slot' && (!name || (prop.arg && prop.arg.content === name)));

const hasDirective = (node, names) => node.type === 1 && node.props.some(prop => prop.type === 7 && names.includes(prop.name));

// Counts the trigger's children the way ElOnlyChild does: comments and whitespace drop out, a v-if/v-else chain is one.
const triggerChildCount = (node) => {
    const slot = TRIGGER_SLOTS[node.tag];
    const named = node.children.find(child => isSlotTemplate(child, slot));
    let children = null;
    if (named) {
        children = named.children;
    } else if (slot === 'default') {
        children = node.children.filter(child => !isSlotTemplate(child));
    }
    if (!children) {
        return null;
    }
    return children
        .filter(child => child.type !== 3 && !(child.type === 2 && !child.content.trim()))
        .filter(child => !hasDirective(child, ['else', 'else-if']))
        .length;
};

const vueFiles = (dir) => fs.readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
        return vueFiles(full);
    }
    return entry.name.endsWith('.vue') ? [full] : [];
});

const multiChildTriggers = (file) => {
    const {descriptor} = parse(fs.readFileSync(file, 'utf8'));
    const found = [];
    const walk = (node) => {
        if (node.type === 1 && TRIGGER_SLOTS[node.tag]) {
            const count = triggerChildCount(node);
            if (count !== null && count > 1) {
                found.push(path.relative(root, file) + ':' + node.loc.start.line + ' <' + node.tag + '> has ' + count + ' trigger children');
            }
        }
        (node.children || []).forEach(walk);
    };
    if (descriptor.template && descriptor.template.ast) {
        walk(descriptor.template.ast);
    }
    return found;
};

const onlyChildWarnings = (warn) => warn.mock.calls
    .map(call => String(call[0]))
    .filter(message => message.includes('ElOnlyChild'));

const renderWithElementPlus = async (component) => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const app = createSSRApp(component);
    app.use(ElementPlus);
    app.provide(ID_INJECTION_KEY, {prefix: 1, current: 0});
    app.provide(ZINDEX_INJECTION_KEY, {current: 0});
    const html = await renderToString(app);
    return {html, warnings: onlyChildWarnings(warn)};
};

describe('ElOnlyChild trigger slots', () => {
    it('give every tooltip, popover, popconfirm and dropdown trigger exactly one child', () => {
        const offenders = vueFiles(path.join(root, 'resources/admin')).flatMap(multiChildTriggers);

        expect(offenders).toEqual([]);
    });

    it('renders the order Collect Payments dropdown without an ElOnlyChild warning', async () => {
        const source = fs.readFileSync(path.join(root, 'resources/admin/Modules/Orders/SingleOrder.vue'), 'utf8');
        const start = source.lastIndexOf('<el-dropdown', source.indexOf('fct-more-option-wrap'));
        const markup = source
            .slice(start, source.indexOf('</el-dropdown>', start) + '</el-dropdown>'.length)
            .replace(/v-if="[^"]*"/, '');

        const {html, warnings} = await renderWithElementPlus({
            template: markup,
            components: {DynamicIcon: {template: '<i/>'}, InfoFilled: {template: '<i/>'}},
            methods: {translate: text => text, handleCommand() {}},
            data: () => ({canSendPaymentReminder: true, sendingPaymentReminder: false}),
        });

        expect(html).toContain('Collect Payments');
        expect(warnings).toEqual([]);
    });

    it('renders the shipment components without an ElOnlyChild warning', async () => {
        const order = {
            id: 7,
            currency: 'USD',
            fulfillment_type: 'physical',
            order_items: [{id: 1, post_title: 'Shirt', quantity: 2, fulfillment_type: 'physical'}],
        };
        const labelled = {id: 1, status: 'shipped', carrier_slug: 'shippo', carrier_name: 'Shippo', tracking_number: '1Z', tracking_url: 'https://track.test/1Z', has_label: true, can_refresh_tracking: true, meta: {transaction_id: 'tx_1', service_name: 'Ground'}, items: [{order_item_id: 1, qty: 1, item_name: 'Shirt'}]};
        const manual = {id: 2, status: 'delivered', carrier_slug: 'manual', meta: {}, items: []};

        const {html, warnings} = await renderWithElementPlus({
            render: () => [
                h(ShipmentForm, {order}),
                h(ShipmentRecord, {shipment: labelled, orderId: 7, currency: 'USD'}),
                h(Shipments, {order: {...order, shipments: [labelled, manual]}}),
            ],
        });

        // Delete now lives in the closed ⋯ menu, so the trigger is what renders.
        expect(html).toContain('More actions');
        expect(html).toContain('Refresh tracking');
        expect(warnings).toEqual([]);
    });
});

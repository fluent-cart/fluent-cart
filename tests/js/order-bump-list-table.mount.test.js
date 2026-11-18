import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, inject, nextTick, provide, reactive} from 'vue';

/**
 * The order bump list: the discount maths behind the Offer Price, and the status switch,
 * which moves only after the server accepts the change.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({put: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));
const common = vi.hoisted(() => ({$confirm: vi.fn()}));
const permission = vi.hoisted(() => ({allowed: true}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('vue-router', () => ({useRoute: () => ({}), useRouter: () => ({push: () => {}})}));
// Like the real one: formatNumber trims trailing zeros, formatFixed keeps the currency's decimals.
vi.mock('@/utils/support/CurrencyFormatter', () => ({
    default: {
        formatNumber: cents => '$' + String(Number((cents / 100).toFixed(2))),
        formatFixed: cents => '$' + (cents / 100).toFixed(2),
    },
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {props: ['name'], render: () => null}}));
vi.mock('@/Bits/Components/Table/Empty.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/common', () => common);
vi.mock('@/utils/permission/Permission', () => ({default: {resolve: () => permission.allowed}}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => 'placeholder.webp'}}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg) => out.replace('%s', arg), text),
    translateNumber: number => String(number),
}));

import {applyDiscount, hasDiscountAmount} from '@/Modules/OrderBump/orderBumpPrice';
import OrderBumpsTable from '@/Modules/OrderBump/OrderBumpsTable.vue';
import OrderBumpOfferCell from '@/Modules/OrderBump/parts/OrderBumpOfferCell.vue';
import OrderBumpPriceCell from '@/Modules/OrderBump/parts/OrderBumpPriceCell.vue';
import OrderBumpStatusSwitch from '@/Modules/OrderBump/parts/OrderBumpStatusSwitch.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 3; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (render, register = () => {}) => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => render}));
    register(app);
    app.mount(root);
    await flush();
    return root;
};

describe('Discount amount check', () => {
    it('accepts only an amount above zero', () => {
        expect(hasDiscountAmount({discount_amount: 10})).toBe(true);
        expect(hasDiscountAmount({discount_amount: '2.5'})).toBe(true);
        expect(hasDiscountAmount({discount_amount: '.5'})).toBe(true);
        expect(hasDiscountAmount({discount_amount: ' 12 '})).toBe(true);
        // Number() accepts these, but they are not plain decimals an admin means to type.
        for (const amount of [0, '0', '', null, undefined, 'abc', -5, '  ', 'Infinity', Infinity, '0x10', '1e3', '1,5', '+5', '-0', '1e999', '0.0']) {
            expect(hasDiscountAmount({discount_amount: amount})).toBe(false);
        }
        expect(hasDiscountAmount(undefined)).toBe(false);
    });
});

describe('Offer price', () => {
    it('takes a percentage off, rounded to the cent', () => {
        expect(applyDiscount(1999, {discount_type: 'percentage', discount_amount: 25})).toEqual({saving: 500, offer: 1499});
        expect(applyDiscount(570, {discount_type: 'percentage', discount_amount: 15})).toEqual({saving: 86, offer: 484});
    });

    it('rounds a tie at half a cent like the server does', () => {
        // Values taken from the server formula (PHP 8.4): these ties went the other way before.
        expect(applyDiscount(250, {discount_type: 'percentage', discount_amount: 64.6}).saving).toBe(162);
        expect(applyDiscount(1250, {discount_type: 'percentage', discount_amount: 2.28}).saving).toBe(29);
        expect(applyDiscount(1250, {discount_type: 'percentage', discount_amount: 4.52}).saving).toBe(57);
        expect(applyDiscount(1150, {discount_type: 'fixed', discount_amount: 1.01}).saving).toBe(101);
    });

    it('reads a fixed amount in currency units', () => {
        expect(applyDiscount(1150, {discount_type: 'fixed', discount_amount: '2'})).toEqual({saving: 200, offer: 950});
    });

    it('never goes below zero, and ignores a negative or missing discount', () => {
        expect(applyDiscount(1000, {discount_type: 'fixed', discount_amount: 99}).offer).toBe(0);
        expect(applyDiscount(1000, {discount_type: 'fixed', discount_amount: -5})).toEqual({saving: 0, offer: 1000});
        expect(applyDiscount(1000, undefined)).toEqual({saving: 0, offer: 1000});
    });
});

describe('Order bump status switch', () => {
    const mountSwitch = async (bump) => {
        const seen = {beforeChange: null, changed: [], loading: null, label: null};
        const ElSwitch = defineComponent({
            props: ['modelValue', 'beforeChange', 'loading', 'ariaLabel'],
            setup(props) {
                return () => {
                    seen.beforeChange = props.beforeChange;
                    seen.loading = props.loading;
                    seen.label = props.ariaLabel;
                    return h('i', {class: 'fake-switch', 'data-value': props.modelValue});
                };
            },
        });
        const app = renderer.createApp(defineComponent({
            setup: () => () => h(OrderBumpStatusSwitch, {bump, onChanged: status => seen.changed.push(status)}),
        }));
        app.component('fct-switch', ElSwitch);
        app.mount(new FakeNode('div'));
        await flush();
        return seen;
    };

    it('sends only the new status, and reports it once the server accepts', async () => {
        rest.put.mockReset().mockResolvedValue({message: 'Saved'});
        const seen = await mountSwitch({id: 7, title: 'Hoodie deal', status: 'active'});

        expect(await seen.beforeChange()).toBe(true);

        expect(rest.put).toHaveBeenCalledWith('order_bump/7/status', {status: 'draft'});
        expect(seen.changed).toEqual(['draft']);
        expect(notify.success).toHaveBeenCalledWith('Saved');
    });

    it('turns a draft bump on', async () => {
        rest.put.mockReset().mockResolvedValue({message: 'Saved'});
        const seen = await mountSwitch({id: 9, title: 'Cap', status: 'draft'});

        await seen.beforeChange();

        expect(rest.put).toHaveBeenCalledWith('order_bump/9/status', {status: 'active'});
        expect(seen.changed).toEqual(['active']);
    });

    it('stays where it was, and says why, when the server refuses', async () => {
        rest.put.mockReset().mockRejectedValue({data: {message: 'Please choose a valid status'}});
        notify.error.mockReset();
        const seen = await mountSwitch({id: 7, title: 'Hoodie deal', status: 'active'});

        expect(await seen.beforeChange()).toBe(false);

        expect(seen.changed).toEqual([]);
        expect(notify.error).toHaveBeenCalledWith('Please choose a valid status');
    });

    it('names the switch after the bump', async () => {
        expect((await mountSwitch({id: 1, title: 'Hoodie deal', status: 'active'})).label).toBe('Enable Hoodie deal');
    });
});

describe('Order bumps list table', () => {
    const row = (id, status, variant, discount, extra = {}) => ({
        id, title: 'Deal ' + id, status, priority: id === 5 ? 7 : 3,
        config: {discount, ...extra},
        product_variant: variant,
    });
    const rows = [
        row(5, 'active', {post_id: 11, payment_type: 'onetime', item_price: 1250, variation_title: 'White', product: {post_title: 'Zipper Hoodie'}}, {discount_type: 'percentage', discount_amount: 20}),
        row(2, 'draft', {post_id: 12, payment_type: 'subscription', item_price: 2000, variation_title: 'Plan', other_info: {billing_summary: '20 daily'}, product: {post_title: 'Simple products'}}, {discount_type: 'fixed', discount_amount: 2}, {position: 'above_summary', show_in_cart: 'yes'}),
    ];

    // A stand-in for the Element Plus table: it collects the columns, then renders each row's cells.
    const FakeTable = defineComponent({
        props: ['data', 'rowClassName'],
        setup(props, {slots}) {
            const columns = reactive([]);
            provide('fake-columns', columns);
            return () => h('div', {class: 'fake-table'}, [
                h('div', slots.default && slots.default()),
                h('div', {class: 'fake-header'}, columns.map(column => h('span', {class: 'fake-th'}, column.label))),
                ...props.data.map(row => h('div', {class: ['fake-row', props.rowClassName ? props.rowClassName({row}) : '']}, columns.map(column => h('div', {class: 'fake-cell'}, column.slots.default ? column.slots.default({row}) : [])))),
            ]);
        },
    });
    const FakeColumn = defineComponent({
        props: ['label'],
        setup(props, {slots}) {
            inject('fake-columns').push({label: props.label, slots});
            return () => null;
        },
    });

    let dropdownEmit = null;
    let switches = [];
    const startStatuses = rows.map(bump => bump.status);

    const mountTable = async (tab = 'all') => {
        // The table changes a row's status in place, so each mount starts from the original.
        rows.forEach((bump, index) => { bump.status = startStatuses[index]; });
        switches = [];
        const fetch = vi.fn();
        const orderBumpTable = {getTableData: () => rows, getSelectedTab: () => tab, fetch};
        const root = await mount(() => h(OrderBumpsTable, {orderBumpTable}), (app) => {
            app.component('fct-table', FakeTable);
            app.component('fct-table-column', FakeColumn);
            app.component('fct-switch', defineComponent({
                props: ['beforeChange'],
                setup(props) {
                    switches.push(props);
                    return () => h('i', {class: 'el-switch'});
                },
            }));
            app.component('fct-dropdown', defineComponent({
                emits: ['command'],
                setup(props, {emit, slots}) {
                    dropdownEmit = emit;
                    return () => h('div', slots.default && slots.default());
                },
            }));
            app.component('el-dropdown-menu', defineComponent({render: () => null}));
            app.component('el-dropdown-item', defineComponent({render: () => null}));
            app.component('router-link', defineComponent({props: ['to'], render() { return h('a', {class: 'fake-link'}, this.$slots.default && this.$slots.default()); }}));
        });
        return {root, fetch};
    };

    it('shows the Offer, Price, Discount, Shows on, Priority and Status columns for each bump', async () => {
        const {root} = await mountTable();
        const text = root.textContent;

        for (const header of ['ID', 'Bump Title', 'Offer', 'Price', 'Discount', 'Shows on', 'Priority', 'Status']) {
            expect(text).toContain(header);
        }
        expect(text).not.toContain('Product');

        expect(text).toContain('Zipper Hoodie');
        expect(text).toContain('Regular Price: $12.50');
        expect(text).toContain('Offer Price: $10.00');
        expect(text).toContain('Percentage');
        expect(text).toContain('20%');

        // A subscription is priced like any other offer, and a fixed discount reads in currency.
        // The line under the name is the variation, never the billing text.
        expect(text).toContain('Plan');
        expect(text).not.toContain('20 daily');
        expect(text).toContain('Regular Price: $20.00');
        expect(text).toContain('Offer Price: $18.00');
        expect(text.match(/Regular Price/g)).toHaveLength(2);
        expect(text).toContain('Fixed Discount');
        expect(text).toContain('$2.00');
    });

    it('says where each bump shows: the checkout spot, and the cart when it is on', async () => {
        const {root} = await mountTable();
        const headers = byClass(root, 'fake-th').map(node => node.textContent);
        const cellOf = (rowIndex) => byClass(byClass(root, 'fake-row')[rowIndex], 'fake-cell')[headers.indexOf('Shows on')].textContent.trim();

        expect(cellOf(0)).toBe('Checkout · under order notes');
        expect(cellOf(1)).toBe('Checkout · above order summaryCart');
    });

    it('shows each bump\'s priority in its own cell, right before the Status column', async () => {
        const {root} = await mountTable();
        const headers = byClass(root, 'fake-th').map(node => node.textContent);

        expect(headers.indexOf('Priority')).toBe(headers.indexOf('Status') - 1);
        const cells = byClass(byClass(root, 'fake-row')[0], 'fake-cell');
        expect(cells[headers.indexOf('Priority')].textContent.trim()).toBe('7');
        expect(byClass(byClass(root, 'fake-row')[1], 'fake-cell')[headers.indexOf('Priority')].textContent.trim()).toBe('3');
    });

    it('asks to confirm a delete by naming the bump', async () => {
        common.$confirm.mockReset().mockRejectedValue('cancel');
        await mountTable();

        dropdownEmit('command', {action: 'delete', orderBump: rows[0]});

        expect(common.$confirm).toHaveBeenCalledTimes(1);
        expect(common.$confirm.mock.calls[0][0]).toBe('Are you sure you want to delete "Deal 5"?');
        expect(common.$confirm.mock.calls[0][1]).toBe('Confirm Delete!');
    });

    describe('after a status switch', () => {
        const flip = async (tab, index) => {
            rest.put.mockReset().mockResolvedValue({message: 'Saved'});
            const {fetch} = await mountTable(tab);
            await switches[index].beforeChange();
            return fetch;
        };

        it('loads the tab again when the bump no longer fits it', async () => {
            expect(await flip('active', 0)).toHaveBeenCalledTimes(1);
            expect(await flip('draft', 1)).toHaveBeenCalledTimes(1);
        });

        it('leaves the list alone when the bump still fits the tab, or on All', async () => {
            expect(await flip('draft', 0)).not.toHaveBeenCalled();
            expect(await flip('active', 1)).not.toHaveBeenCalled();
            expect(await flip('all', 0)).not.toHaveBeenCalled();
        });
    });

    it('shows a dash instead of price, discount, shows on, priority and status for a bump whose product is gone, with no colour on the row', async () => {
        const gone = {id: 9, status: 'active', title: 'Gone', priority: 5, config: {discount: {discount_type: 'percentage', discount_amount: 10}}, product_variant: {post_id: 1, item_price: 1000, product: null}};
        rows.push(gone);
        try {
            const root = (await mountTable()).root;
            const headers = byClass(root, 'fake-th').map(node => node.textContent);
            const fakeRows = byClass(root, 'fake-row');
            const cells = byClass(fakeRows[2], 'fake-cell').map(node => node.textContent.trim());

            expect(fakeRows).toHaveLength(3);
            expect(String(fakeRows[2].className)).not.toContain('missing');
            for (const header of ['Price', 'Discount', 'Shows on', 'Priority', 'Status']) {
                expect(cells[headers.indexOf(header)]).toBe('—');
            }
            expect(cells[headers.indexOf('ID')]).toBe('#9');
            expect(cells[headers.indexOf('Bump Title')]).toBe('Gone');
            expect(cells[headers.indexOf('Offer')]).toContain('Product deleted');
            expect(byClass(fakeRows[2], 'el-switch')).toHaveLength(0);
            expect(byClass(fakeRows[0], 'el-switch')).toHaveLength(1);
        } finally {
            rows.pop();
        }
    });

    it('has one switch and one image tile per bump', async () => {
        const {root} = await mountTable();

        expect(byClass(root, 'el-switch')).toHaveLength(2);
        expect(byClass(root, 'fct-order-bump-offer__tile')).toHaveLength(2);
    });
});

describe('Price column', () => {
    const variant = {post_id: 3, payment_type: 'onetime', item_price: 1250, variation_title: 'White', product: {post_title: 'Zipper Hoodie'}};
    const regularOf = async (discount) => {
        const bump = {id: 1, config: {discount}, product_variant: variant};
        const root = await mount(() => h(OrderBumpPriceCell, {bump}));
        const regular = byClass(root, 'fct-order-bump-price__regular')[0];
        return {text: regular.textContent.trim(), struck: String(regular.className).includes('fct-order-bump-price__regular--struck')};
    };

    it('strikes the regular price out when the offer is cheaper', async () => {
        expect(await regularOf({discount_type: 'percentage', discount_amount: 20})).toEqual({text: '$12.50', struck: true});
    });

    it('leaves the regular price alone when there is no saving', async () => {
        expect(await regularOf({discount_type: 'percentage', discount_amount: 0})).toEqual({text: '$12.50', struck: false});
    });

    it('shows a dash when the product is gone, whether the variation or only the product record is missing', async () => {
        for (const product_variant of [null, {item_price: 1000, product: null}]) {
            const root = await mount(() => h(OrderBumpPriceCell, {bump: {id: 1, config: {discount: {}}, product_variant}}));

            expect(root.textContent.trim()).toBe('—');
            expect(root.textContent).not.toContain('Price');
        }
    });

    it('is not part of the Offer cell any more', async () => {
        const root = await mount(() => h(OrderBumpOfferCell, {bump: {id: 1, config: {discount: {}}, product_variant: variant}}), (app) => {
            app.component('router-link', defineComponent({render() { return h('a', this.$slots.default && this.$slots.default()); }}));
        });

        expect(root.textContent).not.toContain('Price');
    });
});

describe('Offer image', () => {
    const variant = (thumbnail, productThumbnail) => ({
        post_id: 3, payment_type: 'onetime', item_price: 1000, variation_title: 'White', thumbnail,
        product: {post_title: 'Zipper Hoodie', thumbnail: productThumbnail},
    });
    const tileOf = async (image, thumbnail, productThumbnail) => {
        const bump = {id: 1, config: {discount: {}, image}, product_variant: variant(thumbnail, productThumbnail)};
        const root = await mount(() => h(OrderBumpOfferCell, {bump}), (app) => {
            app.component('router-link', defineComponent({render() { return h('a', this.$slots.default && this.$slots.default()); }}));
        });
        const tile = byClass(root, 'fct-order-bump-offer__tile')[0];
        const img = tile.findAll(node => node.tagName === 'img')[0];
        return img.attrs.src;
    };

    it('links the product name only when the user can open the product', async () => {
        const nameOf = async (allowed) => {
            permission.allowed = allowed;
            const bump = {id: 1, config: {discount: {}}, product_variant: variant(null, null)};
            const root = await mount(() => h(OrderBumpOfferCell, {bump}), (app) => {
                app.component('router-link', defineComponent({render() { return h('a', {class: 'fake-link'}, this.$slots.default && this.$slots.default()); }}));
            });
            return {linked: byClass(root, 'fake-link').length === 1, plain: byClass(root, 'fct-order-bump-offer__product').length === 1};
        };

        expect(await nameOf(true)).toEqual({linked: true, plain: false});
        expect(await nameOf(false)).toEqual({linked: false, plain: true});
        permission.allowed = true;
    });

    it('says the product is deleted when the product is gone, with the placeholder image and no link or prices', async () => {
        permission.allowed = true;
        const bump = {id: 1, config: {discount: {}}, product_variant: {post_id: 3, payment_type: 'onetime', item_price: 1000, product: null}};
        const root = await mount(() => h(OrderBumpOfferCell, {bump}), (app) => {
            app.component('router-link', defineComponent({render() { return h('a', {class: 'fake-link'}, this.$slots.default && this.$slots.default()); }}));
        });

        expect(byClass(root, 'fake-link')).toHaveLength(0);
        expect(root.textContent).toContain('Product deleted · choose another');
        expect(root.textContent).not.toContain('Offer Price');
        expect(byClass(root, 'fct-order-bump-offer__tile')[0].findAll(node => node.tagName === 'img')[0].attrs.src).toBe('placeholder.webp');
    });

    it('says the product is deleted when the bump has no product at all', async () => {
        const bump = {id: 1, config: {discount: {}}, product_variant: null};
        const root = await mount(() => h(OrderBumpOfferCell, {bump}), (app) => {
            app.component('router-link', defineComponent({render() { return h('a', this.$slots.default && this.$slots.default()); }}));
        });

        expect(root.textContent).toContain('Product deleted · choose another');
        expect(byClass(root, 'fct-order-bump-offer__tile')[0].findAll(node => node.tagName === 'img')[0].attrs.src).toBe('placeholder.webp');
    });

    it("uses the bump's own image first", async () => {
        expect(await tileOf({id: 5, url: 'custom.jpg'}, 'variant.jpg', 'product.jpg')).toBe('custom.jpg');
    });

    it("then the variation's image", async () => {
        expect(await tileOf({id: 0, url: ''}, 'variant.jpg', 'product.jpg')).toBe('variant.jpg');
    });

    it("then the product's feature image", async () => {
        expect(await tileOf(undefined, null, 'product.jpg')).toBe('product.jpg');
    });

    it('shows the placeholder image when there is no image at all', async () => {
        expect(await tileOf({id: 0, url: ''}, null, null)).toBe('placeholder.webp');
    });
});

import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Order sidebar: editing the order's address in "Manage address" must show the
 * saved order address on the card, without a page reload.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
    globalThis.jQuery = () => ({length: 0});
});

const rest = vi.hoisted(() => ({get: vi.fn(), put: vi.fn(), post: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn(), validationErrors: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({
    default: text => text,
    translateNumber: number => String(number),
}));
vi.mock('@/Bits/productService', () => ({formatNumber: () => ''}));
vi.mock('@/mixin/saveButtonShortcutMixin', () => ({useSaveShortcut: () => ({onSave: () => {}})}));

const passThrough = vi.hoisted(() => ({
    setup: (props, {slots}) => () => slots.default ? slots.default() : null,
}));
// The template reads the namespace through unref(), which probes __v_isRef.
vi.mock('@/Bits/Components/Card/Card.js', () => ({
    __v_isRef: false,
    Container: passThrough,
    Header: {props: ['title'], setup: (props, {slots}) => () => (slots.action ? slots.action() : null)},
    Body: passThrough,
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/UserAvatar.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/CopyToClipboard.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/Modals/NewCustomerModal.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/Components/ChangeOrderCustomer.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/MaterialInput.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Address/AddressComponent.vue', () => ({default: {render: () => null}}));

import OrderCustomerInformation from '@/Modules/Orders/OrderCustomerInformation.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

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

const orderAddress = (type, street, phone) => ({
    id: type === 'billing' ? 81 : 82,
    order_id: 11,
    type,
    name: 'Jane Doe',
    address_1: street,
    city: 'Dhaka',
    meta: {other_data: {phone}},
});

const customerAddress = (type, street) => ({
    id: type === 'billing' ? 7 : 8,
    customer_id: 5,
    type,
    is_primary: '1',
    name: 'Jane Doe',
    address_1: street,
    city: 'Dhaka',
    meta: {other_data: {}},
});

const mountCard = async () => {
    const order = {
        id: 11,
        customer_id: 5,
        customer: {id: 5, full_name: 'Jane Doe', email: 'jane@example.com', user_id: '0'},
        billing_address: orderAddress('billing', '1 Old Billing Rd', '111'),
        shipping_address: orderAddress('shipping', '1 Old Shipping Rd', '222'),
    };
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(OrderCustomerInformation, {order})});
    const app = renderer.createApp(Host);
    app.use(ElementPlus)
        .component('ElDialog', passThrough)
        .component('ElDropdown', passThrough)
        .component('RouterLink', passThrough)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0});
    Object.assign(app.config.globalProperties, {
        getOrderAddress: address => [address.address_1, address.city].filter(Boolean).join(', '),
        $n: (singular, plural, count, empty) => (count ? plural : empty),
        $t: text => text,
        $get: () => Promise.resolve({addresses: []}),
        $router: {push: () => {}},
    });
    app.mount(root);
    await flush();
    return {app, root};
};

// Opens "Manage <type> address", edits the customer's address and clicks Update.
const editAddressFromManageModal = async (app, type, address) => {
    const dropdown = findVnodes(app._instance.vnode, vnode => vnode.props && typeof vnode.props.onCommand === 'function')[0];
    dropdown.props.onCommand('manage_' + type + '_address');
    await flush();

    const manageModal = findVnodes(app._instance.vnode, vnode => vnode.type && vnode.type.name === 'ManageAddressModal')[0];
    manageModal.component.proxy.editAddress(address);
    await flush();

    const updateButton = findVnodes(manageModal.component.subTree, vnode =>
        vnode.type && vnode.type.name === 'ElButton' && vnode.props && vnode.props.type === 'primary' && 'loading' in vnode.props
    )[0];
    updateButton.props.onClick();
    await flush();
};

describe('Order address card after editing an address', () => {
    beforeEach(() => {
        rest.put.mockReset();
        rest.post.mockReset();
    });

    it.each(['shipping', 'billing'])('shows the saved %s order address after Update', async (type) => {
        const edited = customerAddress(type, '2 New Street');
        rest.put.mockResolvedValue({
            message: 'Address updated',
            data: edited,
            order_address: orderAddress(type, '2 New Street', '333'),
        });
        const {app, root} = await mountCard();
        const oldStreet = type === 'billing' ? '1 Old Billing Rd' : '1 Old Shipping Rd';
        expect(root.textContent).toContain(oldStreet);

        await editAddressFromManageModal(app, type, edited);

        expect(rest.put).toHaveBeenCalledWith('customers/5/address', expect.objectContaining({id: edited.id, order_id: 11}));
        expect(root.textContent).toContain('2 New Street');
        expect(root.textContent).toContain('333');
        expect(root.textContent).not.toContain(oldStreet);
    });

    it('keeps the order address when the edit did not change the order', async () => {
        const edited = {...customerAddress('shipping', '9 Other Street'), is_primary: '0'};
        rest.put.mockResolvedValue({message: 'Address updated', data: edited});
        const {app, root} = await mountCard();

        await editAddressFromManageModal(app, 'shipping', edited);

        expect(rest.put).toHaveBeenCalledWith('customers/5/address', expect.objectContaining({order_id: null}));
        expect(root.textContent).toContain('1 Old Shipping Rd');
        expect(root.textContent).not.toContain('9 Other Street');
    });
});

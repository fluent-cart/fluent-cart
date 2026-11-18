import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * A package saved in Settings → Packages (or from the product editor's own
 * "Add new package") must show in the product editor's Package dropdown on the
 * next mount, without a full page reload.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    // AppConfig decodes currency entities through a textarea.
    globalThis.document = globalThis.document || {createElement: () => ({innerHTML: '', value: ''})};
    // Keep AppConfig's cross-tab channel out of the test process.
    globalThis.BroadcastChannel = undefined;
    globalThis.window.fluentCartAdminApp = {
        shop: {
            currency_sign: '$',
            shipping_packages: [
                {slug: 'small-box', name: 'Small box', type: 'box', length: 10, width: 10, height: 10, dimension_unit: 'cm', weight: 1, weight_unit: 'kg', is_default: true},
            ],
        },
    };
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn()}));
const dialog = vi.hoisted(() => ({save: null}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text, _x: text => text, translateNumber: value => value}));
vi.mock('element-plus', () => ({ElMessage: vi.fn(), ElMessageBox: {confirm: vi.fn()}}));
vi.mock('@/Modules/Shipping/Components/PackageDialog.vue', () => ({
    default: defineComponent({
        emits: ['save'],
        setup(props, {emit}) {
            dialog.save = (data) => emit('save', data);
            return () => null;
        },
    }),
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Animation.vue', () => ({
    default: {setup: (props, {slots}) => () => h('div', slots.default && slots.default())},
}));
vi.mock('@/Modules/Products/parts/SharedVariantItemBox.vue', () => ({
    default: {setup: (props, {slots}) => () => h('div', slots.default && slots.default())},
}));

import AllShippingPackages from '@/Modules/Shipping/AllShippingPackages.vue';
import ProductShipping from '@/Modules/Products/parts/ProductShipping.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const SMALL = {slug: 'small-box', name: 'Small box', type: 'box', length: 10, width: 10, height: 10, dimension_unit: 'cm', weight: 1, weight_unit: 'kg', is_default: true};
const LARGE = {slug: 'large-box', name: 'Large box', type: 'box', length: 40, width: 30, height: 30, dimension_unit: 'cm', weight: 2, weight_unit: 'kg', is_default: false};

const passThrough = (tag) => ({
    inheritAttrs: false,
    setup: (props, {slots}) => () => h('div', {class: tag}, slots.default ? slots.default() : []),
});

const elementStubs = {
    'el-row': passThrough('el-row'),
    'el-col': passThrough('el-col'),
    'el-form-item': passThrough('el-form-item'),
    'el-select': passThrough('el-select'),
    'el-input': passThrough('el-input'),
    'el-switch': passThrough('el-switch'),
    'el-tag': passThrough('el-tag'),
    'el-button': passThrough('el-button'),
    'el-dropdown': passThrough('el-dropdown'),
    'el-dropdown-menu': passThrough('el-dropdown-menu'),
    'el-dropdown-item': passThrough('el-dropdown-item'),
    'el-skeleton': passThrough('el-skeleton'),
    'el-empty': passThrough('el-empty'),
    'el-option': {
        props: ['value', 'label'],
        setup: (props) => () => h('div', {class: 'package-option', 'data-value': props.value}, props.label),
    },
    'fct-select': {
        props: ['options'],
        setup: (props) => () => h('div', {class: 'fct-select'}, (props.options || []).map(option => h('div', {class: 'package-option', 'data-value': option.value}, option.label))),
    },
};

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (component, props = {}) => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(component, props)}));
    Object.keys(elementStubs).forEach((name) => app.component(name, elementStubs[name]));
    app.directive('loading', {});
    app.mount(root);
    await flush();
    return {root, app};
};

const productPackageSlugs = async () => {
    const variant = {fulfillment_type: 'physical', other_info: {package_slug: '', weight: '', weight_unit: 'kg'}};
    const productEditModel = {updatePricingOtherValue: vi.fn(), updatePricingValue: vi.fn()};
    const {root, app} = await mount(ProductShipping, {variant, fieldKey: '0', modeType: 'edit', productEditModel});
    const slugs = byClass(root, 'package-option')
        .map(node => node.attrs['data-value'])
        .filter(slug => slug !== '__add_new__');
    app.unmount();
    return slugs;
};

describe('product editor Package dropdown after a package save', () => {
    beforeEach(() => {
        dialog.save = null;
        rest.get.mockResolvedValue({packages: [SMALL]});
    });

    it('lists a package added in Settings → Packages without a reload', async () => {
        expect(await productPackageSlugs()).toEqual(['small-box']);

        rest.post.mockResolvedValue({message: 'Saved', packages: [SMALL, LARGE]});
        const {app} = await mount(AllShippingPackages);
        dialog.save(Object.assign({}, LARGE));
        await flush();
        expect(rest.post).toHaveBeenCalledTimes(1);
        app.unmount();

        expect(await productPackageSlugs()).toEqual(['small-box', 'large-box']);
    });

    it('shows the list the server saved, so edits and removals carry over', async () => {
        rest.get.mockResolvedValue({packages: [SMALL, LARGE]});
        rest.post.mockResolvedValue({message: 'Saved', packages: [LARGE]});
        const {app} = await mount(AllShippingPackages);
        dialog.save(Object.assign({}, LARGE));
        await flush();
        app.unmount();

        expect(await productPackageSlugs()).toEqual(['large-box']);
    });

    it('keeps a package added from the product editor for the next product mount', async () => {
        const variant = {fulfillment_type: 'physical', other_info: {package_slug: '', weight: '', weight_unit: 'kg'}};
        const productEditModel = {updatePricingOtherValue: vi.fn(), updatePricingValue: vi.fn()};
        const medium = Object.assign({}, LARGE, {slug: 'medium-box', name: 'Medium box'});
        rest.post.mockResolvedValue({packages: [LARGE, medium]});

        const {app} = await mount(ProductShipping, {variant, fieldKey: '0', modeType: 'edit', productEditModel});
        dialog.save(Object.assign({}, medium));
        await flush();
        expect(rest.post).toHaveBeenCalledTimes(1);
        app.unmount();

        expect(await productPackageSlugs()).toEqual(['large-box', 'medium-box']);
    });

    it('leaves the list unchanged when the save fails', async () => {
        rest.get.mockResolvedValue({packages: [LARGE]});
        const before = await productPackageSlugs();
        rest.post.mockRejectedValue({message: 'nope'});
        const {app} = await mount(AllShippingPackages);
        dialog.save(Object.assign({}, SMALL, {slug: 'other-box'}));
        await flush();
        app.unmount();

        expect(await productPackageSlugs()).toEqual(before);
    });

    it('leaves the list unchanged when a product-editor create fails', async () => {
        const before = await productPackageSlugs();
        const variant = {fulfillment_type: 'physical', other_info: {package_slug: '', weight: '', weight_unit: 'kg'}};
        const productEditModel = {updatePricingOtherValue: vi.fn(), updatePricingValue: vi.fn()};
        rest.post.mockRejectedValue({message: 'nope'});

        const {app} = await mount(ProductShipping, {variant, fieldKey: '0', modeType: 'edit', productEditModel});
        dialog.save(Object.assign({}, SMALL, {slug: 'unsaved-box', is_default: true}));
        await flush();
        app.unmount();

        expect(await productPackageSlugs()).toEqual(before);
    });
});

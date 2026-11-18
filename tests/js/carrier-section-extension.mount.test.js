import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Carrier settings: an add-on draws its own Vue inside a section card by registering
 * carrier_<slug>_<section key> through the fluent_cart_admin_components filter, the way
 * Pro draws license_table. The component gets the carrier and can hand back a rebuilt one.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const carriers = vi.hoisted(() => ({list: [], invalidate: null}));
const fieldInputs = vi.hoisted(() => ({}));

vi.mock('vue-router', async () => {
    const {reactive} = await import('vue');
    const route = reactive({params: {slug: 'demo'}});
    return {useRoute: () => route, useRouter: () => ({push: () => {}}), onBeforeRouteLeave: () => {}, __route: route};
});
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(), post: vi.fn(), put: vi.fn()}}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text, translateNumber: (value) => value}));
vi.mock('@/Modules/Shipping/CarrierZonesPanel.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback ?? '', onShopUpdate: () => {}}}));
vi.mock('@/utils/KeyboardShortcut', () => ({default: () => ({bind: () => {}, unbind: () => {}})}));
vi.mock('@/Modules/Shipping/useCarriers', () => {
    carriers.invalidate = vi.fn();
    return {fetchCarriers: () => Promise.resolve(carriers.list), invalidateCarriersCache: carriers.invalidate};
});
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/SaveBar.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Shipping/Components/CarrierSetupNotices.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Shipping/Components/CarrierSettingField.vue', () => ({
    default: {
        props: ['modelValue', 'field', 'saved'],
        emits: ['update:modelValue'],
        setup(props, {emit}) {
            fieldInputs[props.field.key] = (value) => emit('update:modelValue', value);
            return () => h('div', {class: 'fake-field', 'data-key': props.field.key}, String(props.modelValue ?? ''));
        },
    },
}));

import SingleCarrier from '@/Modules/Shipping/SingleCarrier.vue';
import {__route as route} from 'vue-router';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const carrierPayload = (slug, overrides = {}) => ({
    slug,
    is_enabled: true,
    meta: {name: slug + ' carrier'},
    settings: {api_key: 'saved-key', label: 'Saved label'},
    saved_secrets: {},
    fields: [
        {key: 'label', type: 'text', label: 'Label'},
        {key: 'section_connection', type: 'section', label: 'Connection'},
        {key: 'api_key', type: 'text', label: 'API key'},
        {key: 'section_empty', type: 'section', label: 'Account'},
    ],
    ...overrides,
});

// Each fake add-on component records the carrier it was given and keeps its emit.
const emitted = {};
const fakeAddonComponent = (id) => defineComponent({
    props: ['carrier'],
    emits: ['carrier'],
    setup(props, {emit}) {
        emitted[id] = (payload) => emit('carrier', payload);
        return () => h('div', {class: 'demo-ext', 'data-id': id, 'data-slug': props.carrier.slug, 'data-label': props.carrier.meta.name});
    },
});

let registered = {};

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    renderer.createApp({render: () => h(SingleCarrier)}).use(ElementPlus).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const cards = (root) => byClass(root, 'fct-carrier-card');
const cardTitled = (root, title) => cards(root).find(card => byClass(card, 'fct-carrier-section__title')[0]?.textContent.trim() === title);
const extensions = (node) => byClass(node, 'demo-ext');
const fieldValue = (root, key) => byClass(root, 'fake-field').find(node => node.attrs['data-key'] === key).textContent;

describe('Carrier settings section render sites', () => {
    beforeEach(() => {
        registered = {};
        Object.keys(emitted).forEach(key => delete emitted[key]);
        carriers.list = [carrierPayload('demo'), carrierPayload('other')];
        carriers.invalidate.mockReset();
        route.params.slug = 'demo';
        window.fluent_cart_admin = {hooks: {applyFilters: (name, value) => (name === 'fluent_cart_admin_components' ? {...value, ...registered} : value)}};
    });

    it('renders nothing extra when no add-on registered a component', async () => {
        const root = await mountPage();

        expect(cards(root)).toHaveLength(3);
        expect(extensions(root)).toHaveLength(0);
    });

    it('draws a registered component inside its section card with the carrier', async () => {
        registered.carrier_demo_section_connection = fakeAddonComponent('connection');
        registered.carrier_demo_section_empty = fakeAddonComponent('account');
        const root = await mountPage();

        const connection = extensions(cardTitled(root, 'Connection'));
        expect(connection).toHaveLength(1);
        expect(connection[0].attrs['data-id']).toBe('connection');
        expect(connection[0].attrs['data-slug']).toBe('demo');
        expect(extensions(cards(root)[0])).toHaveLength(0);

        // A section with no fields still shows its card for the component alone.
        expect(extensions(cardTitled(root, 'Account')).map(node => node.attrs['data-id'])).toEqual(['account']);
        expect(extensions(root)).toHaveLength(2);
    });

    it('uses intro for the card before the first section', async () => {
        registered.carrier_demo_intro = fakeAddonComponent('intro');
        const root = await mountPage();

        expect(extensions(cards(root)[0]).map(node => node.attrs['data-id'])).toEqual(['intro']);
    });

    it('re-resolves the component when only the carrier slug changes', async () => {
        registered.carrier_demo_section_connection = fakeAddonComponent('demo');
        registered.carrier_other_section_connection = fakeAddonComponent('other');
        const root = await mountPage();
        expect(extensions(root).map(node => node.attrs['data-id'])).toEqual(['demo']);

        route.params.slug = 'other';
        await flush();

        const drawn = extensions(cardTitled(root, 'Connection'));
        expect(drawn.map(node => node.attrs['data-id'])).toEqual(['other']);
        expect(drawn[0].attrs['data-slug']).toBe('other');
    });

    it('applies a carrier the component hands back and keeps unsaved edits elsewhere', async () => {
        registered.carrier_demo_section_connection = fakeAddonComponent('connection');
        const root = await mountPage();

        fieldInputs.label('Typed label');
        await flush();

        emitted.connection(carrierPayload('demo', {meta: {name: 'Connected demo'}, settings: {api_key: 'new-key', label: 'Saved label'}}));
        await flush();

        expect(carriers.invalidate).toHaveBeenCalledTimes(1);
        expect(extensions(root)[0].attrs['data-label']).toBe('Connected demo');
        expect(fieldValue(root, 'api_key')).toBe('new-key');
        expect(fieldValue(root, 'label')).toBe('Typed label');
    });

    it('ignores a payload that is not a carrier object', async () => {
        registered.carrier_demo_section_connection = fakeAddonComponent('connection');
        const root = await mountPage();

        for (const payload of [null, undefined, 'carrier', 42, ['demo']]) {
            emitted.connection(payload);
        }
        await flush();

        expect(carriers.invalidate).not.toHaveBeenCalled();
        expect(extensions(root)[0].attrs['data-label']).toBe('demo carrier');
        expect(fieldValue(root, 'api_key')).toBe('saved-key');
    });
});

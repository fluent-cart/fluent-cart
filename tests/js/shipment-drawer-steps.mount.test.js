import {afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, reactive, watch} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * The Create shipment drawer: Method quotes on its own and re-quotes after a
 * quantity change, a manual shipment needs no tracking number, a zone method
 * hides Carrier Name and Shipping Cost, and the request carries notify_customer
 * and leaves "Shipped now" for the server to stamp at Create.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({post: vi.fn()}));
const granted = vi.hoisted(() => ({permissions: null}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
// null grants everything.
vi.mock('@/utils/permission/Permission', () => ({
    default: {resolve: ({permission}) => granted.permissions === null || granted.permissions.includes(permission)},
}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', async () => ({
    _x: text => text,
    default: (await import('./support/reserved-words.js')).withReserved((text, ...args) => args.reduce((out, arg, index) => out.replace('%' + (index + 1) + '$s', arg), text)),
    pluralizeTranslate: (single, plural, count) => (count === 1 ? single : plural).replace('%s', count),
}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => ''}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback}}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({
    default: {formatFixed: (cents) => '$' + (cents / 100).toFixed(2)},
}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({
    fetchDispatchCarriers: () => Promise.resolve([
        {slug: 'manual', name: 'Manual / Other', is_enabled: true, supports_labels: false, supports_rates: true},
        {slug: 'shippo', name: 'Shippo', is_enabled: true, supports_labels: true, supports_rates: true},
    ]),
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
// The real transition animates element styles the fake DOM does not have.
vi.mock('@/Bits/Components/Animation.vue', async () => {
    const {h} = await import('vue');
    return {default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? h('div', slots.default ? slots.default() : []) : null)}};
});

import ShipmentForm from '@/Modules/Orders/Components/ShipmentForm.vue';
import Shipments from '@/Modules/Orders/Components/Shipments.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const stub = {render: () => null};

const DatePickerStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    setup: (props, {emit, expose}) => {
        expose({focus: () => {}});
        return () => h('input', {class: 'fct-test-date', value: props.modelValue, onInput: event => emit('update:modelValue', event.target.value)});
    },
};

// The drawer teleports, which the fake DOM cannot; render its slots in place.
// Renders the button it guards and a confirm button that buys.
const PopconfirmStub = {
    props: ['confirmButtonText'],
    emits: ['confirm'],
    setup: (props, {slots, emit}) => () => h('div', [
        slots.reference && slots.reference(),
        h('button', {class: 'fct-test-confirm', onClick: () => emit('confirm')}, props.confirmButtonText),
    ]),
};

const DrawerStub = {
    props: ['modelValue'],
    emits: ['closed'],
    setup: (props, {slots, emit}) => {
        watch(() => props.modelValue, open => !open && emit('closed'));
        return () => h('div', [
        slots.header ? slots.header({close: () => {}, titleId: 'fct-test-drawer-title'}) : null,
        slots.default ? slots.default() : null,
        slots.footer ? slots.footer() : null,
    ]);
    },
};

const order = {
    id: 7,
    currency: 'USD',
    order_items: [{id: 1, post_title: 'Mug', quantity: 2, fulfilled_quantity: 0, fulfillment_type: 'physical'}],
};

const quote = {quote: {rates: [{rate_object_id: 'flat', service_name: 'Flat rate', cost_cents: 500, currency: 'USD'}], rate_object_id: 'flat'}};

// The host stands in for the order page: it owns the order and takes the drawer's list.
const mountForm = async (listeners = {}, orderOverrides = {}, page = reactive({order: {...order, ...orderOverrides}, open: true})) => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => (page.open ? h(ShipmentForm, {
        order: page.order,
        onUnconfirmed: list => {
            page.order.unconfirmed_shipments = list;
        },
        ...listeners,
    }) : null)});
    renderer.createApp(Host)
        .use(ElementPlus)
        .component('ElDatePicker', DatePickerStub)
        .component('ElInputNumber', stub)
        .component('ElSelect', stub)
        .component('ElOption', stub)
        .component('ElPopconfirm', PopconfirmStub)
        .component('ElDrawer', DrawerStub)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await flush();
    root.page = page;
    return root;
};

const click = async (root, className, label) => {
    byClass(root, className).filter(node => node.textContent.trim() === label)[0].listeners.click(new MouseEvent());
    await flush();
};

const currentStep = root => root.findAll(node => node.attrs['aria-current'] === 'step')[0].textContent.trim();

const quoteCalls = () => rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments/quote');

const formLabels = root => byClass(root, 'el-form-item__label').map(node => node.textContent.trim());

const typeInto = async (input, value) => {
    input.value = value;
    input.listeners.input({target: input});
    await flush();
};

const typeTracking = (root, value) => typeInto(root.findAll(node => node.tagName === 'input' && node.attrs.placeholder === 'Enter tracking number')[0], value);

const trackingUrlInput = root => root.findAll(node => node.tagName === 'input' && node.attrs['aria-describedby'] === 'fct-shipment-tracking-url-hint')[0];

const pickRate = async (root, label) => {
    byClass(root, 'fct-shipment-rate').filter(node => node.textContent.includes(label))[0].listeners.click(new MouseEvent());
    await flush();
};

const patternQuote = {quote: {rates: [
    {rate_object_id: '11', service_name: 'Sa paribahan', cost_cents: 500, currency: 'USD', tracking_url_pattern: 'https://track.example/t/{tracking_number}?src=fc'},
    {rate_object_id: '12', service_name: 'Global', cost_cents: 900, currency: 'USD', tracking_url_pattern: ''},
], rate_object_id: '11'}};

describe('Create shipment drawer', () => {
    beforeAll(() => {
        globalThis.document = {activeElement: null};
    });

    afterAll(() => {
        delete globalThis.document;
    });

    beforeEach(() => {
        granted.permissions = null;
        rest.post.mockReset();
        rest.post.mockImplementation(url => Promise.resolve(url.endsWith('/quote') ? quote : {message: 'Created', shipment: {id: 1}}));
    });

    it('quotes the zone methods when Method opens, without a click', async () => {
        const root = await mountForm();
        expect(currentStep(root)).toBe('1. Items');
        expect(quoteCalls()).toHaveLength(0);

        await click(root, 'el-button', 'Continue');

        expect(currentStep(root)).toBe('2. Method');
        expect(quoteCalls()).toHaveLength(1);
        expect(byClass(root, 'fct-shipment-rate')[0].attrs['aria-checked']).toBe('true');
    });

    it('re-quotes after the quantities change', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');
        await click(root, 'el-button', 'Back');
        await click(root, 'fct-shipment-link', 'Clear all');
        await click(root, 'fct-shipment-link', 'Ship all remaining');
        await click(root, 'el-button', 'Continue');

        expect(quoteCalls()).toHaveLength(2);
    });

    it('continues to Review without a tracking number', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');
        await click(root, 'el-button', 'Continue');

        expect(currentStep(root)).toBe('3. Review');
        expect(byClass(root, 'fct-shipment-review')[0].textContent).toContain('Not set');
    });

    it('still reaches Review when the zone methods fail to load', async () => {
        rest.post.mockImplementation(url => (url.endsWith('/quote') ? Promise.reject({message: 'down'}) : Promise.resolve({message: 'Created'})));
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');
        await click(root, 'el-button', 'Continue');

        expect(currentStep(root)).toBe('3. Review');
    });

    it('hides Carrier Name and Shipping Cost for a zone method', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');

        expect(formLabels(root)).toEqual(['Tracking Number', 'Tracking URL']);
    });

    it('lets a manual shipment take a custom price instead of a zone method', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');

        const rows = byClass(root, 'fct-shipment-rate');
        const custom = rows[rows.length - 1];
        custom.listeners.click(new MouseEvent());
        await flush();

        expect(byClass(root, 'fct-shipment-rate').map(row => row.attrs['aria-checked'])).toEqual(['false', 'true']);
        expect(formLabels(root)).toEqual(['Carrier Name', 'Shipping Cost', 'Tracking Number', 'Tracking URL']);

        // A Refresh must not swap the custom choice back to the paid method.
        await click(root, 'fct-shipment-link', 'Refresh');
        expect(quoteCalls()).toHaveLength(2);
        expect(byClass(root, 'fct-shipment-rate').map(row => row.attrs['aria-checked'])).toEqual(['false', 'true']);

        await click(root, 'el-button', 'Continue');
        await click(root, 'el-button', 'Create Shipment');

        const create = rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments')[0];
        expect(create[1]).toMatchObject({carrier_slug: 'manual', selected_rate_id: '', tracking_number: ''});
    });

    it('creates the shipment with the picked method cost and the notify flag', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');
        await typeTracking(root, 'TRK-9');
        await click(root, 'el-button', 'Continue');
        await click(root, 'el-button', 'Create Shipment');

        const create = rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments')[0];
        expect(create[1]).toMatchObject({
            carrier_slug: 'manual',
            carrier_name: '',
            tracking_number: 'TRK-9',
            cost: '5.00',
            selected_rate_id: 'flat',
            notify_customer: true,
            items: [{order_item_id: 1, qty: 2}],
        });
    });

    describe('Shipped at', () => {
        beforeEach(() => {
            vi.useFakeTimers({toFake: ['Date']});
            vi.setSystemTime(new Date(2026, 9, 28, 17, 10, 4));
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        const createRequest = () => rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments')[0][1];

        it('leaves "Shipped now" empty so the server stamps the Create time', async () => {
            const root = await mountForm();
            vi.setSystemTime(new Date(2026, 9, 28, 17, 11, 41));
            await click(root, 'el-button', 'Continue');
            await click(root, 'el-button', 'Continue');
            await click(root, 'el-button', 'Create Shipment');

            expect(createRequest().shipped_at).toBe('');
        });

        it('sends a picked date as UTC', async () => {
            const root = await mountForm();
            await click(root, 'el-button', 'Continue');
            await click(root, 'el-button', 'Continue');
            vi.setSystemTime(new Date(2026, 9, 28, 17, 11, 0));
            await click(root, 'fct-shipment-link', 'Change');

            const picker = byClass(root, 'fct-test-date')[0];
            expect(picker.value).toBe('2026-10-28 17:11:00');
            await typeInto(picker, '2026-03-01 09:30:00');
            await click(root, 'el-button', 'Create Shipment');

            expect(createRequest().shipped_at).toBe(new Date(2026, 2, 1, 9, 30, 0).toISOString().slice(0, 19).replace('T', ' '));
        });
    });

    describe('Tracking URL from the method pattern', () => {
        beforeEach(() => {
            rest.post.mockImplementation(url => Promise.resolve(url.endsWith('/quote') ? patternQuote : {message: 'Created', shipment: {id: 1}}));
        });

        it('fills the Tracking URL from the picked method and follows the tracking number', async () => {
            const root = await mountForm();
            await click(root, 'el-button', 'Continue');

            // No number yet: the field stays empty and shows the pattern as a hint.
            expect(trackingUrlInput(root).value).toBe('');
            expect(trackingUrlInput(root).attrs.placeholder).toBe('https://track.example/t/{tracking_number}?src=fc');

            await typeTracking(root, 'AB 1');
            expect(trackingUrlInput(root).value).toBe('https://track.example/t/AB%201?src=fc');

            // Encoded like PHP rawurlencode, so the shown URL is the stored one.
            await typeTracking(root, "A&B/(1)!*'");
            expect(trackingUrlInput(root).value).toBe('https://track.example/t/A%26B%2F%281%29%21%2A%27?src=fc');

            await click(root, 'el-button', 'Continue');
            await click(root, 'el-button', 'Create Shipment');
            const create = rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments')[0];
            expect(create[1]).toMatchObject({selected_rate_id: '11', tracking_url: 'https://track.example/t/A%26B%2F%281%29%21%2A%27?src=fc'});
        });

        it('keeps a hand-typed Tracking URL', async () => {
            const root = await mountForm();
            await click(root, 'el-button', 'Continue');
            await typeTracking(root, 'TRK1');
            await typeInto(trackingUrlInput(root), 'https://mine.example/x');

            await typeTracking(root, 'TRK2');
            expect(trackingUrlInput(root).value).toBe('https://mine.example/x');

            await pickRate(root, 'Global');
            expect(trackingUrlInput(root).value).toBe('https://mine.example/x');

            await pickRate(root, 'Sa paribahan');
            expect(trackingUrlInput(root).value).toBe('https://mine.example/x');
        });

        it('re-fills on a method switch and clears a filled value for a method without a pattern', async () => {
            const root = await mountForm();
            await click(root, 'el-button', 'Continue');
            await typeTracking(root, 'TRK1');
            expect(trackingUrlInput(root).value).toBe('https://track.example/t/TRK1?src=fc');

            await pickRate(root, 'Global');
            expect(trackingUrlInput(root).value).toBe('');
            expect(trackingUrlInput(root).attrs.placeholder).toBe('https://example.com/track/...');

            await pickRate(root, 'Sa paribahan');
            expect(trackingUrlInput(root).value).toBe('https://track.example/t/TRK1?src=fc');

            await pickRate(root, 'Custom price');
            expect(trackingUrlInput(root).value).toBe('');
        });
    });

    describe('Unconfirmed label purchase', () => {
        const createButton = root => byClass(root, 'el-button').filter(node => node.textContent.trim() === 'Create Shipment')[0];
        const checkButton = root => byClass(root, 'el-button').filter(node => node.textContent.trim() === 'Check again')[0];
        const resolveButton = root => byClass(root, 'el-button').filter(node => node.textContent.trim() === 'Mark resolved')[0];
        const isDisabled = node => node.attrs.disabled !== undefined && node.attrs.disabled !== false;
        const createCalls = () => rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments');
        const recheckCalls = () => rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments/recheck-unconfirmed');
        const pending = (id, extra = {}) => ({id, carrier: 'manual', carrier_name: 'Manual', at: 1, can_recheck: true, ...extra});
        const unconfirmed = (list = [pending('a1')]) => ({message: 'Timed out', code: 'x_purchase_unconfirmed', purchase_unconfirmed: true, unconfirmed_shipments: list});

        // Each POST other than a quote is answered by `answers[path suffix]`.
        const answer = answers => rest.post.mockImplementation(url => {
            if (url.endsWith('/quote')) {
                return Promise.resolve(quote);
            }
            const key = Object.keys(answers).find(suffix => url.endsWith(suffix));
            return answers[key]();
        });
        const reject = body => () => Promise.reject({data: body, status_code: 422});

        const toReview = async (root) => {
            await click(root, 'el-button', 'Continue');
            await click(root, 'el-button', 'Continue');
            return root;
        };
        const failCreate = async (body, listeners = {}) => {
            answer({'/shipments': reject(body)});
            const root = await toReview(await mountForm(listeners));
            await click(root, 'el-button', 'Create Shipment');
            return root;
        };
        const openSaved = async (list, listeners = {}) => toReview(await mountForm(listeners, {unconfirmed_shipments: list}));
        const closeFromReview = async (root) => {
            await click(root, 'el-button', 'Back');
            await click(root, 'el-button', 'Back');
            await click(root, 'el-button', 'Cancel');
        };

        it('locks Create from the list the server returns with the error', async () => {
            const root = await failCreate(unconfirmed());

            expect(isDisabled(createButton(root))).toBe(true);
            expect(root.textContent).toContain('The carrier did not confirm this label.');
            expect(root.page.order.unconfirmed_shipments).toEqual([pending('a1')]);

            // A disabled el-button stops the event, so a second click never reaches submit().
            createButton(root).listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
            await flush();
            expect(createCalls()).toHaveLength(1);
        });

        it('keeps the note a status, not an alert', async () => {
            const root = await failCreate(unconfirmed());
            const note = root.findAll(node => node.textContent.startsWith('The carrier did not confirm this label.') && node.attrs.role)[0];
            expect(note.attrs.role).toBe('status');
        });

        it('lets a plain error be retried', async () => {
            const root = await failCreate({message: 'Carrier down', code: 'carrier_down', unconfirmed_shipments: []});

            expect(isDisabled(createButton(root))).toBe(false);
            expect(root.textContent).toContain('Carrier down');
        });

        it('posts once for two clicks in the same frame', async () => {
            const root = await toReview(await mountForm());
            createButton(root).listeners.click(new MouseEvent());
            createButton(root).listeners.click(new MouseEvent());
            await flush();

            expect(createCalls()).toHaveLength(1);
        });

        it('shows one note per attempt for the selected carrier only', async () => {
            const root = await openSaved([pending('a1'), pending('a2'), pending('s1', {carrier: 'shippo'})]);

            expect(byClass(root, 'el-button').filter(node => node.textContent.trim() === 'Check again')).toHaveLength(2);
        });

        it('stays open for a carrier with nothing saved', async () => {
            const root = await openSaved([pending('s1', {carrier: 'shippo'})]);

            expect(checkButton(root)).toBeUndefined();
            expect(isDisabled(createButton(root))).toBe(false);
        });

        // The lock lives on the page, so closing the drawer cannot drop it.
        it('stays locked after close and reopen', async () => {
            const root = await failCreate(unconfirmed());
            root.page.open = false;
            await flush();
            root.page.open = true;
            await flush();
            await toReview(root);

            expect(isDisabled(createButton(root))).toBe(true);
            expect(checkButton(root)).toBeDefined();
        });

        it('offers Check again only when the carrier can check, and Mark resolved always', async () => {
            const root = await openSaved([pending('a1', {can_recheck: false})]);

            expect(checkButton(root)).toBeUndefined();
            expect(resolveButton(root)).toBeDefined();
            expect(root.textContent).toContain('then click Mark resolved before buying again.');
        });

        it('hides both actions from a viewer who cannot run their routes', async () => {
            granted.permissions = ['orders/view'];
            const root = await openSaved([pending('a1')]);
            expect(checkButton(root)).toBeUndefined();
            expect(resolveButton(root)).toBeUndefined();

            granted.permissions = ['orders/view', 'orders/manage_statuses'];
            const allowed = await openSaved([pending('a1')]);
            expect(checkButton(allowed)).toBeDefined();
            expect(resolveButton(allowed)).toBeDefined();
        });

        describe('Check again', () => {
            it('records the found label like a normal create', async () => {
                answer({'/recheck-unconfirmed': () => Promise.resolve({message: 'Created', shipment: {id: 9}, order_shipping_status: 'shipped', unconfirmed_shipments: []})});
                const onCreated = vi.fn();
                const root = await openSaved([pending('a1')], {onCreated});

                await click(root, 'el-button', 'Check again');

                expect(recheckCalls()).toEqual([['orders/7/shipments/recheck-unconfirmed', {unconfirmed_id: 'a1'}]]);
                expect(onCreated).toHaveBeenCalledWith({shipment: {id: 9}, order_shipping_status: 'shipped'});
                expect(root.page.order.unconfirmed_shipments).toEqual([]);
            });

            it('stays locked while the carrier has not confirmed', async () => {
                answer({'/recheck-unconfirmed': reject(unconfirmed())});
                const root = await openSaved([pending('a1')]);

                await click(root, 'el-button', 'Check again');

                expect(root.textContent).toContain('The carrier hasn\'t confirmed this label yet. Try again in a minute.');
                expect(isDisabled(createButton(root))).toBe(true);
            });

            it('unlocks with a notice when the server dropped the attempt', async () => {
                answer({'/recheck-unconfirmed': reject({message: 'None', code: 'x_label_not_found', unconfirmed_shipments: []})});
                const root = await openSaved([pending('a1')]);

                await click(root, 'el-button', 'Check again');

                expect(root.textContent).toContain('No label was found at the carrier. You can buy a new one.');
                expect(checkButton(root)).toBeUndefined();
                expect(isDisabled(createButton(root))).toBe(false);
                expect(root.page.order.unconfirmed_shipments).toEqual([]);
            });

            it('stays locked when the server kept a newer attempt', async () => {
                answer({'/recheck-unconfirmed': reject({message: 'None', code: 'x_label_not_found', unconfirmed_shipments: [pending('a2')]})});
                const root = await openSaved([pending('a1')]);

                await click(root, 'el-button', 'Check again');

                expect(isDisabled(createButton(root))).toBe(true);
                expect(root.page.order.unconfirmed_shipments).toEqual([pending('a2')]);
                expect(root.textContent).not.toContain('You can buy a new one.');
                expect(root.textContent).toContain('No label was found for this purchase.');
            });

            it('takes the list for an attempt settled elsewhere and refetches on close', async () => {
                answer({'/recheck-unconfirmed': reject({message: 'Already settled.', code: 'no_unconfirmed_request', unconfirmed_shipments: []})});
                const onClose = vi.fn();
                const root = await openSaved([pending('a1')], {onClose});

                await click(root, 'el-button', 'Check again');
                expect(isDisabled(createButton(root))).toBe(false);
                expect(root.textContent).not.toContain('No label was found');

                await closeFromReview(root);
                expect(onClose).toHaveBeenCalledWith(true);
            });

            it('sends one check at a time and marks the button busy', async () => {
                answer({'/recheck-unconfirmed': () => new Promise(() => {})});
                const root = await openSaved([pending('a1')]);

                checkButton(root).listeners.click(new MouseEvent());
                await flush();
                expect(checkButton(root).attrs['aria-busy']).toBe('true');
                expect(isDisabled(checkButton(root))).toBe(true);
                checkButton(root).listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
                await flush();

                expect(recheckCalls()).toHaveLength(1);
            });
        });

        // The server refuses it while the Buy may still be waiting on the carrier.
        it('offers Mark resolved on a fresh attempt and shows the server refusal', async () => {
            answer({'/dismiss-unconfirmed': reject({message: 'This purchase may still be running.', code: 'unconfirmed_purchase_running', unconfirmed_shipments: [pending('a1')]})});
            const root = await openSaved([pending('a1', {at: Math.floor(Date.now() / 1000) - 30})]);
            expect(resolveButton(root)).toBeDefined();

            await click(root, 'fct-test-confirm', 'Mark resolved');

            expect(root.textContent).toContain('This purchase may still be running.');
            expect(root.page.order.unconfirmed_shipments).toEqual([pending('a1')]);
            expect(isDisabled(createButton(root))).toBe(true);
        });

        it('Mark resolved drops the attempt after the confirm', async () => {
            answer({'/dismiss-unconfirmed': () => Promise.resolve({message: 'Marked resolved.', unconfirmed_shipments: []})});
            const root = await openSaved([pending('a1')]);

            await click(root, 'fct-test-confirm', 'Mark resolved');

            expect(rest.post.mock.calls.filter(call => call[0] === 'orders/7/shipments/dismiss-unconfirmed')).toEqual([['orders/7/shipments/dismiss-unconfirmed', {unconfirmed_id: 'a1'}]]);
            expect(isDisabled(createButton(root))).toBe(false);
        });

        it('drops the Buy error once Mark resolved unlocks the carrier', async () => {
            answer({
                '/shipments': reject(unconfirmed()),
                '/dismiss-unconfirmed': () => Promise.resolve({message: 'Marked resolved.', unconfirmed_shipments: []}),
            });
            const root = await toReview(await mountForm());
            await click(root, 'el-button', 'Create Shipment');
            expect(root.textContent).toContain('Timed out');

            await click(root, 'fct-test-confirm', 'Mark resolved');

            expect(isDisabled(createButton(root))).toBe(false);
            expect(root.textContent).not.toContain('Timed out');
        });

        // Both bind to the page's order like SingleOrder does.
        it('shares one list with the shipments card', async () => {
            answer({'/dismiss-unconfirmed': () => Promise.resolve({message: 'Marked resolved.', unconfirmed_shipments: []})});
            const page = reactive({order: {...order, shipments: [], unconfirmed_shipments: [pending('a1')]}, open: true});
            const setList = list => {
                page.order.unconfirmed_shipments = list;
            };
            const root = new FakeNode('div');
            renderer.createApp(defineComponent({setup: () => () => h('div', [
                h(Shipments, {order: page.order, onUnconfirmed: setList}),
                h(ShipmentForm, {order: page.order, onUnconfirmed: setList}),
            ])}))
                .use(ElementPlus)
                .component('ElDatePicker', DatePickerStub)
                .component('ElInputNumber', stub)
                .component('ElSelect', stub)
                .component('ElOption', stub)
                .component('ElPopconfirm', PopconfirmStub)
                .component('ElDrawer', DrawerStub)
                .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
                .mount(root);
            await flush();
            await toReview(root);
            expect(isDisabled(createButton(root))).toBe(true);

            // The card's Mark resolved is the first one rendered.
            byClass(root, 'fct-test-confirm').filter(node => node.textContent.trim() === 'Mark resolved')[0].listeners.click(new MouseEvent());
            await flush();

            expect(isDisabled(createButton(root))).toBe(false);
            expect(resolveButton(root)).toBeUndefined();
        });

        it('closes without a refetch when nothing changed', async () => {
            const onClose = vi.fn();
            const root = await mountForm({onClose});

            await click(root, 'el-button', 'Cancel');

            expect(onClose).toHaveBeenCalledWith(false);
        });

        it('warns but keeps Manual open when the server dies mid-request, and refetches on close', async () => {
            // A host that kills PHP mid-request leaves an empty 200 body.
            rest.post.mockImplementation(url => Promise.resolve(url.endsWith('/quote') ? quote : null));
            const onClose = vi.fn();
            const root = await toReview(await mountForm({onClose}));
            await click(root, 'el-button', 'Create Shipment');

            expect(root.textContent).not.toContain('Cannot read properties');
            expect(root.textContent).toContain('The server stopped before it confirmed this shipment.');
            expect(isDisabled(createButton(root))).toBe(false);

            await closeFromReview(root);
            expect(onClose).toHaveBeenCalledWith(true);
        });

        describe('with no confirmation from the server', () => {
            const createFails = async (failure, orderOverrides) => {
                rest.post.mockImplementation(url => (url.endsWith('/quote') ? Promise.resolve(quote) : Promise.reject(failure)));
                const root = await toReview(await mountForm({}, orderOverrides));
                await click(root, orderOverrides ? 'fct-test-confirm' : 'el-button', orderOverrides ? 'Buy Label' : 'Create Shipment');
                return root;
            };
            const buyButton = root => byClass(root, 'el-button').filter(node => node.textContent.trim().startsWith('Buy label'))[0];
            const viaShippo = {checkout_shipping: {carrier: 'shippo'}};

            it('locks a label purchase after an empty 200 body', async () => {
                rest.post.mockImplementation(url => Promise.resolve(url.endsWith('/quote') ? quote : null));
                const root = await toReview(await mountForm({}, viaShippo));
                await click(root, 'fct-test-confirm', 'Buy Label');

                expect(root.textContent).toContain('The server stopped before it confirmed this shipment.');
                expect(isDisabled(buyButton(root))).toBe(true);
            });

            it.each([
                ['a 500 with no JSON body', {data: null, status_code: 500}],
                ['a JSON 500 from the fatal handler', {data: {code: 'internal_server_error', message: 'Critical error'}, status_code: 500}],
                ['a network error', {status: 0, statusText: ''}],
            ])('locks a label purchase after %s', async (label, failure) => {
                const root = await createFails(failure, viaShippo);

                expect(createCalls()).toHaveLength(1);
                expect(root.textContent).toContain('The server stopped before it confirmed this shipment.');
                expect(isDisabled(buyButton(root))).toBe(true);
            });

            it('lets a manual shipment retry after a 500', async () => {
                const root = await createFails({data: null, status_code: 500});

                expect(createCalls()).toHaveLength(1);
                expect(root.textContent).toContain('An error occurred while creating the shipment.');
                expect(isDisabled(createButton(root))).toBe(false);
            });
        });
    });
});

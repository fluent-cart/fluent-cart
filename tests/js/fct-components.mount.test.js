import fs from 'node:fs';
import path from 'node:path';
import {describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';
import {fctComponents, registerFctComponents} from '@/Bits/Components/Fct';

/**
 * fct-* components are the names add-on templates use instead of <el-*>. Each one maps its
 * own declared props onto Element Plus and passes nothing else (except class and style), so
 * an add-on cannot start depending on an Element Plus-only prop through them.
 */

// ElDropdown's popper cannot mount in the fake DOM; these stand-ins keep the props fct-dropdown passes.
vi.mock('element-plus', async (importOriginal) => {
    const actual = await importOriginal();
    const {defineComponent, h: hh} = await import('vue');
    const pass = (name) => defineComponent({name, inheritAttrs: false, setup: (props, {slots, attrs}) => () => hh('div', {'data-command': JSON.stringify(attrs.command ?? null)}, [slots.default && slots.default(), slots.dropdown && slots.dropdown()])});
    // Like Element Plus 2.14: the input stops every Escape, open menu or not.
    const ElSelect = defineComponent({name: 'ElSelect', setup: () => () => hh('div', {class: 'el-select'}, [
        hh('input', {role: 'combobox', onKeydown: event => event.key === 'Escape' && event.stopPropagation()}),
    ])});
    return {...actual, ElSelect, ElDropdown: pass('ElDropdown'), ElDropdownMenu: pass('ElDropdownMenu'), ElDropdownItem: pass('ElDropdownItem')};
});

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    // Element Plus checks the click payload with instanceof MouseEvent.
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
    // Element Plus's table watches its own layout.
    globalThis.MutationObserver = globalThis.MutationObserver || class MutationObserver { observe() {} disconnect() {} };
    globalThis.requestAnimationFrame = globalThis.requestAnimationFrame || ((callback) => setTimeout(callback, 0));
    globalThis.cancelAnimationFrame = globalThis.cancelAnimationFrame || ((id) => clearTimeout(id));
});

const ROOT = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

const mount = (component, props, slots) => {
    const root = new FakeNode('div');
    renderer.createApp({render: () => h(component, props, slots)})
        .use(ElementPlus)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    return root;
};

const firstTag = (root, tag) => root.findAll(node => node.tagName === tag)[0];

describe('fct-button', () => {
    it('puts ARIA on the native button, ignores undeclared props and emits click', () => {
        const onClick = vi.fn();
        const root = mount(fctComponents.FctButton, {
            'aria-label': 'Delete row', 'aria-expanded': 'false', 'native-type': 'submit', onClick,
        }, {default: () => 'Delete'});
        const button = firstTag(root, 'button');

        expect(button.attrs['aria-label']).toBe('Delete row');
        expect(button.attrs['aria-expanded']).toBe('false');
        expect(button.attrs.type).toBe('button');
        button.listeners.click(new MouseEvent());
        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('maps size x-small onto core\'s existing x-small button style', () => {
        const root = mount(fctComponents.FctButton, {size: 'x-small'}, {default: () => 'Go'});

        expect(firstTag(root, 'button').className).toContain('el-button--x-small');
    });
});

describe('fct-button with to', () => {
    it('renders a router link with the route\'s href, so it is a real, focusable link', async () => {
        const {createRouter, createMemoryHistory} = await import('vue-router');
        const router = createRouter({history: createMemoryHistory(), routes: [{path: '/', component: {render: () => null}}, {path: '/logs', name: 'logs', component: {render: () => null}}]});
        await router.push('/');
        const root = new FakeNode('div');
        renderer.createApp({render: () => h(fctComponents.FctButton, {to: {name: 'logs'}}, {default: () => 'View'})})
            .use(ElementPlus).use(router).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
        await nextTick();

        expect(firstTag(root, 'a').attrs.href).toBe('/logs');
    });
});

describe('fct-input', () => {
    it('puts id, autocomplete and ARIA on the native input', async () => {
        const root = mount(fctComponents.FctInput, {
            modelValue: 'x', id: 'card-name', autocomplete: 'cc-name', 'aria-describedby': 'card-help',
        });
        await nextTick();
        const input = firstTag(root, 'input');

        expect(input.attrs.id).toBe('card-name');
        expect(input.attrs.autocomplete).toBe('cc-name');
        expect(input.attrs['aria-describedby']).toBe('card-help');
    });
});

describe('fct-* root element', () => {
    it('makes an input without a label its own root element, so $el and <Transition> work', async () => {
        let instance = null;
        const root = mount({render: () => h(fctComponents.FctInput, {modelValue: 'x', ref: (component) => { instance = component; }})});
        await nextTick();

        expect(instance.$el.tagName).toBe('div');
        expect(String(instance.$el.className)).toContain('el-input');
    });
});

describe('fct-* field props', () => {
    const byTag = (root, tag) => root.findAll(node => node.tagName === tag);
    const classed = (root, name) => root.findAll(node => String(node.className).split(/\s+/).includes(name));

    it('renders the input alone when no label, help or errors are passed', async () => {
        const root = mount(fctComponents.FctInput, {modelValue: 'x'});
        await nextTick();

        expect(classed(root, 'fct-form-field')).toHaveLength(0);
        expect(byTag(root, 'label')).toHaveLength(0);
    });

    it('points the label at the input, and shows help text and the field error', async () => {
        const root = mount(fctComponents.FctInput, {
            modelValue: 'x', label: 'Coupon code', tooltip: 'Typed at checkout.', required: true, help: 'Letters only.',
            validationErrors: {code: {required: 'The code is required.'}}, fieldKey: 'code',
        });
        await nextTick();
        const label = byTag(root, 'label')[0];

        expect(label.attrs.for).toBeTruthy();
        expect(byTag(root, 'input')[0].attrs.id).toBe(label.attrs.for);
        expect(classed(root, 'is-required')).toHaveLength(1);
        expect(classed(root, 'form-note')[0].textContent).toBe('Letters only.');
        expect(classed(root, 'fluent-cart-admin-validation-error')[0].textContent).toBe('The code is required.');
    });

    it('links a core Field label to the first fct input inside it, like a form item did', async () => {
        const Field = (await import('@/Bits/Components/Fct/Field.vue')).default;
        const root = mount(Field, {label: 'Discount value'}, {
            default: () => [h(fctComponents.FctInput, {modelValue: '1'}), h(fctComponents.FctInput, {modelValue: '2'})],
        });
        await nextTick();
        const inputs = byTag(root, 'input');

        expect(byTag(root, 'label')[0].attrs.for).toBeTruthy();
        expect(inputs[0].attrs.id).toBe(byTag(root, 'label')[0].attrs.for);
        expect(inputs[1].attrs.id).not.toBe(inputs[0].attrs.id);
    });

    it('keeps an id the add-on passed', async () => {
        const root = mount(fctComponents.FctInput, {modelValue: 'x', label: 'Name', id: 'card-name'});
        await nextTick();

        expect(byTag(root, 'label')[0].attrs.for).toBe('card-name');
        expect(byTag(root, 'input')[0].attrs.id).toBe('card-name');
    });
});

describe('fct-choice', () => {
    const inputs = (root, type) => root.findAll(node => node.tagName === 'input' && node.attrs.type === type);
    const options = [{label: 'Order', value: 'order'}, {label: 'Shipping', value: 'shipping'}];

    it('is a single checkbox without options', async () => {
        const root = mount(fctComponents.FctChoice, {modelValue: 'yes', trueValue: 'yes', falseValue: 'no'}, {default: () => 'Stackable'});
        await nextTick();

        expect(inputs(root, 'checkbox')).toHaveLength(1);
        expect(root.textContent).toContain('Stackable');
    });

    it('draws no empty label when a single checkbox has no text', async () => {
        const root = mount(fctComponents.FctChoice, {modelValue: false});
        await nextTick();

        expect(root.findAll(node => String(node.className).split(/\s+/).includes('el-checkbox__label'))).toHaveLength(0);
    });

    it('forwards a click on a grouped option, with the option', async () => {
        const onClick = vi.fn();
        const root = mount(fctComponents.FctChoice, {type: 'radio', options, modelValue: 'order', onClick});
        await nextTick();
        const shipping = root.findAll(node => String(node.className).split(/\s+/).includes('el-radio'))[1];

        shipping.listeners.click(new MouseEvent());
        expect(onClick).toHaveBeenCalledTimes(1);
        expect(onClick.mock.calls[0][1]).toEqual(options[1]);
    });

    it.each([
        ['radio', 'radio', 'order'],
        ['checkbox', 'checkbox', ['order']],
    ])('is a %s group with options', async (type, inputType, modelValue) => {
        const root = mount(fctComponents.FctChoice, {type, options, modelValue});
        await nextTick();

        expect(inputs(root, inputType)).toHaveLength(2);
        expect(root.textContent).toContain('Shipping');
    });
});

describe('fct-input type="number"', () => {
    it('is a number stepper; other types are a text input', async () => {
        const classed = (root, name) => root.findAll(node => String(node.className).split(/\s+/).includes(name));

        expect(classed(mount(fctComponents.FctInput, {type: 'number', modelValue: 2}), 'el-input-number')).toHaveLength(1);
        expect(classed(mount(fctComponents.FctInput, {modelValue: 'x'}), 'el-input-number')).toHaveLength(0);
        // No buttons: a plain native box, unless precision asks for the rounding number box.
        expect(classed(mount(fctComponents.FctInput, {type: 'number', controls: false, modelValue: '2'}), 'el-input-number')).toHaveLength(0);
        expect(classed(mount(fctComponents.FctInput, {type: 'number', controls: false, precision: 2, modelValue: 2}), 'el-input-number')).toHaveLength(1);
    });
});

describe('fct-dropdown', () => {
    it('sends an item\'s command, and nothing for an item that has none', async () => {
        const onCommand = vi.fn();
        let dropdownProps = null;
        const root = mount({render: () => h(fctComponents.FctDropdown, {
            items: [{command: 'export', label: 'Export'}, {label: 'Opens a dialog'}],
            onCommand,
            onVnodeMounted: (vnode) => { dropdownProps = vnode.component.subTree.props; },
        }, {default: () => h('button', 'Menu')})});
        await nextTick();
        const sent = root.findAll(node => node.attrs && node.attrs['data-command'] !== undefined && node.attrs['data-command'] !== 'null')
            .map(node => JSON.parse(node.attrs['data-command']));

        // Element Plus calls onCommand with the clicked item's command prop.
        sent.forEach((command) => dropdownProps.onCommand(command));
        // An item without a command gets Element Plus's default {} when it has no command of its own.
        dropdownProps.onCommand({});

        expect(onCommand.mock.calls).toEqual([['export']]);
    });
});

describe('fct-select', () => {
    // A dialog around the select listens for Escape on document.
    const pressEscape = async (expanded) => {
        const root = mount(fctComponents.FctSelect, {modelValue: 'a', options: [{label: 'A', value: 'a'}]});
        await nextTick();
        const input = firstTag(root, 'input');
        const outer = root.findAll(node => node.listeners.keydowncapture)[0];
        const stopped = vi.fn();
        const event = {key: 'Escape', target: {getAttribute: () => String(expanded)}, stopPropagation: stopped};
        // Browser order: capture on the wrapper, then the input's own handler.
        if (outer) {
            outer.listeners.keydowncapture(event);
        }
        input.listeners.keydown(event);
        return stopped;
    };

    it('lets Escape reach the dialog when the menu is closed', async () => {
        expect(await pressEscape(false)).not.toHaveBeenCalled();
    });

    it('keeps Escape on the select while its menu is open', async () => {
        expect(await pressEscape(true)).toHaveBeenCalled();
    });
});

describe('fct-date-picker', () => {

    it.each([
        ['daterange', 'el-range-editor--x-small'],
        ['date', 'el-input--x-small'],
    ])('maps size x-small onto core\'s x-small style for type %s', async (type, className) => {
        const root = mount(fctComponents.FctDatePicker, {type, size: 'x-small', modelValue: type === 'date' ? null : []});
        await nextTick();

        expect(root.findAll(node => String(node.className).split(/\s+/).includes(className))).toHaveLength(1);
    });
});

describe('fct-table-column', () => {
    it('hands the cell slot the row and its index, nothing else', async () => {
        const seen = [];
        const rows = [];
        const root = mount(fctComponents.FctTable, {data: [{name: 'Alpha'}]}, {
            default: () => h(fctComponents.FctTableColumn, {label: 'Name'}, {
                default: (scope) => { seen.push(Object.keys(scope)); rows.push(scope.row); return h('b', scope.row.name); },
            }),
        });
        await nextTick();
        await nextTick();

        expect(seen.length).toBeGreaterThan(0);
        expect(seen[0]).toEqual(['row', 'index']);
        // Element Plus probes the slot with an empty row; that call must not reach the add-on's cell.
        expect(rows.every((row) => row.name === 'Alpha')).toBe(true);
        expect(root.findAll(node => node.tagName === 'b').map(node => node.textContent)).toContain('Alpha');
    });
});

describe('fct-* registration', () => {
    it('registers every published name; names may be added, never removed', () => {
        const names = [];
        registerFctComponents({component: (name) => names.push(name)});

        // Shipped add-ons write these tags. Removing one needs FLUENTCART_MODULE_API bumped.
        const published = [
            'FctButton', 'FctChoice', 'FctDatePicker', 'FctDialog', 'FctDropdown', 'FctInput', 'FctPopover',
            'FctProgress', 'FctSelect', 'FctSkeleton', 'FctSwitch', 'FctTable', 'FctTableColumn', 'FctTooltip',
        ];

        expect(published.filter((name) => !names.includes(name))).toEqual([]);
    });

    it('registers them on the admin app and the customer portal app', () => {
        expect(read('resources/admin/bootstrap/app.js')).toMatch(/registerFctComponents\(app\);/);
        expect(read('resources/public/customer-profile/Start.js')).toMatch(/registerFctComponents\(app\);/);
    });

    it('ships them as module API 3', () => {
        const api = Number(read('fluent-cart.php').match(/define\('FLUENTCART_MODULE_API',\s*(\d+)\)/)[1]);

        expect(api).toBeGreaterThanOrEqual(3);
    });
});

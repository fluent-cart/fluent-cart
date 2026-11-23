import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

const attributes = (extra = {}) => ({
    paginator: 'scroll', per_page: 10, view_mode: 'grid', price_format: 'starts_from',
    product_box_grid_size: 0, search_grid_size: 0, product_grid_size: 0, enable_filter: false,
    filters: {}, default_filters: {enabled: false, allow_out_of_stock: false, wildcard: ''},
    ...extra,
});

describe('Products block sidebar', () => {
    afterEach(() => {
        vi.resetModules();
    });

    // Renders the inspector with the shared controls stood in for, so each one's props can be read.
    const render = async (attributeOverrides = {}, props = {}) => {
        vi.resetModules();
        const seen = {toggles: {}, steppers: {}, ranges: {}, select: null, switches: {}, texts: {}, pickers: {}};
        vi.doMock('@/BlockEditor/Components/SegmentedToggle', () => ({
            default: (toggleProps) => {
                seen.toggles[toggleProps.label] = toggleProps;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Components/RangeField', () => ({
            default: (rangeProps) => {
                seen.ranges[rangeProps.label] = rangeProps;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Components/NumberStepper', () => ({
            default: (stepperProps) => {
                seen.steppers[stepperProps.label] = stepperProps;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/Components/BlockEditorControl', () => ({
            default: ({title, children}) => React.createElement('section', {'data-panel': title}, children),
        }));
        vi.doMock('@/BlockEditor/Components/CustomSelect', () => ({
            default: (pickerProps) => {
                seen.pickers[pickerProps.options?.[0]?.label ?? 'none'] = pickerProps;
                return null;
            },
        }));
        vi.doMock('@/BlockEditor/ShopApp/Settings/FilterSettings', () => ({default: () => null}));

        globalThis.React = React;
        globalThis.window = {fluent_cart_block_translation: {}, fluent_cart_shop_app_block_editor_data: {taxonomies: {
            'product-categories': {label: 'Product Categories', terms: [{value: 1, label: 'Shirts'}]},
            'product-brands': {label: 'Product Brands', terms: [{value: 2, label: 'Acme'}]},
        }}};
        globalThis.wp = {
            element: React,
            blockEditor: {InspectorControls: passThrough},
            components: {
                TextControl: (textProps) => {
                    seen.texts[textProps.label] = textProps;
                    return null;
                }, Card: passThrough, CardHeader: passThrough, CardBody: passThrough,
                ToggleControl: (switchProps) => {
                    seen.switches[switchProps.label] = switchProps;
                    return null;
                }, Button: () => null, Tooltip: passThrough,
                SelectControl: (selectProps) => {
                    seen.select = selectProps;
                    return null;
                },
            },
        };

        const {default: InspectorSettings} = await import('@/BlockEditor/ShopApp/Settings/InspectorSettings.jsx');
        const setAttributes = vi.fn();
        const markup = renderToStaticMarkup(React.createElement(InspectorSettings, {
            attributes: attributes(attributeOverrides), setAttributes, blockEditorData: {}, ...props,
        }));

        return {seen, setAttributes, markup};
    };

    it('has the Paginator and Product Grid Option panels first', async () => {
        const {markup} = await render();

        expect(markup.indexOf('data-panel="Paginator"')).toBeGreaterThan(-1);
        expect(markup.indexOf('data-panel="Paginator"')).toBeLessThan(markup.indexOf('data-panel="Product Grid Option"'));
    });

    it('paginator View is a Scroll or Numbers toggle that saves the choice', async () => {
        const {seen, setAttributes} = await render({paginator: 'numbers'});
        const toggle = seen.toggles['View'];

        expect(toggle.options.map((option) => [option.value, option.label])).toEqual([['scroll', 'Scroll'], ['numbers', 'Numbers']]);
        expect(toggle.value).toBe('numbers');
        toggle.onChange('scroll');
        expect(setAttributes).toHaveBeenCalledWith({paginator: 'scroll'});
    });

    it('per page is a 1 to 50 stepper that saves the number, even if it was saved as text', async () => {
        const {seen, setAttributes} = await render({per_page: '10'});
        const stepper = seen.steppers['Per Page/Scroll'];

        expect([stepper.value, stepper.min, stepper.max]).toEqual([10, 1, 50]);
        stepper.onChange(24);
        expect(setAttributes).toHaveBeenCalledWith({per_page: 24});
    });

    it('view mode is a Grid or List toggle that saves the choice', async () => {
        const {seen, setAttributes} = await render({view_mode: 'list'});
        const toggle = seen.toggles['View Mode'];

        expect(toggle.options.map((option) => option.value)).toEqual(['grid', 'list']);
        expect(toggle.value).toBe('list');
        toggle.onChange('grid');
        expect(setAttributes).toHaveBeenCalledWith({view_mode: 'grid'});
    });

    it('hides view mode when the block is grid only', async () => {
        const {seen} = await render({}, {hideViewMode: true});

        expect(seen.toggles['View Mode']).toBeUndefined();
    });

    it('price format is a select with Starts from and Range that saves the choice', async () => {
        const {seen, setAttributes} = await render({price_format: 'range'});

        expect(seen.select.value).toBe('range');
        expect(seen.select.options).toEqual([
            {label: 'Starts from', value: 'starts_from'},
            {label: 'Range', value: 'range'},
        ]);
        seen.select.onChange('starts_from');
        expect(setAttributes).toHaveBeenCalledWith({price_format: 'starts_from'});
    });

    it('products per row offers Default then 1 to 6, and saves a number', async () => {
        const {seen, setAttributes} = await render({product_box_grid_size: 4});
        const toggle = seen.toggles['Product Per Row'];

        expect(toggle.options.map((option) => [option.value, option.label])).toEqual([[0, 'Default'], [1, '1'], [2, '2'], [3, '3'], [4, '4'], [5, '5'], [6, '6']]);
        expect(toggle.value).toBe(4);
        toggle.onChange(2);
        toggle.onChange(0);
        expect(setAttributes.mock.calls).toEqual([[{product_box_grid_size: 2}], [{product_box_grid_size: 0}]]);
    });

    it('an unset or unreadable grid size selects Default', async () => {
        const unset = await render({product_box_grid_size: undefined});
        const text = await render({product_box_grid_size: 'wide'});

        expect(unset.seen.toggles['Product Per Row'].value).toBe(0);
        expect(text.seen.toggles['Product Per Row'].value).toBe(0);
    });

    it('a grid size saved as text still selects the matching number', async () => {
        const {seen} = await render({product_box_grid_size: '3'});

        expect(seen.toggles['Product Per Row'].value).toBe(3);
    });

    it('a grid size of 6, which the server accepts, selects its own option', async () => {
        const {seen} = await render({product_box_grid_size: 6});

        expect(seen.toggles['Product Per Row'].value).toBe(6);
    });

    it('keeps the layout-cards note with the products per row toggle, so it is read with it', async () => {
        const {seen} = await render();

        expect(seen.toggles['Product Per Row'].help).toBe('Layout cards show fewer per row where each would be under 220px wide.');
        expect(seen.toggles['Search Grid Size']?.help).toBeUndefined();
    });

    it('shows the search and product grid sizes only while filtering is on', async () => {
        const off = await render({enable_filter: false});
        const on = await render({enable_filter: true, search_grid_size: 2, product_grid_size: 3});

        expect(off.seen.ranges['Search Grid Size']).toBeUndefined();
        expect(off.seen.ranges['Product Grid Size']).toBeUndefined();
        expect(on.seen.ranges['Search Grid Size'].value).toBe(2);
        expect(on.seen.ranges['Product Grid Size'].value).toBe(3);
    });

    it('the search and product grid sizes are 0 to 6 sliders that read Default at 0', async () => {
        const {seen} = await render({enable_filter: true});

        ['Search Grid Size', 'Product Grid Size'].forEach((label) => {
            expect([seen.ranges[label].min, seen.ranges[label].max, seen.ranges[label].zeroLabel]).toEqual([0, 6, 'Default']);
        });
    });

    it('an unset grid size on a slider counts as Default', async () => {
        const {seen} = await render({enable_filter: true, search_grid_size: undefined, product_grid_size: 'wide'});

        expect(seen.ranges['Search Grid Size'].value).toBe(0);
        expect(seen.ranges['Product Grid Size'].value).toBe(0);
    });

    it('each slider writes its own attribute', async () => {
        const {seen, setAttributes} = await render({enable_filter: true});

        seen.ranges['Search Grid Size'].onChange(1);
        seen.ranges['Product Grid Size'].onChange(4);

        expect(setAttributes.mock.calls).toEqual([[{search_grid_size: 1}], [{product_grid_size: 4}]]);
    });

    describe('Default Filter panel', () => {
        const saved = () => ({enabled: true, allow_out_of_stock: false, wildcard: 'tee', 'product-categories': [1]});

        it('shows only Enable Filtering until it is on', async () => {
            const off = await render({default_filters: {enabled: false}});
            const on = await render({default_filters: saved()});

            expect(Object.keys(off.seen.switches)).toEqual(['Enable Filtering']);
            expect(off.seen.texts['Search']).toBeUndefined();
            expect(Object.keys(on.seen.switches)).toEqual(['Enable Filtering', 'Allow Out Of Stock']);
            expect(on.seen.texts['Search'].value).toBe('tee');
        });

        it('lists one picker per product taxonomy, with the saved choice', async () => {
            const {seen} = await render({default_filters: saved()});

            expect(seen.pickers['Shirts'].defaultValue).toEqual([1]);
            expect(seen.pickers['Acme'].options).toEqual([{value: 2, label: 'Acme'}]);
        });

        it('each control saves its own key and keeps the others', async () => {
            const {seen, setAttributes} = await render({default_filters: saved()});

            seen.switches['Allow Out Of Stock'].onChange(true);
            seen.texts['Search'].onChange('hat');
            seen.pickers['Acme'].onChange([2]);
            seen.switches['Enable Filtering'].onChange(false);

            expect(setAttributes.mock.calls.map((call) => call[0].default_filters)).toEqual([
                {...saved(), allow_out_of_stock: true},
                {...saved(), wildcard: 'hat'},
                {...saved(), 'product-brands': [2]},
                {...saved(), enabled: false},
            ]);
        });

        it('saves a copy and leaves the saved object as it was', async () => {
            const original = saved();
            const {seen} = await render({default_filters: original});

            seen.texts['Search'].onChange('hat');
            seen.switches['Allow Out Of Stock'].onChange(true);

            expect(original).toEqual(saved());
        });
    });
});

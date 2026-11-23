import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';

const passThrough = ({children}) => React.createElement('div', null, children);

const findAll = (node, predicate, found = []) => {
    if (!node || typeof node !== 'object') {
        return found;
    }
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, predicate, found));
        return found;
    }
    if (predicate(node)) {
        found.push(node);
    }
    return findAll(node.props?.children, predicate, found);
};

// A label is a string or a small element around one; the words are what the merchant reads.
const textOf = (node) => (typeof node === 'string' ? node : React.Children.toArray(node?.props?.children).map(textOf).join(''));

const DEFAULT_OPTIONS = {
    enabled: true,
    'product-categories': {enabled: false, filter_type: 'options', is_meta: false, multiple: true, label: 'Product Categories'},
    'product-brands': {enabled: false, filter_type: 'options', is_meta: false, multiple: true, label: 'Product Brands'},
    price_range: {enabled: false, filter_type: 'range', is_meta: false, multiple: false, label: 'Price'},
};

const attributes = (extra = {}) => ({
    enable_filter: true, enable_wildcard_filter: false, enable_wildcard_for_post_content: false, live_filter: false,
    filters: {}, default_filter_options: DEFAULT_OPTIONS, ...extra,
});

describe('Filter Option panel', () => {
    afterEach(() => {
        vi.resetModules();
    });

    // The component holds no state, so it is called as a function and its element tree walked.
    const panel = async (attributeOverrides = {}, blockEditorData = {}) => {
        vi.resetModules();
        const ToggleControl = function ToggleControlStandIn() { return null; };
        const TextControl = function TextControlStandIn() { return null; };
        vi.doMock('@/BlockEditor/Components/BlockEditorControl', () => ({default: ({children}) => React.createElement('section', null, children)}));
        vi.doMock('@/BlockEditor/Components/CustomSelect', () => ({default: function CustomSelectStandIn() { return null; }}));
        globalThis.React = React;
        globalThis.window = {fluent_cart_block_translation: {}};
        globalThis.wp = {components: {ToggleControl, TextControl}};

        const {default: FilterSettings} = await import('@/BlockEditor/ShopApp/Settings/FilterSettings.jsx');
        const CustomSelect = (await import('@/BlockEditor/Components/CustomSelect')).default;
        const setAttributes = vi.fn();
        const tree = FilterSettings({attributes: attributes(attributeOverrides), setAttributes, blockEditorData});
        const toggles = findAll(tree, (el) => el.type === ToggleControl);
        const byLabel = (label) => toggles.find((toggle) => textOf(toggle.props.label) === label);

        return {
            tree, setAttributes, toggles, byLabel,
            labels: toggles.map((toggle) => textOf(toggle.props.label)),
            fields: findAll(tree, (el) => el.type === TextControl),
            selects: findAll(tree, (el) => el.type === CustomSelect),
        };
    };

    it('shows only Enable Filter while filtering is off', async () => {
        const {labels, fields} = await panel({enable_filter: false});

        expect(labels).toEqual(['Enable Filter']);
        expect(fields).toHaveLength(0);
    });

    it('Enable Filter saves its state', async () => {
        const {byLabel, setAttributes} = await panel({enable_filter: true});

        expect(byLabel('Enable Filter').props.checked).toBe(true);
        byLabel('Enable Filter').props.onChange(false);
        expect(setAttributes).toHaveBeenCalledWith({enable_filter: false});
    });

    it('with filtering on it lists the general switches and one switch per filter', async () => {
        const {labels} = await panel();

        expect(labels).toEqual(['Enable Filter', 'Wildcard Filter', 'Live Filter', 'product categories', 'product brands', 'price range']);
    });

    it('Wildcard and Live Filter save their own attributes', async () => {
        const {byLabel, setAttributes} = await panel();

        byLabel('Wildcard Filter').props.onChange(true);
        byLabel('Live Filter').props.onChange(true);

        expect(setAttributes.mock.calls).toEqual([[{enable_wildcard_filter: true}], [{live_filter: true}]]);
    });

    it('offers Also search in Content only while the wildcard filter is on', async () => {
        const off = await panel({enable_wildcard_filter: false});
        const on = await panel({enable_wildcard_filter: true, enable_wildcard_for_post_content: true});

        expect(off.byLabel('Also search in Content')).toBeUndefined();
        expect(on.byLabel('Also search in Content').props.checked).toBe(true);
        on.byLabel('Also search in Content').props.onChange(false);
        expect(on.setAttributes).toHaveBeenCalledWith({enable_wildcard_for_post_content: false});
    });

    it('a filter switch is on only when that filter is saved as enabled', async () => {
        const {byLabel} = await panel({filters: {'product-brands': {enabled: true, label: 'Brands'}}});

        expect(byLabel('product categories').props.checked).toBe(false);
        expect(byLabel('product brands').props.checked).toBe(true);
    });

    it('turning a filter on saves its type, meta flag and multiple flag with the new state', async () => {
        const {byLabel, setAttributes} = await panel();

        byLabel('price range').props.onChange(true);

        expect(setAttributes).toHaveBeenCalledWith({filters: {
            price_range: {enabled: true, filter_type: 'range', is_meta: false, multiple: false, label: 'Price'},
        }});
    });

    it('turning a filter off keeps its saved name and other settings', async () => {
        const saved = {enabled: true, filter_type: 'options', is_meta: false, multiple: true, label: 'Our brands', show_empty: false};
        const {byLabel, setAttributes} = await panel({filters: {'product-brands': saved}});

        byLabel('product brands').props.onChange(false);

        expect(setAttributes.mock.calls[0][0].filters['product-brands']).toEqual({...saved, enabled: false});
    });

    it('fills in the type, meta and multiple flags from the block default when a saved filter lacks them', async () => {
        const {byLabel, setAttributes} = await panel({filters: {'product-brands': {enabled: true, label: 'Brands'}}});

        byLabel('product brands').props.onChange(false);

        expect(setAttributes.mock.calls[0][0].filters['product-brands']).toEqual({
            enabled: false, label: 'Brands', filter_type: 'options', is_meta: false, multiple: true,
        });
    });

    it('does not change the saved filter object when it saves', async () => {
        const saved = {enabled: true, filter_type: 'options', is_meta: false, multiple: true, label: 'Brands'};
        const {byLabel, fields} = await panel({filters: {'product-brands': saved}});

        byLabel('product brands').props.onChange(false);
        fields[0].props.onChange('Makers');

        expect(saved).toEqual({enabled: true, filter_type: 'options', is_meta: false, multiple: true, label: 'Brands'});
    });

    it('shows a Display Name field only for a filter that is on', async () => {
        const off = await panel();
        const on = await panel({filters: {'product-brands': {enabled: true, filter_type: 'options', label: 'Brands'}}});

        expect(off.fields).toHaveLength(0);
        expect(on.fields).toHaveLength(1);
        expect(on.fields[0].props.label).toBe('Display Name');
        expect(on.fields[0].props.value).toBe('Brands');
    });

    it('saves the Display Name with the rest of that filter', async () => {
        const saved = {enabled: true, filter_type: 'options', is_meta: false, multiple: true, label: 'Brands'};
        const {fields, setAttributes} = await panel({filters: {'product-brands': saved}});

        fields[0].props.onChange('Makers');

        expect(setAttributes).toHaveBeenCalledWith({filters: {'product-brands': {...saved, label: 'Makers'}}});
    });

    it('shows Show empty only for an option filter that is on, defaulting to on', async () => {
        const brands = {enabled: true, filter_type: 'options', label: 'Brands'};
        const price = {enabled: true, filter_type: 'range', label: 'Price'};
        const {labels, byLabel} = await panel({filters: {'product-brands': brands, price_range: price}});

        // one Show empty: the brands option filter; the price range has none
        expect(labels.filter((label) => label === 'Show empty')).toHaveLength(1);
        expect(byLabel('Show empty').props.checked).toBe(true);
        expect(byLabel('Show empty').props.help).toBe('Display brands even if they have no products.');
    });

    it('Show empty saves into the filter', async () => {
        const brands = {enabled: true, filter_type: 'options', label: 'Brands'};
        const {byLabel, setAttributes} = await panel({filters: {'product-brands': brands}});

        byLabel('Show empty').props.onChange(false);

        expect(setAttributes).toHaveBeenCalledWith({filters: {'product-brands': {...brands, show_empty: false}}});
    });

    describe('attribute filters', () => {
        const groups = [{id: 1, title: 'Capacity'}, {id: 2, title: 'Paper Size'}];

        it('appear only when the store has attribute groups', async () => {
            const none = await panel({}, {attribute_groups: []});
            const some = await panel({}, {attribute_groups: groups});

            expect(none.byLabel('Attribute Filters')).toBeUndefined();
            expect(some.byLabel('Attribute Filters').props.checked).toBe(false);
        });

        it('show the attribute picker and the count switches only while they are on', async () => {
            const off = await panel({}, {attribute_groups: groups});
            const on = await panel({filters: {attribute_filters: {filter_type: 'attribute_settings', active: true}}}, {attribute_groups: groups});

            expect(off.selects).toHaveLength(0);
            expect(off.byLabel('Show counts')).toBeUndefined();
            expect(on.selects).toHaveLength(1);
            expect(on.byLabel('Show counts').props.checked).toBe(false);
            expect(on.byLabel('Show empty').props.help).toBe('Display attribute values even if no product uses them.');
        });

        it('turning them on saves the settings entry', async () => {
            const {byLabel, setAttributes} = await panel({}, {attribute_groups: groups});

            byLabel('Attribute Filters').props.onChange(true);

            expect(setAttributes).toHaveBeenCalledWith({filters: {
                attribute_filters: {filter_type: 'attribute_settings', active: true, show_count: false, show_empty: false},
            }});
        });

        it('choosing groups saves one filter per group, and Show counts applies to each', async () => {
            const saved = {attribute_filters: {filter_type: 'attribute_settings', active: true, show_count: false, show_empty: false}};
            const {selects, byLabel, setAttributes} = await panel({filters: saved}, {attribute_groups: groups});

            selects[0].props.onChange([1, 2]);
            byLabel('Show counts').props.onChange(true);

            const chosen = setAttributes.mock.calls[0][0].filters;
            expect(chosen.attr_1).toEqual({filter_type: 'attribute', is_meta: false, group_id: 1, show_count: false, show_empty: false, enabled: true});
            expect(chosen.attr_2.group_id).toBe(2);
            expect(setAttributes.mock.calls[1][0].filters.attribute_filters.show_count).toBe(true);
        });

        it('keeps only as many chosen groups as the store allows', async () => {
            const saved = {attribute_filters: {filter_type: 'attribute_settings', active: true}};
            const {selects, setAttributes} = await panel({filters: saved}, {attribute_groups: groups, attribute_group_limit: 1});

            selects[0].props.onChange([1, 2]);

            const chosen = setAttributes.mock.calls[0][0].filters;
            expect(chosen.attr_1).toBeDefined();
            expect(chosen.attr_2).toBeUndefined();
        });

        it('at the group limit only the chosen groups are offered, with the limit note', async () => {
            const saved = {
                attribute_filters: {filter_type: 'attribute_settings', active: true},
                attr_1: {filter_type: 'attribute', group_id: 1, enabled: true},
            };
            const {selects, tree} = await panel({filters: saved}, {attribute_groups: groups, attribute_group_limit: 1, attribute_group_limit_note: 'Only 1 attribute group can be shown.'});

            expect(selects[0].props.options).toEqual([{value: 1, label: 'Capacity'}]);
            const [note] = findAll(tree, (el) => el.props?.role === 'status');
            expect(note.props.children).toBe('Only 1 attribute group can be shown.');
        });
    });
});

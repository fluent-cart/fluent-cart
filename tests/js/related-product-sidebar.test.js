import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Walks a React element tree and returns every element the predicate accepts.
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

describe('lastRowHint', () => {
    const load = async () => {
        const {lastRowHint} = await import('@/BlockEditor/RelatedProduct/lastRowHint.js');
        return lastRowHint;
    };

    it('says how many sit in a short last row and how many to show to fill it', async () => {
        expect((await load())(6, 4, 12)).toEqual({lastRow: 2, columns: 4, fillTo: 8});
        expect((await load())(7, 3, 12)).toEqual({lastRow: 1, columns: 3, fillTo: 9});
    });

    it('stays quiet when the rows are full', async () => {
        const lastRowHint = await load();

        expect(lastRowHint(8, 4, 12)).toBeNull();
        expect(lastRowHint(12, 6, 12)).toBeNull();
    });

    it('stays quiet for a single row, which is not a short last row', async () => {
        const lastRowHint = await load();

        expect(lastRowHint(3, 4, 12)).toBeNull();
        expect(lastRowHint(4, 4, 12)).toBeNull();
    });

    it('offers no fill count that would pass the maximum', async () => {
        expect((await load())(11, 5, 12)).toEqual({lastRow: 1, columns: 5, fillTo: null});
    });

    it('reads numbers that arrive as text, and ignores missing values', async () => {
        const lastRowHint = await load();

        expect(lastRowHint('6', '4', 12)).toEqual({lastRow: 2, columns: 4, fillTo: 8});
        expect(lastRowHint(undefined, 4, 12)).toBeNull();
        expect(lastRowHint(6, 0, 12)).toBeNull();
    });
});

describe('NumberStepper', () => {
    afterEach(() => {
        delete globalThis.wp;
    });

    // Calls the component with fixed state so its element tree can be walked; setDraft records typing.
    const stepper = async (props, typedDraft = null) => {
        vi.resetModules();
        globalThis.React = React;
        globalThis.window = {fluent_cart_block_translation: {}};
        const setDraft = vi.fn();
        let calls = 0;
        globalThis.wp = {
            element: {
                useState: (initial) => (calls++ === 0 ? [typeof initial === 'function' ? initial() : initial] : [typedDraft ?? initial, setDraft]),
                useEffect: () => {},
            },
        };
        const {default: NumberStepper} = await import('@/BlockEditor/Components/NumberStepper.jsx');
        const onChange = vi.fn();
        const tree = NumberStepper({label: 'Number of products', value: 6, min: 1, max: 12, onChange, ...props});
        const [minus] = findAll(tree, (el) => el.props?.['aria-label'] === 'Decrease');
        const [plus] = findAll(tree, (el) => el.props?.['aria-label'] === 'Increase');
        const [input] = findAll(tree, (el) => el.type === 'input');

        return {minus, plus, input, onChange, setDraft};
    };

    it('plus and minus step by one', async () => {
        const {minus, plus, onChange} = await stepper({});

        minus.props.onClick();
        plus.props.onClick();

        expect(onChange.mock.calls).toEqual([[5], [7]]);
    });

    it('minus is disabled at the minimum and plus at the maximum', async () => {
        const atMin = await stepper({value: 1});
        const atMax = await stepper({value: 12});
        const middle = await stepper({});

        expect(atMin.minus.props.disabled).toBe(true);
        expect(atMin.plus.props.disabled).toBe(false);
        expect(atMax.plus.props.disabled).toBe(true);
        expect(atMax.minus.props.disabled).toBe(false);
        expect(middle.minus.props.disabled).toBe(false);
        expect(middle.plus.props.disabled).toBe(false);
    });

    it('typing keeps the text and saves nothing, so a prefix such as 1 of 13 is never saved', async () => {
        const {input, onChange, setDraft} = await stepper({});

        ['1', '13'].forEach((typed) => input.props.onChange({target: {value: typed}}));

        expect(onChange).not.toHaveBeenCalled();
        expect(setDraft.mock.calls).toEqual([['1'], ['13']]);
    });

    it('leaving the box saves the typed number, clamped to the range', async () => {
        const above = await stepper({}, '13');
        const below = await stepper({}, '0');
        const inside = await stepper({}, '9');

        above.input.props.onBlur();
        below.input.props.onBlur();
        inside.input.props.onBlur();

        expect(above.onChange).toHaveBeenCalledWith(12);
        expect(above.setDraft).toHaveBeenCalledWith('12');
        expect(below.onChange).toHaveBeenCalledWith(1);
        expect(inside.onChange).toHaveBeenCalledWith(9);
    });

    it('Enter saves the typed number too', async () => {
        const {input, onChange} = await stepper({}, '9');
        const preventDefault = vi.fn();

        input.props.onKeyDown({key: 'Enter', preventDefault});
        input.props.onKeyDown({key: 'a', preventDefault});

        expect(onChange.mock.calls).toEqual([[9]]);
        expect(preventDefault).toHaveBeenCalledTimes(1);
    });

    it('text that is not a number is dropped and the saved value comes back', async () => {
        const {input, onChange, setDraft} = await stepper({}, 'abc');

        input.props.onBlur();

        expect(onChange).not.toHaveBeenCalled();
        expect(setDraft).toHaveBeenCalledWith('6');
    });

    it('leaving the box unchanged saves nothing', async () => {
        const {input, onChange} = await stepper({}, '6');

        input.props.onBlur();

        expect(onChange).not.toHaveBeenCalled();
    });

    it('plus and minus keep a stored value that is out of range inside it', async () => {
        const tooMany = await stepper({value: 20});
        tooMany.minus.props.onClick();

        expect(tooMany.onChange).toHaveBeenCalledWith(12);
        expect(tooMany.plus.props.disabled).toBe(true);
    });

    it('a missing or non-numeric stored value counts as the minimum', async () => {
        const {minus, plus, onChange} = await stepper({value: NaN});

        expect(minus.props.disabled).toBe(true);
        plus.props.onClick();
        expect(onChange).toHaveBeenCalledWith(2);
    });

    it('the input and the group are named by the visible label', async () => {
        const {input} = await stepper({});

        expect(input.props['aria-labelledby']).toMatch(/^fct-number-stepper-label-\d+$/);
        expect(input.props.min).toBe(1);
        expect(input.props.max).toBe(12);
    });
});

describe('CheckboxGroup', () => {
    afterEach(() => {
        delete globalThis.wp;
    });

    const OPTIONS = [
        {value: 'categories', label: 'Categories', checked: true},
        {value: 'brands', label: 'Brands', checked: false},
    ];

    // WordPress's own checkbox is stood in for, so its props can be read and its change fired.
    const group = async (options = OPTIONS, onToggle = vi.fn()) => {
        vi.resetModules();
        globalThis.React = React;
        globalThis.wp = {
            element: {useState: (initial) => [typeof initial === 'function' ? initial() : initial]},
            components: {CheckboxControl: () => null},
        };
        const {default: CheckboxGroup} = await import('@/BlockEditor/Components/CheckboxGroup.jsx');
        const tree = CheckboxGroup({label: 'Match products that share', options, onToggle});

        return {checkboxes: findAll(tree, (el) => typeof el.props?.onChange === 'function'), onToggle};
    };

    it('shows one checkbox per option, checked as the option says', async () => {
        const {checkboxes} = await group();

        expect(checkboxes.map((box) => [box.props.label, box.props.checked])).toEqual([['Categories', true], ['Brands', false]]);
    });

    it('ticking or clearing a box reports its value and the new state', async () => {
        const {checkboxes, onToggle} = await group();

        checkboxes[0].props.onChange(false);
        checkboxes[1].props.onChange(true);

        expect(onToggle.mock.calls).toEqual([['categories', false], ['brands', true]]);
    });

    it('is a labelled group', async () => {
        vi.resetModules();
        globalThis.React = React;
        globalThis.wp = {element: React, components: {CheckboxControl: ({label}) => React.createElement('label', null, label)}};
        const {default: CheckboxGroup} = await import('@/BlockEditor/Components/CheckboxGroup.jsx');
        const html = renderToStaticMarkup(React.createElement(CheckboxGroup, {label: 'Match products that share', options: OPTIONS, onToggle: () => {}}));

        expect(html).toContain('role="group"');
        expect(html).toMatch(/aria-labelledby="fct-checkbox-group-label-\d+"/);
        expect(html).toContain('Match products that share');
        expect(html).toContain('Categories');
        expect(html).toContain('Brands');
    });

    it('has no chip styling left: no border or pressed state to draw', async () => {
        const {checkboxes} = await group();

        checkboxes.forEach((box) => {
            expect(box.props['aria-pressed']).toBeUndefined();
            expect(box.props.className).toBeUndefined();
        });
    });
});

describe('Related Product sidebar panels', () => {
    const attributes = (extra = {}) => {
        const merged = {
            query_type: 'custom', product_id: '7', related_by_categories: true, related_by_brands: false,
            order_by: 'title_asc', columns: 4, posts_per_page: 6, ...extra,
        };
        // By default every asked-for related product has loaded, as it does once the request returns.
        if (!('related_products' in extra)) {
            merged.related_products = Array.from({length: Number(merged.posts_per_page)}, (unused, index) => ({ID: index + 1}));
        }
        return merged;
    };

    // The inspector holds no state, so it is called as a function and its tree walked.
    const inspector = async (attributeOverrides = {}, setAttributes = vi.fn()) => {
        vi.resetModules();
        const seen = {control: null};
        vi.doMock('@/BlockEditor/Components/ProductPicker/ProductSourceControl', () => ({
            default: (props) => {
                seen.control = props;
                return null;
            },
        }));
        globalThis.React = React;
        globalThis.window = {fluent_cart_block_translation: {}};
        globalThis.wp = {
            element: React,
            blockEditor: {InspectorControls: passThrough},
            components: {PanelBody: passThrough, SelectControl: () => null, ToggleControl: () => null, CheckboxControl: () => null},
        };

        const {default: RelatedProductInspectorSettings} = await import('@/BlockEditor/RelatedProduct/Components/RelatedProductInspectorSettings.jsx');
        const tree = RelatedProductInspectorSettings({
            attributes: attributes(attributeOverrides),
            setAttributes,
            selectedProduct: {ID: 7, post_title: 'Seven'},
            setSelectedProduct: vi.fn(),
            showDisplayElements: false,
        });
        const named = (Component) => findAll(tree, (el) => el.type?.name === Component || el.type === Component);
        const byProp = (predicate) => findAll(tree, (el) => el.props && predicate(el.props));

        return {tree, seen, setAttributes, byProp, named};
    };

    it('has a Product panel and a Display panel', async () => {
        const {byProp} = await inspector();
        const panels = byProp((props) => typeof props.title === 'string').map((el) => el.props.title);

        expect(panels).toEqual(['Product', 'Display']);
    });

    it('labels the source control "Related to"', async () => {
        const {byProp} = await inspector();
        const [control] = byProp((props) => typeof props.onQueryTypeChange === 'function');

        expect(control.props.label).toBe('Related to');
    });

    it('match chips reflect the attributes and switch each attribute on its own', async () => {
        const {byProp, setAttributes} = await inspector();
        const [chips] = byProp((props) => props.label === 'Match products that share');

        expect(chips.props.options).toEqual([
            {value: 'categories', label: 'Categories', checked: true},
            {value: 'brands', label: 'Brands', checked: false},
        ]);
        chips.props.onToggle('brands', true);
        chips.props.onToggle('categories', false);

        expect(setAttributes.mock.calls).toEqual([[{related_by_brands: true}], [{related_by_categories: false}]]);
    });

    it('order by leads with the name options and saves the choice', async () => {
        const {byProp, setAttributes} = await inspector();
        const [select] = byProp((props) => props.label === 'Order by');

        expect(select.props.options.slice(0, 2)).toEqual([
            {label: 'Name: A to Z', value: 'title_asc'},
            {label: 'Name: Z to A', value: 'title_desc'},
        ]);
        expect(select.props.options).toHaveLength(7);
        select.props.onChange('rand');
        expect(setAttributes).toHaveBeenCalledWith({order_by: 'rand'});
    });

    it('columns are a 1 to 6 toggle with the current one selected', async () => {
        const {byProp, setAttributes} = await inspector({columns: 4});
        const [columns] = byProp((props) => props.label === 'Columns');

        expect(columns.props.options.map((option) => option.value)).toEqual([1, 2, 3, 4, 5, 6]);
        expect(columns.props.value).toBe(4);
        columns.props.onChange(3);
        expect(setAttributes).toHaveBeenCalledWith({columns: 3});
    });

    it('columns saved as text still select the matching number', async () => {
        const {byProp} = await inspector({columns: '5'});
        const [columns] = byProp((props) => props.label === 'Columns');

        expect(columns.props.value).toBe(5);
    });

    it('keeps the layout-cards wording as the columns note, read with the toggle', async () => {
        const {byProp} = await inspector();
        const [columns] = byProp((props) => props.label === 'Columns');

        expect(columns.props.help).toBe('Layout cards show fewer per row where each would be under 220px wide.');
    });

    it('number of products is a 1 to 12 stepper that saves the value', async () => {
        const {byProp, setAttributes} = await inspector({posts_per_page: 6});
        const [stepper] = byProp((props) => props.label === 'Number of products');

        expect([stepper.props.value, stepper.props.min, stepper.props.max]).toEqual([6, 1, 12]);
        stepper.props.onChange(9);
        expect(setAttributes).toHaveBeenCalledWith({posts_per_page: 9});
    });

    it('warns about a short last row and offers the count that fills it', async () => {
        const {tree, setAttributes} = await inspector({posts_per_page: 6, columns: 4});
        const markup = renderToStaticMarkup(React.createElement(passThrough, null, tree));
        const [fill] = findAll(tree, (el) => el.props?.className === 'fct-related-hint__fill');

        expect(markup).toContain('Last row has 2 of 4 cards.');
        expect(markup).toContain('Show 8 to fill the row');
        fill.props.onClick();
        expect(setAttributes).toHaveBeenCalledWith({posts_per_page: 8});
    });

    it('shows no warning when fewer related products came back than were asked for', async () => {
        const {tree} = await inspector({posts_per_page: 6, columns: 4, related_products: [{ID: 1}, {ID: 2}, {ID: 3}]});

        expect(findAll(tree, (el) => el.props?.className === 'fct-related-hint')).toHaveLength(0);
    });

    it('shows no warning while nothing has loaded or in Default mode, where the list is empty', async () => {
        const loading = await inspector({posts_per_page: 6, columns: 4, related_products: []});
        const defaultMode = await inspector({query_type: 'default', product_id: '', posts_per_page: 6, columns: 4, related_products: []});

        expect(findAll(loading.tree, (el) => el.props?.className === 'fct-related-hint')).toHaveLength(0);
        expect(findAll(defaultMode.tree, (el) => el.props?.className === 'fct-related-hint')).toHaveLength(0);
    });

    it('still warns when the full count came back', async () => {
        const products = [1, 2, 3, 4, 5, 6].map((id) => ({ID: id}));
        const {tree} = await inspector({posts_per_page: 6, columns: 4, related_products: products});

        expect(findAll(tree, (el) => el.props?.className === 'fct-related-hint')).toHaveLength(1);
    });

    it('announces the warning through a status region that is always there', async () => {
        const warned = await inspector({posts_per_page: 6, columns: 4});
        const quiet = await inspector({posts_per_page: 8, columns: 4});
        const status = (tree) => findAll(tree, (el) => el.props?.role === 'status')[0];

        expect(status(warned.tree).props.children).toBe('Last row has 2 of 4 cards.');
        expect(status(quiet.tree).props.children).toBe('');
    });

    it('shows no warning when the rows are full', async () => {
        const {tree} = await inspector({posts_per_page: 8, columns: 4});

        expect(findAll(tree, (el) => el.props?.className === 'fct-related-hint')).toHaveLength(0);
    });

    it('warns without an offer when filling the row would pass the maximum', async () => {
        const {tree} = await inspector({posts_per_page: 11, columns: 5});
        const markup = renderToStaticMarkup(React.createElement(passThrough, null, tree));

        expect(markup).toContain('Last row has 1 of 5 cards.');
        expect(markup).not.toContain('to fill the row');
    });
});

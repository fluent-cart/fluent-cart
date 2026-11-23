import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

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

describe('RangeField', () => {
    afterEach(() => {
        delete globalThis.wp;
    });

    const load = async () => {
        vi.resetModules();
        globalThis.React = React;
        globalThis.wp = {element: React};
        const {default: RangeField} = await import('@/BlockEditor/Components/RangeField.jsx');
        return RangeField;
    };

    // Calls the component with fixed state so the element tree can be walked.
    const field = async (props) => {
        const RangeField = await load();
        globalThis.wp = {element: {useState: (initial) => [typeof initial === 'function' ? initial() : initial]}};
        const onChange = vi.fn();
        const tree = RangeField({label: 'Product Grid Size', value: 3, min: 0, max: 6, onChange, ...props});
        const [slider] = findAll(tree, (el) => el.type === 'input');
        const [readout] = findAll(tree, (el) => el.type === 'output');

        return {slider, readout, onChange};
    };

    it('is a labelled slider from min to max in whole steps', async () => {
        const {slider} = await field({});

        expect(slider.props.type).toBe('range');
        expect([slider.props.min, slider.props.max, slider.props.step, slider.props.value]).toEqual([0, 6, 1, 3]);
        expect(slider.props['aria-labelledby']).toMatch(/^fct-range-field-label-\d+$/);
    });

    it('saves the number the slider moves to', async () => {
        const {slider, onChange} = await field({});

        slider.props.onChange({target: {value: '5'}});
        slider.props.onChange({target: {value: '0'}});

        expect(onChange.mock.calls).toEqual([[5], [0]]);
    });

    it('shows the value in a box, and speaks it to a screen reader', async () => {
        const {slider, readout} = await field({value: 4});

        expect(readout.props.children).toBe('4');
        expect(slider.props['aria-valuetext']).toBe('4');
        // The slider speaks the value; the box is only its visible copy.
        expect(readout.props['aria-hidden']).toBe('true');
    });

    it('reads Default at the minimum when the setting has a default, and the number elsewhere', async () => {
        const atMin = await field({value: 0, zeroLabel: 'Default'});
        const above = await field({value: 2, zeroLabel: 'Default'});
        const noLabel = await field({value: 0});

        expect(atMin.readout.props.children).toBe('Default');
        expect(atMin.slider.props['aria-valuetext']).toBe('Default');
        expect(above.readout.props.children).toBe('2');
        expect(noLabel.readout.props.children).toBe('0');
    });

    it('fills the track in proportion to the value', async () => {
        const {slider} = await field({value: 3, min: 0, max: 6});
        const quarter = await field({value: 3, min: 0, max: 12});

        expect(slider.props.style['--fct-range-fill']).toBe('50%');
        expect(quarter.slider.props.style['--fct-range-fill']).toBe('25%');
    });

    it('keeps a stored value that is out of range or not a number inside the range', async () => {
        const high = await field({value: 9});
        const low = await field({value: -2});
        const missing = await field({value: NaN});

        expect(high.slider.props.value).toBe(6);
        expect(low.slider.props.value).toBe(0);
        expect(missing.slider.props.value).toBe(0);
    });

    it('renders the label, the slider and the box', async () => {
        const RangeField = await load();
        const html = renderToStaticMarkup(React.createElement(RangeField, {label: 'Product Grid Size', value: 3, min: 0, max: 6, onChange: () => {}, zeroLabel: 'Default'}));

        expect(html).toContain('Product Grid Size');
        expect(html).toContain('type="range"');
        expect(html).toContain('<output');
    });
});

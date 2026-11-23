import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const OPTIONS = [
    {value: 'full', label: 'Fill column'},
    {value: 'fixed', label: 'Fixed'},
];

const load = async () => {
    vi.resetModules();
    globalThis.React = React;
    globalThis.wp = {element: React};
    globalThis.getComputedStyle = () => ({direction: 'ltr'});
    const {default: SegmentedToggle} = await import('@/BlockEditor/Components/SegmentedToggle.jsx');
    return SegmentedToggle;
};

// Calls the component directly with a fixed id so its element tree can be walked.
const tree = async (props) => {
    const SegmentedToggle = await load();
    globalThis.wp = {element: {useState: (initial) => [typeof initial === 'function' ? initial() : initial]}};

    return SegmentedToggle({label: 'Card width', options: OPTIONS, value: 'full', onChange: () => {}, ...props});
};

const buttons = (node) => {
    const group = React.Children.toArray(node.props.children)[1];
    return React.Children.toArray(group.props.children).flat();
};

describe('SegmentedToggle', () => {
    afterEach(() => {
        delete globalThis.getComputedStyle;
    });

    it('is a labelled radio group with one checked, focusable option', async () => {
        const SegmentedToggle = await load();
        const html = renderToStaticMarkup(React.createElement(SegmentedToggle, {label: 'Card width', options: OPTIONS, value: 'fixed', onChange: () => {}}));

        expect(html).toContain('role="radiogroup"');
        expect(html).toMatch(/aria-labelledby="fct-segmented-toggle-label-\d+"/);
        expect(html).toMatch(/id="fct-segmented-toggle-label-\d+" class="fct-sidebar-label">Card width</);
        // Fill column is unchecked and out of the tab order; Fixed is checked and focusable.
        expect(html).toMatch(/role="radio" aria-checked="false" tabindex="-1"[^>]*>Fill column/);
        expect(html).toMatch(/role="radio" aria-checked="true" tabindex="0"[^>]*>Fixed/);
    });

    it('clicking an option reports its value', async () => {
        const onChange = vi.fn();
        const [first, second] = buttons(await tree({onChange}));

        second.props.onClick();
        first.props.onClick();

        expect(onChange.mock.calls).toEqual([['fixed'], ['full']]);
    });

    it('arrow keys move the selection and the focus, Home and End jump to the ends', async () => {
        const onChange = vi.fn();
        const optionButtons = [{focus: vi.fn()}, {focus: vi.fn()}];
        const [first, second] = buttons(await tree({onChange}));
        const keyEvent = (key) => ({
            key,
            preventDefault: vi.fn(),
            currentTarget: {closest: () => ({querySelectorAll: () => optionButtons})},
        });

        first.props.onKeyDown(keyEvent('ArrowRight'));
        second.props.onKeyDown(keyEvent('ArrowRight'));
        second.props.onKeyDown(keyEvent('Home'));
        first.props.onKeyDown(keyEvent('End'));
        first.props.onKeyDown(keyEvent('a'));

        expect(onChange.mock.calls).toEqual([['fixed'], ['full'], ['full'], ['fixed']]);
        expect(optionButtons[1].focus).toHaveBeenCalledTimes(2);
    });

    it('with no option checked, the first one stays reachable by keyboard', async () => {
        const SegmentedToggle = await load();
        const html = renderToStaticMarkup(React.createElement(SegmentedToggle, {label: 'Columns', options: OPTIONS, value: 8, onChange: () => {}}));

        expect(html).toMatch(/aria-checked="false" tabindex="0"[^>]*>Fill column/);
        expect(html).toMatch(/aria-checked="false" tabindex="-1"[^>]*>Fixed/);
    });

    it('ties a note to the group, so a screen reader reads it with the options', async () => {
        const SegmentedToggle = await load();
        const html = renderToStaticMarkup(React.createElement(SegmentedToggle, {label: 'Columns', options: OPTIONS, value: 'full', onChange: () => {}, help: 'Fewer per row on narrow cards.'}));
        const describedBy = html.match(/aria-describedby="([^"]+)"/)?.[1];

        expect(describedBy).toBeTruthy();
        expect(html).toContain('id="' + describedBy + '"');
        expect(html).toContain('Fewer per row on narrow cards.');
    });

    it('has no description link when there is no note', async () => {
        const SegmentedToggle = await load();
        const html = renderToStaticMarkup(React.createElement(SegmentedToggle, {label: 'Columns', options: OPTIONS, value: 'full', onChange: () => {}}));

        expect(html).not.toContain('aria-describedby');
    });
});

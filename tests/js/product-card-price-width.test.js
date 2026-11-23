import {describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Just enough of DOMParser to decode the entities the price fields carry.
class EntityParser {
    parseFromString(value) {
        const text = String(value)
            .replace(/&#(\d+);/g, (match, code) => String.fromCodePoint(Number(code)))
            .replace(/&amp;/g, '&');
        return {body: {textContent: text}};
    }
}

const loadPreview = async () => {
    vi.resetModules();
    globalThis.DOMParser = EntityParser;
    globalThis.window = {fluent_cart_block_translation: {}};
    const {priceFormatPreview} = await import('@/BlockEditor/ProductCard/priceFormatPreview.js');
    return priceFormatPreview;
};

const product = (detail) => ({ID: 1, post_title: 'Tee', detail});
const ranged = product({formatted_min_price: '&#36;45,00', formatted_max_price: '&#36;60,00', min_price: 4500, max_price: 6000});
const single = product({formatted_min_price: '&#36;45,00', formatted_max_price: '&#36;45,00', min_price: 4500, max_price: 4500});

describe('priceFormatPreview mirrors how the card writes its price', () => {
    it('Starts from reads "From {min}" for a product with a price range', async () => {
        expect((await loadPreview())(ranged, 'starts_from')).toBe('From $45,00');
    });

    it('Range reads "{min} - {max}" for a product with a price range', async () => {
        expect((await loadPreview())(ranged, 'range')).toBe('$45,00 - $60,00');
    });

    it('a single-price product shows its one price whichever format is chosen', async () => {
        const preview = await loadPreview();

        expect(preview(single, 'starts_from')).toBe('$45,00');
        expect(preview(single, 'range')).toBe('$45,00');
    });

    it('decodes the entities the price carries', async () => {
        expect((await loadPreview())(ranged, 'range')).not.toContain('&#36;');
    });

    it('shows just the minimum when the maximum price is missing', async () => {
        const noMax = product({formatted_min_price: '&#36;45,00', min_price: 4500, max_price: 6000});

        expect((await loadPreview())(noMax, 'range')).toBe('$45,00');
    });

    it('shows nothing while there is no loaded product to preview', async () => {
        const preview = await loadPreview();

        expect(preview(null, 'range')).toBe('');
        expect(preview({}, 'range')).toBe('');
        expect(preview({missing: true}, 'starts_from')).toBe('');
        expect(preview(product({}), 'range')).toBe('');
    });
});

describe('Product Card price format and card width', () => {
    const renderInspector = async (props) => {
        vi.resetModules();
        const seen = {toggle: null, slider: null, number: null, select: null};
        vi.doMock('@/BlockEditor/Components/ProductPicker/ProductSourceControl', () => ({default: () => null}));
        vi.doMock('@/BlockEditor/Components/SegmentedToggle', () => ({
            default: (toggleProps) => {
                seen.toggle = toggleProps;
                return null;
            },
        }));

        globalThis.React = React;
        globalThis.DOMParser = EntityParser;
        globalThis.window = {fluent_cart_block_translation: {}};
        globalThis.wp = {
            element: React,
            blockEditor: {InspectorControls: passThrough},
            components: {
                Panel: passThrough,
                PanelBody: passThrough,
                RangeControl: (sliderProps) => {
                    seen.slider = sliderProps;
                    return null;
                },
                SelectControl: (selectProps) => {
                    seen.select = selectProps;
                    return null;
                },
                __experimentalNumberControl: (numberProps) => {
                    seen.number = numberProps;
                    return null;
                },
            },
        };

        const {default: InspectorSettings} = await import('@/BlockEditor/ProductCard/Components/InspectorSettings.jsx');
        const markup = renderToStaticMarkup(React.createElement(InspectorSettings, {
            attributes: {query_type: 'custom', product_id: '1', price_format: 'starts_from', card_width: 300},
            setAttributes: vi.fn(),
            selectedProduct: ranged,
            setSelectedProduct: vi.fn(),
            ...props,
        }));

        return {seen, markup};
    };

    it('price format offers Starts from and Range, and saves the choice', async () => {
        const setAttributes = vi.fn();
        const {seen} = await renderInspector({setAttributes});

        expect(seen.select.options).toEqual([
            {label: 'Starts from', value: 'starts_from'},
            {label: 'Range', value: 'range'},
        ]);
        seen.select.onChange('range');
        expect(setAttributes).toHaveBeenCalledWith({price_format: 'range'});
    });

    it('shows how the chosen format reads for the product', async () => {
        const starts = await renderInspector({});
        const range = await renderInspector({attributes: {query_type: 'custom', product_id: '1', price_format: 'range', card_width: 300}});

        expect(starts.markup).toContain('Shows as:');
        expect(starts.markup).toContain('From $45,00');
        expect(range.markup).toContain('$45,00 - $60,00');
    });

    it('shows no preview line when no product is loaded', async () => {
        const {markup} = await renderInspector({selectedProduct: {}});

        expect(markup).not.toContain('Shows as:');
    });

    it('card width is Fixed with a slider and number box for a numeric width', async () => {
        const {seen} = await renderInspector({});

        expect(seen.toggle.value).toBe('fixed');
        expect(seen.toggle.options.map((option) => option.label)).toEqual(['Fill column', 'Fixed']);
        expect(seen.slider.value).toBe(300);
        expect(seen.number.value).toBe('300');
    });

    it('a width saved as a numeric string is Fixed, as the server renders it in pixels', async () => {
        const {seen} = await renderInspector({attributes: {query_type: 'custom', product_id: '1', price_format: 'starts_from', card_width: '300'}});

        expect(seen.toggle.value).toBe('fixed');
        expect(seen.slider.value).toBe(300);
        expect(seen.number.value).toBe('300');
    });

    it('a width outside the range or not a number shows the nearest width the server would use', async () => {
        const low = await renderInspector({attributes: {query_type: 'custom', product_id: '1', price_format: 'starts_from', card_width: '50%'}});
        const high = await renderInspector({attributes: {query_type: 'custom', product_id: '1', price_format: 'starts_from', card_width: 5000}});

        expect(low.seen.slider.value).toBe(216);
        expect(high.seen.slider.value).toBe(1000);
    });

    it('card width is Fill column with no slider for 100%', async () => {
        const {seen} = await renderInspector({attributes: {query_type: 'custom', product_id: '1', price_format: 'starts_from', card_width: '100%'}});

        expect(seen.toggle.value).toBe('full');
        expect(seen.slider).toBeNull();
        expect(seen.number).toBeNull();
    });

    it('the toggle switches between 100% and the 216 minimum', async () => {
        const setAttributes = vi.fn();
        const {seen} = await renderInspector({setAttributes});

        seen.toggle.onChange('full');
        seen.toggle.onChange('fixed');

        expect(setAttributes.mock.calls).toEqual([[{card_width: '100%'}], [{card_width: 216}]]);
    });

    it('the slider saves a number and ignores a cleared value', async () => {
        const setAttributes = vi.fn();
        const {seen} = await renderInspector({setAttributes});

        seen.slider.onChange(450);
        seen.slider.onChange(undefined);

        expect(setAttributes.mock.calls).toEqual([[{card_width: 450}]]);
        expect(seen.slider.min).toBe(216);
        expect(seen.slider.max).toBe(1000);
        expect(seen.slider.step).toBe(10);
    });

    it('the number box saves only a value in range, so typing 300 over 216 can pass through 3 and 30', async () => {
        const setAttributes = vi.fn();
        const {seen} = await renderInspector({setAttributes});

        ['3', '30', '300', '', 'abc', '5000', '1000', '216'].forEach((typed) => seen.number.onChange(typed));

        expect(setAttributes.mock.calls).toEqual([
            [{card_width: 300}],
            [{card_width: 1000}],
            [{card_width: 216}],
        ]);
    });

    it('the number box takes whole pixels while only the slider snaps to a step', async () => {
        const {seen} = await renderInspector({});

        expect(seen.number.step).toBe(1);
        expect(seen.slider.step).toBe(10);
    });

    it('the slider and the number box have their own accessible names', async () => {
        const {seen} = await renderInspector({});

        expect(seen.slider.label).toBe('Card width slider');
        expect(seen.number.label).toBe('Card width in pixels');
        expect(seen.toggle.label).toBe('Card width');
    });

    it('previews the price of the product the card shows when that is not the selected one', async () => {
        const {markup} = await renderInspector({selectedProduct: {}, displayedProduct: ranged});

        expect(markup).toContain('From $45,00');
    });

    it('the number box shows the px unit', async () => {
        const {seen} = await renderInspector({});

        expect(renderToStaticMarkup(seen.number.suffix)).toContain('px');
    });
});

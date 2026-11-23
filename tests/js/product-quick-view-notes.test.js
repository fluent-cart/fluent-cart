import {afterEach, describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

// Renders the Quick View block's canvas for a product kind and returns its markup.
const renderQuickView = async ({variationType, viewOptions = false}) => {
    vi.resetModules();
    vi.doMock('@/BlockEditor/Icons', () => ({QuickView: () => React.createElement('svg', {'data-icon': 'quick-view'})}));
    vi.doMock('@/BlockEditor/Components/ErrorBoundary', () => ({default: passThrough}));
    vi.doMock('@/BlockEditor/ProductQuickView/Components/InspectorSettings', () => ({default: () => null}));
    vi.doMock('@/BlockEditor/ShopApp/Context/SingleProductContext', () => ({useSingleProductData: () => ({product: {ID: 1}})}));
    vi.doMock('@/BlockEditor/ProductSwatches/useProductSwatchPreview', () => ({default: () => ({previews: []})}));
    vi.doMock('@/BlockEditor/ShopApp/InnerBlocks/ProductButtonBlock', () => ({useCardSwatches: () => ({})}));
    vi.doMock('@/BlockEditor/ShopApp/InnerBlocks/cardButtonState', () => ({showsViewOptions: () => viewOptions}));

    let registered = null;
    globalThis.React = React;
    globalThis.window = {
        fluent_cart_product_quick_view_data: {slug: 'fluent-cart', name: 'product-quick-view', title: 'Quick View', description: ''},
        fluent_cart_block_translation: {},
    };
    globalThis.wp = {
        blocks: {registerBlockType: (name, settings) => { registered = settings; }},
        blockEditor: {useBlockProps: (props) => props},
        data: {useSelect: () => false, useDispatch: () => ({})},
        element: {useEffect: () => {}},
    };

    await import('@/BlockEditor/ProductQuickView/ProductQuickViewBlockEditor.jsx');

    return renderToStaticMarkup(React.createElement(registered.edit, {
        attributes: {position: 'button', show_on: 'always', display: 'icon', image_position: 'bottom-center'},
        setAttributes: () => {},
        context: {detail: {variation_type: variationType}},
        clientId: 'quick-view',
    }));
};

describe('Quick View block in the editor canvas', () => {
    afterEach(() => {
        vi.resetModules();
    });

    it('draws no text for a simple product, which gets no quick view', async () => {
        const html = await renderQuickView({variationType: 'simple'});

        expect(html).not.toContain('No quick view');
        expect(html).not.toContain('fct-card-swatch-empty');
        expect(html).not.toContain('fct-card-quick-view-button');
    });

    it('draws no text while the card shows View Options, which hides quick view', async () => {
        const html = await renderQuickView({variationType: 'simple_variations', viewOptions: true});

        expect(html).not.toContain('Hidden while the card shows View Options');
        expect(html).not.toContain('fct-card-swatch-empty');
        expect(html).not.toContain('fct-card-quick-view-button');
    });

    it('still previews the quick view button for a product that has one', async () => {
        const html = await renderQuickView({variationType: 'simple_variations'});

        expect(html).toContain('fct-card-quick-view-button');
        expect(html).not.toContain('fct-card-swatch-empty');
    });
});

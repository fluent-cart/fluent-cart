import {describe, expect, it, vi} from 'vitest';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

const passThrough = ({children}) => React.createElement('div', null, children);

const renderEmptyCarousel = async () => {
    vi.resetModules();
    vi.doMock('@wordpress/api-fetch', () => ({default: () => new Promise(() => {})}));
    vi.doMock('@/BlockEditor/ProductCarousel/Components/InspectorSettings.jsx', () => ({default: () => null}));

    let registered = null;
    const select = () => ({
        getBlocks: () => [],
        getBlock: () => ({innerBlocks: []}),
        getBlockParents: () => [],
        getBlockName: () => null,
    });

    globalThis.React = React;
    globalThis.window = {
        fluent_cart_product_carousel_block_editor_data: {slug: 'fluent-cart', name: 'product-carousel', title: 'Product Carousel'},
        fluentCartRestVars: {rest: {url: '/wp-json/fluent-cart/v2', nonce: 'nonce'}},
        fluent_cart_block_translation: {},
    };
    globalThis.wp = {
        blocks: {registerBlockType: (name, settings) => { registered = {name, settings}; }},
        blockEditor: {useBlockProps: () => ({}), InnerBlocks: passThrough, InspectorControls: passThrough, store: 'core/block-editor'},
        element: React,
        data: {useSelect: (mapSelect) => mapSelect(select)},
    };

    await import('@/BlockEditor/ProductCarousel/ProductCarouselBlockEditor.jsx');

    return renderToStaticMarkup(React.createElement(registered.settings.edit, {
        attributes: {product_ids: [], carousel_settings: {slidesToShow: 3, infinite: 'no'}, inside_product_info: 'no'},
        setAttributes: () => {},
        clientId: 'carousel',
    }));
};

describe('Product Carousel editor preview', () => {
    it('asks for products instead of loading forever when none are selected', async () => {
        const html = await renderEmptyCarousel();

        expect(html).toContain('Select products in the block settings to show them here.');
        expect(html).not.toContain('Loading products...');
    });
});

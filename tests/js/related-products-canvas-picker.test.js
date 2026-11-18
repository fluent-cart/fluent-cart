import {beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import React from 'react';

// The Related Products block's edit, called directly with stubbed hooks: its element tree is searched for the
// picker that lets Custom mode change the product from the canvas.
vi.mock('@/BlockEditor/RelatedProduct/Components/RelatedProductInspectorSettings', () => ({default: () => null}));
vi.mock('@/BlockEditor/RelatedProduct/Components/ProductTemplateBlock.jsx', () => ({}));
vi.mock('@/BlockEditor/RelatedProduct/Components/productTemplate', () => ({anyLoopUsesDisplayElements: () => false}));
vi.mock('@/BlockEditor/Components/ProductPicker/ProductDropdownPicker.jsx', () => ({default: () => null}));
vi.mock('@wordpress/api-fetch', () => ({default: () => new Promise(() => {})}));

let edit;
let picker;

const find = (node, predicate, found = []) => {
    if (Array.isArray(node)) {
        node.forEach((child) => find(child, predicate, found));
    } else if (node && typeof node === 'object' && node.props) {
        if (predicate(node)) found.push(node);
        find(node.props.children, predicate, found);
    }
    return found;
};
const render = (attributes) => {
    const setAttributes = vi.fn();
    const tree = edit({attributes: {related_by_categories: true, related_by_brands: true, ...attributes}, setAttributes, clientId: 'related'});
    return {setAttributes, pickers: find(tree, (node) => node.type === picker)};
};

beforeAll(async () => {
    globalThis.React = React;
    globalThis.window = {
        fluent_cart_related_product_data: {slug: 'fluent-cart', name: 'related-products'},
        fluent_cart_block_translation: {},
        fluentCartRestVars: {rest: {url: '', nonce: ''}},
    };
    globalThis.wp = {
        blocks: {registerBlockType: (name, settings) => {edit = settings.edit;}},
        blockEditor: {useBlockProps: (props) => props, InnerBlocks: () => null},
        components: {Placeholder: () => null},
        element: {useEffect: () => {}, useState: (value) => [value, () => {}]},
        data: {useSelect: (read) => read(() => ({getBlocks: () => []}))},
    };
    ({default: picker} = await import('@/BlockEditor/Components/ProductPicker/ProductDropdownPicker.jsx'));
    await import('@/BlockEditor/RelatedProduct/RelatedProductBlockEditor.jsx');
});

beforeEach(() => {});

describe('Related Products canvas product picker', () => {
    it('shows the picker above the preview once a product is chosen in Custom mode', () => {
        const {pickers} = render({query_type: 'custom', product_id: 42});
        expect(pickers).toHaveLength(1);
        expect(pickers[0].props.compactLabel).toBe('Related to');
    });

    it('changes the product from the canvas', () => {
        const {pickers, setAttributes} = render({query_type: 'custom', product_id: 42});
        pickers[0].props.onSelect({ID: 7, post_title: 'Alcohol Marker'});
        expect(setAttributes).toHaveBeenCalledWith({product_id: 7});
    });

    it('has no picker in Default mode, where the page decides the product', () => {
        expect(render({query_type: 'default', product_id: ''}).pickers).toHaveLength(0);
    });
});

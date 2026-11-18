import {beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import React from 'react';

// The Products block's edit, called directly with stubbed hooks: a grid-only card syncs a saved list view to grid.
vi.mock('@/BlockEditor/ShopApp/Settings/InspectorSettings.jsx', () => ({default: () => null}));
vi.mock('@/BlockEditor/ShopApp/InnerBlocks/Components/CardLayoutSettings', () => ({default: () => null}));
vi.mock('@wordpress/api-fetch', () => ({default: () => new Promise(() => {})}));
vi.mock('@/BlockEditor/ShopApp/Products.png', () => ({default: ''}));
vi.mock('@/BlockEditor/ShopApp/Data/Data', () => ({default: {}}));

let edit;
let cardLayout;
let markNotPersistent;

const run = (attributes) => {
    const setAttributes = vi.fn();
    edit({attributes: {variations: {}, ...attributes}, setAttributes, clientId: 'products'});
    return setAttributes;
};

beforeAll(async () => {
    globalThis.React = React;
    globalThis.window = {
        fluent_cart_shop_app_block_editor_data: {slug: 'fluent-cart', name: 'products'},
        fluent_cart_block_translation: {},
        fluentCartRestVars: {rest: {url: ''}},
        fluent_cart_products_inner_blocks: {card_presets: {compact: {template: []}, floating: {template: [], view_switcher: false}}},
    };
    const store = {
        getClientIdsOfDescendants: () => ['loop', 'card'],
        getBlockName: (id) => ({loop: 'fluent-cart/shopapp-product-loop', card: 'fluent-cart/product-card'})[id] || '',
        getBlockParentsByBlockName: () => ['products'],
        getBlockOrder: (id) => id === 'loop' ? ['card'] : [],
        getBlockAttributes: () => ({template_version: 1, card_layout: cardLayout}),
    };
    globalThis.wp = {
        blocks: {registerBlockType: (name, settings) => {edit = settings.edit;}},
        data: {useSelect: (read) => read(() => store), useDispatch: () => ({__unstableMarkNextChangeAsNotPersistent: markNotPersistent})},
        element: {useEffect: (callback) => callback(), useState: (value) => [value, () => {}], useMemo: (callback) => callback(), createContext: React.createContext, Component: React.Component},
        blockEditor: {useBlockProps: (props) => props, InnerBlocks: Object.assign(() => null, {ButtonBlockAppender: () => null}), BlockContextProvider: () => null, InspectorControls: () => null},
        components: {},
        i18n: {__: (text) => text},
    };
    await import('@/BlockEditor/ShopApp/ShopAppBlockEditor.jsx');
});

beforeEach(() => {
    markNotPersistent = vi.fn();
});

describe('Products block View Mode with a grid-only card', () => {
    it('shows a saved list view as a grid, without an undo step', () => {
        cardLayout = 'floating';
        expect(run({view_mode: 'list'})).toHaveBeenCalledWith({view_mode: 'grid'});
        expect(markNotPersistent).toHaveBeenCalledTimes(1);
    });

    it('leaves the view mode alone for other layouts, or when it is already grid', () => {
        cardLayout = 'compact';
        expect(run({view_mode: 'list'})).not.toHaveBeenCalled();
        cardLayout = 'floating';
        expect(run({view_mode: 'grid'})).not.toHaveBeenCalled();
        expect(markNotPersistent).not.toHaveBeenCalled();
    });
});

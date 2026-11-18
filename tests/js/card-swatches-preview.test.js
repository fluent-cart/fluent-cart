import {beforeAll, describe, expect, it} from 'vitest';
import React from 'react';

let findCardSwatches;

beforeAll(async () => {
    // The block reads the editor globals when it loads.
    globalThis.window = {};
    globalThis.wp = {
        element: React,
        blockEditor: {useBlockProps: () => ({})},
        data: {useSelect: () => null, select: () => null},
    };
    ({findCardSwatches} = await import('@/BlockEditor/ShopApp/InnerBlocks/ProductButtonBlock.jsx'));
});

describe('card button preview scope', () => {
    it.each(['fluent-cart/shopapp-product-loop', 'fluent-cart/product-carousel-loop', 'fluent-cart/product-template'])(
        'uses swatches only in its own %s template', (name) => {
            const attributes = {own: {simple_enabled: false}, other: {simple_enabled: true}};
            const editor = {
                getBlockName: id => ({page: 'core/group', card: name, inner: 'core/group', own: 'fluent-cart/product-swatches', other: 'fluent-cart/product-swatches'})[id],
                getClientIdsOfDescendants: ([id]) => id === 'page' ? ['other', 'card', 'own'] : ['inner', 'own'],
                getBlockAttributes: id => attributes[id],
            };
            expect(findCardSwatches(editor, ['page', 'card', 'inner'])).toEqual([attributes.own]);
        }
    );

    it('does not borrow swatches from another loop when its own card has none', () => {
        const editor = {
            getBlockName: id => id === 'card' ? 'fluent-cart/shopapp-product-loop' : 'fluent-cart/product-swatches',
            getClientIdsOfDescendants: ([id]) => id === 'page' ? ['other'] : [],
            getBlockAttributes: () => ({simple_enabled: true}),
        };
        expect(findCardSwatches(editor, ['page', 'card'])).toEqual([]);
    });
});

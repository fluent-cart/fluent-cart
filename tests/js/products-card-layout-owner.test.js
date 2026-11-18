import {afterEach, beforeEach, describe, expect, it} from 'vitest';
import {cardLayoutName, layoutOwnedByProducts, productsLayoutCard} from '@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets';

// A stand-in block editor store. Products holds a featured card in a group, nearer the top than the
// loop's card, and a loop with a legacy card before the current one.
const tree = {
    products: {name: 'fluent-cart/products', parent: null},
    group: {name: 'core/group', parent: 'products'},
    featured: {name: 'fluent-cart/product-card', parent: 'group', attributes: {template_version: 1}},
    container: {name: 'fluent-cart/shopapp-product-container', parent: 'products'},
    loop: {name: 'fluent-cart/shopapp-product-loop', parent: 'container'},
    legacy: {name: 'fluent-cart/product-card', parent: 'loop', attributes: {}},
    card: {name: 'fluent-cart/product-card', parent: 'loop', attributes: {template_version: 1}},
    title: {name: 'fluent-cart/shopapp-product-title', parent: 'card'},
    carousel: {name: 'fluent-cart/product-carousel', parent: null},
    carouselLoop: {name: 'fluent-cart/product-carousel-loop', parent: 'carousel'},
    carouselCard: {name: 'fluent-cart/product-card', parent: 'carouselLoop', attributes: {template_version: 1}},
    loose: {name: 'fluent-cart/product-card', parent: null, attributes: {template_version: 1}},
    // A Products block with no loop of its own, holding another Products block in a group.
    outer: {name: 'fluent-cart/products', parent: null},
    outerGroup: {name: 'core/group', parent: 'outer'},
    inner: {name: 'fluent-cart/products', parent: 'outerGroup'},
    innerLoop: {name: 'fluent-cart/shopapp-product-loop', parent: 'inner'},
    innerCard: {name: 'fluent-cart/product-card', parent: 'innerLoop', attributes: {template_version: 1}},
};
const children = (id) => Object.keys(tree).filter((key) => tree[key].parent === id);
const parents = (id) => {
    const list = [];
    for (let at = tree[id].parent; at; at = tree[at].parent) list.unshift(at);
    return list;
};
const editor = {
    getBlockName: (id) => tree[id]?.name || null,
    getBlockAttributes: (id) => tree[id]?.attributes || {},
    getBlockOrder: (id) => children(id),
    getBlockRootClientId: (id) => tree[id]?.parent || null,
    // Breadth-first, like Gutenberg's selector: every child first, then their descendants.
    getClientIdsOfDescendants: (root) => {
        const out = [];
        let level = children(root);
        while (level.length) {
            out.push(...level);
            level = level.flatMap(children);
        }
        return out;
    },
    getBlockParentsByBlockName: (id, name, ascending) => {
        const found = parents(id).filter((at) => tree[at].name === name);
        return ascending ? found.reverse() : found;
    },
};

describe('Card layout settings belong to the Products block', () => {
    beforeEach(() => {
        globalThis.window = {fluent_cart_product_card_data: {card_presets: {
            classic: {}, compact: {}, masonry: {builder_only: true},
        }}};
    });
    afterEach(() => { delete globalThis.window; });

    it('finds the current Product Card in the Products loop, not a card nearer the top or a legacy one', () => {
        expect(productsLayoutCard(editor, 'products')).toBe('card');
        expect(productsLayoutCard(editor, 'carousel')).toBe(null);
        // A nested Products block's loop belongs to that block, not the outer one.
        expect(productsLayoutCard(editor, 'outer')).toBe(null);
        expect(productsLayoutCard(editor, 'inner')).toBe('innerCard');
        expect(layoutOwnedByProducts(editor, 'innerCard')).toBe('inner');
    });

    it('moves only that card\'s layout to the Products block', () => {
        expect(layoutOwnedByProducts(editor, 'card')).toBe('products');
        // Other cards keep the settings on the card.
        expect(layoutOwnedByProducts(editor, 'featured')).toBe(null);
        expect(layoutOwnedByProducts(editor, 'legacy')).toBe(null);
        expect(layoutOwnedByProducts(editor, 'carouselCard')).toBe(null);
        expect(layoutOwnedByProducts(editor, 'loose')).toBe(null);
    });

    it('reads the layout the card shows: a builder-only or unknown one is Classic', () => {
        expect(cardLayoutName({card_layout: 'compact'})).toBe('compact');
        expect(cardLayoutName({card_layout: 'masonry'})).toBe('classic');
        expect(cardLayoutName({card_layout: 'nope'})).toBe('classic');
        expect(cardLayoutName({})).toBe('classic');
    });
});

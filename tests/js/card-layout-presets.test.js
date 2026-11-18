import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {applyCardPreset, detectCardPreset, isGridOnlyLayout} from '@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets';

const block = ([name, attributes = {}, children = []]) => ({name, attributes: {...attributes}, innerBlocks: children.map(block)});
const preset = name => ({template: [['core/group', {className: 'fct-card-layout fct-card-layout--' + name}, [
    ['core/group', {className: 'media'}, [['fluent-cart-wishlist/wishlist-button']]],
    ['core/group', {className: 'details'}, [
        ['fluent-cart/product-swatches', {max_visible: 3}],
        ['fluent-cart/shopapp-product-buttons'],
    ]],
]]]});
let current;
let replace;

describe('product card layout selection', () => {
    beforeEach(() => {
        globalThis.window = {};
        window.fluent_cart_products_inner_blocks = {card_presets: {compact: preset('compact'), floating: preset('floating'), framed: preset('framed')}};
        current = [];
        replace = vi.fn((id, blocks) => {current = blocks;});
        globalThis.wp = {
            blocks: {getBlockType: () => ({}), createBlocksFromInnerBlocksTemplate: nodes => nodes.map(block), cloneBlock: value => structuredClone(value)},
            data: {select: () => ({getBlocks: () => current}), dispatch: () => ({replaceInnerBlocks: replace})},
        };
    });
    afterEach(() => {delete globalThis.wp; delete globalThis.window;});

    it('uses Product Card as the preset root and keeps child customizations', () => {
        const card = {attributes: {template_version: 1, card_layout: 'classic'}};
        const replaceCard = vi.fn((id, next) => {
            Object.assign(card, next);
            current = next.innerBlocks;
        });
        wp.data.select = () => ({getBlocks: () => current, getBlock: () => card});
        wp.data.dispatch = () => ({replaceInnerBlocks: replace, replaceBlocks: replaceCard});
        applyCardPreset('compact', 'card');
        expect(current.map(item => item.attributes.className)).toEqual(['media', 'details']);
        expect(detectCardPreset(current, card.attributes.card_layout)).toBe('compact');
        current[1].innerBlocks[0].attributes.group_ids = [42];
        applyCardPreset('framed', 'card');
        expect(card.attributes.card_layout).toBe('framed');
        expect(current[1].innerBlocks[0].attributes.group_ids).toEqual([42]);
        expect(detectCardPreset(current, card.attributes.card_layout)).toBe('framed');
    });

    it('keeps current templates unchanged until explicitly selected', () => {
        current = [block(['core/paragraph', {content: 'Custom card'}])];
        expect(detectCardPreset(current)).toBe('custom');
        expect(replace).not.toHaveBeenCalled();
        expect(applyCardPreset('missing', 'loop')).toBe(false);
        expect(current[0].attributes.content).toBe('Custom card');
    });
    it('applies each layout and avoids replacing an already selected preset', () => {
        for (const name of ['compact', 'floating', 'framed']) {
            expect(applyCardPreset(name, 'loop')).toBe(true);
            expect(detectCardPreset(current)).toBe(name);
            expect(applyCardPreset(name, 'loop')).toBe(false);
        }
        expect(replace).toHaveBeenCalledTimes(3);
    });
    it('preserves swatch, button and wishlist settings across switches', () => {
        applyCardPreset('compact', 'loop');
        current[0].innerBlocks[0].innerBlocks[0].attributes = {buttonPosition: 'image-overlay-top-left', icon_type: 'heart'};
        current[0].innerBlocks[1].innerBlocks[0].attributes = {max_visible: 6, group_ids: [4], simple_enabled: false};
        current[0].innerBlocks[1].innerBlocks[1].attributes = {style: {border: {radius: '8px'}}};
        applyCardPreset('framed', 'loop');
        expect(current[0].innerBlocks[0].innerBlocks[0].attributes.buttonPosition).toBe('image-overlay-top-left');
        expect(current[0].innerBlocks[1].innerBlocks[0].attributes).toEqual({max_visible: 6, group_ids: [4], simple_enabled: false});
        expect(current[0].innerBlocks[1].innerBlocks[1].attributes.style.border.radius).toBe('8px');
    });
    it('omits an unavailable wishlist block without losing preset recognition', () => {
        wp.blocks.getBlockType = name => name.includes('wishlist') ? undefined : {};
        applyCardPreset('compact', 'loop');
        expect(current[0].innerBlocks[0].innerBlocks).toHaveLength(0);
        expect(detectCardPreset(current)).toBe('compact');
    });
    it('leaves out a block its module does not register, and still recognises the layout', () => {
        window.fluent_cart_products_inner_blocks.card_presets.compact.template[0][2][0][2].push(['fluent-cart/sold-out-badge']);
        wp.blocks.getBlockType = name => name === 'fluent-cart/sold-out-badge' ? undefined : {};
        // Like WordPress's createBlock, an unregistered name becomes a core/missing placeholder.
        const create = nodes => nodes.map(([name, attributes = {}, children = []]) => wp.blocks.getBlockType(name)
            ? {name, attributes: {...attributes}, innerBlocks: create(children)}
            : {name: 'core/missing', attributes: {originalName: name}, innerBlocks: []});
        wp.blocks.createBlocksFromInnerBlocksTemplate = create;
        expect(applyCardPreset('compact', 'loop')).toBe(true);
        const names = [];
        const walk = blocks => blocks.forEach(item => {names.push(item.name); walk(item.innerBlocks);});
        walk(current);
        expect(names).not.toContain('core/missing');
        expect(detectCardPreset(current)).toBe('compact');
        expect(applyCardPreset('compact', 'loop')).toBe(false);
    });
    it('keeps optional Quick View settings and detects structural customizations', () => {
        applyCardPreset('compact', 'loop');
        current[0].innerBlocks[1].innerBlocks.push(block(['fluent-cart/product-quick-view', {position: 'image'}]));
        applyCardPreset('floating', 'loop');
        expect(current[0].innerBlocks[1].innerBlocks.at(-1).attributes.position).toBe('image');
        expect(detectCardPreset(current)).toBe('floating');
        current[0].innerBlocks[1].innerBlocks.unshift(block(['core/paragraph', {content: 'Extra'}]));
        expect(detectCardPreset(current)).toBe('custom');
    });
    it('applies the settings a layout depends on, its Quick View once, and its Products grid', () => {
        const updates = [];
        wp.data.select = () => ({getBlocks: () => current, getBlockParentsByBlockName: () => ['products']});
        wp.data.dispatch = () => ({replaceInnerBlocks: replace, updateBlockAttributes: (id, attributes) => updates.push([id, attributes])});
        const framed = window.fluent_cart_products_inner_blocks.card_presets.framed;
        framed.template[0][2][1][2].push(['fluent-cart/product-quick-view', {position: 'button'}]);
        framed.attributes = {'fluent-cart/product-swatches': {max_visible: 2, show_label: true}, 'fluent-cart/product-quick-view': {position: 'button'}};
        framed.products = {view_mode: 'grid', product_box_grid_size: 3};
        applyCardPreset('compact', 'loop');
        current[0].innerBlocks[1].innerBlocks[0].attributes = {max_visible: 6, group_ids: [4]};
        current[0].innerBlocks[1].innerBlocks.push(block(['fluent-cart/product-quick-view', {position: 'image', show_on: 'hover'}]));
        applyCardPreset('framed', 'loop');
        // The layout's own settings win; the rest of the block's settings are kept.
        expect(current[0].innerBlocks[1].innerBlocks[0].attributes).toEqual({max_visible: 2, group_ids: [4], show_label: true});
        const quickViews = current[0].innerBlocks[1].innerBlocks.filter(item => item.name === 'fluent-cart/product-quick-view');
        expect(quickViews).toHaveLength(1);
        expect(quickViews[0].attributes).toEqual({position: 'button', show_on: 'hover'});
        expect(updates).toEqual([['products', {view_mode: 'grid', product_box_grid_size: 3}]]);
        expect(detectCardPreset(current)).toBe('framed');
    });
    it('rebuilds from the new layout and carries only settings the merchant changed', () => {
        const presets = window.fluent_cart_products_inner_blocks.card_presets;
        presets.compact.template[0][2][1][2][0][1] = {max_visible: 3, show_group_name: true};
        applyCardPreset('compact', 'loop');
        // Compact's own starting values are not choices; they stay behind.
        applyCardPreset('floating', 'loop');
        expect(current[0].innerBlocks[1].innerBlocks[0].attributes).toEqual({max_visible: 3});
        // A value the merchant changed follows them.
        current[0].innerBlocks[1].innerBlocks[0].attributes = {max_visible: 5};
        applyCardPreset('framed', 'loop');
        expect(current[0].innerBlocks[1].innerBlocks[0].attributes).toEqual({max_visible: 5});
    });
    it('drops a layout\'s own Quick View when switching to a layout without one', () => {
        const presets = window.fluent_cart_products_inner_blocks.card_presets;
        presets.framed.template[0][2][1][2].push(['fluent-cart/product-quick-view', {position: 'button'}]);
        applyCardPreset('framed', 'loop');
        applyCardPreset('compact', 'loop');
        const names = [];
        const walk = blocks => blocks.forEach(item => {names.push(item.name); walk(item.innerBlocks);});
        walk(current);
        expect(names).not.toContain('fluent-cart/product-quick-view');
    });
    it('keeps a Quick View the merchant added beside the one the layout brought', () => {
        const presets = window.fluent_cart_products_inner_blocks.card_presets;
        presets.framed.template[0][2][1][2].push(['fluent-cart/product-quick-view', {position: 'button'}]);
        presets.floating.template[0][2][1][2].push(['fluent-cart/product-quick-view', {position: 'button'}]);
        const quickViews = () => {
            const found = [];
            const walk = blocks => blocks.forEach(item => {if (item.name === 'fluent-cart/product-quick-view') found.push(item); walk(item.innerBlocks);});
            walk(current);
            return found;
        };
        applyCardPreset('framed', 'loop');
        current[0].innerBlocks[1].innerBlocks.push(block(['fluent-cart/product-quick-view', {position: 'image'}]));
        // To a layout with its own Quick View: the layout's one plus the merchant's.
        applyCardPreset('floating', 'loop');
        expect(quickViews()).toHaveLength(2);
        expect(quickViews().at(-1).attributes.position).toBe('image');
        // To a layout without one: only the layout's Quick View goes.
        applyCardPreset('compact', 'loop');
        expect(quickViews()).toHaveLength(1);
        expect(quickViews()[0].attributes.position).toBe('image');
    });
    it('still recognises a layout whose rating was removed, as with Quick View', () => {
        window.fluent_cart_products_inner_blocks.card_presets = {
            classic: {template: [['fluent-cart/product-image'], ['fluent-cart/product-title'], ['fluent-cart/product-rating'], ['fluent-cart/shopapp-product-buttons']]},
        };
        // Cards made before the rating came back hold no rating block.
        expect(detectCardPreset([block(['fluent-cart/product-image']), block(['fluent-cart/product-title']), block(['fluent-cart/shopapp-product-buttons'])])).toBe('classic');
        expect(detectCardPreset([block(['fluent-cart/product-image']), block(['fluent-cart/product-title']), block(['fluent-cart/product-rating']), block(['fluent-cart/shopapp-product-buttons'])])).toBe('classic');
    });
    it('still recognises a Classic card saved before its badges were added', () => {
        window.fluent_cart_products_inner_blocks.card_presets = {
            classic: {template: [['fluent-cart/product-image', {}, [['fluent-cart/sale-badge'], ['fluent-cart/sold-out-badge']]], ['fluent-cart/product-title']]},
        };
        expect(detectCardPreset([block(['fluent-cart/product-image']), block(['fluent-cart/product-title'])])).toBe('classic');
        expect(detectCardPreset([block(['fluent-cart/product-image', {}, [['fluent-cart/sale-badge'], ['fluent-cart/sold-out-badge']]]), block(['fluent-cart/product-title'])])).toBe('classic');
    });
    it('switches back to the flat Classic card and keeps Quick View', () => {
        window.fluent_cart_products_inner_blocks.card_presets = {
            classic: {template: [['fluent-cart/product-image'], ['fluent-cart/product-title'], ['fluent-cart/shopapp-product-buttons']]},
            ...window.fluent_cart_products_inner_blocks.card_presets,
        };
        current = [block(['fluent-cart/product-image']), block(['fluent-cart/product-title']), block(['fluent-cart/shopapp-product-buttons'])];
        expect(detectCardPreset(current)).toBe('classic');
        applyCardPreset('compact', 'loop');
        current[0].innerBlocks[1].innerBlocks.push(block(['fluent-cart/product-quick-view', {position: 'image'}]));
        expect(applyCardPreset('classic', 'loop')).toBe(true);
        // On the picture, so it goes into the Classic card's image block.
        expect(current.map(item => item.name)).toEqual(['fluent-cart/product-image', 'fluent-cart/product-title', 'fluent-cart/shopapp-product-buttons']);
        expect(current[0].innerBlocks.map(item => item.name)).toEqual(['fluent-cart/product-quick-view']);
        expect(current[0].innerBlocks[0].attributes.position).toBe('image');
        expect(detectCardPreset(current)).toBe('classic');
    });
    // A Products block with a grid/list switch (and, optionally, one in a nested Products block).
    const productsStore = (card, state, extra = {}) => {
        wp.blocks.createBlock = name => ({clientId: 'restored', name, attributes: {}, innerBlocks: []});
        wp.data.select = () => ({
            getBlocks: () => current,
            getBlock: () => card,
            getBlockName: id => id === 'switcher' || id === 'nested' ? 'fluent-cart/shopapp-product-view-switcher' : (/^loop/.test(id) ? 'fluent-cart/shopapp-product-loop' : (/^card/.test(id) ? 'fluent-cart/product-card' : '')),
            // The card being edited sits in the first loop, as the Products block's own card does.
            getClientIdsOfDescendants: () => ['loop', 'loop-later', ...state.descendants],
            // 'nested' sits in a Products block inside this one, so it is not this block's.
            getBlockParentsByBlockName: id => id === 'nested' ? ['inner-products', 'products'] : ['products'],
            getBlockRootClientId: id => id === 'card' ? (state.cardLoop || 'loop') : (id === 'card-first' ? 'loop' : 'container'),
            getBlockOrder: id => id === 'loop' ? [state.firstCard || 'card'] : (id === 'loop-later' ? ['card'] : []),
            getBlockAttributes: () => ({template_version: 1}),
            getBlockIndex: () => 0,
            ...extra,
        });
        wp.data.dispatch = () => ({
            replaceBlocks: (id, next) => {Object.assign(card, next); current = next.innerBlocks;},
            updateBlockAttributes: () => {},
            removeBlocks: ids => {state.removed.push(...ids); state.descendants = state.descendants.filter(id => !ids.includes(id));},
            insertBlocks: state.inserted,
        });
    };
    it('removes the Products block\'s view switcher for a grid-only layout', () => {
        window.fluent_cart_products_inner_blocks.card_presets.floating.view_switcher = false;
        const card = {clientId: 'card', attributes: {template_version: 1, card_layout: 'compact'}};
        const state = {descendants: ['switcher', 'nested'], removed: [], inserted: vi.fn()};
        productsStore(card, state);
        const undoState = {};
        applyCardPreset('floating', 'card', undoState);
        expect(state.removed).toEqual(['switcher']);
        // Undo gets where the switch was, so it can put it back.
        expect(undoState.switches).toEqual([{block: card, parentId: 'container', index: 0}]);
        // Without a switcher there is nothing left to remove; leaving Floating puts one back in the Products block.
        applyCardPreset('framed', 'card');
        expect(state.inserted).toHaveBeenCalledTimes(1);
        expect(state.inserted.mock.calls[0].slice(1, 3)).toEqual([0, 'products']);
        applyCardPreset('floating', 'card');
        expect(state.removed).toEqual(['switcher']);
    });
    it('removes the switcher from a card that already has the grid-only layout', () => {
        window.fluent_cart_products_inner_blocks.card_presets.floating.view_switcher = false;
        const card = {clientId: 'card', attributes: {template_version: 1, card_layout: 'compact'}};
        const state = {descendants: ['switcher'], removed: [], inserted: vi.fn()};
        productsStore(card, state);
        applyCardPreset('floating', 'card');
        expect(state.removed).toEqual(['switcher']);
        // The card is Floating now and the switch is back (saved before this change, or added from the inserter).
        state.descendants = ['switcher'];
        expect(detectCardPreset(current, card.attributes.card_layout)).toBe('floating');
        // The layout already matched, but removing the switch is an edit the panel can undo.
        const undoState = {};
        expect(applyCardPreset('floating', 'card', undoState)).toBe(true);
        expect(state.removed).toEqual(['switcher', 'switcher']);
        expect(undoState.switches).toEqual([{block: card, parentId: 'container', index: 0}]);
    });
    it('leaves the shared switch when the card is not the one its Products block follows', () => {
        window.fluent_cart_products_inner_blocks.card_presets.floating.view_switcher = false;
        const card = {clientId: 'card', attributes: {template_version: 1, card_layout: 'compact'}};
        // A Classic card heads the first loop; Floating is picked on a card in a later loop.
        const state = {descendants: ['switcher'], removed: [], inserted: vi.fn(), cardLoop: 'loop-later', firstCard: 'card-first'};
        productsStore(card, state);
        const undoState = {};
        applyCardPreset('floating', 'card', undoState);
        expect(state.removed).toEqual([]);
        expect(undoState.switches).toEqual([]);
        expect(card.attributes.card_layout).toBe('floating');
    });
    it('leaves a locked switch in place', () => {
        window.fluent_cart_products_inner_blocks.card_presets.floating.view_switcher = false;
        const card = {clientId: 'card', attributes: {template_version: 1, card_layout: 'compact'}};
        const state = {descendants: ['switcher'], removed: [], inserted: vi.fn()};
        productsStore(card, state, {canRemoveBlock: () => false});
        const undoState = {};
        applyCardPreset('floating', 'card', undoState);
        expect(state.removed).toEqual([]);
        expect(undoState.switches).toEqual([]);
    });

    it('tells a grid-only layout from the others, so the Products block can drop its View Mode choice', () => {
        window.fluent_cart_products_inner_blocks.card_presets.floating.view_switcher = false;
        expect(isGridOnlyLayout({template_version: 1, card_layout: 'floating'})).toBe(true);
        expect(isGridOnlyLayout({template_version: 1, card_layout: 'compact'})).toBe(false);
        // A card the layouts do not cover reads as Classic, and no card at all keeps the choice.
        expect(isGridOnlyLayout({template_version: 1, card_layout: 'unknown'})).toBe(false);
        expect(isGridOnlyLayout(null)).toBe(false);
    });
});

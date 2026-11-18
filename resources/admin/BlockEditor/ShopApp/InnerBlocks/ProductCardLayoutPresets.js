const WISHLIST = 'fluent-cart-wishlist/wishlist-button';
const QUICK_VIEW = 'fluent-cart/product-quick-view';
const IMAGE_BLOCKS = ['fluent-cart/shopapp-product-image', 'fluent-cart/product-image'];
// Optional extras: a layout still counts as itself with or without them. Classic cards saved before
// their badges were added hold none.
const OPTIONAL = [WISHLIST, QUICK_VIEW, 'fluent-cart/product-rating', 'fluent-cart/sale-badge', 'fluent-cart/sold-out-badge'];

export const cardPresets = () => window.fluent_cart_product_card_data?.card_presets || window.fluent_cart_products_inner_blocks?.card_presets || {};

// The layout a card shows: a builder-only (Masonry) or unknown one opens as Classic.
export const cardLayoutName = (attributes) => {
    const preset = cardPresets()[attributes?.card_layout];
    return preset && !preset.builder_only ? attributes.card_layout : 'classic';
};

// A grid-only layout (Floating): the Products block offers no grid/list choice.
export const isGridOnlyLayout = (attributes) => !!attributes && cardPresets()[cardLayoutName(attributes)]?.view_switcher === false;

const PRODUCT_CARD = 'fluent-cart/product-card';
const PRODUCTS = 'fluent-cart/products';
const PRODUCTS_LOOP = 'fluent-cart/shopapp-product-loop';
const VIEW_SWITCHER = 'fluent-cart/shopapp-product-view-switcher';
const ACTION_CONTAINER = 'fluent-cart/shopapp-product-action-container';

// Blocks of a name inside a Products block, leaving out those in a Products block nested in it: they belong to that one.
const ownBlocks = (editor, productsId, name) => editor.getClientIdsOfDescendants(productsId).filter((id) => editor.getBlockName(id) === name
    && (editor.getBlockParentsByBlockName(id, PRODUCTS, true) || [])[0] === productsId);

// The card a Products block shows the Layout panel for: a current (template_version 1) Product Card placed
// straight in its product loop. A card elsewhere in the block, or a legacy one, keeps its own panel.
// The selector gets the id itself, not an array, so its cache holds between store changes.
export const productsLayoutCard = (editor, productsId) => {
    const [loopId] = ownBlocks(editor, productsId, PRODUCTS_LOOP);
    return (loopId && editor.getBlockOrder(loopId).find((id) => editor.getBlockName(id) === PRODUCT_CARD
        && editor.getBlockAttributes(id)?.template_version === 1)) || null;
};

// The Products block that shows this card's Layout panel instead of the card, or null.
export const layoutOwnedByProducts = (editor, clientId) => {
    if (editor.getBlockName(editor.getBlockRootClientId(clientId)) !== PRODUCTS_LOOP) {
        return null;
    }
    const [productsId] = editor.getBlockParentsByBlockName(clientId, PRODUCTS, true) || [];
    return productsId && productsLayoutCard(editor, productsId) === clientId ? productsId : null;
};

// Blocks from inactive modules (wishlist, stock badges) would otherwise become "missing block" placeholders.
export const availableTemplate = (nodes) => nodes.flatMap(([name, attributes = {}, children = []]) => {
    if (!wp.blocks.getBlockType(name)) return [];
    return [[name, {...attributes}, availableTemplate(children)]];
});

const matches = (blocks, template) => {
    const actual = blocks.filter(block => !OPTIONAL.includes(block.name));
    const expected = template.filter(([name]) => !OPTIONAL.includes(name));
    return actual.length === expected.length && expected.every(([name, attributes = {}, children = []], index) => {
        const block = actual[index];
        return block.name === name
            && (!attributes.className || attributes.className === block.attributes?.className)
            && matches(block.innerBlocks || [], children);
    });
};

export const cardTemplate = (preset) => {
    const template = preset?.template || [];
    return template[0]?.[1]?.className?.includes('fct-card-layout--') ? template[0][2] : template;
};

export const detectCardPreset = (blocks, layout) => Object.entries(cardPresets())
    .find(([name, preset]) => (!layout || layout === name) && matches(blocks, availableTemplate(layout ? cardTemplate(preset) : preset.template)))?.[0] || 'custom';

const walk = (blocks, callback) => blocks.forEach(block => {
    callback(block);
    walk(block.innerBlocks || [], callback);
});

const countQuickViews = (nodes) => nodes.reduce((count, [name, , children = []]) =>
    count + (name === QUICK_VIEW ? 1 : 0) + countQuickViews(children), 0);

// undoState.switches gets the grid/list switches the layout removed, and undoState.added the one it brought
// back, for the Layout panel's Undo.
export function applyCardPreset(name, clientId, undoState = {}) {
    const preset = cardPresets()[name];
    if (!preset || !clientId) return false;
    const store = wp.data.select('core/block-editor');
    const card = store.getBlock?.(clientId);
    const layout = card?.attributes?.template_version === 1 ? card.attributes.card_layout : undefined;
    const template = (value) => layout ? cardTemplate(value) : value.template;
    const current = store.getBlocks(clientId);
    const [productsId] = store.getBlockParentsByBlockName?.(clientId, PRODUCTS, true) || [];
    // A grid-only layout hides the grid/list switch even when the card already has it, but only on the card
    // its Products block follows: another card in the block does not decide the shared switch.
    const switchOwnerId = preset.view_switcher === false ? layoutOwnedByProducts(store, clientId) : null;
    undoState.switches = switchOwnerId ? removeViewSwitchers(switchOwnerId) : [];
    // Leaving a grid-only layout brings the switch back, if the Products block has none.
    const leftGridOnly = layout && layout !== name && cardPresets()[layout]?.view_switcher === false;
    const restoreOwnerId = preset.view_switcher !== false && leftGridOnly ? layoutOwnedByProducts(store, clientId) : null;
    undoState.added = restoreOwnerId ? restoreViewSwitcher(restoreOwnerId) : [];
    // Removing a switch is an edit the panel can undo, even when the layout itself already matched.
    if (detectCardPreset(current, layout) === name) return undoState.switches.length > 0;

    // Like the review layouts: rebuild from the new template and carry only what the merchant changed,
    // i.e. values that differ from what the layout being left set (or the block's default).
    const leaving = cardPresets()[detectCardPreset(current, layout)];
    const started = new Map();
    if (leaving) walk(wp.blocks.createBlocksFromInnerBlocksTemplate(availableTemplate(template(leaving))), block => {
        if (!started.has(block.name)) started.set(block.name, block.attributes);
    });
    const changed = block => {
        if (!leaving) return block.attributes;
        const expected = started.get(block.name) || {};
        const defaults = wp.blocks.getBlockType(block.name)?.attributes || {};
        return Object.fromEntries(Object.entries(block.attributes || {}).filter(([key, value]) =>
            JSON.stringify(value) !== JSON.stringify(key in expected ? expected[key] : defaults[key]?.default)));
    };
    const saved = new Map();
    const quickViews = [];
    walk(current, block => {
        if (block.name === QUICK_VIEW) quickViews.push(block);
        if (block.name !== 'core/group') {
            if (!saved.has(block.name)) saved.set(block.name, []);
            saved.get(block.name).push(changed(block));
        }
    });
    const blocks = wp.blocks.createBlocksFromInnerBlocksTemplate(availableTemplate(template(preset)));
    walk(blocks, block => {
        const attributes = saved.get(block.name)?.shift();
        if (attributes) block.attributes = {...block.attributes, ...attributes};
        // Only the settings the layout's look depends on override what the block already had.
        const required = preset.attributes?.[block.name];
        if (required) block.attributes = {...block.attributes, ...required};
    });
    // The first Quick Views are the ones the layout being left brought, or ones the new layout's own took the
    // settings of; any beyond those the merchant added, and they stay. A grouped layout keeps them in its
    // details panel; the flat Default card ends with them.
    const owned = Math.max(leaving ? countQuickViews(template(leaving)) : 0, countQuickViews(template(preset)));
    const added = quickViews.slice(owned);
    if (wp.blocks.getBlockType(QUICK_VIEW) && added.length) {
        const details = layout ? blocks.find(block => block.attributes?.className === 'fct-card-layout-details') : (blocks[0]?.name === 'core/group' ? blocks[0].innerBlocks[1] : null);
        // Ones on the picture go back into the new layout's image block, since placement follows the parent.
        let image = null;
        walk(blocks, block => {
            if (!image && IMAGE_BLOCKS.includes(block.name)) image = block;
        });
        added.forEach(block => {
            const onImage = block.attributes?.position === 'image' && image;
            (onImage ? image.innerBlocks : (details ? details.innerBlocks : blocks)).push(wp.blocks.cloneBlock(block));
        });
    }
    const editor = wp.data.dispatch('core/block-editor');
    if (layout) {
        editor.replaceBlocks(clientId, {...card, attributes: {...card.attributes, card_layout: name}, innerBlocks: blocks});
    } else {
        editor.replaceInnerBlocks(clientId, blocks, false);
    }
    // A layout can also set its Products block, e.g. the grid it is designed for.
    if (preset.products && productsId) editor.updateBlockAttributes(productsId, preset.products);
    return true;
}

// Puts a grid/list switch first in the Products block's action container when it has none, or first in the
// block itself when it has no container (the shop page's default); returns its id.
function restoreViewSwitcher(productsId) {
    const store = wp.data.select('core/block-editor');
    if (ownBlocks(store, productsId, VIEW_SWITCHER).length) return [];
    const [containerId = productsId] = ownBlocks(store, productsId, ACTION_CONTAINER);
    const block = wp.blocks.createBlock(VIEW_SWITCHER);
    wp.data.dispatch('core/block-editor').insertBlocks(block, 0, containerId, false);
    return [block.clientId];
}

// Removes the Products block's grid/list switch, where it can be removed, and returns where each one was
// so Undo can put it back. A locked switch stays; the frontend still hides it through the card's layout.
function removeViewSwitchers(productsId) {
    const store = wp.data.select('core/block-editor');
    const removable = ownBlocks(store, productsId, VIEW_SWITCHER).filter((id) => store.canRemoveBlock?.(id) !== false);
    const removed = removable.map((id) => ({block: store.getBlock(id), parentId: store.getBlockRootClientId(id), index: store.getBlockIndex(id)}));
    if (removed.length) wp.data.dispatch('core/block-editor').removeBlocks(removable, false);
    return removed;
}

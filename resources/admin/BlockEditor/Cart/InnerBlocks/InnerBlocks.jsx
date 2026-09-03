import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CartItemsBlock from "@/BlockEditor/Cart/InnerBlocks/CartItemsBlock";
import CartTotalBlock from "@/BlockEditor/Cart/InnerBlocks/CartTotalBlock";
import CartCheckoutButtonBlock from "@/BlockEditor/Cart/InnerBlocks/CartCheckoutButtonBlock";

const componentsMap = {
    CartItemsBlock,
    CartTotalBlock,
    CartCheckoutButtonBlock,
};

const {registerBlockType} = wp.blocks;

// Guarded: this file registers blocks at import time, so an unresolved global
// used to throw before any registerBlockType ran and took the whole editor
// with it. Bailing leaves the cart blocks unregistered instead.
const blockEditorData = window['fluent_cart_cart_inner_blocks'] || {};
const innerBlocks = blockEditorData.blocks || [];

// PHP owns the block list — slug, title, icon and supports all come from
// getInnerBlocks() — so the two sides cannot drift. JSX only supplies edit().
innerBlocks.forEach(block => {
    const Component = componentsMap[block.component];

    registerBlockType(block.slug, {
        apiVersion: 3,
        category: "fluent-cart",
        title: block.title,
        name: block.slug,
        icon: block.icon || null,
        parent: block.parent || ['fluent-cart/cart'],
        edit: Component?.edit || (() => blocktranslate("No edit found")),
        save: Component?.save || (() => null),
        supports: block?.supports || {},
        // PHP first: the render callback reads these, so a mismatch would mean
        // the editor saving a key the server never looks at.
        attributes: block?.attributes || Component?.attributes || {},
    });
});

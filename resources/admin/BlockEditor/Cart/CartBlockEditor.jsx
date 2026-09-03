import {CartPage} from "@/BlockEditor/Icons";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;

const blockEditorData = window.fluent_cart_cart_data;

// The three regions CartRenderer composes on the front end, in the same order.
const DEFAULT_TEMPLATE = [
    ['fluent-cart/cart-items'],
    ['fluent-cart/cart-total'],
    ['fluent-cart/cart-checkout-button'],
];

// The cart regions plus the core blocks an editor is likely to place around
// them — a heading above the items, a note before checkout, columns for a
// side-by-side layout. The container renders whatever inner blocks it is
// handed, so this list is about guiding the inserter, not about safety: the
// regions are confined to this block by their own `parent` declaration in
// InnerBlocks::register().
const ALLOWED_BLOCKS = [
    'fluent-cart/cart-items',
    'fluent-cart/cart-total',
    'fluent-cart/cart-checkout-button',
    'core/heading',
    'core/paragraph',
    'core/columns',
    'core/separator',
    'core/spacer',
    'core/buttons',
];

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: CartPage,
    },
    category: "fluent-cart",
    attributes: {},
    edit: () => {
        const blockProps = useBlockProps({
            className: 'fluent-cart-cart-block'
        });

        return (
            <div {...blockProps}>
                <InnerBlocks
                    template={DEFAULT_TEMPLATE}
                    templateLock={false}
                    allowedBlocks={ALLOWED_BLOCKS}
                />
            </div>
        );
    },

    save: () => {
        const blockProps = useBlockProps.save({
            className: 'fluent-cart-cart-block'
        });

        return (
            <div {...blockProps}>
                <InnerBlocks.Content/>
            </div>
        );
    },
});

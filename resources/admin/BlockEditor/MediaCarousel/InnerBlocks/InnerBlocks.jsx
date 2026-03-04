import ProductImageBlockEditor from './ProductImageBlockEditor.jsx';
import MediaCarouselLoopBlock from "./MediaCarouselLoopBlock.jsx";
import CarouselControlsBlock from './CarouselControlsBlock.jsx';
import CarouselPaginationBlock from './CarouselPaginationBlock.jsx';
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {registerBlockType} = wp.blocks;
const blockEditorData = window['fluent_cart_media_carousel_inner_blocks'];
const {InnerBlocks, useBlockProps} = wp.blockEditor;

const componentsMap = {
    ProductImageBlockEditor,
    MediaCarouselLoopBlock,
    CarouselControlsBlock,
    CarouselPaginationBlock,
};

blockEditorData.blocks.forEach(block => {
    const Component = componentsMap[block.component];
    const parent = [];
    //merge block.parent and Component.parent if exists
    if (block.parent) {
        parent.push(...block.parent);
    }
    if (Component?.parent) {
        parent.push(...Component.parent);
    }

    registerBlockType(block.slug, {
        apiVersion: 2,
        category: "product-elements",
        title: block.title,
        name: block.slug,
        icon: block.icon || null,
        parent: parent.length > 0 ? parent : null,
        edit: Component?.edit || (() => blocktranslate("No edit found")),
        save: Component?.save || (() => null),
        supports: Component?.supports || {},
        usesContext: Component?.usesContext || [],
        attributes: Component?.attributes || {},
    });
});

registerBlockType('fluent-cart/product-paginator', {
    title: blocktranslate('Paginator'),
    icon: 'screenoptions',
    parent: ['fluent-cart/media_carousel', 'core/column'],
    category: 'layout',
    attributes: {},
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fluent-cart-product-paginator',
        });

        return (
            <div {...blockProps} >
                <div>
                    <InnerBlocks allowedBlocks={['core/paragraph']} />
                </div>
            </div>
        );
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps} className="fluent-cart-product-paginator">
                <InnerBlocks.Content/>
            </div>
        );
    },
    usesContext: [
        'fluent-cart/query_type',
        'fluent-cart/carousel_settings',
        'fluent-cart/product_id',
        'fluent-cart/variation_ids',
        'fluent-cart/has_controls',
        'fluent-cart/has_pagination'
    ]
});

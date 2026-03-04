import ProductTitleBlockEditor from '@/BlockEditor/ShopApp/InnerBlocks/ProductTitleBlock.jsx';
import ProductImageBlockEditor from '@/BlockEditor/ShopApp/InnerBlocks/ProductImageBlock.jsx';
import ProductPriceBlockEditor from '@/BlockEditor/ShopApp/InnerBlocks/ProductPriceBlock.jsx';
import ProductButtonBlockEditor from '@/BlockEditor/ShopApp/InnerBlocks/ProductExcerptBlock.jsx';
import ProductExcerptBlockEditor from '@/BlockEditor/ShopApp/InnerBlocks/ProductButtonBlock.jsx';
import ProductCarouselLoopBlock from "./ProductCarouselLoopBlock.jsx";
import CarouselControlsBlock from './CarouselControlsBlock.jsx';
import CarouselPaginationBlock from './CarouselPaginationBlock.jsx';
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {registerBlockType} = wp.blocks;
const blockEditorData = window['fluent_cart_product_carousel_inner_blocks'];
const {InnerBlocks, useBlockProps} = wp.blockEditor;

const componentsMap = {
    ProductTitleBlockEditor,
    ProductPriceBlockEditor,
    ProductImageBlockEditor,
    ProductButtonBlockEditor,
    ProductExcerptBlockEditor,
    ProductCarouselLoopBlock,
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
        apiVersion: 3,
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
    apiVersion: 3,
    title: blocktranslate('Paginator'),
    icon: 'screenoptions',
    parent: ['fluent-cart/product_carousel', 'core/column'],
    category: 'layout',
    attributes: {},
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fluent-cart-product-paginator',
        });

        return (
            <div {...blockProps} >
                <div>
                    <InnerBlocks allowedBlocks={['fluent-cart/product-paginator-info', 'fluent-cart/product-paginator-number', 'core/paragraph']} />
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
        'fluent-cart/carousel_settings',
        'fluent-cart/product_ids'
    ]
});

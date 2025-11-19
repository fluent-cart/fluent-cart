import ProductTitleBlock from './ProductTitleBlock.jsx';
import ProductPriceBlock from './ProductPriceBlock.jsx';
import ProductImageBlock from './ProductImageBlock.jsx';
import ProductButtonBlock from './ProductButtonBlock.jsx';
import ProductExcerptBlock from "./ProductExcerptBlock";
import ProductLoopBlock from "./ProductLoopBlock";
import ProductContainerBlock from "./ProductContainerBlock";
import ProductFilterBlock from "./ProductFilter/ProductFilterBlock";
import ProductFilterViewSwitcherBlock from "./ProductFilter/ProductFilterViewSwitcherBlock";
import ProductFilterSearchBlock from "./ProductFilter/ProductFilterSearchBlock";
import ProductFilterButtonBlock from "./ProductFilter/ProductFilterButtonBlock";
import ProductFilterFilters from "./ProductFilter/ProductFilterFilters";
import ProductPaginatorInfoBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductPaginatorInfoBlock";
import ProductPaginatorNumberBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductPaginatorNumberBlock";
import ProductFilterApplyButtonBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductFilter/ProductFilterApplyButtonBlock";
import ProductFilterResetButtonBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductFilter/ProductFilterResetButtonBlock";
import ProductNoResultBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductNoResultBlock";
import ProductActionContainerBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductFilter/ProductActionContainerBlock";
import ProductFilterSortByBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductFilter/ProductFilterSortByBlock";
import ProductLoaderBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductLoaderBlock";
import ProductSpinnerBlock from "@/BlockEditor/ShopApp/InnerBlocks/ProductSpinnerBlock";

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {registerBlockType} = wp.blocks;
const blockEditorData = window['fluent_cart_products_inner_blocks'];
const {InnerBlocks, useBlockProps} = wp.blockEditor;

const componentsMap = {
    ProductTitleBlock,
    ProductPriceBlock,
    ProductImageBlock,
    ProductButtonBlock,
    ProductExcerptBlock,
    ProductFilterBlock,
    ProductFilterViewSwitcherBlock,
    ProductLoopBlock,
    ProductContainerBlock,
    ProductFilterSearchBlock,
    ProductFilterButtonBlock,
    ProductFilterFilters,
    ProductPaginatorInfoBlock,
    ProductPaginatorNumberBlock,
    ProductFilterApplyButtonBlock,
    ProductFilterResetButtonBlock,
    ProductNoResultBlock,
    ProductActionContainerBlock,
    ProductFilterSortByBlock,
    ProductLoaderBlock,
    ProductSpinnerBlock
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
    parent: ['fluent-cart/products', 'core/column'],
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
        'fluent-cart/paginator',
        'fluent-cart/per_page',
        'fluent-cart/enable_filter',
        'fluent-cart/product_box_grid_size',
        'fluent-cart/view_mode',
        'fluent-cart/filters',
        'fluent-cart/default_filters',
        'fluent-cart/order_type',
        'fluent-cart/order_by',
        'fluent-cart/live_filter',
        'fluent-cart/price_format',
        'fluent-cart/enable_wildcard_filter'
    ]
});

import LegacyProductCard from './LegacyProductCard';
import ProductCardTemplate from './ProductCardTemplate';
import {NEW_CARD_ATTRIBUTES} from './productCardDefaults';
import {ProductCard} from "@/BlockEditor/Icons";
import ProductCardPreview from "./ProductCard.png";


const { InnerBlocks } = wp.blockEditor;
const {registerBlockType} = wp.blocks;


const blockEditorData = window.fluent_cart_product_card_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: ProductCardPreview,
                    alt: 'Product Card Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    icon: {
        src: ProductCard,
    },
    category: "fluent-cart",
    attributes: {
        template_version: {type: 'number', default: 0},
        card_layout: {type: 'string', default: 'classic'},
        // Any layout in a staggered grid, pictures at their own height. Replaces the Masonry layout.
        masonry: {type: 'boolean', default: false},
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        price_format: {
            type: 'string',
            default: 'starts_from',
        },
        card_width: {
            type: [ 'string', 'number' ],
            default: 216
        },
        query_type: {
            type: 'string',
            default: 'default',
        },
    },
    variations: [{
        name: 'template',
        isDefault: true,
        attributes: NEW_CARD_ATTRIBUTES,
        scope: ['inserter'],
    }],
    usesContext: ['postId', 'postType', 'fluent-cart/price_format'],
    edit: (props) => props.attributes.template_version === 1
        ? <ProductCardTemplate {...props}/>
        : <LegacyProductCard {...props}/>,

    save: ({attributes}) => attributes.template_version === 1 ? <InnerBlocks.Content/> : null,
});

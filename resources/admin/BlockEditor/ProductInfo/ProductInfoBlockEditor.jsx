import {ProductInfo} from "@/BlockEditor/Icons";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductInfo/Components/InspectorSettings.jsx";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import ProductInfoPreview from "./ProductInfo.png";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;


const blockEditorData = window.fluent_cart_product_info_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;


// InnerBlocks template
const TEMPLATE = [
    ['core/columns', {}, [
        ['core/column', {}, [
            ['fluent-cart/product-gallery', {}]
        ]],
        ['core/column', {}, [
            ['fluent-cart/product-title', {}],
            ['fluent-cart/excerpt', {}],
            ['fluent-cart/stock', {}],
            ['fluent-cart/product-sku', {}],
            ['fluent-cart/price-range', {}],
            ['fluent-cart/buy-section', {}]
        ]]
    ]]
];


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
                    url: ProductInfoPreview,
                    alt: 'Product Info Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    icon: {
        src: ProductInfo,
    },
    category: "fluent-cart",
    supports: {
        html: false,
        reusable: false,
        innerBlocks: true,
    },
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        variant_id: {
            type: 'string',
            default: '',
        },
        query_type: {
            type: 'string',
            default: 'default',
        }
    },
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps({className: 'my-plugin-product-card'});
        const [selectedVariant, setSelectedVariant] = useState({});
        const [selectedProduct, setSelectedProduct] = useState(null);

        const fetchProduct = (productId) => {
            if (!productId) {
                setSelectedProduct(null);
                return;
            }

            apiFetch({
                path: addQueryArgs(rest.url + '/products/' + productId, {
                    with: ['detail', 'variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                },
            }).then((response) => {
                setSelectedProduct(response.product || null);
            });
        }

        useEffect(() => {
            fetchProduct(attributes.product_id);
        }, [attributes.product_id]);
        
        return (
            <div {...blockProps}>

                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                />

                <div className="product-card-inner"
                     style={{border: '1px solid #eee', padding: '16px', borderRadius: '8px'}}>
                    <SingleProductDataProvider value={{
                        product: selectedProduct
                    }}>
                        <InnerBlocks
                            template={TEMPLATE}
                            templateLock={false}
                            allowedBlocks={
                                [
                                    'core/heading',
                                    'fluent-cart/buy-section',
                                    'fluent-cart/product-gallery',
                                    'fluent-cart/stock',
                                    'fluent-cart/product-sku',
                                    'fluent-cart/price-range',
                                    'core/columns',
                                    'core/paragraph',
                                    'core/title',
                                    'fluent-cart/shopapp-product-title',
                                    'fluent-cart/excerpt',
                                    'fluent-cart/sale-badge'
                                    // 'fluent-cart/shopapp-product-price'
                                ]
                            }
                        />
                    </SingleProductDataProvider>
                </div>
            </div>
        );
    },

    save: () => {
        const blockProps = useBlockProps.save({className: 'my-plugin-product-card'});

        return (
            <div {...blockProps}>
                <div className="product-card-inner">
                    <InnerBlocks.Content/>
                </div>
            </div>
        );
    },
});

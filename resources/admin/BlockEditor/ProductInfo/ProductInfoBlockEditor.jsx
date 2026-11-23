import {ProductInfo} from "@/BlockEditor/Icons";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductInfo/Components/InspectorSettings.jsx";
import {productLoadFailure} from "@/BlockEditor/Components/ProductPicker/productLoadFailure";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import ProductInfoPreview from "./ProductInfo.png";
import safeProductId from '../Components/safeProductId.js';

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
            ['fluent-cart/product-package-description', {}],
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

        const fetchProduct = (productId, isStale) => {
            if (!productId) {
                setSelectedProduct(null);
                return;
            }

            apiFetch({
                path: addQueryArgs(rest.url + '/products/' + safeProductId(productId), {
                    // Screen key, not a relation name — ProductController::allowedWiths()
                    // owns what it resolves to (detail + variants).
                    with: ['block_product_detail']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                },
            }).then((response) => {
                if (!isStale()) {
                    setSelectedProduct(response.product || {missing: true});
                }
            }).catch((error) => {
                if (!isStale()) {
                    setSelectedProduct(productLoadFailure(error));
                }
            });
        }

        // A slower earlier request must not overwrite the product picked after it.
        useEffect(() => {
            let stale = false;
            fetchProduct(attributes.product_id, () => stale);

            return () => {
                stale = true;
            };
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
                        // Not the missing / loadError markers: the inner blocks read product fields.
                        product: selectedProduct?.ID ? selectedProduct : null
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
                                    'fluent-cart/sale-badge',
                                    'fluent-cart/product-package-description',
                                    'fluent-cart/product-rating',
                                    'fluent-cart/product-reviews',
                                    'fluent-cart/product-review-summary-group',
                                    'fluent-cart/write-a-review-button',
                                    'fluent-cart/product-review-list'
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

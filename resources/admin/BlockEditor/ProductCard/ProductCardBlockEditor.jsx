import {ProductCard} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductCard/Components/InspectorSettings";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import ProductCardPreview from "./ProductCard.png";


const { useBlockProps } = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;


const blockEditorData = window.fluent_cart_product_card_data;
const rest = window['fluentCartRestVars'].rest;
const currencySign = blockEditorData.currency_sign;
const currencyPosition = blockEditorData.currency_position;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
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
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState({});
        const fetchUrl = rest.url + '/products/' + attributes.product_id;


        const fetchProduct = () => {
            if (!attributes.product_id) {
                return;
            }
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                setSelectedProduct(response.product || {});
            }).finally(() => {

            });
        }

        const isSimpleVariations = () => {
            return selectedProduct?.detail?.variation_type === "simple_variations";
        };

        useEffect(() => {
            fetchProduct();
        }, [attributes.product_id]);

        return (
            <div {...blockProps}>

                <ErrorBoundary>
                    <div className="flex flex-col gap-4">
                        <InspectorSettings
                            attributes={attributes}
                            setAttributes={setAttributes}
                            selectedProduct={selectedProduct}
                            setSelectedProduct={setSelectedProduct}
                        />

                        <div className="fluent-cart-product-card-block-editor">
                            <div className="fluent-cart-product-card-block-editor-header">
                                <h4 className="fluent-cart-product-card-block-editor-title">
                                    {blocktranslate('Product Card')}
                                </h4>
                            </div>


                            <div className="fct-product-card" style={{ width: attributes.card_width === '100%' ? '100%' : `${attributes.card_width}px` }}>
                                <div className="fct-product-card-head">
                                    <img className="fct-product-card-image"
                                         src={selectedProduct?.thumbnail ?? blockEditorData.placeholder_image}
                                         alt={selectedProduct?.post_title}/>
                                </div>

                                <div className="fct-product-card-body">
                                    <h5 className="fct-product-card-title">
                                        {selectedProduct?.post_title ?? blocktranslate('Select a Product')}
                                    </h5>
                                    <div className="fct-product-card-price-wrap">
                                        {(() => {
                                            const priceFormat = attributes.price_format;
                                            const isSimple = selectedProduct?.detail?.variation_type === 'simple';
                                            let minPrice = selectedProduct?.detail?.min_price;
                                            let maxPrice = selectedProduct?.detail?.max_price;
                                            let comparePrice = 0;

                                            if (isSimple) {
                                                const firstVariant = selectedProduct?.variants?.[0];
                                                if (firstVariant) {
                                                    minPrice = firstVariant.item_price;
                                                    if (firstVariant.compare_price > minPrice) {
                                                        comparePrice = firstVariant.compare_price;
                                                    }
                                                }
                                            }

                                            // Convert from cents → dollars and format
                                            const formatPrice = (price) => {
                                                if (!price && price !== 0) {
                                                    return currencyPosition === 'after'
                                                        ? `0.00${currencySign}`
                                                        : `${currencySign}0.00`;
                                                }

                                                // Divide by 100 since your price is stored in cents
                                                const formatted = (price / 100).toFixed(2);

                                                return currencyPosition === 'after'
                                                    ? `${formatted}${currencySign}`
                                                    : `${currencySign}${formatted}`;
                                            };

                                            // CASE 1: Compare price available
                                            if (comparePrice) {
                                                return (
                                                    <>
                                                        <span className="fc_compare_price">
                                                            <del>{formatPrice(comparePrice)}</del>
                                                        </span>
                                                        <span className="fct-item-price">{formatPrice(minPrice)}</span>
                                                    </>
                                                );
                                            }

                                            // CASE 2: Variable product (multiple variations)
                                            if (maxPrice && maxPrice > minPrice) {
                                                if (priceFormat === 'range') {
                                                    // Range format: "min - max"
                                                    return (
                                                        <>
                                                            <span className="fct-item-price">
                                                              {formatPrice(minPrice)} - {formatPrice(maxPrice)}
                                                            </span>
                                                        </>
                                                    );
                                                } else {
                                                    // Starts-from format: "From min"
                                                    return (
                                                        <>
                                                            <span className="fct-item-price">
                                                              From {formatPrice(minPrice)}
                                                            </span>
                                                        </>
                                                    );
                                                }
                                            }

                                            // CASE 3: Simple price
                                            return (
                                                <>
                                                    <span className="fct-item-price">{formatPrice(minPrice)}</span>
                                                </>
                                            );
                                        })()}
                                    </div>
                                </div>

                                <div className="fct-product-card-footer">
                                    <button className="fluent-cart-shop-app-view-product-button">
                                        <span>
                                            { blocktranslate(isSimpleVariations() ? 'View Options' : 'Add to Cart') }
                                        </span>
                                    </button>
                                </div>
                            </div>



                        </div>
                    </div>
                </ErrorBoundary>



            </div>
        );
    },

    save: function (props) {
        return null;
    },
});

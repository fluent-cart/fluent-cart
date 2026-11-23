import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductCard/Components/InspectorSettings";
import {productLoadFailure} from "@/BlockEditor/Components/ProductPicker/productLoadFailure";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import {convertToTemplateCard, convertedCardTemplate} from "./productCardDefaults";
import {availableTemplate} from "@/BlockEditor/ShopApp/InnerBlocks/ProductCardLayoutPresets";
import safeProductId from '../Components/safeProductId.js';


const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, Button } = wp.components;
const {useEffect, useState} = wp.element;


const blockEditorData = window.fluent_cart_product_card_data;
const rest = window['fluentCartRestVars'].rest;
const currencySign = blockEditorData.currency_sign;
const currencyPosition = blockEditorData.currency_position;

export default function LegacyProductCard({attributes, setAttributes, clientId, name}) {
    // One replacement with the children already built, so a single Undo brings the older card back.
    // Setting the attribute alone let the block's template refill the children after every Undo.
    const convert = () => wp.data.dispatch('core/block-editor').replaceBlock(clientId, wp.blocks.createBlock(
        name, {...attributes, ...convertToTemplateCard()},
        wp.blocks.createBlocksFromInnerBlocksTemplate(availableTemplate(convertedCardTemplate()))
    ));
    const blockProps = useBlockProps();
    const [selectedProduct, setSelectedProduct] = useState({});
    const fetchUrl = rest.url + '/products/' + safeProductId(attributes.product_id);


    const fetchProduct = (isStale) => {
        if (!attributes.product_id) {
            return;
        }
        apiFetch({
            path: addQueryArgs(fetchUrl, {
                // Screen key, not a relation name — ProductController::allowedWiths()
                // owns what it resolves to (detail + variants).
                with: ['block_product_detail']
            }),
            headers: {
                'X-WP-Nonce': rest.nonce
            }
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

    const isSimpleVariations = () => {
        return selectedProduct?.detail?.variation_type === "simple_variations";
    };

    const isOutOfStock = selectedProduct?.detail?.stock_availability === 'out-of-stock';

    // A slower earlier request must not overwrite the product picked after it.
    useEffect(() => {
        let stale = false;
        fetchProduct(() => stale);

        return () => {
            stale = true;
        };
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
                    <InspectorControls>
                        <PanelBody title={blocktranslate('Card layouts')} initialOpen={true}>
                            <p>{blocktranslate('This card was added before the card layouts. Switch to build it from blocks you can edit and choose a layout for. Use Undo to switch back.')}</p>
                            <Button variant="secondary" onClick={convert}>
                                {blocktranslate('Use the card layouts')}
                            </Button>
                        </PanelBody>
                    </InspectorControls>

                    <div className="fct-product-card" style={{ width: attributes.card_width === '100%' ? '100%' : `${attributes.card_width}px` }}>
                        <div className="fct-product-card-head" style={{ position: 'relative' }}>
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
                                                ? `0${currencySign}`
                                                : `${currencySign}0`;
                                        }

                                        // Divide by 100 since your price is stored in cents
                                        const formatted = String(parseFloat((price / 100).toFixed(2)));

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
                            {isOutOfStock ? (
                                <button className="fluent-cart-shop-app-view-product-button" disabled style={{
                                    cursor: 'not-allowed',
                                    pointerEvents: 'none',
                                }}>
                                    <span>{ blocktranslate('Out of Stock') }</span>
                                </button>
                            ) : (
                                <button className="fluent-cart-shop-app-view-product-button">
                                    <span>{ blocktranslate(isSimpleVariations() ? 'View Options' : 'Add To Cart') }</span>
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </ErrorBoundary>



        </div>
    );
}

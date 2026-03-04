import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/SaleBadge/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Tag} from "@/BlockEditor/Icons";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState, useMemo} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_sale_badge_data;
const rest = window['fluentCartRestVars'].rest;

/**
 * Compute sale info from product data.
 * Returns { isOnSale, discountPercent } based on price_source setting.
 */
const computeSaleInfo = (product, priceSource) => {
    if (!product?.variants?.length) {
        return {isOnSale: false, discountPercent: 0};
    }

    if (priceSource === 'default_variant') {
        const defaultVariantId = product?.detail?.default_variation_id;
        const variant = defaultVariantId
            ? (product.variants.find(v => v.id === defaultVariantId) || product.variants[0])
            : product.variants[0];

        if (variant && variant.compare_price > variant.item_price && variant.compare_price > 0) {
            return {
                isOnSale: true,
                discountPercent: Math.round(((variant.compare_price - variant.item_price) / variant.compare_price) * 100),
            };
        }
        return {isOnSale: false, discountPercent: 0};
    }

    // best_discount — scan all variants
    let bestDiscount = 0;
    let onSale = false;
    for (const variant of product.variants) {
        if (variant.compare_price > variant.item_price && variant.compare_price > 0) {
            onSale = true;
            const discount = Math.round(((variant.compare_price - variant.item_price) / variant.compare_price) * 100);
            if (discount > bestDiscount) {
                bestDiscount = discount;
            }
        }
    }
    return {isOnSale: onSale, discountPercent: bestDiscount};
};

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Tag,
    },
    category: "fluent-cart",
    attributes: {
        badge_text: {
            type: 'string',
            default: 'Sale!',
        },
        show_percentage: {
            type: 'boolean',
            default: false,
        },
        percentage_text: {
            type: 'string',
            default: '-{percent}%',
        },
        price_source: {
            type: 'string',
            default: 'default_variant',
        },
        badge_style: {
            type: 'string',
            default: 'badge',
        },
        badge_position: {
            type: 'string',
            default: 'top-left',
        },
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState({});
        const fetchUrl = rest.url + '/products/' + attributes.product_id;

        const singleProductData = useSingleProductData();

        // Detect if inside a product context block
        const isInsideProductInfo = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);
            const parents = getBlockParents(clientId);
            return parents.some((parentId) => {
                const name = getBlockName(parentId);
                return [
                    'fluent-cart/product-info',
                    'fluent-cart/products',
                    'fluent-cart/shopapp-product-container',
                    'fluent-cart/shopapp-product-loop',
                    'fluent-cart/product-carousel',
                    'fluent-cart/product-image',
                ].includes(name);
            });
        }, [clientId]);

        // Detect if inside a visual container (for showing position/style controls)
        const isInsideVisualContainer = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);
            const parents = getBlockParents(clientId);
            return parents.some((parentId) => {
                const name = getBlockName(parentId);
                return [
                    'fluent-cart/product-image',
                    'fluent-cart/shopapp-product-image',
                    'fluent-cart/products',
                ].includes(name);
            });
        }, [clientId]);

        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['detail', 'variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                setSelectedProduct(response?.product || {});
            });
        };

        useEffect(() => {
            if (singleProductData?.product) {
                setSelectedProduct(singleProductData.product);
            } else if (!isInsideProductInfo && attributes.product_id) {
                fetchProduct();
            } else {
                setSelectedProduct({});
            }
        }, [attributes.product_id, singleProductData?.product]);

        // Clear badge_position when not inside a visual container so it won't be saved
        useEffect(() => {
            if (!isInsideVisualContainer && attributes.badge_position) {
                setAttributes({ badge_position: '' });
            }
        }, [isInsideVisualContainer]);

        // Compute sale info from current product
        const saleInfo = useMemo(
            () => computeSaleInfo(selectedProduct, attributes.price_source),
            [selectedProduct, attributes.price_source]
        );

        // Build display text
        const getDisplayText = () => {
            if (attributes.show_percentage && saleInfo.discountPercent > 0) {
                return attributes.percentage_text.replace('{percent}', saleInfo.discountPercent);
            }
            return attributes.badge_text || 'Sale!';
        };

        const badgeClasses = [
            'fct-sale-badge',
            `fct-sale-badge--${attributes.badge_style || 'badge'}`,
            isInsideVisualContainer ? `fct-sale-badge--${attributes.badge_position || 'top-left'}` : '',
        ].filter(Boolean).join(' ');

        const hasProduct = selectedProduct?.ID || selectedProduct?.variants?.length;

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        selectedProduct={selectedProduct}
                        setSelectedProduct={setSelectedProduct}
                        isInsideProductInfo={isInsideProductInfo}
                        isInsideVisualContainer={isInsideVisualContainer}
                        saleInfo={saleInfo}
                    />

                    <span className={badgeClasses}
                          style={hasProduct && !saleInfo.isOnSale ? {opacity: 0.4} : {}}>
                        {getDisplayText()}
                    </span>

                    {hasProduct && !saleInfo.isOnSale && (
                        <small style={{display: 'block', color: '#999', fontSize: '11px', marginTop: '4px'}}>
                            {blocktranslate('Product is not on sale')}
                        </small>
                    )}
                </ErrorBoundary>
            </div>
        );
    },

    save: function () {
        return null;
    },

    supports: {
        html: false,
        align: ["left", "center", "right"],
        color: {
            text: true,
            background: true,
            gradients: true,
        },
        typography: {
            fontSize: true,
            __experimentalFontWeight: true,
            __experimentalLetterSpacing: true,
            __experimentalTextTransform: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        spacing: {
            margin: true,
            padding: true,
        },
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
        shadow: true,
    },
});

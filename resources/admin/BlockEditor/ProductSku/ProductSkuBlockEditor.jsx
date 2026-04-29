import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductSku/Components/InspectorSettings";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Sku} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState, useMemo} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_product_sku_data;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Sku,
    },
    category: "fluent-cart",
    example: {
        attributes: {
            show_label: true,
            label: 'SKU:',
        },
    },
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        variant_id: {
            type: ['string', 'number'],
            default: '',
        },
        show_label: {
            type: 'boolean',
            default: true,
        },
        label: {
            type: 'string',
            default: blocktranslate('SKU:'),
        },
        query_type: {
            type: 'string',
            default: 'default',
        },
        inside_product_info: {
            type: 'string',
            default: '-',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-product-sku',
        });

        const [selectedProduct, setSelectedProduct] = useState({});
        const [selectedVariations, setSelectedVariations] = useState({});

        const singleProductData = useSingleProductData();

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
                ].includes(name);
            });
        }, [clientId]);

        useEffect(() => {
            setAttributes({
                inside_product_info: isInsideProductInfo ? 'yes' : 'no',
                ...(!isInsideProductInfo ? {query_type: 'custom'} : {})
            });
        }, [isInsideProductInfo]);

        // Fetch product for standalone mode (restores state on page reload)
        useEffect(() => {
            if (isInsideProductInfo) return;
            if (!attributes.product_id) return;

            apiFetch({
                path: addQueryArgs(rest.url + '/products/' + attributes.product_id, {
                    with: ['detail', 'variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                },
            }).then((response) => {
                const product = response.product || {};
                setSelectedProduct(product);

                // Restore selectedVariations state from saved variant_id
                if (attributes.variant_id && product.variants?.length) {
                    const variantId = String(attributes.variant_id);
                    const variant = product.variants.find(v => String(v.id) === variantId);
                    if (variant) {
                        setSelectedVariations({[variantId]: variant});
                    }
                }
            });
        }, [attributes.product_id]);

        // Inside product info: use context product
        useEffect(() => {
            if (singleProductData?.product) {
                setSelectedProduct(singleProductData.product);
            }
        }, [singleProductData?.product]);

        // Compute SKU for editor preview
        const sku = useMemo(() => {
            // Standalone mode: use the specific selected variation
            if (!isInsideProductInfo && attributes.variant_id) {
                const variantId = String(attributes.variant_id);
                const variant = Object.values(selectedVariations).find(v => String(v.id) === variantId);
                if (variant?.sku) return variant.sku;

                // Fallback: look in product variants
                const product = selectedProduct?.variants ? selectedProduct : null;
                if (product?.variants?.length) {
                    const found = product.variants.find(v => String(v.id) === variantId);
                    if (found?.sku) return found.sku;
                }
            }

            // Inside product info: use default variant from product context
            const product = selectedProduct?.variants ? selectedProduct : singleProductData?.product;
            if (product?.variants?.length) {
                const defaultVariantId = product?.detail?.default_variation_id;
                const variant = defaultVariantId
                    ? (product.variants.find(v => v.id === defaultVariantId) || product.variants[0])
                    : product.variants[0];
                return variant?.sku || '';
            }
            return '';
        }, [selectedProduct, selectedVariations, singleProductData?.product, attributes.variant_id, isInsideProductInfo]);

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        selectedProduct={selectedProduct}
                        setSelectedProduct={setSelectedProduct}
                        selectedVariations={selectedVariations}
                        setSelectedVariations={setSelectedVariations}
                        sku={sku}
                        isInsideProductInfo={isInsideProductInfo}
                    />

                    <div className="fct-product-sku__preview">
                        {attributes.show_label && (
                            <span className="fct-product-sku__label">
                                {attributes.label || blocktranslate('SKU:')}{' '}
                            </span>
                        )}
                        <span className="fct-product-sku__value">
                            {sku || blocktranslate('N/A')}
                        </span>
                    </div>
                </ErrorBoundary>
            </div>
        );
    },

    save: () => null,

    supports: {
        html: false,
        align: ["left", "center", "right"],
        typography: {
            fontSize: true,
            lineHeight: true,
            __experimentalFontFamily: true,
            __experimentalFontWeight: true,
            __experimentalFontStyle: true,
            __experimentalTextTransform: true,
            __experimentalLetterSpacing: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        color: {
            text: true,
            background: true,
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

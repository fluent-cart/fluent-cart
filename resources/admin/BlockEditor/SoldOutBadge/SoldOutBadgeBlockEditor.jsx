import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/SoldOutBadge/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {SoldOutBadge} from "@/BlockEditor/Icons";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState, useMemo} = wp.element;

const blockEditorData = window.fluent_cart_sold_out_badge_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: SoldOutBadge,
    },
    category: "fluent-cart",
    parent: [
        'fluent-cart/products',
        'fluent-cart/product-carousel',
        'fluent-cart/product-image',
    ],
    attributes: {
        badge_text: {
            type: 'string',
            default: 'Sold Out',
        },
        badge_style: {
            type: 'string',
            default: 'badge',
        },
        badge_position: {
            type: 'string',
            default: 'top-left',
        },
    },
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState({});

        const singleProductData = useSingleProductData();

        useEffect(() => {
            if (singleProductData?.product) {
                setSelectedProduct(singleProductData.product);
            } else {
                setSelectedProduct({});
            }
        }, [singleProductData?.product]);

        // Check stock status
        const isOutOfStock = useMemo(() => {
            return selectedProduct?.detail?.stock_availability === 'out-of-stock';
        }, [selectedProduct]);

        const badgeClasses = [
            'fct-sold-out-badge',
            `fct-sold-out-badge--${attributes.badge_style || 'badge'}`,
            attributes.badge_position ? `fct-sold-out-badge--${attributes.badge_position}` : '',
        ].filter(Boolean).join(' ');

        const hasProduct = selectedProduct?.ID || selectedProduct?.detail;

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        isOutOfStock={isOutOfStock}
                    />

                    <span className={badgeClasses}
                          style={hasProduct && !isOutOfStock ? {opacity: 0.4} : {}}>
                        {attributes.badge_text || 'Sold Out'}
                    </span>

                    {hasProduct && !isOutOfStock && (
                        <small style={{display: 'block', color: '#999', fontSize: '11px', marginTop: '4px'}}>
                            {blocktranslate('Product is in stock')}
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

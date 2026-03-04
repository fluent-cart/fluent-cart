import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/PriceRange/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {PriceRange} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType, createBlock} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_price_range_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: PriceRange,
    },
    category: "fluent-cart",
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        query_type: {
            type: 'string',
            default: 'default',
        },
        inside_product_info: {
            type: 'string',
            default: '-',
        },
        price_format: {
            type: 'string',
            default: 'starts_from',
        }
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState({});
        const fetchUrl = rest.url + '/products/' + attributes.product_id;

        const singleProductData = useSingleProductData();

        const isInsideProductInfo = useSelect((select) => {
            const { getBlockParents, getBlockName } = select(blockEditorStore);

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
                ...(!isInsideProductInfo ? { query_type: 'custom' } : {})
            });
        }, [isInsideProductInfo]);

        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['detail', 'variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                setSelectedProduct(response.product || {});
            }).finally(() => {

            });
        }

        useEffect(() => {
            if (singleProductData?.product) {
                setSelectedProduct(singleProductData.product);
            }
            if (!isInsideProductInfo && attributes.product_id) {
                fetchProduct();
            }
        }, [attributes.product_id, singleProductData?.product]);

        return (
            <div {...blockProps}>
                {!isInsideProductInfo ? (
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        selectedProduct={selectedProduct}
                        setSelectedProduct={setSelectedProduct}
                    />
                ) : ''}
                
                {selectedProduct?.detail ? (
                    <div className="fct-product-price-range">
                        <span
                            className="price-min"
                            dangerouslySetInnerHTML={{
                                __html: selectedProduct.detail.formatted_min_price
                            }}
                        />

                        {selectedProduct.detail.min_price !== selectedProduct.detail.max_price && (
                            <>
                                {' - '}
                                <span
                                    className="price-max"
                                    dangerouslySetInnerHTML={{
                                        __html: selectedProduct.detail.formatted_max_price
                                    }}
                                />
                            </>
                        )}
                    </div>
                )
                : '$0.00'}
            </div>
              
        );
    },

    save: function (props) {
        return null;
    },
    transforms: {
        from: [
            {
                type: 'block',
                blocks: ['fluent-cart/shopapp-product-price'],
                transform: () => {
                    return createBlock(blockEditorData.slug + '/' + blockEditorData.name);
                },
            },
        ],
    },
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
            __experimentalTextDecoration: true,
            __experimentalLetterSpacing: true,
            __experimentalDefaultControls: {
                fontSize: true,
                lineHeight: true,
                fontWeight: true,
            },
        },
        color: {
            text: true,
            background: true,
            link: true,
            gradients: true,
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

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductDescription/Components/InspectorSettings";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Description} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_product_description_data;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Description,
    },
    category: "fluent-cart",
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-description',
        });
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

        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['detail']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                setSelectedProduct(response?.product || {});
            }).catch(() => {
                setSelectedProduct({});
            });
        }

        useEffect(() => {
            if (singleProductData?.product) {
                setSelectedProduct(singleProductData.product);
            } else if (!isInsideProductInfo && attributes.product_id) {
                fetchProduct();
            } else {
                setSelectedProduct({});
            }
        }, [attributes.product_id, singleProductData?.product]);

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    {!isInsideProductInfo ? (
                        <InspectorSettings
                            attributes={attributes}
                            setAttributes={setAttributes}
                            selectedProduct={selectedProduct}
                            setSelectedProduct={setSelectedProduct}
                        />
                    ) : ''}
                    <div className="fct-product-description-preview">
                        {selectedProduct?.post_content ? (
                            // post_content is trusted WordPress post content — safe to render as HTML
                            <div dangerouslySetInnerHTML={{ __html: selectedProduct.post_content }} />
                        ) : (
                            <p className="fct-product-description-placeholder">
                                {blocktranslate('Product Description')}
                            </p>
                        )}
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

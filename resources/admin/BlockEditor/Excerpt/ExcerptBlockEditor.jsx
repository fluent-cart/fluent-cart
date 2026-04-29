import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/Excerpt/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Excerpt} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType, createBlock} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_excerpt_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Excerpt,
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
        }
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-excerpt',
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
                <div className="fct-product-excerpt">
                    {selectedProduct?.post_excerpt || blocktranslate('Excerpt')}
                </div>
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
                blocks: ['fluent-cart/shopapp-product-excerpt'],
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

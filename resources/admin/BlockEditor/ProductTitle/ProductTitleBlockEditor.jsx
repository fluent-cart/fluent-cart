import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductTitle/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Title} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType, createBlock} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_product_title_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Title,
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
        isLink: {
            type: 'boolean',
            default: true
        },
        linkTarget: {
            type: 'string',
            default: '_self'
        }
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState(null);
        const fetchUrl = rest.url + '/products/' + attributes.product_id;
        const productUrl = selectedProduct?.view_url || '#';

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

        const titleText = selectedProduct?.post_title || blocktranslate('Product Title');
        const { isLink, linkTarget } = attributes;
        const TagName = isLink ? 'a' : 'h5';
        const linkProps = isLink ? {
            href: productUrl,
            target: linkTarget,
            rel: linkTarget === '_blank' ? 'noopener noreferrer' : undefined,
            onClick: (e) => e.preventDefault()
        } : {};

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    isInsideProductInfo={isInsideProductInfo}
                    isLink={isLink}
                    linkTarget={linkTarget}
                />
                
                <TagName {...blockProps} {...linkProps}>
                    {titleText}
                </TagName>
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
                blocks: ['fluent-cart/shopapp-product-title'],
                transform: (attributes) => {
                    return createBlock(blockEditorData.slug + '/' + blockEditorData.name, {
                        isLink: attributes.isLink ?? true,
                        linkTarget: attributes.linkTarget ?? '_self',
                    });
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
    }
});

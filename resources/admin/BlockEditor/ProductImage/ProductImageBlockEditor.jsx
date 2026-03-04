import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductImage/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {ProductGallery} from "@/BlockEditor/Icons";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType, createBlock} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_product_image_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ProductGallery,
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

        const getImage = () => {
            const placeholderImage = window.fluent_cart_block_editor_asset.placeholder_image;
            return selectedProduct && selectedProduct.detail && selectedProduct.detail.featured_media && selectedProduct.detail.featured_media !== null && typeof selectedProduct.detail.featured_media === 'object' && selectedProduct.detail.featured_media.url ?
                selectedProduct.detail.featured_media.url : placeholderImage;
        }

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    isInsideProductInfo={isInsideProductInfo}
                />

                <div className="relative group-[.list]:flex-shrink-0">
                    <img src={getImage()}
                         className={'w-full aspect-square object-cover rounded-md group-[.list]:w-[214px] pointer-events-none'}
                         alt={selectedProduct.product ? selectedProduct.product.post_title : 'Product'}/>
                    <div className="absolute inset-0" style={{pointerEvents: 'auto'}}>
                        <InnerBlocks
                            allowedBlocks={[
                                'fluent-cart/sold-out-badge',
                                'fluent-cart/sale-badge',
                                'fluent-cart/shopapp-product-title',
                            ]}
                            renderAppender={InnerBlocks.ButtonBlockAppender}
                        />
                    </div>
                </div>

            </div>
        );
    },
    save: function () {
        return <InnerBlocks.Content />;
    },
    deprecated: [
        {
            save: () => null,
        },
    ],
    transforms: {
        from: [
            {
                type: 'block',
                blocks: ['fluent-cart/shopapp-product-image'],
                transform: () => {
                    return createBlock(blockEditorData.slug + '/' + blockEditorData.name);
                },
            },
        ],
    },
    supports: {
        html: false,
        align: ["left", "center", "right"],
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
        shadow: true,
        __experimentalFilter: {
            duotone: true,
        },
    }
});

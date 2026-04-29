import {Cart} from "@/BlockEditor/Icons";
import InspectorSettings from "@/BlockEditor/Stock/Components/InspectorSettings.jsx";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_stock_data;
const rest = window['fluentCartRestVars'].rest;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Cart,
    },
    category: "fluent-cart",
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        variant_id: {
            type: 'string',
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

        const getStockLabel = (status) => {
            switch (status) {
                case 'in-stock':
                    return blocktranslate('In Stock');

                case 'out-of-stock':
                    return blocktranslate('Out of Stock');

                case 'backorder':
                    return blocktranslate('Available on Backorder');

                case 'low-stock':
                    return blocktranslate('Low Stock');

                default:
                    return blocktranslate('Stock Availability');
            }
        };

        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['detail', 'variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                },
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

        const stockAvailability = getStockLabel(selectedProduct?.detail?.stock_availability) || blocktranslate('Stock Availability');

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

                <div className="fct-stock-status">
                    {stockAvailability}
                </div>
            </div>
        );
    },
    save: function (props) {
        return null;
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

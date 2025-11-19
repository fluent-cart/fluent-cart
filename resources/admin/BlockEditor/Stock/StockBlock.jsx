import {Cart} from "@/BlockEditor/Icons";
import InspectorSettings from "@/BlockEditor/Stock/Components/InspectorSettings.jsx";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import {useSingleProductData} from "@/BlockEditor/ProductInfo/Context/SingleProductContext";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;
const rest = window['fluentCartRestVars'].rest;


const blockEditorData = window.fluent_cart_stock_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Cart,
    },
    supports: blockEditorData.supports || [],
    category: "fluent-cart",
    attributes: {
        product_id: {
            type: 'string',
            default: '',
        },
        variant_id: {
            type: 'string',
            default: '',
        },
        query_type: {
            type: 'string',
            default: 'default',
        }
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps();

        const singleProductData = useSingleProductData();

        const isInsideProductInfo = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);

            // Get all parent block IDs of this block
            const parents = getBlockParents(clientId);

            // Check if any parent has blockName 'product-info'
            return parents.some((parentId) => getBlockName(parentId) === 'fluent-cart/product-info');
        }, [clientId]);

        setAttributes({inside_product_info: isInsideProductInfo ? 'yes' : 'no'});


        const [selectedProduct, setSelectedProduct] = useState({});
        const fetchUrl = rest.url + '/products/' + attributes.product_id;


        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['detail', 'variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                },
            }).then((response) => {
                console.log(response.product, ' response');

                setSelectedProduct(response.product || {});
            }).finally(() => {

            });
        }

        if (!isInsideProductInfo) {
            useEffect(() => {
                fetchProduct();
            }, [attributes.product_id]);
        }


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

                {singleProductData?.product?.detail?.stock_availability ? (
                    <div>{blocktranslate('In Stock')}</div>
                ) : blocktranslate('Out of Stock')}
            </div>
        );
    },

    save: function (props) {
        return null;
    },
});

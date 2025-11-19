import {ProductGallery} from "@/BlockEditor/Icons";
import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal.jsx";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductGallery/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ProductInfo/Context/SingleProductContext";
import ProductGalleryPreview from "./ProductGallery.png";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;


const blockEditorData = window.fluent_cart_product_gallery_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    title: blockEditorData.title,
    description: blockEditorData.description,
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: ProductGalleryPreview,
                    alt: 'Product Gallery Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
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
        enableImageZoom: {
            type: 'string',
            default: 'yes'
        }
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState({});
        const fetchUrl = rest.url + '/products/' + attributes.product_id;

        const isInsideProductInfo = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);

            // Get all parent block IDs of this block
            const parents = getBlockParents(clientId);

            // Check if any parent has blockName 'product-info'
            return parents.some((parentId) => getBlockName(parentId) === 'fluent-cart/product-info');
        }, [clientId]);

        setAttributes({inside_product_info: isInsideProductInfo ? 'yes' : 'no'});

        const singleProductData = useSingleProductData();


        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                console.log(response, ' response');

                setSelectedProduct(response.product || {});
            }).finally(() => {

            });
        }
        
        useEffect(() => {
            fetchProduct();
        }, []);

        useEffect(() => {
            if (singleProductData?.product) {
                setSelectedProduct(singleProductData.product);
            }
        }, [singleProductData?.product]);


        return (
            <div {...blockProps}>
               <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    isInsideProductInfo={isInsideProductInfo}
                />


                {selectedProduct?.ID ? (
                    <div className="fct-product-gallery-wrapper">
                        <div className="fct-product-gallery-thumb">
                            <img src={selectedProduct?.detail?.featured_media?.url ?? placeholderImage} alt=""/>
                        </div>

                        <div className="fct-gallery-thumb-controls">
                            {selectedProduct?.variants?.map((variant, index) => (
                                <div
                                    key={variant.id || index}
                                    className="fct-gallery-thumb-control-button"
                                >
                                    <img
                                        src={variant.thumbnail ?? placeholderImage}
                                        alt={variant.variation_title ?? ''}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="fluent-cart-empty">
                        <div className="fluent-cart-empty-message">
                            {blocktranslate('No product gallery')}
                        </div>
                    </div>
                )}

            </div>
        );
    },

    save: function (props) {
        return null;
    },
});

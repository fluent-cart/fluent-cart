import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/BuySection/Components/InspectorSettings";
import {useSingleProductData} from "@/BlockEditor/ProductInfo/Context/SingleProductContext";
import {BuySection} from "@/BlockEditor/Icons";
import BuySectionPreview from "./BuySection.png";


const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;


const blockEditorData = window.fluent_cart_buy_section_data;
const placeholderImage = blockEditorData.placeholder_image;
const rest = window['fluentCartRestVars'].rest;


registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: BuySection,
    },
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: BuySectionPreview,
                    alt: 'Buy Section Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
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
        const blockProps = useBlockProps();
        const [selectedProduct, setSelectedProduct] = useState({});
        const fetchUrl = rest.url + '/products/' + attributes.product_id;

        const singleProductData = useSingleProductData();


        const isInsideProductInfo = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);

            // Get all parent block IDs of this block
            const parents = getBlockParents(clientId);

            // Check if any parent has blockName 'product-info'
            return parents.some((parentId) => getBlockName(parentId) === 'fluent-cart/product-info');
        }, [clientId]);

        setAttributes({inside_product_info: isInsideProductInfo ? 'yes' : 'no'});


        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(fetchUrl, {
                    with: ['variants']
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
        }, [singleProductData?.product]);

        useEffect(() => {
            if (isInsideProductInfo) {
                return;
            }
            fetchProduct();
        }, [attributes.product_id]);


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

                <div className="fct_buy_section">
                    {selectedProduct?.variants && new Set(selectedProduct.variants.map(v => v.other_info?.payment_type)).size > 1 && (
                        <div className="fct-product-tab-nav">
                            {[...new Set(selectedProduct.variants.map(v => v.other_info?.payment_type))]
                            .filter(Boolean)
                            .map((paymentType, index) => (
                                <div
                                    key={paymentType}
                                    className={`fct-product-tab-nav-item ${index === 0 ? 'active' : ''}`}
                                >
                                    {paymentType}
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="fct-product-variants">
                        {selectedProduct?.variants?.length ? (
                            selectedProduct.variants.map((variant, index) => (
                                <div className={`fct-product-variant-item ${index === 0 ? 'selected' : ''}`} key={variant.id ?? index}>
                                    <div className="variant-content">
                                        <div className="fct-product-variant-image">
                                            <img
                                                src={variant.thumbnail ?? placeholderImage}
                                                alt={variant.variation_title ?? ''}
                                            />
                                        </div>
                                        <div className="fct-product-variant-title">
                                            {variant.variation_title ?? blocktranslate('Select a product')}
                                        </div>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="fct-product-variant-item">
                                <div className="variant-content">
                                    <div className="fct-product-variant-image">
                                    <img
                                        src={selectedProduct?.detail?.featured_media?.url ?? placeholderImage}
                                        alt={selectedProduct?.post_title ?? ''}
                                    />
                                    </div>
                                    <div className="fct-product-variant-title">
                                        {selectedProduct?.post_title ?? blocktranslate('Select a product')}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>


                    <div className="fct-product-item-price">
                        {
                        selectedProduct?.variants?.[0]
                            ? `$${(selectedProduct.variants[0].item_price / 100).toFixed(2)}`
                            : '$0.00'
                        }
                    </div>

                    <div className="fct-product-quantity-container">
                        <h5 className="quantity-title">
                            {blocktranslate('Quantity')}
                        </h5>

                        <div className="fct-product-quantity">
                            <button className="fct-quantity-decrease-button">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="2" viewBox="0 0 14 2" fill="none">
                                    <path d="M12.3333 1L1.66659 1" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"></path>
                                </svg>
                            </button>

                            <input type="text" className="fct-quantity-input" value="1" min="1" max="10000"/>

                            <button className="fct-quantity-increase-button">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                    <path d="M6.99996 1.66666L6.99996 12.3333M12.3333 6.99999L1.66663 6.99999" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"></path>
                                </svg>
                            </button>

                        </div>
                    </div>

                    <div className="fct-product-buttons-wrap">
                        <button className="fluent-cart-direct-checkout-button">
                            {blocktranslate('Buy Now')}
                        </button>
                        <button className="fluent-cart-add-to-cart-button">
                            {blocktranslate('Add to Cart')}
                        </button>
                    </div>

              </div>
        </div>
    );
    },

    save: function (props) {
        return null;
    },
    });

import { ProductGallery } from "@/BlockEditor/Icons";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/ProductCarousel/Components/InspectorSettings.jsx";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import ProductCarouselPreview from "./ProductCarousel.png";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ParentDataProvider} from "@/BlockEditor/ShopApp/Context/ProductContext";

const {
  InnerBlocks,
  useBlockProps,
  useInnerBlocksProps,
  __experimentalUseBlockPreview,
  BlockContextProvider
} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState, useRef} = wp.element;

const { useSelect } = wp.data;
const { store: blockEditorStore } = wp.blockEditor;
let lastChanged = '';

const blockEditorData = window.fluent_cart_product_carousel_block_editor_data;
const rest = window['fluentCartRestVars'].rest;

// InnerBlocks template
const TEMPLATE = [
    [
        'fluent-cart/product-carousel-loop',
        {
            className: 'fluent-cart-product-carousel-loop',
            layout: {type: 'constrained'},
            metadata: {name: 'Product Carousel Loop'},
        },
        [
            ['fluent-cart/product-image'],
            ['fluent-cart/product-title'],
            ['fluent-cart/excerpt'],
            ['fluent-cart/price-range'],
            ['fluent-cart/shopapp-product-buttons'],
        ],
    ],
    ['fluent-cart/product-carousel-controls'],
    ['fluent-cart/product-carousel-pagination'],
];


registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: ProductCarouselPreview,
                    alt: 'Product Carousel Preview',
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
    supports: {
        html: false,
        reusable: false,
        innerBlocks: true,
    },
    attributes: {
        product_ids: {
            type: 'array',
            default: [],
        },
        inside_product_info: { type: 'string', default: '-' },
        carousel_settings: {
            type: 'object',
            default: {
                slidesToShow: 3,
                autoplay: 'yes',
                autoplayDelay: 3000,
                arrows: 'yes',
                arrowsSize: 'md', // sm | md | lg
                pagination: 'yes',
                paginationType: 'bullets',   // bullets | fraction | progress | segmented
                infinite: 'no',
            },
        },
        has_controls: {
            type: 'string',
            default: 'yes',
        },
        has_pagination: {
            type: 'string',
            default: 'yes',
        },
    },
    provides_context : {
        'fluent-cart/carousel_settings' : 'carousel_settings',
        'fluent-cart/product_ids'       : 'product_ids',
        'fluent-cart/has_controls'      : 'has_controls',
        'fluent-cart/has_pagination'    : 'has_pagination',
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({className: 'fluent-cart-product-carousel-block'});
        const [selectedProduct, setSelectedProduct] = useState([]);
        const hasMountedRef = useRef(false);

        const [products, setProducts] = useState([]);
        const [isLoading, setIsLoading] = useState(false);
        const slidesToShow = Math.max(1, Number(attributes.carousel_settings.slidesToShow || 4));
        const infinite = attributes.carousel_settings.infinite === 'yes';

        // Detect whether controls/pagination inner blocks exist
        const { has_controls, has_pagination } = useSelect(
            (select) => {
                const innerBlocks = select('core/block-editor').getBlocks(clientId);
                return {
                    has_controls: innerBlocks.some(
                        (b) => b.name === 'fluent-cart/product-carousel-controls'
                    ),
                    has_pagination: innerBlocks.some(
                        (b) => b.name === 'fluent-cart/product-carousel-pagination'
                    ),
                };
            },
            [clientId]
        );

        useEffect(() => {
            if (!hasMountedRef.current) {
                if (!has_controls && !has_pagination) {
                    return;
                }
                hasMountedRef.current = true;
            }

            setAttributes({
                has_controls: has_controls ? 'yes' : 'no',
                has_pagination: has_pagination ? 'yes' : 'no',
            });
        }, [has_controls, has_pagination]);

        const isInsideProductInfo = useSelect((select) => {
            const { getBlockParents, getBlockName } = select(blockEditorStore);
            return getBlockParents(clientId).some(id => getBlockName(id) === "fluent-cart/product-info");
            }, [clientId]);

        useEffect(() => {
            const value = isInsideProductInfo ? "yes" : "no";

            if (attributes.inside_product_info !== value) {
                setAttributes({ inside_product_info: value });
            }
        }, [isInsideProductInfo]);

        const fetchProduct = () => {
            const hasIds = Array.isArray(attributes.product_ids) && attributes.product_ids.length > 0;

            if(!hasIds) return;
            
            setIsLoading(true);

            const url = rest.url + '/products/fetchProductsByIds';

            const params =  { 
                productIds: attributes.product_ids,
                with: ['detail', 'variants']
            };

            apiFetch({
                path: addQueryArgs(url, params),
                headers: {
                    'X-WP-Nonce': rest.nonce
                },
            }).then((response) => {
                const products = Array.isArray(response?.products?.data)
                    ? response.products.data
                    : Array.isArray(response?.products)
                        ? response.products
                        : Array.isArray(response)
                            ? response
                            : [];


                setSelectedProduct(products);

                console.log('api response', selectedProduct)


            }).finally(() => {
                setIsLoading(false);
            });
        }

        useEffect(() => {
            fetchProduct();
        }, [attributes.product_ids]);

        useEffect(() => {

        }, [selectedProduct]);
        
        const visibleProducts = (() => {
            if (!selectedProduct.length) {
                return Array.from({ length: slidesToShow }).map((_, i) => ({
                ID: `placeholder-${i}`,
                isPlaceholder: true,
                }));
            }

            if (!infinite || selectedProduct.length >= slidesToShow) {
                return selectedProduct.slice(0, slidesToShow);
            }

            const result = [...selectedProduct];
            let i = 0;
            while (result.length < slidesToShow) {
                result.push(selectedProduct[i % selectedProduct.length]);
                i++;
            }
            return result.slice(0, slidesToShow);
        })();

        const totalPages = Math.ceil((selectedProduct.length || slidesToShow) / slidesToShow);

        const currentBlock = useSelect(
            (select) => select('core/block-editor').getBlock(clientId),
            [clientId]
        );
        
        const [count, setCount] = useState(1);

        useEffect(() => {
            if (count > 1) {
                lastChanged = new Date().toISOString();
                return;
            }
            setCount(count + 1);
        }, [currentBlock]);

        const blocks = currentBlock.innerBlocks;

        const [ activeBlockContextId, setActiveBlockContextId ] = useState();
        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                />
                
                <div className="fct-product-gallery-wrapper is-editor-preview">
                    <div
                        className="fct-product-gallery-carousel editor-carousel-preview"
                        style={{
                            display: 'grid',
                            gap: '16px',
                        }}
                    >
                        {isLoading || !selectedProduct.length ? (
                            <>
                                <div className="fluent-cart-empty">
                                    <div className="fct-loading-header">
                                        <div className="fct-loading-spinner"></div>
                                        <span>{blocktranslate("Loading products...")}</span>
                                    </div>
                                    <div
                                        className="dummy-grid"
                                        style={{ '--fct-slides-per-view': slidesToShow }}
                                    >
                                        {Array.from({ length: slidesToShow }).map((_, i) => (
                                            <div key={i} className="dummy-card" style={{ '--card-index': i }}>
                                                <div className="dummy-image skeleton"></div>
                                                <div className="dummy-content">
                                                    <div className="dummy-title skeleton"></div>
                                                    <div className="dummy-excerpt skeleton"></div>
                                                    <div className="dummy-price skeleton"></div>
                                                </div>
                                                <div className="dummy-btn-wrap">
                                                    <div className="dummy-btn"></div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </>
                            ) : (
                                <ParentDataProvider value={{
                                    products: visibleProducts
                                }}>
                                    <InnerBlocks
                                        template={TEMPLATE}
                                    >
                                    </InnerBlocks>
                                </ParentDataProvider>
                            )}
                    </div>
                </div>    
            </div>
        );
    },
    save: () => {
        const blockProps = useBlockProps.save({
            className: 'fluent-cart-product-carousel-block'
        });

        return (
            <div {...blockProps}>
                <InnerBlocks.Content />
            </div>
        );
    },
});
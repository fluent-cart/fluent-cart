import { MediaCarousel } from "@/BlockEditor/Icons";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import InspectorSettings from "@/BlockEditor/MediaCarousel/Components/InspectorSettings.jsx";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import MediaCarouselPreview from "./ProductCarousel.png";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ParentDataProvider} from "@/BlockEditor/ShopApp/Context/ProductContext";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {normalizeVariations} from "./utils/variationUtils";

const {
  InnerBlocks,
  useBlockProps,
  useInnerBlocksProps,
  __experimentalUseBlockPreview,
  BlockContextProvider
} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState, useRef, useMemo} = wp.element;

const { useSelect } = wp.data;
const { store: blockEditorStore } = wp.blockEditor;

const blockEditorData = window.fluent_cart_media_carousel_block_editor_data;
const rest = window['fluentCartRestVars'].rest;

// InnerBlocks template
const TEMPLATE = [
    [
        'fluent-cart/media-carousel-loop',
        {
            className: 'fluent-cart-media-carousel-loop',
            layout: {type: 'constrained'},
            metadata: {name: 'Media Carousel Loop'},
        },
        [
            ['fluent-cart/media-carousel-product-image'],
        ],
    ],
    ['fluent-cart/media-carousel-controls'],
    ['fluent-cart/media-carousel-pagination'],
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
                    url: MediaCarouselPreview,
                    alt: 'Media Carousel Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    icon: {
        src: MediaCarousel,
    },
    category: "fluent-cart",
    supports: {
        html: false,
        reusable: false,
        innerBlocks: true,
    },
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        variation_ids: {
            type: 'array',
            default: [],
        },
        inside_product_info: { type: 'string', default: '' },
        query_type: {
            type: 'string',
            default: 'default',
        },
        carousel_settings: {
            type: 'object',
            default: {
                slidesToShow: 3,
                autoplay: 'yes', // 'no' | 'yes' | 'hover'
                autoplayDelay: 3000,
                arrows: 'yes',
                arrowsSize: 'md', // sm | md | lg
                pagination: 'yes',
                paginationType: 'bullets',   // bullets | fraction | progress
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
        'fluent-cart/query_type'        : 'query_type',
        'fluent-cart/carousel_settings' : 'carousel_settings',
        'fluent-cart/product_id'        : 'product_id',
        'fluent-cart/variation_ids'     : 'variation_ids',
        'fluent-cart/has_controls'      : 'has_controls',
        'fluent-cart/has_pagination'    : 'has_pagination',
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({className: 'fluent-cart-media-carousel-block'});
        const [selectedProduct, setSelectedProduct] = useState([]);
        const [selectedVariations, setSelectedVariations] = useState({});
        const [isLoading, setIsLoading] = useState(false);
        const hasMountedRef = useRef(false);
        const { has_controls, has_pagination } = useSelect(
            (select) => {
                const innerBlocks = select('core/block-editor').getBlocks(clientId);

                return {
                    has_controls: innerBlocks.some(
                        (b) => b.name === 'fluent-cart/media-carousel-controls'
                    ),
                    has_pagination: innerBlocks.some(
                        (b) => b.name === 'fluent-cart/media-carousel-pagination'
                    ),
                    innerCount: innerBlocks.length,
                };
            },
            [clientId]
        );

        useEffect(() => {
            // Skip the initial render to avoid overwriting saved attributes
            if (!hasMountedRef.current) {
                hasMountedRef.current = true;
                return;
            }

            setAttributes({
                has_controls: has_controls ? 'yes' : 'no',
                has_pagination: has_pagination ? 'yes' : 'no',
            });
        }, [has_controls, has_pagination]);

        const isInsideProductInfo = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);
            // Get all parent block IDs of this block
            const parents = getBlockParents(clientId);
            // Check if any parent has particular blockName
            return parents.some((parentId) => {
                const name = getBlockName(parentId);
                return [
                    'fluent-cart/products',
                    'fluent-cart/shopapp-product-container',
                    'fluent-cart/shopapp-product-loop',
                    'fluent-cart/product-info',
                ].includes(name);
            });
        }, [clientId]);

        useEffect(() => {
            const value = isInsideProductInfo ? "yes" : "no";
            setAttributes({ inside_product_info: value });
        }, [isInsideProductInfo]);

        const singleProductData = useSingleProductData();

        useEffect(() => {
            if (!isInsideProductInfo) {
                return;
            }

            // If inside_product_info is already "yes", defaults were applied
            // in a previous session — don't override user's custom settings
            if (attributes.inside_product_info === 'yes') {
                return;
            }

            const current = attributes.carousel_settings || {};
            setAttributes({
                carousel_settings: {
                    ...current,
                    slidesToShow: 1,
                    autoplay: 'hover',
                    pagination: 'no',
                    arrowsSize: 'sm',
                },
            });
        }, [isInsideProductInfo]);

        const slidesToShow = Math.max(1, Number(attributes.carousel_settings.slidesToShow || 3));

        // Memoize dummy cards array
        const dummyCards = useMemo(() =>
            Array.from({ length: slidesToShow }),
            [slidesToShow]
        );

        const currentBlock = useSelect(
            (select) => select('core/block-editor').getBlock(clientId),
            [clientId]
        );

        useEffect(() => {
            if (isInsideProductInfo) return;
            if (!attributes.product_id || !attributes.variation_ids.length) return;

            const fetchVariations = () => {
                const fetchUrl = rest.url + '/products/' + attributes.product_id;
                apiFetch({
                    path: addQueryArgs(fetchUrl, {
                        with: ['detail', 'variants']
                    }),
                    headers: {
                        'X-WP-Nonce': rest.nonce
                    }
                }).then((response) => {
                    if (!response?.product) return;

                    const variationIds = (attributes.variation_ids || []).map(Number);
                    const product = response.product;

                    // Filter variants based on selected variation_ids
                    const filteredProduct = {
                        ...product,
                        variants: Array.isArray(product.variants)
                            ? product.variants.filter(variant =>
                                variationIds.includes(Number(variant.id))
                            )
                            : []
                    };

                    setSelectedProduct([filteredProduct]);

                    const normalized = normalizeVariations(response.product, attributes.variation_ids);
                    setSelectedVariations(normalized);
                })
                .catch((error) => {
                    console.error('Failed to fetch variations:', error);
                });
            };

            fetchVariations();
        }, [isInsideProductInfo, attributes.product_id, attributes.variation_ids]);
        
        useEffect(() => {
            if (singleProductData?.product) {
                const product = singleProductData.product;

                if (!product || !product.ID) {
                    return;
                }
                setSelectedProduct([product]);
            }
        }, [singleProductData?.product]);

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    selectedVariations={selectedVariations}
                    setSelectedVariations={setSelectedVariations}
                    isInsideProductInfo={isInsideProductInfo}
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
                                        <span>{blocktranslate("Loading media...")}</span>
                                    </div>
                                    <div
                                        className="dummy-grid"
                                        style={{
                                            '--fct-slides-per-view': slidesToShow,
                                        }}
                                        >
                                        {dummyCards.map((_, i) => (
                                            <div key={i} className="dummy-media-card" style={{ '--card-index': i }}>
                                                <div className="dummy-image skeleton"></div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </>
                            ) : (
                                <ParentDataProvider value={{
                                    products: selectedProduct
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
    save: ({ attributes }) => {
        const blockProps = useBlockProps.save({
            className: 'fluent-cart-media-carousel-block'
        });

        return (
            <div {...blockProps}>
                <InnerBlocks.Content />
            </div>
        );
    },
});
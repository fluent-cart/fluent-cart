// Import necessary dependencies
import InspectorSettings from "@/BlockEditor/ShopApp/Settings/InspectorSettings.jsx";
import Preview from "@/BlockEditor/ShopApp/Preview/Preview.jsx";
import DefaultData from "@/BlockEditor/ShopApp/Data/Data";
import {Product} from "../Icons";
import ProductsPreview from "./Products.png";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import ServerSidePreview from "@/BlockEditor/Components/ServerSidePreview.jsx";
import {ParentDataProvider} from "@/BlockEditor/ShopApp/Context/ProductContext";
import apiFetch from "@wordpress/api-fetch";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {registerBlockType} = wp.blocks;
const {useEffect, useState, Component, useMemo, createContext} = wp.element;
const ServerSideRender = wp.serverSideRender;

const ParentElement = createContext();


const {useBlockProps, InnerBlocks, BlockContextProvider} = wp.blockEditor;

// Access block editor data from global window object
const blockEditorData = window.fluent_cart_shop_app_block_editor_data;

// Translator function for internationalization
window.fluentCartShopAppTranslator = (str) => {
    return window.fluent_cart_shop_app_block_editor_data?.trans[str] || str;
};

// Utility to merge colors with defaults
const mergeColors = (attributes) => {
    const merged = {...DefaultData.colors, ...(attributes.colors || {})};
    return merged;
};

const restUrl = window.fluentCartRestVars.rest.url;
const baseUrl = restUrl + '/public/products';


// Error Boundary to catch component errors

const CustomBlockAppender = () => {
    return (
        <InnerBlocks.ButtonBlockAppender>
            {({onClick}) => (
                <button className="custom-add-block-button" onClick={onClick}>
                    {blocktranslate('Add Child Block')}
                </button>
            )}
        </InnerBlocks.ButtonBlockAppender>
    );
};

const DEFAULT_TEMPLATE = [
    [
        'fluent-cart/shopapp-product-action-container',
        {},
        [
            ['fluent-cart/shopapp-product-view-switcher'],
            ['fluent-cart/shopapp-product-filter-sort-by'],
        ]
    ],
    [
        'fluent-cart/shopapp-product-container',
        {
            className: 'fluent-product-container',
        },
        [
            [
                'fluent-cart/shopapp-product-loader',
                {
                    layout: {type: 'constrained'},
                    metadata: {name: 'Product Loader'},
                },
                [
                    [
                        'fluent-cart/shopapp-product-spinner'
                    ],
                ]
            ],
            [
                'fluent-cart/shopapp-product-filter',
                {},
                [
                    ['fluent-cart/shopapp-product-filter-search-box'],
                    ['fluent-cart/shopapp-product-filter-filters'],
                    [
                        'fluent-cart/shopapp-product-filter-button',
                        {},
                        [
                            ['fluent-cart/shopapp-product-filter-apply-button'],
                            ['fluent-cart/shopapp-product-filter-reset-button']
                        ]
                    ],
                ]
            ],
            [
                'fluent-cart/shopapp-product-no-result',
                {
                    className: 'fluent-product-no-result',
                    layout: {type: 'constrained'},
                    metadata: {name: 'No Result'},
                },
                [
                    [
                        'core/paragraph',
                        {
                            "content": "No results found",
                            "align": "center",
                            "fontSize": "large"
                        }
                    ],
                    [
                        'core/paragraph',
                        {
                            /* translators: %1$s, %2$s, %3$s, %4$s is the anchor tag */
                            "content": blocktranslate("You can try %1$s clearing any filters %2$s or head to our %3$s store's home %4$s", '<a href="#">', '</a>', '<a href="#">', '</a>'),
                            "align": "center"
                        }
                    ]
                ]
            ],
            [
                'fluent-cart/shopapp-product-loop',
                {
                    className: 'fluent-product-loop',
                    layout: {type: 'constrained'},
                    metadata: {name: 'Product Loop'},
                },
                [
                    ['fluent-cart/shopapp-product-image'],
                    ['fluent-cart/shopapp-product-title'],
                    ['fluent-cart/shopapp-product-price'], // (duplicate, but valid syntax-wise)
                    ['fluent-cart/shopapp-product-buttons'],
                ],
            ],
        ]
    ],
    [
        'fluent-cart/product-paginator',
        {
            className: 'fluent-product-paginator',
            layout: {type: 'constrained'},
            metadata: {name: 'Paginator'},
        },
        [
            ['fluent-cart/product-paginator-info'],
            ['fluent-cart/product-paginator-number'],
        ]
    ],
];


const blockName = `${blockEditorData.slug}/${blockEditorData.name}`;

registerBlockType(blockName, {
    apiVersion: 2,
    providesContext: {
        'fluent-cart/paginator': 'paginator',
        'fluent-cart/per_page': 'per_page',
        'fluent-cart/enable_filter': 'enable_filter',
        'fluent-cart/product_box_grid_size': 'product_box_grid_size',
        'fluent-cart/view_mode': 'view_mode',
        'fluent-cart/filters': 'filters',
        'fluent-cart/default_filters': 'default_filters',
        'fluent-cart/order_type': 'order_type',
        'fluent-cart/order_by': 'order_by',
        'fluent-cart/live_filter': 'live_filter',
        'fluent-cart/price_format': 'price_format',
        'fluent-cart/enable_wildcard_filter': 'enable_wildcard_filter'
    },
    title: blockEditorData.title,
    icon: {
        src: Product,
    },
    example: {
        attributes: {
        },
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: ProductsPreview,
                    alt: 'Shop App Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    supports: {
        html: false,
        reusable: false,
        innerBlocks: true,
    },
    category: "fluent-cart",
    attributes: {
        colors: {
            type: "object",
            default: DefaultData.colors || {},
        },
        message: {
            type: "string",
            default: "",
        },
        variations: {
            type: "object",
            default: {},
        },
        ...DefaultData, // Spread additional attributes after specific ones
    },
    edit: ({attributes, setAttributes}) => {

        // const blockType = wp.blocks.getBlockType('core/paragraph'); // or 'core/paragraph'
        // const blockType2 = wp.blocks.getBlockType('fluent/product-title'); // or 'core/paragraph'
        // const blockType3 = wp.blocks.getBlockType('fluent-cart/shopapp-product-title'); // or 'core/paragraph'
        //
        // console.log({
        //     attributes1: blockType2,
        //     attributes2:blockType3,
        // });
        // Apply useBlockProps to the root element
        const blockProps = useBlockProps({
            className: "fluent-cart-product-block-editor",
        });

        const [products, setProducts] = useState([]);

        // Initialize attributes
        useEffect(() => {
            try {
                if (!attributes.colors || Object.keys(attributes.colors).length === 0) {
                    setAttributes({colors: mergeColors(attributes)});
                }
                if (!attributes.variations) {
                    setAttributes({variations: {}});
                }
            } catch (error) {
                console.error("Error in useEffect:", error);
            }
        }, []); // Run once on mount

        useEffect(() => {
            apiFetch({
                path: baseUrl,
            }).then((res) => {
                setProducts(res.products.products.data)
            });
        }, []);

        // Log block props for debugging
        //console.log("Block Props:", blockProps);

        const sharedData = {
            someValue: 'data to share',
            methods: {
                updateSomething: () => { /* ... */ }
            }
        };
        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <div className="flex flex-col gap-4">
                        <InspectorSettings
                            attributes={attributes}
                            setAttributes={setAttributes}
                            blockEditorData={blockEditorData}
                        />

                        {/*<ServerSidePreview*/}
                        {/*    key='shop-app-preview'*/}
                        {/*    block={blockName}*/}
                        {/*    attributes={attributes}*/}
                        {/*/>*/}

                        {/*<Preview*/}
                        {/*    attributes={attributes}*/}
                        {/*    blockEditorData={blockEditorData}*/}
                        {/*/>*/}

                        <div className="fluent-cart-inner-blocks">
                            <ParentDataProvider value={{
                                products: products
                            }}>
                                <InnerBlocks
                                    template={DEFAULT_TEMPLATE}
                                    allowedBlocks={[
                                        'core/group',
                                        'core/columns',
                                        'core/paragraph',
                                        'core/image',
                                        'core/heading',
                                    ]}
                                    templateLock={false} // Allow free editing
                                    renderAppender={InnerBlocks.ButtonBlockAppender}
                                />
                            </ParentDataProvider>


                        </div>
                    </div>
                </ErrorBoundary>
            </div>
        );
    },
    save({attributes}) {
        const columns = 3;
        const blockProps = useBlockProps.save();

        return (
            <div {...blockProps}>
                <div className={`fct-products-wrapper`} data-fluent-cart-shop-app data-fluent-cart-product-wrapper=''>
                    <InnerBlocks.Content/>
                </div>
            </div>
        );
    },
});

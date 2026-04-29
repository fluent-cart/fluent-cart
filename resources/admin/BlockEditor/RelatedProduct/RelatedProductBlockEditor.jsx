import {RelatedProduct} from "@/BlockEditor/Icons";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import RelatedProductInspectorSettings from "@/BlockEditor/RelatedProduct/Components/RelatedProductInspectorSettings";
import ProductDropdownPicker from "@/BlockEditor/Components/ProductPicker/ProductDropdownPicker.jsx";
import "@/BlockEditor/RelatedProduct/Components/ProductTemplateBlock.jsx";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {Placeholder} = wp.components;
const {useEffect, useState} = wp.element;

const blockEditorData = window.fluent_cart_related_product_data;
const rest = window['fluentCartRestVars'].rest;

// Register the main related products block: fluent-cart/related-products
registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: RelatedProduct,
    },
    category: "fluent-cart",
    attributes: {
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        related_product_ids: {
            type: 'array',
            default: [],
        },
        related_products: {
            type: 'array',
            default: [],
        },
        related_by_categories: {
            type: 'boolean',
            default: true,
        },
        related_by_brands: {
            type: 'boolean',
            default: true,
        },
        query_type: {
            type: 'string',
            default: 'custom',
        },
        order_by: {
            type: 'string',
            default: 'title_asc',
        },
        columns: {
            type: 'number',
            default: 4,
        },
        posts_per_page: {
            type: 'number',
            default: 6,
        },
        show_image: {
            type: 'boolean',
            default: true,
        },
        show_title: {
            type: 'boolean',
            default: true,
        },
        show_price: {
            type: 'boolean',
            default: true,
        },
        show_button: {
            type: 'boolean',
            default: true,
        },
    },
    providesContext: {
        'fluent-cart/related_product_ids': 'related_product_ids',
        'fluent-cart/related_products': 'related_products',
        'fluent-cart/product_id': 'product_id',
        'fluent-cart/related_by_categories': 'related_by_categories',
        'fluent-cart/related_by_brands': 'related_by_brands',
        'fluent-cart/query_type': 'query_type',
        'fluent-cart/order_by': 'order_by',
        'fluent-cart/columns': 'columns',
        'fluent-cart/posts_per_page': 'posts_per_page',
        'fluent-cart/show_image': 'show_image',
        'fluent-cart/show_title': 'show_title',
        'fluent-cart/show_price': 'show_price',
        'fluent-cart/show_button': 'show_button',
    },
    supports: {
        innerBlocks: true,
    },
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps({
            className: 'fluent-cart-related-product-block'
        });
        const [selectedProduct, setSelectedProduct] = useState({});
        const [relatedProducts, setRelatedProducts] = useState(attributes.related_products || []);
        const [isLoading, setIsLoading] = useState(false);

        // Layout:
        // fluent-cart/related-products
        // ├── core/heading
        // └── fluent-cart/product-template
        const TEMPLATE = [
            ['core/heading', {level: 2, content: 'Related Products'}],
            ['fluent-cart/product-template'],
        ];

        const fetchProduct = () => {
            apiFetch({
                path: addQueryArgs(rest.url + '/products/' + attributes.product_id, {
                    with: ['variants']
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                setSelectedProduct(response.product || {});
            }).catch((error) => {
                console.error('Error fetching product:', error);
            });
        }

        const fetchRelatedProducts = () => {
            setIsLoading(true);
            apiFetch({
                path: addQueryArgs(rest.url + '/products/' + attributes.product_id + '/related-products', {
                    related_by_categories: attributes.related_by_categories ? 1 : 0,
                    related_by_brands: attributes.related_by_brands ? 1 : 0,
                    order_by: attributes.order_by,
                    posts_per_page: attributes.posts_per_page,
                }),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            }).then((response) => {
                const products = response.products || [];
                setRelatedProducts(products);

                const productIds = products.map(p => p.ID);
                setAttributes({
                    related_product_ids: productIds,
                    related_products: products
                });
            }).catch((error) => {
                console.error('Error fetching related products:', error);
            }).finally(() => {
                setIsLoading(false);
            });
        }

        // Custom mode: fetch product and related products when product_id or settings change
        useEffect(() => {
            if (attributes.query_type === 'custom' && attributes.product_id) {
                fetchProduct();
                fetchRelatedProducts();
            }
        }, [attributes.product_id, attributes.related_by_categories, attributes.related_by_brands, attributes.order_by, attributes.posts_per_page, attributes.query_type]);

        // Default mode: clear editor preview data (products come from page context on frontend)
        useEffect(() => {
            if (attributes.query_type === 'default') {
                setRelatedProducts([]);
                setSelectedProduct({});
                setAttributes({
                    product_id: '',
                    related_product_ids: [],
                    related_products: []
                });
            }
        }, [attributes.query_type]);

        // Custom mode without product selected: show placeholder
        if (attributes.query_type === 'custom' && !attributes.product_id) {
            return (
                <div {...blockProps}>
                    <RelatedProductInspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        selectedProduct={selectedProduct}
                        setSelectedProduct={setSelectedProduct}
                    />
                    <Placeholder
                        icon={RelatedProduct}
                        label={blocktranslate('Related Products')}
                        instructions={blocktranslate('Related products requires a product to be selected in order to display associated items.')}
                        className="fct-related-products-placeholder"
                    >
                        <ProductDropdownPicker
                            selectedProduct={selectedProduct}
                            onSelect={(product) => {
                                setAttributes({ product_id: product.ID || '' });
                                setSelectedProduct(product);
                            }}
                        />
                    </Placeholder>
                </div>
            );
        }

        // Default mode or custom mode with product selected: show block content
        return (
            <div {...blockProps}>
                <RelatedProductInspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                />

                {isLoading && (
                    <Placeholder
                        icon={RelatedProduct}
                        label={blocktranslate('Loading Related Products...')}
                    />
                )}

                {!isLoading && (
                    <InnerBlocks
                        template={TEMPLATE}
                        templateLock={false}
                        allowedBlocks={[
                            'core/heading',
                            'fluent-cart/product-template',
                        ]}
                    />
                )}
            </div>
        );
    },
    save: () => {
        const blockProps = useBlockProps.save({
            className: 'fluent-cart-related-products'
        });

        return (
            <div {...blockProps}>
                <InnerBlocks.Content />
            </div>
        );
    },
});

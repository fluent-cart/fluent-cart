import {useProductData} from "@/BlockEditor/ShopApp/Context/ProductContext";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import { ProductContainerContext } from "@/BlockEditor/ShopApp/Context/ProductContainerContext";

const {
    InnerBlocks,
    useBlockProps,
    useInnerBlocksProps,
    __experimentalUseBlockPreview,
} = wp.blockEditor;

const useBlockPreview = __experimentalUseBlockPreview || null;

const {useContext, useEffect, useState, useMemo, memo} = wp.element;
const {useSelect} = wp.data;

const placeholderImage = window.fluent_cart_product_image_data?.placeholder_image
    || window.fluent_cart_block_editor_asset?.placeholder_image
    || '';

/**
 * Editable inner blocks (active image slot)
 */
const ImageTemplateInnerBlocks = () => {
    const innerBlocksProps = useInnerBlocksProps(
        {className: 'fct-media-carousel-slide'},
        {__unstableDisableLayoutClassNames: true}
    );
    return <div {...innerBlocksProps} />;
};

/**
 * Non-editable preview (other image slots)
 */
const ImageTemplateBlockPreview = ({
    blocks,
    blockContextId,
    isHidden,
    setActiveBlockContextId,
}) => {
    const blockPreviewProps = useBlockPreview({
        blocks,
        props: {
            className: 'fct-media-carousel-slide',
        },
    });

    const handleOnClick = () => {
        setActiveBlockContextId(blockContextId);
    };

    const style = {
        display: isHidden ? 'none' : undefined,
    };

    return (
        <div
            {...blockPreviewProps}
            tabIndex={0}
            role="button"
            onClick={handleOnClick}
            onKeyPress={handleOnClick}
            style={style}
        />
    );
};

const MemoizedImagePreview = memo(ImageTemplateBlockPreview);

/**
 * Single image slide: shows editable template OR preview
 */
const ImageSlideContent = ({
    displayTemplate,
    blocks,
    imageIndex,
    setActiveBlockContextId,
}) => {
    return (
        <>
            {displayTemplate ? <ImageTemplateInnerBlocks /> : null}
            <MemoizedImagePreview
                blocks={blocks}
                blockContextId={imageIndex}
                setActiveBlockContextId={setActiveBlockContextId}
                isHidden={displayTemplate}
            />
        </>
    );
};

/**
 * Build image list from a product (gallery + variant images)
 */
const buildImagesFromProduct = (product) => {
    if (!product) return [];

    const imgs = [];

    // Gallery images
    const gallery = Array.isArray(product?.detail?.gallery_image?.meta_value) ? product.detail.gallery_image.meta_value : [];
    gallery.forEach((img) => {
        if (img?.url) imgs.push(img);
    });

    // Variant images
    const variants = product?.variants || [];
    variants.forEach((variant) => {
        const variantImages = Array.isArray(variant?.media?.meta_value) ? variant.media.meta_value : [];
        variantImages.forEach((img) => {
            if (img?.url) imgs.push(img);
        });
    });

    // Placeholder fallback
    if (!imgs.length) {
        imgs.push({
            id: 'placeholder',
            url: placeholderImage,
            title: 'Product Image',
        });
    }

    return imgs;
};

const MediaCarouselLoopBlock = {
    attributes: {
        wp_client_id: {
            type: 'string',
            default: ''
        },
        last_changed: {
            type: 'string',
            default: ''
        }
    },
    edit: (props) => {
        const {attributes, setAttributes, clientId, context} = props;
        const containerContext = useContext(ProductContainerContext) || {};
        const { simulateLoading, simulateNoResults } = containerContext;

        let isHidden = simulateLoading || simulateNoResults ? 'hide' : '';

        const blockProps = useBlockProps({
            className: 'fct-shop-app-preview-wrap ' + isHidden,
        });

        useEffect(() => {
            setAttributes({last_changed: new Date().toISOString()});
            if (attributes.wp_client_id) {
                return;
            }
            setAttributes({wp_client_id: clientId});
        }, [clientId, attributes.wp_client_id, setAttributes]);

        // Get product from parent context (MediaCarouselBlockEditor provides ParentDataProvider)
        const parentData = useProductData();
        const product = parentData?.products?.[0] || null;

        // Carousel settings from block context
        const carouselSettings = context['fluent-cart/carousel_settings'] || {};
        const slidesToShow = Math.max(1, Number(carouselSettings?.slidesToShow || 3));

        // Build image list from product
        const images = useMemo(
            () => buildImagesFromProduct(product),
            [product]
        );

        // Get inner blocks for preview
        const currentBlock = useSelect(
            (select) => select('core/block-editor').getBlock(clientId),
            [clientId]
        );
        const blocks = currentBlock?.innerBlocks || [];

        const [activeImageIndex, setActiveImageIndex] = useState(0);

        // Only show slidesToShow images (carousel shows one row at a time)
        const visibleImages = images.slice(0, slidesToShow);

        return (
            <div {...blockProps}>
                <div
                    className="fct-product-loop-editor"
                    style={{
                        display: 'grid',
                        gridTemplateColumns: `repeat(${Math.min(slidesToShow, visibleImages.length || 1)}, 1fr)`,
                        gap: '12px',
                    }}
                >
                    {visibleImages.map((image, index) => {
                        const displayTemplate = index === (activeImageIndex ?? 0);

                        return (
                            <SingleProductDataProvider
                                key={image.id || index}
                                value={{
                                    product: product,
                                    currentImage: image,
                                    imageIndex: index,
                                }}
                            >
                                <div className="fluent-cart-product-loop fct-product-block-editor-product-card">
                                    <ImageSlideContent
                                        displayTemplate={displayTemplate}
                                        blocks={blocks}
                                        imageIndex={index}
                                        setActiveBlockContextId={setActiveImageIndex}
                                    />
                                </div>
                            </SingleProductDataProvider>
                        );
                    })}
                </div>
            </div>
        );
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps} className="fluent-cart-product-loop fct-product-block-editor-product-card">
                <InnerBlocks.Content/>
            </div>
        );
    },
    supports: {
        align: ['wide', 'full'],
        html: false,
    },
    usesContext: [
        'fluent-cart/query_type',
        'fluent-cart/carousel_settings',
        'fluent-cart/product_id',
        'fluent-cart/variation_ids',
        'fluent-cart/has_controls',
        'fluent-cart/has_pagination'
    ]
};

export default MediaCarouselLoopBlock;

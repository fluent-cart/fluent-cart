import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/ProductRating/Components/InspectorSettings";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {ProductRatingStars, ReviewStar} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;

const blockEditorData = window.fluent_cart_product_rating_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ProductRatingStars,
    },
    category: "fluent-cart",
    attributes: {
        query_type: {
            type: 'string',
            default: 'default',
        },
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        // Mirrors ProductRatingBlockEditor::blockAttributes() — an attribute
        // missing on either side never reaches the render callback.
        minReviewCount: {
            type: 'number',
            default: 0,
        },
        minAverageRating: {
            type: 'number',
            default: 0,
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-product-rating-block-editor',
        });

        // The reviews module is off: the block stays in the template (so a
        // card built with it keeps parsing) but draws nothing, as the page
        // will. Read after the hooks above so their order never changes.
        const reviewsActive = !!blockEditorData.reviews_active;

        const insideParent = useInsideProductReviews(clientId);

        // Same product resolution the review blocks use: custom fetches the
        // picked product, default follows the surrounding container's
        // provider (loops, Product Info) when one exists.
        const {selectedProduct, setSelectedProduct} = useReviewBlockProduct(attributes, insideParent, false);

        // Inside a product-owning container that container owns the product:
        // pin to the default query so the container's setup wins.
        wp.element.useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        const avgRating = selectedProduct?.detail?.other_info?.average_rating || 0;
        const reviewCount = selectedProduct?.detail?.other_info?.review_count || 0;

        const renderStars = (rating) => {
            const stars = [];
            for (let i = 1; i <= 5; i++) {
                const isFull = i <= Math.floor(rating);
                const isHalf = !isFull && i === Math.floor(rating) + 1 && (rating - Math.floor(rating)) >= 0.5;
                let cls = 'fct-star fct-star-empty';
                if (isFull) cls = 'fct-star fct-star-filled';
                else if (isHalf) cls = 'fct-star fct-star-half';
                stars.push(<span key={i} className={cls}><ReviewStar/></span>);
            }
            return stars;
        };

        // The canvas says what the page will do. A product under the
        // threshold renders nothing there, so showing stars here would
        // promise a rating the page is not going to print.
        const minReviewCount = parseInt(attributes.minReviewCount, 10) || 0;
        const minAverageRating = parseFloat(attributes.minAverageRating) || 0;
        const isBelowThreshold =
            (minReviewCount > 0 && reviewCount < minReviewCount)
            || (minAverageRating > 0 && avgRating < minAverageRating);

        if (!reviewsActive) {
            return null;
        }

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                />

                {isBelowThreshold ? (
                    <span className="fct-product-rating-block-editor-hidden">
                        {blocktranslate('Hidden — this product is below the thresholds set here')}
                    </span>
                ) : (
                    <>
                        <span className="fct-product-card-stars">
                            {renderStars(avgRating)}
                        </span>
                        <span className="fct-product-card-review-count">
                            ({reviewCount})
                        </span>
                    </>
                )}
            </div>
        );
    },
    save: function () {
        return null;
    },
    supports: {
        // Mirrors ProductRatingBlockEditor::supports() — the client's supports
        // replace the server's, so the inserter flag is set here too.
        inserter: !!blockEditorData.reviews_active,
        html: false,
        align: ["left", "center", "right"],
        typography: {
            fontSize: true,
            lineHeight: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        color: {
            text: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
    },
});

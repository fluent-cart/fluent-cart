import InspectorSettings from "@/BlockEditor/ProductRating/Components/InspectorSettings";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {ProductRatingStars} from "@/BlockEditor/Icons";

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
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-product-rating-block-editor',
        });

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
                stars.push(<span key={i} className={cls}>★</span>);
            }
            return stars;
        };

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                />

                <span className="fct-product-card-stars">
                    {renderStars(avgRating)}
                </span>
                <span className="fct-product-card-review-count">
                    ({reviewCount})
                </span>
            </div>
        );
    },
    save: function () {
        return null;
    },
    supports: {
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

import { sampleReview } from "./sampleReview";

const { useBlockProps } = wp.blockEditor;

/**
 * The item a review names, for a product whose variations are worth telling
 * apart. A product-level review renders nothing here.
 */
const ReviewVariationTitleBlock = {
    edit: ({ context }) => {
        const review = context?.['fluent-cart/review'] || sampleReview();
        const blockProps = useBlockProps({ className: 'fct-review-item-variant' });

        return <div {...blockProps}>{review.item_label}</div>;
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewVariationTitleBlock;

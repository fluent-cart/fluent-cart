import { sampleReview } from "./sampleReview";

const { useBlockProps } = wp.blockEditor;

const ReviewDateBlock = {
    edit: ({ context }) => {
        const review = context?.['fluent-cart/review'] || sampleReview();
        const blockProps = useBlockProps({ className: 'fct-review-item-date' });

        return <div {...blockProps}>{review.created_at}</div>;
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewDateBlock;

import { sampleReview } from "./sampleReview";

const { useBlockProps } = wp.blockEditor;

const ReviewTitleBlock = {
    edit: ({ context }) => {
        const review = context?.['fluent-cart/review'] || sampleReview();
        const blockProps = useBlockProps({ className: 'fct-review-item-title' });

        return <div {...blockProps}>{review.title}</div>;
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewTitleBlock;

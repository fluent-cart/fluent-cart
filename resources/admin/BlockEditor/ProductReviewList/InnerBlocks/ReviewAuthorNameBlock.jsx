import { sampleReview } from "./sampleReview";

const { useBlockProps } = wp.blockEditor;

const ReviewAuthorNameBlock = {
    edit: ({ context }) => {
        const review = context?.['fluent-cart/review'] || sampleReview();
        const blockProps = useBlockProps({ className: 'fct-review-item-author' });

        return <div {...blockProps}>{review.reviewer_name}</div>;
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewAuthorNameBlock;

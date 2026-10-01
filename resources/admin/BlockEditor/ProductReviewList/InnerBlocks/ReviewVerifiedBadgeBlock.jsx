import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;

/**
 * Shown only for a review that carries the badge, and only while the store's
 * Verified Owner setting is on — both decided server-side. The editor always
 * draws it, or there would be nothing to lay out.
 */
const ReviewVerifiedBadgeBlock = {
    edit: () => {
        const blockProps = useBlockProps({ className: 'fct-review-verified' });

        return <div {...blockProps}>{blocktranslate('Verified Purchase')}</div>;
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewVerifiedBadgeBlock;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { ReviewReplyArrow } from "@/BlockEditor/Icons";

const { useBlockProps } = wp.blockEditor;

/**
 * The View Reply trigger, in the markup the server emits — Reviews.js binds it
 * on the page by these attributes; here it is inert but wears the same button.
 */
const ReviewReplyBlock = {
    edit: () => {
        const blockProps = useBlockProps({ className: 'fct-review-footer' });

        return (
            <div {...blockProps}>
                <button
                    type="button"
                    className="fct-review-view-replies"
                    tabIndex={-1}
                >
                    <ReviewReplyArrow />
                    <span>{blocktranslate('View Reply')}</span>
                </button>
            </div>
        );
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewReplyBlock;

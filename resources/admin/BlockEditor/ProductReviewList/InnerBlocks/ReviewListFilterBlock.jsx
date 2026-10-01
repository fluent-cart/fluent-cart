import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { ReviewStar } from "@/BlockEditor/Icons";

const { useBlockProps } = wp.blockEditor;

const STARS = [5, 4, 3, 2, 1];

/**
 * The star filter chips.
 *
 * One block for the whole strip rather than one per star: the storefront
 * script and PRO both treat the chip container as a single control — PRO
 * appends its own chip into it — so a split would leave them nowhere to write.
 */
const ReviewListFilterBlock = {
    edit: () => {
        const blockProps = useBlockProps({ className: 'fct-reviews-filter-chips' });

        return (
            <div {...blockProps}>
                <span className="fct-filter-chip active">{blocktranslate('All')}</span>
                {STARS.map((star) => (
                    <span className="fct-filter-chip" key={star}>
                        {star}
                        <ReviewStar />
                    </span>
                ))}
            </div>
        );
    },
    save: () => null,
    supports: {
        html: false,
        spacing: { margin: true, padding: true },
    },
    usesContext: [
        'fluent-cart/review_list_header',
        'fluent-cart/review_list_rows_only',
    ],
};

export default ReviewListFilterBlock;

import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;

/* A stand-in total: the editor has no live count, and the page rewrites this
   number on every filter and page change anyway. */
const SAMPLE_TOTAL = 12;

/**
 * "12 Reviews" above the list.
 *
 * The editor has no live total, so it shows a sample one. On the page the
 * number sits in its own span and the storefront script rewrites it in place
 * on every filter and page change.
 */
const ReviewListCountBlock = {
    edit: () => {
        const blockProps = useBlockProps({ className: 'fct-reviews-section-title' });

        return (
            <h3 {...blockProps}>
                {SAMPLE_TOTAL} {blocktranslate('Reviews')}
            </h3>
        );
    },
    save: () => null,
    supports: {
        html: false,
        align: ['left', 'center', 'right'],
        typography: { fontSize: true, lineHeight: true },
        spacing: { margin: true, padding: true },
        color: { text: true },
    },
    usesContext: [
        'fluent-cart/review_list_header',
        'fluent-cart/review_list_rows_only',
    ],
};

export default ReviewListCountBlock;

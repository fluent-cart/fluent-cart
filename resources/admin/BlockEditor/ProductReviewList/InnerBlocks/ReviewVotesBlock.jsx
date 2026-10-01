import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;

/**
 * The Helpful votes.
 *
 * PRO builds the real buttons client-side and appends them to the review's
 * footer, so this draws what they will look like rather than the buttons
 * themselves — the editor has no vote counts and a server-rendered copy would
 * stop PRO binding the real ones.
 */
const ReviewVotesBlock = {
    edit: () => {
        const blockProps = useBlockProps({ className: 'fct-review-votes' });

        return (
            <div {...blockProps}>
                <span className="fct-vote-label">{blocktranslate('Helpful?')}</span>
                <span className="fct-vote-btn">
                    <span aria-hidden="true">&#128077;</span>
                    <span className="fct-vote-count">0</span>
                </span>
                <span className="fct-vote-btn">
                    <span aria-hidden="true">&#128078;</span>
                    <span className="fct-vote-count">0</span>
                </span>
            </div>
        );
    },
    save: () => null,
    supports: {
        html: false,
        spacing: { margin: true, padding: true },
    },
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewVotesBlock;

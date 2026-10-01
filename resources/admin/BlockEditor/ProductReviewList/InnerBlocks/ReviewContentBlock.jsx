import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { sampleReview } from "./sampleReview";

const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, RangeControl } = wp.components;

/**
 * The most a review may be cut down to. Past this a "limit" stops being one:
 * the reviews on the page are simply whole again.
 */
const MAX_WORDS = 200;

/**
 * Preview the same whitespace-separated word limit used on the storefront.
 */
const trimWords = (text, limit) => {
    if (!limit) {
        return text;
    }

    const words = String(text).trim().split(/\s+/);

    return words.length <= limit ? text : words.slice(0, limit).join(' ') + '…';
};

const ReviewContentBlock = {
    attributes: {
        maxWords: {
            type: 'number',
            default: 0,
        },
    },
    edit: ({ attributes, setAttributes, context }) => {
        const { maxWords } = attributes;
        const review = context?.['fluent-cart/review'] || sampleReview();
        const blockProps = useBlockProps({ className: 'fct-review-item-content' });

        return (
            <>
                <InspectorControls>
                    <PanelBody title={blocktranslate('Content')} initialOpen={true}>
                        <RangeControl
                            label={blocktranslate('Words shown')}
                            value={maxWords}
                            onChange={(value) => setAttributes({ maxWords: value || 0 })}
                            min={0}
                            max={MAX_WORDS}
                            help={
                                maxWords
                                    ? blocktranslate('Show this many words initially. Readers can select Read more to see the full review.')
                                    : blocktranslate('Reviews show in full. Move the slider to cut longer ones down.')
                            }
                            allowReset={true}
                            resetFallbackValue={0}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>{trimWords(review.content, maxWords)}</div>
            </>
        );
    },
    save: () => null,
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewContentBlock;

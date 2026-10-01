import { sampleReview } from "./sampleReview";
import { ReviewStar } from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, ColorPicker, Button } = wp.components;

/**
 * The stars, in the markup ReviewThreadMarkup::starsHtml() emits — the same
 * fct-star-filled / fct-star-empty classes and the same glyph (ReviewStar,
 * which mirrors the server's starSvg()), so a star looks the same in the
 * editor as on the page.
 *
 * The colour is set as --fct-star-color rather than as a text colour: the
 * filled stars read that custom property and the empty ones keep their own
 * grey, which a plain colour would flatten. Left empty the block inherits,
 * so a list that sets the property on its container still governs.
 */
const ReviewRatingBlock = {
    attributes: {
        starColor: {
            type: 'string',
            default: '',
        },
    },
    edit: ({ attributes, setAttributes, context }) => {
        const { starColor } = attributes;
        const review = context?.['fluent-cart/review'] || sampleReview();
        const blockProps = useBlockProps({
            className: 'fct-review-item-stars',
            style: starColor ? { '--fct-star-color': starColor } : undefined,
        });
        const rating = review.rating;

        return (
            <>
                <InspectorControls>
                    <PanelBody title={blocktranslate('Star Color')} initialOpen={false}>
                        <ColorPicker
                            color={starColor || '#f59e0b'}
                            onChange={(val) => setAttributes({ starColor: val })}
                            enableAlpha={false}
                        />
                        {starColor && (
                            <Button
                                variant="tertiary"
                                onClick={() => setAttributes({ starColor: '' })}
                            >
                                {blocktranslate('Reset to default')}
                            </Button>
                        )}
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    {[1, 2, 3, 4, 5].map((star) => (
                        <span
                            key={star}
                            className={star <= rating ? 'fct-star fct-star-filled' : 'fct-star fct-star-empty'}
                            aria-hidden="true"
                        >
                            <ReviewStar />
                        </span>
                    ))}
                </div>
            </>
        );
    },
    save: () => null,
    supports: {
        html: false,
        align: ["left", "center", "right"],
        spacing: { margin: true, padding: true },
        color: { text: true },
    },
    usesContext: [
        'fluent-cart/review',
    ],
};

export default ReviewRatingBlock;

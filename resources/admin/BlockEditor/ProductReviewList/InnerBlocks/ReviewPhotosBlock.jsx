import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { sampleReview } from "./sampleReview";
import { ReviewMediaMore } from "@/BlockEditor/Icons";

const { useBlockProps, InspectorControls } = wp.blockEditor;
const { Notice, PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
const { useState } = wp.element;

/**
 * The photos a review carries — and whatever else an extension puts there
 * through the media_item_html filter, video included. The settings say
 * "attachments" rather than naming the kinds: PRO uploads photos and nothing
 * else today, so naming video would promise a merchant something they cannot
 * produce, while naming photos would be wrong about what the tiles render.
 *
 * The editor has no copies of them, so
 * it draws thumbnail-shaped stand-ins at the size the real ones render — enough
 * to lay the row out against. PRO attaches the real ones; without it the block
 * renders nothing on the page.
 *
 * The three settings are the block's own because they are about these tiles and
 * nothing else, the same way star colour sits on the Review Rating block.
 */
const ReviewPhotosBlock = {
    edit: ({ attributes, setAttributes, context }) => {
        const review = context?.['fluent-cart/review'] || sampleReview();
        const visibleCount = attributes.visibleCount || 0;
        const width = attributes.mediaWidth || 72;
        // ?? rather than ||: 0 is a real choice at full width — it means the
        // tile takes the photo's own height — and || would read it as unset.
        const height = attributes.mediaHeight ?? 72;
        const fullWidth = attributes.mediaFullWidth === true;
        // Pro. Resolved rather than read, so the canvas draws what the page
        // will draw: ProductReviewService::isPhotoStylingAllowed() refuses the
        // modifier on a site without Pro, and a saved attribute left over from
        // one that lapsed would otherwise preview a card the page flattens.
        const photoStylingAllowed = !!(window.fluent_cart_product_review_list_data || {}).is_pro;
        const flush = photoStylingAllowed && attributes.mediaFlush === true;
        const backdrop = photoStylingAllowed && attributes.mediaBackdrop === true;
        const [lockedStyle, setLockedStyle] = useState('');

        const onProStyleChange = (key, label) => (value) => {
            if (!photoStylingAllowed) {
                // Not saved: storing a style that cannot draw would leave the
                // block one renewal away from silently changing the card.
                setLockedStyle(label);

                return;
            }

            setLockedStyle('');
            setAttributes({ [key]: value });
        };
        const more = attributes.mediaMore || 'overlay';

        const blockProps = useBlockProps({
            // The modifier the page writes too, so the canvas bleeds a flush
            // photograph to the card's edges the way the page does.
            className: 'fct-review-media-gallery'
                + (flush ? ' fct-review-media-gallery--flush' : '')
                + (backdrop ? ' fct-review-media-gallery--backdrop' : ''),
            style: {
                // The same rule the page follows: a full-width tile is as
                // wide as the gallery, which puts one on each line.
                '--fct-review-thumb-w': fullWidth ? '100%' : `${width}px`,
                // Flush tiles take their shape from the stylesheet's ratio, so
                // auto is what the page prints for them too. Everywhere else an
                // auto height is the photograph's own, which the canvas has no
                // copy of -- so it stands a representative height in, enough to
                // lay the row out against.
                // A backdrop fills the card, so its height is the card's and
                // not a number this block sets.
                '--fct-review-thumb-h': backdrop
                    ? '100%'
                    : (fullWidth && !height ? (flush ? 'auto' : '220px') : `${height}px`),
            },
        });

        // The same stand-in the product blocks draw, read from the list
        // block's own localized data — read here rather than at module scope,
        // where a missing global would take the whole registration down with
        // it and leave every review block uninsertable.
        const listData = window.fluent_cart_product_review_list_data;
        const placeholderImage = listData?.placeholder_image;
        // What a review may actually hold. Offering a limit past it would be a
        // number that never comes into play — the store's upload cap decides
        // how many attachments there can be.
        const maxPhotos = Math.max(1, parseInt(listData?.max_photos_per_review, 10) || 5);

        const total = review.media_count;
        // 0 is every attachment, and a limit at or past the count is the same
        // thing — neither draws a + tile with nothing behind it. The canvas
        // has to agree with ReviewThreadMarkup::mediaGalleryHtml() about this,
        // or the editor shows a + the page never renders.
        const shown = visibleCount > 0 && visibleCount < total ? visibleCount : total;
        const overflow = more === 'none' ? 0 : total - shown;

        return (
            <>
                <InspectorControls>
                    <PanelBody title={blocktranslate('Attachments')} initialOpen={false}>
                        <RangeControl
                            label={blocktranslate('Attachments Shown')}
                            help={blocktranslate('How many attachments a review shows. The rest go behind a + that opens them in the lightbox. 0 shows all of them.')}
                            value={visibleCount}
                            onChange={(value) => setAttributes({ visibleCount: value || 0 })}
                            min={0}
                            max={maxPhotos}
                            step={1}
                        />

                        {visibleCount > 0 && (
                            <SelectControl
                                label={blocktranslate('The + Counter')}
                                help={blocktranslate('Counts the attachments the limit leaves out and opens them in the lightbox. Hidden, they are simply not shown.')}
                                value={more}
                                options={[
                                    {label: blocktranslate('On the last attachment'), value: 'overlay'},
                                    {label: blocktranslate('Beside the attachments'), value: 'tile'},
                                    {label: blocktranslate('Hidden'), value: 'none'},
                                ]}
                                onChange={(value) => setAttributes({ mediaMore: value })}
                            />
                        )}

                        <ToggleControl
                            label={blocktranslate('Full Width Attachments')}
                            help={blocktranslate('Each attachment spans the whole review, one to a line. The height still applies.')}
                            checked={fullWidth}
                            onChange={(value) => setAttributes({
                                mediaFullWidth: value,
                                // Full width starts uncropped: a photo the
                                // width of the review inside the 72px the
                                // pixel sizes default to is a sliver of one.
                                // Turning it off restores that default.
                                mediaHeight: value ? 0 : 72,
                            })}
                        />

                        {fullWidth && (
                            <ToggleControl
                                label={blocktranslate('Attachment As Card Background')}
                                help={blocktranslate('The first attachment fills the card and the rest of the review sits over it. A review with no attachment is unaffected, so a grid can mix photo cards and plain ones.')}
                                checked={backdrop}
                                onChange={onProStyleChange('mediaBackdrop', blocktranslate('Attachment As Card Background'))}
                            />
                        )}

                        {fullWidth && !backdrop && (
                            <ToggleControl
                                label={blocktranslate('Flush To Card Edges')}
                                help={blocktranslate('The attachment becomes the top of the card: no padding around it, and one height for every card in the row. Applies when the attachments are the first thing in the review.')}
                                checked={flush}
                                onChange={onProStyleChange('mediaFlush', blocktranslate('Flush To Card Edges'))}
                            />
                        )}

                        {lockedStyle && (
                            <div className="fct-photo-style-notice">
                                <Notice status="warning" isDismissible onRemove={() => setLockedStyle('')}>
                                    {blocktranslate('%s is a Pro feature. Without FluentCart Pro the attachment stays inside the card at its own size.', lockedStyle)}
                                </Notice>
                            </div>
                        )}

                        {!fullWidth && (
                            <RangeControl
                                label={blocktranslate('Attachment Width (px)')}
                                value={width}
                                onChange={(value) => setAttributes({ mediaWidth: value || 72 })}
                                min={40}
                                max={200}
                                step={2}
                            />
                        )}

                        <RangeControl
                            label={blocktranslate('Attachment Height (px)')}
                            help={fullWidth
                                ? blocktranslate('0 gives each attachment its own proportions, uncropped. Any other height crops it to a band of that depth.')
                                : undefined}
                            value={height}
                            onChange={(value) => setAttributes({
                                mediaHeight: fullWidth ? (value || 0) : (value || 72),
                            })}
                            min={fullWidth ? 0 : 40}
                            max={fullWidth ? 600 : 200}
                            step={2}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    {Array.from({ length: shown }, (unused, index) => {
                        const stand = (
                            <span className="fct-review-media-thumb is-editor-placeholder">
                                {placeholderImage && (
                                    <img src={placeholderImage} alt={blocktranslate('Review photo')}/>
                                )}
                            </span>
                        );

                        // The badge rides the last stand-in, the way the page
                        // puts it on the last visible attachment.
                        if (overflow && more === 'overlay' && index === shown - 1) {
                            return (
                                <span key={index} className="fct-review-media-tile">
                                    {stand}
                                    <span className="fct-review-media-more is-overlay">
                                        <ReviewMediaMore/>
                                        <span>+{overflow}</span>
                                    </span>
                                </span>
                            );
                        }

                        return <span key={index}>{stand}</span>;
                    })}
                    {overflow > 0 && more === 'tile' && (
                        <span className="fct-review-media-thumb fct-review-media-more is-editor-placeholder">
                            <ReviewMediaMore/>
                            <span>+{overflow}</span>
                        </span>
                    )}
                </div>
            </>
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
    attributes: {
        visibleCount: { type: 'number', default: 0 },
        mediaWidth: { type: 'number', default: 72 },
        mediaHeight: { type: 'number', default: 72 },
        mediaFullWidth: { type: 'boolean', default: false },
        mediaFlush: { type: 'boolean', default: false },
        mediaBackdrop: { type: 'boolean', default: false },
        mediaMore: { type: 'string', default: 'overlay' },
    },
};

export default ReviewPhotosBlock;

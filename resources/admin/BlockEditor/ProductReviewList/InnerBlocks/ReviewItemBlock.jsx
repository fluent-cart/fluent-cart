import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ReviewPagerPrevious, ReviewPagerNext} from "@/BlockEditor/Icons";
import {sampleReviews} from "./sampleReview";
import { resolveViewMode } from "./viewMode";

const {
    useBlockProps,
    useInnerBlocksProps,
    InnerBlocks,
    InspectorControls,
    BlockContextProvider,
    __experimentalUseBlockPreview: useBlockPreview,
} = wp.blockEditor;
const {PanelBody, SelectControl} = wp.components;
const {useEffect, useState, memo} = wp.element;
const {useSelect} = wp.data;

/**
 * The floor an editor may set, and what each one reads as.
 *
 * Whole stars only: the rating is stored as one, so "3.5 and up" would be the
 * same query as 4 and say something the data cannot answer.
 */
/**
 * Built when the control renders, not held as a constant.
 *
 * Each label is its own blocktranslate() call with the string written out, so
 * the extractor can find them. Passing a variable to blocktranslate() reads
 * the same at runtime and leaves nothing for it to collect — the labels would
 * ship untranslated in every language.
 */
const minRatingOptions = () => ([
    {value: 0, label: blocktranslate('All ratings')},
    {value: 2, label: blocktranslate('2 stars and up')},
    {value: 3, label: blocktranslate('3 stars and up')},
    {value: 4, label: blocktranslate('4 stars and up')},
    {value: 5, label: blocktranslate('5 stars only')},
]);

const REVIEW_ITEM_BLOCK = 'fluent-cart/review-item';

/**
 * How many reviews the canvas draws when the list is a plain column.
 *
 * Enough to show that it repeats and that the cards sit apart, without turning
 * a page of blocks into a page of sample copy.
 */
const LIST_PREVIEW_COUNT = 3;

/**
 * The most a preview draws.
 *
 * Not the server's cap, which is six: this one is about the canvas, which is a
 * column of the editor rather than the width of a page. Six cards there are
 * narrower than anything the storefront would show, so a strip asking for six
 * previews as four and says so — the wrapper's cols- class is built from this
 * number, not from what the block asked for.
 */
const MAX_PREVIEW_COUNT = 4;

/**
 * What a row takes: the fields of a review, and the containers an editor lays
 * them out with. Not another Review Item — it would be handed the same
 * collection and loop every review again, once per row of this one.
 */
const ROW_ALLOWED_BLOCKS = [
    'fluent-cart/review-item-avatar',
    'fluent-cart/review-item-author-name',
    'fluent-cart/review-item-verified-badge',
    'fluent-cart/review-item-variation-title',
    'fluent-cart/review-item-rating',
    'fluent-cart/review-item-date',
    'fluent-cart/review-item-title',
    'fluent-cart/review-item-content',
    'fluent-cart/review-item-photos',
    'fluent-cart/review-item-reply',
    'fluent-cart/review-item-votes',
    'core/group',
    'core/columns',
];

/**
 * The editable copy. One of the drawn reviews renders the real inner blocks;
 * the others are previews of the same blocks, which is what lets the canvas
 * show several cards while the editor still edits exactly one.
 */
const ReviewTemplateInnerBlocks = () => {
    const innerBlocksProps = useInnerBlocksProps(
        {className: 'fct-review-item-body'},
        {
            // The same list the single-card canvas allowed: a row takes the
            // fields of a review and the containers they are laid out with,
            // never another Review Item.
            allowedBlocks: ROW_ALLOWED_BLOCKS,
            templateInsertUpdatesSelection: false,
            renderAppender: InnerBlocks.ButtonBlockAppender,
            __unstableDisableLayoutClassNames: true,
        }
    );

    return <div {...innerBlocksProps} />;
};

/**
 * A non-editable copy of the row, drawn against its own review. Clicking one
 * makes it the editable copy, so a field can be selected in whichever card the
 * editor happens to be looking at.
 */
const ReviewTemplateBlockPreview = ({blocks, reviewId, isHidden, setActiveReviewId}) => {
    const blockPreviewProps = useBlockPreview({
        blocks,
        props: {className: 'fct-review-item-body'},
    });

    const handleOnClick = () => setActiveReviewId(reviewId);

    return (
        <div
            {...blockPreviewProps}
            tabIndex={0}
            role="button"
            onClick={handleOnClick}
            onKeyPress={handleOnClick}
            style={{display: isHidden ? 'none' : undefined}}
        />
    );
};

const MemoizedReviewTemplateBlockPreview = memo(ReviewTemplateBlockPreview);

/**
 * One of the slider's arrows.
 *
 * Swiper never runs in the canvas, so these are drawn rather than built: the
 * same markup, classes and chevron the storefront's navButton() emits, sitting
 * where the real ones sit. Without them a slider previews as a plain row of
 * cards and an editor cannot see that they will have arrows at all, or which
 * size they chose.
 *
 * Out of the tab order and inert, for the reason the View Reply trigger is:
 * this is a preview of a control, not the control.
 *
 * @param {'prev'|'next'} direction
 */
const SliderArrow = ({direction}) => (
    <button
        type="button"
        className={`fct-reviews-slider-nav fct-reviews-slider-${direction}`}
        aria-label={direction === 'prev' ? blocktranslate('Previous') : blocktranslate('Next')}
        tabIndex={-1}
    >
        {direction === 'prev' ? <ReviewPagerPrevious/> : <ReviewPagerNext/>}
    </button>
);

/**
 * One review, and the fields inside it.
 *
 * On the page this block draws itself once per review, the way ShopApp's
 * product loop draws itself once per product — the list around it renders each
 * of its children once and leaves the repeating to this.
 *
 * The canvas repeats it too, so what is previewed is the arrangement rather
 * than a single card: as many as fit a row in grid and slider view, a short
 * column in list view. Each copy gets its own stand-in review, so the cards
 * are different lengths — which is the case that shows whether the layout
 * holds, and the one a single card cannot show.
 */
const ReviewItemBlock = {
    edit: ({attributes, setAttributes, clientId, context}) => {
        // Resolved, not read raw. providesContext hands this down straight from
        // the list's saved attribute, so a site that has lapsed to free still
        // announces 'grid' here while ProductReviewService::resolveViewMode()
        // draws the page as a list — the canvas would preview a layout the page
        // refuses. The same question, asked on both sides.
        const viewMode = resolveViewMode(context['fluent-cart/review_view_mode']);
        const columns = parseInt(context['fluent-cart/review_grid_columns'], 10) || 2;
        // Masonry lays out in columns too -- CSS columns rather than grid ones,
        // but a preview that leaves it out draws a plain stack and says the
        // layout is broken when it is only unhandled. The storefront renderer
        // keeps the same list; the two have to agree or the canvas and the
        // page disagree about what masonry is.
        const hasColumns = viewMode === 'grid' || viewMode === 'slider' || viewMode === 'masonry';

        // The slider's arrows, on the block's own settings. Only 'no' turns
        // them off, matching the storefront's own `arrows !== 'no'` — an unset
        // value there means a slider that has never been configured, which
        // gets arrows.
        const sliderSettings = context['fluent-cart/review_slider_settings'] || {};
        const arrowsPosition = sliderSettings.arrowsPosition || 'overlap';
        const showArrows = viewMode === 'slider' && sliderSettings.arrows !== 'no';
        const arrowsSize = ['sm', 'md', 'lg'].includes(sliderSettings.arrowsSize)
            ? sliderSettings.arrowsSize
            : 'md';

        // A row's worth in the laid-out modes, so the preview is the row an
        // editor is configuring; a short column otherwise.
        //
        // The columns are capped with the cards, not just the cards. A strip
        // asking for six drew six tracks and four cards, leaving two empty
        // cells and squeezing the rest to a sixth of a canvas far narrower
        // than the page -- a layout that looks broken rather than narrow. The
        // preview is a row's worth at a width worth reading; the page still
        // draws every column it was asked for.
        const previewColumns = Math.min(MAX_PREVIEW_COUNT, Math.max(1, columns));
        // Masonry gets two rows' worth. One row of cards is the one thing
        // masonry never looks like -- the stagger only appears once a card has
        // a shorter neighbour to start under, so a single row previews as a
        // plain grid and says the mode does nothing.
        const previewCount = viewMode === 'masonry'
            ? previewColumns * 2
            : (hasColumns ? previewColumns : LIST_PREVIEW_COUNT);

        const reviews = sampleReviews(previewCount);

        // The block's own wrapper stands in for the element the server wraps
        // the rows in — the same classes and the same column count, so the
        // cards are previewed at the width they will have.
        const blockProps = useBlockProps({
            // fct-arrows-* is the class the storefront puts on the slider's
            // container and the product carousel's alike, so one stylesheet
            // describes the arrow at every size.
            className: [
                'fct-reviews-list',
                hasColumns ? `fct-reviews-list--${viewMode}` : '',
                // The preview is capped, so the class says what the canvas is
                // actually drawing rather than what the block was asked for.
                hasColumns ? `fct-reviews-list--cols-${previewColumns}` : '',
                showArrows ? `fct-arrows-${arrowsSize}` : '',
                // The canvas has no frame around the track the way the page
                // does, so the placement rides on the track itself here.
                showArrows ? `fct-arrows-at-${arrowsPosition}` : '',
            ].filter(Boolean).join(' '),
            style: hasColumns ? {'--fct-review-columns': previewColumns} : undefined,
        });

        // Whether another Review Item in this post already answers to our id.
        // Gutenberg copies saved attributes when a block is duplicated or
        // pasted, so a copy arrives holding the original's id and the two
        // would share one stored row. The runtime clientId is not the id —
        // Gutenberg mints a fresh one on every editor load — so it is only
        // written when there is none, or when this one is taken.
        const idIsTaken = useSelect((select) => {
            if (!attributes.wp_client_id) {
                return false;
            }

            const {getClientIdsWithDescendants, getBlockName, getBlockAttributes} = select('core/block-editor');

            return getClientIdsWithDescendants().some((id) => (
                id !== clientId
                && getBlockName(id) === REVIEW_ITEM_BLOCK
                && getBlockAttributes(id)?.wp_client_id === attributes.wp_client_id
            ));
        }, [clientId, attributes.wp_client_id]);

        // Whether another Review Item in this list is drawn instead of this
        // one. The page draws the first and discards the rest — the script
        // refills one rows element and sends back one id — so a second row
        // says so here rather than being laid out to no effect.
        const isSpare = useSelect((select) => {
            const {getBlockParentsByBlockName, getClientIdsOfDescendants, getBlockName} = select('core/block-editor');

            const [listId] = getBlockParentsByBlockName(clientId, 'fluent-cart/product-review-list', true);

            if (!listId) {
                return false;
            }

            const rows = getClientIdsOfDescendants(listId)
                .filter((id) => getBlockName(id) === REVIEW_ITEM_BLOCK);

            return rows.length > 1 && rows[0] !== clientId;
        }, [clientId]);

        useEffect(() => {
            if (attributes.wp_client_id && !idIsTaken) {
                return;
            }
            setAttributes({wp_client_id: clientId});
        }, [clientId, attributes.wp_client_id, idIsTaken, setAttributes]);

        // The stand-in review, handed to the fields as block context — the
        // same channel the server puts the real one on, and the same thing
        // ShopApp's product loop does with each product in the canvas. A field
        // reads the review it is drawing the one way, in the editor and on the
        // page both.
        // The blocks each non-editable copy is drawn from, and which copy is
        // the editable one. Both are read before the spare row returns early:
        // a hook skipped on one render and not the next is a hook-order
        // violation, and the spare message is a render like any other.
        const blocks = useSelect(
            (select) => select('core/block-editor').getBlock(clientId)?.innerBlocks ?? [],
            [clientId]
        );

        const [activeReviewId, setActiveReviewId] = useState();

        if (isSpare) {
            return (
                <div {...blockProps}>
                    <p className="fct-review-item-spare">
                        {blocktranslate('Only the first Review Item in a list is drawn. Remove this one, or move its fields into the first.')}
                    </p>
                </div>
            );
        }

        return (
            <>
                <InspectorControls>
                    <PanelBody title={blocktranslate('Reviews shown')} initialOpen={true}>
                        <SelectControl
                            label={blocktranslate('Minimum rating')}
                            value={attributes.minRating || 0}
                            options={minRatingOptions()}
                            onChange={(value) => setAttributes({minRating: parseInt(value, 10) || 0})}
                            help={blocktranslate('Lower-rated reviews are left out of the list entirely — the count and the pages follow.')}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    {reviews.map((review) => {
                        const isEditable = review.id === (activeReviewId ?? reviews[0].id);

                        return (
                            <BlockContextProvider
                                key={review.id}
                                value={{'fluent-cart/review': review}}
                            >
                                {isEditable ? <ReviewTemplateInnerBlocks/> : null}
                                <MemoizedReviewTemplateBlockPreview
                                    blocks={blocks}
                                    reviewId={review.id}
                                    isHidden={isEditable}
                                    setActiveReviewId={setActiveReviewId}
                                />
                            </BlockContextProvider>
                        );
                    })}

                    {showArrows && arrowsPosition === 'bottom' && (
                        <div className="fct-reviews-slider-nav-row">
                            <SliderArrow direction="prev"/>
                            <SliderArrow direction="next"/>
                        </div>
                    )}

                    {showArrows && arrowsPosition !== 'bottom' && (
                        <>
                            <SliderArrow direction="prev"/>
                            <SliderArrow direction="next"/>
                        </>
                    )}
                </div>
            </>
        );
    },
    save: () => <InnerBlocks.Content/>,
    attributes: {
        wp_client_id: {
            type: 'string',
            default: ''
        },
        minRating: {
            type: 'number',
            default: 0,
        },
    },
    supports: {
        html: false,
        spacing: {margin: true, padding: true},
    },
    usesContext: [
        'fluent-cart/review_list_reviews',
        'fluent-cart/review_list_rows_only',
        'fluent-cart/review_view_mode',
        'fluent-cart/review_grid_columns',
        'fluent-cart/review_slider_settings',
    ],
};

export default ReviewItemBlock;

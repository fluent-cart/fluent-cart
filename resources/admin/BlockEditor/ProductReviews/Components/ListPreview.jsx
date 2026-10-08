import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ReviewStar} from "@/BlockEditor/Icons";

/**
 * Canvas preview of the review list column: count line, filter chips / sort
 * row, then the review items.
 *
 * `rows` is the row an editor is composing out of review-field blocks, drawn
 * where the items go so the header, chips and sorting above it read as they
 * will on the page. Without it the items stay skeleton — the list is runtime
 * data and the real reviews render on the frontend.
 *
 * `viewMode` lays the rows out in columns the way the page does, so a row in a
 * three-column grid or slider is previewed at the width it will actually have.
 *
 * `showHeader` is false once those blocks include the header ones: they draw
 * the count and the chips/sort row themselves, and the preview's own copy is
 * then a second set of controls stacked above the real ones.
 *
 * `sliderSettings` is read only for its pagination: Swiper never runs in the
 * editor, so the dots are drawn rather than live, the same way the sort
 * control above is drawn. Three of them, because how many pages the slider
 * has is runtime data.
 */
const ListPreview = ({hasProductData, reviewCount, showHeader = true, showSortControls, showReviewerName, showReviewDate, rows = null, viewMode = 'list', gridColumns = 2, sliderSettings = {}}) => {
    // Content toggles drive the preview: reviewer name hides the avatar,
    // name + date together hide the meta line, sort controls hide the
    // chips/sort row.
    const showMetaLine = showReviewerName || showReviewDate;

    // Grid, masonry and slider all lay the rows out in columns; only the
    // class differs.
    const hasColumns = ['grid', 'masonry', 'slider'].includes(viewMode);

    // Only a slider pages between what is loaded, so only a slider shows this.
    const paginationType = sliderSettings.paginationType || 'bullets';
    const showPagination = viewMode === 'slider' && sliderSettings.pagination === 'yes';

    const skeletonReview = (
        <div className="fct-reviews-block-skeleton-review">
            {showReviewerName && <div className="fct-reviews-block-skeleton-avatar"/>}
            <div className="fct-reviews-block-skeleton-lines">
                {showMetaLine && <div className="fct-reviews-block-skeleton-line is-short"/>}
                <div className="fct-reviews-block-skeleton-line is-wide"/>
                <div className="fct-reviews-block-skeleton-line"/>
            </div>
        </div>
    );

    const list = (
        <div
            className={`fct-reviews-block-skeleton-list${hasColumns ? ` is-${viewMode}` : ''}`}
            style={hasColumns ? {'--fct-review-columns': gridColumns} : undefined}
        >
            {showHeader && hasProductData && (
                <div className="fct-reviews-block-real-count">
                    {reviewCount} {blocktranslate('Reviews')}
                </div>
            )}
            {showHeader && showSortControls && (hasProductData ? (
                <div className="fct-reviews-block-real-controls">
                    <div className="fct-reviews-block-real-chips">
                        <span className="fct-reviews-block-real-chip is-active">{blocktranslate('All')}</span>
                        {[5, 4, 3, 2, 1].map((star) => (
                            <span key={star} className="fct-reviews-block-real-chip">{star} <ReviewStar/></span>
                        ))}
                    </div>
                    <span className="fct-reviews-block-real-sort">{blocktranslate('Newest')} ▾</span>
                </div>
            ) : (
                <div className="fct-reviews-block-skeleton-chips">
                    <div className="fct-reviews-block-skeleton-line is-short"/>
                    <div className="fct-reviews-block-skeleton-chip"/>
                    <div className="fct-reviews-block-skeleton-chip"/>
                    <div className="fct-reviews-block-skeleton-chip"/>
                </div>
            ))}
            {rows || <>{skeletonReview}{skeletonReview}</>}
        </div>
    );

    if (!showPagination) {
        return list;
    }

    return (
        <>
            {list}
            <div className={`fct-reviews-block-slider-pagination is-${paginationType}`}>
                {paginationType === 'bullets' && [0, 1, 2].map((dot) => (
                    <span
                        key={dot}
                        className={`fct-reviews-block-slider-dot${dot === 0 ? ' is-active' : ''}`}
                    />
                ))}
                {paginationType === 'fraction' && (
                    <span className="fct-reviews-block-slider-fraction">1 / 3</span>
                )}
                {paginationType === 'progressbar' && (
                    <span className="fct-reviews-block-slider-progress">
                        <span className="fct-reviews-block-slider-progress-fill"/>
                    </span>
                )}
                {paginationType === 'segmented' && (
                    <>
                        {[0, 1, 2].map((segment) => (
                            <span
                                key={segment}
                                className={`fct-reviews-block-slider-segment${segment === 0 ? ' is-active' : ''}`}
                            />
                        ))}
                        <span className="fct-reviews-block-slider-counter">1 / 3</span>
                    </>
                )}
            </div>
        </>
    );
};

export default ListPreview;

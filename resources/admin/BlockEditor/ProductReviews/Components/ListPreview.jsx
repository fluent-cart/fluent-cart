import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

/**
 * Canvas preview of the review list column: count line, filter chips /
 * sort row, then skeleton review items. The items always stay skeleton —
 * the list is runtime data and renders on the frontend.
 */
const ListPreview = ({hasProductData, reviewCount, showSortControls, showReviewerName, showReviewDate}) => {
    // Content toggles drive the preview: reviewer name hides the avatar,
    // name + date together hide the meta line, sort controls hide the
    // chips/sort row.
    const showMetaLine = showReviewerName || showReviewDate;

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

    return (
        <div className="fct-reviews-block-skeleton-list">
            {hasProductData && (
                <div className="fct-reviews-block-real-count">
                    {reviewCount} {blocktranslate('Reviews')}
                </div>
            )}
            {showSortControls && (hasProductData ? (
                <div className="fct-reviews-block-real-controls">
                    <div className="fct-reviews-block-real-chips">
                        <span className="fct-reviews-block-real-chip is-active">{blocktranslate('All')}</span>
                        {[5, 4, 3, 2, 1].map((star) => (
                            <span key={star} className="fct-reviews-block-real-chip">{star} ★</span>
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
            {skeletonReview}
            {skeletonReview}
        </div>
    );
};

export default ListPreview;

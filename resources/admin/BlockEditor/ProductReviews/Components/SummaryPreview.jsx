import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

/**
 * Canvas preview of the rating summary card. With real product data it
 * shows the fetched numbers; otherwise a skeleton. The review list is
 * runtime data and never previews here.
 */
const SummaryPreview = ({hasProductData, avgRating, reviewCount, ratingBreakdown, starColor}) => {
    if (!hasProductData) {
        return (
            <div className="fct-reviews-block-skeleton-aside">
                <div className="fct-reviews-block-skeleton-score"/>
                <div className="fct-reviews-block-skeleton-line is-short"/>
                {[5, 4, 3, 2, 1].map((bar) => (
                    <div key={bar} className="fct-reviews-block-skeleton-bar-row">
                        <div className="fct-reviews-block-skeleton-bar-label"/>
                        <div className="fct-reviews-block-skeleton-bar-track"/>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="fct-reviews-block-real-summary">
            <div className="fct-reviews-block-real-average">
                <span className="fct-reviews-block-real-average-number">{avgRating || 0}</span>
                <span className="fct-reviews-block-real-average-max">/5</span>
            </div>
            <div className="fct-reviews-block-real-stars">
                {[1, 2, 3, 4, 5].map((star) => (
                    <span key={star} style={{color: star <= Math.round(avgRating) ? starColor : '#d1d5db'}}>★</span>
                ))}
            </div>
            <div className="fct-reviews-block-real-total">
                {blocktranslate('Based on')} {reviewCount} {reviewCount === 1 ? blocktranslate('review') : blocktranslate('reviews')}
            </div>
            <div className="fct-reviews-block-real-bars">
                {[5, 4, 3, 2, 1].map((star) => {
                    const barCount = parseInt(ratingBreakdown[star], 10) || 0;
                    const barWidth = reviewCount > 0 ? Math.round((barCount / reviewCount) * 100) : 0;
                    return (
                        <div key={star} className="fct-reviews-block-real-bar-row">
                            <span className="fct-reviews-block-real-bar-label">{star} ★</span>
                            <div className="fct-reviews-block-skeleton-bar-track">
                                <div className="fct-reviews-block-real-bar-fill" style={{width: `${barWidth}%`, background: starColor}}/>
                            </div>
                            <span className="fct-reviews-block-real-bar-count">{barCount}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
};

export default SummaryPreview;

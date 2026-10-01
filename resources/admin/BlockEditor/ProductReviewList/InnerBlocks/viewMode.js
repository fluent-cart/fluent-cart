/**
 * Which arrangement a list actually renders in.
 *
 * Grid, slider and masonry are Pro. Mirrors
 * ProductReviewService::resolveViewMode() on the server, which is what decides;
 * this only keeps the canvas honest about what the page will do.
 *
 * Shared rather than copied per block. Two blocks ask the question -- the row,
 * to draw the right arrangement, and the pager, to know whether a slider has
 * taken its place -- and they have to agree: a pager that reads the saved
 * slider while the list has fallen back to a plain list hides itself on a site
 * that needs it, stranding every review after the first page.
 *
 * @param {string} value the saved view mode
 * @returns {string} 'list' | 'grid' | 'slider' | 'masonry'
 */
export const resolveViewMode = (value) => {
    const mode = String(value || '').trim().toLowerCase();

    if (['grid', 'slider', 'masonry'].indexOf(mode) === -1) {
        return 'list';
    }

    return (window.fluent_cart_product_review_list_data || {}).is_pro ? mode : 'list';
};

export default resolveViewMode;

/**
 * The localized review strings, for code outside the lightbox closure — that
 * one has its own trans() and is not reachable from the class.
 *
 * Its own module so the slider can read it without importing Reviews.js,
 * which would be a cycle: Reviews.js is what builds the slider.
 */
export const reviewTrans = () => (window.fluentcart_review_vars && window.fluentcart_review_vars.trans) || {};

export default reviewTrans;

// The review star's shape, read from the same file the server renders
// (ReviewThreadMarkup::starSvg()), so the admin, block editor and customer
// portal can never draw a different star from the storefront.
import reviewStarMarkup from '../../../../../app/Views/icons/review-star.svg?raw';

export const REVIEW_STAR_PATH = (reviewStarMarkup.match(/\sd="([^"]+)"/) || [])[1] || '';

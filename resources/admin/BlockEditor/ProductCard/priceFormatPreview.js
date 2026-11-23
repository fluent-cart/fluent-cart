import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const decodeEntities = (value) => new DOMParser().parseFromString(String(value), 'text/html').body.textContent;

/**
 * How the chosen format words the price, as ProductCardRender::renderPrices() does for a price range:
 * "From {min}" or "{min} - {max}"; a product with one price shows that price. Sale (compare) prices
 * are not previewed. Returns '' while there is no loaded product.
 */
export const priceFormatPreview = (product, priceFormat) => {
    const detail = product?.detail;
    if (!detail?.formatted_min_price) {
        return '';
    }

    const min = decodeEntities(detail.formatted_min_price);
    const max = detail.formatted_max_price ? decodeEntities(detail.formatted_max_price) : '';
    const hasRange = Number(detail.max_price) > Number(detail.min_price) && max !== '';

    if (!hasRange) {
        return min;
    }

    if (priceFormat === 'range') {
        return min + ' - ' + max;
    }

    /* translators: %s is the minimum price */
    return blocktranslate('From %s', min);
};

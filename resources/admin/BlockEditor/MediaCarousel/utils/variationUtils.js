/**
 * Normalizes product variations with their associated images
 *
 * @param {Object} product - The product object containing variants and detail
 * @param {Array<number|string>} variationIds - Array of variation IDs to normalize
 * @returns {Object} Object mapping variant IDs to normalized variant data with images
 *
 * @example
 * const normalized = normalizeVariations(product, [1, 2, 3]);
 * // Returns: { 1: { ...variant, images: [...] }, 2: { ...variant, images: [...] } }
 */
export const normalizeVariations = (product, variationIds = []) => {
    // Normalize variation IDs to numbers for safe comparison
    const ids = variationIds.map(id => Number(id));
    const variations = {};

    if (!product?.variants?.length) return variations;

    product.variants.forEach(variant => {
        // Only store variants that exist in variationIds
        if (ids.includes(Number(variant.id))) {
            // Product featured image
            const featuredImage = product?.detail?.featured_media
                ? [{ url: product.detail.featured_media.url, title: product.detail.featured_media.title }]
                : [];

            // Variant images from meta_value
            const variantImages = (Array.isArray(variant?.media?.meta_value) ? variant.media.meta_value : []).map(img => ({
                url: img.url,
                title: img.title
            }));

            // Combine all images
            const allImages = [...featuredImage, ...variantImages];

            variations[variant.id] = {
                ...variant,
                images: allImages
            };
        }
    });

    return variations;
};

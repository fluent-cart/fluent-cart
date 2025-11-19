import Asset from "@/utils/support/Asset";

/**
 * Utility class for handling image-related operations
 */
export default class Image {
    /**
     * Gets the base asset URL for images
     * @returns {string} The base URL for assets
     * @example
     * Image.assetUrl()
     * // returns 'https://example.com/wp-content/plugins/fluent-cart/assets/'
     */
    static assetUrl = () => {
        return Asset.url();
    }

    /**
     * Returns the URL for the empty image placeholder
     * @param {boolean} isDark - Whether to use dark mode version of the image
     * @returns {string} The URL of the empty image placeholder
     * @example
     * Image.emptyImage()
     * // returns 'https://example.com/wp-content/plugins/fluent-cart/assets/images/empty-image.svg'
     * 
     * Image.emptyImage(true)
     * // returns 'https://example.com/wp-content/plugins/fluent-cart/assets/images/empty-image-dark.svg'
     */
    static emptyImage(isDark = false) {
        return Image.assetUrl() +
            (
                isDark ?
                    'images/empty-image-dark.svg' :
                    'images/empty-image.svg'
            )
    }
}
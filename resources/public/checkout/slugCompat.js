const LEGACY_PREFIX = 'fluent-cart';

export const checkoutSlug = window.fluentcart_checkout_info?.slug || LEGACY_PREFIX;

export const ensureCheckoutDataAttributeCompatibility = () => {
    const normalizedSlug = checkoutSlug || LEGACY_PREFIX;

    if (normalizedSlug === LEGACY_PREFIX) {
        return;
    }

    const modernPrefix = `data-${normalizedSlug}-`;
    const legacyPrefix = `data-${LEGACY_PREFIX}-`;

    document.querySelectorAll('*').forEach((element) => {
        Array.from(element.attributes).forEach((attribute) => {
            const { name, value } = attribute;

            if (name.startsWith(modernPrefix)) {
                const legacyName = legacyPrefix + name.slice(modernPrefix.length);
                if (!element.hasAttribute(legacyName)) {
                    element.setAttribute(legacyName, value);
                }
                return;
            }

            if (name.startsWith(legacyPrefix)) {
                const modernName = modernPrefix + name.slice(legacyPrefix.length);
                if (!element.hasAttribute(modernName)) {
                    element.setAttribute(modernName, value);
                }
            }
        });
    });
};

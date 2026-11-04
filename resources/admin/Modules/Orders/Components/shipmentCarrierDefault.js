import { CARRIER_MANUAL } from '@/utils/orderConstants';

/**
 * The carrier the customer chose at checkout, or ''.
 *
 * @param {Object|null} order Admin order payload
 * @return {string}
 */
export const orderCarrierSlug = (order) => {
    if (!order) {
        return '';
    }

    const checkoutCarrier = order.checkout_shipping && order.checkout_shipping.carrier;
    if (checkoutCarrier) {
        return String(checkoutCarrier);
    }

    let config = order.config;
    if (typeof config === 'string') {
        try {
            config = JSON.parse(config);
        } catch (error) {
            config = null;
        }
    }

    return config && config.shipping_carrier ? String(config.shipping_carrier) : '';
};

/**
 * The carrier Create shipment starts on: the order's own carrier when it can
 * still quote or buy a label, else the fallback, else the first carrier.
 *
 * @param {Object|null} order
 * @param {Array} carriers getDispatchOptions() payload
 * @param {string} fallback
 * @return {string}
 */
export const defaultShipmentCarrier = (order, carriers, fallback = CARRIER_MANUAL) => {
    const list = Array.isArray(carriers) ? carriers : [];
    const slug = orderCarrierSlug(order);
    const orderCarrier = slug && slug !== CARRIER_MANUAL
        ? list.find((carrier) => carrier.slug === slug)
        : null;

    if (orderCarrier && orderCarrier.is_enabled !== false
        && (orderCarrier.supports_labels === true || orderCarrier.supports_rates === true)) {
        return slug;
    }

    if (!list.length || list.some((carrier) => carrier.slug === fallback)) {
        return fallback;
    }

    return list[0].slug;
};

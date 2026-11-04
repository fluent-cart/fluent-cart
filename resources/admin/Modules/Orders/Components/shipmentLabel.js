import { CARRIER_MANUAL } from '@/utils/orderConstants';

/**
 * Whether deleting the shipment voids a carrier label. Mirrors
 * ShipmentService::voidCarrierLabel(): a bought label has a transaction id.
 *
 * @param {Object|null} shipment
 * @return {boolean}
 */
export const shipmentHasCarrierLabel = (shipment) => {
    if (!shipment || !shipment.carrier_slug || shipment.carrier_slug === CARRIER_MANUAL) {
        return false;
    }

    return !!(shipment.meta && shipment.meta.transaction_id);
};

/**
 * The stored label's REST URL, openable as a plain link: WordPress reads the
 * cookie-auth nonce from `_wpnonce` when no X-WP-Nonce header is sent.
 *
 * @param {{url: string, nonce: string}|null} rest window.fluentCartRestVars.rest
 * @param {number} orderId
 * @param {number} shipmentId
 * @return {string}
 */
export const shipmentLabelHref = (rest, orderId, shipmentId) => {
    if (!rest || !rest.url) {
        return '';
    }

    const url = rest.url + '/orders/' + orderId + '/shipments/' + shipmentId + '/label';

    return url + (url.indexOf('?') === -1 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(rest.nonce || '');
};

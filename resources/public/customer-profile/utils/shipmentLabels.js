import translate, {dateTimeI18, translateNumber} from '../translator/Translator';

// Customer words for shipment statuses; the admin keeps Shipment::getStatuses().
const getLabels = () => ({
    dispatched: translate('Shipped'),
    in_transit: translate('On the way'),
    delivered: translate('Delivered'),
    failed: translate('Delivery problem'),
    returned: translate('Returned to the store'),
});

const STEPS = {dispatched: 1, in_transit: 2, delivered: 3};

// The carrier's substatus; only the Shippo and ShipStation add-ons send it so far.
const isOutForDelivery = shipment => shipment.status === 'in_transit' && shipment.tracking_substatus === 'out_for_delivery';

export function shipmentLabel(shipment) {
    if (isOutForDelivery(shipment)) {
        return translate('Out for delivery');
    }
    return getLabels()[shipment.status] || shipment.status_label || shipment.status;
}

// "Expected Nov 4" while a parcel is on its way; '' once it arrived or went wrong.
export function expectedText(shipment) {
    if (isOutForDelivery(shipment)) {
        return translate('Expected today');
    }
    if (!shipment.tracking_eta || !['dispatched', 'in_transit'].includes(shipment.status)) {
        return '';
    }
    /* translators: %1$s is the expected delivery date */
    return translate('Expected %1$s', dateTimeI18(shipment.tracking_eta, 'date_short'));
}

export const shipmentStep = shipment => STEPS[shipment.status] || 0;

export const isShipmentProblem = shipment => shipment.status === 'failed' || shipment.status === 'returned';

export function shipmentTone(shipment) {
    if (isShipmentProblem(shipment)) {
        return 'danger';
    }
    return shipment.status === 'delivered' ? 'success' : 'progress';
}

// Both are GMT 'Y-m-d H:i:s', so they compare as strings.
export const lastUpdate = shipment => shipment.tracking_status_date || shipment.shipped_at || '';

// The newest update opens, plus every problem so an older one is never hidden behind it.
export function defaultOpenIds(shipments) {
    const open = shipments.filter(isShipmentProblem).map(shipment => shipment.id);
    let latest = null;
    shipments.forEach(shipment => {
        if (!latest || lastUpdate(shipment) > lastUpdate(latest)) {
            latest = shipment;
        }
    });
    if (latest && !open.includes(latest.id)) {
        open.push(latest.id);
    }
    return open;
}

// Order item id => the delivery lines shown under that product in the Summary.
export function itemDeliveryStates(shipments, unshippedItems) {
    const states = {};
    const add = (id, state) => (states[id] = states[id] || []).push(state);

    shipments.forEach((shipment, index) => {
        /* translators: %1$s is the shipment number, %2$s is its delivery status */
        const text = translate('Shipment %1$s · %2$s', translateNumber(index + 1), shipmentLabel(shipment));
        (shipment.items || []).forEach(item => add(item.order_item_id, {text, tone: shipmentTone(shipment)}));
    });
    (unshippedItems || []).forEach(item => add(item.order_item_id, {text: translate('Not shipped yet'), tone: 'waiting'}));

    return states;
}

// Units in one shipment status, for the Purchase History delivery line.
function deliveryCountText(count) {
    const qty = translateNumber(count.qty);
    if (count.out_for_delivery) {
        /* translators: %s is a number of units */
        return translate('%s out for delivery', qty);
    }
    switch (count.status) {
        /* translators: %s is a number of units */
        case 'dispatched': return translate('%s shipped', qty);
        /* translators: %s is a number of units */
        case 'in_transit': return translate('%s on the way', qty);
        /* translators: %s is a number of units */
        case 'delivered': return translate('%s delivered', qty);
        /* translators: %s is a number of units */
        case 'failed': return translate('%s with a delivery problem', qty);
        /* translators: %s is a number of units */
        case 'returned': return translate('%s returned to the store', qty);
        /* translators: %1$s is a number of units, %2$s is a shipment status */
        default: return translate('%1$s %2$s', qty, count.status_label || count.status);
    }
}

const unitCount = items => (items || []).reduce((total, item) => total + (Number(item.qty) || 0), 0);

// Purchase History badge and line, built from an order's shipments and unshipped items.
export function deliverySummary(shipments, unshippedItems) {
    if (!shipments || !shipments.length) {
        return null;
    }

    const unshipped = unitCount(unshippedItems);
    if (shipments.length === 1 && !unshipped) {
        const shipment = shipments[0];
        const parts = [shipment.carrier_name];
        if (isOutForDelivery(shipment)) {
            parts.push(translate('expected today'));
        } else if (expectedText(shipment)) {
            /* translators: %1$s is the expected delivery date */
            parts.push(translate('expected %1$s', dateTimeI18(shipment.tracking_eta, 'date_short')));
        }
        return {type: shipment.status, text: shipmentLabel(shipment), line: parts.filter(Boolean).join(' · ')};
    }

    // Units per customer word, in the order the shipments went out.
    const counts = [];
    shipments.forEach(shipment => {
        const outForDelivery = isOutForDelivery(shipment);
        let count = counts.find(entry => entry.status === shipment.status && entry.out_for_delivery === outForDelivery);
        if (!count) {
            count = {status: shipment.status, status_label: shipment.status_label, out_for_delivery: outForDelivery, qty: 0};
            counts.push(count);
        }
        count.qty += unitCount(shipment.items);
    });

    const parts = counts.map(deliveryCountText);
    if (unshipped) {
        /* translators: %s is a number of units */
        parts.push(translate('%s not shipped yet', translateNumber(unshipped)));
    }

    return {
        type: 'partially_shipped',
        text: unshipped ? translate('Partly shipped') : translate('Shipped in parts'),
        line: parts.join(' · '),
    };
}

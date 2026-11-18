const DESTINATION_FIELDS = ['country', 'state', 'postcode', 'city', 'address_id'];

/**
 * Whether saving this checkout field can change the shipping rates.
 * Billing is the destination unless "ship to a different address" is on.
 */
export function isShippingDestinationKey(key, shipToDifferent) {
    if (key === 'ship_to_different') {
        return true;
    }

    const prefix = shipToDifferent ? 'shipping_' : 'billing_';
    if (!key || !key.startsWith(prefix)) {
        return false;
    }

    return DESTINATION_FIELDS.includes(key.slice(prefix.length));
}

/**
 * The destination a save posts, as the server reads it: the posted form with
 * `data_key` set to `data_value`, then billing or shipping per ship_to_different.
 * Mirrors the fields ShippingModule::maybeRecalculateShippingCharges() re-quotes on,
 * contact included.
 */
/**
 * A field a live carrier builds its rate request from (only listed while such a
 * carrier is on): saving it re-quotes, so Place Order waits.
 */
export function isShippingQuoteFormKey(key) {
    const fields = window.fluentcart_checkout_vars?.shipping_quote_form_fields;
    return Array.isArray(fields) && !!key && fields.includes(key);
}

export function destinationSignature(formData, column, value, shipToDifferent) {
    const read = (key) => {
        if (key === column) {
            return String(value ?? '');
        }
        const values = formData.getAll(key);
        return values.length ? String(values[values.length - 1]) : '';
    };

    const toDifferent = column === 'ship_to_different' ? value === 'yes' : !!shipToDifferent;
    const prefix = toDifferent ? 'shipping_' : 'billing_';

    return JSON.stringify([toDifferent, ...DESTINATION_FIELDS.map((field) => read(prefix + field)), quoteContact(read, toDifferent)]);
}

/**
 * The contact a live carrier quotes with, as AddressHelper::quoteContact() reads it:
 * none until every field the checkout shows for it is filled.
 */
function quoteContact(read, toDifferent) {
    const fields = window.fluentcart_checkout_vars?.shipping_quote_fields?.[toDifferent ? 'shipping' : 'billing'] || [];
    const values = fields.map((key) => read(key).trim());

    return values.includes('') ? [] : values;
}

/**
 * The destination the server last stored. Any save whose posted destination
 * differs can re-quote shipping, whatever field triggered it.
 */
export class DestinationTracker {
    #confirmed = null;
    #confirmedAt = 0;

    reset(signature) {
        this.#confirmed = signature;
        this.#confirmedAt = 0;
    }

    needsHold(signature) {
        return this.#confirmed === null || signature !== this.#confirmed;
    }

    confirm(signature, sequence) {
        if (sequence >= this.#confirmedAt) {
            this.#confirmed = signature;
            this.#confirmedAt = sequence;
        }
    }

    // A failed save may or may not have been stored, so hold until one succeeds.
    forget(sequence) {
        if (sequence >= this.#confirmedAt) {
            this.#confirmed = null;
            this.#confirmedAt = sequence;
        }
    }
}

export const destinationTracker = new DestinationTracker();

/**
 * Orders checkout save responses. A response that re-renders fragments is
 * dropped when a later request's fragments are already on the page, so a
 * slow quote for an old postcode cannot replace the rates for the new one.
 * Responses without fragments never block an older one.
 */
export class SaveResponseSequence {
    #issued = 0;
    #applied = 0;

    next() {
        this.#issued += 1;
        return this.#issued;
    }

    shouldApply(sequence, data) {
        if (!data?.fragments) {
            return true;
        }

        if (sequence < this.#applied) {
            return false;
        }

        this.#applied = sequence;
        return true;
    }
}

export const saveResponseSequence = new SaveResponseSequence();

/**
 * Starts a save_checkout_data request: takes its sequence and holds the
 * place-order button while the save may re-quote shipping.
 */
export function startCheckoutSave(formData, column, value, shipToDifferent, uiService) {
    const sequence = saveResponseSequence.next();
    const destination = destinationSignature(formData, column, value, shipToDifferent);
    const hold = isShippingDestinationKey(column, shipToDifferent)
        || isShippingQuoteFormKey(column)
        || destinationTracker.needsHold(destination);
    const holdOwner = hold ? 'shipping_destination_' + sequence : null;

    if (holdOwner && uiService?.holdCheckoutButton) {
        uiService.holdCheckoutButton(holdOwner);
    }

    return {
        sequence,
        settled(stored) {
            if (stored) {
                destinationTracker.confirm(destination, sequence);
            } else {
                destinationTracker.forget(sequence);
            }
        },
        release() {
            if (holdOwner && uiService?.releaseCheckoutButton) {
                uiService.releaseCheckoutButton(holdOwner);
            }
        },
    };
}

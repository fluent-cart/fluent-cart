import CheckoutHelper from "./CheckoutHelper";
import Url from "../../admin/utils/support/Url";

const LIST = '[data-fluent-cart-checkout-page-shipping-method-wrapper]';
const ITEM = '.fct_shipping_methods_item';
const RADIO = 'input[name="fc_shipping_method"]';

/**
 * Progressive carrier rates: the server lists zone and cached rates at once and
 * names the carrier rate sources still to quote. Each source is fetched in
 * parallel and its rows are appended below the shown ones, never selected.
 */
export default class ProgressiveShippingRates {
    // In-flight request per source and list, reused when the list re-renders meanwhile.
    requests = new Map();
    // Row order the customer saw, kept when the same list re-renders.
    shown = {key: '', ids: []};

    static init(form) {
        return new ProgressiveShippingRates(form);
    }

    constructor(form) {
        this.form = form;
        window.addEventListener('fluentCartFragmentsReplaced', () => this.load());
        this.load();
    }

    load() {
        const list = this.form.querySelector(LIST);
        if (!list) {
            return;
        }

        const key = list.dataset.fctRatesKey || '';
        this.keepOrder(list, key);

        let pending = [];
        try {
            pending = JSON.parse(list.dataset.fctPendingRateSources || '[]');
        } catch (e) {
            pending = [];
        }
        if (!Array.isArray(pending) || !pending.length) {
            return;
        }

        const waiting = new Set(pending.map((item) => `${item.carrier}|${item.source}`));
        const rendered = new Set(this.ids(list));

        pending.forEach((item) => {
            this.request(item, key)
                .then((data) => {
                    // A newer list (another address) replaced this one: its own fetches add its rates.
                    if (list.isConnected && data?.rates_key === key) {
                        this.append(list, data.view || '');
                        this.dropHidden(list, rendered, data.list_ids);
                    }
                })
                // A source that fails is left out, like one that times out on the server.
                .catch(() => null)
                .finally(() => {
                    waiting.delete(`${item.carrier}|${item.source}`);
                    this.updateStatus(list, waiting);
                });
        });
    }

    request(item, key) {
        const id = `${item.carrier}|${item.source}|${key}`;
        if (!this.requests.has(id)) {
            const url = Url.appendQueryParams(
                CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes`).toString(),
                {fc_checkout_action: 'get_shipping_rate_source', carrier: item.carrier, source: item.source}
            );

            this.requests.set(id, fetch(url, {
                headers: {'X-WP-Nonce': window.fluentcart_checkout_info?.checkout_nonce || ''},
                credentials: 'include',
            }).then((response) => {
                if (!response.ok) {
                    throw new Error(`Rate source failed (${response.status})`);
                }
                return response.json();
            }).finally(() => this.requests.delete(id)));
        }

        return this.requests.get(id);
    }

    append(list, view) {
        const template = document.createElement('template');
        template.innerHTML = view;
        const chosen = list.querySelector('input[name="fc_selected_shipping_method"]')?.value || '';

        template.content.querySelectorAll(ITEM).forEach((item) => {
            const radio = item.querySelector(RADIO);
            if (!radio || this.radioFor(list, radio.value)) {
                return;
            }
            // Only the cart's own choice is checked, and never over one the customer just made.
            radio.checked = radio.value === chosen && !list.querySelector(`${RADIO}:checked`);
            item.classList.add('fct_shipping_methods_item--arrived');
            list.insertBefore(item, this.loader(list));
        });

        this.shown.ids = this.ids(list);
    }

    // The available_rates filter may hide a rendered row once a source has quoted.
    // Only rows rendered with the list are dropped: another source's rows may
    // arrive before this answer knew of them.
    dropHidden(list, rendered, listIds) {
        if (!Array.isArray(listIds)) {
            return;
        }
        const keep = new Set(listIds.map(String));
        list.querySelectorAll(`:scope > ${ITEM}`).forEach((item) => {
            const value = item.querySelector(RADIO)?.value;
            if (value && rendered.has(value) && !keep.has(value)) {
                item.remove();
            }
        });
        this.shown.ids = this.ids(list);
    }

    keepOrder(list, key) {
        if (this.shown.key === key) {
            const position = new Map(this.shown.ids.map((id, index) => [id, index]));
            const items = Array.from(list.querySelectorAll(`:scope > ${ITEM}`));
            const rank = (item) => {
                const value = item.querySelector(RADIO)?.value;
                return position.has(value) ? position.get(value) : position.size;
            };
            items.map((item, index) => ({item, index, rank: rank(item)}))
                .sort((a, b) => a.rank - b.rank || a.index - b.index)
                .forEach(({item}) => list.insertBefore(item, this.loader(list)));
        }

        this.shown = {key, ids: this.ids(list)};
    }

    updateStatus(list, waiting) {
        const section = list.parentElement;
        const status = this.loader(list);
        if (!status) {
            return;
        }

        if (waiting.size) {
            return;
        }

        // Settled: later fragment swaps of other sections must not fetch these again.
        delete list.dataset.fctPendingRateSources;
        this.collapseAndRemove(status);
        this.dropMissingChoice(list);

        const empty = section.querySelector('[data-fct-rate-sources-empty]');
        if (empty && !list.querySelector(RADIO)) {
            empty.hidden = false;
        }
    }

    // No source brought the cart's choice: clearing it re-renders the list and the
    // summary through the usual save, so nothing stays charged unselected.
    dropMissingChoice(list) {
        const chosen = list.querySelector('input[name="fc_selected_shipping_method"]');
        if (!list.isConnected || !chosen?.value || list.querySelector(`${RADIO}:checked`) || this.radioFor(list, chosen.value)) {
            return;
        }
        chosen.value = '';
        window.fluent_cart_checkout_data_watcher?.saveCustomerData('shipping_method_id', '');
    }

    // Removing the row gives the last rate its bottom border back.
    collapseAndRemove(status) {
        status.classList.remove('is-open');
        const remove = () => status.remove();
        // Reduced motion or no collapse CSS: nothing will animate.
        if (!parseFloat(getComputedStyle(status).transitionDuration)) {
            remove();
            return;
        }
        status.addEventListener('transitionend', remove, {once: true});
        // transitionend never fires while the checkout is hidden.
        setTimeout(remove, 400);
    }

    // The loader row stays last, so arriving rates go above it.
    loader(list) {
        return list.querySelector(':scope > [data-fct-rate-sources-status]');
    }

    radioFor(list, value) {
        return Array.from(list.querySelectorAll(RADIO)).find((radio) => radio.value === value);
    }

    ids(list) {
        return Array.from(list.querySelectorAll(RADIO)).map((radio) => radio.value);
    }
}

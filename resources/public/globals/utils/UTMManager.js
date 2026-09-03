// Usage examples:

// Basic usage with defaults
// const utm = new UTMManager();

// Custom configuration
// const utm = new UTMManager({
//   storageKey: 'my_utm_tracking',
//   expirationDays: 14,
//   utmKeys: ['utm_source', 'utm_medium', 'utm_campaign', 'custom_param']
// });

// Methods:
// utm.get() - Get all UTM parameters
// utm.getParam('utm_source') - Get specific parameter
// utm.clear() - Clear all stored data
// utm.setParam('utm_source', 'google') - Set single parameter
// utm.setParams({utm_source: 'google', utm_medium: 'cpc'}) - Set multiple
// utm.getExpirationInfo() - Get expiration details
// utm.setExpirationDays(60) - Update expiration period
// utm.hasConsent() - Whether attribution may be read/written right now
// utm.revokeConsent() - Withdraw consent: stop transmitting and clear storage

// Consent providers listen for the cancellable UTM_CONSENT_EVENT on window:
// window.addEventListener('fluent_cart_utm_before_store', (e) => {
//   e.preventDefault();                 // claim the write; nothing is stored yet
//   showBanner().then(ok => ok ? e.detail.allow() : e.detail.deny());
// });

/**
 * Attribution fields: one coherent marketing touch. These are exactly the keys
 * UtmHelper::addUtmToOrder() writes to their own columns on fct_order_operations,
 * so the browser and the server draw the boundary in the same place. Every other
 * allowed key is an ad-network click identifier and is routed to the `meta` column.
 */
const ATTRIBUTION_KEYS = [
    'utm_campaign',
    'utm_content',
    'utm_term',
    'utm_source',
    'utm_medium',
    'utm_id',
    'refer_url',
];

/**
 * Announced on `window` immediately before attribution would be written, and
 * cancellable. A consent provider claims the write by calling preventDefault()
 * on it, then answers with `detail.allow()` or `detail.deny()`.
 *
 * Claiming by preventDefault() rather than by registering a provider is what
 * keeps a store with no consent plugin behaving exactly as it did before this
 * gate existed: nothing cancels the event, so the write happens inline.
 */
export const UTM_CONSENT_EVENT = 'fluent_cart_utm_before_store';

/** No provider claimed the write — store freely. The pre-gate behaviour. */
const CONSENT_OPEN = 'open';
/** A provider claimed it and has not answered yet — withhold, but keep the params. */
const CONSENT_PENDING = 'pending';
const CONSENT_GRANTED = 'granted';
const CONSENT_DENIED = 'denied';

export default class UTMManager {

    constructor(options = {}) {
        this.storageKey = options.storageKey || 'fc_utm_data';
        this.expirationDays = options.expirationDays || 30;
        // Click identifiers are join keys for an ad network, not credit claims, so
        // they outlive the attribution block. Google's own _gcl_aw cookie defaults
        // to 90 days and survives intervening email and referral traffic; match it,
        // or the conversion can no longer be reported to the network at all.
        this.clickExpirationDays = options.clickExpirationDays || 90;
        this.utmKeys = options.utmKeys || UTMManager.getUtmParams();
        this.internalDomains = options.internalDomains || UTMManager.getInternalDomains();

        // Open until a consent provider claims a write. Must be set before
        // collectFromURL(), which can dispatch and resolve in the same tick.
        this.consentState = CONSENT_OPEN;

        // Initialize and collect UTM parameters on instantiation
        this.collectFromURL();
    }

    /** Split a flat parameter bag into the attribution block and the click ids. */
    static partition(params) {
        const attribution = {};
        const clickIds = {};

        Object.keys(params || {}).forEach((key) => {
            if (ATTRIBUTION_KEYS.indexOf(key) !== -1) {
                attribution[key] = params[key];
            } else {
                clickIds[key] = params[key];
            }
        });

        return {attribution, clickIds};
    }

    /**
     * Collect UTM parameters from current URL and store them
     */
    collectFromURL() {
        const currentParams = this.getURLParams();

        // A tagged arrival — any attribution parameter on the query string — is a new
        // marketing touch and replaces whatever was stored. A referrer picked up from
        // document.referrer is not: it is ambient, present on ordinary navigation, and
        // must not discard the campaign the visitor actually arrived on.
        const isFreshTouch = Object.keys(currentParams).some(key => key !== 'refer_url');

        if (currentParams['refer_url']) {
            currentParams['refer_url'] = this.normalizeReferUrl(currentParams['refer_url']);
        }

        // An explicit refer_url query param (forwarded by an internal/child site)
        // wins over document.referrer, which would only point at the child site itself
        if (!currentParams['refer_url'] && document.referrer) {
            try {
                const refHost = new URL(document.referrer).hostname;
                if (refHost !== window.location.hostname && !this.isInternalHost(refHost)) {
                    currentParams['refer_url'] = this.normalizeReferUrl(document.referrer);
                }
            } catch (error) {
                // ignore unparsable referrer
            }
        }

        if (Object.keys(currentParams).length > 0) {
            this.requestStore(currentParams, isFreshTouch);
            return;
        }

        this.requestAccess();
    }

    /**
     * Nothing new to store, but something is already stored. Ask anyway.
     *
     * An untagged pageview used to skip the announcement entirely, so the
     * manager stayed `open` and get() handed a previously stored block to
     * checkout without any provider being asked. A visitor who had refused, or
     * whose consent had lapsed, could walk from a blog post to checkout and have
     * the old attribution transmitted — the write had been gated, the read had
     * not. It also forced providers to find this global and call revokeConsent()
     * before any checkout reader ran, which is an ordering nobody can guarantee.
     *
     * This announces on the same event, so a provider needs no changes. It
     * differs from requestStore() in one way only: nothing is ever written here.
     * allow() merely unlocks what is already stored.
     */
    requestAccess() {
        // Read-only: an expired block must not be pruned here. Development
        // never touched storage on an untagged pageview, and a store with no
        // consent provider has to keep behaving exactly as it did.
        const stored = this.getStoredData(false);

        // Nothing to gate. Staying quiet keeps the event meaningful — it fires
        // when a decision actually governs something.
        if (!Object.keys(stored.params).length && !Object.keys(stored.clickIds).length) {
            return;
        }

        const event = new CustomEvent(UTM_CONSENT_EVENT, {
            cancelable: true,
            detail: {
                // Built from the block just read, not from get() — get()
                // consults hasConsent(), which is the very thing this event
                // decides.
                params: {...stored.clickIds, ...stored.params},
                replace: false,
                // Lets a provider tell "may I write this?" from "may I read what
                // is already here?". Existing providers ignore it and answer
                // both the same way, which is correct.
                reason: 'read',
                allow: () => {
                    this.consentState = CONSENT_GRANTED;
                },
                deny: () => this.revokeConsent()
            }
        });

        window.dispatchEvent(event);

        if (event.defaultPrevented) {
            // Same guard as requestStore(): a provider that already knows the
            // answer replies inside the dispatch, and demoting that to pending
            // would discard it.
            if (this.consentState === CONSENT_OPEN) {
                this.consentState = CONSENT_PENDING;
            }
        }
    }

    /**
     * Announce the write, then store unless a consent provider intervenes.
     *
     * § 25 TDDDG conditions storing anything on the visitor's device on prior
     * consent, unless the storage is strictly necessary to run the service they
     * asked for. It is not a cookies-only rule — it says "Informationen", and
     * localStorage is squarely within it. Marketing attribution is not
     * necessary to run a shop, so the write is offered up for cancellation.
     *
     * While an answer is outstanding the parameters live only in this closure
     * and are never written. Page memory is not "Speicherung in der
     * Endeinrichtung", which is what lets a visitor consent on the very page
     * they landed on without the campaign being lost in the meantime.
     *
     * allow/deny ride on the event rather than being reached through
     * window.fluentCartUtmManager because this runs from the constructor —
     * that global is not assigned until the constructor has returned.
     */
    requestStore(utmParams, replace = false) {
        const params = {...utmParams};

        const event = new CustomEvent(UTM_CONSENT_EVENT, {
            cancelable: true,
            detail: {
                params: {...params},
                replace: replace,
                allow: () => {
                    this.consentState = CONSENT_GRANTED;
                    this.store(params, replace);
                },
                deny: () => this.revokeConsent()
            }
        });

        window.dispatchEvent(event);

        if (event.defaultPrevented) {
            // A provider that claims and answers in the same handler has already
            // moved the state; only an unanswered claim is left pending. Assigning
            // unconditionally here would demote that answer back to 'pending' and
            // silently discard a consent given synchronously — which is the normal
            // path on every visit after the first, where the decision is known.
            if (this.consentState === CONSENT_OPEN) {
                this.consentState = CONSENT_PENDING;
            }

            return;
        }

        this.store(params, replace);
    }

    /**
     * Whether stored attribution may be read or written at this moment.
     *
     * Open (nobody claimed it) and granted both qualify. A claim that has not
     * been answered yet does not: the visitor has been asked and has not said
     * yes, so nothing may be stored or transmitted while they decide.
     */
    hasConsent() {
        return this.consentState === CONSENT_OPEN || this.consentState === CONSENT_GRANTED;
    }

    /**
     * Withdraw consent: stop transmitting and delete what was already stored.
     *
     * GDPR Art. 7(3) requires withdrawal to be as easy as giving consent and to
     * actually take effect, so this removes the stored block rather than merely
     * closing the gate on future writes. Public because withdrawal also happens
     * away from a tagged arrival — a "cookie preferences" link on any page —
     * where there is no event in flight to answer.
     */
    revokeConsent() {
        this.consentState = CONSENT_DENIED;
        this.clear();
    }

    /**
     * Reduce a referrer (full URL or bare host) to its bare domain:
     * no scheme, no www. prefix, no path — e.g. "google.com"
     */
    normalizeReferUrl(value) {
        const raw = String(value || '').trim();
        if (!raw) {
            return '';
        }
        const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(raw) ? raw : 'https://' + raw.replace(/^\/+/, '');
        try {
            return new URL(withScheme).hostname.toLowerCase().replace(/^www\./, '');
        } catch (error) {
            return raw;
        }
    }

    /**
     * Whether a hostname belongs to the store network (child/product sites
     * that redirect visitors here for checkout)
     */
    isInternalHost(hostname) {
        const host = (hostname || '').toLowerCase();
        return this.internalDomains.some(domain => {
            const normalized = String(domain).toLowerCase().replace(/^www\./, '');
            return host === normalized || host === 'www.' + normalized || host.endsWith('.' + normalized);
        });
    }

    /**
     * Get current URL parameters
     */
    getURLParams() {
        const urlParams = new URLSearchParams(window.location.search);
        const params = {};

        this.utmKeys.forEach(key => {
            const value = urlParams.get(key);
            if (value) {
                params[key] = value;
            }
        });

        return params;
    }


    /**
     * Store UTM parameters with timestamp
     *
     * @param {Object} utmParams
     * @param {boolean} replace Whether this is a new marketing touch, which supersedes
     *                          the stored attribution instead of being merged into it.
     */
    store(utmParams, replace = false) {
        const existingData = this.getStoredData();
        const timestamp = Date.now();
        const incoming = UTMManager.partition(utmParams);

        // Merging the attribution block key by key let a field absent from the new
        // touch survive from the previous one — a visitor arriving on a click that
        // carried only a click id kept the campaign they arrived on weeks earlier,
        // and the sale was credited to it. A fresh touch therefore replaces the
        // whole block, including clearing it when the new click carried no tags.
        const updatedParams = replace ? {...incoming.attribution} : {
            ...existingData.params,
            ...incoming.attribution
        };

        // Click identifiers are merged per key regardless: a later email or referral
        // touch must not destroy the ad click it is stacked on top of, and each
        // network replaces only its own identifier.
        const updatedClickIds = {
            ...existingData.clickIds,
            ...incoming.clickIds
        };

        const hasNewClickId = Object.keys(incoming.clickIds).length > 0;

        const dataToStore = {
            params: updatedParams,
            timestamp: timestamp,
            clickIds: updatedClickIds,
            clickTimestamp: hasNewClickId ? timestamp : (existingData.clickTimestamp || null)
        };

        try {
            localStorage.setItem(this.storageKey, JSON.stringify(dataToStore));
        } catch (error) {
            //console.warn('UTMManager: Unable to store data in localStorage', error);
        }
    }

    /**
     * Get stored UTM data from localStorage
     */
    /**
     * @param {boolean} pruneExpired Whether to delete a block that has expired
     *        entirely. Callers that are only asking "is there anything here?"
     *        pass false: this used to run only when something read the block, so
     *        pruning from anywhere else would write to storage on pageviews that
     *        previously did not, which is a behaviour change for stores with no
     *        consent provider at all.
     */
    getStoredData(pruneExpired = true) {
        const empty = {params: {}, timestamp: null, clickIds: {}, clickTimestamp: null};

        try {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) {
                return empty;
            }

            const result = JSON.parse(stored);
            if (!result || !result.timestamp) {
                return empty;
            }

            // Blocks written before the two-bucket split kept everything under
            // `params`. Partition them on read so an existing visitor's click id is
            // carried over rather than expiring with the attribution block.
            const legacy = !result.clickIds;
            const partitioned = legacy
                ? UTMManager.partition(result.params)
                : {attribution: result.params || {}, clickIds: result.clickIds || {}};

            const clickTimestamp = legacy ? result.timestamp : (result.clickTimestamp || null);

            const data = {
                params: this.isExpired(result.timestamp, this.expirationDays) ? {} : partitioned.attribution,
                timestamp: result.timestamp,
                clickIds: this.isExpired(clickTimestamp, this.clickExpirationDays) ? {} : partitioned.clickIds,
                clickTimestamp: clickTimestamp
            };

            if (!Object.keys(data.params).length && !Object.keys(data.clickIds).length) {
                if (pruneExpired) {
                    this.clear();
                }

                return empty;
            }

            return data;
        } catch (error) {
            // Unparsable storage is treated as absent attribution.
        }

        return empty;
    }

    /**
     * Whether stored attribution has outlived expirationDays.
     *
     * expirationDays was previously assigned and never read, so attribution persisted
     * indefinitely and a click from any point in the past could still be credited.
     */
    isExpired(timestamp, expirationDays = null) {
        const days = expirationDays === null ? this.expirationDays : expirationDays;

        if (!days || !timestamp) {
            return false;
        }

        return (Date.now() - timestamp) > days * 24 * 60 * 60 * 1000;
    }

    /**
     * Get UTM parameters
     */
    /**
     * The two buckets are an internal concern: consumers post one flat `utm_data`
     * bag, and UtmHelper::addUtmToOrder() re-splits it into columns and `meta` on
     * the server. Attribution wins a key collision, since those keys are its own.
     */
    get() {
        // Gating the single reader keeps every consumer honest without touching
        // them: the checkout handlers and address savers all post whatever this
        // returns, so withholding here withholds from all of them at once.
        if (!this.hasConsent()) {
            return {};
        }

        const data = this.getStoredData();

        return {
            ...(data.clickIds || {}),
            ...(data.params || {})
        };
    }

    /**
     * Clear all stored UTM data
     */
    clear() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch (error) {
        }
    }

    /**
     * Reading `allowed_keys` off an unlocalized `fluentcart_utm_vars` threw before
     * the fallback could apply, and the throw is inside the constructor, so the
     * whole visit went unattributed rather than falling back to these keys.
     */
    static getUtmParams() {
        return (window.fluentcart_utm_vars && window.fluentcart_utm_vars.allowed_keys)
            || ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
    }

    static getInternalDomains() {
        return (window.fluentcart_utm_vars && window.fluentcart_utm_vars.internal_domains) || [];
    }
}

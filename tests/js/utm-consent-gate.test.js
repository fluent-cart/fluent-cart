import {beforeEach, describe, expect, it} from 'vitest';

import UTMManager, {UTM_CONSENT_EVENT} from '../../resources/public/globals/utils/UTMManager.js';
import FluentCartCart from '../../resources/public/globals/Cart/FluentCartCart.js';

/**
 * § 25 TDDDG makes storing anything on a visitor's device conditional on prior
 * consent unless it is strictly necessary to run the service they asked for.
 * It is not a cookies-only rule — the statute says "Informationen", and the
 * attribution block lives in localStorage, squarely inside it. Marketing
 * attribution is not necessary to run a shop, so the write has to be gated.
 *
 * The gate is a cancellable event rather than a registered provider: a consent
 * plugin claims the write with preventDefault(), and a store with no such
 * plugin cancels nothing and keeps writing inline. That default is the single
 * most important property here — the overwhelming majority of stores are not in
 * scope for § 25 and must see no change at all.
 *
 * The parameters are held in memory while an answer is outstanding and never
 * written. Page memory is not "Speicherung in der Endeinrichtung", which is
 * what lets a visitor consent on the page they landed on without the campaign
 * being lost in between.
 */

const DAY = 24 * 60 * 60 * 1000;

const ALLOWED_KEYS = [
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
    'refer_url', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'gad_campaignid', 'gad_source', 'msclkid',
];

function fakeStorage() {
    const rows = new Map();

    return {
        getItem: key => (rows.has(key) ? rows.get(key) : null),
        setItem: (key, value) => rows.set(key, String(value)),
        removeItem: key => rows.delete(key),
    };
}

/** One pageview on the checkout domain. Returns the manager it constructed. */
function visit(search) {
    global.window.location.search = search;

    return new UTMManager();
}

/** What actually reached the device, as opposed to what get() is willing to hand out. */
function storedBlock() {
    const raw = global.localStorage.getItem('fc_utm_data');

    return raw === null ? null : JSON.parse(raw);
}

beforeEach(() => {
    global.localStorage = fakeStorage();
    global.window = Object.assign(new EventTarget(), {
        location: {search: '', hostname: 'wpmanageninja.com'},
        fluentcart_utm_vars: {
            allowed_keys: ALLOWED_KEYS,
            internal_domains: ['fluentforms.com'],
        },
    });
    global.document = {referrer: ''};
});

describe('UTM consent gate', () => {

    /**
     * The no-provider path. Every assertion here describes behaviour that
     * predates the gate, so a failure means the gate leaked into stores that
     * never asked for it.
     */
    describe('a store with no consent provider is unaffected', () => {

        it('stores a tagged arrival immediately', () => {
            visit('?utm_source=google&utm_medium=cpc&gclid=ABC');

            expect(storedBlock().params).toEqual({utm_source: 'google', utm_medium: 'cpc'});
            expect(storedBlock().clickIds).toEqual({gclid: 'ABC'});
        });

        it('hands the attribution to checkout', () => {
            const manager = visit('?utm_source=google&utm_campaign=spring');

            expect(manager.get().utm_source).toBe('google');
            expect(manager.get().utm_campaign).toBe('spring');
        });

        it('announces the write on window before making it', () => {
            const seen = [];
            global.window.addEventListener(UTM_CONSENT_EVENT, event => seen.push(event.detail.params));

            visit('?utm_source=google&utm_medium=cpc');

            expect(seen).toEqual([{utm_source: 'google', utm_medium: 'cpc'}]);
        });

        it('announces nothing on an untagged page, having nothing to store', () => {
            const seen = [];
            global.window.addEventListener(UTM_CONSENT_EVENT, () => seen.push(true));

            visit('');

            expect(seen).toEqual([]);
        });
    });

    describe('a provider that claims the write', () => {

        it('leaves the device untouched while the visitor decides', () => {
            global.window.addEventListener(UTM_CONSENT_EVENT, event => event.preventDefault());

            const manager = visit('?utm_source=google&utm_medium=cpc');

            expect(storedBlock()).toBeNull();
            expect(manager.get()).toEqual({});
        });

        /**
         * The parameters must survive in memory, unwritten, so that consenting
         * on the landing page still credits the campaign the visitor arrived
         * on. Writing them early would be the § 25 violation; discarding them
         * would make consent cost the store its attribution.
         */
        it('still credits the arrival campaign when consent comes later', () => {
            let decision = null;
            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                decision = event.detail;
            });

            const manager = visit('?utm_source=google&utm_medium=cpc&gclid=ABC');
            expect(storedBlock()).toBeNull();

            decision.allow();

            expect(storedBlock().params).toEqual({utm_source: 'google', utm_medium: 'cpc'});
            expect(manager.get().gclid).toBe('ABC');
        });

        /**
         * Regression guard. A provider that already knows the visitor's answer
         * — every visit after the first — claims and answers in the same
         * handler, so allow() runs before dispatchEvent() has returned.
         * Marking the manager pending on defaultPrevented without checking
         * whether it had already been answered discarded that consent and
         * withheld attribution for the rest of the pageview.
         */
        it('honours a decision taken in the same tick as the claim', () => {
            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                event.detail.allow();
            });

            const manager = visit('?utm_source=google&utm_medium=cpc');

            expect(storedBlock().params).toEqual({utm_source: 'google', utm_medium: 'cpc'});
            expect(manager.get().utm_source).toBe('google');
        });

        it('writes nothing when the visitor refuses', () => {
            let decision = null;
            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                decision = event.detail;
            });

            const manager = visit('?utm_source=google&utm_medium=cpc');
            decision.deny();

            expect(storedBlock()).toBeNull();
            expect(manager.get()).toEqual({});
        });

        /**
         * Contract the consent add-on relies on: the parameters outlive a
         * refusal. A consent plugin can report "not answered yet" as a deny
         * before the visitor has touched its banner — CookieYes does, through
         * the WP Consent API — and the accept that follows on the same page
         * must still be able to credit the campaign the visitor arrived on.
         */
        it('stores the held parameters when allow() follows deny()', () => {
            let decision = null;
            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                decision = event.detail;
            });

            const manager = visit('?utm_source=google&utm_medium=cpc&gclid=ABC');
            decision.deny();
            expect(storedBlock()).toBeNull();

            decision.allow();

            expect(storedBlock().params).toEqual({utm_source: 'google', utm_medium: 'cpc'});
            expect(manager.get().gclid).toBe('ABC');
            expect(manager.hasConsent()).toBe(true);
        });

        /**
         * Art. 7(3): withdrawal must be as easy as consent and must take
         * effect. Closing the gate on future writes while leaving the existing
         * block on the device would not be withdrawal.
         */
        it('erases attribution captured before the refusal', () => {
            global.localStorage.setItem('fc_utm_data', JSON.stringify({
                params: {utm_source: 'oldcampaign'},
                timestamp: Date.now(),
                clickIds: {gclid: 'OLD'},
                clickTimestamp: Date.now(),
            }));

            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                event.detail.deny();
            });

            const manager = visit('?utm_source=google');

            expect(storedBlock()).toBeNull();
            expect(manager.get()).toEqual({});
        });
    });

    /**
     * The write was gated from the start; the read was not. An untagged
     * pageview announced nothing, so the manager stayed 'open' and get() handed
     * a previously stored block to checkout without asking anyone. A visitor
     * who had refused, or whose consent had lapsed, could go from a blog post
     * to checkout and have the old attribution transmitted.
     */
    describe('an untagged page still asks before releasing what is stored', () => {

        const storeEarlierVisit = () => {
            global.localStorage.setItem('fc_utm_data', JSON.stringify({
                params: {utm_source: 'google', utm_campaign: 'earlier'},
                timestamp: Date.now(),
                clickIds: {gclid: 'EARLIER'},
                clickTimestamp: Date.now(),
            }));
        };

        it('asks the provider even with no parameters and no referrer', () => {
            storeEarlierVisit();
            const seen = [];
            global.window.addEventListener(UTM_CONSENT_EVENT, event => seen.push(event.detail.reason));

            visit('');

            expect(seen).toEqual(['read']);
        });

        it('withholds and erases the stored block when the provider refuses', () => {
            storeEarlierVisit();
            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                event.detail.deny();
            });

            const manager = visit('');

            expect(manager.get()).toEqual({});
            expect(storedBlock()).toBeNull();
        });

        it('withholds while the visitor has not answered yet', () => {
            storeEarlierVisit();
            global.window.addEventListener(UTM_CONSENT_EVENT, event => event.preventDefault());

            const manager = visit('');

            expect(manager.consentState).toBe('pending');
            expect(manager.get()).toEqual({});
            // Held, not destroyed — an accept must still be able to release it.
            expect(storedBlock()).not.toBeNull();
        });

        it('releases the stored block when the provider allows, writing nothing new', () => {
            storeEarlierVisit();
            const before = storedBlock().timestamp;
            global.window.addEventListener(UTM_CONSENT_EVENT, event => {
                event.preventDefault();
                event.detail.allow();
            });

            const manager = visit('');

            expect(manager.get().utm_campaign).toBe('earlier');
            expect(manager.get().gclid).toBe('EARLIER');
            // This path grants access; it must never restamp the block, which
            // would extend the attribution window without a new touch.
            expect(storedBlock().timestamp).toBe(before);
        });

        it('stays silent when there is nothing stored to release', () => {
            const seen = [];
            global.window.addEventListener(UTM_CONSENT_EVENT, () => seen.push(true));

            visit('');

            expect(seen).toEqual([]);
        });

        it('leaves a store with no provider completely unaffected', () => {
            storeEarlierVisit();

            const manager = visit('');

            expect(manager.consentState).toBe('open');
            expect(manager.get().utm_campaign).toBe('earlier');
        });

        /**
         * Asking on an untagged page must not touch storage on the way.
         *
         * The first cut read the block through getStoredData(), which prunes a
         * fully expired one as a side effect. That happened only when something
         * read the block before, so the gate introduced a localStorage write on
         * pageviews that previously had none — invisible in every assertion
         * about what reaches checkout, and still a behaviour change for a store
         * that has no consent provider at all.
         */
        it('does not prune an expired block just because it looked', () => {
            const stale = {
                params: {utm_source: 'google', utm_campaign: 'ancient'},
                timestamp: Date.now() - 40 * DAY,
                clickIds: {gclid: 'ANCIENT'},
                clickTimestamp: Date.now() - 100 * DAY,
            };
            global.localStorage.setItem('fc_utm_data', JSON.stringify(stale));

            const manager = visit('');

            // Checked before get(), because get() prunes — that is pre-existing
            // behaviour and not what this test is about. The question is only
            // whether merely constructing the manager touched storage.
            expect(storedBlock()).not.toBeNull();
            expect(storedBlock().params.utm_campaign).toBe('ancient');

            expect(manager.get()).toEqual({});
        });
    });

    /**
     * Withdrawal usually happens away from a tagged arrival — a "cookie
     * preferences" link on an ordinary page — where no event is in flight to
     * answer, so the provider needs a direct route.
     */
    describe('withdrawing consent outside a tagged arrival', () => {

        it('erases the stored block and closes the gate', () => {
            const manager = visit('?utm_source=google&utm_campaign=spring');
            expect(manager.get().utm_source).toBe('google');

            manager.revokeConsent();

            expect(storedBlock()).toBeNull();
            expect(manager.get()).toEqual({});
            expect(manager.hasConsent()).toBe(false);
        });
    });

    /**
     * get() is the single reader every checkout handler and address saver goes
     * through, so withholding here withholds from all of them without any of
     * them needing to know the gate exists.
     */
    describe('the transmission path follows the decision', () => {

        it('withholds a stored block from checkout once consent is refused', () => {
            const manager = visit('?utm_source=google&utm_campaign=spring');
            expect(manager.get()).not.toEqual({});

            manager.revokeConsent();

            expect(manager.get()).toEqual({});
        });
    });

    /**
     * Add-to-cart forwards the campaign straight off the URL and never goes
     * through store(), so the event gate does not cover it. It was the one
     * collection path that could keep leaking after everything else was gated.
     */
    describe('the add-to-cart collection path', () => {

        beforeEach(() => {
            global.window.fluentCartRestVars = {
                ajaxurl: 'https://wpmanageninja.com/wp-admin/admin-ajax.php',
                rest: {nonce: 'test-nonce'},
            };
            global.window.fluentcart_drawer_vars = {is_admin_bar_showing: false, is_drawer_hidden: '0'};
            global.window.location.search = '?utm_source=google&utm_medium=cpc';
        });

        it('forwards the campaign when nothing has withheld consent', () => {
            global.window.fluentCartUtmManager = {hasConsent: () => true};

            expect(new FluentCartCart().appendUtmSource({id: 7}))
                .toEqual({id: 7, utm_source: 'google', utm_medium: 'cpc'});
        });

        it('forwards nothing while consent is withheld', () => {
            global.window.fluentCartUtmManager = {hasConsent: () => false};

            expect(new FluentCartCart().appendUtmSource({id: 7})).toEqual({id: 7});
        });

        it('keeps forwarding when no manager is on the page to consult', () => {
            delete global.window.fluentCartUtmManager;

            expect(new FluentCartCart().appendUtmSource({id: 7}))
                .toEqual({id: 7, utm_source: 'google', utm_medium: 'cpc'});
        });
    });
});

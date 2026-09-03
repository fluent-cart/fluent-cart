import {beforeEach, describe, expect, it, vi} from 'vitest';

import UTMManager from '../../resources/public/globals/utils/UTMManager.js';

/**
 * Regression: stored attribution was merged key by key and never expired, so a
 * campaign from one visit was credited with a sale from a later, different one.
 *
 * `store()` did `{...existingData.params, ...utmParams}`. A key absent from the
 * new visit kept its old value. So a visitor who first arrived on a non-brand ad
 * (`utm_campaign=ffppc0525`) and later returned through a brand ad — whose URL
 * carries a `gclid` but no `utm_*` tags, because Google auto-tagging supplies the
 * click id and the campaign tag is only on the non-brand ad's final URL — kept
 * `ffppc0525`. The `gclid` updated; the campaign did not.
 *
 * Measured on the WP Manage Ninja store over 90 days: the campaign the tag
 * credited earned $2,865, of which $2,104 (73%) came from clicks that actually
 * landed on a different campaign.
 *
 * Second defect in the same class: `expirationDays` was assigned in the
 * constructor and never read. `getStoredData()` returned whatever was stored the
 * moment a timestamp existed, so attribution persisted indefinitely.
 *
 * Both feed `fct_order_operations` via the checkout's `utm_data`, so a wrong
 * value here becomes a wrong value on the order.
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
function visit(search, {referrer = '', options = {}} = {}) {
    global.window.location.search = search;
    global.document.referrer = referrer;

    return new UTMManager(options);
}

function at(days) {
    vi.spyOn(Date, 'now').mockReturnValue(new Date('2026-08-01T00:00:00Z').getTime() + days * DAY);
}

beforeEach(() => {
    global.localStorage = fakeStorage();
    // A browser window is an EventTarget, and the manager announces every write
    // on it before storing, so the stub has to be one too.
    global.window = Object.assign(new EventTarget(), {
        location: {search: '', hostname: 'wpmanageninja.com'},
        fluentcart_utm_vars: {
            allowed_keys: ALLOWED_KEYS,
            internal_domains: ['fluentforms.com'],
        },
    });
    global.document = {referrer: ''};
    at(0);
});

describe('UTMManager attribution', () => {

    describe('a fresh tagged visit replaces the stored block', () => {

        it('drops the previous campaign when the new click carries no utm tags', () => {
            visit('?utm_source=google&utm_medium=cpc&utm_campaign=ffppc0525&gclid=AAA');

            const stored = visit('?gclid=BBB').get();

            expect(stored.gclid).toBe('BBB');
            expect(stored.utm_campaign).toBeUndefined();
            expect(stored.utm_source).toBeUndefined();
            expect(stored.utm_medium).toBeUndefined();
        });

        it('replaces a campaign rather than keeping both', () => {
            visit('?utm_source=news&utm_medium=email&utm_campaign=spring');

            const stored = visit('?utm_source=google&utm_medium=cpc&utm_campaign=summer').get();

            expect(stored.utm_campaign).toBe('summer');
            expect(stored.utm_source).toBe('google');
            expect(stored.utm_medium).toBe('cpc');
        });

        /**
         * Superseded by "ad click identifiers outlive the attribution block".
         *
         * This previously asserted the opposite — that a later non-ad touch drops
         * the click id. That over-corrected: a `utm_*` block and a click id answer
         * different questions. The block is a credit claim and belongs to exactly
         * one touch; a click id is a join key for an ad network that applies its
         * own attribution window on its side. Google's own `_gcl_aw` cookie
         * survives intervening email and referral traffic for this reason, so
         * dropping it here only removed our ability to report the conversion at all.
         */
        it('replaces the campaign but keeps the click id from the earlier ad', () => {
            visit('?utm_campaign=ffppc0525&gclid=AAA');

            const stored = visit('?utm_campaign=newsletter').get();

            expect(stored.utm_campaign).toBe('newsletter');
            expect(stored.gclid).toBe('AAA');
        });
    });

    /**
     * The stored block is two things with two lifetimes. Attribution fields —
     * the six utm_* keys plus refer_url, which are exactly the columns
     * UtmHelper::addUtmToOrder() writes directly — describe one touch and are
     * replaced wholesale by the next one. Ad click identifiers, which that same
     * helper routes to the `meta` column, persist until the same network sends
     * a newer one.
     */
    describe('ad click identifiers outlive the attribution block', () => {

        it('keeps the gclid when a later email click takes the credit', () => {
            visit('?utm_source=google&utm_medium=cpc&utm_campaign=spring&gclid=ABC');

            const stored = visit('?utm_source=newsletter&utm_medium=email&utm_campaign=july').get();

            expect(stored.utm_source).toBe('newsletter');
            expect(stored.utm_medium).toBe('email');
            expect(stored.utm_campaign).toBe('july');
            expect(stored.gclid).toBe('ABC');
        });

        it('lets a newer ad click replace the older one', () => {
            visit('?utm_campaign=spring&gclid=ABC');

            const stored = visit('?utm_campaign=summer&gclid=XYZ').get();

            expect(stored.gclid).toBe('XYZ');
            expect(stored.utm_campaign).toBe('summer');
        });

        it('keeps identifiers from different networks side by side', () => {
            visit('?gclid=ABC');

            const stored = visit('?msclkid=BING1').get();

            expect(stored.gclid).toBe('ABC');
            expect(stored.msclkid).toBe('BING1');
        });

        it('still clears a stale campaign when an auto-tagged click carries no utm tags', () => {
            visit('?utm_source=google&utm_medium=cpc&utm_campaign=ffppc0525&gclid=AAA');

            const stored = visit('?gclid=BBB').get();

            expect(stored.gclid).toBe('BBB');
            expect(stored.utm_campaign).toBeUndefined();
            expect(stored.utm_source).toBeUndefined();
        });

        it('outlives the attribution window', () => {
            at(0);
            visit('?utm_campaign=spring&gclid=ABC');

            at(40);

            const stored = visit('').get();

            expect(stored.utm_campaign).toBeUndefined();
            expect(stored.gclid).toBe('ABC');
        });

        it('expires on its own longer window', () => {
            at(0);
            visit('?gclid=ABC');

            at(100);

            expect(visit('').get().gclid).toBeUndefined();
        });

        it('reads a block stored in the previous single-bucket format', () => {
            global.localStorage.setItem('fc_utm_data', JSON.stringify({
                params: {utm_campaign: 'legacy', gclid: 'LEGACY1'},
                timestamp: new Date('2026-08-01T00:00:00Z').getTime(),
            }));

            const stored = visit('').get();

            expect(stored.utm_campaign).toBe('legacy');
            expect(stored.gclid).toBe('LEGACY1');
        });
    });

    describe('untagged navigation leaves the stored block alone', () => {

        it('keeps attribution across an internal pageview', () => {
            visit('?utm_campaign=spring&utm_source=news&gclid=AAA');

            const stored = visit('').get();

            expect(stored.utm_campaign).toBe('spring');
            expect(stored.gclid).toBe('AAA');
        });

        it('does not treat a bare external referrer as a new campaign', () => {
            visit('?utm_campaign=spring&utm_source=news');

            const stored = visit('', {referrer: 'https://someblog.example/post'}).get();

            expect(stored.utm_campaign).toBe('spring');
            expect(stored.refer_url).toBe('someblog.example');
        });

        it('ignores a referrer from a store domain', () => {
            const stored = visit('', {referrer: 'https://www.fluentforms.com/pricing/'}).get();

            expect(stored.refer_url).toBeUndefined();
        });
    });

    describe('stored attribution expires', () => {

        it('forgets a campaign older than expirationDays', () => {
            at(0);
            visit('?utm_campaign=spring&utm_source=news');

            at(31);

            expect(visit('').get()).toEqual({});
        });

        it('keeps a campaign inside the window', () => {
            at(0);
            visit('?utm_campaign=spring&utm_source=news');

            at(29);

            expect(visit('').get().utm_campaign).toBe('spring');
        });

        it('honours a custom expirationDays', () => {
            at(0);
            visit('?utm_campaign=spring', {options: {expirationDays: 7}});

            at(8);

            expect(visit('', {options: {expirationDays: 7}}).get()).toEqual({});
        });

        it('restarts the window on a fresh tagged visit', () => {
            at(0);
            visit('?utm_campaign=spring');

            at(20);
            visit('?utm_campaign=summer');

            at(45);

            expect(visit('').get().utm_campaign).toBe('summer');
        });
    });

    /**
     * `getUtmParams()` read `window.fluentcart_utm_vars.allowed_keys` without a
     * guard, so on any page where AssetLoader had not localized that object the
     * property access threw before the `||` fallback could apply. The throw
     * happens in the constructor, so the manager never came up and the visit was
     * not attributed at all — a silent, total loss rather than a degraded one.
     */
    describe('bootstrap when the localized vars are missing', () => {

        it('still captures a tagged visit using the built-in key list', () => {
            delete global.window.fluentcart_utm_vars;

            const stored = visit('?utm_source=google&utm_medium=cpc&utm_campaign=spring').get();

            expect(stored.utm_source).toBe('google');
            expect(stored.utm_medium).toBe('cpc');
            expect(stored.utm_campaign).toBe('spring');
        });

        it('survives an entirely absent vars object without throwing', () => {
            delete global.window.fluentcart_utm_vars;

            expect(() => visit('?utm_source=google')).not.toThrow();
        });

        it('treats a localized object with no allowed_keys as the built-in list', () => {
            global.window.fluentcart_utm_vars = {};

            expect(visit('?utm_source=google').get().utm_source).toBe('google');
        });
    });
});

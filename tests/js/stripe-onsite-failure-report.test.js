import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * A failed onsite confirm used to fall through: the error branch showed a toast
 * and kept going into the success branch, and nothing told the server the
 * attempt was over. The transaction stayed `pending`, so `CheckoutProcessor`
 * never bumped `payment_attempt` (it bumps only for a `failed` transaction),
 * the retry reused the same idempotency seed, and Stripe replayed its 24h-cached
 * response for a subscription the create-guard had already deleted —
 * `payment_intent_unexpected_state` for a full day.
 *
 * Source guard: the suite has no DOM, and stripe-checkout.js exports nothing.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources');

function confirmErrorBranch() {
    const source = fs.readFileSync(path.join(ROOT, 'public/payment-methods/stripe-checkout.js'), 'utf8');
    const start = source.indexOf('confirmIntent(confirmData).then(');
    expect(start, 'confirmIntent(confirmData) call not found — locate it by symbol and re-pin').toBeGreaterThan(-1);

    const errorAt = source.indexOf('if (result?.error) {', start);
    expect(errorAt, 'confirm error branch not found').toBeGreaterThan(start);

    const end = source.indexOf('const intent = result[accessor];', errorAt);
    expect(end, 'error branch bounds moved').toBeGreaterThan(errorAt);

    return source.slice(errorAt, end);
}

describe('stripe onsite confirm return url', () => {
    it('hands Stripe the internal return route, not the filterable success url', () => {
        const source = fs.readFileSync(path.join(ROOT, 'public/payment-methods/stripe-checkout.js'), 'utf8');

        expect(source).toMatch(/gateway_return_url/);
        expect(source).toMatch(/return_url:\s*gatewayReturnUrl/);
    });
});

describe('stripe onsite confirm failure reporting', () => {
    it('stops on a failed confirm instead of falling into the success branch', () => {
        expect(confirmErrorBranch()).toMatch(/\breturn;/);
    });

    it('keeps checkout disabled until the failure report resolves', () => {
        const branch = confirmErrorBranch();

        // Re-enabling inline lets a fast buyer resubmit while the transaction is
        // still pending — the stale-seed replay this whole report exists to
        // prevent. The single call must sit behind the request's own callbacks.
        expect(branch.match(/enableCheckoutButton/g)).toHaveLength(1);
        expect(branch.indexOf('enableCheckoutButton'), 'the only re-enable is not inside releaseCheckout')
            .toBeGreaterThan(branch.indexOf('const releaseCheckout'));
        expect(branch).toMatch(/onload\s*=\s*function[\s\S]*?releaseCheckout\(recorded\)/);
        expect(branch).toMatch(/onerror\s*=\s*function[^}]*releaseCheckout\(false\)/);
        expect(branch).toMatch(/ontimeout\s*=\s*function[^}]*releaseCheckout\(false\)/);

        // Every terminal outcome of the request has to release the button, or a
        // dropped connection leaves checkout stuck.
        expect(branch).toMatch(/failureXhr\.onload/);
        expect(branch).toMatch(/failureXhr\.onerror/);
        expect(branch).toMatch(/failureXhr\.ontimeout/);
        expect(branch).toMatch(/failureXhr\.timeout\s*=/);
    });

    it('lifts the full-viewport overlay when it hands checkout back', () => {
        const branch = confirmErrorBranch();
        const release = branch.slice(branch.indexOf('const releaseCheckout'));

        // `.fct-loader.active` is fixed, opaque and z-index 999999 on the custom
        // payment page (custom-payment-page.scss). Left up, the error message and
        // the re-enabled button are both behind it.
        expect(release).toMatch(/loaderElement\?\.classList\?\.remove\('active'\)/);
        expect(release.indexOf("classList?.remove('active')"))
            .toBeLessThan(release.indexOf('enableCheckoutButton'));
    });

    it('does not hand the button back when the failure was never recorded', () => {
        const branch = confirmErrorBranch();
        const release = branch.slice(branch.indexOf('const releaseCheckout'));
        const unreported = release.slice(release.indexOf('if (!reported)'), release.indexOf('enableCheckoutButton'));

        // An unrecorded failure leaves the transaction `pending`, so the seed does
        // not roll — an enabled button replays Stripe's cached response for a dead
        // intent. Reload is the only safe path, and the notice must stay up.
        expect(unreported).toMatch(/return;/);
        expect(unreported).toMatch(/-1/);
        expect(release.indexOf('if (!reported)'))
            .toBeLessThan(release.indexOf('enableCheckoutButton'));
    });

    it('treats only a terminal transaction status as recorded, not any HTTP reply', () => {
        const branch = confirmErrorBranch();

        // A 400 is also how "invalid request" and an unfinished 3DS challenge
        // answer, and a 500 means nothing was written. Releasing on any reply
        // hands the buyer a retry against a still-pending transaction.
        expect(branch).toMatch(/transaction_status\s*===\s*'failed'/);
        expect(branch).toMatch(/transaction_status\s*===\s*'succeeded'/);
        expect(branch).toMatch(/JSON\.parse\(failureXhr\.responseText/);
        expect(branch).not.toMatch(/onload\s*=\s*function[^}]*releaseCheckout\(true\)/);
    });

    it('reports the failed intent back to the confirm endpoint with its trx_hash', () => {
        const branch = confirmErrorBranch();

        expect(branch).toMatch(/action:\s*'fluent_cart_confirm_stripe_payment'/);
        expect(branch).toMatch(/trx_hash:\s*trxHash/);
        // The server marks the transaction failed only for a reporter that also
        // knows the hash, so sending the intent id alone is not enough.
        expect(branch).toMatch(/result\.error\?\.payment_intent\?\.id/);
        expect(branch).toMatch(/result\.error\?\.setup_intent\?\.id/);
    });
});

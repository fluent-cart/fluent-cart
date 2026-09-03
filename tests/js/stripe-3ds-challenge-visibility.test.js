import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * Onsite Stripe checkout confirms with `redirect: 'if_required'`, so a card that
 * needs 3D Secure gets its challenge rendered INLINE by Stripe.js, in an iframe
 * appended to document.body of the current page.
 *
 * Both checkout loaders are appended to that same body, are `position: fixed`,
 * cover the full viewport, carry `z-index: 999999` and paint an opaque
 * `rgba(0,0,0,0.7)` backdrop:
 *
 *   .fct-order-processing  — checkout page          (checkout.scss)
 *   .fct-loader.active     — custom payment page    (custom-payment-page.scss)
 *
 * They used to be taken down immediately before `confirmIntent(...)` and left
 * down for its whole lifetime. That kept the challenge reachable, but a card
 * that does not need 3DS flashed the checkout form (worse in modal checkout,
 * where the modal shell stays up). Stripe still does not tell us 3DS is coming
 * before confirm, so the overlay stays up unless a challenge iframe appears
 * *during* the pending confirm — outside the Payment Element container, which
 * already has Stripe iframes before Pay Now.
 *
 * Left covering the challenge, the buyer saw a black screen, a spinner and
 * "Please Don't close the browser" while the OTP prompt sat behind it. They
 * waited, then left — and Stripe recorded "3D Secure attempt failed / The
 * customer failed 3D Secure authentication". The PaymentIntent stayed
 * `requires_action`, the subscription stayed `incomplete`, and Stripe
 * auto-cancelled it ~23h later as `incomplete_expired`.
 *
 * This is a source guard, not a behavioural reproduction: the suite has no DOM
 * environment, and stripe-checkout.js exports nothing and registers window
 * listeners at import time. It pins: the overlays really are full-screen
 * occluders; confirm is watched with a body MutationObserver; loaders are not
 * dropped until a challenge-like iframe is added; they come back when confirm
 * resolves.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources');

function read(relative) {
    return fs.readFileSync(path.join(ROOT, relative), 'utf8');
}

function stripeCheckoutSource() {
    return read('public/payment-methods/stripe-checkout.js');
}

/** Body of the `stripeNextActionHandler = (e) => { ... }` assignment. */
function nextActionHandlerSource() {
    const source = stripeCheckoutSource();
    const start = source.indexOf('stripeNextActionHandler = (e) =>');
    expect(start, 'stripeNextActionHandler assignment not found — locate it by symbol and re-pin').toBeGreaterThan(-1);

    // Runs to the end of the enclosing `paymentElement.on('ready')` callback.
    const end = source.indexOf('const handleRequireAction', start);
    expect(end, 'handleRequireAction not found — handler bounds moved').toBeGreaterThan(start);

    return source.slice(start, end);
}

function stripComments(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\/\/[^\n]*/g, '');
}

describe('stripe onsite 3DS challenge visibility', () => {
    it('both checkout loaders are full-viewport opaque occluders', () => {
        const checkout = read('public/checkout/style/checkout.scss');
        const orderProcessing = checkout.slice(checkout.indexOf('.fct-order-processing {'));
        expect(orderProcessing).toMatch(/@apply fixed[^;]*w-full h-full z-\[999999\]/);
        expect(orderProcessing).toMatch(/background:\s*rgba\(0,\s*0,\s*0,\s*0\.7\)/);

        const customPage = read('public/payments/custom-payment-page.scss');
        const fctLoader = customPage.slice(customPage.indexOf('.fct-loader{'), customPage.indexOf('.fct-loader.active{'));
        expect(fctLoader).toMatch(/position:\s*fixed/);
        expect(fctLoader).toMatch(/z-index:\s*999999/);
        expect(fctLoader).toMatch(/background:\s*rgba\(0,\s*0,\s*0,\s*0\.7\)/);
    });

    it('does not take both loaders down unconditionally before confirming', () => {
        const handler = nextActionHandlerSource();
        const confirmAt = handler.indexOf('confirmIntent(confirmData)');
        expect(confirmAt, 'confirmIntent(confirmData) call not found').toBeGreaterThan(-1);

        const beforeConfirm = stripComments(handler.slice(0, confirmAt)).trimEnd();

        // The old flash: hideLoader + remove('active') as the last statements
        // before confirmIntent. Challenge hide still happens, but only inside
        // the observer helper — not as a pre-confirm pair.
        expect(
            beforeConfirm,
            'unconditional hideLoader + remove(active) immediately before confirmIntent flashes non-3DS checkout'
        ).not.toMatch(/hideLoader\(\);\s*loaderElement\?\.classList\?\.remove\('active'\);\s*$/);
    });

    it('watches document.body for a challenge iframe before confirming', () => {
        const source = stripeCheckoutSource();
        const handler = nextActionHandlerSource();
        const confirmAt = handler.indexOf('confirmIntent(confirmData)');
        const beforeConfirm = handler.slice(0, confirmAt);

        expect(source, 'challenge watch must be a MutationObserver').toMatch(/new MutationObserver/);
        expect(source, 'observer must watch document.body').toMatch(/observe\(\s*document\.body/);
        expect(
            beforeConfirm,
            'watch must start before confirmIntent so an inline 3DS iframe is seen while confirm is pending'
        ).toMatch(/watchStripeChallengeAndHideLoaders\(/);
    });

    it('ignores Payment Element iframes and the overlay markup when deciding a challenge appeared', () => {
        const source = stripeCheckoutSource();
        const helperStart = source.indexOf('function nodeLooksLikeStripeChallenge');
        expect(helperStart, 'nodeLooksLikeStripeChallenge not found — locate it by symbol and re-pin').toBeGreaterThan(-1);

        const helperEnd = source.indexOf('function hideLoadersForStripeChallenge', helperStart);
        expect(helperEnd, 'hideLoadersForStripeChallenge not found — helper bounds moved').toBeGreaterThan(helperStart);

        const helper = source.slice(helperStart, helperEnd);
        expect(helper).toMatch(/fluent-cart-checkout_embed_payment_container_stripe/);
        expect(helper).toMatch(/fct-order-processing/);
        expect(helper).toMatch(/fct-loader/);
        expect(helper).toMatch(/IFRAME/);
    });

    it('takes both loaders down when the challenge watch matches', () => {
        const source = stripeCheckoutSource();
        const helperStart = source.indexOf('function hideLoadersForStripeChallenge');
        expect(helperStart, 'hideLoadersForStripeChallenge not found').toBeGreaterThan(-1);

        const helperEnd = source.indexOf('function watchStripeChallengeAndHideLoaders', helperStart);
        expect(helperEnd, 'watchStripeChallengeAndHideLoaders not found — helper bounds moved').toBeGreaterThan(helperStart);

        const helper = source.slice(helperStart, helperEnd);
        expect(helper).toMatch(/hideLoader\(\)/);
        expect(helper).toMatch(/classList\?\.remove\('active'\)/);
    });

    it('raises both loaders again once the confirm resolves', () => {
        const handler = nextActionHandlerSource();
        const confirmAt = handler.indexOf('confirmIntent(confirmData)');
        const afterConfirm = handler.slice(confirmAt);

        // Ordered: the loader must be back up before the status text that assumes it.
        const reAdd = afterConfirm.indexOf("loaderElement?.classList?.add('active')");
        const confirming = afterConfirm.indexOf("changeLoaderStatus('confirming')");

        expect(reAdd, '.fct-loader is never restored after the confirm resolves').toBeGreaterThan(-1);
        expect(confirming, "changeLoaderStatus('confirming') not found after the confirm").toBeGreaterThan(-1);
        expect(reAdd).toBeLessThan(confirming);
    });

    it('stops the challenge watch when confirm settles, including submit failure', () => {
        const handler = nextActionHandlerSource();
        const confirmAt = handler.indexOf('confirmIntent(confirmData)');
        const afterConfirm = handler.slice(confirmAt);
        const catchAt = afterConfirm.indexOf('.catch(');

        expect(
            afterConfirm.indexOf('stopStripeChallengeWatch()'),
            'confirm then() must disconnect the observer before restoring loaders'
        ).toBeGreaterThan(-1);
        expect(afterConfirm.indexOf('stopStripeChallengeWatch()'))
            .toBeLessThan(afterConfirm.indexOf("changeLoaderStatus('confirming')"));

        expect(catchAt, 'elements.submit() catch not found').toBeGreaterThan(-1);
        expect(
            afterConfirm.slice(catchAt),
            'submit/confirm reject must disconnect a leftover observer'
        ).toMatch(/stopStripeChallengeWatch\(\)/);
    });

    it('never dereferences loaderElement without a guard', () => {
        const handler = nextActionHandlerSource();
        const source = stripeCheckoutSource();
        // `.fct-loader` only exists on the custom payment page; every other checkout
        // gets null here, and an unguarded call throws inside the confirm promise
        // chain where nothing reports it.
        expect(handler).not.toMatch(/loaderElement\.classList/);
        expect(source).not.toMatch(/loaderElement\.classList[^?]/);
    });
});

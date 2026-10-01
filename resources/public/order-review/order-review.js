/**
 * The public order-review page (?fluent-cart=order-review).
 *
 * ReviewForm.js owns the form and closes the overlay on success. On a product
 * page the review list underneath then reloads, so the visitor sees their
 * review appear. This page has no list — without this, the overlay would close
 * onto a row still offering a "Write a Review" button that the duplicate guard
 * is about to refuse.
 *
 * Every string is pre-rendered by OrderReviewRenderer and hidden, so nothing
 * here needs translating.
 */

// A row is a (product, item) slot: a variable product has one row per item
// bought, so the row that flips is the one for the exact item just reviewed.
// 0 is the product as a whole.
const pendingSelector = (postId, itemId) =>
    `[data-order-review-pending][data-post-id="${CSS.escape(String(postId))}"][data-item-id="${CSS.escape(String(itemId || 0))}"]`;

// Whether the row's own overlay is still on screen. ReviewForm.js keeps the
// closed overlay in the DOM with display:none, so visibility is the signal.
const overlayIsOpen = (pending) => {
    const overlay = pending.querySelector('[data-review-drawer]');
    return !!overlay && overlay.style.display !== 'none';
};

// Flip a row to its reviewed state and put focus on the confirmation, which
// is a status region so assistive tech announces it. Called only once the
// row's overlay has finished closing — hiding the wrapper earlier would pull
// the open panel off screen before its success message and focus handling
// completed.
const swapRow = (pending) => {
    const row = pending.closest('.fct-order-review-item-body');
    pending.hidden = true;
    delete pending.dataset.orderReviewAwaitingClose;

    const done = row && row.querySelector('[data-order-review-done]');
    if (done) {
        done.hidden = false;
        done.focus();
    }

    // Stop claiming the original count after an intermediate submission.
    const subtitle = document.querySelector('[data-order-review-subtitle]');
    if (subtitle && subtitle.dataset.afterSubmission) {
        subtitle.textContent = subtitle.dataset.afterSubmission;
    }
    const stillPending = document.querySelectorAll('[data-order-review-pending]:not([hidden])');
    if (stillPending.length === 0) {
        const subtitle = document.querySelector('[data-order-review-subtitle]');
        const allDone = document.querySelector('[data-order-review-all-done]');

        if (subtitle) {
            subtitle.hidden = true;
        }
        if (allDone) {
            allDone.hidden = false;
        }
    }
};

document.addEventListener('fct-review-submitted', (event) => {
    const postId = event.detail && event.detail.postId;
    const itemId = (event.detail && event.detail.itemId) || 0;

    if (!postId) {
        return;
    }

    const pending = document.querySelector(pendingSelector(postId, itemId));

    if (!pending) {
        return;
    }

    // With the overlay still up, the form is showing its success message and
    // will close itself shortly; the row swaps when that finishes. Without
    // one (the overlay was already closed when the response landed) there is
    // nothing to wait for.
    if (overlayIsOpen(pending)) {
        pending.dataset.orderReviewAwaitingClose = '1';
        return;
    }

    swapRow(pending);
});

document.addEventListener('fct-review-overlay-closed', (event) => {
    const postId = event.detail && event.detail.postId;
    const itemId = (event.detail && event.detail.itemId) || 0;

    if (!postId) {
        return;
    }

    const pending = document.querySelector(`${pendingSelector(postId, itemId)}[data-order-review-awaiting-close]`);

    if (pending) {
        swapRow(pending);
    }
});

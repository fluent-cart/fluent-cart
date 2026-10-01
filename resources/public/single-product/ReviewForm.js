// Every form that rendered, by the id its own trigger names. A page can hold
// two Write a Review blocks for the same product configured differently — one
// a modal, one a drawer — and each button has to open its own.
const formControllers = new Map();

// Fallback for triggers that name no form: an affordance rendered elsewhere,
// or by another plugin, that only knows the product. One controller per
// product answers those, so they never stack two open overlays on one click.
const primaryControllers = new Map();

// The overlay currently standing open, if any. Routing each trigger to its own
// form means a page with two of these blocks can now open a second overlay on
// top of the first — the old shared-controller behaviour made that impossible
// by accident. One at a time is a property worth keeping on purpose: two
// stacked dialogs trap focus in the wrong one and leave the reviewer typing
// into a form they cannot see.
let openOverlayController = null;

export default class FluentCartReviewForm {
    #container;
    #postId;
    // The variation this form reviews, 0 for the product. Seeded once from
    // the renderer's data-item-id and sent with every submission as
    // item_id — the value lives in this state, never in a form field.
    #itemId = 0;
    #restUrl;
    #restNonce;
    #starEnabled = true;
    #starRequired = true;
    #currentStep = 1;
    #totalSteps = 2;
    #isEditMode = false;
    #reviewId = null;
    #triggerElement = null;
    #abortController = new AbortController();
    // The document-level click and keydown handlers hang on this one, apart
    // from the fetch above: destroy() drops the handlers but lets a
    // submission already on its way settle, so a review the server did
    // create still reaches the page as a success.
    #documentListeners = new AbortController();
    #isSubmitting = false;
    // Bumped on every submission AND on drawer close. A submission whose
    // token no longer matches when its response arrives is stale: the user
    // closed (and possibly reopened) the drawer mid-flight, so the response
    // must not reset the form, show messages, or auto-close — that would
    // wipe a newly typed second draft.
    #submitToken = 0;
    // The second the success line is shown before the overlay closes on
    // its own; cleared when the visitor closes it first.
    #closeTimeout = null;
    // Tells the list about a save whose auto-close was cut short by hand.
    #pendingListNotify = null;
    // Re-enables the submit control once the overlay has closed on a save.
    #releaseSubmit = null;
    // The 300ms transition-out of the overlay, cleared on reopen so a
    // drawer reopened mid-transition is not hidden by its own close.
    #hideTimeout = null;
    #detailsStep = 2;
    // Two independent axes, mirroring the renderer. Layout paces the fields:
    // 'inline' means one step, so the whole form validates together.
    // Container is the chrome: with a drawer or modal a success closes the
    // overlay, without one the card itself has to become the confirmation.
    #isInline = false;
    #hasOverlay = true;
    #formId = null;
    #submitted = false;

    constructor(container) {
        this.#container = container;
        this.#postId = container.getAttribute('data-post-id');
        this.#itemId = parseInt(container.getAttribute('data-item-id'), 10) || 0;
        this.#restUrl = container.getAttribute('data-rest-url');
        this.#restNonce = container.getAttribute('data-rest-nonce');
        this.#starEnabled = container.getAttribute('data-star-enabled') !== '0';
        this.#starRequired = container.getAttribute('data-star-required') !== '0';
        this.#totalSteps = parseInt(container.getAttribute('data-total-steps')) || 2;
        this.#isEditMode = container.getAttribute('data-edit-mode') === '1';
        this.#reviewId = container.getAttribute('data-review-id');
        this.#formId = container.getAttribute('data-form-id');
        this.#isInline = container.getAttribute('data-layout') === 'inline';
        this.#hasOverlay = container.getAttribute('data-container') !== 'none';
        this.#detailsStep = (this.#isInline || !this.#starEnabled) ? 1 : 2;
    }

    init() {
        this.#initStarSelector();
        // Drawer and modal share the overlay contract, so one initialiser
        // serves both; it also self-guards when no overlay was rendered.
        this.#initDrawer();
        this.#initStepper();
        this.#initCharCounters();
        this.#updateFooterButtons();

        if (this.#isEditMode) {
            this.#prefillForm();
        }
    }

    // ─── Drawer open/close ───────────────────────────────────

    #initDrawer() {
        const drawer = this.#container.querySelector('[data-review-drawer]');
        if (!drawer) return;

        if (this.#formId) {
            formControllers.set(this.#formId, this);
        }

        if (this.#postId && !primaryControllers.has(this.#postId)) {
            primaryControllers.set(this.#postId, this);
        }

        const closeBtn = drawer.querySelector('[data-close-review-drawer]');
        const overlay = drawer; // the overlay IS the drawer wrapper

        // Delegate to document — open buttons may be rendered after init
        // (e.g. inline edit affordance on the user's own review card).
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-open-review-drawer]');
            if (!trigger) return;
            const postId = trigger.getAttribute('data-post-id');
            if (postId && postId !== this.#postId) return;

            const target = trigger.getAttribute('data-review-form-target');

            if (target) {
                if (target === this.#formId) {
                    this.#openDrawer();
                    return;
                }
                // A trigger whose form never initialised — the block that
                // rendered it printed no form, or its form was removed. Falls
                // back to the product's primary controller rather than being
                // a button that does nothing. Every controller registers on
                // DOM ready, so by click time this map is complete.
                if (!formControllers.has(target) && primaryControllers.get(this.#postId) === this) {
                    this.#openDrawer();
                }
                return;
            }

            if (primaryControllers.get(this.#postId) !== this) return;
            this.#openDrawer();
        }, { signal: this.#documentListeners.signal });

        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.#closeDrawer());
        }

        // Close on overlay click (not on drawer panel itself)
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.#closeDrawer();
            }
        });

        // Keyboard handling: Escape to close, Tab trap (removable via AbortController)
        document.addEventListener('keydown', (e) => {
            if (drawer.style.display === 'none') return;
            // An overlay on its way out is still on screen for 300ms. Without
            // this its Escape handler and its Tab trap would both fight the
            // overlay that replaced it.
            if (openOverlayController !== this) return;

            if (e.key === 'Escape') {
                this.#closeDrawer();
            } else if (e.key === 'Tab') {
                const panel = drawer.querySelector('[data-review-drawer-panel]');
                if (!panel) return;
                const focusable = panel.querySelectorAll(
                    'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }, { signal: this.#documentListeners.signal });
    }

    /**
     * Open the overlay from outside — a page that mounted the form itself
     * (the My Reviews page) has no trigger button inside it to click.
     */
    open() {
        this.#openDrawer();
    }

    /**
     * Take the controller out of service, for a form whose markup is about
     * to leave the page (the My Reviews page unmounting). Everything the
     * open state holds outside the markup is released at once: the scroll
     * lock, the document listeners, the timers and the page-level
     * registrations. Nothing here waits for a transition — the markup is
     * going regardless.
     *
     * A submission already on its way is not cut off. The server may well
     * create the review; when its answer comes, the stale-response path
     * still announces the success, so the page can take the product off
     * its to-review list instead of offering it again.
     */
    destroy() {
        clearTimeout(this.#hideTimeout);
        this.#hideTimeout = null;
        clearTimeout(this.#closeTimeout);
        this.#closeTimeout = null;
        // A response still in flight must not touch a form that is gone —
        // but it does still get to say the review was saved.
        this.#submitToken++;
        this.#isSubmitting = false;
        this.#documentListeners.abort();

        if (openOverlayController === this) {
            openOverlayController = null;
            document.body.style.overflow = '';
        }
        if (formControllers.get(this.#formId) === this) {
            formControllers.delete(this.#formId);
        }
        if (primaryControllers.get(this.#postId) === this) {
            primaryControllers.delete(this.#postId);
        }

        const drawer = this.#container.querySelector('[data-review-drawer]');
        if (drawer) {
            drawer.classList.remove('fct-drawer-open');
            drawer.style.display = 'none';
        }
        this.#triggerElement = null;
    }

    #openDrawer() {
        if (this.#submitted) return;
        const drawer = this.#container.querySelector('[data-review-drawer]');
        if (!drawer) return;

        // Private methods are reachable on any instance from inside the class,
        // so the outgoing overlay closes through its own controller and keeps
        // its handling of a submission still in flight. It is told it is being
        // replaced: the page scroll lock and the focus now belong to this
        // overlay, and its teardown runs 300ms from now — after this one has
        // taken both.
        if (openOverlayController && openOverlayController !== this) {
            openOverlayController.#closeDrawer({ replaced: true });
        }
        openOverlayController = this;
        // Reopened before the last close finished hiding it (rapid reopen,
        // or A → B → A within the transition): that close must not land now,
        // or it would hide this overlay and, unless it was a hand-over,
        // release the scroll lock and pull focus back to the trigger.
        if (this.#hideTimeout) {
            clearTimeout(this.#hideTimeout);
            this.#hideTimeout = null;
        }
        // Reset to step 1 on every open so user doesn't land on a stale step
        if (!this.#isEditMode) {
            this.#goToStep(1);
        }
        this.#triggerElement = document.activeElement;
        drawer.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            drawer.classList.add('fct-drawer-open');
            const panel = drawer.querySelector('[data-review-drawer-panel]');
            if (panel) panel.focus();
        });
    }

    #closeDrawer({ replaced = false } = {}) {
        const drawer = this.#container.querySelector('[data-review-drawer]');
        if (!drawer) return;

        if (openOverlayController === this) {
            openOverlayController = null;
        }
        // Closing invalidates any in-flight submission's completion handlers
        // (see #submitToken) — its response may no longer touch the form.
        this.#submitToken++;
        if (this.#closeTimeout) {
            clearTimeout(this.#closeTimeout);
            this.#closeTimeout = null;
        }
        if (this.#pendingListNotify) {
            const notify = this.#pendingListNotify;
            this.#pendingListNotify = null;
            notify();
        }
        if (this.#releaseSubmit) {
            const release = this.#releaseSubmit;
            this.#releaseSubmit = null;
            release();
        }
        // Whatever the panel was saying goes with it; the next open starts
        // clean.
        const messageEl = this.#container.querySelector('[data-review-form-message]');
        if (messageEl) messageEl.style.display = 'none';
        drawer.classList.remove('fct-drawer-open');
        if (this.#hideTimeout) {
            clearTimeout(this.#hideTimeout);
        }
        this.#hideTimeout = setTimeout(() => {
            this.#hideTimeout = null;
            drawer.style.display = 'none';

            // Handing over rather than closing down: releasing the scroll lock
            // would let the page scroll behind the overlay that replaced this
            // one, and restoring focus would pull the reviewer out of it.
            if (replaced) {
                this.#triggerElement = null;
                return;
            }

            document.body.style.overflow = '';
            if (this.#triggerElement) {
                this.#triggerElement.focus();
                this.#triggerElement = null;
            }

            // The overlay is gone and focus is back where it started: a page
            // that swaps the trigger's row on success (the order-review page)
            // waits for this rather than pulling the panel away mid-close.
            document.dispatchEvent(new CustomEvent('fct-review-overlay-closed', {
                detail: { postId: this.#postId, itemId: this.#itemId }
            }));
        }, 300);
    }

    // ─── Multi-step wizard ───────────────────────────────────

    #initStepper() {
        const nextBtn = this.#container.querySelector('[data-review-next]');
        const backBtn = this.#container.querySelector('[data-review-back]');
        const submitBtn = this.#container.querySelector('[data-review-submit]');

        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.#goToStep(this.#currentStep + 1));
        }
        if (backBtn) {
            backBtn.addEventListener('click', () => this.#goToStep(this.#currentStep - 1));
        }
        if (submitBtn) {
            submitBtn.addEventListener('click', () => this.#submitReview());
        }
    }

    #goToStep(step) {
        // Validate current step before advancing
        if (step > this.#currentStep && !this.#validateStep(this.#currentStep)) {
            return;
        }

        if (step < 1 || step > this.#totalSteps) return;

        // Hide current step, show target step
        const currentEl = this.#container.querySelector(`[data-review-step="${this.#currentStep}"]`);
        const targetEl = this.#container.querySelector(`[data-review-step="${step}"]`);
        if (currentEl) currentEl.style.display = 'none';
        if (targetEl) targetEl.style.display = '';

        this.#currentStep = step;
        this.#updateStepperUI();
        this.#updateFooterButtons();

        // Hide any visible error message when changing steps
        const messageEl = this.#container.querySelector('[data-review-form-message]');
        if (messageEl) messageEl.style.display = 'none';
    }

    #validateStep(step) {
        const trans = window.fluentcart_review_vars?.trans || {};
        const messageEl = this.#container.querySelector('[data-review-form-message]');

        if (this.#starEnabled && step === 1) {
            const rating = this.#container.querySelector('[data-review-rating]');
            if (this.#starRequired && (!rating || !rating.value)) {
                this.#showMessage(messageEl, trans.rating_required || 'Please select a rating', 'error');
                return false;
            }
        }

        if (step === this.#detailsStep) {
            const form = this.#container.querySelector('[data-review-form]');
            const content = form?.querySelector('textarea[name="content"]');
            if (!content || !content.value.trim()) {
                this.#showMessage(messageEl, trans.content_required || 'Please write a review', 'error');
                return false;
            }

            const nameField = form?.querySelector('input[name="reviewer_name"]:not([type="hidden"])');
            if (nameField && !nameField.value.trim()) {
                this.#showMessage(messageEl, trans.name_required || 'Please enter your name', 'error');
                return false;
            }

            const emailField = form?.querySelector('input[name="reviewer_email"]:not([type="hidden"])');
            if (emailField && !emailField.value.trim()) {
                this.#showMessage(messageEl, trans.email_required || 'Please enter your email', 'error');
                return false;
            }
        }

        return true;
    }

    #updateStepperUI() {
        const indicators = this.#container.querySelectorAll('[data-step-indicator]');
        indicators.forEach(ind => {
            const num = parseInt(ind.getAttribute('data-step-indicator'));
            ind.classList.toggle('active', num === this.#currentStep);
            ind.classList.toggle('completed', num < this.#currentStep);
        });
    }

    #updateFooterButtons() {
        const nextBtn = this.#container.querySelector('[data-review-next]');
        const backBtn = this.#container.querySelector('[data-review-back]');
        const submitBtn = this.#container.querySelector('[data-review-submit]');
        const stepInfo = this.#container.querySelector('[data-review-step-info]');

        const isLast = this.#currentStep === this.#totalSteps;
        const isFirst = this.#currentStep === 1;

        if (backBtn) backBtn.style.display = isFirst ? 'none' : '';
        if (nextBtn) nextBtn.style.display = isLast ? 'none' : '';
        if (submitBtn) submitBtn.style.display = isLast ? '' : 'none';

        if (stepInfo) {
            if (this.#totalSteps <= 1) {
                stepInfo.style.display = 'none';
            } else {
                stepInfo.style.display = '';
                const trans = window.fluentcart_review_vars?.trans || {};
                const stepLabels = {};
                let stepNum = 1;
                if (this.#starEnabled) {
                    stepLabels[stepNum++] = trans.rating_step_hint || 'Rate this product';
                }
                stepLabels[stepNum++] = trans.details_step_hint || 'Tell us more';
                if (this.#totalSteps >= stepNum) {
                    stepLabels[stepNum] = trans.photos_step_hint || 'Photos are optional';
                }
                const label = stepLabels[this.#currentStep] || '';
                const stepText = (trans.step_x_of_y || 'Step %1$s of %2$s')
                    .replace('%1$s', this.#currentStep)
                    .replace('%2$s', this.#totalSteps);
                stepInfo.textContent = stepText + (label ? ` \u2014 ${label}` : '');
            }
        }
    }

    // ─── Star selector ───────────────────────────────────────

    #initStarSelector() {
        const selector = this.#container.querySelector('[data-star-selector]');
        if (!selector) return;

        const stars = selector.querySelectorAll('[data-star-value]');
        const ratingInput = this.#container.querySelector('[data-review-rating]');

        // 0 clears: no star selected, the hidden field empty again.
        const selectStar = (value) => {
            ratingInput.value = value > 0 ? value : '';
            stars.forEach(s => {
                const v = parseInt(s.dataset.starValue);
                s.classList.toggle('selected', value > 0 && v <= value);
                s.setAttribute('aria-checked', v === value ? 'true' : 'false');
            });
        };

        stars.forEach(star => {
            // Clicking the star that is already the rating takes it back —
            // the way a rating can be undone on Google — so a change of
            // mind does not need a page reload.
            star.addEventListener('click', () => {
                const value = parseInt(star.dataset.starValue);
                const cleared = value === parseInt(ratingInput.value);
                selectStar(cleared ? 0 : value);
                // The pointer is still on the star, so the hover fill would
                // keep it looking chosen until the mouse moves on.
                if (cleared) {
                    stars.forEach(s => s.classList.remove('hover'));
                }
            });

            star.addEventListener('keydown', (e) => {
                const current = parseInt(star.dataset.starValue);
                let next = null;
                if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                    next = Math.min(current + 1, 5);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                    next = Math.max(current - 1, 1);
                }
                if (next !== null) {
                    e.preventDefault();
                    const nextStar = selector.querySelector(`[data-star-value="${next}"]`);
                    if (nextStar) {
                        nextStar.focus();
                        selectStar(next);
                    }
                }
            });

            star.addEventListener('mouseenter', () => {
                const value = parseInt(star.dataset.starValue);
                stars.forEach(s => {
                    s.classList.toggle('hover', parseInt(s.dataset.starValue) <= value);
                });
            });

            star.addEventListener('mouseleave', () => {
                stars.forEach(s => s.classList.remove('hover'));
            });
        });
    }

    // ─── Prefill for edit mode ─────────────────────────────

    #prefillForm() {
        const rating = parseInt(this.#container.getAttribute('data-existing-rating')) || 0;
        const title = this.#container.getAttribute('data-existing-title') || '';
        const content = this.#container.getAttribute('data-existing-content') || '';

        // Pre-select stars
        if (rating > 0) {
            const ratingInput = this.#container.querySelector('[data-review-rating]');
            const stars = this.#container.querySelectorAll('[data-star-value]');
            if (ratingInput) ratingInput.value = rating;
            stars.forEach(s => {
                const v = parseInt(s.dataset.starValue);
                s.classList.toggle('selected', v <= rating);
                s.setAttribute('aria-checked', v === rating ? 'true' : 'false');
            });
        }

        // Pre-fill text fields
        const titleInput = this.#container.querySelector('input[name="title"]');
        if (titleInput) titleInput.value = title;

        const contentInput = this.#container.querySelector('textarea[name="content"]');
        if (contentInput) contentInput.value = content;

        // Update char counters
        this.#container.querySelectorAll('[data-char-count]').forEach(counter => {
            const inputId = counter.getAttribute('data-char-count');
            const input = this.#container.querySelector('#' + inputId);
            if (!input) return;
            const max = parseInt(input.getAttribute('maxlength')) || 0;
            counter.textContent = `${input.value.length} / ${max}`;
        });

        // Update drawer title
        const drawerTitle = this.#container.querySelector('[data-review-drawer-title]');
        if (drawerTitle) {
            drawerTitle.textContent = window.fluentcart_review_vars?.trans?.edit_review || 'Edit your review';
        }
    }

    // ─── Character counters ──────────────────────────────────

    #initCharCounters() {
        const counters = this.#container.querySelectorAll('[data-char-count]');
        counters.forEach(counter => {
            const inputId = counter.getAttribute('data-char-count');
            const input = this.#container.querySelector('#' + inputId);
            if (!input) return;

            const max = parseInt(input.getAttribute('maxlength')) || 0;
            const update = () => {
                counter.textContent = `${input.value.length} / ${max}`;
            };
            input.addEventListener('input', update);
        });
    }

    // ─── Submit ──────────────────────────────────────────────

    #submitReview() {
        // Prevent double-submit
        if (this.#isSubmitting || this.#submitted) return;

        // Validate current step first
        if (!this.#validateStep(this.#currentStep)) return;

        const form = this.#container.querySelector('[data-review-form]');
        const submitBtn = this.#container.querySelector('[data-review-submit]');
        const messageEl = this.#container.querySelector('[data-review-form-message]');
        if (!form) return;

        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2);
                if (!Array.isArray(data[cleanKey])) data[cleanKey] = [];
                data[cleanKey].push(value);
            } else {
                data[key] = value;
            }
        });

        // Always sent, from state rather than a field: 0 means the product,
        // anything else the order line this form was rendered for. The
        // server only honours a non-zero value behind an order grant.
        data.item_id = this.#itemId;

        // Final validation
        if (this.#starEnabled && this.#starRequired && !data.rating) {
            this.#goToStep(1);
            this.#showMessage(messageEl, window.fluentcart_review_vars?.trans?.rating_required || 'Please select a rating', 'error');
            return;
        }
        if (!data.content || !data.content.trim()) {
            this.#goToStep(this.#detailsStep);
            this.#showMessage(messageEl, window.fluentcart_review_vars?.trans?.content_required || 'Please write a review', 'error');
            return;
        }

        this.#isSubmitting = true;
        const submitToken = ++this.#submitToken;
        submitBtn.disabled = true;
        const originalText = submitBtn.textContent;
        submitBtn.textContent = window.fluentcart_review_vars?.trans?.submitting || 'Submitting...';

        const url = this.#isEditMode
            ? `${this.#restUrl}/public/reviews/${this.#postId}/${this.#reviewId}`
            : `${this.#restUrl}/public/reviews/${this.#postId}`;

        // Multipart, not JSON: the photos travel with the review in this one
        // request, the way the admin form sends them, so nothing is uploaded
        // before the review exists and nothing is left pending if it never
        // does. The fields are re-added from `data` so array keys keep the
        // shape the server reads; anything that extends the form (Pro's
        // photo step) appends its files here.
        const payload = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((entry) => payload.append(`${key}[]`, entry));
            } else {
                payload.append(key, value);
            }
        });
        form.dispatchEvent(new CustomEvent('fct-review-form-before-submit', {
            detail: { formData: payload, data }
        }));

        // PHP only parses multipart bodies on POST, so an edit is posted with
        // the method override WordPress's REST server honours.
        const headers = { 'X-WP-Nonce': this.#restNonce };
        if (this.#isEditMode) {
            headers['X-HTTP-Method-Override'] = 'PUT';
        }

        fetch(url, {
            method: 'POST',
            headers,
            body: payload,
            signal: this.#abortController.signal,
        })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => { throw err; });
                }
                return res.json();
            })
            .then(response => {
                // Inside an overlay the lock is held through the second the
                // success line is shown: a second click there would send the
                // same review again while the first save still owned the
                // close. #closeDrawer() releases it. Everywhere else, and
                // for a stale response, it is released here.
                const release = () => {
                    this.#isSubmitting = false;
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                };
                if (!this.#hasOverlay || submitToken !== this.#submitToken) {
                    release();
                } else {
                    this.#releaseSubmit = release;
                }

                if (response.can_edit === false) {
                    this.#submitted = true;
                    document.querySelectorAll(`[data-open-review-drawer][data-post-id="${this.#postId}"]`).forEach((trigger) => {
                        const target = trigger.getAttribute('data-review-form-target');
                        if (target && target !== this.#formId) return;
                        if ((parseInt(trigger.getAttribute('data-item-id'), 10) || 0) !== this.#itemId) return;
                        trigger.hidden = true;
                        trigger.style.display = 'none';
                    });
                }

                if (submitToken !== this.#submitToken) {
                    // Drawer was closed (and possibly reopened with a new
                    // draft) while this request was in flight. The review
                    // was still created server-side, so refresh the list —
                    // but leave the form, messages, and drawer alone.
                    document.dispatchEvent(new CustomEvent('fct-review-submitted', {
                        detail: { postId: this.#postId, itemId: this.#itemId, response }
                    }));
                    return;
                }

                // With no overlay the success line replaces the form and has
                // to stay; inside one it sits above a panel about to close.
                this.#showMessage(messageEl, response.message || 'Review submitted successfully!', 'success', !this.#hasOverlay);

                const wasEditMode = this.#isEditMode;

                // The review now exists, so this form is its edit form from
                // here on: the values stay, the next open edits, and every
                // trigger for it reads "Edit your review" — without the page
                // reload that used to be the only way to get there.
                if (!wasEditMode && response.can_edit === true && response.review && response.review.id) {
                    this.#becomeEditForm(response.review, data);
                }
                form.dispatchEvent(new CustomEvent('fct-review-form-saved', {
                    detail: { review: response.review || null, media: response.media || [] }
                }));

                // Tells the list to reload. The response rides along for
                // anything that extended the request and needs the server's
                // report on its part of it (Pro's photos).
                const notifyList = () => {
                    document.dispatchEvent(new CustomEvent('fct-review-submitted', {
                        detail: { postId: this.#postId, itemId: this.#itemId, response }
                    }));
                };

                if (!this.#hasOverlay) {
                    if (!wasEditMode) {
                        this.#markStandaloneSubmitted();
                    }
                    notifyList();
                    return;
                }

                // The success line gets a second to be read, the overlay
                // closes, and only then does the list redraw — so the
                // visitor is never looking at a panel while the page moves
                // behind it. A close by hand before the second is up cancels
                // this and tells the list itself.
                this.#pendingListNotify = notifyList;
                this.#closeTimeout = setTimeout(() => {
                    this.#closeTimeout = null;
                    // #closeDrawer() tells the list, here as on a close by hand.
                    this.#closeDrawer();
                }, 1000);
            })
            .catch(error => {
                this.#isSubmitting = false;
                if (error.name === 'AbortError') return;
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;

                // Stale (drawer closed mid-flight): nothing succeeded and
                // there's no current context to show the error in.
                if (submitToken !== this.#submitToken) return;

                this.#showMessage(messageEl, error.message || 'Something went wrong', 'error');
            });
    }

    /**
     * Turn the blank form into the saved review's edit form, in place. The
     * container attributes are set so anything that reads them later sees
     * what a page reload would have rendered; the fields keep what was
     * just typed.
     */
    #becomeEditForm(review, data) {
        this.#isEditMode = true;
        this.#reviewId = String(review.id);

        this.#container.setAttribute('data-edit-mode', '1');
        this.#container.setAttribute('data-review-id', this.#reviewId);
        this.#container.setAttribute('data-existing-rating', String(data.rating || ''));
        this.#container.setAttribute('data-existing-title', String(data.title || ''));
        this.#container.setAttribute('data-existing-content', String(data.content || ''));

        const drawerTitle = this.#container.querySelector('[data-review-drawer-title]');
        if (drawerTitle) {
            drawerTitle.textContent = window.fluentcart_review_vars?.trans?.edit_review || 'Edit your review';
        }

        // The triggers that own this form, or name only its product and
        // item: both labels are in the markup, so this is a swap, not a
        // rewrite of text the block may have customised.
        document.querySelectorAll(`[data-open-review-drawer][data-post-id="${this.#postId}"]`).forEach((trigger) => {
            const target = trigger.getAttribute('data-review-form-target');
            if (target && target !== this.#formId) return;
            const itemId = parseInt(trigger.getAttribute('data-item-id'), 10) || 0;
            if (itemId !== this.#itemId) return;

            const add = trigger.querySelector('[data-cta-add]');
            const edit = trigger.querySelector('[data-cta-edit]');
            if (add) add.hidden = true;
            if (edit) edit.hidden = false;
        });
    }

    // Printed on the page there is no overlay to close, so the card itself
    // becomes the receipt: the fields go away and the success line stays.
    #markStandaloneSubmitted() {
        this.#container.classList.add('fct-review-form-submitted');
        const form = this.#container.querySelector('[data-review-form]');
        const footer = this.#container.querySelector('.fct-review-standalone-footer');
        const stepper = this.#container.querySelector('[data-review-stepper]');
        if (form) form.style.display = 'none';
        if (footer) footer.style.display = 'none';
        if (stepper) stepper.style.display = 'none';
    }

    #showMessage(el, message, type, persist = false) {
        if (!el) return;
        el.textContent = message;
        el.className = `fct-review-form-message fct-review-form-message-${type}`;
        el.style.display = 'block';

        if (type === 'success' && !persist) {
            setTimeout(() => {
                el.style.display = 'none';
            }, 5000);
        }
    }
}

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('[data-fluent-cart-review-form]');
    containers.forEach(container => {
        const reviewForm = new FluentCartReviewForm(container);
        reviewForm.init();
    });
});

// A form added after DOM ready — markup fetched and inserted by another
// script, the way the My Reviews page does — announces itself here and is
// taken over the same way. `open` asks for the overlay to open at once.
document.addEventListener('fct-review-form-mount', (event) => {
    const container = event.detail && event.detail.container;
    if (!container || !container.hasAttribute('data-fluent-cart-review-form')) {
        return;
    }
    const reviewForm = new FluentCartReviewForm(container);
    reviewForm.init();
    // Handed back to whoever mounted the form, so it can destroy() the
    // controller when it takes the markup away again.
    event.detail.controller = reviewForm;
    // Anything that extends a form (Pro's photo upload) bound itself to the
    // forms present at DOM ready; this one arrived later, so it is
    // announced for the same treatment.
    document.dispatchEvent(new CustomEvent('fct-review-form-mounted', {
        detail: { container }
    }));
    if (event.detail.open) {
        reviewForm.open();
    }
});

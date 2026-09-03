function getRatingLabels() {
    const trans = window.fluentcart_review_vars?.trans || {};
    return [
        '',
        trans.rating_poor || 'Poor',
        trans.rating_fair || 'Fair',
        trans.rating_good || 'Good',
        trans.rating_very_good || 'Very Good',
        trans.rating_excellent || 'Excellent',
    ];
}

// First controller with a drawer per product. Every trigger for a product
// has the same purpose — open that product's review drawer — so only one
// controller answers, and duplicate form blocks for the same product never
// stack two open drawers on a single click.
const primaryControllers = new Map();

export default class FluentCartReviewForm {
    #container;
    #postId;
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
    #isSubmitting = false;
    // Bumped on every submission AND on drawer close. A submission whose
    // token no longer matches when its response arrives is stale: the user
    // closed (and possibly reopened) the drawer mid-flight, so the response
    // must not reset the form, show messages, or auto-close — that would
    // wipe a newly typed second draft.
    #submitToken = 0;
    #closeTimeout = null;
    #detailsStep = 2;

    constructor(container) {
        this.#container = container;
        this.#postId = container.getAttribute('data-post-id');
        this.#restUrl = container.getAttribute('data-rest-url');
        this.#restNonce = container.getAttribute('data-rest-nonce');
        this.#starEnabled = container.getAttribute('data-star-enabled') !== '0';
        this.#starRequired = container.getAttribute('data-star-required') !== '0';
        this.#totalSteps = parseInt(container.getAttribute('data-total-steps')) || 2;
        this.#isEditMode = container.getAttribute('data-edit-mode') === '1';
        this.#reviewId = container.getAttribute('data-review-id');
        this.#detailsStep = this.#starEnabled ? 2 : 1;
    }

    init() {
        if (this.#container.getAttribute('data-api-available') === '0') {
            return;
        }
        this.#initStarSelector();
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
            if (primaryControllers.get(this.#postId) !== this) return;
            this.#openDrawer();
        }, { signal: this.#abortController.signal });

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
        }, { signal: this.#abortController.signal });
    }

    #openDrawer() {
        const drawer = this.#container.querySelector('[data-review-drawer]');
        if (!drawer) return;
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

    #closeDrawer() {
        const drawer = this.#container.querySelector('[data-review-drawer]');
        if (!drawer) return;
        // Closing invalidates any in-flight submission's completion handlers
        // (see #submitToken) — its response may no longer touch the form.
        this.#submitToken++;
        // Cancel any pending auto-close from a successful submission
        if (this.#closeTimeout) {
            clearTimeout(this.#closeTimeout);
            this.#closeTimeout = null;
        }
        drawer.classList.remove('fct-drawer-open');
        setTimeout(() => {
            drawer.style.display = 'none';
            document.body.style.overflow = '';
            if (this.#triggerElement) {
                this.#triggerElement.focus();
                this.#triggerElement = null;
            }
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
        const ratingLabel = this.#container.querySelector('[data-rating-label]');

        const selectStar = (value) => {
            ratingInput.value = value;
            stars.forEach(s => {
                const v = parseInt(s.dataset.starValue);
                s.classList.toggle('selected', v <= value);
                s.setAttribute('aria-checked', v === value ? 'true' : 'false');
            });
            if (ratingLabel) {
                ratingLabel.textContent = getRatingLabels()[value] || '';
            }
        };

        stars.forEach(star => {
            star.addEventListener('click', () => {
                selectStar(parseInt(star.dataset.starValue));
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
                if (ratingLabel) {
                    ratingLabel.textContent = getRatingLabels()[value] || '';
                }
            });

            star.addEventListener('mouseleave', () => {
                stars.forEach(s => s.classList.remove('hover'));
                // Restore selected label
                const selectedValue = parseInt(ratingInput.value) || 0;
                if (ratingLabel) {
                    ratingLabel.textContent = getRatingLabels()[selectedValue] || '';
                }
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
            const ratingLabel = this.#container.querySelector('[data-rating-label]');
            if (ratingInput) ratingInput.value = rating;
            stars.forEach(s => {
                const v = parseInt(s.dataset.starValue);
                s.classList.toggle('selected', v <= rating);
                s.setAttribute('aria-checked', v === rating ? 'true' : 'false');
            });
            if (ratingLabel) ratingLabel.textContent = getRatingLabels()[rating] || '';
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
        if (this.#isSubmitting) return;

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
        const method = this.#isEditMode ? 'PUT' : 'POST';

        fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.#restNonce,
            },
            body: JSON.stringify(data),
            signal: this.#abortController.signal,
        })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => { throw err; });
                }
                return res.json();
            })
            .then(response => {
                this.#isSubmitting = false;
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;

                if (submitToken !== this.#submitToken) {
                    // Drawer was closed (and possibly reopened with a new
                    // draft) while this request was in flight. The review
                    // was still created server-side, so refresh the list —
                    // but leave the form, messages, and drawer alone.
                    document.dispatchEvent(new CustomEvent('fct-review-submitted', {
                        detail: { postId: this.#postId }
                    }));
                    return;
                }

                this.#showMessage(messageEl, response.message || 'Review submitted successfully!', 'success');

                if (!this.#isEditMode) {
                    // Reset form for new submissions
                    form.reset();
                    const stars = this.#container.querySelectorAll('[data-star-value]');
                    stars.forEach(s => {
                        s.classList.remove('selected');
                        s.setAttribute('aria-checked', 'false');
                    });
                    const ratingLabel = this.#container.querySelector('[data-rating-label]');
                    if (ratingLabel) ratingLabel.textContent = '';

                    // Reset char counters
                    this.#container.querySelectorAll('[data-char-count]').forEach(counter => {
                        const inputId = counter.getAttribute('data-char-count');
                        const max = parseInt(this.#container.querySelector('#' + inputId)?.getAttribute('maxlength')) || 0;
                        counter.textContent = `0 / ${max}`;
                    });

                    // Reset Pro upload state
                    form.dispatchEvent(new CustomEvent('fct-review-form-reset'));
                }

                // Notify reviews list to reload
                document.dispatchEvent(new CustomEvent('fct-review-submitted', {
                    detail: { postId: this.#postId }
                }));

                // Close drawer after short delay (clear any existing timeout first)
                if (this.#closeTimeout) clearTimeout(this.#closeTimeout);
                this.#closeTimeout = setTimeout(() => {
                    this.#closeTimeout = null;
                    this.#closeDrawer();
                    if (!this.#isEditMode) {
                        this.#goToStep(1);
                    }
                }, 1500);
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

    #showMessage(el, message, type) {
        if (!el) return;
        el.textContent = message;
        el.className = `fct-review-form-message fct-review-form-message-${type}`;
        el.style.display = 'block';

        if (type === 'success') {
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

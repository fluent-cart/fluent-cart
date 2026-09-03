/**
 * Cloudflare Turnstile Handler
 * Manages Turnstile widget rendering and token retrieval
 */
class TurnstileHandler {
    constructor(checkoutHandler = null) {
        this.checkoutHandler = checkoutHandler;
        this.pendingTokenPromise = null;
        this.pendingResolve = null;
        this.inVisibleMode = false;
        this.visibleModeActive = false;
        this.fadeTimer = null;
        this.autoRetryCount = 0;
        this.setupGlobalCallback();
        this.autoRenderWidget();
    }

    setupGlobalCallback() {
        window.fluentCartTurnstileHandlerInstance = this;
        window.fluentCartTurnstileCallback = function (token) {
            window.fluentCartTurnstileHandlerInstance?.handleToken(token);
        };
    }

    isEnabled() {
        return window.fluentcart_checkout_vars?.turnstile?.enabled;
    }

    autoRenderWidget() {
        if (!this.isEnabled()) {
            return;
        }

        const widget = document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
        const wrapper = document.querySelector('[data-fluent-cart-turnstile-widget]');

        if (!widget) {
            return;
        }

        if (!this.isEnabled() && wrapper?.getAttribute('data-turnstile-active') !== 'yes') {
            return;
        }

        if (typeof turnstile === 'undefined') {
            let attempts = 0;
            const interval = setInterval(() => {
                attempts++;
                if (typeof turnstile !== 'undefined') {
                    clearInterval(interval);
                    this.renderAndExecute(widget);
                } else if (attempts >= 50) {
                    clearInterval(interval);
                }
            }, 100);
            return;
        }

        this.renderAndExecute(widget);
    }

    /**
     * execution:'render' auto-fires immediately on render.
     * appearance:'execute' makes the widget visible during the challenge.
     * Chrome/good Safari: auto-verifies silently, widget disappears.
     * Safari ITP: auto-verify fails, checkbox stays visible — user clicks it
     * BEFORE touching any payment button. Pay click finds cached token = instant.
     */
    renderAndExecute(widget) {
        if (widget.getAttribute('data-widget-id')) {
            return;
        }

        const siteKey = widget.getAttribute('data-sitekey') || window.fluentcart_checkout_vars?.turnstile?.site_key;
        if (!siteKey) {
            return;
        }

        try {
            const renderedWidgetId = turnstile.render(widget, {
                sitekey: siteKey,
                execution: 'render',
                callback: this.handleToken.bind(this),
                'expired-callback': this.handleExpired.bind(this),
                'error-callback': this.handleError.bind(this),
                size: 'flexible',
                appearance: 'interaction-only',
                theme: 'auto'
            });
            if (renderedWidgetId) {
                widget.setAttribute('data-widget-id', renderedWidgetId);
            }
        } catch (error) {
            // Silent - widget may have been rendered by the footer script
        }
    }

    handleExpired() {
        window.fluentCartTurnstileToken = null;
    }

    handleError() {
        window.fluentCartTurnstileToken = null;

        // Cloudflare fires error-callback for transient issues too (network blips,
        // challenge timeouts), not only hard failures. Chrome hits these but
        // recovers on a silent retry — so don't reveal the manual checkbox on the
        // first error. Retry auto-verify a couple of times; only Safari ITP (which
        // never recovers) exhausts the retries and falls through to manual.
        this.autoRetryCount++;
        if (this.autoRetryCount <= 2) {
            const widget = document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
            const widgetId = widget && widget.getAttribute('data-widget-id');
            if (widgetId && typeof turnstile !== 'undefined') {
                // Defer out of the error-callback stack before resetting.
                setTimeout(() => {
                    try {
                        turnstile.reset(widgetId);
                    } catch (e) {
                    }
                }, 0);
                return;
            }
        }

        // Silent retries exhausted — genuine non-interactive failure. Reveal the
        // manual checkbox. Deferred: rendering a fresh widget synchronously inside
        // error-callback is unreliable (the new callback fails to attach).
        setTimeout(() => this.switchToVisibleMode(), 0);
    }

    /**
     * Re-render with appearance:'always' after auto-verify fails.
     * The checkbox becomes visible; user clicks it; handleToken() resolves the pending promise.
     */
    switchToVisibleMode() {
        if (this.visibleModeActive) {
            return;
        }
        const wrapper = document.querySelector('[data-fluent-cart-turnstile-widget]');
        if (!wrapper || typeof turnstile === 'undefined') {
            return;
        }

        const old = wrapper.querySelector('.cf-turnstile');
        const siteKey = (old && old.getAttribute('data-sitekey')) || window.fluentcart_checkout_vars?.turnstile?.site_key;
        if (!siteKey) {
            return;
        }

        this.inVisibleMode = true;
        this.visibleModeActive = true;

        // Destroy any existing widget completely and start from a clean element.
        // Re-rendering onto a stale/errored element leaves the manual-verify
        // callback unattached, so handleToken never fires and the widget never removes.
        if (old) {
            const oldId = old.getAttribute('data-widget-id');
            try {
                if (oldId) {
                    turnstile.remove(oldId);
                }
            } catch (e) {
            }
            old.remove();
        }

        const widget = document.createElement('div');
        widget.className = 'cf-turnstile';
        widget.setAttribute('data-sitekey', siteKey);
        wrapper.appendChild(widget);

        wrapper.style.opacity = '1';
        wrapper.style.transition = '';

        try {
            const newId = turnstile.render(widget, {
                sitekey: siteKey,
                execution: 'render',
                callback: this.handleToken.bind(this),
                'expired-callback': this.handleExpired.bind(this),
                'error-callback': () => {}, // prevent infinite loop on repeated failures
                size: 'flexible',
                appearance: 'always',
                theme: 'auto'
            });
            if (newId) {
                widget.setAttribute('data-widget-id', newId);
            }
        } catch (e) {
            this.visibleModeActive = false;
        }
    }

    reset() {
        if (!this.isEnabled()) {
            return;
        }

        if (this.fadeTimer) {
            clearTimeout(this.fadeTimer);
            this.fadeTimer = null;
            this.hideAndRemoveWidget();
        }

        if (this.pendingResolve) {
            const resolve = this.pendingResolve;
            this.pendingResolve = null;
            this.pendingTokenPromise = null;
            resolve(null);
        }

        this.pendingTokenPromise = null;
        this.pendingResolve = null;
        this.inVisibleMode = false;
        this.visibleModeActive = false;
        this.autoRetryCount = 0;
        window.fluentCartTurnstileToken = null;

        if (typeof turnstile === 'undefined') {
            return;
        }

        const widget = document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
        if (!widget) {
            return;
        }

        const widgetId = widget.getAttribute('data-widget-id');
        if (!widgetId) {
            return;
        }

        try {
            turnstile.reset(widgetId);
        } catch (e) {
        }
    }

    async handleCheckoutSecurityVerification(formData) {
        if (!this.isEnabled()) {
            return true;
        }

        const turnstileToken = await this.getToken();
        if (!turnstileToken) {
            if (this.checkoutHandler?.cleanupAfterProcessing) {
                this.checkoutHandler.cleanupAfterProcessing();
            }
            if (this.inVisibleMode) {
                new Toastify({
                    text: this.checkoutHandler?.translate?.("Please complete the security verification above, then try again.") || "Please complete the security verification above, then try again.",
                    className: "warning",
                    duration: 4000
                }).showToast();
                // Do not reset — keep checkbox visible so user can click it
            } else {
                new Toastify({
                    text: this.checkoutHandler?.translate?.("Security check failed. Please refresh the page and try again.") || "Security check failed. Please refresh the page and try again.",
                    className: "warning",
                    duration: 3000
                }).showToast();
                this.reset();
            }
            return false;
        }

        formData.append('cf_turnstile_token', turnstileToken);
        return true;
    }

    /**
     * @deprecated Use handleCheckoutSecurityVerification instead
     */
    async verifyAndAppendToken(formData, translate, cleanupCallback) {
        if (!this.isEnabled()) {
            return true;
        }

        const turnstileToken = await this.getToken();
        if (!turnstileToken) {
            if (cleanupCallback) {
                cleanupCallback();
            }
            new Toastify({
                text: translate("Security check failed. Please refresh the page and try again."),
                className: "warning",
                duration: 3000
            }).showToast();
            this.reset();
            return false;
        }

        formData.append('cf_turnstile_token', turnstileToken);
        return true;
    }

    /**
     * Get token. Calls execute() once — making the widget visible — then polls
     * for the callback. On Chrome/good Safari, auto-verify completes quickly.
     * On Safari with ITP, the now-visible widget shows a checkbox the user can click.
     */
    async getToken() {
        if (!this.isEnabled()) {
            return null;
        }

        if (typeof turnstile === 'undefined') {
            await new Promise((resolve) => {
                let attempts = 0;
                const interval = setInterval(() => {
                    attempts++;
                    if (typeof turnstile !== 'undefined' || attempts >= 100) {
                        clearInterval(interval);
                        resolve();
                    }
                }, 100);
            });
        }

        if (typeof turnstile === 'undefined') {
            return null;
        }

        if (window.fluentCartTurnstileToken) {
            return window.fluentCartTurnstileToken;
        }

        const wrapper = document.querySelector('[data-fluent-cart-turnstile-widget]');
        if (!wrapper) {
            return null;
        }

        // Element may have been removed after previous verification — recreate it
        let widget = wrapper.querySelector('.cf-turnstile');
        if (!widget) {
            widget = document.createElement('div');
            widget.className = 'cf-turnstile';
            wrapper.appendChild(widget);
        }

        let widgetId = widget.getAttribute('data-widget-id');
        if (!widgetId) {
            const siteKey = widget.getAttribute('data-sitekey') || window.fluentcart_checkout_vars?.turnstile?.site_key;
            if (!siteKey) {
                return null;
            }
            try {
                widgetId = turnstile.render(widget, {
                    sitekey: siteKey,
                    execution: 'render',
                    callback: this.handleToken.bind(this),
                    'expired-callback': this.handleExpired.bind(this),
                    'error-callback': this.handleError.bind(this),
                    size: 'flexible',
                    appearance: 'interaction-only',
                    theme: 'auto'
                });
                if (widgetId) {
                    widget.setAttribute('data-widget-id', widgetId);
                }
            } catch (error) {
                return null;
            }
        }

        if (this.pendingTokenPromise) {
            return this.pendingTokenPromise;
        }

        this.pendingTokenPromise = new Promise((resolve) => {
            let attempts = 0;
            const maxAttempts = 150; // 15 seconds — covers slow connections
            this.pendingResolve = resolve;

            const poll = () => {
                if (window.fluentCartTurnstileToken) {
                    const token = window.fluentCartTurnstileToken;
                    this.pendingTokenPromise = null;
                    this.pendingResolve = null;
                    resolve(token);
                    return;
                }

                // Auto-verify failed — show checkbox now that user is trying to pay
                if (this.inVisibleMode) {
                    this.switchToVisibleMode();
                    this.pendingTokenPromise = null;
                    this.pendingResolve = null;
                    resolve(null);
                    return;
                }

                if (attempts >= maxAttempts) {
                    this.pendingTokenPromise = null;
                    this.pendingResolve = null;
                    resolve(null);
                    return;
                }

                attempts++;
                setTimeout(poll, 100);
            };

            poll();
        });

        return this.pendingTokenPromise;
    }

    resolvePendingToken(token) {
        if (!this.pendingResolve) {
            return;
        }
        const resolve = this.pendingResolve;
        this.pendingResolve = null;
        this.pendingTokenPromise = null;
        resolve(token);
    }

    handleToken(token) {
        if (!token) {
            return;
        }
        this.inVisibleMode = false;
        this.visibleModeActive = false;
        this.autoRetryCount = 0;
        window.fluentCartTurnstileToken = token;
        this.resolvePendingToken(token);

        const wrapper = document.querySelector('[data-fluent-cart-turnstile-widget]');

        // Verified (auto OR manual) — always remove from DOM so Cloudflare can't
        // re-scan and re-verify (the reappear/loop). Decide the path by whether the
        // widget is actually on-screen, NOT by visibleModeActive: on Safari the
        // interaction-only widget shows its own checkbox without ever entering
        // visible mode, so the flag would be false even though the user saw it.
        const isOnScreen = wrapper && wrapper.offsetHeight > 0;
        if (isOnScreen) {
            // Visible widget — let user see "Success!" briefly, then smoothly
            // fade + collapse height, then remove.
            this.fadeTimer = setTimeout(() => {
                wrapper.style.overflow = 'hidden';
                wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
                // Force reflow so the max-height start value is committed before transitioning.
                void wrapper.offsetHeight;
                wrapper.style.transition = 'opacity 0.15s ease, max-height 0.15s ease, margin 0.15s ease';
                wrapper.style.opacity = '0';
                wrapper.style.maxHeight = '0px';
                wrapper.style.marginTop = '0px';
                wrapper.style.marginBottom = '0px';
                this.fadeTimer = setTimeout(() => {
                    this.fadeTimer = null;
                    this.hideAndRemoveWidget();
                }, 200);
            }, 800);
        } else {
            // Invisible auto-verify — remove immediately, nothing to show.
            this.hideAndRemoveWidget();
        }
    }

    hideAndRemoveWidget() {
        const widget = document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
        if (!widget) {
            return;
        }
        if (typeof turnstile !== 'undefined') {
            const widgetId = widget.getAttribute('data-widget-id');
            try { if (widgetId) turnstile.remove(widgetId); } catch (e) {}
        }
        // Remove element only — wrapper stays in normal flow (collapses to 0 height when empty).
        // display:none blocks Cloudflare from initialising a fresh widget on next attempt.
        widget.remove();
    }
}

export default TurnstileHandler;

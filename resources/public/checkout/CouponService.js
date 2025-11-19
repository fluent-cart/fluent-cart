import CheckoutHelper from "./CheckoutHelper";
import Url from "@/utils/support/Url";

export default class CouponService {
    #form;
    #baseUrl;
    #applyCouponUrl;
    #cancelCouponUrl;
    #couponContainer;
    #couponToggle;
    #couponApplyButton;
    #inputWrapper;
    #appliedCoupons = [];
    #applyingCoupon = false;
    #isDisableCoupons = false;
    translate = window.fluentcart.$t;
    #nonce = ''

    constructor(form) {

        this.#form = form;
        this.#baseUrl = window.fluentcart_checkout_vars.rest.url;
        this.#isDisableCoupons = window.fluentcart_checkout_vars?.disable_coupons.toString() === 'yes';
        this.#applyCouponUrl = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=apply_coupon`);

        this.#cancelCouponUrl = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=remove_coupon`);
        this.#nonce = window.fluentcart_checkout_info.checkout_nonce;

        this.#couponContainer = '[data-fluent-cart-checkout-page-coupon-container]';
        this.#couponToggle = '[data-fluent-cart-checkout-page-coupon-field-toggle]';
        this.#couponApplyButton = '[data-fluent-cart-checkout-page-coupon-validate]';

        this.#inputWrapper = '[data-fluent-cart-checkout-page-form-input-wrapper]';


    }

    init() {

        setTimeout(() => {
            this.#bindActions();
        }, 300);
        //this.#bindActions();
        window.addEventListener('fluentCartNotifySummaryViewUpdated', () => {
            //    this.#bindActions();
        });
        window.fluentCartCouponService = this;
    }

    // Getter
    getCoupons() {
        return this.#appliedCoupons;
    }

    // Setter (adds a new coupon)
    addCoupon(coupon) {
        this.#appliedCoupons.push(coupon);
    }

    // Remove by code
    removeCouponByCode(code) {
        this.#appliedCoupons = this.#appliedCoupons.filter(coupon => {
            return !(
                coupon === code
            );
        });
        this.removeCouponFromUrl(code)

    }

    removeCouponFromUrl(couponToRemove) {

        const url = new URL(window.location.href);
        const searchParams = url.searchParams;
        const coupons = searchParams.get('coupons');
        if (coupons) {
            const updatedCoupons = coupons
                .split(',')
                .filter(coupon => {
                    return !(
                        coupon === couponToRemove
                    );
                })
                .join(',');

            if (updatedCoupons) {
                searchParams.set('coupons', updatedCoupons);
            } else {
                searchParams.delete('coupons');
            }

            // Update the URL without reloading the page
            const newUrl = url.pathname + '?' + searchParams.toString();
            history.replaceState(null, '', newUrl);
        }
    }


    #bindActions() {

		if (!this.#isDisableCoupons) {
			// Delegated pointerdown to trigger apply before click, survives fragment replacement
			this.#form.addEventListener('pointerdown', (event) => {
				const btn = event.target.closest(this.#couponApplyButton);
				if (!btn) return;
				event.preventDefault();
				this.#applyCoupon();
			});

			// Delegated click to suppress default/duplicate submissions while applying
			this.#form.addEventListener('click', (event) => {
				const btn = event.target.closest(this.#couponApplyButton);
				if (!btn) return;
				if (this.#applyingCoupon) {
					event.preventDefault();
					return;
				}
				event.stopPropagation();
			});
		}


		// Delegated toggle handler to survive fragment replacement
		this.#form.addEventListener('click', (event) => {
			const toggleEl = event.target.closest(this.#couponToggle);
			if (!toggleEl) return;
			event.preventDefault();
			const container = document.querySelector(this.#couponContainer);
			if (container) {
				const inputWrapper = container.querySelector(this.#inputWrapper);
				if (inputWrapper) {
					const input = inputWrapper.querySelector('input');
					if (input) {
						setTimeout(() => input.focus(), 0);
					}
				}
				container.style.display = (container.style.display === 'none' || !container.style.display) ? 'block' : 'none';
			}
		});

		// Delegated handler so it survives fragment replacements
		this.#form.addEventListener('click', (event) => {
			const button = event.target.closest('[data-remove-coupon]');
			if (!button) return;
			// Ensure the click originated from inside the applied coupon container
			const withinApplied = button.closest('[data-fluent-cart-checkout-page-applied-coupon]');
			if (!withinApplied) return;
			event.preventDefault();
			const coupon = button.dataset.coupon ?? button.getAttribute('data-coupon');
			this.#removeCoupon(coupon);
		});

        // Delegated keypress handler to survive fragment replacement
        this.#form.addEventListener('keypress', (event) => {
            if (event.target.id === 'coupon' && event.key === 'Enter') {
                event.preventDefault();
                this.#applyCoupon();
            }
        });
    }

    #applyCoupon() {

        this.#applyingCoupon = true;
        const coupon = document.getElementById("coupon").value;
        const customerEmail = document.getElementById("billing_email").value;
        const url = Url.appendQueryParams(
            this.#applyCouponUrl.toString(), {
                coupon_code: coupon,
                customer_email: customerEmail
            });

        fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": this.#nonce,
            },
            credentials: 'include'
        })
            .then((response) => {
                this.#applyingCoupon = false;
                if (!response.ok) {
                    return response.json().then((error) => {
                        throw new Error(error.message);
                    });
                }
                return response.json();
            })
            .then((data) => {
                CheckoutHelper.handleFragments(data.fragments); 
                
                this.#appliedCoupons = data.applied_coupons;

                document.getElementById("coupon").value = '';

                if (this.#appliedCoupons.includes(coupon)) {
                    new Toastify({
                        text: this.translate("Applied Successfully"),
                        className: "info",
                        duration: 3000
                    }).showToast();

                    window.dispatchEvent(new CustomEvent('fluentCartNotifyCouponApplied', {
                        detail: {
                            response: data
                        }
                    }));

                    window.dispatchEvent(new CustomEvent('fluentCartCheckoutDataChanged', {
                        detail: {
                            response: data
                        }
                    }));
                } else {
                    new Toastify({
                        text: this.translate("Coupon cannot be applied"),
                        className: "info",
                        duration: 3000
                    }).showToast();
                }
            })
            .catch((error) => {
                this.#applyingCoupon = false;
                new Toastify({
                    text: `<span class="warn warning"></span>${error.message}`,
                    className: "warning",
                    escapeMarkup: false,
                    duration: 3000,
                    close: false
                }).showToast();
            });
    }


    #removeCoupon(couponCode) {

        this.removeCouponByCode(couponCode);
        fetch(this.#cancelCouponUrl + '&coupon_code=' + couponCode, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": this.#nonce,
            },
            credentials: 'include'
        })
            .then((response) => {
                if (!response.ok) {
                    return response.text().then((text) => {
                        throw new Error(text);
                    });
                }
                new Toastify({
                    text: this.translate("Coupon removed!"),
                    className: "info",
                    duration: 3000
                }).showToast();
                return response.json();
            })
            .then((response) => {
                this.#appliedCoupons = response.applied_coupons;
                if (response.fragments) {
                    CheckoutHelper.handleFragments(response.fragments);
                }

                window.dispatchEvent(new CustomEvent('fluentCartNotifyCouponRemoved', {
                    detail: {
                        response: response
                    }
                }));

                window.dispatchEvent(new CustomEvent('fluentCartCheckoutDataChanged', {
                    detail: {
                        response: response
                    }
                }));

            })
            .catch((error) => {
                this.addCoupon(couponCode);
                console.error("Failed to cancel coupon:", error.message);
            });
    }
}

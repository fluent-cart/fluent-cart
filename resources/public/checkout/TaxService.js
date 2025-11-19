import CheckoutHelper from "./CheckoutHelper";
import Url from "@/utils/support/Url";

export default class TaxService {

    static instance = null;
    checkoutHandler = null;
    form = null;
    taxWrapper = null;
    applyBtn = null;
    loader = null;
    taxIdInput = null;
    errorWrapper = null;
    taxRemoveBtn = null;
    validNoteWrapper = null;
    validNote = null;
    translate = window.fluentcart.$t;

    static init(checkoutHandler) {
        if (TaxService.instance === null) {
            TaxService.instance = new TaxService(checkoutHandler);
        }
        return TaxService.instance;
    }

    constructor(checkoutHandler) {
        this.checkoutHandler = checkoutHandler;
        this.form = checkoutHandler?.form;
        this.taxWrapper = this.form.querySelector('[data-fluent-cart-checkout-page-tax-wrapper]');
        this.applyBtn = this.taxWrapper?.querySelector('[data-fluent-cart-checkout-page-tax-apply-btn]');
        this.loader = this.taxWrapper?.querySelector('[data-fluent-cart-checkout-page-tax-loading]');
        this.taxIdInput = this.taxWrapper?.querySelector('[data-fluent-cart-checkout-page-tax-id]');
        this.errorWrapper = this.taxWrapper?.querySelector('[data-fluent-cart-checkout-page-form-error]');
        this.taxRemoveBtn = this.taxWrapper?.querySelector('[data-fluent-cart-tax-remove-btn]');
        this.validNoteWrapper = this.taxWrapper?.querySelector('[data-fluent-cart-tax-valid-note-wrapper]');
        this.validNote = this.taxWrapper?.querySelector('[data-fluent-cart-tax-valid-note]');
        
        this.bindDelegatedEvents();
    }

    getTaxWrapper() {
        // Refresh reference if DOM was replaced by fragments
        this.taxWrapper = this.form.querySelector('[data-fluent-cart-checkout-page-tax-wrapper]');
        return this.taxWrapper;
    }

    getTaxElements() {
        const wrapper = this.getTaxWrapper();
        if (!wrapper) return {};
        
        return {
            applyBtn: wrapper.querySelector('[data-fluent-cart-checkout-page-tax-apply-btn]'),
            loader: wrapper.querySelector('[data-fluent-cart-checkout-page-tax-loading]'),
            taxIdInput: wrapper.querySelector('[data-fluent-cart-checkout-page-tax-id]'),
            errorWrapper: wrapper.querySelector('[data-fluent-cart-checkout-page-form-error]'),
            taxRemoveBtn: wrapper.querySelector('[data-fluent-cart-tax-remove-btn]'),
            validNoteWrapper: wrapper.querySelector('[data-fluent-cart-tax-valid-note-wrapper]'),
            validNote: wrapper.querySelector('[data-fluent-cart-tax-valid-note]')
        };
    }

    bindDelegatedEvents() {
        // Delegated event handlers that survive DOM fragment replacements
        this.form.addEventListener('click', (event) => {
            // Apply button click
            if (event.target.matches('[data-fluent-cart-checkout-page-tax-apply-btn]')) {
                event.preventDefault();
                this.validateVatHandler();
                return;
            }
            
            // Remove button click
            if (event.target.matches('[data-fluent-cart-tax-remove-btn]')) {
                event.preventDefault();
                this.removeVat();
                return;
            }
        });

        // Delegated keydown handler for tax ID input
        this.form.addEventListener('keydown', (event) => {
            if (event.target.matches('[data-fluent-cart-checkout-page-tax-id]') && event.key === 'Enter') {
                event.preventDefault();
                this.validateVatHandler();
            }
        });
    }

    validateVatHandler() {
        const elements = this.getTaxElements();
        const vatNumber = elements.taxIdInput?.value?.trim();
        if (!vatNumber) {
            this.clearError();
            return;
        }

        this.validateVat(vatNumber);
    }


    async validateVat(vatNumber) {
        const url = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_validate_vat`).toString();

        const body = new URLSearchParams({
            vat_number: vatNumber,
            _wpnonce: window.fluentcart_checkout_info?.checkout_nonce || ''
        }).toString();

        try {
            this.startLoading();
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'include',
                body
            });

            const data = await response.json();

            if (!response.ok || data?.success === false) {
                const message = data?.message || data?.message || this.translate('Invalid VAT number');
                this.showError(message);
                return false;
            }

            const result = data?.tax_data;
            if (data?.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
            }
            this.clearError();
            // Optionally, you can display a small success note
            this.showSuccess(`${result?.name ? result.name + ' • ' : ''}${result?.country || ''}`);
            return true;
        } catch (e) {
            console.log(e, 'error');
            this.showError(this.translate('Validation service unavailable. Please try again.'));
            return false;
        } finally {
            this.endLoading();
        }
    }

    async removeVat() {
        if (!confirm(this.translate('Are you sure you want to remove VAT?'))) {
            return;
        }

        const url = CheckoutHelper.buildUrl(
            `${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_remove_vat`
        ).toString();

        const body = new URLSearchParams({
            _wpnonce: window.fluentcart_checkout_info?.checkout_nonce || ''
        }).toString();

        try {
            this.startLoading();
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'include',
                body
            });

            const data = await response.json();

            if (!response.ok || data?.success === false) {
                this.showError(data?.message || this.translate('Failed to remove VAT'));
                return;
            }

            this.clearError();
            this.clearSuccess();

            if (data?.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
            }

            const elements = this.getTaxElements();
            if (elements.taxIdInput) {
                elements.taxIdInput.value = '';
            }

            //@todo: temporary reload when removed vat, will be removed after ajax reload is implemented
            window.location.reload();

        } catch (e) {
            console.error(e);
            this.showError(this.translate('Could not remove VAT, please try again.'));
        } finally {
            this.endLoading();
        }
    }

    showError(message) {
        const elements = this.getTaxElements();
        const wrapper = this.getTaxWrapper();

        wrapper?.classList.add('has-error');
        elements.taxIdInput?.setAttribute('aria-invalid', 'true');
        if (elements.errorWrapper) {
            elements.errorWrapper.textContent = message || this.translate('Invalid VAT number');
            elements.errorWrapper.style.display = 'block';
        }
    }

    clearError() {
        const elements = this.getTaxElements();
        const wrapper = this.getTaxWrapper();

        wrapper?.classList.remove('has-error');
        elements.taxIdInput?.removeAttribute('aria-invalid');
        if (elements.errorWrapper) {
            elements.errorWrapper.textContent = '';
            elements.errorWrapper.style.display = '';
        }
        this.clearSuccess();
    }

    showSuccess(message) {
        const elements = this.getTaxElements();
        const wrapper = this.getTaxWrapper();
        if (!wrapper) return;
        if (elements.validNote) {
            elements.validNoteWrapper.classList.remove('is-hidden');
            elements.validNote.textContent = message || this.translate('Valid VAT number');
        }
    }

    clearSuccess() {
        const elements = this.getTaxElements();
        const wrapper = this.getTaxWrapper();
        if (!wrapper) return;
        if (elements.validNote) {
            elements.validNoteWrapper.classList.add('is-hidden');
            elements.validNote.textContent = '';
        }
    }

    startLoading() {
        const elements = this.getTaxElements();
        if (elements.loader) {
            elements.loader.style.display = 'block';
            elements.loader.textContent = this.translate('Validating...');
        }

        if (elements.applyBtn || elements.taxIdInput) {
            elements.taxIdInput?.setAttribute('disabled', 'disabled');
            elements.applyBtn?.setAttribute('disabled', 'disabled');
        }

        this.clearError();
    }

    endLoading() {
        const elements = this.getTaxElements();
        if (elements.loader) {
            elements.loader.style.display = 'none';
        }

        if (elements.applyBtn || elements.taxIdInput) {
            elements.taxIdInput?.removeAttribute('disabled');
            elements.applyBtn?.removeAttribute('disabled');
        }

        if (elements.errorWrapper) {
            elements.errorWrapper.style.display = 'block';
        }
    }
}


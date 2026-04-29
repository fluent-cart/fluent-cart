const validateCheckout = (rangeElement) => {
    return new Promise(function (resolve, reject) {
        let hasError = false;

        // Reset errors
        const errorElements = rangeElement.querySelectorAll('.fluent_cart_checkout_has_errors');
        errorElements.forEach(el => {
            el.classList.remove('fluent_cart_checkout_has_errors');
        });

        function getLabel(input) {
            const wrapper = input.closest('[data-fluent-cart-checkout-page-form-input-wrapper]');
            if (!wrapper) return '';
            const label = wrapper.querySelector('label');
            let errorLabel = label ? label.textContent.replace('*', '') : '';

            if (!errorLabel) {
                const placeholder = input.getAttribute('placeholder');
                if (placeholder) {
                    errorLabel = placeholder;
                }
            }

            return errorLabel.trim();
        }

        const translate = window.fluentcart.$t;

        // Select required inputs, checkboxes, textareas, selects
        const inputTypes = rangeElement.querySelectorAll(
            'input[data-required="yes"][data-type="input"],' +
            'input[data-required="yes"][type="checkbox"],' +
            'textarea[data-required="yes"],' +
            'select[data-required="yes"]'
        );

        if (inputTypes.length) {
            inputTypes.forEach(input => {
                let has_error = !input.value;

                if (input.type === 'checkbox') {
                    has_error = !input.checked;
                }

                const wrapper = input.parentElement;
                const errorEl = input.nextElementSibling?.getAttribute('data-fluent_cart_checkout_error') !== null
                    ? input.nextElementSibling
                    : wrapper.querySelector('[data-fluent_cart_checkout_error]');

                if (has_error) {
                    wrapper.classList.add('has-error');
                    const label = getLabel(input);
                    hasError = true;
                    if (errorEl) {
                        /* translators: 1: Field name */
                        errorEl.innerHTML = translate('%1$s field is required.', label);
                    }
                } else {
                    wrapper.classList.remove('has-error');
                    if (errorEl) {
                        errorEl.innerHTML = '';
                    }
                }

                // Validate email
                if (input.type === 'email' && input.value) {
                    const emailRegex = /^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/;
                    if (!emailRegex.test(input.value)) {
                        wrapper.classList.add('has-error');
                        const label = getLabel(input);
                        hasError = true;
                        if (errorEl) {
                            /* translators: 1: Field name */
                            errorEl.innerHTML = translate('%s is invalid, please use a valid email.', label);
                        }
                    }
                }
            });
        }

        if (hasError) {
            reject('validation_failed');
            return;
        }
        resolve(true);
    });
};

export default validateCheckout;

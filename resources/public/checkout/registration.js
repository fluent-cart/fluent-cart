document.addEventListener("DOMContentLoaded", function () {
    class Registration {
        constructor() {
            this.formatErrorFields();
            this.signupUrl = window.fluentcart_checkout_info?.rest?.url + '/user/register';
            this.translate = window.fluentcart.$t;
        }
        formatErrorFields() {
            const containers = document.querySelectorAll('[data-fluent-cart-checkout-page-form-section]');
            containers.forEach(container => {
                const errors = container.querySelectorAll('[data-fluent-cart-checkout-page-form-error]');
                errors.forEach(error => {
                    const cloned = error.cloneNode(true);
                    error.remove();
                    container.appendChild(cloned);
                });
            });
        }

        init() {
            this.form = document.querySelector('[data-fluent-cart-registration-form]');
            if (this.form) {
                this.form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const button = this.form.querySelector('button');
                    button.textContent = this.translate('Loading...');
                    button.disabled = true;
                    this.handleRegistration();
                });
            }
        }

        handleRegistration() {
            const formData = {};
            const formElements = this.form.querySelectorAll('input, select, textarea');
            formElements.forEach(el => {
                if (el.name) {
                    formData[el.name] = el.value;
                }
            });

            const submitButton = this.form.querySelector('button.fluent-cart-registration-button');
            submitButton.textContent = this.translate('Please wait...');
            submitButton.disabled = true;
            this.removeValidationErrors();

            const xhr = new XMLHttpRequest();
                xhr.open('POST', this.signupUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

                xhr.onload = ()=> {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        const response = JSON.parse(xhr.responseText);
                        submitButton.textContent = this.translate('Redirecting please wait...');
                        new Toastify({
                            text: response.data?.message,
                            duration: 300,
                            className: "info",
                            style: {
                                background: "linear-gradient(to right, #00b09b, #96c93d)",
                            }
                        }).showToast();
                        window.location.href = response.data?.redirect_url;
                    } else {
                        submitButton.disabled = false;
                        submitButton.textContent = this.translate('Register');

                        let errorResponse;
                        try {
                            errorResponse = JSON.parse(xhr.responseText);
                        } catch (e) {
                            errorResponse = null;
                        }

                        if (errorResponse) {
                            const errors = errorResponse ?? {};
                            this.showValidationErrors(errors);
                            if (errorResponse.hasOwnProperty('data')) {
                                new Toastify({
                                    text: errorResponse.data?.message ?? this.translate('Something went wrong'),
                                    className: "warning",
                                    style: {
                                        background: "linear-gradient(to right, #ff8a23, #ffc051)",
                                    }
                                }).showToast();
                            }
                        }
                    }
                };

                xhr.onerror = function () {
                    submitButton.disabled = false;
                    submitButton.textContent = this.translate('Submit');
                    new Toastify({
                        text: this.translate('Network error occurred'),
                        className: "error",
                        style: {
                            background: "linear-gradient(to right, #ff5f6d, #ffc371)",
                        }
                    }).showToast();
                };

                xhr.send(new URLSearchParams(formData).toString());
        }

        removeValidationErrors() {
            const wrappers = document.querySelectorAll('[data-fluent-cart-checkout-page-form-input-wrapper]');
            wrappers.forEach(wrapper => {
                wrapper.classList.remove('has-error');
            });
            const errors = document.querySelectorAll('[data-fluent-cart-checkout-page-form-error]');
            errors.forEach(error => {
                error.innerHTML = '';
            });
        }

        showValidationErrors(errors) {
            if (typeof errors === 'object') {
                for (const fieldId in errors) {
                    const input = document.getElementById(fieldId);
                    if (!input) continue;
                    const wrapper = input.closest('[data-fluent-cart-checkout-page-form-input-wrapper]');
                    if (wrapper) {
                        wrapper.classList.add('has-error');
                    }

                    const errorMessages = errors[fieldId];
                    if (typeof errorMessages === 'object') {
                        let errorMessage = '';
                        let index = 0;
                        const errorCount = Object.entries(errorMessages).length;
                        for (const messageId in errorMessages) {
                            errorMessage += errorMessages[messageId];
                            if (index !== errorCount - 1) {
                                errorMessage += '<br>';
                            }
                            index++;
                        }

                        const errorContainer = document.querySelector("[data-fluent-cart-checkout-page-form-error][for='" + input.name + "']");
                        if (errorContainer) {
                            errorContainer.innerHTML = errorMessage;
                        }
                    }
                }
            }
        }
    }

    new Registration().init();
});

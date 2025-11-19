document.addEventListener("DOMContentLoaded", function () {
    class Login {
        constructor() {
            this.loginUrl = window.fluentcart_checkout_info?.rest?.url + '/user/login';
            this.nonce = window.fluentcart_checkout_info.rest.nonce;
            this.translate = window.fluentcart.$t;
        }

        init() {
            this.form = document.querySelector('[data-fluent-cart-login-form]');
            if (!this.form) return;

            this.submitButton = this.form.querySelector('button.fluent-cart-login-button');
            this.enableLiveErrorClearing();

            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleLogin();
            });
        }

        getFormData() {
            const data = {};
            const fields = this.form.querySelectorAll('input, select, textarea');

            fields.forEach(field => {
                if (!field.name) return;

                if (field.type === 'checkbox') {
                    data[field.name] = field.checked ? 'on' : '';
                } else {
                    data[field.name] = field.value.trim();
                }
            });

            return data;
        }

        clearErrors() {
            const errorSpans = this.form.querySelectorAll('[data-fluent-cart-checkout-page-form-error]');
            errorSpans.forEach(span => {
                span.textContent = '';

                const wrapper = span.closest('[data-fluent-cart-checkout-page-form-input-wrapper]');
                if (wrapper) {
                    wrapper.classList.remove('has-error');
                }
            });
        }

        showFieldError(fieldName, message) {
            const errorSpan = this.form.querySelector(`[data-fluent-cart-checkout-page-form-error][for="${fieldName}"]`);
            if (errorSpan) {
                errorSpan.textContent = message;

                const wrapper = errorSpan.closest('[data-fluent-cart-checkout-page-form-input-wrapper]');
                if (wrapper) {
                    wrapper.classList.add('has-error');
                }
            } else {
                this.showToast(message, 'warning');
            }
        }

        enableLiveErrorClearing() {
            const inputs = this.form.querySelectorAll('input');

            inputs.forEach(input => {
                input.addEventListener('input', () => {
                    const wrapper = input.closest('[data-fluent-cart-checkout-page-form-input-wrapper]');
                    const errorSpan = this.form.querySelector(`[data-fluent-cart-checkout-page-form-error][for="${input.name}"]`);

                    if (wrapper) wrapper.classList.remove('has-error');
                    if (errorSpan) errorSpan.textContent = '';
                });
            });
        }

        validate(formData) {
            this.clearErrors();

            let isValid = true;

            if (!formData.user_login) {
                this.showFieldError('user_login', this.translate('Username or Email is required'));
                isValid = false;
            }

            if (!formData.password) {
                this.showFieldError('password', this.translate('Password is required'));
                isValid = false;
            }

            return isValid;
        }

        handleLogin() {
            const formData = this.getFormData();

            if (!this.validate(formData)) {
                return;
            }

            this.submitButton.disabled = true;
            this.submitButton.textContent = this.translate('Please wait...');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.loginUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.setRequestHeader('X-WP-Nonce', this.nonce);

            xhr.onload = () => {
                let response = null;

                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {}

                if (xhr.status >= 200 && xhr.status < 300 && response?.success) {
                    this.showToast(response.data?.message || this.translate('Login successful'), 'success');
                    this.submitButton.textContent = this.translate('Redirecting...');
                    window.location.href = response.data?.redirect_url;
                } else {
                    this.submitButton.disabled = false;
                    this.submitButton.textContent = this.translate('Login');

                    const errorMsg = response?.data?.message || this.translate('Login failed. Please try again.');
                    this.showToast(errorMsg, 'warning');
                }
            };

            xhr.onerror = () => {
                this.submitButton.disabled = false;
                this.submitButton.textContent = this.translate('Login');
                this.showToast(this.translate('Network error occurred'), 'error');
            };

            xhr.send(new URLSearchParams(formData).toString());
        }

        showToast(message, type = 'info') {
            let background;
            let color;
            const rawHTML = message || this.translate('Login failed.');

            const toastNode = document.createElement('div');
            toastNode.innerHTML = rawHTML;

            switch (type) {
                case 'success':
                    background = 'linear-gradient(to right, #00b09b, #96c93d)';
                    color = '#fff';
                    break;
                case 'warning':
                    background = 'linear-gradient(to right, #ff8a23, #ffc051)';
                    color = '#fff';
                    break;
                case 'error':
                    background = 'linear-gradient(to right, #ff5f6d, #ffc371)';
                    color = '#fff';
                    break;
                default:
                    background = 'linear-gradient(to right, #4e54c8, #8f94fb)';
                    color = '#fff';
            }

            new Toastify({
                node: toastNode,
                text: message,
                duration: 3000,
                className: type,
                style: { background, color }
            }).showToast();
        }
    }

    new Login().init();
});

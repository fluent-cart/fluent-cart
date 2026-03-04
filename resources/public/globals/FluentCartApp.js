import UTMManager from "./utils/UTMManager";
import FluentCartCart from "./Cart/FluentCartCart";
import AddToCartButton from "../buttons/add-to-cart/script";
import ModalCheckoutHandler from "../checkout/ModalCheckoutHandler";

const translationStrings = {
    ...window.fluentcart_checkout_vars?.trans || {},
    ...window.fluentcart_checkout_vars?.payments_trans || {},
    ...window.fluentcart_shop_vars?.trans || {}
}
window.fluentcart = {
    $t: function (str) {
        let string = translationStrings[str] || str;
        // Prepare the arguments, excluding the first one (the string itself)
        const args = Array.prototype.slice.call(arguments, 1);

        if (args.length === 0) {
            return string;
        }

        // Regular expression to match %s, %d, or %1s, %2s,  %1$s etc.
        const regex = /%(\d*\$?)s|%d/g;

        // Replace function to handle each match found by the regex
        let argIndex = 0; // Keep track of the argument index for non-numbered placeholders
        string = string.replace(regex, (match, number) => {
            // If it's a numbered placeholder, use the number to find the corresponding argument
            if (number) {
                const index = parseInt(number, 10) - 1; // Convert to zero-based index
                return index < args.length ? args[index] : match; // Replace or keep the placeholder
            } else {
                // For non-numbered placeholders, use the next argument in the array
                return argIndex < args.length ? args[argIndex++] : match; // Replace or keep the placeholder
            }
        });

        return string;
    }
};

const recursiveFlatten = function (obj, prefix) {
    return Object.keys(obj).reduce(function (acc, key) {
        const fullKey = prefix + '[' + key + ']';
        if (obj[key] !== null && typeof obj[key] === 'object' && !Array.isArray(obj[key])) {
            Object.assign(acc, recursiveFlatten(obj[key], fullKey));
        } else {
            acc[fullKey] = obj[key];
        }
        return acc;
    }, {});
};

const request = function (method, data = {}, cancelable) {
    const baseUrl = `${window.fluentCartRestVars.ajaxurl}`;

    const controller = new AbortController();
    const signal = controller.signal;
    const headers = {
        'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
    };

    if (['PUT', 'PATCH', 'DELETE'].indexOf(method.toUpperCase()) !== -1) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    data.query_timestamp = Date.now();

    const promise = new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();

        let url = baseUrl;
        if (method.toUpperCase() === 'GET') {

            let formattedData = {};

            // filter out null or false values
            Object.keys(data).forEach(key => {
                if (data[key] === null || data[key] === false) {
                    delete data[key];
                } else {
                    // check if the value is an array or object
                    if (typeof data[key] === 'object') {
                        // use recursiveFlatten to flatten the object
                        formattedData = Object.assign(formattedData, recursiveFlatten(data[key], key));
                    } else {
                        formattedData[key] = data[key];
                    }
                }
            });

            let queryStrings = new URLSearchParams(formattedData).toString();

            // if the url already has query strings
            if (url.indexOf('?') !== -1) {
                url += '&' + queryStrings;
            } else {
                url += '?' + queryStrings;
            }
        }

        xhr.open(method, url, true);
        signal.addEventListener('abort', () => {
            xhr.abort();
            reject({aborted: true, message: 'Request aborted'});
        });

        Object.keys(headers).forEach(key => {
            xhr.setRequestHeader(key, headers[key]);
        });

        xhr.onload = function () {
            let response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = null;
            }

            if (this.status >= 200 && this.status < 300) {
                resolve(response);
            } else {
                if (response) {
                    if (typeof response === 'object') {
                        response.xhr_status = xhr.status;
                    }

                    if (response.code == 'rest_cookie_invalid_nonce') {
                        document.dispatchEvent(new CustomEvent('fcart_renew_rest_nonce', {detail: response}));
                    }
                }

                reject(response);
            }
        };

        xhr.onerror = function () {
            console.info('Your server firewall blocked the request or it\'s a plugin conflict. Please check the detailed error.');
            console.log({
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText
            });
            reject({
                status: xhr.status,
                statusText: xhr.statusText
            });
        };

        if (method.toUpperCase() === 'GET') {
            xhr.send();
        } else {
            xhr.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
            xhr.send(JSON.stringify(data));
        }
    });

    if (cancelable) {
        return {request: promise, controller};
    }

    return promise;
};

document.addEventListener('DOMContentLoaded', function () {

    window.fluentCartCart = new FluentCartCart().init();
    window.fluentCartUtmManager = new UTMManager();
    AddToCartButton.init();
    window.fluentCartAjax = {
        get: function (data = {}, cancelable = false) {
            return request('GET', data, cancelable);
        },
        post: function (data = {}, cancelable = false) {
            return request('POST', data, cancelable);
        }
    };

    window.fluentcart['ajax'] = window.fluentCartAjax;
    ModalCheckoutHandler.init();

    window.dispatchEvent(
        new CustomEvent("fluent_cart_app_loaded", {})
    );

    document.addEventListener("click", function(e){
        const btn = e.target.closest("[data-fluent-cart-collapsible-toggle]");
        if(!btn) return;

        e.preventDefault();

        const wrap = btn.closest("[data-fluent-cart-collapsibles]");

        wrap.classList.toggle("show-all");
    });
});

function recursiveFlatten(obj, prefix = '') {
    let newObj = {};

    Object.keys(obj).forEach(key => {
        if (obj[key] === null || obj[key] === false) {
            delete obj[key];
        } else {
            if (typeof obj[key] === 'object') {
                newObj = Object.assign(newObj, recursiveFlatten(obj[key], `${prefix}[${key}]`));
            } else {
                newObj[`${prefix}[${key}]`] = obj[key];
            }
        }
    });

    return newObj;
}

const request = function (method, route, data = {}) {
    const cleanRoute = route.startsWith('/') ? route.slice(1) : route;
    const baseUrl = `${window.fluentCartRestVars.rest.url}/${cleanRoute}`;

    const headers = {
        'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
    };

    if (['PUT', 'PATCH', 'DELETE'].indexOf(method.toUpperCase()) !== -1) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    data.query_timestamp = Date.now();

    return new Promise((resolve, reject) => {
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
                        //response.xhr_status = xhr.status;
                    }

                    if (response.code === 'rest_cookie_invalid_nonce' || response.code === 'rest_cookie_invalid') {
                        document.dispatchEvent(new CustomEvent('fcart_renew_rest_nonce', {detail: response}));
                    }
                }

                reject({
                    data: response,
                    status_code: xhr.status,
                    //statusText: xhr.statusText
                });
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
};

export default {
    get(route, data = {}) {
        return request('GET', route, data);
    },
    post(route, data = {}) {
        return request('POST', route, data);
    },
    delete(route, data = {}) {
        return request('DELETE', route, data);
    },
    put(route, data = {}) {
        return request('PUT', route, data);
    },
    patch(route, data = {}) {
        return request('PATCH', route, data);
    },
    upload(route, data = {}) {
        const url = `${window.fluentCartRestVars.rest.url}/${route}`;
        const headers = {'X-WP-Nonce': window.fluentCartRestVars.rest.nonce};
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);

            // Set headers
            Object.keys(headers).forEach(key => {
                xhr.setRequestHeader(key, headers[key]);
            });

            xhr.onload = function () {
                if (this.status >= 200 && this.status < 300) {
                    resolve(JSON.parse(xhr.responseText));
                } else {
                    reject(JSON.parse(xhr.responseText));
                }
            };

            xhr.onerror = function () {
                reject({
                    status: xhr.status,
                    statusText: xhr.statusText
                });
            };

            // Send FormData object
            xhr.send(data);
        });
    },
    getNonce() {
        return window.fluentCartRestVars.rest.nonce;
    }
};

if (window.jQuery !== undefined) {
    jQuery(document).ajaxSuccess((event, xhr, settings) => {
        const nonce = xhr.getResponseHeader('X-WP-Nonce');
        if (nonce) {
            if (window.fluentCartRestVars) {
                window.fluentCartRestVars.rest_nonce = nonce;
            } else {
                window.fluentCartRestVars.rest_nonce = nonce;
            }
        }
    });
}

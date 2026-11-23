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

// Params that change how WordPress REST handles a request. A GET with ?_method=DELETE runs as a DELETE.
const OVERRIDE_PARAMS = ['_method', '_jsonp', '_envelope'];

const parsedBases = {};

function parseBase(raw) {
    if (!parsedBases[raw]) {
        const url = new URL(raw, window.location?.href);
        const route = url.searchParams.get('rest_route'); // set on sites with plain permalinks
        parsedBases[raw] = {
            origin: url.origin,
            pathname: url.pathname,
            plain: route !== null,
            prefix: (route !== null ? route : url.pathname).replace(/\/$/, '') + '/'
        };
    }

    return parsedBases[raw];
}

// PHP: stop at NUL and drop leading spaces. A "[" with a matching "]" after it starts an array
// (the name is what comes before it). Otherwise every " ", "." and "[" becomes "_".
function phpParamName(key) {
    const name = key.split('\0')[0].replace(/^ +/, '');
    const open = name.indexOf('[');
    if (open !== -1 && name.indexOf(']', open) !== -1) {
        return name.slice(0, open).replace(/[ .]/g, '_');
    }
    return name.replace(/[ .[]/g, '_');
}

/**
 * Route params come from the page URL, so a crafted link reaches this helper.
 * A safe URL stays inside the REST base and has no param that changes the method or the route.
 */
export function isSafeRestUrl(fullUrl, baseUrl = window.fluentCartRestVars.rest.url) {
    // "#" cuts off the rest of the URL, browsers and servers read "\" differently,
    // the URL parser strips tabs and newlines (".\t." becomes ".."), and PHP can be set to split
    // query params on ";" as well as "&", which URLSearchParams does not do. Data values are
    // encoded, so a legitimate URL holds none of these.
    if (/[#\\;\u0000-\u001F\u007F]/.test(fullUrl)) {
        return false;
    }

    // The route as written: new URL() resolves "." and ".." before pathname is read, so check it here.
    // An encoded slash or backslash leaves its segment once the server decodes it.
    if (!fullUrl.startsWith(baseUrl)) {
        return false;
    }
    const base = parseBase(baseUrl);
    const rest = fullUrl.slice(baseUrl.length);

    // PHP splits the query on every character of arg_separator.input (sent as query_separators);
    // URLSearchParams splits only on "&". On plain permalinks the route itself is in the query.
    const query = base.plain ? rest : rest.split('?').slice(1).join('?');
    for (const separator of String(window.fluentCartRestVars?.rest?.query_separators || '')) {
        if (separator !== '&' && query.includes(separator)) {
            return false;
        }
    }

    // On pretty permalinks "&" is part of the path; on plain ones the route ends at the next query param
    const routePath = rest.split(base.plain ? '&' : '?')[0];
    let decodedPath;
    try {
        decodedPath = decodeURIComponent(routePath);
    } catch (e) {
        return false;
    }
    if (/%2f|%5c/i.test(routePath) || decodedPath.split('/').some(part => part === '.' || part === '..')) {
        return false;
    }

    const url = new URL(fullUrl, window.location?.href);

    let routeParams = 0;
    for (const key of url.searchParams.keys()) {
        // how PHP names the key: see phpParamName()
        const name = phpParamName(key);
        if (OVERRIDE_PARAMS.includes(name)) {
            return false;
        }
        if (name === 'rest_route') {
            routeParams++;
        }
    }

    const path = (base.plain ? url.searchParams.get('rest_route') : url.pathname) || '';

    return url.origin === base.origin
        && routeParams === (base.plain ? 1 : 0)
        && (!base.plain || url.pathname === base.pathname)
        && path.startsWith(base.prefix)
        && !path.split('/').some(part => part === '.' || part === '..');
}

// A fixed message: error notices render HTML, so the route (from the page URL) must not appear in it.
// Same shape as every other failure here ({data, status_code}), so notices read error.data.message.
const invalidRoute = () => ({
    data: {message: 'Invalid REST route.', code: 'fcart_invalid_rest_route'},
    status_code: 0
});

const request = function (baseURL = null, method, route, data = {}, options = {}) {
    const cleanRoute = route.startsWith('/') ? route.slice(1) : route;
    const restBase = baseURL ? baseURL : window.fluentCartRestVars.rest.url;
    const baseUrl = `${restBase}/${cleanRoute}`;

    const headers = {
        'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
    };

    if (['PUT', 'PATCH', 'DELETE'].indexOf(method.toUpperCase()) !== -1) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    data.query_timestamp = Date.now();

    return new Promise((resolve, reject) => {
        const signal = options.signal;

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

        if (!isSafeRestUrl(url, restBase)) {
            reject(invalidRoute());
            return;
        }

        const xhr = new XMLHttpRequest();
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
            reject({
                status: xhr.status,
                statusText: xhr.statusText
            });
        };

        xhr.onabort = function () {
            reject(new DOMException('The request was aborted.', 'AbortError'));
        };

        if (signal) {
            if (signal.aborted) {
                xhr.abort();
                return;
            }

            signal.addEventListener('abort', () => xhr.abort(), {once: true});
        }

        if (method.toUpperCase() === 'GET') {
            xhr.send();
        } else {
            xhr.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
            xhr.send(JSON.stringify(data));
        }
    });
};

export default {
    get(route, data = {}, baseURL = null, options = {}) {
        return request(baseURL, 'GET', route, data, options);
    },
    post(route, data = {}, baseURL = null, options = {}) {
        return request(baseURL, 'POST', route, data, options);
    },
    delete(route, data = {}, baseURL = null) {
        return request(baseURL, 'DELETE', route, data);
    },
    put(route, data = {}, baseURL = null) {
        return request(baseURL, 'PUT', route, data);
    },
    patch(route, data = {}, baseURL = null) {
        return request(baseURL, 'PATCH', route, data);
    },
    upload(route, data = {}) {
        const url = `${window.fluentCartRestVars.rest.url}/${route}`;
        if (!isSafeRestUrl(url)) {
            return Promise.reject(invalidRoute());
        }
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

import Str from "@/utils/support/Str";
import Arr from "@/utils/support/Arr";
import {useRoute} from "vue-router";

/**
 * Utility class for URL manipulation
 */
export default class Url {
    /**
     * Appends query parameters to a URL
     * @param {string} url - The base URL to append parameters to
     * @param {Object} params - Key-value pairs of query parameters
     * @returns {string} The URL with appended query parameters
     * @example
     * Url.appendQueryParams('https://example.com', { page: 1, sort: 'desc' })
     * // returns 'https://example.com?page=1&sort=desc'
     */
    static appendQueryParams(url, params = {}) {
        const urlObject = new URL(url);
        Object.entries(params).forEach(([key, value]) => {
            if(value !== null && value !== undefined){
                urlObject.searchParams.append(key, value.toString());
            }
        });
        return urlObject.toString();
    }

    /**
     * Joins a base URL with a path and optional query parameters
     * @param {string} base - The base URL
     * @param {string} path - The path to append
     * @param {Object} params - Key-value pairs of query parameters
     * @returns {string} The joined URL with optional query parameters
     * @example
     * Url.join('https://example.com', 'api/users', { id: 123 })
     * // returns 'https://example.com/api/users?id=123'
     */
    static join(base, path, params = {}) {
        if (!Str.endsWith(base, '/')) {
            base = base + '/';
        }
        if (Str.startsWith(path, '/')) {
            path = path.substring(1);
        }
        return this.appendQueryParams(base + path, params);
    }

    /**
     * Adds a single query parameter to a URL
     * @param {string} url - The URL to add the parameter to
     * @param {string} key - The parameter key
     * @param {string|number} value - The parameter value
     * @returns {string} The URL with the added parameter
     * @example
     * Url.addParam('https://example.com', 'token', '123456')
     * // returns 'https://example.com?token=123456'
     */
    static addParam(url, key, value) {
        const urlObject = new URL(url);
        urlObject.searchParams.append(key, value);
        return urlObject.toString();
    }

    /**
     * Gets the value of a query parameter from a URL
     * @param {string} url - The URL to get the parameter from
     * @param {string} param - The parameter key to get
     * @returns {string|null} The parameter value or null if not found
     * @example
     * Url.getParam('https://example.com?token=123', 'token')
     * // returns '123'
     */
    static getParam(url, param) {

        const urlObject = new URL(url ?? window.location.href);
        return urlObject.searchParams.get(param);
    }

    /**
     * Removes a query parameter from a URL
     * @param {string} url - The URL to remove the parameter from
     * @param {string} param - The parameter key to remove
     * @returns {string} The URL with the parameter removed
     * @example
     * Url.removeParam('https://example.com?token=123&page=1', 'token')
     * // returns 'https://example.com?page=1'
     */
    static removeParam(url, param) {
        const urlObject = new URL(url);
        urlObject.searchParams.delete(param);
        return urlObject.toString();
    }

    /**
     * Replaces the current browser URL by merging query parameters inside the hash fragment.
     * Keeps existing query params and updates or adds new ones.
     *
     * @param {string|null} url - The full URL, or null to use window.location.href
     * @param {Object} [params={}] - Query params to merge inside the hash fragment
     */
    static pushToVueUrl(url = null, params = {}) {
        const newUrl = this.appendToVueUrl(url, params);
        if (newUrl) {
            window.history.replaceState({}, '', newUrl);
        }
    }


    static appendToVueUrl(url = null, params = {}) {
        if (typeof window === 'undefined') return;

        const fullUrl = url ?? window.location.href;
        const urlObject = new URL(fullUrl);
        const base = fullUrl.split('#')[0]; // everything before the hash
        const hash = urlObject.hash; // includes leading '#'

        if (!hash.includes('?')) {
            // No query in the hash, just append normally
            const newHash = hash.replace(/\/?$/, '/') + '?' + new URLSearchParams(params).toString();
            return `${base}${newHash}`;
        }

        // Split hash route and query: '#/products/?active_view=draft'
        const [routePath, queryString] = hash.slice(1).split('?'); // remove '#' and split

        const currentParams = new URLSearchParams(queryString || '');
        Object.entries(params).forEach(([key, value]) => {
            currentParams.set(key, value.toString());
        });

        const newHash = `#${routePath}?${currentParams.toString()}`;
        return `${base}${newHash}`;
    }


    static getAdminRoute(path = '') {
        return Arr.get(window, 'fluentCartAdminApp.admin_url') + path;
    }

    static getVueParams(key){
        const router = useRoute();
        return router?.query?.[key] ?? router?.query ?? null;
    }

}

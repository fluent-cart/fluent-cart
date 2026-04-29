// Usage examples:

// Basic usage with defaults
// const utm = new UTMManager();

// Custom configuration
// const utm = new UTMManager({
//   storageKey: 'my_utm_tracking',
//   expirationDays: 14,
//   utmKeys: ['utm_source', 'utm_medium', 'utm_campaign', 'custom_param']
// });

// Methods:
// utm.get() - Get all UTM parameters
// utm.getParam('utm_source') - Get specific parameter
// utm.clear() - Clear all stored data
// utm.setParam('utm_source', 'google') - Set single parameter
// utm.setParams({utm_source: 'google', utm_medium: 'cpc'}) - Set multiple
// utm.getExpirationInfo() - Get expiration details
// utm.setExpirationDays(60) - Update expiration period

export default class UTMManager {

    constructor(options = {}) {
        this.storageKey = options.storageKey || 'fc_utm_data';
        this.expirationDays = options.expirationDays || 30;
        this.utmKeys = options.utmKeys || UTMManager.getUtmParams();

        // Initialize and collect UTM parameters on instantiation
        this.collectFromURL();
    }

    /**
     * Collect UTM parameters from current URL and store them
     */
    collectFromURL() {
        const currentParams = this.getURLParams();
        if (document.referrer) {
            const refHost = new URL(document.referrer).hostname;
            if (refHost !== window.location.hostname) {
                currentParams['refer_url'] = document.referrer;
            }
        }

        if (Object.keys(currentParams).length > 0) {
            this.store(currentParams);
        }
    }

    /**
     * Get current URL parameters
     */
    getURLParams() {
        const urlParams = new URLSearchParams(window.location.search);
        const params = {};

        this.utmKeys.forEach(key => {
            const value = urlParams.get(key);
            if (value) {
                params[key] = value;
            }
        });

        return params;
    }


    /**
     * Store UTM parameters with timestamp
     */
    store(utmParams) {
        const existingData = this.getStoredData();
        const timestamp = Date.now();

        // Merge new parameters with existing ones
        const updatedParams = {
            ...existingData.params,
            ...utmParams
        };

        const dataToStore = {
            params: updatedParams,
            timestamp: timestamp
        };

        try {
            localStorage.setItem(this.storageKey, JSON.stringify(dataToStore));
        } catch (error) {
            //console.warn('UTMManager: Unable to store data in localStorage', error);
        }
    }

    /**
     * Get stored UTM data from localStorage
     */
    getStoredData() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            if (stored) {
                let result = JSON.parse(stored);
                if(result && result.timestamp) {
                    return result;
                }
            }
        } catch (error) {
            //console.warn('UTMManager: Unable to parse stored data', error);
        }

        return {params: {}, timestamp: null};
    }

    /**
     * Get UTM parameters
     */
    get() {
        const data = this.getStoredData();
        return data.params || {};
    }

    /**
     * Clear all stored UTM data
     */
    clear() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch (error) {
            console.warn('UTMManager: Unable to clear localStorage', error);
        }
    }

    static getUtmParams() {
        return window.fluentcart_utm_vars.allowed_keys || ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
    }
}

import Config from "@/utils/Config/Config";
import { reactive } from 'vue';

export default class AppConfig {
    static _data = reactive(window.fluentCartAdminApp || {});
    static #channel = null;
    static #shopUpdateCallbacks = [];

    static onShopUpdate(cb) {
        AppConfig.#shopUpdateCallbacks.push(cb);
    }

    static #notifyShopUpdate() {
        AppConfig.#shopUpdateCallbacks.forEach(cb => cb());
    }

    static {
        // PHP stores currency symbols as HTML entities (e.g. &euro;, &#36;).
        // Decode them once here so every JS consumer gets plain Unicode characters
        // regardless of whether it renders via v-html or plain text interpolation.
        AppConfig.#decodeCurrencySymbols(AppConfig._data);

        if (typeof BroadcastChannel !== 'undefined') {
            AppConfig.#channel = new BroadcastChannel('fluent-cart-config:' + window.location.origin);
            // Apply patches via Object.assign (not mergeConfig) to avoid re-broadcasting.
            // Incoming config is already serialized plain JSON, so decode currency symbols again.
            AppConfig.#channel.onmessage = function(event) {
                const msg = event.data;
                if (!msg) return;
                if (msg.type === 'merge' && msg.config) {
                    AppConfig.#decodeCurrencySymbols(msg.config);
                    Object.assign(AppConfig._data, msg.config);
                    if (msg.config.shop) {
                        AppConfig.#notifyShopUpdate();
                        window.dispatchEvent(new CustomEvent('fluentCartCurrencyChange'));
                    }
                } else if (msg.type === 'set' && msg.config) {
                    AppConfig.#decodeCurrencySymbols(msg.config);
                    const prevSign = AppConfig._data.shop && AppConfig._data.shop.currency_sign;
                    Object.keys(AppConfig._data).forEach(k => delete AppConfig._data[k]);
                    Object.assign(AppConfig._data, msg.config);
                    const newSign = AppConfig._data.shop && AppConfig._data.shop.currency_sign;
                    if (prevSign !== newSign) {
                        AppConfig.#notifyShopUpdate();
                        window.dispatchEvent(new CustomEvent('fluentCartCurrencyChange'));
                    }
                }
            };
        }
    }

    // Decode HTML entities in currency_sign and the currency_signs map in-place.
    // Uses a textarea so the browser handles all named and numeric entities correctly.
    static #decodeCurrencySymbols(data) {
        if (!data || typeof data !== 'object') return;
        const el = document.createElement('textarea');
        if (data.shop && data.shop.currency_sign) {
            el.innerHTML = data.shop.currency_sign;
            data.shop.currency_sign = el.value;
        }
        if (data.currency_signs && typeof data.currency_signs === 'object') {
            Object.keys(data.currency_signs).forEach(function(k) {
                el.innerHTML = data.currency_signs[k];
                data.currency_signs[k] = el.value;
            });
        }
    }

    static setConfig(config) {
        AppConfig.#decodeCurrencySymbols(config);
        Object.keys(AppConfig._data).forEach(k => delete AppConfig._data[k]);
        Object.assign(AppConfig._data, config);
        if (AppConfig.#channel) {
            try {
                AppConfig.#channel.postMessage({ type: 'set', config: JSON.parse(JSON.stringify(config)) });
            } catch(e) {
                // config not serializable, skip broadcast
            }
        }
    }

    static mergeConfig(config) {
        AppConfig.#decodeCurrencySymbols(config);
        Object.assign(AppConfig._data, config);
        if (AppConfig.#channel) {
            try {
                AppConfig.#channel.postMessage({ type: 'merge', config: JSON.parse(JSON.stringify(config)) });
            } catch(e) {
                // config not serializable, skip broadcast
            }
        }
    }

    static get(key = null, $default = null) {

        if (key === null) {
            return AppConfig._data;
        }

        return Config.form(AppConfig._data).get(key, $default)
    }

    static assetUrl(url = '') {
        return AppConfig.get('asset_url') + url
    }

    static get shop() {
        return Config.form(
            AppConfig.get('shop')
        );
    }

    static get config() {
        return Config.form(
            AppConfig.get('app_config')
        );
    }

}

window.fluentCartAdminApp = AppConfig._data;

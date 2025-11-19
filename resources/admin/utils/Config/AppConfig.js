import Config from "@/utils/Config/Config";

export default class AppConfig {
    static _data = window.fluentCartAdminApp;

    static setConfig(config) {
        AppConfig._data = config;
    }

    static mergeConfig(config) {
        AppConfig._data = {
            ...AppConfig._data,
            ...config,
        };
    }

    static get(key = null, $default = null) {

        if (key === null) {
            return AppConfig._data;
        }

        return Config.form(AppConfig._data).get(key)
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

import Str from "@/utils/support/Str";
import Arr from "@/utils/support/Arr";

const generator = (key) => {
    const version = Str.snake(
        Arr.get(window, 'fluentCartAdminApp.app_config.version', '1.0.0')
    );
    return 'fcart-' + version + '_' + key
};

export default class Storage {
    static get(key, defaultValue = '') {
        let value = localStorage.getItem(generator(key));

        if (value && ['{', '['].indexOf(value[0]) !== -1) {
            value = JSON.parse(value);
        } else if (value === "true") {
            return true;
        } else if (value === "false") {
            return false;
        }

        if (!value) {
            return defaultValue;
        }

        return value;
    }

    static set(key, value) {
        if (typeof value === 'object') {
            value = JSON.stringify(value);
        }

        localStorage.setItem(generator(key), value);
    }

    static remove(key) {
        localStorage.removeItem(generator(key));
    }

    static clear() {
        localStorage.clear();
    }

    static serverMaxUploadSize() {
        return Arr.get(window, 'fluentCartAdminApp.max_upload_size') || '';
    }

    static readableFileSizeFromBytes(size) {
        if (!size) return '';
        const i = Math.floor(Math.log(size) / Math.log(1024));
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        return (size / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
    }
}

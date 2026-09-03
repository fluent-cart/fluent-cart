import {ElNotification} from 'element-plus';
import translate from '../translator/Translator';

/**
 * Notification facade for the customer portal.
 *
 * Mirrors the admin `@/utils/Notify` contract so an add-on template written
 * against one loader behaves the same in the other, but resolves its strings
 * through the portal translator (the admin one reads a different localized
 * vars object and would silently return every string untranslated here).
 */
class PortalNotify {
    duration = 2500;
    offset = 30;

    success(message, title = null) {
        this.notify(message, title || translate('Success'), 'success');
    }

    error(message, title = null, duration = null) {
        this.notify(message, title || translate('Error'), 'error', duration ?? 3500);
    }

    info(message, title = null) {
        this.notify(message, title || translate('Information'), 'info');
    }

    notify(message, title, type = 'success', duration = null) {
        ElNotification({
            type: type,
            title: title,
            offset: this.offset,
            message: this.parseMessage(message),
            duration: duration ?? this.duration
        });
    }

    /**
     * Accepts a plain string, an axios-ish `{message}` payload, or a rejected
     * Rest response (`{data: {message}}`) and always returns something a human
     * can read.
     */
    parseMessage(data) {
        if (!data) {
            return translate('Something went wrong!');
        }

        if (typeof data === 'string') {
            return data;
        }

        if (data.message) {
            return data.message;
        }

        if (data.data && data.data.message) {
            return data.data.message;
        }

        return translate('Something went wrong!');
    }
}

const portalNotify = new PortalNotify();

export default portalNotify;

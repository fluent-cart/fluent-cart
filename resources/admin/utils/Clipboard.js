import {ElMessageBox, ElNotification} from 'element-plus';
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import Message from "@/utils/Message";

export default class Clipboard {
    static copyModern(text) {
        return navigator.clipboard.writeText(text);
    }

    static copyLegacy(text) {

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            textarea.remove();
            return Promise.resolve();
        } catch (err) {
            textarea.remove();
            return Promise.reject(new Error(
                translate('Failed to copy text')
            ));
        }
    }

    static async copy(text, options = {}) {
        const defaultOptions = {
            showNotification: true,
            duration: 2000
        };

        const settings = {...defaultOptions, ...options};
        settings['successMessage'] = settings['successMessage'] || translate('Copied to clipboard');
        settings['errorMessage'] = settings['successMessage'] || translate('Copied to clipboard');

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await this.copyModern(text);
            } else {
                await this.copyLegacy(text);
            }

            if (settings.showNotification) {
                Notify.success(
                    settings.successMessage,
                )
            }

            return true;
        } catch (err) {
            if (settings.showNotification) {
                ElMessageBox.alert(text, translate('Please copy this text: '), {
                    confirmButtonText: translate('Close'),
                })
            }
            return false;
        }
    }

    static copyElement(element, options = {}) {
        if (!element) {
            throw new Error(translate('Element not provided'));
        }
        const text = element.textContent || element.value || '';
        return this.copy(text, options);
    }
}

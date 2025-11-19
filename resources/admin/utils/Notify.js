import {ElNotification} from "element-plus";
import Str from "@/utils/support/Str";
import translate from "@/utils/translator/Translator";

class Notify {
    $notify = ElNotification;
    duration = 1500;
    offset = 30;

    success(message, title = 'Success') {
        this.notify(message, title, 'success')
    }

    error(message, title = 'Error', duration = null) {
        this.notify(message, title, 'error', null, duration??2000)
    }

    info(message, title = 'Information') {
        this.notify(message, title, 'info')
    }

    notify(message, title = 'Success', type = 'success', offset = null, duration = null) {

        message = this.parseMessage(message)
        this.$notify({
            type: type,
            title: title,
            offset: offset ?? this.offset,
            message: message,
            dangerouslyUseHTMLString: true,
            duration: duration ?? this.duration
        });
    }

    parseMessage(data) {

        if (!data) {
            return translate('Something went wrong!');
        }

        if (typeof data === 'string') {
            return data;
        }

        if (data && data.message) {
            return data.message;
        } else {
            return Str.objectToString(data);
        }
    }

    validationErrors(errors)
    {
        if (!errors) {
            return;
        }
        if (errors && errors.data?.message) {
            return this.error(errors.data?.message);
        }
        if (errors.data && typeof errors.data === 'object') {
            let message = '';
            for (const fieldKey in errors.data) {
                const errorData = errors.data[fieldKey];
                const firstError = Object.values(errorData);
                message += firstError + ' </br>';
            }
            this.error(message);
        }
    }
}

const notify = new Notify();
export default notify;

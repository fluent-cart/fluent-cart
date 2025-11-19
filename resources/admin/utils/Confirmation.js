import {ElMessageBox} from "element-plus";
import translate from "@/utils/translator/Translator";
import Clipboard from "@/utils/Clipboard";

class Confirmation {
    confirmBox = ElMessageBox.confirm;
    promptBox = ElMessageBox.prompt;

    confirm(message, title, options) {
        return this.confirmBox(
            message,
            title,
            options
        );
    }

    ofDelete(
        message = null,
        title = null,
        options) {
        if (message === null) {
            message = translate('Are you sure you want to delete this?. This action is not recoverable')
        }

        if (title === null) {
            title = translate('Confirm Delete!')
        }
        options = {
            ...{
                confirmButtonText: translate('Yes, Delete!'),
                cancelButtonText: translate('Cancel'),
                confirmButtonClass: 'el-button--primary',
                type: 'warning'
            },
            ...options ?? {}
        }
        return this.confirm(message, title, options);
    }

    confirmWithInput(message, title, options) {
        const prompts = this.promptBox(
            message,
            title,
            options
        );

        return prompts;
    }

    confirmDeleteWithInput(
        requiredText,
        message = null,
        title = null,
        options) {
        if (message === null) {
            message = translate('Are you sure you want to delete this?. This action is not recoverable')
        }

        /* translators: %s is the required text */
        const content = translate('You must type "%s" exactly to proceed',
            `<span >${requiredText}</span>`);
        message = `<label for=""><p>${message}</p>
        <p>${content}</p></label>`;

        if (title === null) {
            title = translate('Confirm Delete!')
        }
        options = {
            ...{
                confirmButtonText: translate('Yes, Delete!'),
                cancelButtonText: translate('Cancel'),
                confirmButtonClass: 'el-button--primary',
                inputPattern: new RegExp(`^${requiredText}$`),
                /* translators: %s is the required text */
                inputErrorMessage: translate('You must type %s exactly to proceed', requiredText),
                dangerouslyUseHTMLString: true,
                type: 'warning'
            },
            ...options ?? {}
        }
        return this.confirmWithInput(message, title, options);
    }
}

const confirmation = new Confirmation();
export default confirmation;

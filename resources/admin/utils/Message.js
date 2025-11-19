import {ElMessage, ElMessageBox} from "element-plus";
import { getCurrentInstance } from 'vue'
import {h} from "vue";
import Features from "@/Bits/Components/CTA/Features.vue";
import translate from "@/utils/translator/Translator";

class Message {
    $message = ElMessage;
    duration = 1000;

    success(message) {
        this.show(message, 'success',)
    }

    error(message) {
        this.show(message, 'error')
    }

    info(message) {
        this.show(message, 'info')
    }

    show(message, type = 'success', offset = 40, duration = 1000) {
        this.$message({
            type: type,
            offset: offset,
            message: message,
            dangerouslyUseHTMLString: true,
            duration: duration ?? this.duration
        });
    }


    showFeaturesCTA(featureName, featureDescription, features, vueInstance){

        let appContext = null;
        if(vueInstance){
            appContext = vueInstance.appContext;
        }
        const instance = ElMessageBox({
            title: translate('Pro Feature'),
            message: h(
                Features, {
                    featureName,
                    featureDescription,
                    features,
                }),
            customClass: 'fluent-cart-pro-cta',
            showCancelButton: false,
            showConfirmButton: false,
            showFooter: false,
            showClose: true,
            //closeOnClickModal: false, // prevent closing when clicking overlay
            //closeOnPressEscape: true // prevent ESC closing (optional)
        },appContext)
    }


}

const message = new Message();
export default message;

import Model from "@/utils/model/Model";
import {useFormModel} from "@/utils/model/form/FormModel";
import {getCurrentInstance, onMounted} from "vue";

class SettingsModel extends Model {

    get controllerKey() {
        return 'Customer';
    }

    data = {
        form: useFormModel(),
    };
}

export function useSettingsModel() {
    return SettingsModel.init();
}

import Model from "@/utils/model/Model";
import formModel from "@/utils/model/form/FormModel";
import {getCurrentInstance, onMounted} from "vue";

class DynamicFormModel extends Model {



    data = {
        form: formModel,
    };
}

export function useDynamicFormModel() {
    return DynamicFormModel.init();
}

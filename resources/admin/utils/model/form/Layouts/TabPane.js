import BaseInput from "@/utils/model/form/Input/BaseInput";
import ConditionBuilder from "@/utils/model/form/Condition/Builder/ConditionBuilder";
import BaseLayout from "@/utils/model/form/Layouts/BaseLayout";

export default class TabPane extends BaseLayout {

    constructor(key) {
        super(key);
        this._type = 'tab-pane';
    }

}
import BaseLayout from "@/utils/model/form/Layouts/BaseLayout";
import TabPane from "@/utils/model/form/Layouts/TabPane";

export default class TabLayout extends BaseLayout {

    constructor(key) {
        super(key);
        this._type = 'tab';
    }

    set schema(schema) {
        if (Array.isArray(schema)) {
            schema.forEach((scheme) => {
                if (!scheme instanceof TabPane) {
                    throw new TypeError("Schema of Tab must be the instance of TabPane");
                }
            })
            this._schema = schema;
        } else {
            throw new TypeError("Schema must be an array.");
        }
    }

}
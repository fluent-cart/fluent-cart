import BaseLayout from "@/utils/model/form/Layouts/BaseLayout";
import HasColumns from "@/utils/model/form/Contract/HasColumns";
import Mixin from "@/utils/Mixin";


/**
 * Class representing a grid layout.
 * @extends BaseLayout
 * @mixes HasColumns
 */

export default class GridLayout extends BaseLayout {

    constructor(key) {
        super(key);
        this._type = 'grid';
        Mixin.install(this, HasColumns);
    }

    build() {
        return {
            ...super.build(),
            columns: this._columns
        }
    }

}
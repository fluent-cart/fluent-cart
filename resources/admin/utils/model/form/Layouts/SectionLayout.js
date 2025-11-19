import BaseLayout from "@/utils/model/form/Layouts/BaseLayout";
import HasColumns from "@/utils/model/form/Contract/HasColumns";
import Mixin from "@/utils/Mixin";

/**
 * Class representing a grid layout.
 * @extends BaseLayout
 * @mixes HasColumns
 */

export default class SectionLayout extends BaseLayout {
    constructor(key) {
        super(key);
        this._type = 'section';
        Mixin.install(this, HasColumns);
        this._title = '';
    }

    title(title) {
        this._title = title;
        return this;
    }

    build() {
        return {
            ...super.build(),
            title: this._title,
            columns: this._columns
        };
    }
}
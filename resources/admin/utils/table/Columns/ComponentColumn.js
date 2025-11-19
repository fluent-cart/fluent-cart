import TableColumn from "@/utils/table/Columns/TableColumn";
import {formatDate} from "@/Bits/common";

/**
 * Class representing a table column configuration.
 */
export default class ComponentColumn extends TableColumn {
    _component;

    constructor(key) {
        super(key);
    }

    /**
     * Set a custom component for the column.
     * @param {String} component - The componentPath  inside Bits/Components/Table/Components to render in the column.
     * @returns {this}
     */
    component(component) {
        this._component = component;
        return this;
    }

    build() {
        return {
            ...super.build(),
            component: this._component
        };
    }


}

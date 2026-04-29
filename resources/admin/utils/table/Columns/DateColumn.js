import TableColumn from "@/utils/table/Columns/TableColumn";
import {formatDate} from "@/Bits/common";

/**
 * Class representing a table column configuration.
 */
export default class DateColumn extends TableColumn {
    constructor(key) {
        super(key);
        this.formatUsing = this.dateFormatter.bind(this)
    }

    dateFormatter(row) {
        return formatDate(row[this._accessor ?? this._key]);
    }
}

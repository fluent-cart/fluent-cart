import translate from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";

/**
 * Class representing a table column configuration.
 */
export default class TableColumn {

    _title;
    _visible = true;
    _accessor;
    formatUsing;
    cellAttribute;
    slotAttribute;
    _sortableKey;
    _toggleable;


    /**
     * Initialize a TableColumn instance with default values.
     */
    constructor(key) {
        if (typeof key !== 'string' || key.length < 1) {
            throw new Error('Key is Required');
        }
        this._key = key;
        this._sortable = true;
        this._toggleable = true;
    }

    /**
     * Static method to create a new instance of the class.
     * @return {TableColumn|DateColumn|ComponentColumn} A new instance of BaseInput.
     */
    static make(key) {
        return new this(key);
    }

    /**
     * Set title of the column.
     * @param {String} title - Title Of The Column.
     * @returns {TableColumn}
     */
    title(title) {
        this._title = title;
        return this;
    }

    /**
     * Set if the column is sortable.
     * @param {Boolean} sortable - set it the column is sortable.
     * @returns {TableColumn}
     */
    sortable(sortable= true) {
        this._sortable = sortable;
        return this;
    }


    /**
     * Set if the column is toggleable mean its visibility can be managed.
     * @param {Boolean} toggleable - set it the column is toggleable.
     * @returns {TableColumn}
     */
    toggleable(toggleable= true) {
        this._toggleable = toggleable;
        return this;
    }


    /**
     * Set if the key, which will be used to sort
     * @param {String} sortableKey - set it the sortable key.
     * @returns {TableColumn}
     */
    sortableKey(sortableKey) {
        this._sortableKey = sortableKey;
        return this;
    }


    /**
     * Set visibility of the column.
     * @param {boolean} visible - Visibility flag for the column.
     * @returns {TableColumn}
     */
    visible(visible) {
        this._visible = visible;
        return this;
    }

    /**
     * Set the accessor for the column data.
     * @param {string} accessor - The data field accessor.
     * @returns {TableColumn}
     */
    accessor(accessor) {
        this._accessor = accessor;
        return this;
    }

    /**
     * Set a formatter function for the column.
     * @param {Function} formatter - Function to format the column data.
     * @returns {TableColumn}
     */
    formatter(formatter) {
        this.formatUsing = formatter;
        return this;
    }

    /**
     * Set attributes for the table cell.
     * @param {Object} cellAttribute - Attributes for the cell element.
     * @returns {TableColumn}
     */
    setCellAttribute(cellAttribute) {
        if (typeof cellAttribute !== 'object' && cellAttribute !== null) {
            console.warn('Cell attr must be an object');
            return this;
        }
        this.cellAttribute = cellAttribute;
        return this;
    }

    /**
     * Set attributes for the slot.
     * @param {Object} slotAttribute - Attributes for the slot element.
     * @returns {TableColumn}
     */
    setSlotAttribute(slotAttribute) {
        if (typeof slotAttribute !== 'object' && slotAttribute !== null) {
            console.warn('Cell attr must be an object');
            return this;
        }
        this.slotAttribute = slotAttribute;
        return this;
    }


    minWidth(width) {
        if (typeof this.cellAttribute !== "object") {
            this.cellAttribute = {};
        }
        this.cellAttribute['min-width'] = width;

        return this;
    }

    build() {

        // title;
        // visible;
        // accessor;
        // formatter;
        // cellAttribute;
        // slotAttribute;

        //Convert the key from customer.email to customer_email
        const key = Str.snake(this._key);

        const column = {
            key: key,
            title: this._title ?? translate(Str.headline(key)),
            visible: this._visible,
            accessor: this._accessor ?? this._key,
            sortable: this._sortable,
            sortableKey: this._sortableKey ?? key,
            toggleable: this._toggleable,
            attr: {}
        };

        if (this.formatUsing) {
            column['formatter'] = this.formatUsing;
        }


        if (typeof this.cellAttribute === 'object' || typeof this.slotAttribute === 'object') {
            column['attr'] = {};
        }

        column['attr']['cell'] = {};
        if (typeof this.cellAttribute === 'object') {
            column['attr']['cell'] = this.cellAttribute
        }

        if (this._sortable) {
            column['attr']['cell']['prop'] = column.sortableKey;
            //column['attr']['cell']['sortable'] = 'custom';
        }

        if (typeof this.slotAttribute === 'object') {
            column['attr']['slot'] = this.slotAttribute
        }

        return column;
    }

    static buildColumnsFromData(data) {
        return Object.keys(data).map((key) => {
            return TableColumn.make(key)
        })
    }

    static buildColumnsFromSchema(schema = []) {
        return schema.map((scheme) => {
            if (typeof scheme === "object") {
                const col = TableColumn.make(scheme.key);
                for (let key in scheme) {
                    if (typeof col[key] === 'function') {
                        col[key](scheme[key]);
                    }
                }
                return col;
            }
            return TableColumn.make(scheme)
        });

    }
}

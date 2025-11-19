import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable HTML select element.
 * Extends BaseInput to include an options list.
 */
export default class SelectInput extends BaseInput {
    /**
     * Creates an instance of SelectInput.
     */
    constructor(key) {
        super(key, 'select');
        this._options = [];
        this._getOptionsUsing = null;
        this._searchable = false;
        this._filterable = true;
        this._clearable = true;
        this._multiple = true;
    }

    /**
     * Static method to create a new instance of the class.
     * @return {SelectInput} A new instance of SelectInput.
     */
    static make(key, type) {
        return new this(key);
    }


    searchable(searchable = true) {
        this._searchable = searchable;
        return this;
    }

    clearable(clearable = true) {
        this._clearable = clearable;
        return this;
    }

    multiple(multiple = true) {
        this._multiple = multiple;
        return this;
    }

    filterable(filterable = true) {
        this._filterable = filterable;
        return this;
    }

    /**
     * Sets the options for the select input.
     * @param {Array<{ label: string, value: string }>} options - Array of option objects.
     * @returns {SelectInput} The current instance for chaining.
     */
    options(options) {
        this._options = options;
        return this;
    }

    getOptionsUsing(callable) {
        this._getOptionsUsing = callable
        return this;
    }

    selected(value) {
        this.value(value)
        return this;
    }

    /**
     * Renders the select element as an object with properties and callbacks, including options.
     * @returns {Object} Object containing the select element properties, callbacks, and options.
     */
    build() {
        let built = super.build();
        built.attributes['remote'] = this._searchable;
        built.attributes['filterable'] = this._filterable;
        built.attributes['clearable'] = this._clearable;
        built.attributes['multiple'] = this._multiple;

        return {
            options: this._options,
            getOptionsUsing: this._getOptionsUsing,
            ...built,
        };
    }
}

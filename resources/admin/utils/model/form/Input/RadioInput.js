import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable HTML radio group.
 * Extends BaseInput to include options for radio buttons.
 */
export default class RadioInput extends BaseInput {
    /**
     * Creates an instance of RadioInput.
     */
    constructor(key) {
        super(key, 'radio');
        this._options = [];
    }

    /**
     * Static method to create a new instance of the class.
     * @return {RadioInput} A new instance of Radio.
     */
    static make(key, type) {
        return new this(key);
    }

    /**
     * Sets the options for the radio input.
     * @param {Array<{ label: string, value: string }> | Function} options - Array of option objects.
     * @returns {RadioInput} The current instance for chaining.
     */
    options(options) {
        this._options = options;
        return this;
    }

    /**
     * Sets the selected value for the radio input.
     * @param {string} value - The selected value.
     * @returns {RadioInput} The current instance for chaining.
     */
    selected(value) {
        this.value(value);
        return this;
    }

    /**
     * Renders the radio group as an object with properties, callbacks, and options.
     * @returns {Object} Object containing the radio group properties, callbacks, and options.
     */
    build() {
        return {
            ...super.build(),
            options: this._options
        };
    }
}

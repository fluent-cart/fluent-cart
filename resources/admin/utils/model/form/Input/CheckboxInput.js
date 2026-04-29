import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable HTML checkbox input.
 * Extends BaseInput to include true and false values.
 */
export default class CheckboxInput extends BaseInput {
    /**
     * Creates an instance of CheckboxInput with default true and false values.
     */
    constructor(key) {
        super(key, 'checkbox');
        //this._type = 'checkbox';
        this._trueValue = 'yes';
        this._falseValue = 'no';
        this._value = this._falseValue;
    }

    /**
     * Static method to create a new instance of the class.
     * @return {CheckboxInput} A new instance of CheckboxInput.
     */
    static make(key, type) {
        return new this(key);
    }

    /**
     * Sets the true value for the checkbox.
     * @param {string} value - The value when the checkbox is checked.
     *  @returns {this}  The current instance for chaining.
     */
    trueValue(value) {
        this._trueValue = value;
        return this;
    }

    /**
     * Sets the false value for the checkbox.
     * @param {string} value - The value when the checkbox is unchecked.
     *  @returns {this}  The current instance for chaining.
     */
    falseValue(value) {
        this._falseValue = value;
        return this;
    }

    /**
     * Renders the checkbox input as an object with properties, callbacks, and true/false values.
     * @returns {Object} Object containing the checkbox input properties, callbacks, and true/false values.
     */
    build() {
        let built = super.build();
        built.attributes['true-value'] = this._trueValue;
        built.attributes['false-value'] = this._falseValue;

        return built;
    }
}

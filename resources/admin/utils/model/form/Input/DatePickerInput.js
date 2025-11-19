import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable date picker input.
 * Extends BaseInput to include date-specific properties.
 */
export default class DatePickerInput extends BaseInput {
    /**
     * Creates an instance of DatePickerInput with default values.
     */
    constructor(key) {
        super(key, 'date');
        this._dateFormat = 'YYYY-MM-DD';
        this._valueFormat = 'YYYY-MM-DD';
        this._disabledDate;
    }

    /**
     * Static method to create a new instance of the class.
     * @return {DatePickerInput} A new instance of DatePicker.
     */
    static make(key, type) {
        return new this(key);
    }

    /**
     * Sets the date format.
     * @param {string} format - The date display format (e.g., 'yyyy-MM-dd').
     * @returns {DatePickerInput} The current instance for chaining.
     */
    dateFormat(format) {
        this._dateFormat = format;
        return this;
    }

    /**
     * Builds the date picker input as an object with properties and callbacks.
     * @returns {Object} Object containing the date picker input properties, callbacks, and date-specific configurations.
     */
    build() {
        let built = super.build();
        built.attributes['date-format'] = this._dateFormat;
        built.attributes['value-format'] = this._valueFormat;
        built.attributes['disabledDate'];
        return built;
    }
}

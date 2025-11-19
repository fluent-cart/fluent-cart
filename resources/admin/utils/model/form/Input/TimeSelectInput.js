import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable time select input.
 * Extends BaseInput to include time-specific properties.
 */
export default class TimeSelectInput extends BaseInput {
    /**
     * Creates an instance of TimeSelectInput with default values.
     */
    constructor(key) {
        super(key);
        this._type = 'time';
        this._start = '';
        this._step = '';
        this._end = '';
        this._format = 'HH:mm';
    }

    /**
     * Static method to create a new instance of the class.
     * @return {TimeSelectInput} A new instance of TimeSelectInput.
     */
    static make(key, type) {
        return new this(key);
    }

    /**
     * Sets the start time.
     * @param {string} start - The starting time (e.g., '09:00').
     * @returns {TimeSelectInput} The current instance for chaining.
     */
    start(start) {
        this._start = start;
        return this;
    }

    /**
     * Sets the time step interval.
     * @param {string} step - The time interval step (e.g., '00:30').
     * @returns {TimeSelectInput} The current instance for chaining.
     */
    step(step) {
        this._step = step;
        return this;
    }

    /**
     * Sets the end time.
     * @param {string} end - The ending time (e.g., '18:00').
     * @returns {TimeSelectInput} The current instance for chaining.
     */
    end(end) {
        this._end = end;
        return this;
    }

    /**
     * Sets the time format.
     * @param {string} format - The time display format (e.g., 'HH:mm').
     * @returns {TimeSelectInput} The current instance for chaining.
     */
    format(format) {
        this._format = format;
        return this;
    }

    /**
     * Builds the time select input as an object with properties and callbacks.
     * @returns {Object} Object containing the time select input properties, callbacks, and time-specific configurations.
     */
    build() {

        let built = super.build();
        built.attributes['start'] = this._start;
        built.attributes['step'] = this._step;
        built.attributes['end'] = this._end;
        built.attributes['format'] = this._format;
        return built;
    }
}

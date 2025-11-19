import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable switch input.
 * Extends BaseInput to include switch-specific properties.
 */
export default class SwitchInput extends BaseInput {
    /**
     * Creates an instance of SwitchInput with default active and inactive values.
     */
    constructor(key) {
        super(key, 'switch');
        this._activeValue = 'yes';
        this._inactiveValue = 'no';
    }

    /**
     * Static method to create a new instance of the class.
     * @return {SwitchInput} A new instance of SwitchInput.
     */
    static make(key, type) {
        return new this(key);
    }

    /**
     * Sets the active value for the switch.
     * @param {string} value - The value when the switch is active.
     * @returns {SwitchInput} The current instance for chaining.
     */
    activeValue(value) {
        this._activeValue = value;
        return this;
    }

    /**
     * Sets the inactive value for the switch.
     * @param {string} value - The value when the switch is inactive.
     * @returns {SwitchInput} The current instance for chaining.
     */
    inactiveValue(value) {
        this._inactiveValue = value;
        return this;
    }

    /**
     * Builds the switch input as an object with properties and callbacks.
     * @returns {Object} Object containing the switch input properties, callbacks, and switch-specific configurations.
     */
    build() {
        return {
            ...super.build(),
            activeValue: this._activeValue,
            inactiveValue: this._inactiveValue
        };
    }
}

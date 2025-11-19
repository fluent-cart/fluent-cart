import BaseInput from "@/utils/model/form/Input/BaseInput";

/**
 * Class representing a customizable media input.
 * Extends BaseInput to include media-specific properties.
 */
export default class MediaInput extends BaseInput {
    /**
     * Creates an instance of MediaInput with default values.
     */
    constructor(key) {
        super(key, 'media');
        this._multiple = false;
        this._mediaInputSize = 'medium';
        this._mediaInputBg = 'white';
        this._showSupported = false;
        this._title = '';
    }

    /**
     * Static method to create a new instance of the class.
     * @return {MediaInput} A new instance of MediaInput.
     */
    static make(key, type) {
        return new this(key);
    }

    /**
     * Sets whether multiple files can be selected.
     * @param {boolean} isMultiple - Enables multiple file selection if true.
     * @returns {MediaInput} The current instance for chaining.
     */
    multiple(isMultiple = true) {
        this._multiple = isMultiple;
        return this;
    }

    /**
     * Sets the title of the media input.
     * @param {string} title - Title for the media input.
     * @returns {MediaInput} The current instance for chaining.
     */
    title(title) {
        this._title = title;
        return this;
    }

    /**
     * Sets the size of the media input.
     * @param {string} size - The size of the media input (e.g., 'small', 'medium', 'large').
     * @returns {MediaInput} The current instance for chaining.
     */
    mediaInputSize(size) {
        this._mediaInputSize = size;
        return this;
    }

    /**
     * Sets the background color of the media input.
     * @param {string} color - The background color of the media input.
     * @returns {MediaInput} The current instance for chaining.
     */
    mediaInputBg(color) {
        this._mediaInputBg = color;
        return this;
    }

    /**
     * Sets whether to show supported file types.
     * @param {boolean} show - Show supported file types if true.
     * @returns {MediaInput} The current instance for chaining.
     */
    showSupported(show) {
        this._showSupported = show;
        return this;
    }

    /**
     * Builds the media input as an object with properties and callbacks.
     * @returns {Object} Object containing the media input properties, callbacks, and media-specific configurations.
     */
    build() {

        let built = super.build();
        built.attributes['multiple'] = this._multiple;
        built.attributes['title'] = this._multiple;
        built.attributes['mediaInputSize'] = this._mediaInputSize;
        built.attributes['mediaInputBg'] = this._mediaInputBg;
        built.attributes['showSupported'] = this._showSupported;
        return built;

    }
}

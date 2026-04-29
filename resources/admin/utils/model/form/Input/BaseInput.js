import ConditionBuilder from "@/utils/model/form/Condition/Builder/ConditionBuilder";
import Mixin from "@/utils/Mixin";
import Conditionable from "@/utils/model/form/Contract/Conditionable";
import Str from "@/utils/support/Str";
import translate from "@/utils/translator/Translator";
import HasEvents from "@/utils/model/form/Contract/HasEvents";
import HasAttributes from "@/utils/Contract/HasAttributes";
import Affix from "@/utils/model/form/utils/Affix";

/**
 * Class representing a grid layout.
 * @mixes Conditionable
 * @mixes HasEvents
 * @mixes HasAttributes
 */

export default class BaseInput {

    constructor(key, type = 'input') {

        if (typeof key !== 'string' || key.length < 1) {
            throw new Error('Key is Required');
        }

        this._key = key;
        this._type = type;
        this._class = '';
        this._id = '';
        this._name = '';
        this._label = '';
        this._value = '';
        this._placeholder = '';
        this._min = '';
        this._max = '';

        this._prefix = '';
        this._hasPrefix = false;
        this._hasSuffix = false;
        this._suffix = '';
        Mixin.install(this, HasAttributes);
        Mixin.install(this, HasEvents);
        Mixin.install(this, Conditionable);
        this._wrapperClass = '';
    }


    /**
     * Static method to create a new instance of the class.
     * @return {BaseInput} A new instance of BaseInput.
     */
    static make(key, type = 'input') {
        return new this(key, type);
    }

    /**
     * Sets the parent class.
     * @param {string} wrapperClass - The Parent Class (e.g., 'col-span-full', 'email').
     *  @returns {this}  The current instance for chaining.
     */
    wrapperClass(wrapperClass) {
        this._wrapperClass = wrapperClass;
        return this;
    }

    /**
     * Sets the input type.
     * @param {string} type - The input type (e.g., 'text', 'email').
     *  @returns {this}  The current instance for chaining.
     */
    type(type) {
        this._type = type;
        return this;
    }

    /**
     * Sets the input's CSS class.
     * @param {string} className - The CSS class name.
     *  @returns {this}  The current instance for chaining.
     */
    class(className) {
        this._class = className;
        return this;
    }

    /**
     * Sets the input's ID.
     * @param {string} id - The ID of the input.
     *  @returns {this}  The current instance for chaining.
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Sets the input's name attribute.
     * @param {string} name - The name of the input.
     *  @returns {this}  The current instance for chaining.
     */
    name(name) {
        this._name = name;
        return this;
    }

    /**
     * Sets the input's label attribute.
     * @param {string,Function} label - The label of the input.
     * @returns {this}  The current instance for chaining.
     */
    label(label) {
        this._label = label;
        return this;
    }

    /**
     * Sets the input's value.
     * @param {string} value - The value of the input.
     *  @returns {this}  The current instance for chaining.
     */
    value(value) {
        this._value = value;
        return this;
    }

    /**
     * Sets the input's placeholder text.
     * @param {string} placeholder - The placeholder text.
     *  @returns {this}  The current instance for chaining.
     */
    placeholder(placeholder) {
        this._placeholder = placeholder;
        return this;
    }

    /**
     * Sets the input's min attribute.
     * @param {int} min - The min of the input.
     *  @returns {this}  The current instance for chaining.
     */
    min(min) {
        this._min = min;
        return this;
    }

    /**
     * Sets the input's max attribute.
     * @param {int,Function} max - The max of the input.
     *  @returns {this}  The current instance for chaining.
     */
    max(max) {
        this._max = max;
        return this;
    }


    prefix(prefix) {
        this._hasPrefix = true;
        this._prefix = prefix;
        return this;
    }

    suffix(suffix) {
        this._hasSuffix = true;
        this._suffix = suffix;
    }

    /**
     * Sets a callback for the change event.
     * @param {function} callback - The function to call on change.
     *  @returns {this}  The current instance for chaining.
     */
    onChange(callback) {
        this._onChangeCallback = callback;
        return this;
    }

    /**
     * Sets a callback for the key press event.
     * @param {function} callback - The function to call on key press.
     *  @returns {this}  The current instance for chaining.
     */
    onKeyPress(callback) {
        this._onKeyPressCallback = callback;
        return this;
    }

    /**
     * Sets a callback for the key up event.
     * @param {function} callback - The function to call on key up.
     *  @returns {this}  The current instance for chaining.
     */
    onKeyUp(callback) {
        this._onKeyUpCallback = callback;
        return this;
    }

    /**
     * Sets a callback for the key down event.
     * @param {function} callback - The function to call on key down.
     *  @returns {this}  The current instance for chaining.
     */
    onKeyDown(callback) {
        this._onKeyDownCallback = callback;
        return this;
    }

    /**
     * Builds the input object with configured properties.
     * @returns {Object} The built input object.
     */
    build() {
        let buildConditions = this._conditions;
        if (Array.isArray(this._conditions) && this._conditions.length > 0) {
            buildConditions = this._conditions.map((condition) => {
                if (condition instanceof ConditionBuilder) {
                    return condition.build.bind(condition)();
                }
                return condition;
            });
        }

        this._attributes = typeof this._attributes === 'object' ? this._attributes : {};
        this._attributes['min'] = this._min;
        this._attributes['max'] = this._max;
        this._attributes['placeholder'] = this._placeholder;
        this._attributes['type'] = this._type;

        return {
            key: this._key,
            type: this._type,
            class: this._class,
            id: this._id,
            name: this._name ?? translate(Str.headline(this._key)),
            label: this._label ?? translate(Str.headline(this._key)),
            value: this._value,
            condition_type: this._conditionType,
            conditions: this.buildConditions(this._conditions),
            hasSuffix: this._hasSuffix,
            hasPrefix: this._hasPrefix,
            suffix: this._suffix instanceof Affix ? this._suffix.build() : this._suffix,
            prefix: this._prefix instanceof Affix ? this._prefix.build() : this._prefix,
            min: this._min,
            max: this._max,
            placeholder: this._placeholder,
            attributes: this._attributes,
            events: this._events,
            wrapperClass: this._wrapperClass
        };
    }

}

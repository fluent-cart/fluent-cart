export default class HasAttributes {

    _attributes = {};

    /**
     * Sets an additional attribute for the input.
     * @param {string} attr - The attribute name.
     * @param {string|number} value - The attribute value.
     * @returns {this}  The current instance for chaining.
     */
    attribute(attr, value) {
        this._attributes[attr] = value;
        return this;
    }

    setAttribute(attributes) {
        this._attributes = (typeof attributes === 'object') ? attributes : this._attributes;
        return this;
    }
}
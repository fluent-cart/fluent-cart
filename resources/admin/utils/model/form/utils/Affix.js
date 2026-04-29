export default class Affix {

    constructor(affix = '') {
        this._component = '';
        this._affix = affix;
    }

    static component(componentPath) {
        const instance = new Affix();
        instance._component = 'Affix/' + componentPath;
        return instance;
    }


    /**
     * Static method to create a new instance of the class.
     * @param {string,Function} prefix - The label of the input.
     * @returns {Affix}  The current instance for chaining.
     */

    static make(prefix = '') {
        return new this(prefix);
    }

    /**
     * Builds the input object with configured properties.
     * @returns {Object} The built input object.
     */
    build() {
        return this._component.length > 0 ?
            {
                component: this._component
            } : this._affix;
    }

}

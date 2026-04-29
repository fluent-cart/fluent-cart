import Mixin from "@/utils/Mixin";
import Conditionable from "@/utils/model/form/Contract/Conditionable";
import SchemaBuilder from "@/utils/model/form/utils/SchemaBuilder";
import HasEvents from "@/utils/model/form/Contract/HasEvents";
import HasAttributes from "@/utils/Contract/HasAttributes";

/**
 * Class representing a grid layout.
 * @mixes HasAttributes
 * @mixes Conditionable
 * @mixes HasEvents
 */
export default class BaseLayout {
    constructor(key) {
        if (typeof key !== 'string' || key.length < 1) {
            throw new Error('Key is Required');
        }

        this._type = 'section';
        this._key = key;
        this._schema = [];
        this._class = '';
        this.disable_nesting = true;
        Mixin.install(this, HasAttributes);
        Mixin.install(this, HasEvents);
        Mixin.install(this, Conditionable);
    }

    /**
     * Static method to create a new instance of the class.
     * @return {BaseLayout|SectionLayout|GridLayout|TabLayout|TabPane} A new instance.
     */
    static make(key) {
        return new this(key);
    }

    enableNesting() {
        this.disable_nesting = false;
        return this;
    }

    disableNesting() {
        this.disable_nesting = true;
        return this;
    }

    /**
     * Sets the input's CSS class.
     * @param {string} className - The CSS class name.
     * @returns {BaseLayout} The current instance for chaining.
     */
    class(className) {
        this._class = className;
        return this;
    }

    /**
     * Gets the layout type.
     * @returns {string} - The layout type.
     */
    get type() {
        return this._type;
    }

    /**
     * Sets the layout type.
     * @param {string} type - The layout type to set.
     */
    set type(type) {
        if (typeof type === 'string') {
            this._type = type;
        } else {
            throw new TypeError("Type must be a string.");
        }
    }

    /**
     * Gets the layout schema.
     * @returns {Array} - The layout schema array.
     */
    get schema() {
        return this._schema;
    }

    /**
     * Sets the layout schema.
     * @param {Object} schema - The schema array to set.
     */
    setSchema(schema) {
        this._schema = schema;
        return this;
    }

    /**
     * Builds the layout by iterating over the schema.
     * If an element is an instance of BaseInput or BaseLayout, calls its build method.
     */
    build() {


        const builtSchema = SchemaBuilder.build(this.schema);


        return {
            key: this._key,
            type: this._type,
            class: this._class,
            schema: builtSchema,
            disable_nesting: this.disable_nesting,
            condition_type: this._conditionType,
            conditions: this.buildConditions(this._conditions),
            attributes: this._attributes,
        };

    }
}


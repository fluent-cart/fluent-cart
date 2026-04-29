import Mixin from "@/utils/Mixin";
import BaseInput from "@/utils/model/form/Input/BaseInput";
import BaseLayout from "@/utils/model/form/Layouts/BaseLayout";
import SchemaBuilder from "@/utils/model/form/utils/SchemaBuilder";
import Str from "@/utils/support/Str";
import translate from "@/utils/translator/Translator";

/**
 * Class representing a grid layout.
 * @mixes BaseInput
 * @mixes BaseLayout
 */
export default class Component {
    constructor(key, componentPath) {
        if(typeof key !== 'string' || key.length<1){
            throw new Error('Key is Required');
        }

        Mixin.install(this, BaseInput, {key});
        Mixin.install(this, BaseLayout, {key});
        this._component = componentPath;
        this._key = key;
    }

    static make(key, componentPath = '') {
        return new this(key, componentPath);
    }


    component(componentPath) {
        this._component = componentPath;
        return this;
    }

    /**
     * Sets the input's value.
     * @param {any} value - The value of the input.
     *  @returns {this}  The current instance for chaining.
     */
    value(value) {
        this._value = value;
        return this;
    }

    build() {
        const builtSchema = SchemaBuilder.build(this.schema ?? {});
        return {
            ...{
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
                suffix: this._suffix,
                prefix: this._prefix,
                placeholder: this._placeholder,
                attributes: this._attributes,
                onChange: this._onChangeCallback,
                onKeyUp: this._onKeyUpCallback,
                onKeyDown: this._onKeyDownCallback
            },
            ...{
                type: this._type,
                class: this._class,
                schema: builtSchema,
                disable_nesting: this.disable_nesting,
                condition_type: this._conditionType,
                conditions: this.buildConditions(this._conditions),
            },
            type: 'custom',
            component: this._component,
            key: this._key
        };
    }
}
import Model from "@/utils/model/Model";
import Arr from "@/utils/support/Arr";
import Condition from "@/utils/model/form/Condition/Condition";
import SchemaBuilder from "@/utils/model/form/utils/SchemaBuilder";
import {callback} from "chart.js/helpers";

class FormModel extends Model {
    data = {
        schema: {},
        defaults: {},
        values: {},
        initialized: false,
        hasChange: false,
        validationErrors: {},
        onChangeCallback: [],
    }
    nestedFormLayouts = ['section', 'tab', 'tab-pane', 'grid'];
    ignorableFormLayouts = ['grid'];

    get hasChange() {
        return this.data.hasChange;
    }

    setSchema(schema) {
        this.data.schema = {};
        this.data.schema = SchemaBuilder.build(schema ?? {});
        this.initForm();
        return this;
    }

    setDefaults(defaults) {
        this.data.defaults = defaults;
        this.initForm();
        return this;
    }

    get schema() {
        return this.data.schema;
    }

    get values() {
        return this.data.values;
    }

    get isReady() {
        return this.data.initialized;
    }

    initForm() {

        if (typeof this.data.schema !== 'object' || typeof this.data.defaults !== 'object') {
            throw new Error('You need to set Schema and Defaults First');
        }
        this.setValues()
        this.data.initialized = true;
    }

    setValues() {
        this.data.values = this.ensureNestedDataProperties(this.data.schema, this.data.defaults);
    }

    getState() {
        return this.data.values;
    }

    setValidationErrors(errors) {
        this.data.validationErrors = errors;
    }


    hasValidationError(errorKey) {
        return (this.data.validationErrors ?? {}).hasOwnProperty(errorKey);
    }

    getValidationError(errorKey) {
        return this.data.validationErrors[errorKey];
    }

    ensureNestedDataProperties(schema, value = {}) {

        //console.log(schema, value);
        return Object.keys(schema).reduce((data, key) => {


            const field = schema[key];
            if (field.type === 'html') {
                return data;
            }
            if (this.ignorableFormLayouts.includes(field.type)) {

                //return this.ensureNestedDataProperties(field.schema ?? {}, value);
            }

            if (this.nestedFormLayouts.includes(field.type ?? '')) {
                if (field.disable_nesting === true) {
                    const updatedValue = this.ensureNestedDataProperties(field.schema ?? {}, value ?? {});
                    data = {...data, ...updatedValue};
                } else {
                    data[key] = this.ensureNestedDataProperties(field.schema ?? {}, value[key] ?? {})
                }
            } else {
                data[key] = value[key] || field.value || '';
            }

            return data;
        }, {});
    }


    isVisible(field, stateKey) {

        if (field.hasOwnProperty('conditions')) {
            return new Condition(stateKey, this.values).evaluate(
                Arr.get(field, 'conditions'),
                Arr.get(field, 'condition_type', 'and'),
            );
        }
        return true;
    }

    triggerChange(data) {
        for (let callback of this.data.onChangeCallback) {
            callback(data)
        }

    }

    onDataChanged(callback) {
        this.data.onChangeCallback.push(callback);
    }
}

export function useFormModel() {
    return FormModel.init();
}

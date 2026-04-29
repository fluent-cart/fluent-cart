import Model from "@/utils/model/Model";
import Arr from "@/utils/support/Arr";
import Condition from "@/utils/model/form/Condition/Condition";
import SchemaBuilder from "@/utils/model/form/utils/SchemaBuilder";

class FormModel extends Model {
    data = {
        schema: {},
        defaults: {},
        _originalDefaults: {},
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
        this.data.schema = SchemaBuilder.build(schema ?? {});
        this.initForm();
        return this;
    }

    setDefaults(defaults) {
        this.data.defaults = defaults || {};
        // keep a deep copy of first defaults for reset
        this.data._originalDefaults = JSON.parse(JSON.stringify(this.data.defaults));
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
        this.setValues();
        this.data.initialized = true;
    }

    setValues() {
        this.data.values = this.ensureNestedDataProperties(this.data.schema, this.data.defaults);
    }

    getState() {
        return this.data.values;
    }

    setValidationErrors(errors) {
        this.data.validationErrors = errors || {};
    }

    hasValidationError(errorKey) {
        return (this.data.validationErrors ?? {}).hasOwnProperty(errorKey);
    }

    getValidationError(errorKey) {
        return this.data.validationErrors[errorKey];
    }

    ensureNestedDataProperties(schema, value = {}) {
        return Object.keys(schema).reduce((data, key) => {
            const field = schema[key];

            if (field.type === 'html') {
                return data;
            }

            if (this.nestedFormLayouts.includes(field.type ?? '')) {
                if (field.disable_nesting === true) {
                    const updatedValue = this.ensureNestedDataProperties(field.schema ?? {}, value ?? {});
                    data = { ...data, ...updatedValue };
                } else {
                    data[key] = this.ensureNestedDataProperties(field.schema ?? {}, value[key] ?? {});
                }
            } else {
                data[key] = value[key] ?? field.value ?? '';
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
            callback(data);
        }
    }

    onDataChanged(callback) {
        this.data.onChangeCallback.push(callback);
    }

    /**
     * Set a value and mark form dirty
     */
    setValue(path, value) {
        Arr.set(this.data.values, path, value);
        this.data.hasChange = true;
        this.triggerChange(this.data.values);
    }

    /**
     * Reset form to defaults
     * @param {Boolean} toOriginal - true = first defaults, false = last setDefaults()
     */
    reset(toOriginal = true) {
        if (!this.data.schema || !this.data._originalDefaults) {
            throw new Error('Form is not initialized');
        }

        const base = toOriginal
            ? this.data._originalDefaults
            : this.data.defaults;

        this.data.values = this.ensureNestedDataProperties(
            this.data.schema,
            JSON.parse(JSON.stringify(base))
        );

        this.data.validationErrors = {};
        this.data.hasChange = false;
        this.data.initialized = true;

        //this.triggerChange(this.data.values);

        return this;
    }
}

export function useFormModel() {
    return FormModel.init();
}
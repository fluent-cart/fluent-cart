import Evaluator from "@/utils/model/form/Condition/Builder/Evaluator";

export default class ModifierBuilder {
    value;
    operator;

    constructor(value, operator) {
        this.value = value;
        this.operator = operator;
    }

    setOperator(operator) {
        this.operator = operator;
        return this;
    }

    setValue(value) {
        this.value = value;
        return this;
    }

    static make(value, operator) {
        return new this(value, operator);
    }

    build() {
        return {
            operator: this.operator,
            value: this.value instanceof Evaluator ?
                this.value.setModifiers([]).build() :
                this.value
        }
    }
}
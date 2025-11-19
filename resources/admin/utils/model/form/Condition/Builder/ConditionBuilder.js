import Evaluator from "@/utils/model/form/Condition/Builder/Evaluator";

export default class ConditionBuilder {
    conditionType;
    conditions;
    key;
    operator;
    value;

    constructor(key, value, operator, conditionType, conditions) {
        this.key = key;
        this.value = value;
        this.operator = operator;
        this.conditions = [];

        this.conditionType = this.buildConditionType(conditionType);
        this.setConditions(conditions);
    }


    static make(key, value, operator, conditionType) {
        return new this(key, value, operator, conditionType);
    }

    setConditionType(type) {
        this.conditionType = this.buildConditionType(type);
        return this;
    }

    buildConditionType(type) {
        return (type === 'or' || type === '|') ? 'or' : 'and';
    }

    setOperator(operator) {
        this.operator = operator;
        return this;
    }

    setKey(key) {
        this.key = key;
        return this;
    }

    setValue(value) {
        this.value = value;
        return this;
    }

    addCondition(condition) {
        this.conditions.push(condition);
        return this;
    }

    /**
     * Sets an additional attribute for the input.
     * @param {array|ConditionBuilder|Function} conditions - The condition type.
     * @returns {this} The current instance for chaining.
     */
    setConditions(conditions) {
        this.conditions =
            (
                Array.isArray(conditions) ||
                conditions instanceof ConditionBuilder ||
                typeof conditions === 'function'
            ) ? conditions : this.conditions;
        return this;
    }

    build() {

        if (Array.isArray(this.conditions) && this.conditions.length > 0) {
            let buildConditions = [];
            buildConditions = this.conditions.map((condition) => {
                if (condition instanceof ConditionBuilder) {
                    return condition.build.bind(condition)();
                }
                return condition;
            });
            return {
                condition_type: this.conditionType,
                conditions: buildConditions
            };
        }

        const key = (this.key instanceof Evaluator) ? this.key.build() : this.key;
        const value = (this.value instanceof Evaluator) ? this.value.build() : this.value;
        return {
            key,
            operator: this.operator,
            value
        };
    }
}

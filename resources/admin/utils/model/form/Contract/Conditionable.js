import ConditionBuilder from "@/utils/model/form/Condition/Builder/ConditionBuilder";

export default class Conditionable {
    _conditionType = 'and';
    _conditions = null;

    /**
     * Sets the condition type.
     * @param {string} conditionType - The condition type.
     *  @returns {this}  The current instance for chaining.
     */
    conditionType(conditionType) {
        this._conditionType = conditionType;
        return this;
    }

    /**
     * Sets the conditions for the input.
     * @param {array|ConditionBuilder|Function} conditions - The conditions.
     * @returns {this} The current instance for chaining.
     */
    conditions(conditions) {
        this._conditions =
            (
                Array.isArray(conditions) ||
                conditions instanceof ConditionBuilder ||
                typeof conditions === 'function'
            ) ? conditions : this._conditions;
        return this;
    }

    /**
     * Sets the conditions for visibility of the input.
     * @param {function(string, Array): boolean} condition - A function that takes a state key and an array value, and returns a boolean indicating if the input should be visible.
     * @returns {this} The current instance for chaining.
     */
    visibleIf(condition) {
        this._conditions = condition;
        return this;
    }


    buildConditions(conditions) {
        if (Array.isArray(conditions) && conditions.length > 0) {
            return conditions.map((condition) => {
                if (condition instanceof ConditionBuilder) {
                    return condition.build.bind(condition)();
                }
                return condition;
            });
        }
        return conditions;
    }
}
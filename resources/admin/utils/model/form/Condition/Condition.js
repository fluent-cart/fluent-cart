import Arr from "@/utils/support/Arr";
import Matcher from "@/utils/model/form/Condition/Matcher";
import Modifier from "@/utils/model/form/Condition/Modifier";
import Str from "@/utils/support/Str";
import ConditionBuilder from "@/utils/model/form/Condition/Builder/ConditionBuilder";

/**
 * @class Condition
 * Manages conditional evaluation and modification of data values based on specified criteria.
 */
export default class Condition {
    /**
     * @type {Object} data - The main data object to be evaluated against conditions.
     */
    data;

    /**
     * @type {Array|undefined} conditions - The set of conditions to evaluate.
     */
    conditions;

    /**
     * @type {string} stateKey - Key representing the current statePath of the data being evaluated.
     */
    stateKey;

    /**
     * @type {Matcher} matcher - Instance of Matcher Class to match the value's
     */
    matcher;

    /**
     * @type {Modifier} modifier - Instance of Modifier for applying modifications to values.
     */
    modifier;

    /**
     * @constructor
     * @param {string} stateKey - Key representing the current state.
     * @param {Object} data - Data object containing values to evaluate.
     */
    constructor(stateKey, data) {
        this.data = data;
        this.stateKey = stateKey;
        this.matcher = new Matcher();
        this.modifier = new Modifier();
    }

    /**
     * Matches a value against a specified operator and another value.
     * @param {*} value - The value to be matched.
     * @param {string} operator - The operator used for comparison.
     * @param {*} value2 - The second value to compare against.
     * @param {boolean} [defaultMatch=true] - Default result if matching fails.
     * @returns {boolean} - Returns the result of the match.
     */
    match(value, operator, value2, defaultMatch = true) {
        let matchUsing = '';
        let matchMethod = this.matcher.getMatchingMethod(operator);

        if (matchMethod != null) {
            matchUsing = matchMethod;
        } else {
            matchUsing = Str.camel('match' + operator);
        }

        if (matchUsing in this.matcher && typeof this.matcher[matchUsing] === 'function') {
            return this.matcher[matchUsing](value, value2, operator, defaultMatch);
        } else {
            console.error(`Method ${matchUsing} does not exist in Matcher Class`);
            return defaultMatch;
        }
    }

    /**
     * Evaluates an array of conditions using 'and'/'or' logic.
     * @param {Array|Function} conditions - The conditions to evaluate.
     * @param {string} [conditionType='and'] - Logical operator for evaluation ('and'/'or').
     * @returns {boolean} - True if conditions are met, false otherwise.
     */
    evaluate(conditions, conditionType = 'and') {

        if (conditions instanceof ConditionBuilder) {
            const buildConditions = conditions.build.bind(conditions)();
            if(buildConditions.hasOwnProperty('conditions')){
                conditions = buildConditions['conditions'];
                conditionType = buildConditions['condition_type'];
            }else{
                conditions = [
                    buildConditions
                ];
            }
        }

        conditionType = (conditionType === 'or' ? 'or' : 'and');
        let isMatched = conditionType === 'and';

        if (typeof conditions === "function") {
            return conditions(this.stateKey, this.data);
        }

        if (!Array.isArray(conditions)) {
            return true;
        }

        for (let condition of conditions) {
            let currentlyMatched = null;

            if (Array.isArray(Arr.get(condition, 'conditions'))) {
                currentlyMatched = this.evaluate(
                    condition['conditions'],
                    Arr.get(condition, 'condition_type', 'and'),
                );
            } else {
                if (condition['key'] === null || condition['key'] === undefined) {
                    isMatched = true;
                    continue;
                }
                currentlyMatched = this.applyCondition(condition);
            }

            isMatched = conditionType === 'and' ? (isMatched && currentlyMatched) : (isMatched || currentlyMatched);
        }
        return isMatched;
    }

    /**
     * Applies a single condition to determine if a specific key-value pair matches the criteria.
     * @param {Object} condition - The condition containing key, value, and operator.
     * @returns {boolean} - True if condition is met, false otherwise.
     */
    applyCondition(condition) {
        let keyPath = '';
        let valueToMatch = null;

        if (typeof condition['key'] === 'object') {
            keyPath = Arr.resolvePath(this.stateKey, Arr.get(condition, 'key.accessor'));
        } else {
            keyPath = Arr.resolvePath(this.stateKey, condition['key']);
        }

        let keyValue = Arr.get(this.data, keyPath);

        if (typeof condition['value'] === 'object') {
            const valuePath = Arr.resolvePath(this.stateKey, Arr.get(condition, 'value.accessor'));
            valueToMatch = Arr.get(this.data, valuePath);
        } else {
            valueToMatch = condition['value'];
        }

        keyValue = this.applyModification(keyValue, condition['key'].modifiers, this.stateKey);
        valueToMatch = this.applyModification(valueToMatch, condition['value'].modifiers, this.stateKey);

        return this.match(keyValue, condition.operator, valueToMatch);
    }

    /**
     * Applies modifications to a value based on specified modifiers.
     * @param {*} value - The initial value to modify.
     * @param {Array} modifiers - Array of modifiers to apply to the value.
     * @param {string} stateKey - Key for the current state.
     * @returns {*} - Modified value.
     */
    applyModification(value, modifiers, stateKey) {
        if (Array.isArray(modifiers)) {
            modifiers.forEach((modifier) => {
                let operator = modifier.operator;
                let modifyUsing = '';

                let modificationMethod = this.modifier.getModificationMethod(operator);

                if (modificationMethod != null) {
                    modifyUsing = modificationMethod;
                } else {
                    modifyUsing = Str.camel('apply' + operator);
                }



                if (modifyUsing in this.modifier && typeof this.modifier[modifyUsing] === 'function') {
                    let modifierValue = modifier.value;



                    if (typeof modifierValue === 'object' && modifierValue!== null) {
                        modifierValue = Arr.getByPath(this.data, stateKey, Arr.get(modifierValue, 'accessor'));
                    }

                    value = this.modifier[modifyUsing](value, modifierValue, operator, stateKey, this.data);
                } else {
                    console.error(`Method ${modifyUsing} does not exist in Modifier Class`);
                }
            });
        }

        return value;
    }
}

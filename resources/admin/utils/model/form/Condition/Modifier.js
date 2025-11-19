/**
 * Modifier class for dynamically applying operations to values based on provided modifiers.
 * This class allows you to specify a modification operator (e.g., '+', '-', '*', '==', etc.)
 * and determine the appropriate method to handle the operation, such as addition or equality matching.
 *
 * The `modifierMap` can be used to resolve a specific function name for an operator. If an operator
 * is not found in `modifierMap`, the class will attempt to dynamically call a method named `apply<Operator>`.
 * For instance, if the operator is 'multiply', a method named `applyMultiply` will be automatically called if defined.
 *
 * When a modification method is resolved, the following parameters are passed to the method:
 * - `value1`: The first value to modify.
 * - `value2`: The second value to use in the operation.
 * - `operator`: The operator to apply to `value1` and `value2`.
 * - `stateKey`: The state key of the input.
 * - `data`: The full data object of the form.
 *
 * @class
 */
export default class Modifier {

    /**
     * A map of operator symbols to corresponding method names.
     * Used to determine the appropriate modification method based on the operator.
     * @type {Object<string, string>}
     */
    modifierMap = {
        '+': 'applyAddition',
        '-': 'applySubstruction',
    };

    /**
     * The default modification method name if none is provided in modifierMap.
     * @type {string|null}
     */
    defaultModifierMethod = null;

    /**
     * Retrieves the modification method based on the provided operator.
     *
     * @param {string} modifier - The operator symbol to look up (e.g., '+', '-', '==').
     * @returns {string|null} The name of the method to handle the specified operation.
     */
    getModificationMethod(modifier) {

        // Handle specific equality cases dynamically
        if (modifier === '==' || modifier === '===') {
            return 'matchEqual';
        }

        // Retrieve method from modifier map
        if (this.modifierMap.hasOwnProperty(modifier)) {
            return this.modifierMap[modifier];
        }

        // Fallback to default method if available
        return this.defaultModifierMethod;
    }

    /**
     * Applies an addition or specified operation to two values and updates the given state key.
     *
     * @param {any} value1 - The first value to modify.
     * @param {any} value2 - The second value to use in the operation.
     * @param {string} operator - The operator to apply to `value1` and `value2`.
     * @param {string} stateKey - The state key of the input.
     * @param {Object} data - The full data object of the form.
     */
    applyAddition(value1, value2, operator, stateKey, data) {
        return value1 + value2;
    }
}

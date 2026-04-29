export default class Matcher {


    operatorMap = {
        '!==': 'matchNotEqual',
        '!=': 'matchNotEqual',
    };

    defaultMatcherMethod = null;


    getMatchingMethod(operator) {

        // example if you want to handle it dynamically
        if (operator === '==' || operator === '===') {
            return 'matchEqual';
        }

        //or pass it from reference
        if (this.operatorMap.hasOwnProperty(operator)) {
            return this.operatorMap[operator];
        }

        //Or pass the default method name if you want
        //return 'matchDefault';
        return this.defaultMatcherMethod;

    }

    defaultMatcher(value, value2, operator) {

    }


    matchEqual(value, value2, operator) {
        if (operator === '===') {
            return value === value2
        }
        return value == value2;
    }

    matchNotEqual(value, value2, operator) {
        if (operator === '!==') {
            return value !== value2
        }
        return value != value2;
    }

    matchContains(value, value2, operator) {
        if (value2 instanceof String) {
            return value2.includes(value);
        }
        if (Array.isArray(value2)) {
            return value2.includes(value);
        }
        if (typeof value2 === 'object') {
            return value2.hasOwnProperty(value);
        }

        return false;
    }


    //This will be called dynamically if the operator is set to `in` in the schema`. as the default defaultMatcherName is set to null
    matchIn(value, value2, operator) {
        return this.matchContains(value, value2, operator);
    }

    matchNotIn(value, value2, operator) {
        return !this.matchContains(value, value2, operator);
    }
}
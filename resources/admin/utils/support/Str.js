export default class Str {

    /**
     * Capitalize the first letter of a string.
     *
     * @example
     * Str.capitalize('hello'); // "Hello"
     *
     * @param {string} str - The string to capitalize.
     * @returns {string} The string with the first letter capitalized.
     */
    static capitalize(str) {
        return str?.charAt(0)?.toUpperCase() + str?.slice(1);
    }

    /**
     * Convert a string to camelCase.
     *
     * @example
     * Str.camel('hello_world'); // "helloWorld"
     * Str.camel('hello world'); // "helloWorld"
     *
     * @param {string} str - The string to convert.
     * @param {string} delimiter - The character to split the string with (e.g., '_', '-', ' ').
     * @returns {string} The camelCase version of the input string.
     */
    static camel(str, delimiter = '_') {
        return str
            .split(delimiter)
            .map((word, index) =>
                index === 0 ? word.toLowerCase() : Str.capitalize(word.toLowerCase())
            )
            .join('');
    }

    /**
     * Convert a string to kebab-case.
     *
     * @example
     * Str.kebab('hello World'); // "hello-world"
     * Str.kebab('HelloWorld');  // "hello-world"
     *
     * @param {string} str - The string to convert.
     * @returns {string} The kebab-case version of the input string.
     */
    static kebab(str) {
        return Str.snake(str, '-');
    }

    /**
     * Convert a string to dot.case.
     *
     * @example
     * Str.dotCase('Hello World'); // "hello.world"
     * Str.dotCase('hello-world'); // "hello.world"
     * Str.dotCase('helloWorld');  // "hello.world"
     *
     * @param {string} str - The string to convert.
     * @returns {string} The dot.case version of the input string.
     */
    static dotCase(str) {
        return Str.snake(str, '.');
    }


    /**
     * Convert a string to snake_case.
     *
     * @example
     * Str.snake('Hello World'); // "hello_world"
     * Str.snake('helloWorld');  // "hello_world"
     *
     * @param {string} str - The string to convert.
     * @param {string} [delimiter='.'] - The character to use as the delimiter.
     * @returns {string} The snake_case version of the input string.
     */
    static snake(str, delimiter = '_') {
        if(!str) return '';
        return str
            .replace(/[\s._-]+/g, delimiter)               // Replace spaces, underscores, and hyphens with the replacer
            .replace(/([a-z])([A-Z])/g, `$1${delimiter}$2`) // Insert replacer between camelCase letters
            .toLowerCase();
    }

    /**
     * Replace the first occurrence of a given value in the string.
     *
     * @example
     * Str.replaceFirst('hello world', 'world', 'there'); // "hello there"
     *
     * @param {string} str - The string to search and replace in.
     * @param {string} search - The value to search for.
     * @param {string} replace - The replacement value.
     * @returns {string} The modified string with the first occurrence replaced.
     */
    static replaceFirst(str, search, replace) {
        if(!str) return '';
        return str.replace(new RegExp(search, 'i'), replace);
    }

    /**
     * Convert a string to StudlyCase (PascalCase).
     *
     * @example
     * Str.studly('hello world'); // "HelloWorld"
     * Str.studly('hello-world'); // "HelloWorld"
     *
     * @param {string} str - The string to convert.
     * @param {string} delimiter - The character to split the string with.
     * @returns {string} The StudlyCase version of the input string.
     */
    static studly(str, delimiter = ' ') {
        if(!str) return '';
        return str
            .split(delimiter)
            .map(word => Str.capitalize(word.toLowerCase()))
            .join('');
    }

    /**
     * Convert a string to Headline Case, capitalizing the first letter of each word.
     *
     * @example
     * Str.headline('hello_world example'); // "Hello World Example"
     * Str.headline('my-title-case string'); // "My Title Case String"
     *
     * @param {string} str - The string to convert.
     * @returns {string} The Headline Case version of the input string.
     */
    static headline(str) {
        if(!str) return '';
        return str
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2') // Handle camelCase
            .replace(/[-_]/g, ' ')                 // Replace dashes and underscores
            .replace(/\s+/g, ' ')                  // Normalize spaces
            .trim()                                // Remove leading/trailing whitespace
            .split(' ')
            .map(word => Str.capitalize(word))
            .join(' ');
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @example
     * Str.contains('hello world', 'world');            // true
     * Str.contains('hello world', ['earth', 'world']); // true
     *
     * @param {string} haystack - The string to search within.
     * @param {string|string[]} needles - The substring or array of substrings to look for.
     * @returns {boolean} True if any of the needles are found, otherwise false.
     */
    static contains(haystack, needles) {
        needles = Array.isArray(needles) ? needles : [needles];
        return needles.some(needle => haystack.includes(needle));
    }

    /**
     * Check if a string starts with the given substring.
     *
     * @example
     * Str.startsWith('hello world', 'hello'); // true
     * Str.startsWith('hello world', ['hi', 'hello']); // true
     *
     * @param {string} str - The string to check.
     * @param {string|string[]} needles - The substring(s) to check.
     * @returns {boolean} True if the string starts with any of the needles, otherwise false.
     */
    static startsWith(str, needles) {
        needles = Array.isArray(needles) ? needles : [needles];
        return needles.some(needle => str.startsWith(needle));
    }

    /**
     * Check if a string ends with the given substring.
     *
     * @example
     * Str.endsWith('hello world', 'world'); // true
     * Str.endsWith('hello world', ['earth', 'world']); // true
     *
     * @param {string} str - The string to check.
     * @param {string|string[]} needles - The substring(s) to check.
     * @returns {boolean} True if the string ends with any of the needles, otherwise false.
     */
    static endsWith(str, needles) {
        needles = Array.isArray(needles) ? needles : [needles];
        return needles.some(needle => str.endsWith(needle));
    }

    /**
     * Limit the number of characters in a string.
     *
     * @example
     * Str.limit('hello world', 5);    // "hello..."
     * Str.limit('hello', 10);         // "hello"
     * Str.limit('hello world', 5, '~'); // "hello~"
     *
     * @param {string} str - The string to limit.
     * @param {number} limit - The maximum number of characters allowed.
     * @param {string} [end='...'] - The string to append if truncation occurs.
     * @returns {string} The truncated string.
     */
    static limit(str, limit, end = '...') {
        return str.length > limit ? str.slice(0, limit) + end : str;
    }

    static objectToString(obj) {

        const string = [];

        if (typeof (obj) === 'object' && (obj.join === undefined)) {
            for (const prop in obj) {
                string.push(Str.objectToString(obj[prop]));
            }
        } else if (typeof (obj) === 'object' && !(obj.join === undefined)) {
            for (const prop in obj) {
                string.push(Str.objectToString(obj[prop]));
            }
        } else if (typeof (obj) === 'function') {

        } else if (typeof (obj) === 'string') {
            string.push(obj)
        }

        return string.join('<br />')
    }
}

export default class Arr {
    // Static method to set value using dot notation
    static set(data, path, value) {
        const keys = path.split('.');
        let current = data;

        while (keys.length > 1) {
            const key = keys.shift();
            if (!current[key]) {
                current[key] = {};
            }
            current = current[key];
        }

        current[keys[0]] = value;
    }

    // Static method to get value using dot notation with wildcard support
    static get(data, path, defaultValue = null) {

        if(!path) return defaultValue;
        if (!(path || Array.isArray(data) || typeof data === 'object')) {
            return data;
        }

        const keys = path.trim().split('.');

        // Check if path contains wildcard
        if (path.includes('*')) {
            return Arr._getWithWildcard(data, keys, defaultValue);
        }

        let current = data;
        for (const key of keys) {
            if (!current || !current.hasOwnProperty(key)) {
                return defaultValue;
            }
            current = current[key];
        }

        return current;
    }

    // Helper method to handle wildcard paths
    static _getWithWildcard(data, keys, defaultValue = null) {
        let current = data;

        for (let i = 0; i < keys.length; i++) {
            const key = keys[i];

            if (key === '*') {
                // Handle wildcard - collect values from all items
                if (Array.isArray(current)) {
                    const remainingPath = keys.slice(i + 1).join('.');
                    if (remainingPath) {
                        // There are more keys after the wildcard
                        return current.map(item => Arr.get(item, remainingPath, defaultValue));
                    } else {
                        // Wildcard is the last key, return all items
                        return current;
                    }
                } else if (typeof current === 'object' && current !== null) {
                    // If current is an object, get values from all properties
                    const remainingPath = keys.slice(i + 1).join('.');
                    if (remainingPath) {
                        return Object.values(current).map(item => Arr.get(item, remainingPath, defaultValue));
                    } else {
                        return Object.values(current);
                    }
                } else {
                    return defaultValue;
                }
            } else {
                // Regular key navigation
                if (!current || !current.hasOwnProperty(key)) {
                    return defaultValue;
                }
                current = current[key];
            }
        }

        return current;
    }

    static resolvePath(base, accessor) {
        if (!(base.includes('.') || accessor.includes('.'))) {
            return accessor;
        }

        let paths = base.split('.');
        paths.pop(); // remove last item from the Array because its the scope
        let file = accessor.split('../');

        let fileName = file.pop();
        let itemToAvoid = 0;
        let path = ""

        for (let i = 0; i < file.length; i++) {
            if (file[i] === "") {
                itemToAvoid++
            }
        }

        paths.splice(0, itemToAvoid)
        path = paths.length ? paths.join('.') + "." : "";
        return path + `${fileName}`;
    }

    static getByPath(data, base, accessor, defaultValue) {
        const path = Arr.resolvePath(base, accessor);
        return Arr.get(data, path, defaultValue);
    }

    /**
     * Filter an array by matching values at a given path (dot notation) against a list of allowed values.
     *
     * - If the array contains objects, the `path` is used to extract the value (e.g., "user.id").
     * - If the array contains scalar values (like numbers or strings), pass an empty string as the `path`.
     *
     * @param {Array} data - The array of objects or scalar values to filter.
     * @param {string} path - The dot notation path to the value (e.g., "user.id"). Use '' for scalar values.
     * @param {Array} values - The array of allowed values.
     * @returns {Array} A filtered array where the path value (or item itself) is in the `values` array.
     *
     * @example
     * // Array of objects
     * const data = [
     *   { user: { id: 1 } },
     *   { user: { id: 2 } },
     *   { user: { id: 3 } }
     * ];
     * const result = Arr.whereIn(data, 'user.id', [1, 3]);
     * // result: [{ user: { id: 1 } }, { user: { id: 3 } }]
     *
     * @example
     * // Array of objects
     * const data = [
     *   { id: 1, title: 'Title 1' },
     *   { id: 2, title: 'Title 2' },
     *   { id: 3, title: 'Title 3' }
     * ];
     * const result = Arr.whereIn(data, 'id', [1, 3]);
     * // result: [ { id: 1, title: 'Title 1' }, { id: 3, title: 'Title 3' }]
     */
    static whereIn(data, path, values) {
        if (!Array.isArray(data) || !Array.isArray(values)) {
            return [];
        }

        return data.filter(item => {
            if (typeof item === 'object' && item !== null) {
                const val = Arr.get(item, path);
                return values.includes(val);
            }
            return values.includes(item);
        });
    }

    /**
     * Filter an array by matching a specific value at a given path (dot notation).
     *
     * - If the array contains objects, the `path` is used to extract the value (e.g., "user.id").
     * - If the array contains scalar values (like numbers or strings), pass an empty string as the `path`.
     *
     * @param {Array} data - The array of objects or scalar values to filter.
     * @param {string} path - The dot notation path to the value (e.g., "user.id"). Use '' for scalar values.
     * @param {*} value - The value to compare strictly (===).
     * @returns {Array} A filtered array where the path value (or item itself) is strictly equal to the `value`.
     *
     * @example
     * // Array of objects
     * const data = [
     *   { user: { id: 1 } },
     *   { user: { id: 2 } },
     *   { user: { id: 3 } }
     * ];
     * const result = Arr.where(data, 'user.id', 2);
     * // result: [{ user: { id: 2 } }]
     *
     * @example
     * // Array of objects
     * const data = [
     *   { id: 1, title: 'Title 1' },
     *   { id: 2, title: 'Title 2' },
     *   { id: 3, title: 'Title 3' }
     * ];
     * const result = Arr.where(data, 'title', 'Title 2');
     * // result: [{ id: 2, title: 'Title 2' }]
     *
     * @example
     * // Array of scalar values
     * const data = [1, 2, 3];
     * const result = Arr.where(data, '', 2);
     * // result: [2]
     */
    static where(data, path, value) {
        if (!Array.isArray(data)) {
            return [];
        }

        return data.filter(item => {
            if (typeof item === 'object' && item !== null) {
                const val = Arr.get(item, path);
                return val === value;
            }
            return item === value;
        });
    }

    /**
     * Sum values in an array at a given dot-notated path with wildcard support.
     *
     * - If the array contains objects, the `path` is used to extract the value (e.g., "price").
     * - If the array contains scalar values (numbers), pass an empty string as the `path`.
     * - Supports wildcards like "*.current" to sum all 'current' properties from array items.
     *
     * @param {Array} data - The array of objects or scalar numbers.
     * @param {string} path - The dot notation path to the value (e.g., "price" or "*.current"). Use '' for scalar values.
     * @returns {number} The sum of values.
     *
     * @example
     * // Array of objects
     * const data = [{ price: 10 }, { price: 20 }];
     * const result = Arr.sum(data, 'price'); // 30
     *
     * // Array of scalar numbers
     * const data = [5, 15, 25];
     * const result = Arr.sum(data, ''); // 45
     *
     * // Wildcard usage
     * const data = [
     *   { stats: { current: 10, total: 50 } },
     *   { stats: { current: 20, total: 60 } }
     * ];
     * const result = Arr.sum(data, '*.stats.current'); // 30
     */
    static sum(data, path = '') {

        // Handle wildcard paths
        if (path.includes('*')) {
            const values = Arr.get(data, path, []);
            if (Array.isArray(values)) {
                return values.reduce((total, value) => {
                    return total + (typeof value === 'number' ? value : 0);
                }, 0);
            }
            return 0;
        }

        // Handle regular paths
        return data.reduce((total, item) => {
            const value = (typeof item === 'object' && item !== null)
                ? Arr.get(item, path, 0)
                : item;

            return total + (typeof value === 'number' ? value : 0);
        }, 0);
    }
}
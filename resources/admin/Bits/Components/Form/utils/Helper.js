export default class Helper {
    static attributesBuilders = async (field, callback, form) => {
        const attributes = {};
        if (!field.attributes) {
            field.attributes = {};
        }

        if (field.class) {
            field.attributes['class'] = field.class;
        }

        if (field.placeholder) {
            field.attributes['placeholder'] = field.placeholder;
        }

        if (typeof callback !== 'function') {
            return field.attributes ?? {};
        }

        let events = {};

        if (typeof field.events !== 'object') {
            events = {};
        }

        if (events.hasOwnProperty('onChange') && typeof events['onChange'] === "function") {
            events['onChange'] = (...args) => {
                form.data.hasChange = true;
                events['onChange'](args);
            }
        } else {
            events['onChange'] = () => {
                form.data.hasChange = true;
            }
        }

        for (let key in field.events) {
            events[key] = (...args) => {
                callback(field.events[key], args);
            }
        }


        for (let key in field.attributes ?? {}) {
            let attribute = field.attributes[key];
            if (typeof attribute === 'function') {
                attributes[key] = await callback(attribute, {});
            } else {
                attributes[key] = attribute;
            }
        }

        return {
            ...field.attributes ?? {},
            ...events
        };
    }
}
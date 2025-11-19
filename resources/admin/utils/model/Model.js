import Rest from '@/utils/http/Rest';

import {capitalize, reactive} from 'vue';

export default class Model {



    /**
     * @return {this}
     */
    static init(data = {}) {
        const target = new this(data);
        if (typeof target.beforeInit === 'function') {
            target.beforeInit();
        }

        target.data = reactive(target.data);
        return Model.#buildProxyFor(target);
    }

    static #buildProxyFor(target) {
        return new Proxy(target, {
            get: (target, prop, receiver) => Model.#get(target, prop, receiver),
            set: (target, prop, value) => Model.#set(target, prop, value)
        });
    }

    static #get(target, prop, receiver) {
        return Model.#resolveProperty(
            target, prop, receiver
        ) || Model.#resolveRestMethod(
            target, prop, receiver
        );
    }

    static #set(target, prop, value) {

        const computed = prop.split('_').map(i => capitalize(i)).join('');

        const method = `set${capitalize(computed)}Attribute`;

        if (method in target) {
            target[method](prop, value);
        } else {
            target.data[prop] = value;
        }

        return true;
    }

    static #resolveProperty(target, prop, receiver) {
        if (prop in target) {
            if (typeof target[prop] === 'function') {
                return (...args) => {
                    return target[prop].bind(receiver)(...args);
                };
            }

            return target[prop];
        }

        // Retrieve properties from data object
        if (prop in target.data) {

            const computed = prop.split('_').map(i => capitalize(i)).join('');

            const method = `get${capitalize(computed)}Attribute`;

            if (method in target) {
                return target[method](target.data[prop]);
            }

            return target.data[prop];
        }
    }

    static #resolveRestMethod(target, prop, receiver) {
        if (typeof prop !== 'string') return;

        const method = prop.substr(1);

        if (method in Rest) {
            return (...args) => Rest[method](...args);
        }
    }

}

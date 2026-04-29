export default class ResponseProxyItr {

    constructor(response) {

        this.response = response;

        return new Proxy(this, {
            get: function(target, prop) {

                if (typeof target[prop] === 'function') {
                    if (prop === Symbol.iterator) {
                        return target[Symbol.iterator].bind(target);
                    }
                }

                if (target.response && typeof target.response === 'object') {
                    if (prop in target.response) {
                        return target.response[prop];
                    } 
                    if (target.response.responseJSON && typeof target.response.responseJSON === 'object') {
                        if (prop in target.response.responseJSON) {
                            return target.response.responseJSON[prop];
                        }
                    }
                }
                if (prop === 'errors' && target.response && target.response.responseJSON) {
                    return target.response.responseJSON;
                }
            },
            ownKeys(target) {
                return Object.keys(target.response.responseJSON);
            },
            getOwnPropertyDescriptor(target, prop) {
                return {
                  enumerable: true,
                  configurable: true,
                  value: target[prop]
                };
            }
        });
    }

    *iterator() {
        for (let i in this.response.responseJSON) {
            yield this.response.responseJSON[i];
        }
    }

    [Symbol.iterator]() {
        return this.iterator();
    }
}

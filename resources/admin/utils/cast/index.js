import castings from './castings';

export default {
    cast(data, casts) {
        if (typeof data !== 'object') return;
        
        for (let key in casts) {
            if (key in data) {
                if (typeof cast === 'function') {
                    data[key] = cast(data[key]);
                } else if (typeof castings[casts[key]] === 'function') {
                    data[key] = castings[casts[key]](data[key]);
                }
            } else {
                const keys = key.startsWith(
                    'data'
                ) ? key.split('.') : `data.${key}`.split('.');

                for (let k of keys) {
                    if (Array.isArray(data[k])) {
                        this.handle(
                            data[k],
                            keys.slice(keys.indexOf(k) + 1),
                            casts[key]
                        );
                    }
                }
            }
        }

        return data;
    },
    handle(data, keys, cast) {
        for (let item of data) {
            for (let key of keys) {
                if (key in item) {
                    if (Array.isArray(item[key])) {
                        this.handle(
                            item[key],
                            keys.slice(keys.indexOf(key) + 1),
                            cast
                        );
                    } else {
                        if (typeof cast === 'function') {
                            item[key] = cast(item[key]);
                        } else {
                            const parts = cast.split(':');
                            const action = parts.shift();
                            item[key] = castings[action](item[key], parts.join(':'));
                        }
                    }
                }
            }
        }

        return data;
    }
};

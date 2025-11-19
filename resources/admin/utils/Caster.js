const castings = {
    int: (v) => parseInt(v),
    integer: (v) => parseInt(v),
    float: (v, p) => v.toFixed(p || 2),
    string: (v) => String(v),
    bool: (v) => !!v,
    boolean: (v) => !!v,
    datetime: (v, format) => {
        const date = new Date(v);
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const daysShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const monthsShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        const pad = (num, size) => {
            let s = "000" + num;
            return s.substr(s.length-size);
        };

        const getOrdinalSuffix = (n) => {
            return ["th", "st", "nd", "rd"][((n % 100) > 10 && (n % 100) < 20) || n % 10 > 3 ? 0 : n % 10];
        };

        const isLeapYear = (year) => {
            return (year % 4 === 0 && year % 100 !== 0) || (year % 400 === 0);
        };

        const getDayOfYear = (date) => {
            const start = new Date(date.getFullYear(), 0, 0);
            const diff = (date - start) + ((start.getTimezoneOffset() - date.getTimezoneOffset()) * 60 * 1000);
            const oneDay = 1000 * 60 * 60 * 24;
            return Math.floor(diff / oneDay);
        };

        const getWeekNumber = (d) => {
            d = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
            d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
            const yearStart = new Date(Date.UTC(d.getUTCFullYear(),0,1));
            const weekNo = Math.ceil(( ( (d - yearStart) / 86400000) + 1)/7);
            return weekNo;
        };

        const formatMapping = {
            'd': pad(date.getDate(), 2),
            'D': daysShort[date.getDay()],
            'j': date.getDate(),
            'l': days[date.getDay()],
            'N': date.getDay() || 7,
            'S': getOrdinalSuffix(date.getDate()),
            'w': date.getDay(),
            'z': getDayOfYear(date),
            'W': pad(getWeekNumber(date), 2),
            'F': months[date.getMonth()],
            'm': pad(date.getMonth() + 1, 2),
            'M': monthsShort[date.getMonth()],
            'n': date.getMonth() + 1,
            't': new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate(),
            'L': isLeapYear(date.getFullYear()) ? 1 : 0,
            'o': date.getFullYear(),
            'Y': date.getFullYear(),
            'y': String(date.getFullYear()).slice(-2),
            'a': date.getHours() >= 12 ? 'pm' : 'am',
            'A': date.getHours() >= 12 ? 'PM' : 'AM',
            'B': Math.floor(((date.getUTCHours() + 1) % 24 + date.getUTCMinutes() / 60 + date.getUTCSeconds() / 3600) * 1000 / 24),
            'g': date.getHours() % 12 || 12,
            'G': date.getHours(),
            'h': pad(date.getHours() % 12 || 12, 2),
            'H': pad(date.getHours(), 2),
            'i': pad(date.getMinutes(), 2),
            's': pad(date.getSeconds(), 2),
            'u': pad(date.getMilliseconds() * 1000, 6),
            'e': Intl.DateTimeFormat().resolvedOptions().timeZone,
            'I': (new Date(date.getFullYear(), 0) - new Date(date.getFullYear(), 6)) ? 1 : 0,
            'O': (date.getTimezoneOffset() > 0 ? "-" : "+") + pad(Math.floor(Math.abs(date.getTimezoneOffset()) / 60) * 100 + Math.abs(date.getTimezoneOffset()) % 60, 4),
            'P': (date.getTimezoneOffset() > 0 ? "-" : "+") + pad(Math.floor(Math.abs(date.getTimezoneOffset()) / 60), 2) + ":" + pad(Math.abs(date.getTimezoneOffset()) % 60, 2),
            'T': (date.toString().match(/\(([A-Za-z\s].*)\)/) || [])[1] || '',
            'Z': -date.getTimezoneOffset() * 60,
            'c': date.toISOString(),
            'r': date.toString(),
            'U': Math.floor(date.getTime() / 1000),
        };

        const formattedDate = format.replace(/d|D|j|l|N|S|w|z|W|F|m|M|n|t|L|o|Y|y|a|A|B|g|G|h|H|i|s|u|e|I|O|P|T|Z|c|r|U/g, match => formatMapping[match]);

        return formattedDate;
    },
}

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

import {getCurrentInstance} from "vue";
import Arr from "@/utils/support/Arr";

export default class Config {

    _data = {};

    static form(data) {
        const instance = new this;
        instance._data = data;
        return instance;
    }

    get(key = null, $default = '') {
        if (key === null) {
            return this._data;
        }
        return Arr.get(this._data, key, $default)
    }
}
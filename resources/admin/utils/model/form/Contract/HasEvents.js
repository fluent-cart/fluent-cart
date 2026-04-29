export default class HasEvents {

    _events = {};

    event(name, callable) {
        this._events[name] = callable;
        return this;
    }
}
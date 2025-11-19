export default class HasColumns {

    _columns = {
        default: 1,
        xs: 1,
        sm: 1,
        md: 2,
        lg: 2,
        xl: 2
    };

    columns(columns) {
        this._columns = columns;
        return this;
    }
}
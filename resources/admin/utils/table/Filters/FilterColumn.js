import translate from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";

/**
 * Class representing a table column configuration.
 */
export default class FilterColumn {

    constructor(name) {
        if (typeof name !== 'string' || name.length < 1) {
            throw new Error('Key is Required');
        }
        this._name = name;
        this.isDate = false;
        //this._multiple = false;
    }

    /**
     * Static method to create a new instance of the class.
     * @return {FilterColumn} A new instance of BaseInput.
     */
    static make(name) {
        return new this(name);
    }

    title(title) {
        this._title = title;
        return this;
    }

    date(isDate = true) {
        this.isDate = isDate;
        return this;
    }

    // multiple(multiple = true) {
    //     this._multiple = multiple;
    // }


    build() {

        //Convert the key from customer.email to customer_email
        const name = Str.snake(this._name);

        return {
            title: this._title ?? translate(Str.headline(name)),
            name: this._name,
            isDate: this.isDate,
            //multiple: this._multiple,
        };
    }

}

import translate from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";
import FilterColumn from "@/utils/table/Filters/FilterColumn";

/**
 * Class representing a table column configuration.
 */
export default class FilterRelation {

    constructor(name) {
        if (typeof name !== 'string' || name.length < 1) {
            throw new Error('Key is Required');
        }
        this._name = name;
        this._columns = [];
        this._relations = [];
    }

    columns(columns) {
        this._columns = columns.map(function (column) {
            return column instanceof FilterColumn ? column.build.bind(column)() : column;
        });

        return this;
    }

    relations(relations) {
        this._relations = relations.map(function (relation) {
            return relation instanceof FilterRelation ? relation.build.bind(relation)() : relation;
        });
        return this;
    }

    /**
     * Static method to create a new instance of the class.
     * @return {FilterRelation} A new instance of BaseInput.
     */
    static make(name) {
        return new this(name);
    }

    title(title) {
        this._title = title;
        return this;
    }


    build() {

        //Convert the key from customer.email to customer_email
        const name = Str.snake(this._name);

        return {
            title: this._title ?? translate(Str.headline(name)),
            name: this._name,
            columns: this._columns,
            relations: this._relations,
            conditions: [
                {
                    title: 'And',
                    type: 'and',
                    isCondition: true,
                    conditions: [],
                }
            ]
        };
    }

}

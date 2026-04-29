import Model from "@/utils/model/Model";
import FilterColumn from "@/utils/table/Filters/FilterColumn";
import FilterRelation from "@/utils/table/Filters/FilterRelation";

export default class Query extends Model {

    data = {
        query: [
            {
                title: 'And',
                type: 'and',
                isCondition: true,
                conditions: [],
            }
        ],
        columns: [],
        relations: []
    };

    conditionTypes = ([
        {
            title: 'Or',
            type: 'or',
            conditions: []
        },
        {
            title: 'And',
            type: 'and',
            conditions: []
        },
    ]);

    operatorList = {

        where: {title: 'Where', operator: 'where', column: '', value: ''},
        is_null: {title: 'Null', operator: 'is_null', column: '', value: '', isNull: true},
        is_not_null: {title: 'Not Null', operator: 'is_not_null', column: '', value: '', isNull: true},
        like_all: {title: 'Matching', operator: 'like_all', multiple: false, column: '', value: ''},
        not_like: {title: 'Not Matching', operator: 'not_like', multiple: false, column: '', value: ''},

        between: {title: 'Between', operator: 'between', column: '', value: '', multiple: true},
        not_between: {title: 'Not Between', operator: 'not_between', column: '', value: '', multiple: true},
        in: {title: 'Include', operator: 'in', column: '', value: '', multiple: true},
        not_in: {title: 'Exclude', operator: 'not_in', column: '', value: '', multiple: true},
    };

    get query(){
        return this.data.query[0]??[];
    }


    setColumns(columns) {
        this.data.columns = columns.map(function (column) {
            return column instanceof FilterColumn ? column.build.bind(column)() : column;
        });
    }


    setRelations(relations) {
        this.data.relations = relations.map(function (relation) {
            return relation instanceof FilterRelation ? relation.build.bind(relation)() : relation;
        });
    }

    getOperatorScheme(operator) {
        return this.operatorList[operator];
    }
}



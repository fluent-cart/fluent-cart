import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";

import {useRoute} from 'vue-router'

class ProductTable extends Table {

    setupInitialData() {
        super.setupInitialData();
        this.data.sorting.sortBy = "ID";
    }

    getTabs() {
        return {
            all: translate("All"),
            publish: translate("Published"),
            draft: translate("Draft"),
            physical: translate('Physical'),
            digital: translate("Digital"),
            //simple: translate("Simple"),
            //simple_variations: translate("Simple Variations"),
            subscribable: translate("Subscribable"),
            bundle: translate("Bundle"),
            non_bundle: translate("Non Bundle"),
        }
    }

    getToggleableColumns() {
        return [
            {
                label: translate('Type'),
                value: 'product_type'
            },
            {
                label: translate('Variation'),
                value: 'variation_type'
            },
            {
                label: translate('Stock'),
                value: 'stock_availability'
            },
            {
                label: translate('Price'),
                value: 'item_price'
            },
            {
                label: translate('Status'),
                value: 'post_status'
            },
            {
                label: translate('Date'),
                value: 'post_date'
            }
        ];
    }

    getSortableColumns() {
        return [
            {
                label: translate('Product ID'),
                value: 'ID'
            },
            {
                label: translate('Title'),
                value: 'post_title'
            },
            {
                label: translate('Created at'),
                value: 'post_date'
            }
        ]
    }

    getSearchHint() {
        return translate("Search by Id, product title or variation title")
    }

    getFetchUrl() {
        return 'products';
    }

    parseResponse(response) {
        return response.products;
    }

    getTableName() {
        return 'product_table';
    }

    with() {
        return [
            'detail',
            'variants:post_id,available'
        ];
    }
}


/**
 * @return {ProductTable}
 */
export default function useProductTable(data) {
    return ProductTable.init(data);
}


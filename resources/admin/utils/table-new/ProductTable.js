import Table from "@/utils/table-new/Table";
import translate from "@/utils/translator/Translator";

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
            },
            {
                label: translate('Reviews'),
                value: 'reviews'
            }
        ];
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

    // A SCREEN key, not a relation name — ProductFilter::allowedWiths() owns
    // which relations and columns it loads. The admin list gets its own key so
    // it can be re-scoped without touching the block-editor or Elementor
    // pickers, which read a deeper subtree of the same model. It feeds the
    // thumbnail and price columns from `detail`, and the stock column, which
    // sums variants[].available.
    with() {
        return [
            'admin_product_list'
        ];
    }
}


/**
 * @return {ProductTable}
 */
export default function useProductTable(data) {
    return ProductTable.init(data);
}


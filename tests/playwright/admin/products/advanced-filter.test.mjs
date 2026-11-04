import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsAdvancedFilterTest extends BaseTableTest {
  static title = 'Products — Advanced Filter';

  route()                   { return '/products'; }
  apiEndpoint()             { return 'products'; }
  pageSelector()            { return '.fct-all-products-page'; }
  tableWrapSelector()       { return '.fct-products-table-wrap'; }
  advanceFilterWindowPath() { return 'fluentCartAdminApp.table_config.product_table.filters.advance'; }

  async run() {
    await this._navigate();
    await this._testAdvancedFilter();
  }
}

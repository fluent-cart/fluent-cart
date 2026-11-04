import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsTableTest extends BaseTableTest {
  static title = 'Products — Full Table Suite';

  route()                   { return '/products'; }
  apiEndpoint()             { return 'products'; }
  pageSelector()            { return '.fct-all-products-page'; }
  tableWrapSelector()       { return '.fct-products-table-wrap'; }
  expectedColumns()         { return ['Products', 'Type', 'Price', 'Status', 'Date']; }
  filterTabs()              { return ['Published', 'Draft', 'Physical']; }
  toggleColumn()            { return 'Type'; }
  sortByField()             { return 'Title'; }
  advanceFilterWindowPath() { return 'fluentCartAdminApp.table_config.product_table.filters.advance'; }

  async run() {
    await this._navigate();
    await this._testLoad();
    await this._testTabFiltering();
    await this._testSearch();
    await this._testColumnToggle();
    await this._testSort();
    await this._testPagination();
    await this._testAdvancedFilter();
    await this._testSavedView();
  }
}

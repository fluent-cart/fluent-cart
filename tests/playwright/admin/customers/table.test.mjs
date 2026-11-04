import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersTableTest extends BaseTableTest {
  static title = 'Customers — Full Table Suite';

  route()                   { return '/customers'; }
  apiEndpoint()             { return 'customers'; }
  pageSelector()            { return '.fct-all-customers-page'; }
  tableWrapSelector()       { return '.fct-table-wrapper'; }
  expectedColumns()         { return ['Customer', 'Address', 'Purchases', 'LTV', 'Last Purchase Date', 'Customer Since']; }
  toggleColumn()            { return 'Customer Since'; }
  sortByField()             { return 'Name'; }
  advanceFilterWindowPath() { return 'fluentCartAdminApp.table_config.customers.filters.advance'; }

  async run() {
    await this._navigate();
    await this._testLoad();
    await this._testSearch();
    await this._testColumnToggle();
    await this._testSort();
    await this._testPagination();
    await this._testAdvancedFilter();
    await this._testSavedView();
  }
}

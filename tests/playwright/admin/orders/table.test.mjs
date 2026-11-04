import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersTableTest extends BaseTableTest {
  static title = 'Orders — Full Table Suite';

  route()                   { return '/orders'; }
  apiEndpoint()             { return 'orders'; }
  pageSelector()            { return '.fct-all-orders-page'; }
  tableWrapSelector()       { return '.fct-orders-table-wrap'; }
  expectedColumns()         { return ['Date', 'Customer', 'Total', 'Payment Status']; }
  tabsWindowPath()          { return 'fluentCartAdminApp.order_statuses'; }
  toggleColumn()            { return 'Customer'; }
  sortByField()             { return 'Total'; }
  searchFor()               { return 'admin'; }
  advanceFilterWindowPath() { return 'fluentCartAdminApp.table_config.order_table.filters.advance'; }

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

import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersAdvancedFilterTest extends BaseTableTest {
  static title = 'Orders — Advanced Filter';

  route()                    { return '/orders'; }
  apiEndpoint()              { return 'orders'; }
  pageSelector()             { return '.fct-all-orders-page'; }
  advanceFilterWindowPath()  { return 'fluentCartAdminApp.table_config.order_table.filters.advance'; }

  async run() {
    await this._navigate();
    await this._testAdvancedFilter();
  }
}

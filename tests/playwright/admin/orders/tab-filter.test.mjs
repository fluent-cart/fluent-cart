import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersTabFilterTest extends BaseTableTest {
  static title = 'Orders — Tab Filter';

  route()           { return '/orders'; }
  apiEndpoint()     { return 'orders'; }
  pageSelector()    { return '.fct-all-orders-page'; }
  tabsWindowPath()  { return 'fluentCartAdminApp.order_statuses'; }

  async run() {
    await this._navigate();
    await this._testTabFiltering();
  }
}

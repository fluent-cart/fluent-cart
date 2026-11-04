import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersColumnToggleTest extends BaseTableTest {
  static title = 'Orders — Column Toggle Visibility';

  route()             { return '/orders'; }
  apiEndpoint()       { return 'orders'; }
  pageSelector()      { return '.fct-all-orders-page'; }
  tableWrapSelector() { return '.fct-orders-table-wrap'; }
  toggleColumn()      { return 'Customer'; }

  async run() {
    await this._navigate();
    await this._testColumnToggle();
  }
}

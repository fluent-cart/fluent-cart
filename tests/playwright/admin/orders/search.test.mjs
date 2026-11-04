import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersSearchTest extends BaseTableTest {
  static title = 'Orders — Search';

  route()             { return '/orders'; }
  apiEndpoint()       { return 'orders'; }
  pageSelector()      { return '.fct-all-orders-page'; }
  tableWrapSelector() { return '.fct-orders-table-wrap'; }
  searchFor()         { return 'admin'; }

  async run() {
    await this._navigate();
    await this._testSearch();
  }
}

import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersSortTest extends BaseTableTest {
  static title = 'Orders — Sort';

  route()       { return '/orders'; }
  apiEndpoint() { return 'orders'; }
  pageSelector(){ return '.fct-all-orders-page'; }
  sortByField() { return 'Total'; }

  async run() {
    await this._navigate();
    await this._testSort();
  }
}

import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersPaginationTest extends BaseTableTest {
  static title = 'Orders — Pagination Controls';

  route()       { return '/orders'; }
  apiEndpoint() { return 'orders'; }
  pageSelector(){ return '.fct-all-orders-page'; }

  async run() {
    await this._navigate();
    await this._testPagination();
  }
}

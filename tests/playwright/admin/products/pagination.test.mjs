import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsPaginationTest extends BaseTableTest {
  static title = 'Products — Pagination';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }

  async run() {
    await this._navigate();
    await this._testPagination();
  }
}

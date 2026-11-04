import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsSearchTest extends BaseTableTest {
  static title = 'Products — Search';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }

  async run() {
    await this._navigate();
    await this._testSearch();
  }
}

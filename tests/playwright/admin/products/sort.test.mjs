import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsSortTest extends BaseTableTest {
  static title = 'Products — Sort';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }
  sortByField()       { return 'Title'; }

  async run() {
    await this._navigate();
    await this._testSort();
  }
}

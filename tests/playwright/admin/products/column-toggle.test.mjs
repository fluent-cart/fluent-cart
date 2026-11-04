import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsColumnToggleTest extends BaseTableTest {
  static title = 'Products — Column Toggle';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }
  toggleColumn()      { return 'Type'; }

  async run() {
    await this._navigate();
    await this._testColumnToggle();
  }
}

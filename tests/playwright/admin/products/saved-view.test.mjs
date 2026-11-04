import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsSavedViewTest extends BaseTableTest {
  static title = 'Products — Saved View';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }

  async run() {
    await this._navigate();
    await this._testSavedView();
  }
}

import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsTabFilterTest extends BaseTableTest {
  static title = 'Products — Tab Filtering';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }

  // Only top-level tabs (FilterTabs shows first 4: All, Published, Draft, Physical)
  // "Digital" and beyond are in the "More views" dropdown — not top-level tabs
  filterTabs()   { return ['Published', 'Draft', 'Physical']; }

  async run() {
    await this._navigate();
    await this._testTabFiltering();
  }
}

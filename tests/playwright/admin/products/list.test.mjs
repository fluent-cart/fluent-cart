import BaseTableTest from '../../base-table-test.mjs';

export default class ProductsListTest extends BaseTableTest {
  static title = 'Products — List Loads & Row Navigation';

  route()             { return '/products'; }
  apiEndpoint()       { return 'products'; }
  pageSelector()      { return '.fct-all-products-page'; }
  tableWrapSelector() { return '.fct-products-table-wrap'; }
  expectedColumns()   { return ['Products', 'Type', 'Price', 'Status', 'Date']; }

  async run() {
    await this._navigate();
    await this._testLoad();

    const rowCount = await this.evaluate((sel) => document.querySelectorAll(sel).length, this._rowSel);
    this.assert(rowCount > 0, 'Products table has rows', `found: ${rowCount}`);

    // "Add Product" button visible
    const addBtn = await this.evaluate(() => {
      const btns = [...document.querySelectorAll('button')];
      return btns.some(b => b.textContent.trim() === 'Add Product');
    });
    this.assert(addBtn, '"Add Product" button visible');

    // Row click navigates to product edit page
    this.info('Clicking first product row to navigate to edit page...');
    const firstLink = await this.page.$('.el-table__row a').catch(() => null);
    if (firstLink) {
      await firstLink.click();
      await this.page.waitForTimeout(1500);
      const url = this.page.url();
      this.assert(
        /#\/products\/\d+(?:\/edit)?\/?$/.test(url),
        'Navigated to product edit page',
        url
      );
      // Navigate back
      await this.adminPage(this.route());
      await this.page.waitForSelector(this.pageSelector(), { timeout: 10000 }).catch(() => null);
    } else {
      this.assert(false, 'No product row link found to click');
    }
  }
}

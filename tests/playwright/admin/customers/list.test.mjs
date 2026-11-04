import BaseTableTest from '../../base-table-test.mjs';

const ROW_LINK = '.fct-table-wrapper .el-table__row a';

export default class CustomersListTest extends BaseTableTest {
  static title = 'Customers — List Loads & Row Navigation';

  route()             { return '/customers'; }
  apiEndpoint()       { return 'customers'; }
  pageSelector()      { return '.fct-all-customers-page'; }
  tableWrapSelector() { return '.fct-table-wrapper'; }
  expectedColumns()   { return ['Customer', 'Address', 'Purchases', 'LTV', 'Last Purchase Date', 'Customer Since']; }

  async run() {
    await this._navigate();
    await this._testLoad();

    const rowCount = await this.evaluate((sel) => document.querySelectorAll(sel).length, this._rowSel);
    this.assert(rowCount > 0, 'Customers table has rows', `found: ${rowCount}`);

    const addBtn = await this.evaluate(() => {
      const btns = [...document.querySelectorAll('button')];
      return btns.some(b => b.textContent.trim() === 'Add Customer');
    });
    this.assert(addBtn, '"Add Customer" button visible');

    this.info('Clicking first customer row to navigate to detail...');
    const firstLink = await this.page.$(ROW_LINK).catch(() => null);
    if (firstLink) {
      await firstLink.click();
      await this.page.waitForTimeout(1500);
      const url = this.page.url();
      this.assert(
        /#\/customers\/\d+\/view\/?$/.test(url),
        'Navigated to customer detail page',
        url
      );
      await this.adminPage(this.route());
      await this.page.waitForSelector(this.pageSelector(), { timeout: 10000 }).catch(() => null);
    } else {
      this.assert(false, 'No customer row link found to click');
    }
  }
}

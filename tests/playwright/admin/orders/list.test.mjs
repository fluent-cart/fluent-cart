import BaseTableTest from '../../base-table-test.mjs';

const ROW_LINK = '.fct-orders-table-wrap .el-table__row a';

export default class OrdersListTest extends BaseTableTest {
  static title = 'Orders — List Loads & Row Navigation';

  route()             { return '/orders'; }
  apiEndpoint()       { return 'orders'; }
  pageSelector()      { return '.fct-all-orders-page'; }
  tableWrapSelector() { return '.fct-orders-table-wrap'; }
  expectedColumns()   { return ['Date', 'Customer', 'Total', 'Payment Status']; }

  async run() {
    await this._navigate();
    await this._testLoad();

    const rowCount = await this.evaluate((sel) => document.querySelectorAll(sel).length, this._rowSel);
    this.assert(rowCount > 0, 'Orders table has rows', `found: ${rowCount}`);

    // "Create Order" button
    const createHref = await this.evaluate(() => {
      const a = [...document.querySelectorAll('a')].find(el => el.textContent.trim() === 'Create Order');
      return a ? a.getAttribute('href') : null;
    });
    this.assert(!!createHref, '"Create Order" link present');
    this.assert(createHref && createHref.includes('orders/add'), '"Create Order" links to /orders/add', `href: ${createHref}`);

    // Row click navigates to order detail
    this.info('Clicking first order row to navigate to detail...');
    const firstLink = await this.page.$(ROW_LINK).catch(() => null);
    if (firstLink) {
      await firstLink.click();
      await this.page.waitForTimeout(1500);
      const url = this.page.url();
      this.assert(
        url.includes('/orders/') && url.includes('/view'),
        'Navigated to order detail page',
        url
      );
    } else {
      this.assert(false, 'No order row link found to click');
    }
  }
}

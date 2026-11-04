import BaseTableTest from '../../base-table-test.mjs';

export default class OrdersSavedViewTest extends BaseTableTest {
  static title = 'Orders — Saved View Create / Delete';

  route()       { return '/orders'; }
  apiEndpoint() { return 'orders'; }
  pageSelector(){ return '.fct-all-orders-page'; }
  searchFor()   { return 'admin'; }

  async run() {
    await this._navigate();
    await this._testSavedView();
  }
}

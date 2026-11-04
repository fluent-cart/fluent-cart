import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersSavedViewTest extends BaseTableTest {
  static title = 'Customers — Saved View';

  route()             { return '/customers'; }
  apiEndpoint()       { return 'customers'; }
  pageSelector()      { return '.fct-all-customers-page'; }
  tableWrapSelector() { return '.fct-table-wrapper'; }

  async run() {
    await this._navigate();
    await this._testSavedView();
  }
}

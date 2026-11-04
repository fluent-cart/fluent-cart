import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersSearchTest extends BaseTableTest {
  static title = 'Customers — Search';

  route()             { return '/customers'; }
  apiEndpoint()       { return 'customers'; }
  pageSelector()      { return '.fct-all-customers-page'; }
  tableWrapSelector() { return '.fct-table-wrapper'; }

  async run() {
    await this._navigate();
    await this._testSearch();
  }
}

import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersColumnToggleTest extends BaseTableTest {
  static title = 'Customers — Column Toggle';

  route()             { return '/customers'; }
  apiEndpoint()       { return 'customers'; }
  pageSelector()      { return '.fct-all-customers-page'; }
  tableWrapSelector() { return '.fct-table-wrapper'; }
  toggleColumn()      { return 'Customer Since'; }

  async run() {
    await this._navigate();
    await this._testColumnToggle();
  }
}

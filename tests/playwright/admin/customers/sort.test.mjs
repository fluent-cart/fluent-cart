import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersSortTest extends BaseTableTest {
  static title = 'Customers — Sort';

  route()             { return '/customers'; }
  apiEndpoint()       { return 'customers'; }
  pageSelector()      { return '.fct-all-customers-page'; }
  tableWrapSelector() { return '.fct-table-wrapper'; }
  sortByField()       { return 'Name'; }

  async run() {
    await this._navigate();
    await this._testSort();
  }
}

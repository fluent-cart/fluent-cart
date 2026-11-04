import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersPaginationTest extends BaseTableTest {
  static title = 'Customers — Pagination';

  route()             { return '/customers'; }
  apiEndpoint()       { return 'customers'; }
  pageSelector()      { return '.fct-all-customers-page'; }
  tableWrapSelector() { return '.fct-table-wrapper'; }

  async run() {
    await this._navigate();
    await this._testPagination();
  }
}

import BaseTableTest from '../../base-table-test.mjs';

export default class CustomersAdvancedFilterTest extends BaseTableTest {
  static title = 'Customers — Advanced Filter';

  route()                   { return '/customers'; }
  apiEndpoint()             { return 'customers'; }
  pageSelector()            { return '.fct-all-customers-page'; }
  tableWrapSelector()       { return '.fct-table-wrapper'; }
  advanceFilterWindowPath() { return 'fluentCartAdminApp.table_config.customers.filters.advance'; }

  async run() {
    await this._navigate();
    await this._testAdvancedFilter();
  }
}

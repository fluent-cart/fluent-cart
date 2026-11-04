import BaseTest from '../../base-test.mjs';

export default class TaxOverridesTest extends BaseTest {
  static title = 'Tax Overrides — Add / Verify / Delete / Reload';

  async run() {
    await this.adminPage('/settings/tax_settings/tax_rates_country_single/US/NA');
    await this.waitForText('Tax overrides', 8000);

    this.info('Opening Add Tax Override dialog...');
    const opened = await this.clickButton('Add Tax Override');
    if (!opened) { this.assert(false, 'Add Tax Override button not found'); return; }

    // Wait for dialog
    const dialogVisible = await this.page.waitForFunction(() => {
      return [...document.querySelectorAll('.el-overlay')].some(
        o => o.style.display !== 'none' && o.querySelector('.el-dialog')
      );
    }, { timeout: 5000 }).then(() => true).catch(() => false);

    if (!dialogVisible) { this.assert(false, 'Override dialog did not open'); return; }
    this.assert(true, 'Override dialog opened');

    // Wait for categories to load
    this.info('Selecting category...');
    await this.page.waitForTimeout(3000);

    // Count poppers before clicking so we find the NEW one
    const poppersBefore = 0;

    const categorySelected = await this.evaluate(async (pb) => {
      const overlay = [...document.querySelectorAll('.el-overlay')].find(
        o => o.style.display !== 'none' && o.querySelector('.el-dialog')
      );
      if (!overlay) return 'no-dialog';

      // Find Category form item
      const formItems = overlay.querySelectorAll('.el-form-item');
      let catItem = null;
      for (const item of formItems) {
        if (item.querySelector('label')?.textContent?.trim()?.toLowerCase().includes('category')) { catItem = item; break; }
      }
      if (!catItem) return 'no-category-field';

      // Click category select
      const trigger = catItem.querySelector('.el-select input, .el-select__wrapper');
      if (!trigger) return 'no-trigger';
      trigger.click();

      // Element Plus reuses closed poppers, so look for the visible dropdown.
      for (let i = 0; i < 20; i++) {
        await new Promise(r => setTimeout(r, 300));
        const open = [...document.querySelectorAll('.el-select-dropdown')].filter(d => d.getClientRects().length > 0).pop();
        const items = open ? [...open.querySelectorAll('.el-select-dropdown__item')].filter(i => !i.classList.contains('is-disabled')) : [];
        if (items.length > 0) { items[0].click(); return items[0].textContent.trim(); }
        if (open && open.querySelector('.el-select-dropdown__empty')) return 'no-categories';
      }
      return 'no-dropdown';
    }, poppersBefore);

    if (categorySelected === 'no-categories') {
      this.skip('Tax override CRUD', 'The store has no product category to pick');
      await this.closeDialog();
      return;
    }
    if (!categorySelected || categorySelected.startsWith('no-')) {
      this.assert(false, 'Category picked in the override dialog', categorySelected);
      await this.closeDialog();
      return;
    }
    this.assert(true, 'Category selected', categorySelected);

    // Set rate
    this.info('Setting rate to 3%...');
    await this.evaluate(() => {
      const overlay = [...document.querySelectorAll('.el-overlay')].find(o => o.style.display !== 'none' && o.querySelector('.el-dialog'));
      const inputs = overlay?.querySelectorAll('input[type="number"]') || [];
      if (inputs[0]) {
        const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        set.call(inputs[0], '3');
        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
      }
    });

    // Save
    const [res] = await Promise.all([
      this.waitForApi('tax/product-overrides'),
      this.evaluate(() => {
        const overlay = [...document.querySelectorAll('.el-overlay')].find(o => o.style.display !== 'none');
        const btn = [...(overlay?.querySelectorAll('button') || [])].find(b => b.textContent.includes('Add override'));
        if (btn) btn.click();
      })
    ]);
    this.assert(res?.ok, 'Override saved', `status: ${res?.status}`);
    if (!res?.ok) return;

    // Only rows of the overrides table count; "No overrides configured" text can sit elsewhere.
    const ROWS = '.fct-tax-rates-country-view .el-table__body-wrapper .el-table__row';
    const hasRow = (present) => this.page.waitForFunction(({ rows, name, present }) =>
      [...document.querySelectorAll(rows)].some(r => r.textContent.includes(name)) === present,
    { rows: ROWS, name: categorySelected, present }, { timeout: 8000 }).then(() => true).catch(() => false);

    await this.hardReload('.fct-tax-rates-country-view');
    this.assert(await hasRow(true), 'Override persists after reload', categorySelected);

    // Delete: the last button in the row is the delete icon.
    this.info('Deleting override...');
    await this.evaluate(({ rows, name }) => {
      const row = [...document.querySelectorAll(rows)].find(r => r.textContent.includes(name));
      [...(row?.querySelectorAll('button') || [])].pop()?.click();
    }, { rows: ROWS, name: categorySelected });
    await this.page.waitForSelector('.el-message-box', { timeout: 5000 }).catch(() => null);
    const deleted = await this.actionWithApi(() => this.confirmDialog(), 'tax/product-overrides/');
    this.assert(deleted?.ok, 'Override deleted via API', `status: ${deleted?.status}`);
    this.assert(await hasRow(false), 'Override row removed');

    await this.hardReload('.fct-tax-rates-country-view');
    this.assert(await hasRow(false), 'Override deletion persists after reload');
  }
}

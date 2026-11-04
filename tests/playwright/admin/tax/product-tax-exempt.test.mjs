import BaseTest from '../../base-test.mjs';

/**
 * Tax exemption is set per variation: VariationTaxClass.vue renders a
 * "Charge tax on this variation" checkbox in the pricing form while taxes are
 * enabled, and the product's Update button saves it with the pricing.
 */
const SEL = {
  checkbox: '.fct-product-pricing-form-wrap .fct-tax-class-control__checkbox',
  formReady: '.fct-product-pricing-wrap',
};

export default class ProductTaxExemptTest extends BaseTest {
  static title = 'Product Tax Exempt — Toggle / Reload';

  async run() {
    // A full load, so an earlier test's unsaved edits cannot hold the route.
    await this.goto('/wp-admin/admin.php?page=fluent-cart');
    await this.adminPage('/products');
    await this.page.waitForSelector('.el-table__row a', { timeout: 15000 }).catch(() => null);
    const productLink = await this.page.$eval(
      '.el-table__row a[href*="#/products/"]',
      el => el.getAttribute('href')
    ).catch(() => null);
    if (!productLink) { this.assert(false, 'Product found in the list'); return; }

    const productHash = productLink.replace(/.*#/, '');
    await this.adminPage(productHash);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);

    const taxEnabled = await this.evaluate(() => !!window.fluentCartAdminApp?.is_tax_enabled);
    if (!taxEnabled) {
      this.skip('Variation tax checkbox', 'Taxes are not enabled in FluentCart settings');
      return;
    }
    this.assert(!!(await this.page.$(SEL.checkbox)), '"Charge tax on this variation" checkbox visible');

    const wasCharged = await this._isChecked();
    this.info(`Toggling "Charge tax" ${wasCharged ? 'OFF' : 'ON'}...`);
    const toggled = await this._toggleAndSave();
    this.assert(toggled?.ok, 'Tax exemption saved via Update', `status: ${toggled?.status}`);

    await this.hardReload(SEL.formReady);
    await this.page.waitForSelector(SEL.checkbox, { timeout: 15000 }).catch(() => null);
    this.assert((await this._isChecked()) === !wasCharged, 'Tax exemption persists after reload');

    this.info('Restoring the original "Charge tax" state...');
    const restored = await this._toggleAndSave();
    this.assert(restored?.ok, 'Original tax state saved via Update', `status: ${restored?.status}`);

    await this.hardReload(SEL.formReady);
    await this.page.waitForSelector(SEL.checkbox, { timeout: 15000 }).catch(() => null);
    this.assert((await this._isChecked()) === wasCharged, 'Original tax state persists after reload');
  }

  async _isChecked() {
    return this.evaluate((sel) => !!document.querySelector(sel)?.classList.contains('is-checked'), SEL.checkbox);
  }

  async _toggleAndSave() {
    await this.evaluate((sel) => document.querySelector(sel)?.querySelector('input')?.click(), SEL.checkbox);
    await this.page.waitForSelector('.fct-save-bar button', { timeout: 5000 }).catch(() => null);
    return this.actionWithApi(
      () => this.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        [...(bar?.querySelectorAll('button') || [])].find(b => b.textContent.includes('Update'))?.click();
      }),
      '/pricing',
      'POST'
    );
  }
}

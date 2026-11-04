import BaseTest from '../../base-test.mjs';

export default class TaxConfigurationTest extends BaseTest {
  static title = 'Tax Configuration — Save / Reload';

  async run() {
    // saveSettings() ignores Save until the settings GET has answered.
    const loaded = this.waitForApi('tax/configuration/settings', 'GET');
    await this.adminPage('/settings/tax_settings');
    this.assert(!!(await loaded), 'Tax settings loaded');

    const text = await this.bodyText();
    this.assert(text.includes('Enable Tax'), 'Enable Tax toggle visible');
    this.assert(text.includes('Prices entered with tax'), 'Price inclusion setting visible');
    this.assert(text.includes('Calculate Tax Based On'), 'Tax basis setting visible');

    // Save and verify
    const [res] = await Promise.all([
      this.waitForApi('tax/configuration/settings'),
      this.clickButton('Save')
    ]);
    this.assert(!!res, 'Settings saved successfully');

    await this.hardReload();
    const reloaded = await this.waitForText('Enable Tax');
    this.assert(reloaded, 'Settings page loads correctly after reload');
  }
}

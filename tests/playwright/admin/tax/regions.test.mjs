import BaseTest from '../../base-test.mjs';

export default class TaxRegionsTest extends BaseTest {
  static title = 'Tax Regions Page';

  async run() {
    await this.adminPage('/settings/tax_settings/tax_rates');

    const text = await this.bodyText();
    this.assert(text.includes('Tax Regions') || text.includes('Region'), 'Page loads');

    // Search
    const searchInput = await this.page.$('input[placeholder*="Search"], input[placeholder*="search"]');
    if (searchInput) {
      await searchInput.fill('United');
      await this.page.waitForTimeout(500);
      this.assert(
        (await this.bodyText()).includes('United States') || (await this.bodyText()).includes('United Kingdom'),
        'Search filters countries'
      );
      await searchInput.fill('');
      await this.page.waitForTimeout(300);
    }

    this.assert((await this.bodyText()).includes('European Union'), 'European Union row present');
  }
}

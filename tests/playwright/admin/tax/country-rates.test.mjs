import BaseTest from '../../base-test.mjs';

/**
 * TaxRatesCountrySingle.vue lists the federal rate as the first row of
 * .fct-tax-rates-table and one row per state after it. Edits are saved
 * together by the "Save Rates" button, one request per changed row.
 */
const SEL = {
  container: '.fct-base-taxes',
  rows: '.fct-tax-rates-table .el-table__body-wrapper .el-table__row',
};

// Column 2 of a row holds the rate input.
const rateInput = (rowIndex) => `${SEL.rows}:nth-child(${rowIndex + 1}) td:nth-child(2) input`;

export default class CountryRatesTest extends BaseTest {
  static title = 'Country Rates — CRUD + Reload';

  async run() {
    await this.adminPage('/settings/tax_settings/tax_rates_country_single/US/NA');
    await this.page.waitForSelector(SEL.container, { timeout: 10000 }).catch(() => null);
    await this.page.waitForSelector(rateInput(1), { timeout: 10000 }).catch(() => null);

    const text = await this.bodyText();
    this.assert(text.includes('Standard'), 'Standard tab visible');
    this.assert(text.includes('Reduced'), 'Reduced tab visible');
    this.assert(text.includes('Zero'), 'Zero tab visible');

    const original = { federal: await this.getInput(rateInput(0)), state: await this.getInput(rateInput(1)) };
    this.assert(original.federal !== null && original.state !== null, 'Federal and state rate inputs present', JSON.stringify(original));

    // Both rows must change, or Save Rates sends nothing for the unchanged one.
    const federal = original.federal === '7' ? '8' : '7';
    const state = original.state === '4' ? '3' : '4';
    this.info(`Setting US federal rate to ${federal}% and the first state rate to ${state}%...`);
    await this.setInput(rateInput(0), federal);
    await this.setInput(rateInput(1), state);
    const saved = await this._saveRates(2);
    this.assert(saved.length === 2 && saved.every(r => r.ok), 'Federal and state rates saved via API', JSON.stringify(saved));

    await this.hardReload(SEL.container);
    await this.page.waitForSelector(rateInput(1), { timeout: 10000 }).catch(() => null);
    this.assert((await this.getInput(rateInput(0))) === federal, 'Federal rate persists after reload', `got: ${await this.getInput(rateInput(0))}`);
    this.assert((await this.getInput(rateInput(1))) === state, 'State rate persists after reload', `got: ${await this.getInput(rateInput(1))}`);

    // The compound select of a state row names the federal rate it applies to.
    this.assert((await this.bodyText()).includes(`${federal}% federal tax`), `Compound label shows "${federal}% federal tax"`);

    this.info(`Restoring rates to ${original.federal}% / ${original.state}%...`);
    await this.setInput(rateInput(0), original.federal);
    await this.setInput(rateInput(1), original.state);
    const restored = await this._saveRates(2);
    this.assert(restored.length === 2 && restored.every(r => r.ok), 'Original rates restored', JSON.stringify(restored));
  }

  // Resolves once `expected` rate saves have answered. Playwright reports a response
  // after the page has already handled it, so wait on the responses themselves.
  async _saveRates(expected) {
    const responses = [];
    let collect;
    const done = new Promise((resolve) => {
      collect = (response) => {
        const method = response.request().method();
        if (!response.url().includes('tax/country/rate') || method !== 'POST') return;
        responses.push({ ok: response.ok(), status: response.status() });
        if (responses.length === expected) resolve();
      };
      setTimeout(resolve, 10000);
    });
    this.page.on('response', collect);
    await this.clickButton('Save Rates');
    await done;
    this.page.off('response', collect);
    return responses;
  }
}

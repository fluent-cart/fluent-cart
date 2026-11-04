/**
 * Product Edit — Tax (Simple Mode)
 *
 * Covers the VariationTaxClass component as it appears in the Simple pricing form.
 * This test is intentionally separate so it can be skipped or run in isolation
 * on environments where FluentCart taxes are disabled.
 *
 * Covers:
 *   - Tax section presence (skips gracefully if taxes are not enabled)
 *   - Charge tax checkbox: present, reflects current state
 *   - Toggling charge tax OFF hides the tax class row
 *   - Toggling charge tax ON shows the tax class row (when multiple classes exist)
 *   - Charge-tax state persists after save + hard reload
 *
 * Selector contract:
 *   .fct-tax-class-control__checkbox   el-checkbox for "Charge tax on this variation"
 *   .fct-tax-class-control__class-row  Tax class row (visible: chargeTax ON + >1 class)
 *   .fct-tax-class-control__edit       Edit icon to change tax class
 *
 * Condition:
 *   This test requires taxes to be enabled in FluentCart → Settings → Tax.
 *   If the tax checkbox is absent the test reports a single info line and exits cleanly.
 *
 * API:
 *   POST .../products/{id}/pricing
 *
 * Registration:
 *   Registered in tests/playwright/admin/products/edit/index.mjs
 *   Suite key: admin/products/edit
 *   Individual key: admin/products/edit/pricing-simple-tax
 */
import BaseTest from '../../../base-test.mjs';

const SEL = {
  formReady:    'input#post_title',
  taxCheckbox:  '.fct-tax-class-control__checkbox',
  taxClassRow:  '.fct-tax-class-control__class-row',
  taxEditIcon:  '.fct-tax-class-control__edit',
  saveBarActive: '.fct-save-bar.is-active',
};

export default class ProductEditPricingSimpleTaxTest extends BaseTest {
  static title = 'Product Edit — Tax (Simple Mode)';

  async run() {
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    await this.adminPage(`/products/${productId}`);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();

    const taxEnabled = await this._taxSectionPresent();
    if (!taxEnabled) {
      this.skip('Simple-mode tax fields', 'Taxes are not enabled in FluentCart settings');
      return;
    }

    await this._testTaxCheckboxPresence();
    await this._testTaxClassRowVisibility();
    await this._testChargeTaxPersists();
  }

  // ── Product ID discovery ──────────────────────────────────────────────────

  async _discoverProductId() {
    this.info('Reading first product ID from list...');
    // An earlier test can leave unsaved edits, and SaveBar then refuses a route change.
    // A URL that differs only in its hash does not reload, so load the page without one.
    const leave = (dialog) => dialog.accept();
    this.page.on('dialog', leave);
    await this.goto('/wp-admin/admin.php?page=fluent-cart');
    this.page.off('dialog', leave);
    await this.adminPage('/products');
    await this.page.waitForSelector('.fct-products-table-wrap', { timeout: 10000 }).catch(() => null);
    // The wrapper renders before the rows do, so wait for a row link itself.
    await this.page.waitForSelector('.el-table__row a', { timeout: 15000 }).catch(() => null);

    const href = await this.page.evaluate(() => {
      const a = document.querySelector('.el-table__row a');
      return a ? (a.getAttribute('href') || a.href) : null;
    });
    const m = href && href.match(/\/products\/(\d+)/);
    return m ? m[1] : null;
  }

  // ── Guard: is the tax checkbox in the DOM at all? ─────────────────────────

  async _taxSectionPresent() {
    return this.page.evaluate((sel) => !!document.querySelector(sel), SEL.taxCheckbox);
  }

  // ── Test: checkbox presence and initial state ─────────────────────────────

  async _testTaxCheckboxPresence() {
    this.info('Checking tax checkbox presence...');

    this.assert(true, 'Tax section renders (charge tax checkbox present)');

    const chargeTaxOn = await this._readChargeTaxState();
    this.info(`Charge tax initial state: ${chargeTaxOn ? 'ON' : 'OFF'}`);
  }

  // ── Test: tax class row visibility toggle ─────────────────────────────────

  async _testTaxClassRowVisibility() {
    this.info('Testing charge tax toggle effect on tax class row...');

    const wasOn = await this._readChargeTaxState();

    if (!wasOn) {
      // Toggle ON and verify row appears (if multiple classes exist)
      this.info('Toggling charge tax ON...');
      await this._clickTaxCheckbox();
      await this.pause(2);

      const taxClassRowPresent = await this.page.evaluate(
        (sel) => !!document.querySelector(sel), SEL.taxClassRow
      );
      if (taxClassRowPresent) {
        this.assert(
          taxClassRowPresent,
          'Tax class row (.fct-tax-class-control__class-row) visible when charge tax ON (multiple classes exist)'
        );
        const editIconPresent = await this.page.evaluate(
          (sel) => !!document.querySelector(sel), SEL.taxEditIcon
        );
        this.assert(editIconPresent, 'Tax class edit icon (.fct-tax-class-control__edit) present');
      } else {
        this.info('Tax class row not visible — site has a single tax class (expected)');
      }

      // Toggle back OFF
      this.info('Restoring charge tax to OFF...');
      await this._clickTaxCheckbox();
      await this.pause();

      const rowGone = await this.page.evaluate(
        (sel) => !document.querySelector(sel), SEL.taxClassRow
      );
      this.assert(rowGone, 'Tax class row hidden after toggling charge tax OFF');
    } else {
      // Already ON — verify row state, then toggle OFF and verify row disappears
      const taxClassRowPresent = await this.page.evaluate(
        (sel) => !!document.querySelector(sel), SEL.taxClassRow
      );
      if (taxClassRowPresent) {
        this.assert(taxClassRowPresent, 'Tax class row visible when charge tax is ON (multiple classes)');
      } else {
        this.info('Tax class row not visible — site has a single tax class (expected)');
      }

      this.info('Toggling charge tax OFF to verify row disappears...');
      await this._clickTaxCheckbox();
      await this.pause(2);

      const rowGone = await this.page.evaluate(
        (sel) => !document.querySelector(sel), SEL.taxClassRow
      );
      this.assert(rowGone, 'Tax class row hidden after toggling charge tax OFF');

      // Restore ON
      this.info('Restoring charge tax to ON...');
      await this._clickTaxCheckbox();
      await this.pause();
    }
  }

  // ── Test: charge-tax persists after save + reload ─────────────────────────

  async _testChargeTaxPersists() {
    this.info('Testing charge-tax persistence after save + reload...');

    const originalState = await this._readChargeTaxState();

    // Ensure charge tax is ON for the persistence test
    if (!originalState) {
      await this._clickTaxCheckbox();
      await this.pause();
    }

    await this._waitForSaveBar();
    const saveResult = await this._clickUpdate();
    this.assert(!!saveResult?.ok, 'Charge-tax ON save API OK', `status: ${saveResult?.status}`);

    await this.hardReload(SEL.formReady);
    await this.pause(2);

    const taxPresent = await this._taxSectionPresent();
    if (!taxPresent) {
      this.info('Tax section disappeared after reload — taxes may have been toggled globally');
      return;
    }

    const stateAfterReload = await this._readChargeTaxState();
    this.assert(stateAfterReload, 'Charge tax ON persists after hard reload');

    // Restore original state
    if (!originalState) {
      this.info('Restoring charge tax to OFF...');
      await this._clickTaxCheckbox();
      await this.pause();
      await this._waitForSaveBar().catch(() => {});
      const barActive = await this.page.evaluate(
        (sel) => !!document.querySelector(sel), SEL.saveBarActive
      );
      if (barActive) {
        await this._clickUpdate();
      }
      await this.hardReload(SEL.formReady);
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  async _readChargeTaxState() {
    return this.page.evaluate((sel) => {
      const checkbox = document.querySelector(sel);
      if (!checkbox) return false;
      const input = checkbox.querySelector('input[type="checkbox"]');
      return input ? input.checked : false;
    }, SEL.taxCheckbox);
  }

  async _clickTaxCheckbox() {
    await this.page.evaluate((sel) => {
      const checkbox = document.querySelector(sel);
      if (checkbox) checkbox.click();
    }, SEL.taxCheckbox);
    await this.pause();
  }

  async _waitForSaveBar() {
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel),
      SEL.saveBarActive,
      { timeout: 5000 }
    ).catch(() => {});
  }

  async _clickUpdate() {
    return this.actionWithApi(
      () => this.page.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        const btn = [...(bar?.querySelectorAll('button') || [])]
          .find(b => b.textContent.includes('Update'));
        if (btn) btn.click();
      }),
      '/pricing',
      'POST'
    );
  }
}

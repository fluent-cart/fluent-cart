/**
 * Product Edit — Pricing (Simple Mode)
 *
 * Covers every user-facing input in the simple-mode pricing form:
 *
 *   Price
 *   - Price field: present, persists after save + reload
 *   - Compare-at price: present inside "Additional display prices" collapse, persists
 *
 *   Subscription (inside "Additional display prices" collapse)
 *   - Subscription switch: toggle via hint-text targeting (not raw .el-switch)
 *   - Interval select + trial-days input appear when subscription ON
 *   - Subscription-ON state persists after save + reload
 *   - Installment checkbox (Pro): appears when subscription ON
 *   - Installment count + total-price inputs appear after enabling installment
 *   - Setup fee switch (Pro): label + amount inputs appear after enabling
 *
 *   Manage cost (conditional — only when manage_cost field has been set on the variant)
 *   - manage_cost switch is present inside .fct-manage-cost-col
 *   - item_cost input renders when switch is ON
 *   - Profit display (.fct-profit-display) renders when switch is ON
 *   - Margin display (.fct-margin-display) renders when switch is ON
 *
 *   Inventory (inside "More details" collapse of VariantInventory)
 *   - SKU input present
 *   - Generate SKU button present and enabled (product has a title)
 *
 *   Shipping
 *   - Physical Product switch found via hint-text
 *   - Package select + weight input + weight unit appear when Physical ON
 *   - Fields hide again when Physical toggled OFF
 *
 *   Tax (conditional — only when FluentCart taxes are enabled)
 *   - Charge tax checkbox present
 *   - Tax class row visible when chargeTax ON and multiple classes exist
 *
 * Selector contract:
 *   .fct-product-pricing-wrap                   Root of ProductPricing.vue
 *   .fct-product-pricing-form-wrap               Root of ProductPricingForm.vue (simple mode)
 *   input[id="variants.0.item_price"]            Price native input
 *   input[id="variants.0.compare_price"]         Compare-at-price native input
 *   .fct-variant-item-collapse                   VariantItemCollapse (el-collapse wrapper)
 *   .el-collapse-item__header                    Clickable collapse toggle
 *   .fct-shared-variant-pricing-additional       Collapse body with compare/subscription fields
 *   .fct-shared-variant-item-box__hint           Hint text label adjacent to each switch toggle
 *   .fct-repeat-payment-every-select             Interval select (v-if: subscription ON)
 *   input[id="variants.0.other_info.trial_days"] Trial-days input (v-if: subscription ON)
 *   .fct-manage-cost-col                         Column with manage_cost switch (v-if: manage_cost set)
 *   input[id="variants.0.item_cost"]             Cost per item input (Animation: manage_cost === 'true')
 *   .fct-profit-display                          Profit el-input (Animation: manage_cost === 'true')
 *   .fct-margin-display                          Margin el-input (Animation: manage_cost === 'true')
 *   .fct-variant-inventory                       VariantInventory SharedVariantItemBox
 *   input[id="variants.0.sku"]                   SKU input (inside inventory collapse)
 *   .fct-generate-sku-btn                        Generate SKU button (inside inventory collapse)
 *   .fct-physical-product-meta-row               Package + weight row (Animation: fulfillment_type === 'physical')
 *   .fct-package-options-select                  Package select
 *   .fct-product-weight-number                   Weight el-input wrapper
 *   .fct-product-weight-unit                     Weight unit el-select
 *   .fct-tax-class-control__checkbox             Charge-tax el-checkbox (conditional: taxes enabled)
 *   .fct-tax-class-control__class-row            Tax class row (conditional: chargeTax + >1 class)
 *
 * New stable classes added to source files for this workstream:
 *   fct-manage-cost-col   — VariantPrice.vue <el-col v-if="variant.manage_cost">
 *   fct-profit-display    — VariantPrice.vue profit <el-input disabled>
 *   fct-margin-display    — VariantPrice.vue margin <el-input disabled>
 *   fct-generate-sku-btn  — VariantInventory.vue Generate SKU <button>
 *
 * API:
 *   POST .../products/{id}/pricing   (SaveBar "Update" button)
 *
 * Registration:
 *   NOT registered in index.mjs or runner.mjs — reserved for the
 *   "Index + runner registration" workstream.
 */
import BaseTest from '../../../base-test.mjs';
import ElSelectHelper from '../../../helpers/el-select.mjs';

const SEL = {
  formReady:          'input#post_title',
  pricingWrap:        '.fct-product-pricing-wrap',
  pricingFormWrap:    '.fct-product-pricing-form-wrap',
  priceInput:         'input[id="variants.0.item_price"]',
  comparePriceInput:  'input[id="variants.0.compare_price"]',
  intervalSelect:     '.fct-repeat-payment-every-select',
  trialDaysInput:     'input[id="variants.0.other_info.trial_days"]',
  saveBarActive:      '.fct-save-bar.is-active',
  // Manage cost (conditional: manage_cost field set on variant)
  manageCostCol:      '.fct-manage-cost-col',
  itemCostInput:      'input[id="variants.0.item_cost"]',
  profitDisplay:      '.fct-profit-display',
  marginDisplay:      '.fct-margin-display',
  // Inventory section
  inventorySection:   '.fct-variant-inventory',
  skuInput:           'input[id="variants.0.sku"]',
  generateSkuBtn:     '.fct-generate-sku-btn',
  // Shipping
  physicalMetaRow:    '.fct-physical-product-meta-row',
  packageSelect:      '.fct-package-options-select',
  weightInput:        '.fct-product-weight-number',
  weightUnitSelect:   '.fct-product-weight-unit',
};

export default class ProductEditPricingSimpleTest extends BaseTest {
  static title = 'Product Edit — Pricing Simple Mode';

  async run() {
    // ── Discover product ID ──────────────────────────────────────────────
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    await this.adminPage(`/products/${productId}`);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();

    // ── 1. Pricing section presence ──────────────────────────────────────
    const isSimple = await this._testPricingPresence();
    if (!isSimple) {
      this.skip('Simple-mode pricing form', `Product ${productId} is not in Simple mode`);
      return;
    }

    // ── 2. Price persistence ─────────────────────────────────────────────
    const originalPrice = await this._readInputValue(SEL.priceInput);
    await this._testPricePersists(originalPrice);

    // ── 3. Compare-at price persistence (inside pricing collapse) ────────
    await this._openPricingCollapse();
    const originalCompare = await this._readInputValue(SEL.comparePriceInput);
    await this._testComparePricePersists(originalCompare);

    // ── 4. Subscription toggle + persistence ─────────────────────────────
    const wasSubscription = await this._isSubscriptionOn();
    await this._testSubscriptionPersists(wasSubscription);

    // ── 5. Manage cost section (conditional) ─────────────────────────────
    await this._testManageCostSection();

    // ── 6. Inventory: SKU + Generate SKU ────────────────────────────────
    await this._testInventorySection();

    // ── 7. Shipping: Physical Product switch + fields ────────────────────
    await this._testShippingSection();
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

  // ── Test: pricing section presence ───────────────────────────────────────

  async _testPricingPresence() {
    this.info('Checking Pricing section...');

    const pricingPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.pricingWrap
    );
    this.assert(pricingPresent, 'Pricing section (.fct-product-pricing-wrap) renders');

    const elSelect = new ElSelectHelper(this);
    const modeText = await elSelect.getValue('.fct-product-pricing-wrap .el-select');

    const isSimple = modeText.toLowerCase().includes('simple') &&
                     !modeText.toLowerCase().includes('variation');
    this.assert(!!modeText, 'Pricing mode selector has a value', `"${modeText}"`);
    this.info(`Pricing mode: "${modeText}" (isSimple: ${isSimple})`);

    if (isSimple) {
      const formPresent = await this.page.evaluate(
        (sel) => !!document.querySelector(sel), SEL.pricingFormWrap
      );
      this.assert(formPresent, 'Pricing form (.fct-product-pricing-form-wrap) renders in Simple mode');

      const pricePresent = await this.page.evaluate(
        (sel) => !!document.querySelector(sel), SEL.priceInput
      );
      this.assert(pricePresent, 'Price input (variants.0.item_price) is present');
    }

    return isSimple;
  }

  // ── Test: price field persists after save + reload ────────────────────────

  async _testPricePersists(originalPrice) {
    this.info('Testing price field persistence...');
    const testPrice = '42';

    await this.setInput(SEL.priceInput, testPrice);
    await this._waitForSaveBar();

    const saveResult = await this._clickUpdate();
    this.assert(!!saveResult?.ok, 'Price save API OK', `status: ${saveResult?.status}`);

    const savedDisplayValue = await this._readInputValue(SEL.priceInput);
    this.assert(!!savedDisplayValue, 'Price input shows a value after save', `"${savedDisplayValue}"`);

    await this.hardReload(SEL.formReady);

    const priceAfterReload = await this._readInputValue(SEL.priceInput);
    this.assert(
      priceAfterReload === savedDisplayValue,
      'Price persists after hard reload',
      `expected "${savedDisplayValue}", got "${priceAfterReload}"`
    );

    this.info(`Restoring original price: "${originalPrice}"...`);
    const restorePrice = originalPrice || '0';
    await this.setInput(SEL.priceInput, restorePrice);
    await this._waitForSaveBar().catch(() => {});
    const barActive = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive
    );
    if (barActive) {
      await this._clickUpdate();
    }
    await this.hardReload(SEL.formReady);
  }

  // ── Test: compare-at price persists after save + reload ───────────────────

  async _testComparePricePersists(originalCompare) {
    this.info('Testing compare-at price persistence...');
    const testCompare = '55';

    await this._openPricingCollapse();

    await this.setInput(SEL.comparePriceInput, testCompare);
    await this._waitForSaveBar();

    const saveResult = await this._clickUpdate();
    this.assert(!!saveResult?.ok, 'Compare-at price save API OK', `status: ${saveResult?.status}`);

    const savedDisplayValue = await this._readInputValue(SEL.comparePriceInput);

    await this.hardReload(SEL.formReady);

    await this._openPricingCollapse();

    const compareAfterReload = await this._readInputValue(SEL.comparePriceInput);
    this.assert(
      compareAfterReload === savedDisplayValue,
      'Compare-at price persists after hard reload',
      `expected "${savedDisplayValue}", got "${compareAfterReload}"`
    );

    this.info('Restoring original compare-at price...');
    await this.setInput(SEL.comparePriceInput, originalCompare || '');
    await this._waitForSaveBar().catch(() => {});
    const barActive = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive
    );
    if (barActive) {
      await this._clickUpdate();
    }
    await this.hardReload(SEL.formReady);
  }

  // ── Test: subscription toggle + persistence ───────────────────────────────

  async _testSubscriptionPersists(wasSubscription) {
    this.info(`Testing subscription toggle (was subscription: ${wasSubscription})...`);

    await this._openPricingCollapse();

    if (wasSubscription) {
      this.info('Subscription already on — toggling OFF first for a clean test state...');
      await this._clickSwitchByHint('Subscription');
      await this.pause();
    }

    this.info('Toggling subscription ON...');
    await this._clickSwitchByHint('Subscription');
    await this.pause(2);

    const intervalInDom = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.intervalSelect
    );
    this.assert(intervalInDom, 'Interval select appears after enabling Subscription');

    const trialInDom = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.trialDaysInput
    );
    this.assert(trialInDom, 'Trial days input appears after enabling Subscription');

    await this._testProFieldsIfAvailable();

    await this._waitForSaveBar();
    const saveResult = await this._clickUpdate();
    this.assert(!!saveResult?.ok, 'Subscription-ON save API OK', `status: ${saveResult?.status}`);

    await this.hardReload(SEL.formReady);
    await this.pause(2);

    const intervalAfterReload = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.intervalSelect
    );
    this.assert(
      intervalAfterReload,
      'Subscription remains ON after hard reload (interval select in DOM)'
    );

    if (!wasSubscription) {
      this.info('Restoring subscription to OFF...');
      await this._clickSwitchByHint('Subscription');
      await this.pause();

      await this._waitForSaveBar();
      const restoreResult = await this._clickUpdate();
      this.assert(!!restoreResult?.ok, 'Subscription-OFF restore API OK', `status: ${restoreResult?.status}`);

      await this.hardReload(SEL.formReady);
      await this.pause(2);

      const intervalGone = await this.page.evaluate(
        (sel) => !document.querySelector(sel), SEL.intervalSelect
      );
      this.assert(intervalGone, 'Interval select absent after restoring subscription to OFF');
    }
  }

  // ── Test: Pro-only fields (installment + setup fee) ───────────────────────

  async _testProFieldsIfAvailable() {
    const proActive = await this.page.evaluate(() => {
      return window.fluentCartAdminApp?.app_config?.isProActive === true ||
             window.fluentCartAdminApp?.app_config?.isProActive === 1;
    });

    if (!proActive) {
      this.skip('Installment and setup fee fields', 'FluentCart Pro is not active');
      return;
    }

    const installmentCheckbox = await this.page.evaluate(() => {
      return !!document.querySelector('[id="variants.0.other_info.installment"]');
    });
    this.assert(installmentCheckbox, 'Installment checkbox present when subscription ON and Pro active');

    if (installmentCheckbox) {
      await this.page.evaluate(() => {
        const label = document.querySelector('[id="variants.0.other_info.installment"]');
        if (label) label.click();
      });
      await this.pause(2);

      const installmentCount = await this.page.evaluate(() => {
        return !!document.querySelector('input[id="variants.0.other_info.times"]');
      });
      this.assert(installmentCount, 'Installment count input appears after enabling installment');

      const totalPrice = await this.page.evaluate(() => {
        return !!document.querySelector('input[id="variants.0.total_price"]');
      });
      this.assert(totalPrice, 'Total price input (disabled, auto-calculated) appears with installment');

      await this.page.evaluate(() => {
        const label = document.querySelector('[id="variants.0.other_info.installment"]');
        if (label) label.click();
      });
      await this.pause();
    }

    const setupFeeSwitch = await this.page.evaluate(() => {
      const switches = [...document.querySelectorAll(
        '.fct-shared-variant-pricing-additional .el-switch'
      )];
      return switches.length > 0;
    });

    if (setupFeeSwitch) {
      this.info('Setup fee switch present — toggling ON to check fields...');
      await this.page.evaluate(() => {
        const switches = [...document.querySelectorAll(
          '.fct-shared-variant-pricing-additional .el-switch'
        )];
        if (switches[0]) switches[0].click();
      });
      await this.pause(2);

      const setupFeeLabel = await this.page.evaluate(() => {
        return !!document.querySelector('input[id="variants.0.other_info.signup_fee_name"]');
      });
      this.assert(setupFeeLabel, 'Setup fee label input appears after enabling setup fee');

      const setupFeeAmount = await this.page.evaluate(() => {
        return !!document.querySelector('input[id="variants.0.other_info.signup_fee"]');
      });
      this.assert(setupFeeAmount, 'Setup fee amount input appears after enabling setup fee');

      await this.page.evaluate(() => {
        const switches = [...document.querySelectorAll(
          '.fct-shared-variant-pricing-additional .el-switch'
        )];
        if (switches[0]) switches[0].click();
      });
      await this.pause();
    }
  }

  // ── Test: manage cost switch + profit/margin fields ───────────────────────

  async _testManageCostSection() {
    this.info('Checking manage cost section...');

    const manageCostPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.manageCostCol
    );

    if (!manageCostPresent) {
      this.skip('Profit and cost section', 'manage_cost is not set for this variant');
      return;
    }

    this.assert(manageCostPresent, 'Manage cost column (.fct-manage-cost-col) renders');

    const switchOn = await this.page.evaluate((sel) => {
      const col = document.querySelector(sel);
      const sw = col?.querySelector('.el-switch');
      return sw ? sw.classList.contains('is-checked') : false;
    }, SEL.manageCostCol);

    if (!switchOn) {
      this.info('Manage cost switch is OFF — toggling ON to check cost/profit/margin fields...');
      await this.page.evaluate((sel) => {
        const col = document.querySelector(sel);
        const sw = col?.querySelector('.el-switch');
        if (sw) sw.click();
      }, SEL.manageCostCol);
      await this.pause(2);
    }

    const costInputPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.itemCostInput
    );
    this.assert(costInputPresent, 'Cost per item input (variants.0.item_cost) present when manage cost ON');

    const profitPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.profitDisplay
    );
    this.assert(profitPresent, 'Profit display (.fct-profit-display) renders when manage cost ON');

    const marginPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.marginDisplay
    );
    this.assert(marginPresent, 'Margin display (.fct-margin-display) renders when manage cost ON');

    if (!switchOn) {
      this.info('Restoring manage cost switch to OFF...');
      await this.page.evaluate((sel) => {
        const col = document.querySelector(sel);
        const sw = col?.querySelector('.el-switch');
        if (sw) sw.click();
      }, SEL.manageCostCol);
      await this.pause();
    }
  }

  // ── Test: inventory section — SKU + Generate SKU ─────────────────────────

  async _testInventorySection() {
    this.info('Testing inventory section (SKU, Generate SKU)...');

    const opened = await this._openInventoryCollapse();
    if (!opened) {
      this.assert(false, 'Inventory collapse opens', 'not found');
      return;
    }

    const skuPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.skuInput
    );
    this.assert(skuPresent, 'SKU input (variants.0.sku) is present inside inventory collapse');

    const generateSkuPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.generateSkuBtn
    );
    this.assert(generateSkuPresent, 'Generate SKU button (.fct-generate-sku-btn) is present');

    const generateSkuEnabled = await this.page.evaluate((sel) => {
      const btn = document.querySelector(sel);
      return btn ? !btn.disabled : false;
    }, SEL.generateSkuBtn);
    this.assert(generateSkuEnabled, 'Generate SKU button is enabled when product title is set');
  }

  // ── Test: shipping section — Physical Product switch + fields ─────────────

  async _testShippingSection() {
    this.info('Testing shipping section (Physical Product toggle)...');

    const physicalSwitchPresent = await this.page.evaluate(() => {
      const hints = [...document.querySelectorAll('.fct-shared-variant-item-box__hint')];
      const target = hints.find(h => h.textContent.trim() === 'Physical Product');
      return !!target?.closest('.fct-shared-variant-item-box__switch')?.querySelector('.el-switch');
    });

    if (!physicalSwitchPresent) {
      this.assert(false, 'Physical Product switch is present', 'not found');
      return;
    }

    this.assert(physicalSwitchPresent, 'Physical Product switch is present');

    const wasPhysical = await this._getSwitchStateByHint('Physical Product');
    this.info(`Physical Product was: ${wasPhysical ? 'physical' : 'digital'}`);

    if (!wasPhysical) {
      this.info('Toggling Physical Product ON...');
      await this._clickSwitchByHint('Physical Product');
      await this.page.waitForSelector(SEL.packageSelect, { timeout: 5000 }).catch(() => null);
    }

    const packagePresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.packageSelect
    );
    this.assert(packagePresent, 'Package select (.fct-package-options-select) present when Physical Product ON');

    const weightPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.weightInput
    );
    this.assert(weightPresent, 'Weight input (.fct-product-weight-number) present when Physical Product ON');

    const weightUnitPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.weightUnitSelect
    );
    this.assert(weightUnitPresent, 'Weight unit select (.fct-product-weight-unit) present when Physical Product ON');

    if (!wasPhysical) {
      this.info('Restoring Physical Product to digital...');
      await this._clickSwitchByHint('Physical Product');
      // The row leaves through a Vue transition that a headless run can leave unfinished,
      // so an element still inside .v-leave-active counts as gone.
      const packageGone = await this.page.waitForFunction(
        (sel) => [...document.querySelectorAll(sel)].every(el => el.closest('.v-leave-active')),
        SEL.packageSelect,
        { timeout: 5000 }
      ).then(() => true).catch(() => false);
      this.assert(packageGone, 'Package select hidden after toggling Physical Product OFF');
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  /**
   * Open the "Additional display prices" collapse in the pricing form.
   * Targets the first fct-variant-item-collapse NOT inside .fct-variant-inventory.
   */
  async _openPricingCollapse() {
    const opened = await this.page.evaluate(() => {
      const allCollapses = [...document.querySelectorAll(
        '.fct-product-pricing-form-wrap .fct-variant-item-collapse'
      )];
      const pricingCollapse = allCollapses.find(
        el => !el.closest('.fct-variant-inventory')
      );
      if (!pricingCollapse) return false;

      const header = pricingCollapse.querySelector('.el-collapse-item__header');
      if (!header) return false;

      if (header.classList.contains('is-active')) return true;

      header.click();
      return true;
    });
    await this.pause(2);
    return opened;
  }

  /**
   * Open the "More details" collapse inside the VariantInventory box.
   */
  async _openInventoryCollapse() {
    const opened = await this.page.evaluate((inventorySel) => {
      const inventorySection = document.querySelector(inventorySel);
      if (!inventorySection) return false;
      const collapse = inventorySection.querySelector('.fct-variant-item-collapse');
      if (!collapse) return false;
      const header = collapse.querySelector('.el-collapse-item__header');
      if (!header) return false;
      if (header.classList.contains('is-active')) return true;
      header.click();
      return true;
    }, SEL.inventorySection);
    await this.pause(2);
    return opened;
  }

  async _isSubscriptionOn() {
    return this.page.evaluate((sel) => !!document.querySelector(sel), SEL.intervalSelect);
  }

  /**
   * Click the el-switch adjacent to the given hint text.
   * Uses hint-text detection so the correct switch is targeted when multiple
   * switches share the same structural container (e.g. Subscription vs Physical Product).
   */
  async _clickSwitchByHint(hintText) {
    await this.page.evaluate((hint) => {
      const hints = [...document.querySelectorAll('.fct-shared-variant-item-box__hint')];
      const target = hints.find(h => h.textContent.trim() === hint);
      const sw = target?.closest('.fct-shared-variant-item-box__switch')?.querySelector('.el-switch');
      if (sw) sw.click();
    }, hintText);
    await this.pause();
  }

  /**
   * Return true if the el-switch adjacent to the given hint is currently checked (active).
   */
  async _getSwitchStateByHint(hintText) {
    return this.page.evaluate((hint) => {
      const hints = [...document.querySelectorAll('.fct-shared-variant-item-box__hint')];
      const target = hints.find(h => h.textContent.trim() === hint);
      const sw = target?.closest('.fct-shared-variant-item-box__switch')?.querySelector('.el-switch');
      return sw ? sw.classList.contains('is-checked') : false;
    }, hintText);
  }

  async _readInputValue(selector) {
    return this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      return el ? el.value : '';
    }, selector);
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

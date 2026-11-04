/**
 * Product Edit — Page Chrome & Save Bar Smoke Test
 *
 * Covers:
 *   - Page chrome: header, name, status badge, tab list, edit wrapper
 *   - Save bar hidden on clean load
 *   - Title edit → Update → hard reload → value persists
 *   - Discard changes → page reload → value reverts to saved state
 *
 * Selector contract:
 *   .fct-admin-product-header              PHP-injected product header block
 *   .fct-admin-product-info .product-name  Product name span in header
 *   .fct-admin-product-info .badge         Status badge span in header
 *   .fct-admin-product-menu                Tab nav <ul>
 *   .fct-admin-product-menu li             One <li> per tab
 *   .fct-admin-product-menu li.active-menu Currently active tab
 *   .fct-edit-product-wrapper              Root wrapper rendered by EditProduct.vue
 *   .single-page-body                      Body rendered when isEditingReady
 *   .fct-save-bar                          SaveBar component root (always in DOM)
 *   .fct-save-bar.is-active                Save bar when unsaved changes exist
 *   input#post_title                       Title <input> (id set by El Plus el-input)
 *
 * API:
 *   POST .../products/{id}/pricing         Product save endpoint
 *
 * Registration:
 *   Registered in tests/playwright/admin/products/edit/index.mjs
 *   Individual key: admin/products/edit/smoke
 */
import BaseTest from '../../../base-test.mjs';

const SEL = {
  pageWrapper:   '.fct-edit-product-wrapper',
  productHeader: '.fct-admin-product-header',
  productName:   '.fct-admin-product-info .product-name',
  statusBadge:   '.fct-admin-product-info .badge',
  tabList:       '.fct-admin-product-menu',
  tabItems:      '.fct-admin-product-menu li',
  activeTab:     '.fct-admin-product-menu li.active-menu',
  saveBar:       '.fct-save-bar',
  saveBarActive: '.fct-save-bar.is-active',
  // El Plus applies :id to the native <input> element
  titleInput:    'input#post_title',
  // Used for hardReload wait — present once the form is fully rendered
  formReady:     'input#post_title',
};

export default class ProductEditSmokeTest extends BaseTest {
  static title = 'Product Edit — Page Chrome & Save Bar';

  async run() {
    // ── Discover a real product ID from the list table ──────────────────
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered from list', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    // ── Navigate to the product edit page ────────────────────────────────
    await this.adminPage(`/products/${productId}`);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();

    // ── 1. Assert page chrome ────────────────────────────────────────────
    await this._testPageChrome();

    // ── 2. Assert save bar hidden on clean load ──────────────────────────
    await this._testSaveBarHiddenOnLoad();

    // ── 3. Title edit → save → hard reload → assert persistence ─────────
    const originalTitle = await this._getTitleValue();
    this.assert(!!originalTitle, 'Title input has a value on load', `"${originalTitle}"`);
    await this._testTitleEditPersists(productId, originalTitle);

    // ── 4. Discard changes → reload → assert reverts ─────────────────────
    const restoredTitle = await this._getTitleValue();
    await this._testDiscardReverts(restoredTitle);
  }

  // ── Discover product ID ───────────────────────────────────────────────────

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

  // ── Test: page chrome ─────────────────────────────────────────────────────

  async _testPageChrome() {
    this.info('Checking page chrome...');

    const headerExists = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.productHeader
    );
    this.assert(headerExists, 'Product header present (.fct-admin-product-header)');

    const productName = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.productName
    );
    this.assert(!!productName, 'Product name shown in header', `"${productName}"`);

    const badgeText = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.statusBadge
    );
    this.assert(!!badgeText, 'Status badge shown in header', `"${badgeText}"`);

    const tabCount = await this.page.evaluate(
      (sel) => document.querySelectorAll(sel).length, SEL.tabItems
    );
    this.assert(tabCount >= 1, 'Product nav tabs rendered', `found ${tabCount}`);

    const activeTabText = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim()?.toLowerCase() || '', SEL.activeTab
    );
    this.assert(
      activeTabText.includes('edit'),
      'Active tab is "Edit Product" on initial load',
      `"${activeTabText}"`
    );

    const wrapperExists = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.pageWrapper
    );
    this.assert(wrapperExists, 'Edit product wrapper (.fct-edit-product-wrapper) present');
  }

  // ── Test: save bar hidden on fresh load ───────────────────────────────────

  async _testSaveBarHiddenOnLoad() {
    this.info('Checking save bar is not active on fresh load...');
    const isActive = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive
    );
    this.assert(!isActive, 'Save bar not active (.fct-save-bar.is-active absent) on initial load');
  }

  // ── Test: title edit persists after hard reload ───────────────────────────

  async _testTitleEditPersists(productId, originalTitle) {
    this.info('Testing: edit title → save → hard reload → assert persisted...');
    const editedTitle = (originalTitle || 'Product') + ' SMOKE';

    // 1. Edit title — triggers onChangeInputField → hasChange = true → is-active
    await this.setInput('#post_title', editedTitle);

    // 2. Wait for save bar to become active
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel),
      SEL.saveBarActive,
      { timeout: 5000 }
    ).catch(() => {});
    const barActive = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive
    );
    this.assert(barActive, 'Save bar activates (.fct-save-bar.is-active) after title change');

    // 3. Click Update and wait for POST .../pricing
    const apiResult = await this.actionWithApi(
      () => this.page.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        const btn = [...(bar?.querySelectorAll('button') || [])]
          .find(b => b.textContent.includes('Update'));
        if (btn) btn.click();
      }),
      '/pricing',
      'POST'
    );
    this.assert(!!apiResult?.ok, 'Product save API OK', `status: ${apiResult?.status}`);

    // 4. Save bar deactivates after successful save
    await this.page.waitForFunction(
      (sel) => !document.querySelector(sel),
      SEL.saveBarActive,
      { timeout: 5000 }
    ).catch(() => {});
    const barGone = await this.page.evaluate(
      (sel) => !document.querySelector(sel), SEL.saveBarActive
    );
    this.assert(barGone, 'Save bar deactivates after save');

    // 5. Hard reload and assert the value persisted
    await this.hardReload(SEL.formReady);
    const titleAfterReload = await this._getTitleValue();
    this.assert(
      titleAfterReload === editedTitle,
      'Title persists after hard reload',
      `expected "${editedTitle}", got "${titleAfterReload}"`
    );

    // 6. Cleanup: restore original title so subsequent tests and data are clean
    await this._saveTitle(originalTitle);
  }

  // ── Test: discard changes reverts to last saved state ────────────────────

  async _testDiscardReverts(savedTitle) {
    this.info('Testing: dirty title → Discard → reload → assert reverts...');
    const dirtyTitle = (savedTitle || 'Product') + ' DISCARD';

    // 1. Make a dirty edit
    await this.setInput('#post_title', dirtyTitle);

    // 2. Confirm save bar activates
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel),
      SEL.saveBarActive,
      { timeout: 5000 }
    ).catch(() => {});
    const barActive = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive
    );
    this.assert(barActive, 'Save bar active before discard');

    // 3. Click Discard — triggers window.location.reload() in ProductEditModel.discard()
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 })
        .catch(() => {}),
      this.page.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        const btn = [...(bar?.querySelectorAll('button') || [])]
          .find(b => b.textContent.includes('Discard'));
        if (btn) btn.click();
      }).catch(() => {}),
    ]);

    // 4. Wait for form to be ready after reload
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();
    await this._ensureOverlay();

    // 5. Assert title reverts to the last saved value
    const titleAfterDiscard = await this._getTitleValue();
    this.assert(
      titleAfterDiscard === savedTitle,
      'Title reverts to saved value after discard',
      `expected "${savedTitle}", got "${titleAfterDiscard}"`
    );

    // 6. Save bar should be inactive after the clean reload
    const barNotActive = await this.page.evaluate(
      (sel) => !document.querySelector(sel), SEL.saveBarActive
    );
    this.assert(barNotActive, 'Save bar not active after discard/reload');
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  async _getTitleValue() {
    return this.page.evaluate(() => {
      const input = document.querySelector('input#post_title');
      return input ? input.value : '';
    });
  }

  async _saveTitle(title) {
    this.info(`Saving title: "${title}"...`);
    await this.setInput('#post_title', title);
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel),
      SEL.saveBarActive,
      { timeout: 5000 }
    ).catch(() => {});
    const apiResult = await this.actionWithApi(
      () => this.page.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        const btn = [...(bar?.querySelectorAll('button') || [])]
          .find(b => b.textContent.includes('Update'));
        if (btn) btn.click();
      }),
      '/pricing',
      'POST'
    );
    this.assert(!!apiResult?.ok, `Title "${title}" saved`, `status: ${apiResult?.status}`);
    await this.page.waitForFunction(
      (sel) => !document.querySelector(sel),
      SEL.saveBarActive,
      { timeout: 5000 }
    ).catch(() => {});
    await this.hardReload(SEL.formReady);
  }
}

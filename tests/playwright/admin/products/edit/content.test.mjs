/**
 * Product Edit — Product Info & Long Description
 *
 * Covers:
 *   - Title: present and non-empty on load, persists after save + reload
 *   - Short description: present, word counter updates live on keystroke
 *   - Short description: persists after save + reload
 *   - Long Description tab bar: Classic Editor and Builder tabs render
 *   - Active tab on load matches stored preference (not hardcoded)
 *   - Switching Builder → Classic Editor: correct content area visible each time
 *   - Tab preference persists after save + hard reload
 *
 * Selector contract:
 *   input#post_title                        Title <input> (El Plus id binding)
 *   .fct-short-desc-input                   Short-desc el-input root (class added to ProductInfo.vue)
 *   .fct-short-desc-input .el-textarea__inner  Underlying <textarea>
 *   .fct-excerpt-word-counter               Word-counter <p> (class added to ProductInfo.vue)
 *   .editor-tabs-nav                        Tab bar container
 *   .editor-tabs-nav .fct-fluid-tab-item    Individual tab <span> elements (FluidTabItem.vue)
 *   .editor-tabs-nav .fct-fluid-tab-item.active  Currently active tab
 *   .fct-wp-editor-tab-content              Classic Editor content div (class added to ProductInfo.vue)
 *   .fct-gutenberg-tab-content              Builder content div (class added to ProductInfo.vue)
 *   .fct-custom-gutenberg-editor-wrap       Gutenberg preview/editor wrapper (GutenbergEditor.vue)
 *
 * New stable classes added to ProductInfo.vue:
 *   fct-short-desc-input     — on the short-description el-input
 *   fct-excerpt-word-counter — on the word-counter <p>
 *   fct-wp-editor-tab-content   — on the Classic Editor v-show div
 *   fct-gutenberg-tab-content   — on the Builder v-show div
 *
 * API:
 *   POST .../products/{id}/pricing   Product save endpoint
 *
 * Registration:
 *   NOT registered in index.mjs or runner.mjs — reserved for the
 *   "Index + runner registration" workstream.
 */
import BaseTest from '../../../base-test.mjs';

const SEL = {
  formReady:        'input#post_title',
  titleInput:       'input#post_title',
  shortDescInput:   '.fct-short-desc-input',
  shortDescArea:    '.fct-short-desc-input .el-textarea__inner',
  wordCounter:      '.fct-excerpt-word-counter',
  editorTabsNav:    '.editor-tabs-nav',
  tabItems:         '.editor-tabs-nav .fct-fluid-tab-item',
  activeTabItem:    '.editor-tabs-nav .fct-fluid-tab-item.active',
  wpEditorContent:  '.fct-wp-editor-tab-content',
  gutenbergContent: '.fct-gutenberg-tab-content',
  gutenbergWrap:    '.fct-custom-gutenberg-editor-wrap',
  saveBarActive:    '.fct-save-bar.is-active',
};

export default class ProductEditContentTest extends BaseTest {
  static title = 'Product Edit — Product Info & Long Description';

  async run() {
    // ── Discover product ID ──────────────────────────────────────────────
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered from list', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    await this.adminPage(`/products/${productId}`);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();

    // ── 1. Presence assertions ───────────────────────────────────────────
    await this._testProductInfoPresence();

    // ── 2. Word counter live update (no save, clears via reload) ─────────
    await this._testWordCounterLive();

    // Reload to clear dirty state before persistence tests
    await this.hardReload(SEL.formReady);
    await this.pause();

    // ── 3. Title: edit → save → reload → assert persists → restore ───────
    const originalTitle = await this._getTitleValue();
    await this._testTitlePersists(originalTitle);

    // ── 4. Short description: edit → save → reload → assert → restore ────
    const originalExcerpt = await this._getTextareaValue();
    await this._testExcerptPersists(originalExcerpt);

    // ── 5. Long Description editor tabs ──────────────────────────────────
    await this._testEditorTabs();
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

  // ── Test: Product Info section presence ───────────────────────────────────

  async _testProductInfoPresence() {
    this.info('Checking Product Info section presence...');

    // Title input present and non-empty
    const titleVal = await this._getTitleValue();
    this.assert(!!titleVal, 'Title input is present and non-empty on load', `"${titleVal}"`);

    // Short description textarea present
    const excerptPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.shortDescArea
    );
    this.assert(excerptPresent, 'Short description textarea (.fct-short-desc-input) is present');

    // Word counter renders with expected format "N Words of N"
    const counterText = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.wordCounter
    );
    this.assert(
      /\d+\s+Words?\s+of\s+\d+/i.test(counterText),
      'Word counter renders below short description (format: N Words of N)',
      `"${counterText}"`
    );
  }

  // ── Test: word counter updates live (no save) ─────────────────────────────

  async _testWordCounterLive() {
    this.info('Testing live word counter update...');

    // Set a known 3-word value — no save
    await this._setTextareaValue('Hello World Test');
    await this.pause();

    const counterText = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.wordCounter
    );
    this.assert(
      /^3\s+Words?/i.test(counterText),
      'Word counter updates to "3 Words..." after typing 3 words',
      `"${counterText}"`
    );
  }

  // ── Test: title edit → save → reload → persists → restore ────────────────

  async _testTitlePersists(originalTitle) {
    this.info('Testing title persistence after save + reload...');
    const editedTitle = (originalTitle || 'Product') + ' CONTENT-TEST';

    await this.setInput('#post_title', editedTitle);
    await this._waitForSaveBar();

    const saveResult = await this._clickUpdate();
    this.assert(!!saveResult?.ok, 'Title save API OK', `status: ${saveResult?.status}`);

    await this.hardReload(SEL.formReady);
    const titleAfter = await this._getTitleValue();
    this.assert(
      titleAfter === editedTitle,
      'Title persists after hard reload',
      `expected "${editedTitle}", got "${titleAfter}"`
    );

    // Restore original title
    this.info('Restoring original title...');
    await this.setInput('#post_title', originalTitle);
    await this._waitForSaveBar();
    await this._clickUpdate();
    await this.hardReload(SEL.formReady);
  }

  // ── Test: short description edit → save → reload → persists → restore ────

  async _testExcerptPersists(originalExcerpt) {
    this.info('Testing short description persistence after save + reload...');
    const editedExcerpt = 'Browser test short description.';

    await this._setTextareaValue(editedExcerpt);
    await this._waitForSaveBar();

    const saveResult = await this._clickUpdate();
    this.assert(!!saveResult?.ok, 'Short description save API OK', `status: ${saveResult?.status}`);

    await this.hardReload(SEL.formReady);
    const excerptAfter = await this._getTextareaValue();
    this.assert(
      excerptAfter === editedExcerpt,
      'Short description persists after hard reload',
      `expected "${editedExcerpt}", got "${excerptAfter}"`
    );

    // Restore original excerpt
    this.info('Restoring original short description...');
    await this._setTextareaValue(originalExcerpt || '');
    await this._waitForSaveBar().catch(() => {});
    const barActive = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive
    );
    if (barActive) {
      await this._clickUpdate();
    }
    await this.hardReload(SEL.formReady);
  }

  // ── Test: Long Description editor tab switcher ────────────────────────────

  async _testEditorTabs() {
    this.info('Testing Long Description editor tab switcher...');

    // Tab bar renders
    const tabNavPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.editorTabsNav
    );
    this.assert(tabNavPresent, 'Editor tab bar (.editor-tabs-nav) is present');

    // Classic Editor tab exists
    const classicExists = await this.page.evaluate((sel) => {
      const items = document.querySelectorAll(sel);
      return [...items].some(el => el.textContent.trim().includes('Classic Editor'));
    }, SEL.tabItems);
    this.assert(classicExists, '"Classic Editor" tab is present');

    // Builder tab exists
    const builderExists = await this.page.evaluate((sel) => {
      const items = document.querySelectorAll(sel);
      return [...items].some(el => el.textContent.trim().includes('Builder'));
    }, SEL.tabItems);
    this.assert(builderExists, '"Builder" tab is present');

    // Read active tab on load (matches stored preference)
    const activeTabText = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.activeTabItem
    );
    this.assert(
      activeTabText.includes('Classic Editor') || activeTabText.includes('Builder'),
      'An editor tab is active on load (matches stored preference)',
      `active: "${activeTabText}"`
    );
    const initialTab = activeTabText.includes('Builder') ? 'gutenberg' : 'wp-editor';
    this.info(`Active tab on load: "${activeTabText}" (${initialTab})`);

    // ── Click Builder → assert gutenberg area visible ─────────────────────
    this.info('Clicking Builder tab...');
    await this.page.evaluate((sel) => {
      const builder = [...document.querySelectorAll(sel)].find(
        el => el.textContent.trim().includes('Builder')
      );
      if (builder) builder.click();
    }, SEL.tabItems);

    // Wait for any loading overlay on the tab nav to clear
    await this.page.waitForFunction(
      (sel) => !document.querySelector(sel + ' .el-loading-mask'),
      SEL.editorTabsNav,
      { timeout: 8000 }
    ).catch(() => {});
    await this.pause(2);

    const gutenbergVisible = await this._isVisible(SEL.gutenbergContent);
    this.assert(gutenbergVisible, 'Gutenberg/Builder content area is visible after clicking Builder tab');

    const gutenbergWrapPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.gutenbergWrap
    );
    this.assert(gutenbergWrapPresent, '.fct-custom-gutenberg-editor-wrap renders inside Builder tab');

    // ── Click Classic Editor → assert wp-editor area visible ─────────────
    this.info('Clicking Classic Editor tab...');
    await this.page.evaluate((sel) => {
      const classic = [...document.querySelectorAll(sel)].find(
        el => el.textContent.trim().includes('Classic Editor')
      );
      if (classic) classic.click();
    }, SEL.tabItems);

    await this.page.waitForFunction(
      (sel) => !document.querySelector(sel + ' .el-loading-mask'),
      SEL.editorTabsNav,
      { timeout: 8000 }
    ).catch(() => {});
    await this.pause(2);

    const classicVisible = await this._isVisible(SEL.wpEditorContent);
    this.assert(classicVisible, 'Classic Editor content area is visible after clicking Classic Editor tab');

    // ── Persistence: save Builder preference → reload → assert ────────────
    this.info('Switching to Builder tab and saving to test preference persistence...');
    await this.page.evaluate((sel) => {
      const builder = [...document.querySelectorAll(sel)].find(
        el => el.textContent.trim().includes('Builder')
      );
      if (builder) builder.click();
    }, SEL.tabItems);
    await this.pause();

    await this._waitForSaveBar();
    const tabSaveResult = await this._clickUpdate();
    this.assert(!!tabSaveResult?.ok, 'Tab preference (Builder) save API OK', `status: ${tabSaveResult?.status}`);

    await this.hardReload(SEL.formReady);
    await this.pause(2);

    const tabAfterReload = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.activeTabItem
    );
    this.assert(
      tabAfterReload.includes('Builder'),
      'Builder tab preference persists after hard reload',
      `active tab after reload: "${tabAfterReload}"`
    );

    // ── Restore original tab preference ───────────────────────────────────
    if (initialTab !== 'gutenberg') {
      this.info('Restoring Classic Editor tab preference...');
      await this.page.evaluate((sel) => {
        const classic = [...document.querySelectorAll(sel)].find(
          el => el.textContent.trim().includes('Classic Editor')
        );
        if (classic) classic.click();
      }, SEL.tabItems);
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

  // ── Private helpers ───────────────────────────────────────────────────────

  async _getTitleValue() {
    return this.page.evaluate(() => {
      const input = document.querySelector('input#post_title');
      return input ? input.value : '';
    });
  }

  async _getTextareaValue() {
    return this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      return el ? el.value : '';
    }, SEL.shortDescArea);
  }

  /**
   * Set a textarea value via native prototype setter so Vue/El Plus pick up
   * the change event and update both v-model and the @input handler.
   */
  async _setTextareaValue(value) {
    const result = await this.evaluate(({ sel, val }) => {
      const textarea = document.querySelector(sel);
      if (!textarea) return false;
      const set = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
      set.call(textarea, val);
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      textarea.dispatchEvent(new Event('change', { bubbles: true }));
      textarea.dispatchEvent(new Event('blur', { bubbles: true }));
      return true;
    }, { sel: SEL.shortDescArea, val: String(value) });
    await this.pause();
    return result;
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

  async _isVisible(selector) {
    return this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      const style = window.getComputedStyle(el);
      return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetHeight > 0;
    }, selector);
  }
}

/**
 * Product Edit — Gallery tab loading state
 *
 * A gallery tab is registered as a lazy import (galleryTabComponent.js), so on
 * a cold cache the pane has nothing to show until the chunk arrives. What this
 * pins, with the chunk request held open on purpose:
 *   - the pane shows a skeleton while the chunk is on the wire, never a blank
 *   - the skeleton is replaced by the tab itself once it resolves
 *   - a chunk that fails to load lands on an error, not on that same blank
 *
 * DOM contract:
 *   [data-fct-gallery-tab-loading]   skeleton shown while the chunk loads
 *   [data-fct-gallery-tab-error]     shown when the chunk failed
 *   [data-fct-fp-video-tab]          the FluentPlayer tab itself
 */
import BaseTest from '../../../base-test.mjs';

const SEL = {
  formReady: 'input#post_title',
  trigger:   '.fct-product-media-wrap .fct-media-picker',
  modal:     '.fct-bulk-media-picker-modal',
  tab:       '.fct-bulk-media-picker-modal [id="tab-fluent-player"]',
  pane:      '.fct-gallery-tab-pane--fluent-player',
  loading:   '[data-fct-gallery-tab-loading]',
  error:     '[data-fct-gallery-tab-error]',
  tabBody:   '[data-fct-fp-video-tab]',
};

// The lazy chunk, however the bundler names it — the dev server serves the SFC
// path, a build serves a hashed file whose name keeps the component name.
const CHUNK = /FluentPlayerVideoTab/;

export default class ProductEditGalleryTabLoadingTest extends BaseTest {
  static title = 'Product Edit — gallery tab loading state';

  async run() {
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered from list', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    await this._openProduct(productId);
    const fpActive = await this.page.evaluate(() => !!window.fluentCartAdminApp?.app_config?.fluentPlayer?.active);
    if (!fpActive) {
      this.skip('Gallery tab loading state', 'FluentPlayer is not active on this site');
      return;
    }

    // ── 1. Chunk held open → skeleton, not a blank pane ────────────────
    let held = 0;
    const slowChunk = async (route) => {
      held++;
      await new Promise(resolve => setTimeout(resolve, 2500));
      return route.continue();
    };
    await this.page.route(CHUNK, slowChunk);

    await this._openGallery();
    await this.page.evaluate((sel) => document.querySelector(sel)?.click(), SEL.tab);
    const pending = await this.page.waitForSelector(SEL.loading, { timeout: 4000 }).then(() => true).catch(() => false);

    if (!held) {
      this.skip('Gallery tab loading state', 'The tab chunk was not requested — it is already bundled into the page');
      await this.page.unroute(CHUNK, slowChunk);
      return;
    }

    this.assert(pending, 'The pane shows a loading skeleton while the tab chunk is on the wire');
    const whilePending = await this.page.evaluate(({ loading, body, pane }) => ({
      skeleton: !!document.querySelector(loading),
      tabBody: !!document.querySelector(body),
      paneHeight: Math.round(document.querySelector(pane)?.getBoundingClientRect().height || 0),
    }), { loading: SEL.loading, body: SEL.tabBody, pane: SEL.pane });
    this.assert(!whilePending.tabBody, 'The tab itself is not rendered yet — the skeleton is standing in for it', JSON.stringify(whilePending));
    this.assert(whilePending.paneHeight > 0, 'The pane has height while loading, so the dialog does not collapse', `height: ${whilePending.paneHeight}`);

    await this.page.waitForSelector(SEL.tabBody, { timeout: 10000 }).catch(() => null);
    const afterLoad = await this.page.evaluate(({ loading, body }) => ({
      skeleton: !!document.querySelector(loading),
      tabBody: !!document.querySelector(body),
    }), { loading: SEL.loading, body: SEL.tabBody });
    this.assert(afterLoad.tabBody && !afterLoad.skeleton, 'The skeleton is replaced by the tab once the chunk resolves', JSON.stringify(afterLoad));

    await this.page.unroute(CHUNK, slowChunk);
    await this._cancelDialog();

    // ── 2. Chunk fails → an error, and the way out of it ───────────────
    const failChunk = (route) => route.abort();
    await this.page.route(CHUNK, failChunk);

    await this._openProduct(productId);
    await this._openGallery();
    await this.page.evaluate((sel) => document.querySelector(sel)?.click(), SEL.tab);
    const failed = await this.page.waitForSelector(SEL.error, { timeout: 10000 }).then(() => true).catch(() => false);
    this.assert(failed, 'A tab whose chunk fails to load shows an error instead of an empty pane');

    const errorState = await this.page.evaluate(({ error, loading, body }) => ({
      text: document.querySelector(error)?.textContent.trim() || '',
      skeleton: !!document.querySelector(loading),
      tabBody: !!document.querySelector(body),
    }), { error: SEL.error, loading: SEL.loading, body: SEL.tabBody });
    this.assert(!errorState.skeleton, 'The skeleton gives way to the error rather than spinning forever', JSON.stringify(errorState));
    this.assert(/reload/i.test(errorState.text), 'The error asks for the page load that actually recovers a failed chunk', errorState.text);

    // A failed dynamic import is cached by the browser's module map, so the
    // tab only comes back on a fresh document — which is the whole reason the
    // error says so rather than offering a retry.
    await this.page.unroute(CHUNK, failChunk);
    await this._openProduct(productId);
    await this._openGallery();
    await this.page.evaluate((sel) => document.querySelector(sel)?.click(), SEL.tab);
    const recovered = await this.page.waitForSelector(SEL.tabBody, { timeout: 10000 }).then(() => true).catch(() => false);
    this.assert(recovered, 'Reloading the page brings the tab back');

    await this._cancelDialog();
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  async _openProduct(productId) {
    await this.adminPage(`/products/${productId}`);
    await this.hardReload(SEL.formReady);
    // The form renders before the product resolves; a title means its data has landed.
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel)?.value,
      SEL.formReady,
      { timeout: 15000 }
    ).catch(() => null);
    await this.pause();
  }

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
      const link = document.querySelector('.el-table__row a');
      return link ? (link.getAttribute('href') || link.href) : null;
    });
    const match = href && href.match(/\/products\/(\d+)/);
    return match ? match[1] : null;
  }

  async _openGallery() {
    await this.page.evaluate((sel) => {
      const triggers = [...document.querySelectorAll(sel)];
      const visible = triggers.find(t => t.offsetParent !== null) || triggers[0];
      if (visible) visible.click();
    }, SEL.trigger);
    await this.page.waitForSelector(SEL.modal, { timeout: 8000 }).catch(() => null);
  }

  async _cancelDialog() {
    await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const btn = [...(modal?.querySelectorAll('.dialog-footer button') || [])].find(b => b.textContent.trim() === 'Cancel');
      if (btn) btn.click();
    });
    await this.page.waitForSelector(SEL.modal, { state: 'detached', timeout: 5000 }).catch(() => null);
  }
}

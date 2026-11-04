/**
 * Product Edit — Gallery order (images + FluentPlayer videos)
 *
 * The Product Gallery dialog's Gallery tab holds one draggable list of the
 * gallery images and the videos attached in the FluentPlayer tab. What this
 * pins:
 *   - a video renders as a tile in the Gallery grid, with a play badge
 *   - the "Featured" tag follows the first *image*, never a video
 *   - a video dragged to the front is tagged "Plays first" (it opens the
 *     product page) while the image keeps "Featured"
 *   - the order survives Save → Update → reload, and the Media card preview
 *     outside the dialog shows the same order
 *   - the same reordering is reachable from the keyboard, and announced
 *   - the grid cannot be edited until the tabs' items have arrived, so the
 *     result of an edit never depends on how fast the request came back
 *
 * DOM contract:
 *   .fmp-grid-item[data-fct-media-kind="image"|"video"]   one tile per entry
 *   .fmp-grid-item .fmp-grid-play svg                     play badge on a video tile
 *   .fmp-grid-item .fmp-featured-tag                      "Featured" / "Plays first"
 *   .fmp-preview-item[data-fct-media-kind=...]            Media card preview, same order
 */
import BaseTest from '../../../base-test.mjs';

const SEL = {
  formReady:    'input#post_title',
  trigger:      '.fct-product-media-wrap .fct-media-picker',
  modal:        '.fct-bulk-media-picker-modal',
  gridItem:     '.fct-bulk-media-picker-modal .fmp-grid-item',
  videoTile:    '.fct-bulk-media-picker-modal .fmp-grid-item[data-fct-media-kind="video"]',
  imageTile:    '.fct-bulk-media-picker-modal .fmp-grid-item[data-fct-media-kind="image"]',
  tab:          '.fct-bulk-media-picker-modal [id="tab-fluent-player"]',
  galleryTab:   '.fct-bulk-media-picker-modal [id="tab-gallery"]',
  tabBody:      '.fct-bulk-media-picker-modal [data-fct-fp-video-tab]',
  srcInput:     '.fct-bulk-media-picker-modal #fct-fp-video-src',
  createBtn:    '.fct-bulk-media-picker-modal [data-fct-fp-video-create-btn]',
  selectedCard: '.fct-bulk-media-picker-modal [data-fct-fp-video-selected]',
  removeBtn:    '.fct-bulk-media-picker-modal [data-fct-fp-video-remove]',
  saveBarActive:'.fct-save-bar.is-active',
  urlTab:       '.fct-bulk-media-picker-modal [id="tab-imported-urls"]',
  urlInput:     '.fct-bulk-media-picker-modal .fmp-url-add input',
  urlAddBtn:    '.fct-bulk-media-picker-modal .fmp-url-add .el-button',
  moveEarlier:  '.fct-bulk-media-picker-modal [data-fct-media-move="earlier"]',
  moveLater:    '.fct-bulk-media-picker-modal [data-fct-media-move="later"]',
  live:         '.fct-bulk-media-picker-modal [data-fct-gallery-live]',
  loading:      '.fct-bulk-media-picker-modal [data-fct-gallery-loading]',
  saveBtn:      '.fct-bulk-media-picker-modal .dialog-footer .el-button--primary',
  tileRemove:   '.fct-bulk-media-picker-modal .fmp-remove-btn',
  cardPreview:  '.fct-product-media-wrap .fct-media-picker .fmp-preview-item',
};

const VIDEO_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

export default class ProductEditGalleryVideoOrderTest extends BaseTest {
  static title = 'Product Edit — gallery order with videos';

  async run() {
    const candidates = await this._discoverProductIds();
    if (!candidates.length) {
      this.assert(false, 'Product IDs discovered from list', 'No product rows found — seed data required');
      return;
    }

    // The featured-image rule only means something next to a real image, so
    // walk the list until a product with a gallery image turns up.
    let productId = null;
    for (const candidate of candidates) {
      // A hash change keeps the SPA (and the previous product's data) alive for
      // a moment, so boot the page fresh on the product being inspected.
      await this.adminPage(`/products/${candidate}`);
      await this.hardReload(SEL.formReady);
      await this._waitForProductLoaded();

      const fpActive = await this.page.evaluate(() => !!window.fluentCartAdminApp?.app_config?.fluentPlayer?.active);
      if (!fpActive) {
        this.skip('Gallery order with videos', 'FluentPlayer is not active on this site');
        return;
      }

      await this._openGallery();
      const images = await this._count(SEL.imageTile);
      this.info(`Product ${candidate}: ${images} gallery image(s)`);
      if (images) {
        productId = candidate;
        break;
      }
      await this._cancelDialog();
    }

    if (!productId) {
      this.skip('Gallery order with videos', `No product with a gallery image among ${candidates.join(', ')} — seed data required`);
      return;
    }
    this.info(`Using product ID: ${productId}`);

    // ── 1. Attach one video, then look at the Gallery grid ─────────────
    await this._openTab();
    await this._removeAllVideos();
    await this.setInput(SEL.srcInput, VIDEO_URL);
    const created = await this.actionWithApi(() => this._clickIn(SEL.createBtn), '/fluent-player/v2/media', 'POST');
    this.assert(!!created?.ok, 'Created a FluentPlayer video for the gallery', `status: ${created?.status}`);
    await this.page.waitForSelector(SEL.selectedCard, { timeout: 8000 }).catch(() => null);

    await this._clickIn(SEL.galleryTab);
    await this.page.waitForSelector(SEL.videoTile, { timeout: 8000 }).catch(() => null);

    const grid = await this._grid();
    this.assert(grid.some(item => item.kind === 'video'), 'The attached video renders as a tile in the Gallery grid', JSON.stringify(grid));
    this.assert(grid.filter(item => item.kind === 'video').every(item => item.badge), 'Every video tile carries a play badge');
    this.assert(grid[grid.length - 1].kind === 'video', 'A video with no place of its own sits after the images', JSON.stringify(grid.map(i => i.kind)));

    const firstImage = grid.findIndex(item => item.kind === 'image');
    this.assert(grid[firstImage].tag === 'Featured', 'The first image is tagged "Featured"', JSON.stringify(grid));
    this.assert(grid.filter(item => item.tag === 'Featured').length === 1, 'Exactly one tile is tagged "Featured"');

    // ── 1b. A new image joins the images, not the tail ─────────────────
    // The video has no place of its own yet, so it is trailing: an image added
    // now has to land in front of it, which is where a Save would put it.
    const beforeAdd = await this._grid();
    await this._clickIn(SEL.urlTab);
    await this.setInput(SEL.urlInput, `https://example.com/fct-order-check-${Date.now()}.jpg`);
    await this._clickIn(SEL.urlAddBtn);
    await this._clickIn(SEL.galleryTab);

    const afterAdd = await this._grid();
    const displayed = afterAdd.map(item => item.kind);
    this.assert(afterAdd.length === beforeAdd.length + 1, 'The added image shows up in the grid', JSON.stringify(displayed));
    this.assert(displayed[displayed.length - 1] === 'video', 'A video with no place of its own stays last when an image is added', JSON.stringify(displayed));

    // What the grid shows has to be what a Save keeps: re-open and compare.
    await this._saveDialog();
    await this._waitForSaveBar();
    const addSaved = await this._clickUpdate();
    this.assert(!!addSaved?.ok, 'Product update after adding an image API OK', `status: ${addSaved?.status}`);

    await this.hardReload(SEL.formReady);
    await this._waitForProductLoaded();
    await this._openGallery();
    await this.page.waitForSelector(SEL.videoTile, { timeout: 8000 }).catch(() => null);
    const reopened = await this._grid();
    this.assert(
      JSON.stringify(reopened.map(i => i.kind)) === JSON.stringify(displayed),
      'The order shown after adding an image is the order that was saved',
      `${JSON.stringify(displayed)} vs ${JSON.stringify(reopened.map(i => i.kind))}`
    );

    // ── 1b2. The grid holds still until the videos arrive ──────────────
    // Their stored positions are measured against the images as they are now,
    // so an edit made before they land would apply them to a list they never
    // saw — the same removal would then land them differently depending on
    // whether the request had come back yet.
    await this._cancelDialog();
    const slowSummaries = async (route) => {
      await new Promise(resolve => setTimeout(resolve, 3000));
      return route.continue();
    };
    await this.page.route(/media\/search/, slowSummaries);
    await this._openProduct(productId);
    await this._openGallery();

    const whileLoading = await this.page.evaluate(({ loading, save, remove, tiles }) => ({
      notice: !!document.querySelector(loading),
      saveDisabled: !!document.querySelector(save)?.disabled,
      removeDisabled: [...document.querySelectorAll(remove)].every(b => b.disabled),
      videoTiles: [...document.querySelectorAll(tiles)].filter(t => t.dataset.fctMediaKind === 'video').length,
    }), { loading: SEL.loading, save: SEL.saveBtn, remove: SEL.tileRemove, tiles: SEL.gridItem });
    this.assert(whileLoading.notice, 'The grid says it is still loading the tabs\' items', JSON.stringify(whileLoading));
    this.assert(whileLoading.videoTiles === 0, 'The videos are not in the grid yet — that is the window being closed', JSON.stringify(whileLoading));
    this.assert(whileLoading.removeDisabled, 'Tiles cannot be removed while the items are on their way', JSON.stringify(whileLoading));
    this.assert(whileLoading.saveDisabled, 'Save is held until the gallery is whole', JSON.stringify(whileLoading));

    await this.page.waitForSelector(SEL.videoTile, { timeout: 15000 }).catch(() => null);
    const afterLoading = await this.page.evaluate(({ loading, save, remove }) => ({
      notice: !!document.querySelector(loading),
      saveDisabled: !!document.querySelector(save)?.disabled,
      removeDisabled: [...document.querySelectorAll(remove)].every(b => b.disabled),
    }), { loading: SEL.loading, save: SEL.saveBtn, remove: SEL.tileRemove });
    this.assert(!afterLoading.notice && !afterLoading.saveDisabled && !afterLoading.removeDisabled, 'Everything is live again once the items land', JSON.stringify(afterLoading));

    await this.page.unroute(/media\/search/, slowSummaries);

    // Drop the added image again so the rest of this test runs on one image.
    const addedIndex = reopened.map(item => item.kind).lastIndexOf('image');
    await this.page.locator(`${SEL.gridItem} >> nth=${addedIndex}`).locator('.fmp-remove-btn').click({ force: true });
    await this.pause();

    // ── 1c. The same move, from the keyboard ────────────────────
    // Dragging is pointer-only, so the tiles carry move controls; this is the
    // whole feature for anyone not using a mouse.
    const beforeKeyboard = await this._grid();
    const controls = await this.page.evaluate(({ earlier, later }) => {
      const read = (sel) => [...document.querySelectorAll(sel)].map(b => ({ label: b.getAttribute('aria-label') || '', disabled: !!b.disabled }));
      return { earlier: read(earlier), later: read(later) };
    }, { earlier: SEL.moveEarlier, later: SEL.moveLater });
    this.assert(
      controls.earlier.length === beforeKeyboard.length && controls.later.length === beforeKeyboard.length,
      'Every tile carries a move-earlier and a move-later control',
      JSON.stringify(controls)
    );
    this.assert(controls.earlier[0].disabled && controls.later[controls.later.length - 1].disabled, 'The controls are disabled at the ends of the list', JSON.stringify(controls));
    this.assert(controls.earlier.every(control => control.label.trim() !== ''), 'The controls name the item they move', JSON.stringify(controls.earlier.map(c => c.label)));

    // Walk the video to the front one press at a time. Focus is supposed to
    // follow the item, so the same key keeps moving the same tile.
    const videoAt = beforeKeyboard.map(item => item.kind).lastIndexOf('video');
    await this.page.locator(`${SEL.moveEarlier}[data-fct-media-move-index="${videoAt}"]`).focus();
    for (let step = videoAt; step > 0; step--) {
      await this.page.keyboard.press('Enter');
      await this.pause();
    }

    const moved = await this._grid();
    this.assert(moved[0]?.kind === 'video', 'Repeated move-earlier presses walk the video to the front', JSON.stringify(moved.map(i => i.kind)));
    this.assert(moved[0]?.tag === 'Plays first', 'The tags follow a keyboard move', JSON.stringify(moved));

    const announced = await this.page.evaluate((sel) => document.querySelector(sel)?.textContent.trim() || '', SEL.live);
    this.assert(new RegExp(`position 1 of ${moved.length}`, 'i').test(announced), 'The new position is announced in a live region', announced);

    const focused = await this.page.evaluate(() => {
      const el = document.activeElement;
      return { move: el?.getAttribute('data-fct-media-move') || '', index: el?.getAttribute('data-fct-media-move-index') || '' };
    });
    this.assert(focused.index === '0', 'Focus follows the item that moved, so it can be moved again', JSON.stringify(focused));
    this.assert(focused.move === 'later', 'Focus drops to the control that still works when the move hits an end', JSON.stringify(focused));

    // Put it back, so the drag step below starts where it expects to.
    for (let step = 0; step < videoAt; step++) {
      await this.page.keyboard.press('Enter');
      await this.pause();
    }
    const restored = await this._grid();
    this.assert(
      JSON.stringify(restored.map(i => i.kind)) === JSON.stringify(beforeKeyboard.map(i => i.kind)),
      'Move-later presses undo it',
      `${JSON.stringify(beforeKeyboard.map(i => i.kind))} vs ${JSON.stringify(restored.map(i => i.kind))}`
    );

    // ── 2. Drag the video in front of every image ──────────────────────
    await this.page.locator(SEL.videoTile).first().dragTo(this.page.locator(SEL.gridItem).first());
    await this.pause();

    const dragged = await this._grid();
    this.assert(dragged[0]?.kind === 'video', 'The video can be dragged to the front of the gallery', JSON.stringify(dragged.map(i => i.kind)));
    this.assert(dragged[0]?.tag === 'Plays first', 'A leading video is tagged "Plays first" — it opens the product page', JSON.stringify(dragged));
    const featured = dragged.find(item => item.tag === 'Featured');
    this.assert(!!featured && featured.kind === 'image', 'The featured image is still the first image, not the video', JSON.stringify(dragged));

    // ── 3. Save the dialog → the Media card preview follows ────────────
    await this._saveDialog();
    await this.pause();
    const preview = await this.page.evaluate((sel) => {
      const wrap = [...document.querySelectorAll('.fct-product-media-wrap')].find(w => w.offsetParent !== null);
      return [...(wrap ? wrap.querySelectorAll('.fmp-preview-item') : [])].map(el => el.dataset.fctMediaKind);
    }, SEL.cardPreview);
    this.assert(preview[0] === 'video', 'The Media card preview shows the video first too', JSON.stringify(preview));

    // ── 4. Update → reload → the order is what was saved ───────────────
    await this._waitForSaveBar();
    const saved = await this._clickUpdate();
    this.assert(!!saved?.ok, 'Product update API OK', `status: ${saved?.status}`);

    await this.hardReload(SEL.formReady);
    await this._waitForProductLoaded();
    await this._openGallery();
    await this.page.waitForSelector(SEL.videoTile, { timeout: 8000 }).catch(() => null);
    const reloaded = await this._grid();
    this.assert(reloaded[0]?.kind === 'video' && reloaded[0]?.tag === 'Plays first', 'The gallery order persists after a hard reload', JSON.stringify(reloaded.map(i => i.kind)));
    this.assert(reloaded.find(item => item.tag === 'Featured')?.kind === 'image', 'The featured image survives the reload as the first image');

    // ── 5. Remove the video from the Gallery grid itself ───────────────
    await this.page.locator(`${SEL.videoTile} .fmp-remove-btn`).first().click({ force: true });
    await this.pause();
    this.assert((await this._count(SEL.videoTile)) === 0, 'A video can be removed from the Gallery grid');
    const afterRemove = await this._grid();
    this.assert(afterRemove[0]?.tag === 'Featured', 'The "Featured" tag returns to the first tile once the video is gone', JSON.stringify(afterRemove));

    await this._saveDialog();
    await this._waitForSaveBar();
    const cleaned = await this._clickUpdate();
    this.assert(!!cleaned?.ok, 'Removing the video from the grid persists', `status: ${cleaned?.status}`);
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  async _grid() {
    return this.page.evaluate((sel) => [...document.querySelectorAll(sel)].map(el => ({
      kind: el.dataset.fctMediaKind,
      tag: el.querySelector('.fmp-featured-tag')?.textContent.trim() || '',
      badge: !!el.querySelector('.fmp-grid-play svg'),
    })), SEL.gridItem);
  }

  // A hash change keeps the SPA (and the previous product's data) alive for a
  // moment, so boot the page fresh on the product being inspected.
  async _openProduct(productId) {
    await this.adminPage(`/products/${productId}`);
    await this.hardReload(SEL.formReady);
    await this._waitForProductLoaded();
  }

  // The form renders before the product resolves; a title means its data
  // (gallery included) has landed, so the dialog opens on the real gallery.
  async _waitForProductLoaded() {
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel)?.value,
      SEL.formReady,
      { timeout: 15000 }
    ).catch(() => null);
    await this.pause();
  }

  async _count(selector) {
    return this.page.evaluate((sel) => document.querySelectorAll(sel).length, selector);
  }

  async _discoverProductIds(limit = 6) {
    this.info('Reading product IDs from list...');
    // An earlier test can leave unsaved edits, and SaveBar then refuses a route change.
    // A URL that differs only in its hash does not reload, so load the page without one.
    const leave = (dialog) => dialog.accept();
    this.page.on('dialog', leave);
    await this.goto('/wp-admin/admin.php?page=fluent-cart');
    this.page.off('dialog', leave);
    await this.adminPage('/products');
    // The wrapper renders before the rows do, so wait for a row link itself.
    await this.page.waitForSelector('.el-table__row a', { timeout: 15000 }).catch(() => null);
    await this.pause();

    return this.page.evaluate((max) => {
      const ids = [];
      document.querySelectorAll('.el-table__row a').forEach((link) => {
        const match = (link.getAttribute('href') || link.href || '').match(/\/products\/(\d+)/);
        if (match && !ids.includes(match[1]) && ids.length < max) ids.push(match[1]);
      });
      return ids;
    }, limit);
  }

  async _removeAllVideos() {
    for (let i = 0; i < 25; i++) {
      const has = await this.page.evaluate((sel) => !!document.querySelector(sel), SEL.removeBtn);
      if (!has) break;
      this.info('Removing leftover video from a previous run');
      await this._clickIn(SEL.removeBtn);
    }
  }

  async _openGallery() {
    await this.page.evaluate((sel) => {
      const triggers = [...document.querySelectorAll(sel)];
      const visible = triggers.find(t => t.offsetParent !== null) || triggers[0];
      if (visible) visible.click();
    }, SEL.trigger);
    await this.page.waitForSelector(SEL.modal, { timeout: 8000 }).catch(() => null);
    // The grid is only meaningful once it has drawn its tiles or its empty state.
    await this.page.waitForSelector(`${SEL.modal} .fmp-grid, ${SEL.modal} .fmp-empty`, { timeout: 8000 }).catch(() => null);
    await this.pause();
  }

  // The pane is lazy and the tab component is an async chunk: wait for the body.
  async _openTab() {
    await this._clickIn(SEL.tab);
    await this.page.waitForSelector(SEL.tabBody, { timeout: 15000 }).catch(() => null);
    await this.pause();
  }

  async _clickIn(selector) {
    const clicked = await this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      el.click();
      return true;
    }, selector);
    await this.pause();
    return clicked;
  }

  async _saveDialog() {
    await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const btn = [...(modal?.querySelectorAll('.dialog-footer button') || [])].find(b => b.textContent.trim() === 'Save');
      if (btn) btn.click();
    });
    await this.page.waitForSelector(SEL.modal, { state: 'detached', timeout: 5000 }).catch(() => null);
    await this.pause();
  }

  async _cancelDialog() {
    await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const btn = [...(modal?.querySelectorAll('.dialog-footer button') || [])].find(b => b.textContent.trim() === 'Cancel');
      if (btn) btn.click();
    });
    await this.page.waitForSelector(SEL.modal, { state: 'detached', timeout: 5000 }).catch(() => null);
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
        const btn = [...(bar?.querySelectorAll('button') || [])].find(b => b.textContent.includes('Update'));
        if (btn) btn.click();
      }),
      '/pricing',
      'POST'
    );
  }
}

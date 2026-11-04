/**
 * MediaPickerHelper — reusable browser helper for the BulkMediaPicker modal.
 *
 * Selector contract
 * ─────────────────
 *   Trigger:       .fct-media-picker
 *                  The outer <div> rendered by BulkMediaPicker. Clicking it opens the modal.
 *                  Multiple triggers may exist on one page (BulkEdit/BulkInsert rows).
 *                  Always pass a more-specific selector when the page shows more than one.
 *
 *   Modal:         .fct-bulk-media-picker-modal
 *                  Set as `class` on the <el-dialog>. Because the dialog uses
 *                  append-to-body, it is appended to <body> outside the trigger's tree.
 *                  Only one modal is open at a time (v-if="showModal").
 *                  ALL modal interactions are scoped to this class.
 *
 *   Gallery tab:   [id="tab-gallery"]  (inside .fct-bulk-media-picker-modal)
 *   URL tab:       [id="tab-imported-urls"]  (inside .fct-bulk-media-picker-modal)
 *
 *   Gallery items: .fmp-grid-item  (all images, WP + URL-only)
 *   Remove btn:    .fmp-remove-btn  (inside each .fmp-grid-item)
 *   Empty state:   .fmp-empty
 *
 *   URL list:      .fmp-url-item  (URL-only images shown in the URL tab)
 *   URL del btn:   .fmp-url-delete  (inside each .fmp-url-item)
 *   URL input:     .fmp-url-add input
 *   URL add btn:   .fmp-url-add button
 *
 *   Footer:        .dialog-footer  (inside .el-dialog__footer)
 *   Cancel btn:    button matching text "Cancel" inside .dialog-footer
 *   Save btn:      button matching text "Save" inside .dialog-footer
 *
 * Usage
 * ─────
 *   import MediaPickerHelper from '../../helpers/media-picker.mjs';
 *
 *   const picker = new MediaPickerHelper(this);
 *
 *   // Open — pass a scoped selector when multiple pickers are on the page
 *   await picker.open('.fct-product-media-wrap .fct-media-picker');
 *   await picker.assertVisible();
 *
 *   // Add by URL
 *   await picker.switchToUrlTab();
 *   await picker.addImageByUrl('https://example.com/photo.jpg');
 *   await picker.switchToGalleryTab();
 *   await picker.assertGalleryCount(1);
 *
 *   // Save and verify trigger
 *   await picker.save();
 *   await picker.assertTriggerHasImages('.fct-product-media-wrap .fct-media-picker');
 *
 *   // After hardReload — re-assert trigger still shows the image
 *   await this.hardReload('.fct-product-media-wrap');
 *   await picker.assertTriggerHasImages('.fct-product-media-wrap .fct-media-picker');
 */
export default class MediaPickerHelper {

  constructor(test) {
    this.test = test;
    this.page = test.page;
  }

  // ─── Modal lifecycle ──────────────────────────────────────────────────

  /**
   * Open the media picker by clicking the trigger element.
   * Waits for the modal to appear in the DOM.
   *
   * @param {string} triggerSelector  CSS selector for the specific .fct-media-picker trigger.
   *                                  Defaults to the first one found; pass a scoped selector
   *                                  when multiple pickers exist on the page.
   */
  async open(triggerSelector = '.fct-media-picker') {
    this.test.info(`Opening media picker (${triggerSelector})`);
    await this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (el) el.click();
    }, triggerSelector);
    await this.page.waitForSelector('.fct-bulk-media-picker-modal', { timeout: 8000 }).catch(() => null);
    await this.test.pause();
  }

  /**
   * Assert the modal is currently present in the DOM.
   */
  async assertVisible() {
    const visible = await this.page.evaluate(() => !!document.querySelector('.fct-bulk-media-picker-modal'));
    this.test.assert(visible, 'Media picker modal visible');
    return visible;
  }

  /**
   * Assert the modal has been removed from the DOM (after save or cancel).
   */
  async assertClosed() {
    await this.page.waitForSelector('.fct-bulk-media-picker-modal', { state: 'detached', timeout: 5000 }).catch(() => null);
    const gone = await this.page.evaluate(() => !document.querySelector('.fct-bulk-media-picker-modal'));
    this.test.assert(gone, 'Media picker modal closed');
    return gone;
  }

  /**
   * Click the Cancel button to discard changes and close the modal.
   */
  async cancel() {
    this.test.info('Cancelling media picker');
    await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const btns = modal?.querySelectorAll('.dialog-footer button') || [];
      const btn = [...btns].find(b => b.textContent.trim() === 'Cancel');
      if (btn) btn.click();
    });
    await this.page.waitForSelector('.fct-bulk-media-picker-modal', { state: 'detached', timeout: 5000 }).catch(() => null);
    await this.test.pause();
  }

  /**
   * Click the Save button to apply changes and close the modal.
   * Emits update:modelValue to the parent component.
   */
  async save() {
    this.test.info('Saving media picker');
    await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const btns = modal?.querySelectorAll('.dialog-footer button') || [];
      const btn = [...btns].find(b => b.textContent.trim() === 'Save');
      if (btn) btn.click();
    });
    await this.page.waitForSelector('.fct-bulk-media-picker-modal', { state: 'detached', timeout: 5000 }).catch(() => null);
    await this.test.pause();
  }

  // ─── Tab navigation ───────────────────────────────────────────────────

  /**
   * Switch to the "Add by URL" tab inside the modal.
   */
  async switchToUrlTab() {
    this.test.info('Switching to Add by URL tab');
    const clicked = await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const tab = modal?.querySelector('[id="tab-imported-urls"]');
      if (tab) { tab.click(); return true; }
      return false;
    });
    this.test.assert(clicked, 'Add by URL tab found and clicked');
    await this.test.pause();
    return clicked;
  }

  /**
   * Switch to the Gallery tab inside the modal.
   */
  async switchToGalleryTab() {
    this.test.info('Switching to Gallery tab');
    const clicked = await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const tab = modal?.querySelector('[id="tab-gallery"]');
      if (tab) { tab.click(); return true; }
      return false;
    });
    this.test.assert(clicked, 'Gallery tab found and clicked');
    await this.test.pause();
    return clicked;
  }

  // ─── URL-based images ─────────────────────────────────────────────────

  /**
   * Add an image by pasting a URL (must already be on the Add by URL tab).
   *
   * Sets the input value via the native setter so Vue's v-model picks it up,
   * then clicks Add in the same synchronous evaluate so the Vue ref is already
   * updated when addFromUrl() reads pasteUrl.value.
   *
   * @param {string} url  Full image URL to add.
   */
  async addImageByUrl(url) {
    this.test.info(`Adding image by URL: ${url}`);

    const result = await this.page.evaluate(({ u }) => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      if (!modal) return 'no-modal';

      const input = modal.querySelector('.fmp-url-add input');
      if (!input) return 'no-input';

      // Update the native value and fire input event so Vue's v-model on el-input updates
      const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      setter.call(input, u);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));

      // Click Add in the same synchronous context — pasteUrl Vue ref is already updated above
      const btn = modal.querySelector('.fmp-url-add button');
      if (!btn) return 'no-button';
      btn.click();
      return 'ok';
    }, { u: url });

    const ok = result === 'ok';
    this.test.assert(ok, `Image URL queued: ${url}`, ok ? '' : result);
    await this.test.pause();
    return ok;
  }

  /**
   * Remove a URL-added image from the "Add by URL" tab list by matching its URL.
   *
   * @param {string} url  The URL to remove (partial match on textContent).
   */
  async removeImageByUrl(url) {
    this.test.info(`Removing URL image: ${url}`);
    const removed = await this.page.evaluate(({ u }) => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const items = modal?.querySelectorAll('.fmp-url-item') || [];
      for (const item of items) {
        if (item.textContent.includes(u)) {
          const btn = item.querySelector('.fmp-url-delete');
          if (btn) { btn.click(); return true; }
        }
      }
      return false;
    }, { u: url });
    this.test.assert(removed, `URL image removed: ${url}`);
    await this.test.pause();
    return removed;
  }

  // ─── Gallery management (within modal) ───────────────────────────────

  /**
   * Remove a gallery image by its 0-based index (Gallery tab).
   *
   * @param {number} index  0-based index of the .fmp-grid-item to remove.
   */
  async removeImage(index) {
    this.test.info(`Removing gallery image at index ${index}`);
    const removed = await this.page.evaluate(({ idx }) => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const items = modal?.querySelectorAll('.fmp-grid-item') || [];
      const item = items[idx];
      if (!item) return false;
      const btn = item.querySelector('.fmp-remove-btn');
      if (btn) { btn.click(); return true; }
      return false;
    }, { idx: index });
    this.test.assert(removed, `Gallery item at index ${index} removed`);
    await this.test.pause();
    return removed;
  }

  /**
   * Assert the gallery tab shows exactly `expected` items (.fmp-grid-item).
   * Counts all images (WP attachments + URL-only).
   */
  async assertGalleryCount(expected) {
    const count = await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      return modal?.querySelectorAll('.fmp-grid-item').length ?? 0;
    });
    this.test.assert(count === expected, `Gallery has ${expected} item(s)`, `actual: ${count}`);
    return count;
  }

  /**
   * Assert the gallery tab shows the empty state (.fmp-empty).
   */
  async assertGalleryEmpty() {
    const isEmpty = await this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      return !!(modal?.querySelector('.fmp-empty'));
    });
    this.test.assert(isEmpty, 'Gallery shows empty state');
    return isEmpty;
  }

  /**
   * Assert a gallery item at `index` has an img src containing `partialUrl`.
   *
   * @param {number} index       0-based gallery item index.
   * @param {string} partialUrl  Expected substring in the img src.
   */
  async assertGalleryItem(index, partialUrl) {
    const src = await this.page.evaluate(({ idx }) => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      const imgs = modal?.querySelectorAll('.fmp-grid-item img') || [];
      return imgs[idx]?.src ?? null;
    }, { idx: index });
    const ok = src !== null && src.includes(partialUrl);
    this.test.assert(ok, `Gallery item ${index} contains expected URL fragment`, `src: ${src ?? '(not found)'}`);
    return ok;
  }

  /**
   * Return the count of URL-only images shown in the "Add by URL" tab list.
   */
  async getUrlImageCount() {
    return this.page.evaluate(() => {
      const modal = document.querySelector('.fct-bulk-media-picker-modal');
      return modal?.querySelectorAll('.fmp-url-item').length ?? 0;
    });
  }

  // ─── Trigger state assertions (outside modal) ─────────────────────────
  //
  // Use these before opening the modal or after a hardReload to verify the
  // trigger's visual state reflects the saved gallery.  The trigger renders
  // differently depending on the `compact`, `featured`, and `multiple` props:
  //
  //   compact (default)  — .fmp-stack containing .fmp-thumb-wrap thumbnails
  //   featured           — .fmp-featured button with single image
  //   full (!compact)    — .fmp-preview-grid with .fmp-preview-item thumbnails
  //                        or .fmp-add-full button when empty
  //
  // These assertions detect any non-empty trigger regardless of mode.
  //

  /**
   * Assert the trigger element shows at least one image (any display mode).
   *
   * @param {string} triggerSelector  CSS selector for the .fct-media-picker trigger.
   */
  async assertTriggerHasImages(triggerSelector = '.fct-media-picker') {
    const hasImages = await this.page.evaluate(({ sel }) => {
      const trigger = document.querySelector(sel);
      if (!trigger) return false;
      return !!(
        trigger.querySelector('.fmp-thumb-wrap') ||     // compact mode
        trigger.querySelector('.fmp-preview-item') ||   // full mode
        trigger.querySelector('.fmp-featured')          // featured mode
      );
    }, { sel: triggerSelector });
    this.test.assert(hasImages, `Trigger (${triggerSelector}) shows images`);
    return hasImages;
  }

  /**
   * Assert the trigger element shows the empty / add-media state.
   *
   * @param {string} triggerSelector  CSS selector for the .fct-media-picker trigger.
   */
  async assertTriggerEmpty(triggerSelector = '.fct-media-picker') {
    const isEmpty = await this.page.evaluate(({ sel }) => {
      const trigger = document.querySelector(sel);
      if (!trigger) return false;
      return !!(
        trigger.querySelector('.fct-mp-add-compact') ||  // compact empty
        trigger.querySelector('.fct-mp-add-featured') || // featured empty
        trigger.querySelector('.fmp-add-full')           // full empty
      );
    }, { sel: triggerSelector });
    this.test.assert(isEmpty, `Trigger (${triggerSelector}) shows empty/add state`);
    return isEmpty;
  }
}

/**
 * Product Edit — FluentPlayer tab in the Product Gallery dialog
 *
 * Requires FluentPlayer to be active on the site (the tab is hidden otherwise;
 * the test then records a SKIP rather than a failure).
 *
 * Covers:
 *   - "FluentPlayer" tab renders inside the Product Gallery dialog with the
 *     three entry points (paste URL, Media Library button, existing-video select)
 *   - Paste a YouTube URL → provider detected (title/poster resolved from the source, no fields)
 *   - Source input, Media Library, Add video and Use video controls share one height
 *   - Media Library opens the WP media modal filtered to videos only (no audio/images)
 *   - Add video → the selected-video card appears (media created in FluentPlayer)
 *   - Add a second video via the existing-video select → two cards, add form still visible
 *   - Save dialog, Update product → both persist after reload
 *   - Remove one → one card left; remove the other → Save → Update → removal persists
 *   - A media id FluentPlayer no longer knows shows a "Not found" card and no error toast
 *   - While a video is still being created, Save and Cancel are disabled and the dialog
 *     stays open; once created the video is attached and Save works
 *   - The Media card preview (.fct-media-picker) shows the attached videos with their
 *     posters and a play badge next to the images, and drops them on removal
 *
 * Selector contract:
 *   .fct-product-media-wrap .fct-media-picker     Product Gallery trigger (aside instance)
 *   .fct-bulk-media-picker-modal                  Gallery dialog (append-to-body)
 *   [id="tab-fluent-player"]                      The FluentPlayer tab
 *   [data-fct-fp-video-tab]                       Tab body
 *   [data-fct-fp-video-create]                    "Add a video" section
 *   #fct-fp-video-src                             Source input
 *   [data-fct-fp-video-library]                   Media Library button
 *   [data-fct-fp-video-detected]                  Provider hint
 *   [data-fct-fp-video-create-btn]                "Add video" button
 *   [data-fct-fp-video-existing]                  Existing-video select section
 *   [data-fct-fp-video-list]                      Attached-videos list
 *   [data-fct-fp-video-selected][data-fct-fp-video-id]  One card per attached video
 *   [data-fct-fp-video-title]                     Card title
 *   [data-fct-fp-video-missing]                   "Not found" tag on a card whose media is gone
 *   [data-fct-fp-video-use-existing]              "Use video" for the existing-video select
 *   [data-fct-fp-video-edit]                      Pencil icon → opens the media in FluentPlayer (new tab)
 *   [data-fct-fp-video-remove]                    Cross icon → removes the video
 */
import BaseTest from '../../../base-test.mjs';

const SEL = {
  formReady:     'input#post_title',
  trigger:       '.fct-product-media-wrap .fct-media-picker',
  modal:         '.fct-bulk-media-picker-modal',
  tab:           '.fct-bulk-media-picker-modal [id="tab-fluent-player"]',
  tabBody:       '.fct-bulk-media-picker-modal [data-fct-fp-video-tab]',
  createSection: '.fct-bulk-media-picker-modal [data-fct-fp-video-create]',
  libraryBtn:    '.fct-bulk-media-picker-modal [data-fct-fp-video-library]',
  existing:      '.fct-bulk-media-picker-modal [data-fct-fp-video-existing]',
  srcInput:      '.fct-bulk-media-picker-modal #fct-fp-video-src',
  detected:      '.fct-bulk-media-picker-modal [data-fct-fp-video-detected]',
  createBtn:     '.fct-bulk-media-picker-modal [data-fct-fp-video-create-btn]',
  selectedCard:  '.fct-bulk-media-picker-modal [data-fct-fp-video-selected]',
  missingTag:    '.fct-bulk-media-picker-modal [data-fct-fp-video-missing]',
  errorToast:    '.el-notification.el-notification--error',
  existingSelect:'.fct-bulk-media-picker-modal [data-fct-fp-video-existing] .el-select input',
  useExisting:   '.fct-bulk-media-picker-modal [data-fct-fp-video-use-existing]',
  editBtn:       '.fct-bulk-media-picker-modal [data-fct-fp-video-edit]',
  removeBtn:     '.fct-bulk-media-picker-modal [data-fct-fp-video-remove]',
  saveBarActive: '.fct-save-bar.is-active',
  footerBtns:    '.fct-bulk-media-picker-modal .dialog-footer .fct-btn-group button',
  cardVideo:     '.fct-product-media-wrap .fct-media-picker .fmp-preview-item[data-fct-media-kind="video"]',
};

// Footer buttons carry no data attributes; find them by label inside the page.
const footerButtonState = (sel, label) => {
  const btn = [...document.querySelectorAll(sel)].find(b => b.textContent.trim() === label);
  return btn ? { found: true, disabled: !!btn.disabled } : { found: false, disabled: false };
};

const VIDEO_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

export default class ProductEditFluentPlayerVideoTest extends BaseTest {
  static title = 'Product Edit — FluentPlayer video tab';

  async run() {
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered from list', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    await this.adminPage(`/products/${productId}`);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();

    const fpActive = await this.page.evaluate(() => !!window.fluentCartAdminApp?.app_config?.fluentPlayer?.active);
    if (!fpActive) {
      this.skip('FluentPlayer tab', 'FluentPlayer is not active on this site');
      return;
    }

    // ── 1. Tab renders with the three entry points ─────────────────────
    await this._openGallery();
    const tabPresent = await this.page.evaluate((sel) => !!document.querySelector(sel), SEL.tab);
    this.assert(tabPresent, 'FluentPlayer tab is present in the Product Gallery dialog');
    if (!tabPresent) return;
    const viaFilter = await this.page.evaluate(() => {
      const tabs = window.fluent_cart_admin?.hooks?.applyFilters('fluent_cart_product_gallery_tabs', [], {}) || [];
      return tabs.some(t => t && t.name === 'fluent-player');
    });
    this.assert(viaFilter, 'Tab is pushed through the fluent_cart_product_gallery_tabs JS filter (extensible by other plugins)');

    await this._openTab();
    this.assert(await this._isVisible(SEL.tabBody), 'FluentPlayer tab body renders (lazy pane + async component)');

    await this._removeAllVideos();

    this.assert(await this._isVisible(SEL.srcInput), 'Paste-a-media-URL input is present');
    this.assert(await this._isVisible(SEL.libraryBtn), 'Media Library button is present');
    this.assert(await this._isVisible(SEL.existing), 'Existing-video searchable select is present');
    const noFields = await this.page.evaluate(() => !document.querySelector('.fct-bulk-media-picker-modal #fct-fp-video-title') && !document.querySelector('.fct-bulk-media-picker-modal [data-fct-fp-video-poster-preview], .fct-bulk-media-picker-modal .fct-fp-video__poster'));
    this.assert(noFields, 'No Title / Poster fields — FluentPlayer resolves them from the source');

    const heights = await this.page.evaluate(({ src, lib, create, use }) => {
      const h = (sel) => Math.round(document.querySelector(sel)?.getBoundingClientRect().height || 0);
      return { input: h(src + ' .el-input__wrapper') || h(src), library: h(lib), create: h(create), use: h(use), select: h('.fct-bulk-media-picker-modal [data-fct-fp-video-existing] .el-select__wrapper') };
    }, { src: '.fct-bulk-media-picker-modal .fct-fp-video__source .el-input', lib: SEL.libraryBtn, create: SEL.createBtn, use: SEL.useExisting });
    const sameHeight = heights.input > 0 && [heights.library, heights.create, heights.use, heights.select].every(v => Math.abs(v - heights.input) <= 1);
    this.assert(sameHeight, 'Media Library, Add video and Use video match the text input height', JSON.stringify(heights));

    // ── 1b. Media Library shows videos only ────────────────────────────
    await this._clickIn(SEL.libraryBtn);
    const modalOpened = await this.page.waitForFunction(() => { const m = document.querySelector('.media-modal'); return !!m && getComputedStyle(m).display !== 'none'; }, null, { timeout: 15000 }).then(() => true).catch(() => false);
    this.assert(modalOpened, 'Media Library modal opens from the FluentPlayer tab');
    if (modalOpened) {
      const onLibraryTab = await this.page.waitForFunction(() => document.querySelectorAll('.media-modal .attachments .attachment').length > 0, null, { timeout: 15000 }).then(() => true).catch(() => false);
      this.assert(onLibraryTab, 'Media Library opens on the library grid, not the upload pane');
      const types = await this.page.evaluate(() => {
        const ids = [...document.querySelectorAll('.media-modal .attachments .attachment')].map(el => el.getAttribute('data-id'));
        const kinds = ids.map(id => window.wp?.media?.attachment(id)?.get('type') || 'unknown');
        return { count: ids.length, kinds: [...new Set(kinds)] };
      });
      this.assert(types.count > 0 && types.kinds.every(k => k === 'video'), 'Only video attachments are listed', JSON.stringify(types));
      // Escape would also close the gallery dialog underneath — use the modal's own close button.
      await this.page.evaluate(() => document.querySelector('.media-modal .media-modal-close')?.click());
      await this.page.waitForFunction(() => { const m = document.querySelector('.media-modal'); return !m || getComputedStyle(m).display === 'none'; }, null, { timeout: 5000 }).catch(() => null);
      this.assert(await this._isVisible(SEL.srcInput), 'Gallery dialog is still open after closing the Media Library');
    }

    // ── 2. Paste URL → detection + poster ──────────────────────────────
    await this.setInput(SEL.srcInput, VIDEO_URL);
    const detectedText = await this.page.evaluate((sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.detected);
    this.assert(detectedText.includes('YouTube'), 'Provider detected as YouTube', `"${detectedText}"`);

    // Title/poster are resolved from the source; the hint shows the fetched title once it arrives.
    await this.page.waitForFunction((sel) => /—/.test(document.querySelector(sel)?.textContent || ''), SEL.detected, { timeout: 12000 }).catch(() => null);
    const hint = await this.page.evaluate((sel) => document.querySelector(sel)?.textContent?.trim() || '', SEL.detected);
    this.info(`Detection hint: "${hint}"`);

    // ── 3. Add video → FluentPlayer media created ─────────────────────
    const created = await this.actionWithApi(() => this._clickIn(SEL.createBtn), '/fluent-player/v2/media', 'POST');
    this.assert(!!created?.ok, 'FluentPlayer media create API OK', `status: ${created?.status}`);

    await this.page.waitForSelector(SEL.selectedCard, { timeout: 8000 }).catch(() => null);
    this.assert(await this._isVisible(SEL.selectedCard), 'Selected-video card appears after Add video');
    // The card fills in asynchronously: summary from FluentPlayer REST + lazily loaded icons.
    await this.page.waitForFunction((sel) => {
      const card = document.querySelector(sel);
      const title = card?.querySelector('[data-fct-fp-video-title]')?.textContent?.trim() || '';
      return !!card && title !== '' && title !== 'Loading...' && !!card.querySelector('[data-fct-fp-video-remove] svg') && !!card.querySelector('[data-fct-fp-video-edit] svg');
    }, SEL.selectedCard, { timeout: 15000 }).catch(() => null);
    const cardInfo = await this.page.evaluate((sel) => { const c = document.querySelector(sel); return { title: c?.querySelector('[data-fct-fp-video-title]')?.textContent?.trim() || '', poster: c?.querySelector('img')?.getAttribute('src') || '' }; }, SEL.selectedCard);
    this.assert(!!cardInfo.title && !/^Video #/.test(cardInfo.title) && cardInfo.title !== 'Untitled Media', 'Title resolved from the source', cardInfo.title);
    this.assert(!!cardInfo.poster, 'Poster resolved from the source', cardInfo.poster);

    const actions = await this.page.evaluate(({ edit, remove }) => {
      const editEl = document.querySelector(edit);
      const removeEl = document.querySelector(remove);
      return {
        editHref: editEl?.getAttribute('href') || '',
        editTarget: editEl?.getAttribute('target') || '',
        editLabel: editEl?.getAttribute('aria-label') || '',
        editHasIcon: !!editEl?.querySelector('svg'),
        removeLabel: removeEl?.getAttribute('aria-label') || '',
        removeHasIcon: !!removeEl?.querySelector('svg'),
        removeText: (removeEl?.textContent || '').trim(),
      };
    }, { edit: SEL.editBtn, remove: SEL.removeBtn });
    this.assert(/post\.php\?action=edit&post=\d+/.test(actions.editHref) && actions.editTarget === '_blank', 'Pencil icon links to the media edit screen in a new tab', actions.editHref);
    this.assert(actions.editHasIcon && actions.editLabel === 'Edit in FluentPlayer', 'Pencil icon is an icon-only control with an accessible label');
    this.assert(actions.removeHasIcon && actions.removeText === '' && actions.removeLabel === 'Remove video', 'Cross icon is an icon-only control with an accessible label');
    this.assert(await this._isVisible(SEL.createSection), 'Add form stays available after the first video');

    // ── 3b. Second video through the existing-video select ─────────────
    await this.page.locator(SEL.existingSelect).click();
    await this.page.locator(SEL.existingSelect).pressSequentially('E2E', { delay: 20 });
    const optionPicked = await this.page.locator('.el-select-dropdown__item:visible').first().click({ timeout: 10000 }).then(() => true).catch(() => false);
    this.assert(optionPicked, 'An existing FluentPlayer video can be picked from the searchable select');
    await this._clickIn(SEL.useExisting);
    await this.page.waitForFunction((sel) => document.querySelectorAll(sel).length === 2, SEL.selectedCard, { timeout: 8000 }).catch(() => null);
    const countAfterSecond = await this._cardCount();
    this.assert(countAfterSecond === 2, 'Two video cards after adding a second video', `cards: ${countAfterSecond}`);

    // ── 4. Save dialog, Update product, reload ─────────────────────────
    await this._saveDialog();
    await this.page.waitForFunction((sel) => document.querySelectorAll(sel).length === 2, SEL.cardVideo, { timeout: 10000 }).catch(() => null);
    const cardPreview = await this.page.evaluate((sel) => [...document.querySelectorAll(sel)].map(el => ({
      poster: el.querySelector('img')?.getAttribute('src') || '',
      badge: !!el.querySelector('.fmp-preview-play svg'),
    })), SEL.cardVideo);
    this.assert(cardPreview.length === 2 && cardPreview.every(p => p.poster && p.badge), 'Media card preview shows both videos with a poster and play badge', JSON.stringify(cardPreview));
    await this._waitForSaveBar();
    const saved = await this._clickUpdate();
    this.assert(!!saved?.ok, 'Product update API OK', `status: ${saved?.status}`);

    await this.hardReload(SEL.formReady);
    await this.page.waitForFunction((sel) => document.querySelectorAll(sel).length === 2, SEL.cardVideo, { timeout: 10000 }).catch(() => null);
    this.assert((await this.page.evaluate((sel) => document.querySelectorAll(sel).length, SEL.cardVideo)) === 2, 'Media card preview shows the videos on a fresh load');
    await this._openGallery();
    await this._openTab();
    await this.page.waitForSelector(SEL.selectedCard, { timeout: 8000 }).catch(() => null);
    const countAfterReload = await this._cardCount();
    this.assert(countAfterReload === 2, 'Both videos persist after hard reload', `cards: ${countAfterReload}`);

    // ── 5. Remove one, then the other → Save → Update → reload ─────────
    await this._clickIn(SEL.removeBtn);
    this.assert((await this._cardCount()) === 1, 'Removing one video leaves the other card');
    await this._clickIn(SEL.removeBtn);
    this.assert(!(await this._isVisible(SEL.selectedCard)), 'All video cards gone after removing both');
    await this._saveDialog();
    await this.page.waitForFunction((sel) => document.querySelectorAll(sel).length === 0, SEL.cardVideo, { timeout: 10000 }).catch(() => null);
    this.assert((await this.page.evaluate((sel) => document.querySelectorAll(sel).length, SEL.cardVideo)) === 0, 'Media card preview drops the videos after removal');
    await this._waitForSaveBar();
    const removed = await this._clickUpdate();
    this.assert(!!removed?.ok, 'Product update (remove video) API OK', `status: ${removed?.status}`);

    await this.hardReload(SEL.formReady);
    await this._openGallery();
    await this._openTab();
    await this.pause();
    this.assert(!(await this._isVisible(SEL.selectedCard)), 'Video removal persists after hard reload');
    await this._cancelDialog();

    // ── 6. A media deleted in FluentPlayer degrades to a "Not found" card, quietly
    await this._openGallery();
    await this._openTab();
    await this.setInput(SEL.srcInput, VIDEO_URL);
    const doomed = await this.actionWithApi(() => this._clickIn(SEL.createBtn), '/fluent-player/v2/media', 'POST');
    this.assert(!!doomed?.ok, 'Created a throwaway FluentPlayer media', `status: ${doomed?.status}`);
    await this.page.waitForSelector(SEL.selectedCard, { timeout: 8000 }).catch(() => null);
    const doomedId = await this.page.evaluate((sel) => Number(document.querySelector(sel)?.getAttribute('data-fct-fp-video-id')) || 0, SEL.selectedCard);
    await this._saveDialog();
    await this._waitForSaveBar();
    const attachedDoomed = await this._clickUpdate();
    this.assert(doomedId > 0 && !!attachedDoomed?.ok, 'Attached the throwaway media to the product', `id: ${doomedId}, status: ${attachedDoomed?.status}`);

    const deleted = await this._deleteFluentPlayerMedia(doomedId);
    this.assert(deleted.ok, 'Deleted the media in FluentPlayer', `status: ${deleted.status}`);

    await this.hardReload(SEL.formReady);
    await this._openGallery();
    await this._openTab();
    await this.page.waitForSelector(SEL.missingTag, { timeout: 8000 }).catch(() => null);
    const missing = await this.page.evaluate(({ tag, card, toast }) => ({
      tag: !!document.querySelector(tag),
      cards: document.querySelectorAll(card).length,
      errorToasts: document.querySelectorAll(toast).length,
    }), { tag: SEL.missingTag, card: SEL.selectedCard, toast: SEL.errorToast });
    this.assert(missing.tag && missing.cards === 1, 'Deleted media renders a card tagged "Not found"', JSON.stringify(missing));
    this.assert(missing.errorToasts === 0, 'No error toast for a media FluentPlayer no longer returns');
    await this._clickIn(SEL.removeBtn);
    await this._saveDialog();
    await this._waitForSaveBar();
    const cleaned = await this._clickUpdate();
    this.assert(!!cleaned?.ok, 'Removing the deleted video persists', `status: ${cleaned?.status}`);

    // ── 7. Save/close are held while a video is still being created ────
    await this.hardReload(SEL.formReady);
    await this._openGallery();
    await this._openTab();
    await this.setInput(SEL.srcInput, VIDEO_URL);
    await this.page.waitForFunction((sel) => !document.querySelector(sel)?.disabled, SEL.createBtn, { timeout: 15000 }).catch(() => null);

    const slowCreate = async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      await new Promise(resolve => setTimeout(resolve, 3000));
      return route.continue();
    };
    await this.page.route(/fluent-player\/v2\/media(\?|$)/, slowCreate);
    const held = this.actionWithApi(() => this._clickIn(SEL.createBtn), '/fluent-player/v2/media', 'POST');
    await this.page.waitForFunction(({ sel, fn }) => new Function('sel', 'label', `return (${fn})(sel, label)`)(sel, 'Save').disabled, { sel: SEL.footerBtns, fn: footerButtonState.toString() }, { timeout: 2500 }).catch(() => null);
    const whilePending = await this.page.evaluate(({ sel, fn }) => {
      const state = new Function('sel', 'label', `return (${fn})(sel, label)`);
      return { save: state(sel, 'Save'), cancel: state(sel, 'Cancel') };
    }, { sel: SEL.footerBtns, fn: footerButtonState.toString() });
    this.assert(whilePending.save.disabled && whilePending.cancel.disabled, 'Save and Cancel are disabled while the video is being created', JSON.stringify(whilePending));
    await this.page.evaluate((sel) => [...document.querySelectorAll(sel)].find(b => b.textContent.trim() === 'Save')?.click(), SEL.footerBtns);
    await this.page.keyboard.press('Escape');
    await this.pause();
    this.assert(await this._isVisible(SEL.tabBody), 'Dialog stays open on Save click / Escape while a video is being created');

    const slowCreated = await held;
    await this.page.unroute(/fluent-player\/v2\/media(\?|$)/, slowCreate);
    this.assert(!!slowCreated?.ok, 'Delayed create API OK', `status: ${slowCreated?.status}`);
    await this.page.waitForSelector(SEL.selectedCard, { timeout: 8000 }).catch(() => null);
    await this.page.waitForFunction(({ sel, fn }) => !new Function('sel', 'label', `return (${fn})(sel, label)`)(sel, 'Save').disabled, { sel: SEL.footerBtns, fn: footerButtonState.toString() }, { timeout: 5000 }).catch(() => null);
    const afterCreate = await this.page.evaluate(({ sel, fn, card }) => ({
      saveEnabled: !new Function('sel', 'label', `return (${fn})(sel, label)`)(sel, 'Save').disabled,
      cards: document.querySelectorAll(card).length,
    }), { sel: SEL.footerBtns, fn: footerButtonState.toString(), card: SEL.selectedCard });
    this.assert(afterCreate.saveEnabled && afterCreate.cards === 1, 'Once created the video is attached and Save is enabled again', JSON.stringify(afterCreate));
    await this._saveDialog();
    await this._waitForSaveBar();
    const heldSaved = await this._clickUpdate();
    this.assert(!!heldSaved?.ok, 'Product update after the delayed create API OK', `status: ${heldSaved?.status}`);
    await this.hardReload(SEL.formReady);
    await this._openGallery();
    await this._openTab();
    await this.page.waitForSelector(SEL.selectedCard, { timeout: 8000 }).catch(() => null);
    this.assert((await this._cardCount()) === 1, 'The video created during the held Save persists');
    await this._clickIn(SEL.removeBtn);
    await this._saveDialog();
    await this._waitForSaveBar();
    await this._clickUpdate();
  }

  // ── Helpers ────────────────────────────────────────────────────────────

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

  async _deleteFluentPlayerMedia(mediaId) {
    return this.page.evaluate(async (id) => {
      const base = window.fluentCartAdminApp?.app_config?.fluentPlayer?.restUrl;
      const nonce = window.fluentCartRestVars?.rest?.nonce;
      if (!base || !nonce) return { ok: false, status: 'no rest config' };
      const res = await fetch(`${base}/media/${id}/force`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
      return { ok: res.ok, status: res.status };
    }, mediaId);
  }

  async _cardCount() {
    return this.page.evaluate((sel) => document.querySelectorAll(sel).length, SEL.selectedCard);
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

  async _isVisible(selector) {
    return this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      const style = window.getComputedStyle(el);
      return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetHeight > 0;
    }, selector);
  }
}

/**
 * Single Product — FluentPlayer demo video (thumbnail → plays inside the gallery)
 *
 * Preconditions: FluentPlayer active and a product configured with a
 * published video. Pass the product page URL via FCT_VIDEO_PRODUCT_URL;
 * otherwise the test discovers the first product that renders a video thumb
 * from the shop page and SKIPs when none is found.
 *
 * A product whose only gallery media is FluentPlayer video shows the (first)
 * video in the main area on load, without a click; with a single video there
 * is no thumb strip at all. Pass such a product via FCT_VIDEO_ONLY_PRODUCT_URL
 * (SKIPped otherwise).
 *
 * Each attached video is a thumbnail (FluentPlayer poster + play badge) after
 * the image thumbs in .fct-gallery-thumb-controls. Clicking one swaps the main
 * image for that video's inline FluentPlayer (and only that one); an image
 * thumb switches back; arrow keys reach the video thumbs; exactly one thumb
 * control is in the tab order on load (the first video thumb for a
 * video-only product).
 *
 * Selector contract (PHP: ProductVideoRenderer, JS: ImageGallery.js):
 *   .fct-gallery-video-thumb[data-fct-video-media-id]
 *   .fct-product-gallery-thumb.is-video-active
 *   [data-fct-product-gallery-video] .fluent-player
 */
import BaseTest from '../../base-test.mjs';

const SEL = {
  wrapper:       '[data-fluent-cart-product-gallery-wrapper]',
  region:        '.fct-product-gallery-thumb',
  mainImg:       '[data-fluent-cart-single-product-page-product-thumbnail]',
  videoThumb:    '.fct-gallery-video-thumb[data-fct-video-media-id]',
  videoBadge:    '.fct-gallery-video-thumb .fct-gallery-video-thumb__badge svg',
  imageThumb:    '[data-fluent-cart-thumb-control-button]:not([data-fct-video-media-id])',
  inlinePlayer:  '[data-fct-product-gallery-video].is-active .fluent-player',
  videoBoxes:    '[data-fct-product-gallery-video]',
  anyThumb:      '[data-fluent-cart-thumb-control-button]',
};

export default class ProductVideoGalleryTest extends BaseTest {
  static title = 'Single Product — FluentPlayer video thumbnail (plays in gallery)';
  static requiresLogin = false;

  async run() {
    const url = await this._resolveProductUrl();
    if (!url) {
      this.skip('Product with a FluentPlayer video', 'Set FCT_VIDEO_PRODUCT_URL or configure a product video first');
      return;
    }
    this.info(`Product page: ${url}`);
    await this.goto(url);
    await this.page.waitForSelector(SEL.wrapper, { timeout: 15000 }).catch(() => null);
    await this.pause();

    // ── Thumb contract ──────────────────────────────────────────────────
    const thumbInfo = await this.page.evaluate(({ thumb, badge }) => {
      const btn = document.querySelector(thumb);
      if (!btn) return null;
      const controls = [...document.querySelectorAll('[data-fluent-cart-thumb-control-button]')];
      const videoThumbs = controls.filter(c => c.hasAttribute('data-fct-video-media-id'));
      const productImages = [...document.querySelectorAll('[data-fluent-cart-thumb-control-button]:not([data-fct-video-media-id]) img')].map(i => i.getAttribute('src'));
      return {
        poster: btn.querySelector('img')?.getAttribute('src') || '',
        posterIsProductImage: productImages.includes(btn.querySelector('img')?.getAttribute('src') || ''),
        label: btn.getAttribute('aria-label') || '',
        pressed: btn.getAttribute('aria-pressed'),
        hasBadge: !!document.querySelector(badge),
        isLast: controls[controls.length - 1] === btn,
        videoCount: videoThumbs.length,
        videosAreTrailing: controls.slice(-videoThumbs.length).every(c => c.hasAttribute('data-fct-video-media-id')),
        playerCount: document.querySelectorAll('[data-fct-product-gallery-video]').length,
        inStrip: !!btn.closest('.fct-gallery-thumb-controls'),
        focusable: controls.filter(c => c.getAttribute('tabindex') === '0'),
        imageCount: controls.length - videoThumbs.length,
        firstVideoFocusable: videoThumbs[0]?.getAttribute('tabindex') === '0',
      };
    }, { thumb: SEL.videoThumb, badge: SEL.videoBadge });
    this.assert(!!thumbInfo, 'Video thumb rendered');
    if (!thumbInfo) return;
    this.assert(thumbInfo.inStrip, 'Video thumb sits inside .fct-gallery-thumb-controls with the images');
    this.info(`Videos attached: ${thumbInfo.videoCount}`);
    this.assert(thumbInfo.videosAreTrailing, 'Video thumbs come after the image thumbs');
    this.assert(thumbInfo.playerCount === thumbInfo.videoCount, 'One hidden player per video thumb', `${thumbInfo.playerCount}/${thumbInfo.videoCount}`);
    this.assert(!!thumbInfo.poster, 'Video thumb has a poster image', thumbInfo.poster);
    this.assert(!thumbInfo.posterIsProductImage, 'Video thumb poster is the FluentPlayer poster, not a product image');
    this.assert(thumbInfo.hasBadge, 'Video thumb shows a play badge');
    this.assert(thumbInfo.label.startsWith('Play video'), 'Video thumb has an accessible label', thumbInfo.label);
    this.assert(thumbInfo.pressed === 'false', 'Video thumb is not active on load');
    this.assert(thumbInfo.focusable.length === 1, 'Exactly one thumb control is in the tab order on load', `tabindex=0 count: ${thumbInfo.focusable.length}`);
    if (thumbInfo.imageCount === 0) {
      this.assert(thumbInfo.firstVideoFocusable, 'Video-only product: the first video thumb holds the roving tabindex');
    } else {
      this.assert(!thumbInfo.firstVideoFocusable, 'With image thumbs present the video thumb stays out of the tab order until selected');
    }

    await this._testInlineMode();
    await this._testVideoOnlyProduct();
  }

  // ── Video-only product: video shows directly ───────────────────────────

  async _testVideoOnlyProduct() {
    const url = process.env.FCT_VIDEO_ONLY_PRODUCT_URL;
    if (!url) {
      this.skip('Video-only product', 'Set FCT_VIDEO_ONLY_PRODUCT_URL to a product whose only gallery media is a FluentPlayer video');
      return;
    }
    this.info(`Video-only product page: ${url}`);
    await this.goto(url);
    await this.page.waitForSelector(SEL.wrapper, { timeout: 15000 }).catch(() => null);
    await this.pause();

    const onLoad = await this.page.evaluate(({ region, img, player }) => {
      const visible = (el) => {
        if (!el) return false;
        const style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetHeight > 0;
      };
      const regionEl = document.querySelector(region);
      const controls = [...document.querySelectorAll('[data-fluent-cart-thumb-control-button]')];
      return {
        imageThumbs: controls.filter(c => !c.hasAttribute('data-fct-video-media-id')).length,
        videoThumbs: controls.filter(c => c.hasAttribute('data-fct-video-media-id')).length,
        players: document.querySelectorAll('[data-fct-product-gallery-video]').length,
        videoActive: !!regionEl?.classList.contains('is-video-active'),
        defaultId: regionEl?.dataset.fctVideoDefault || '',
        activeId: document.querySelector('[data-fct-product-gallery-video].is-active')?.getAttribute('data-fct-video-media-id') || '',
        playerVisible: visible(document.querySelector(player)),
        imageVisible: visible(document.querySelector(img)),
        strip: !!document.querySelector('.fct-gallery-thumb-controls'),
        activeThumb: document.querySelector('.fct-gallery-video-thumb.active[aria-pressed="true"][tabindex="0"]')?.getAttribute('data-fct-video-media-id') || '',
      };
    }, { region: SEL.region, img: SEL.mainImg, player: SEL.inlinePlayer });
    this.assert(onLoad.imageThumbs === 0 && onLoad.players > 0, 'Precondition: product has FluentPlayer video(s) and no image thumbs', JSON.stringify(onLoad));
    this.assert(onLoad.videoActive && onLoad.playerVisible && !onLoad.imageVisible, 'Video is shown in the gallery main area on load, without a click', JSON.stringify(onLoad));
    this.assert(onLoad.activeId && onLoad.activeId === onLoad.defaultId, 'The first video is the active/default one', `${onLoad.activeId} / ${onLoad.defaultId}`);
    if (onLoad.players === 1) {
      this.assert(!onLoad.strip, 'A single video without images renders no thumb strip');
    } else {
      this.assert(onLoad.strip && onLoad.activeThumb === onLoad.defaultId, 'With several videos the strip shows and the first video thumb is selected', JSON.stringify(onLoad));
    }
  }

  // ── Inline mode ────────────────────────────────────────────────────────

  async _testInlineMode() {
    const boxBefore = await this.page.evaluate((sel) => { const r = document.querySelector(sel)?.getBoundingClientRect(); return r ? { w: Math.round(r.width), h: Math.round(r.height) } : null; }, SEL.region);

    // Every video thumb shows its own player, and only that one.
    const mediaIds = await this.page.evaluate(() => [...document.querySelectorAll('.fct-gallery-video-thumb[data-fct-video-media-id]')].map(b => b.getAttribute('data-fct-video-media-id')));
    for (const id of mediaIds) {
      await this._click(`.fct-gallery-video-thumb[data-fct-video-media-id="${id}"]`);
      const state = await this._regionState();
      const active = await this.page.evaluate(() => [...document.querySelectorAll('[data-fct-product-gallery-video].is-active')].map(b => b.getAttribute('data-fct-video-media-id')));
      this.assert(state.videoActive && state.playerVisible && !state.imageVisible, `Video #${id}: player shown, main image hidden`);
      this.assert(active.length === 1 && active[0] === id, `Video #${id}: only its own player is active`, active.join(','));
      const pressed = await this.page.evaluate((sel) => ({
        thisOne: document.querySelector(sel)?.getAttribute('aria-pressed'),
        pressedCount: document.querySelectorAll('[data-fluent-cart-thumb-control-button][aria-pressed="true"]').length,
        focusableCount: document.querySelectorAll('[data-fluent-cart-thumb-control-button][tabindex="0"]').length,
      }), `.fct-gallery-video-thumb[data-fct-video-media-id="${id}"]`);
      this.assert(pressed.thisOne === 'true' && pressed.pressedCount === 1 && pressed.focusableCount === 1, `Video #${id}: the clicked thumb alone is pressed and focusable`, JSON.stringify(pressed));
    }

    const boxAfter = await this.page.evaluate((sel) => { const r = document.querySelector(sel)?.getBoundingClientRect(); return r ? { w: Math.round(r.width), h: Math.round(r.height) } : null; }, SEL.region);
    this.assert(!!boxBefore && !!boxAfter && boxBefore.w === boxAfter.w && Math.abs(boxBefore.h - boxAfter.h) <= 1, 'Gallery main box keeps its size while the video shows', `${JSON.stringify(boxBefore)} → ${JSON.stringify(boxAfter)}`);

    const hasImageThumb = await this.page.evaluate((sel) => !!document.querySelector(sel), SEL.imageThumb);
    if (!hasImageThumb) {
      this.skip('Image thumb switch-back', 'Product has no image thumbnails');
      return;
    }

    await this._click(SEL.imageThumb);
    const back = await this._regionState();
    this.assert(!back.videoActive, 'Clicking an image thumb leaves video mode');
    this.assert(back.imageVisible, 'Main image is visible again');
    this.assert(!back.playerVisible, 'Inline player is hidden again');

    await this.page.evaluate((sel) => document.querySelector(sel)?.focus(), SEL.imageThumb);
    const thumbCount = await this.page.evaluate(() => document.querySelectorAll('[data-fluent-cart-thumb-control-button]:not(.is-hidden)').length);
    for (let i = 0; i < thumbCount; i++) {
      await this.page.keyboard.press('ArrowRight');
      const state = await this._regionState();
      if (state.videoActive) break;
    }
    const keyboardState = await this._regionState();
    this.assert(keyboardState.videoActive, 'Arrow-key navigation reaches and activates the video thumb');
    await this._click(SEL.imageThumb);
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  async _resolveProductUrl() {
    if (process.env.FCT_VIDEO_PRODUCT_URL) {
      return process.env.FCT_VIDEO_PRODUCT_URL;
    }
    await this.goto('/shop/');
    const links = await this.page.evaluate(() =>
      [...document.querySelectorAll('a[href*="/item/"]')].map(a => a.href).filter((v, i, arr) => arr.indexOf(v) === i).slice(0, 8)
    );
    for (const link of links) {
      await this.page.goto(link);
      const has = await this.page.evaluate(() => !!document.querySelector('.fct-gallery-video-thumb[data-fct-video-media-id]'));
      if (has) return link;
    }
    return null;
  }

  async _click(selector) {
    await this.page.evaluate((sel) => document.querySelector(sel)?.click(), selector);
    await this.pause();
  }

  async _regionState() {
    return this.page.evaluate(({ region, img, player, thumb }) => {
      const visible = (el) => {
        if (!el) return false;
        const style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetHeight > 0;
      };
      const regionEl = document.querySelector(region);
      return {
        videoActive: !!regionEl?.classList.contains('is-video-active'),
        imageVisible: visible(document.querySelector(img)),
        playerVisible: visible(document.querySelector(player)),
        thumbPressed: document.querySelector(thumb)?.getAttribute('aria-pressed') || null,
      };
    }, { region: SEL.region, img: SEL.mainImg, player: SEL.inlinePlayer, thumb: SEL.videoThumb });
  }
}

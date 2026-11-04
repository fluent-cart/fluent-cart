/**
 * Cloudflare Turnstile Handler — Invisible Checkout State Machine
 *
 * Covers the behavior introduced in commit f5e01844 (PR #1955):
 *   "fix(turnstile): keep widget invisible, manual fallback on auto-verify failure"
 *
 * Strategy:
 *   Real Cloudflare can't be driven deterministically (Safari ITP, error-callback
 *   timing). So we run the REAL TurnstileHandler class in a real browser against a
 *   FAKE `window.turnstile` global whose render/reset/remove are observable and
 *   whose challenge outcome we fire by hand. A real browser is required (not jsdom)
 *   because the core fix decides the removal path by `wrapper.offsetHeight > 0`.
 *
 * Fake contract (mirrors Cloudflare semantics):
 *   - turnstile.render(el, opts) → returns an id, records {id, el, opts}.
 *     appearance:'always' (visible mode) gives the element real height; the
 *     interaction-only auto-verify widget stays height 0 (invisible), exactly
 *     like Cloudflare.
 *   - turnstile.reset(id) / turnstile.remove(id) → bump observable counters.
 *   - __fireCallback(token) / __fireError() → drive the last widget's callbacks.
 *
 * Scenarios:
 *   1. Chrome transient error → silent retry ×2, stays invisible
 *   2. Safari ITP → retries exhausted → manual checkbox revealed (visible mode)
 *   3. Auto-verify success → token cached, widget removed off-screen (no loop)
 *   4. Manual verify (on-screen) → "Success!" hold → fade → physical removal
 *   5. reset() during fade → cancels timer, removes immediately, clears state
 *   6. getToken() returns a cached token instantly
 *   7. getToken() recreates a previously-removed .cf-turnstile element
 *   8. handleCheckoutSecurityVerification in visible mode → guidance toast, no reset
 *   9. PHP markup: 1px wrapper hack removed, execution:'render' added
 *
 * Selector / DOM contract (emitted by TurnstileBoot.php):
 *   [data-fluent-cart-turnstile-widget]       wrapper
 *   [data-fluent-cart-turnstile-widget] .cf-turnstile   the widget element
 *   data-turnstile-active="yes"                module active flag
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import BaseTest from '../../base-test.mjs';

const __dir = dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = join(__dir, '..', '..', '..', '..');
const HANDLER_SRC_PATH = join(PLUGIN_ROOT, 'resources/public/checkout/TurnstileHandler.js');
const BOOT_PHP_PATH = join(PLUGIN_ROOT, 'app/Modules/Turnstile/TurnstileBoot.php');

export default class TurnstileHandlerTest extends BaseTest {
  static title = 'Checkout — Cloudflare Turnstile Handler';

  // Self-contained: runs entirely on about:blank with a fake turnstile global.
  // No WordPress and no login required.
  static requiresLogin = false;

  async run() {
    await this._loadHarness();

    await this._testSilentRetryStaysInvisible();   // 1
    await this._testRetriesExhaustedRevealsManual(); // 2
    await this._testAutoVerifyRemovesOffScreen();   // 3
    await this._testManualVerifyFadesAndRemoves();  // 4
    await this._testResetCancelsFadeImmediately();  // 5
    await this._testGetTokenReturnsCached();        // 6
    await this._testGetTokenRecreatesElement();     // 7
    await this._testVisibleModeGuidanceToast();     // 8

    this._testPhpMarkup();                          // 9
  }

  // ── Harness ────────────────────────────────────────────────────────────────

  async _loadHarness() {
    this.info('Loading TurnstileHandler into a blank page with a fake turnstile global...');
    await this.page.goto('about:blank');

    // Inject the REAL class as a global (strip the ES `export default`).
    let src = readFileSync(HANDLER_SRC_PATH, 'utf8');
    src = src.replace(/export\s+default\s+TurnstileHandler;?\s*$/m, '');
    await this.page.addScriptTag({ content: src });

    const hasClass = await this.page.evaluate(() => typeof TurnstileHandler === 'function');
    this.assert(hasClass, 'TurnstileHandler class injected into page');
  }

  /**
   * Reset DOM + fake turnstile + a fresh handler instance for one scenario.
   * Returns nothing — state lives on window (__tsFake, __h).
   */
  async _fresh() {
    await this.page.evaluate(() => {
      // ── Fake turnstile global ──
      const fake = { renders: [], resetCount: 0, removeCount: 0, removed: new Set(), nextId: 1 };
      window.__tsFake = fake;
      window.turnstile = {
        render(el, opts) {
          const id = 'w' + (fake.nextId++);
          // Visible mode (appearance:'always') gets real height; interaction-only stays 0.
          if (opts && opts.appearance === 'always') {
            el.style.cssText = 'display:block;height:65px;width:300px;';
          }
          fake.renders.push({ id, el, opts });
          return id;
        },
        reset() { fake.resetCount++; },
        remove(id) { fake.removeCount++; fake.removed.add(id); },
        execute() {},
        getResponse() { return null; },
      };
      window.__lastRender = () => fake.renders[fake.renders.length - 1];
      window.__fireCallback = (token) => window.__lastRender().opts.callback(token);
      window.__fireError = () => window.__lastRender().opts['error-callback']();

      // ── Captured toasts (handler uses `new Toastify(...).showToast()`) ──
      window.__toasts = [];
      window.Toastify = function (opts) {
        return { showToast() { window.__toasts.push(opts); } };
      };

      // ── Checkout vars + wrapper DOM (as TurnstileBoot.php renders it) ──
      window.fluentcart_checkout_vars = { turnstile: { enabled: true, site_key: 'testkey' } };
      window.fluentCartTurnstileToken = null;
      document.body.innerHTML =
        '<div data-fluent-cart-turnstile-widget data-turnstile-active="yes">' +
        '<div class="cf-turnstile" data-sitekey="testkey"></div></div>';

      // Constructor auto-renders the interaction-only widget.
      window.__h = new TurnstileHandler();
    });
  }

  _state() {
    return this.page.evaluate(() => {
      const h = window.__h;
      const f = window.__tsFake;
      const visible = document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
      const wrapper = document.querySelector('[data-fluent-cart-turnstile-widget]');
      const last = f.renders[f.renders.length - 1];
      return {
        renderCount: f.renders.length,
        resetCount: f.resetCount,
        removeCount: f.removeCount,
        lastAppearance: last ? last.opts.appearance : null,
        widgetPresent: !!visible,
        wrapperHeight: wrapper ? wrapper.offsetHeight : -1,
        token: window.fluentCartTurnstileToken,
        inVisibleMode: h.inVisibleMode,
        visibleModeActive: h.visibleModeActive,
        autoRetryCount: h.autoRetryCount,
        fadeTimerActive: h.fadeTimer !== null,
        toasts: window.__toasts.map(t => t.text),
      };
    });
  }

  _waitFor(predicate, timeout = 4000) {
    return this.page.waitForFunction(predicate, null, { timeout, polling: 30 })
      .then(() => true).catch(() => false);
  }

  // ── 1. Silent retry keeps the widget invisible (Chrome transient error) ──────

  async _testSilentRetryStaysInvisible() {
    this.info('Scenario 1: transient error-callback → silent auto-verify retry ×2');
    await this._fresh();

    let s = await this._state();
    this.assert(s.renderCount === 1 && s.lastAppearance === 'interaction-only',
      'Widget auto-rendered invisibly on load', `appearance=${s.lastAppearance}`);

    // First transient error → deferred turnstile.reset(), no checkbox.
    await this.page.evaluate(() => window.__fireError());
    await this._waitFor(() => window.__tsFake.resetCount === 1);
    s = await this._state();
    this.assert(s.resetCount === 1, 'First error triggers one silent reset()', `resets=${s.resetCount}`);
    this.assert(s.autoRetryCount === 1, 'autoRetryCount incremented to 1');
    this.assert(!s.inVisibleMode && s.wrapperHeight === 0,
      'Still invisible after first error (no checkbox)', `height=${s.wrapperHeight}`);

    // Second transient error → second silent reset.
    await this.page.evaluate(() => window.__fireError());
    await this._waitFor(() => window.__tsFake.resetCount === 2);
    s = await this._state();
    this.assert(s.resetCount === 2, 'Second error triggers a second silent reset()', `resets=${s.resetCount}`);
    this.assert(!s.inVisibleMode && s.renderCount === 1,
      'Still invisible after two retries — no re-render to visible mode');
  }

  // ── 2. Retries exhausted → reveal manual checkbox (Safari ITP) ───────────────

  async _testRetriesExhaustedRevealsManual() {
    this.info('Scenario 2: third error after retries → switchToVisibleMode()');
    await this._fresh();

    // Exhaust the two silent retries, then a third genuine failure.
    await this.page.evaluate(async () => {
      window.__fireError(); // retry 1
      window.__fireError(); // retry 2
      window.__fireError(); // exhausted → visible mode (deferred)
    });
    const switched = await this._waitFor(() =>
      window.__h.inVisibleMode &&
      window.__tsFake.renders.some(r => r.opts.appearance === 'always'));
    this.assert(switched, 'Switched to visible mode after retries exhausted');

    const s = await this._state();
    this.assert(s.lastAppearance === 'always',
      'Manual widget re-rendered with appearance:always', `appearance=${s.lastAppearance}`);
    this.assert(s.removeCount >= 1, 'Stale interaction-only widget destroyed before re-render');
    this.assert(s.widgetPresent && s.wrapperHeight > 0,
      'Manual checkbox is on-screen (wrapper has height)', `height=${s.wrapperHeight}`);

    // No-op error-callback on the visible widget prevents an infinite loop.
    const noopError = await this.page.evaluate(() => {
      const r = window.__lastRender();
      return r.opts['error-callback'].toString().replace(/\s+/g, '') === '()=>{}';
    });
    this.assert(noopError, 'Visible-mode widget uses a no-op error-callback (no failure loop)');
  }

  // ── 3. Auto-verify success → cached + removed off-screen (kills the loop) ─────

  async _testAutoVerifyRemovesOffScreen() {
    this.info('Scenario 3: auto-verify success → token cached, widget removed off-screen');
    await this._fresh();

    await this.page.evaluate(() => window.__fireCallback('tok-auto'));
    const removed = await this._waitFor(() =>
      !document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile'));
    this.assert(removed, 'Invisible widget removed immediately after auto-verify');

    const s = await this._state();
    this.assert(s.token === 'tok-auto', 'Token cached on window.fluentCartTurnstileToken', `token=${s.token}`);
    this.assert(s.removeCount >= 1, 'turnstile.remove() called (SDK can no longer re-scan)');
    this.assert(s.autoRetryCount === 0, 'autoRetryCount reset after success');
    this.assert(!s.fadeTimerActive, 'No fade timer scheduled for off-screen removal');
  }

  // ── 4. Manual verify (on-screen) → hold + fade + physical removal ────────────

  async _testManualVerifyFadesAndRemoves() {
    this.info('Scenario 4: manual verify on-screen → success hold → fade → removal');
    await this._fresh();

    // Drive to visible mode.
    await this.page.evaluate(() => { window.__fireError(); window.__fireError(); window.__fireError(); });
    await this._waitFor(() => window.__tsFake.renders.some(r => r.opts.appearance === 'always'));

    const removeBefore = await this.page.evaluate(() => window.__tsFake.removeCount);

    // User clicks the checkbox → callback fires on the visible widget.
    await this.page.evaluate(() => window.__fireCallback('tok-manual'));

    // Immediately: token cached, fade timer armed, widget NOT yet removed.
    let s = await this._state();
    this.assert(s.token === 'tok-manual', 'Token cached immediately on manual verify');
    this.assert(s.fadeTimerActive, 'Fade timer armed (success shown briefly before removal)');
    this.assert(s.widgetPresent, 'Widget still on-screen right after verify (held for "Success!")');

    // After the ~800ms hold + ~200ms fade: physically removed.
    const removed = await this._waitFor(() =>
      !document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile'), 3000);
    this.assert(removed, 'Widget physically removed after fade completes');

    s = await this._state();
    this.assert(s.removeCount > removeBefore, 'turnstile.remove() called on fade completion');
    this.assert(!s.fadeTimerActive, 'Fade timer cleared after removal');
  }

  // ── 5. reset() during fade → cancel timer + remove now, clear state ──────────

  async _testResetCancelsFadeImmediately() {
    this.info('Scenario 5: reset() during fade window → immediate removal, no flash-back');
    await this._fresh();

    // Visible mode + manual verify → fade timer armed.
    await this.page.evaluate(() => { window.__fireError(); window.__fireError(); window.__fireError(); });
    await this._waitFor(() => window.__tsFake.renders.some(r => r.opts.appearance === 'always'));
    await this.page.evaluate(() => window.__fireCallback('tok-manual'));
    let s = await this._state();
    this.assert(s.fadeTimerActive, 'Fade timer armed before reset()');

    // Post-submit reset() — must cancel the fade and remove immediately.
    await this.page.evaluate(() => window.__h.reset());
    s = await this._state();
    this.assert(!s.fadeTimerActive, 'reset() cancels the pending fade timer');
    this.assert(!s.widgetPresent, 'reset() removes the lingering widget immediately (no flash-back)');
    this.assert(s.token === null, 'reset() clears the cached token');
    this.assert(!s.inVisibleMode && !s.visibleModeActive && s.autoRetryCount === 0,
      'reset() clears visible-mode + retry state');
  }

  // ── 6. getToken() returns cached token instantly ────────────────────────────

  async _testGetTokenReturnsCached() {
    this.info('Scenario 6: getToken() returns a cached token without re-render');
    await this._fresh();

    const before = await this.page.evaluate(() => window.__tsFake.renders.length);
    const token = await this.page.evaluate(async () => {
      window.fluentCartTurnstileToken = 'cached-tok';
      return await window.__h.getToken();
    });
    const after = await this.page.evaluate(() => window.__tsFake.renders.length);
    this.assert(token === 'cached-tok', 'getToken() resolves the cached token', `got=${token}`);
    this.assert(after === before, 'No new render triggered when a token is already cached');
  }

  // ── 7. getToken() recreates a removed .cf-turnstile element ──────────────────

  async _testGetTokenRecreatesElement() {
    this.info('Scenario 7: getToken() recreates the element after a prior verify removed it');
    await this._fresh();

    // Auto-verify success removes the element.
    await this.page.evaluate(() => window.__fireCallback('tok-auto'));
    await this._waitFor(() =>
      !document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile'));

    // Pay clicked again: clear token, call getToken() — it must recreate + render.
    const result = await this.page.evaluate(async () => {
      window.fluentCartTurnstileToken = null;
      const before = window.__tsFake.renders.length;
      const p = window.__h.getToken();
      // Let getToken recreate the element + render before the poll loop runs.
      await new Promise(r => setTimeout(r, 50));
      const exists = !!document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
      const rendered = window.__tsFake.renders.length > before;
      // Resolve the pending promise so it doesn't dangle.
      window.__fireCallback('tok-fresh');
      const token = await p;
      return { exists, rendered, token };
    });
    this.assert(result.exists, 'getToken() recreated the removed .cf-turnstile element');
    this.assert(result.rendered, 'getToken() re-rendered the widget on the fresh element');
    this.assert(result.token === 'tok-fresh', 'getToken() resolves with the new token', `got=${result.token}`);
  }

  // ── 8. Visible-mode security verification → guidance toast, no reset ─────────

  async _testVisibleModeGuidanceToast() {
    this.info('Scenario 8: pay in visible mode with no token → guidance toast, checkbox kept');
    await this._fresh();

    // Enter visible mode (manual checkbox shown, not yet clicked).
    await this.page.evaluate(() => { window.__fireError(); window.__fireError(); window.__fireError(); });
    await this._waitFor(() => window.__h.inVisibleMode);

    // Submit without verifying — getToken() resolves null while inVisibleMode.
    const ok = await this.page.evaluate(async () => {
      const fd = new FormData();
      return await window.__h.handleCheckoutSecurityVerification(fd);
    });
    this.assert(ok === false, 'handleCheckoutSecurityVerification returns false when unverified');

    const s = await this._state();
    const guided = s.toasts.some(t => /complete the security verification/i.test(t));
    this.assert(guided, 'Shows "complete the security verification above" guidance toast',
      `toasts=${JSON.stringify(s.toasts)}`);
    this.assert(s.widgetPresent, 'Checkbox kept visible (no reset) so the user can still click it');
  }

  // ── 9. PHP markup invariants ─────────────────────────────────────────────────

  _testPhpMarkup() {
    this.info('Scenario 9: TurnstileBoot.php markup invariants');
    const php = readFileSync(BOOT_PHP_PATH, 'utf8');

    this.assert(!/position:\s*fixed;[^"']*1px/.test(php) && !php.includes('pointer-events: none'),
      '1px fixed-position wrapper hack removed (no longer blocks Cloudflare init)');
    this.assert(php.includes("data-fluent-cart-turnstile-widget"),
      'Wrapper still emits data-fluent-cart-turnstile-widget hook');
    this.assert(/data-turnstile-active/.test(php),
      'Wrapper still emits data-turnstile-active flag');
    this.assert(php.includes("execution: 'render'") || php.includes('execution:'),
      'Footer fallback render adds execution:render so the challenge auto-fires');
  }
}

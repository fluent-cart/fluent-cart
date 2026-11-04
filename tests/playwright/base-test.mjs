/**
 * Base class for all browser tests.
 * Each test module extends this and implements run().
 *
 * Configurable pace via PACE env var:
 *   PACE=0     — no delay (CI mode)
 *   PACE=500   — 500ms after each action (default)
 *   PACE=1500  — slow, comfortable for watching
 */
export default class BaseTest {

  // Set via BaseTest.pace / BaseTest.headed from runner, or PACE env var
  static pace = null;  // resolved lazily from env
  static headed = true;

  // Whether this test needs a logged-in WordPress session.
  // Default true (admin tests). Self-contained tests that run on about:blank
  // (no WP/login needed) override this to false; the runner then skips login
  // for runs that contain only no-login tests.
  static requiresLogin = true;

  constructor(page, baseUrl) {
    this.page = page;
    this.baseUrl = baseUrl;
  }

  // Override in subclass
  static title = 'Unnamed Test';

  // Override in subclass
  async run() {
    throw new Error(`${this.constructor.name}.run() not implemented`);
  }

  // ─── Pace: pause after every visible action ──────────────────────────

  async pause(multiplier = 1) {
    if (!BaseTest.headed) return;
    const ms = BaseTest.pace * multiplier;
    console.log('waiting', ms, 'ms', '...')
    if (ms > 0) await this.page.waitForTimeout(ms);
  }

  // ─── In-browser progress overlay (headed mode only) ───────────────
  //
  // Chatbox-style floating widget:
  //   - Collapsed: small pill showing pass/fail count (click to expand)
  //   - Expanded: scrolling log of all assertions + current test title
  //

  async _ensureOverlay() {
    // Use addScriptTag — it persists across SPA navigations
    const exists = await this.page.evaluate(() => !!document.getElementById('__fct_test_overlay__')).catch(() => false);
    if (exists) return;

    await this.page.evaluate(() => {

      // Build overlay with pure inline styles (no <style> tag — WP admin can strip them)
      const wrapper = document.createElement('div');
      wrapper.id = '__fct_test_overlay__';
      wrapper.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:2147483647;display:flex;flex-direction:column;align-items:flex-end;font-family:-apple-system,BlinkMacSystemFont,monospace;';

      // Panel (hidden by default)
      const panel = document.createElement('div');
      panel.id = '__fct_panel__';
      panel.style.cssText = 'display:none;width:380px;max-height:400px;background:#0f172a;border:1px solid #1e293b;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.5);overflow:hidden;margin-bottom:8px;';

      const panelHeader = document.createElement('div');
      panelHeader.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #1e293b;';

      const panelTitle = document.createElement('span');
      panelTitle.id = '__fct_panel_title__';
      panelTitle.textContent = 'Starting...';
      panelTitle.style.cssText = 'font-weight:700;font-size:12px;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;';

      const panelClose = document.createElement('span');
      panelClose.textContent = '×';
      panelClose.style.cssText = 'cursor:pointer;color:#64748b;font-size:18px;padding:0 6px;';

      panelHeader.appendChild(panelTitle);
      panelHeader.appendChild(panelClose);

      const panelLog = document.createElement('div');
      panelLog.id = '__fct_panel_log__';
      panelLog.style.cssText = 'flex:1;overflow-y:auto;padding:8px 14px;font-size:11px;line-height:1.7;color:#94a3b8;max-height:300px;';

      panel.appendChild(panelHeader);
      panel.appendChild(panelLog);

      // Pill (always visible)
      const pill = document.createElement('div');
      pill.id = '__fct_pill__';
      pill.style.cssText = 'cursor:pointer;display:flex;align-items:center;gap:10px;padding:14px 24px;background:#1e40af;border:3px solid #60a5fa;border-radius:28px;font-weight:700;font-size:16px;color:white;box-shadow:0 6px 30px rgba(59,130,246,0.5);user-select:none;';

      const dot = document.createElement('span');
      dot.id = '__fct_dot__';
      dot.style.cssText = 'width:8px;height:8px;border-radius:50%;background:#4ade80;';

      const score = document.createElement('span');
      score.id = '__fct_pill_score__';
      score.textContent = '0✓ 0✗';

      const label = document.createElement('span');
      label.id = '__fct_pill_label__';
      label.textContent = 'Test';
      label.style.cssText = 'color:#64748b;font-weight:400;font-size:11px;';

      pill.appendChild(dot);
      pill.appendChild(score);
      pill.appendChild(label);

      wrapper.appendChild(panel);
      wrapper.appendChild(pill);
      document.body.appendChild(wrapper);

      // Toggle panel
      let panelOpen = false;
      pill.addEventListener('click', () => {
        panelOpen = !panelOpen;
        panel.style.display = panelOpen ? 'flex' : 'none';
        panel.style.flexDirection = 'column';
      });
      panelClose.addEventListener('click', (e) => {
        e.stopPropagation();
        panelOpen = false;
        panel.style.display = 'none';
      });
    }).catch(e => {
      if (!this._isIgnorableOverlayError(e)) {
        console.error('    [overlay inject error]', e.message);
      }
    });
  }

  async _updateOverlay(line) {
    await this._ensureOverlay();
    await this.page.evaluate(({ line, passed, failed, title }) => {
      const overlay = document.getElementById('__fct_test_overlay__');
      if (!overlay) return; // page navigated, will re-inject next call

      // Update pill
      const scoreEl = document.getElementById('__fct_pill_score__');
      if (scoreEl) scoreEl.textContent = `${passed}✓ ${failed}✗`;
      const dot = document.getElementById('__fct_dot__');
      if (dot) dot.style.background = failed > 0 ? '#f87171' : '#4ade80';
      const label = document.getElementById('__fct_pill_label__');
      if (label) label.textContent = title;

      // Update panel
      const titleEl = document.getElementById('__fct_panel_title__');
      if (titleEl) titleEl.textContent = `${title}  (${passed}✓ ${failed}✗)`;
      const log = document.getElementById('__fct_panel_log__');
      if (log && line) {
        const div = document.createElement('div');
        div.innerHTML = line;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
      }
    }, {
      line,
      passed: BaseTest._passed,
      failed: BaseTest._failed,
      title: this.constructor.title || 'Test'
    }).catch(e => {
      if (!this._isIgnorableOverlayError(e)) {
        console.error('    [overlay update error]', e.message);
      }
    });
  }

  _isIgnorableOverlayError(error) {
    const message = error?.message || '';
    return message.includes('Execution context was destroyed')
      || message.includes('Target page, context or browser has been closed')
      || message.includes('Target closed');
  }

  // ─── Assertions ──────────────────────────────────────────────────────

  assert(condition, name, detail = '') {
    const icon = condition ? '✅' : '❌';
    const color = condition ? '#4ade80' : '#f87171';
    console.log(`  ${icon} ${name}${detail ? ' — ' + detail : ''}`);
    BaseTest._results.push({ status: condition ? 'PASS' : 'FAIL', name, detail, suite: BaseTest._currentSuite });
    if (condition) BaseTest._passed++;
    else BaseTest._failed++;
    this._updateOverlay(`<span style="color:${color}">${icon} ${name}${detail ? ' <span style="color:#64748b">— ' + detail + '</span>' : ''}</span>`);
    return condition;
  }

  skip(name, detail = '') {
    const icon = '⏭️';
    console.log(`  ${icon} SKIP ${name}${detail ? ' — ' + detail : ''}`);
    BaseTest._results.push({ status: 'SKIP', name, detail, suite: BaseTest._currentSuite });
    BaseTest._skipped++;
    this._updateOverlay(`<span style="color:#fbbf24">${icon} SKIP ${name}${detail ? ' <span style="color:#64748b">— ' + detail + '</span>' : ''}</span>`);
  }

  info(message) {
    console.log(`  ℹ️  ${message}`);
    this._updateOverlay(`<span style="color:#64748b">ℹ️ ${message}</span>`);
  }

  // ─── Navigation ──────────────────────────────────────────────────────

  async goto(path) {
    const url = path.startsWith('http') ? path : `${this.baseUrl}${path}`;
    await this.page.goto(url);
    await this._ensureOverlay();
    await this.pause(3);
  }

  async adminPage(hash) {
    const currentUrl = this.page.url();
    const currentHash = currentUrl.split('#')[1] || '';
    const targetHash = hash.startsWith('/') ? hash : `/${hash}`;
    const isAlreadyOnAdmin = currentUrl.includes('page=fluent-cart');
    const isSamePage = isAlreadyOnAdmin && currentHash === targetHash;

    if (isSamePage) {
      // Already on this exact page — do nothing
      return;
    }

    if (isAlreadyOnAdmin) {
      // Same SPA, different route — just change the hash
      await this.page.evaluate((h) => {
        window.location.hash = h;
      }, targetHash);
      await this.pause(3);
    } else {
      // Full navigation (first load or coming from outside FluentCart)
      await this.goto(`/wp-admin/admin.php?page=fluent-cart#${targetHash}`);
    }
  }

  async hardReload(waitFor = '.el-input__inner, .el-switch, .el-checkbox') {
    await this.page.evaluate(() => location.reload(true));
    if (waitFor) {
      await this.page.waitForSelector(waitFor, { timeout: 15000 }).catch(() => null);
    }
    await this._ensureOverlay();
    await this.pause(2);
  }

  // ─── DOM helpers ─────────────────────────────────────────────────────

  async waitForText(text, timeout = 8000) {
    return this.page.waitForFunction(
      (t) => document.body.textContent.includes(t), text, { timeout }
    ).then(() => true).catch(() => false);
  }

  async waitForTextGone(text, timeout = 5000) {
    return this.page.waitForFunction(
      (t) => !document.body.textContent.includes(t), text, { timeout }
    ).then(() => true).catch(() => false);
  }

  async bodyText() {
    return this.page.textContent('body').catch(() => '');
  }

  async evaluate(fn, ...args) {
    return this.page.evaluate(fn, ...args);
  }

  async screenshot(name) {
    await this.page.screenshot({
      path: `.playwright-mcp/test-results/${name}.png`,
      fullPage: true
    }).catch(() => {});
  }

  // ─── API-aware actions ───────────────────────────────────────────────
  //
  // Every action that triggers an API call should:
  //   1. Perform the action
  //   2. Wait for the API response
  //   3. Report if the API returned an error
  //   4. Pause for pace
  //

  /**
   * Wait for an API response matching urlPart.
   * Returns { ok, status, data } or null if no response.
   */
  async waitForApi(urlPart, method = 'POST', timeout = 10000) {
    const response = await this.page.waitForResponse(
      r => r.url().includes(urlPart) && r.request().method() === method,
      { timeout }
    ).catch(() => null);

    if (!response) return null;

    const status = response.status();
    let data = null;
    try { data = await response.json(); } catch {}

    const ok = status >= 200 && status < 300;

    if (!ok) {
      const message = data?.message || data?.data?.message || `HTTP ${status}`;
      console.log(`  ⚠️  API Error: ${method} ...${urlPart} → ${status}: ${message}`);
    }

    return { ok, status, data };
  }

  /**
   * Perform an action (click/evaluate) and wait for an API response.
   * Returns the API result { ok, status, data } or null.
   */
  async actionWithApi(actionFn, urlPart, method = 'POST') {
    const [apiResult] = await Promise.all([
      this.waitForApi(urlPart, method),
      actionFn()
    ]);
    await this.pause();
    return apiResult;
  }

  // ─── Input helpers (with pace) ───────────────────────────────────────

  /**
   * Set an input value by CSS selector. Finds the inner <input> inside Element Plus wrappers.
   * Triggers input + change + blur events. Pauses after.
   */
  async setInput(selector, value) {
    const result = await this.evaluate(({ sel, val }) => {
      const wrapper = document.querySelector(sel);
      if (!wrapper) return false;
      const input = wrapper.matches('input') ? wrapper : wrapper.querySelector('input');
      if (!input) return false;
      const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      set.call(input, val);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.dispatchEvent(new Event('blur', { bubbles: true }));
      return true;
    }, { sel: selector, val: String(value) });
    await this.pause();
    return result;
  }

  /**
   * Get an input value by CSS selector.
   */
  async getInput(selector) {
    return this.evaluate((sel) => {
      const wrapper = document.querySelector(sel);
      if (!wrapper) return null;
      const input = wrapper.matches('input') ? wrapper : wrapper.querySelector('input');
      return input?.value || null;
    }, selector);
  }

  // Convenience: set/get by index (fallback for generic pages)
  async setNumberInput(index, value) {
    const result = await this.evaluate(({ idx, val }) => {
      const inputs = [...document.querySelectorAll('input[type="number"].el-input__inner')];
      if (!inputs[idx]) return false;
      const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      set.call(inputs[idx], val);
      inputs[idx].dispatchEvent(new Event('input', { bubbles: true }));
      inputs[idx].dispatchEvent(new Event('change', { bubbles: true }));
      inputs[idx].dispatchEvent(new Event('blur', { bubbles: true }));
      return true;
    }, { idx: index, val: String(value) });
    await this.pause();
    return result;
  }

  async getNumberInput(index) {
    return this.evaluate((idx) => {
      const inputs = [...document.querySelectorAll('input[type="number"].el-input__inner')];
      return inputs[idx]?.value || null;
    }, index);
  }

  /**
   * Click a button by text. Pauses after.
   */
  async clickButton(text) {
    const result = await this.evaluate((t) => {
      const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim().includes(t));
      if (btn) { btn.click(); return true; }
      return false;
    }, text);
    await this.pause();
    return result;
  }

  /**
   * Click a button and wait for an API response.
   * Returns { clicked, api } where api is { ok, status, data } or null.
   */
  async clickButtonWithApi(buttonText, urlPart, method = 'POST') {
    const api = await this.actionWithApi(
      () => this.evaluate((t) => {
        const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim().includes(t));
        if (btn) { btn.click(); return true; }
        return false;
      }, buttonText),
      urlPart,
      method
    );
    return api;
  }

  /**
   * Confirm a dialog (Yes/Delete/OK/Confirm). Pauses after.
   */
  async confirmDialog() {
    const result = await this.evaluate(() => {
      const btns = document.querySelectorAll('.el-message-box__btns button');
      const yes = [...btns].find(b =>
        b.textContent.includes('Yes') || b.textContent.includes('Delete') || b.textContent.includes('OK') || b.textContent.includes('Confirm')
      );
      if (yes) { yes.click(); return true; }
      return false;
    });
    await this.pause();
    return result;
  }

  /**
   * Close a dialog (Cancel / X button). Pauses after.
   */
  async closeDialog() {
    const result = await this.evaluate(() => {
      const overlay = [...document.querySelectorAll('.el-overlay')].find(o => o.style.display !== 'none');
      const close = overlay?.querySelector('.el-dialog__headerbtn')
        || [...(overlay?.querySelectorAll('button') || [])].find(b => b.textContent.includes('Cancel'));
      if (close) { close.click(); return true; }
      return false;
    });
    await this.pause();
    return result;
  }

  // ─── Static tracking ─────────────────────────────────────────────────

  static _passed = 0;
  static _failed = 0;
  static _skipped = 0;
  static _results = [];
  static _currentSuite = '';

  static resetResults() {
    BaseTest._passed = 0;
    BaseTest._failed = 0;
    BaseTest._skipped = 0;
    BaseTest._results = [];
    BaseTest._currentSuite = '';
  }

  static get summary() {
    return {
      passed: BaseTest._passed,
      failed: BaseTest._failed,
      skipped: BaseTest._skipped,
      total: BaseTest._passed + BaseTest._failed + BaseTest._skipped,
      results: BaseTest._results
    };
  }

  static printSummary() {
    const { passed, failed, skipped, total, results } = BaseTest.summary;
    console.log('\n' + '═'.repeat(50));
    console.log(`  Results: ${passed} passed, ${failed} failed, ${skipped} skipped, ${total} total`);
    console.log('═'.repeat(50));

    if (failed > 0) {
      console.log('\n❌ Failed tests:');
      results.filter(r => r.status === 'FAIL').forEach(r => {
        console.log(`   - ${r.name}${r.detail ? ': ' + r.detail : ''}`);
      });
    }
  }
}

/**
 * ElSelectHelper — reusable browser helper for Element Plus el-select interactions.
 *
 * Why this helper exists
 * ──────────────────────
 * El Plus v2 `el-select` has two behaviors that trip up naive DOM reads:
 *
 *   1. Async label population — `states.selectedLabel` starts as "" and is only
 *      populated after `el-option` children mount and call `setSelected()`. Reading
 *      `.el-select__selected-item` immediately after page load returns "" even when a
 *      value is bound. Every getValue/assertValue here waits for the span to populate.
 *
 *   2. Teleported dropdown — the option list is appended to <body> via Vue's Teleport,
 *      outside the select's DOM tree. Scoped selectors like
 *      `.my-wrapper .el-select-dropdown` do not work. Instead, every interaction method
 *      counts open dropdowns before clicking and identifies the *new* one that appears.
 *
 * Selector contract
 * ─────────────────
 *   Trigger:      .el-select
 *                 The root element rendered by <el-select>. Pass either the exact
 *                 `.el-select` selector or any ancestor that contains it — the helper
 *                 resolves the inner `.el-select` automatically.
 *
 *   Wrapper:      .el-select__wrapper   (clickable trigger inside .el-select)
 *
 *   Selected:     .el-select__selected-item   (div, present in single + multiple mode)
 *                 .el-select__selected-item span   (text node in single mode)
 *
 *   Dropdown:     .el-select-dropdown   (teleported to <body> by Teleport)
 *                 .el-select-dropdown__item   (one per option)
 *                 .el-select-dropdown__item.selected   (currently chosen option)
 *                 .el-select-dropdown__item.is-disabled   (disabled option)
 *
 *   Disabled:     .el-select.is-disabled   (set by El Plus when :disabled prop is true)
 *
 * Usage
 * ─────
 *   import ElSelectHelper from '../../helpers/el-select.mjs';
 *
 *   const sel = new ElSelectHelper(this);
 *
 *   // Read current value (handles async label population)
 *   const mode = await sel.getValue('.fct-product-pricing-wrap .el-select');
 *
 *   // Assert displayed label
 *   await sel.assertValue('.fct-product-pricing-wrap .el-select', 'Simple');
 *
 *   // Select an option by label
 *   await sel.select('.fct-product-pricing-wrap .el-select', 'Simple Variations');
 *
 *   // Select an option in a filterable select
 *   await sel.filterAndSelect('.fct-country-select', 'Italy', 'Italy');
 *
 *   // List available options without leaving the dropdown open
 *   const opts = await sel.getOptions('.fct-some-select .el-select');
 *
 *   // State checks
 *   await sel.assertDisabled('.fct-read-only-field .el-select');
 *   await sel.assertEnabled('.fct-editable-field .el-select');
 */
export default class ElSelectHelper {

  constructor(test) {
    this.test = test;
    this.page = test.page;
  }

  // ─── Internal: resolve .el-select inside any ancestor selector ────────

  /**
   * Evaluate helper: given a CSS selector for either .el-select itself or
   * a parent that contains one, return the .el-select element.
   * Used inside page.evaluate() calls — must be inlined there.
   */
  static _resolveEl(sel) {
    const wrap = document.querySelector(sel);
    if (!wrap) return null;
    if (wrap.classList.contains('el-select')) return wrap;
    return wrap.querySelector('.el-select');
  }

  // ─── Value reading ────────────────────────────────────────────────────

  /**
   * Read the currently-displayed label of a single-mode el-select.
   *
   * Waits up to `timeout` ms for the label to become non-empty (El Plus v2
   * populates it async after el-option components mount), then returns the text.
   *
   * Returns "" if the element is not found or has no label after the wait.
   *
   * @param {string} selector  CSS selector for .el-select or a parent containing it.
   * @param {number} timeout   Max ms to wait for a non-empty label. Default 5000.
   */
  async getValue(selector, timeout = 5000) {
    this.test.info(`Reading value of select (${selector})`);

    // Wait until the inner span has non-empty text
    await this.page.waitForFunction(({ sel }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      if (!el) return false;
      const span = el.querySelector('.el-select__selected-item span');
      return !!(span && span.textContent.trim().length > 0);
    }, { sel: selector }, { timeout }).catch(() => {});

    return this.page.evaluate(({ sel }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      if (!el) return '';
      // Prefer the inner span (single mode)
      const span = el.querySelector('.el-select__selected-item span');
      if (span && span.textContent.trim()) return span.textContent.trim();
      // Fallback: outer div text content (covers edge cases)
      const item = el.querySelector('.el-select__selected-item');
      return item ? item.textContent.trim() : '';
    }, { sel: selector });
  }

  /**
   * Assert the currently-displayed label matches `expected`.
   *
   * @param {string} selector   CSS selector for .el-select or a parent containing it.
   * @param {string} expected   Exact expected label text.
   * @param {string} [message]  Custom assertion label. Defaults to a descriptive string.
   */
  async assertValue(selector, expected, message) {
    const actual = await this.getValue(selector);
    const label = message || `Select (${selector}) shows "${expected}"`;
    this.test.assert(actual === expected, label, `actual: "${actual}"`);
    return actual === expected;
  }

  // ─── Option selection ─────────────────────────────────────────────────

  /**
   * Open the dropdown and click the option whose visible text matches `label`.
   *
   * Handles dropdowns teleported to <body>: counts open dropdowns before clicking
   * and waits for the count to increase, then targets the newly-opened one.
   *
   * @param {string}  selector         CSS selector for .el-select or a parent.
   * @param {string}  label            Option label to click.
   * @param {Object}  [opts]
   * @param {boolean} [opts.exact]     true = exact match; false (default) = case-insensitive includes.
   */
  async select(selector, label, { exact = false } = {}) {
    this.test.info(`Selecting "${label}" in select (${selector})`);

    const countBefore = await this.page.evaluate(
      () => document.querySelectorAll('.el-select-dropdown').length
    );

    // Click the trigger to open the dropdown
    await this.page.evaluate(({ sel }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      const trigger = el && (el.querySelector('.el-select__wrapper') || el.querySelector('input'));
      if (trigger) trigger.click();
    }, { sel: selector });

    // Wait for a new dropdown to appear
    await this.page.waitForFunction(
      (cb) => document.querySelectorAll('.el-select-dropdown').length > cb,
      countBefore,
      { timeout: 5000 }
    ).catch(() => {});

    await this.page.waitForFunction(() => [...document.querySelectorAll('.el-select-dropdown')]
      .some(d => d.getClientRects().length > 0 && d.querySelector('.el-select-dropdown__item')), null, { timeout: 5000 }).catch(() => {});
    await this.test.pause(0.5);

    // Find and click the matching option in the last-opened dropdown
    const result = await this.page.evaluate(({ lbl, isExact }) => {
      // Element Plus keeps closed dropdowns in the DOM; the open one is the visible one.
      const dropdowns = [...document.querySelectorAll('.el-select-dropdown')];
      const dropdown = dropdowns.filter(d => d.getClientRects().length > 0).pop() || dropdowns[dropdowns.length - 1];
      if (!dropdown) return 'no-dropdown';

      const items = [...dropdown.querySelectorAll('.el-select-dropdown__item')];
      const match = items.find(item => {
        const text = item.textContent.trim();
        return isExact ? text === lbl : text.toLowerCase().includes(lbl.toLowerCase());
      });
      if (!match) {
        return 'no-option:' + items.map(i => i.textContent.trim()).join('|');
      }
      match.click();
      return 'ok';
    }, { lbl: label, isExact: exact });

    const ok = result === 'ok';
    this.test.assert(ok, `Option "${label}" selected`, ok ? '' : result);

    // Wait for dropdown to close
    await this.page.waitForFunction(
      (cb) => document.querySelectorAll('.el-select-dropdown').length <= cb,
      countBefore,
      { timeout: 3000 }
    ).catch(() => {});

    await this.test.pause(0.5);
    return ok;
  }

  /**
   * For filterable el-select: type `filterText` into the input to narrow results,
   * then click the option whose visible text matches `label`.
   *
   * @param {string} selector     CSS selector for .el-select or a parent.
   * @param {string} filterText   Text to type into the filter input.
   * @param {string} label        Option label to click after filtering.
   */
  async filterAndSelect(selector, filterText, label) {
    this.test.info(`Filter-selecting "${label}" (filter: "${filterText}") in (${selector})`);

    const countBefore = await this.page.evaluate(
      () => document.querySelectorAll('.el-select-dropdown').length
    );

    // Click the trigger and type the filter text into the input
    await this.page.evaluate(({ sel, text }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      if (!el) return;

      // For filterable selects: click wrapper first, then update the input
      const wrapper = el.querySelector('.el-select__wrapper');
      if (wrapper) wrapper.click();

      const input = el.querySelector('input');
      if (input) {
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        setter.call(input, text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, { sel: selector, text: filterText });

    // Wait for the dropdown to appear with filtered results
    await this.page.waitForFunction(
      (cb) => document.querySelectorAll('.el-select-dropdown').length > cb,
      countBefore,
      { timeout: 5000 }
    ).catch(() => {});

    await this.page.waitForFunction(() => [...document.querySelectorAll('.el-select-dropdown')]
      .some(d => d.getClientRects().length > 0 && d.querySelector('.el-select-dropdown__item')), null, { timeout: 5000 }).catch(() => {});
    await this.test.pause(0.5);

    const result = await this.page.evaluate(({ lbl }) => {
      // Element Plus keeps closed dropdowns in the DOM; the open one is the visible one.
      const dropdowns = [...document.querySelectorAll('.el-select-dropdown')];
      const dropdown = dropdowns.filter(d => d.getClientRects().length > 0).pop() || dropdowns[dropdowns.length - 1];
      if (!dropdown) return 'no-dropdown';

      const items = [...dropdown.querySelectorAll('.el-select-dropdown__item')];
      const match = items.find(i => i.textContent.trim().toLowerCase().includes(lbl.toLowerCase()));
      if (!match) {
        return 'no-option:' + items.map(i => i.textContent.trim()).join('|');
      }
      match.click();
      return 'ok';
    }, { lbl: label });

    const ok = result === 'ok';
    this.test.assert(ok, `Filter-selected "${label}"`, ok ? '' : result);

    await this.page.waitForFunction(
      (cb) => document.querySelectorAll('.el-select-dropdown').length <= cb,
      countBefore,
      { timeout: 3000 }
    ).catch(() => {});

    await this.test.pause(0.5);
    return ok;
  }

  // ─── Inspection ───────────────────────────────────────────────────────

  /**
   * Open the dropdown, collect all visible option labels, then close via Escape.
   *
   * Useful for asserting the available set of options without selecting one.
   *
   * @param {string} selector  CSS selector for .el-select or a parent.
   * @returns {string[]}  Array of option label strings.
   */
  async getOptions(selector) {
    this.test.info(`Reading options of select (${selector})`);

    const countBefore = await this.page.evaluate(
      () => document.querySelectorAll('.el-select-dropdown').length
    );

    await this.page.evaluate(({ sel }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      const trigger = el && (el.querySelector('.el-select__wrapper') || el.querySelector('input'));
      if (trigger) trigger.click();
    }, { sel: selector });

    await this.page.waitForFunction(
      (cb) => document.querySelectorAll('.el-select-dropdown').length > cb,
      countBefore,
      { timeout: 5000 }
    ).catch(() => {});

    await this.test.pause(0.3);

    const options = await this.page.evaluate(() => {
      const dropdowns = [...document.querySelectorAll('.el-select-dropdown')];
      const dropdown = dropdowns[dropdowns.length - 1];
      if (!dropdown) return [];
      return [...dropdown.querySelectorAll('.el-select-dropdown__item')]
        .map(i => i.textContent.trim())
        .filter(t => t.length > 0);
    });

    await this.page.keyboard.press('Escape');
    await this.test.pause(0.3);

    return options;
  }

  /**
   * Assert the select is in a disabled state (.el-select.is-disabled).
   *
   * @param {string} selector  CSS selector for .el-select or a parent.
   */
  async assertDisabled(selector) {
    const disabled = await this.page.evaluate(({ sel }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      return el ? el.classList.contains('is-disabled') : false;
    }, { sel: selector });
    this.test.assert(disabled, `Select (${selector}) is disabled`);
    return disabled;
  }

  /**
   * Assert the select is NOT disabled.
   *
   * @param {string} selector  CSS selector for .el-select or a parent.
   */
  async assertEnabled(selector) {
    const enabled = await this.page.evaluate(({ sel }) => {
      const wrap = document.querySelector(sel);
      const el = (wrap && wrap.classList.contains('el-select')) ? wrap : wrap && wrap.querySelector('.el-select');
      return el ? !el.classList.contains('is-disabled') : false;
    }, { sel: selector });
    this.test.assert(enabled, `Select (${selector}) is enabled`);
    return enabled;
  }

  /**
   * Assert a specific option label exists in the dropdown (opens + closes it).
   *
   * @param {string} selector  CSS selector for .el-select or a parent.
   * @param {string} label     Option label text to look for.
   */
  async assertHasOption(selector, label) {
    const options = await this.getOptions(selector);
    const found = options.some(o => o.toLowerCase().includes(label.toLowerCase()));
    this.test.assert(found, `Option "${label}" exists in select (${selector})`, `options: ${options.join(', ')}`);
    return found;
  }

  // ─── Utility ──────────────────────────────────────────────────────────

  /**
   * Close any open el-select dropdown by pressing Escape.
   */
  async close() {
    await this.page.keyboard.press('Escape');
    await this.test.pause(0.3);
  }
}

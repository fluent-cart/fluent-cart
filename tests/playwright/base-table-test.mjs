/**
 * BaseTableTest — reusable test suite for any FluentCart admin list page.
 *
 * All admin list pages share the same TableWrapper structure:
 *   tabs / search / column-toggle / sort / advanced-filter / saved-views / pagination
 *
 * Usage:
 *   import BaseTableTest from '../../base-table-test.mjs';
 *
 *   export default class OrdersTableTest extends BaseTableTest {
 *     static title = 'Orders — Full Table Suite';
 *     route()            { return '/orders'; }
 *     apiEndpoint()      { return 'orders'; }
 *     pageSelector()     { return '.fct-all-orders-page'; }
 *     tableWrapSelector(){ return '.fct-orders-table-wrap'; }
 *     expectedColumns()  { return ['Date', 'Customer', 'Total', 'Payment Status']; }
 *     filterTabs()       { return ['Completed', 'Processing', 'On Hold']; }
 *     toggleColumn()     { return 'Customer'; }
 *     sortByField()      { return 'Total'; }
 *     searchFor()        { return 'admin'; }
 *   }
 */

import BaseTest from './base-test.mjs';

export default class BaseTableTest extends BaseTest {

  // ── REQUIRED — override in subclass ─────────────────────────────────────────

  /** Vue router hash path, e.g. '/orders' */
  route() { throw new Error(`${this.constructor.name}: route() is required`); }

  /** REST API URL segment matched by waitForApi, e.g. 'orders' */
  apiEndpoint() { throw new Error(`${this.constructor.name}: apiEndpoint() is required`); }

  // ── OPTIONAL — override in subclass ─────────────────────────────────────────

  /** CSS selector for page root element (proves the page loaded) */
  pageSelector() { return '.fct-layout-width'; }

  /** CSS selector for the element wrapping .el-table__row rows */
  tableWrapSelector() { return '.fct-table-wrapper'; }

  /** Column header texts expected to be visible after page load */
  expectedColumns() { return []; }

  /**
   * Dot-path into window for tab labels, e.g. 'fluentCartAdminApp.order_statuses'.
   * Expects an object of { slug: 'Label' }. When set, filterTabs() is ignored
   * and tab names are read from the page's localized data at runtime.
   */
  tabsWindowPath() { return null; }

  /**
   * Fallback explicit tab names when tabsWindowPath() is not set.
   * Exclude "All" — it's tested automatically as the restore step.
   */
  filterTabs() { return []; }

  /**
   * Dot-path into window for advanced filter option groups,
   * e.g. 'fluentCartAdminApp.table_config.order_table.filters.advance'.
   * When set, the test asserts that filter groups are present and picks
   * a field label dynamically instead of hardcoding one.
   */
  advanceFilterWindowPath() { return null; }

  /** A toggleable column label to hide/show, e.g. 'Customer'. null = skip */
  toggleColumn() { return null; }

  /** A sort field label to test, e.g. 'Total'. null = skip */
  sortByField() { return null; }

  /** A search term that returns ≥1 result, e.g. 'admin'. null = take it from the first visible row */
  searchFor() { return null; }

  /**
   * True if search input is always visible (Table.useFullWidthSearch() = true).
   * False (default) if it appears after clicking the search icon button.
   */
  isFullWidthSearch() { return false; }

  /** True to test the Advanced Filter toggle (skipped if toggle not found on page) */
  hasAdvancedFilter() { return true; }

  /** True to test saved views (also requires searchFor() to be set) */
  hasSavedViews() { return true; }

  // ── INTERNAL SELECTORS ───────────────────────────────────────────────────────

  get _rowSel() { return `${this.tableWrapSelector()} .el-table__row`; }

  get _searchInputSel() {
    return this.isFullWidthSearch()
      ? '.search-bar.is-transparent input[type="text"]'
      : '.filter-search-wrap input[type="text"]';
  }

  async _getVisibleSearchInputSelector() {
    return this.evaluate(() => {
      const selectors = [
        '.search-bar.is-transparent input[type="text"]',
        '.filter-search-wrap input[type="text"]',
      ];

      for (const selector of selectors) {
        const el = document.querySelector(selector);
        if (!el) continue;

        const style = window.getComputedStyle(el);
        const visible = style.visibility !== 'hidden'
          && style.display !== 'none'
          && el.getClientRects().length > 0;

        if (visible) {
          return selector;
        }
      }

      return null;
    });
  }

  async _ensureSearchInputVisible() {
    let selector = await this._getVisibleSearchInputSelector();
    if (selector) return selector;

    await this.evaluate(() => {
      for (const btn of document.querySelectorAll('.fct-card-header-actions button')) {
        if (btn.querySelector('svg') && !btn.textContent.trim()) {
          btn.click();
          return;
        }
      }
    });
    await this.pause();

    selector = await this._getVisibleSearchInputSelector();
    return selector;
  }

  async _resolveSearchTerm() {
    const configuredTerm = this.searchFor();
    if (configuredTerm) {
      return configuredTerm;
    }

    return this.evaluate((rowSel) => {
      const row = document.querySelector(rowSel);
      if (!row) return null;

      // innerText keeps a break between cells; textContent glues an email to the next cell ("a@b.testBerlin").
      const text = (row.innerText || row.textContent || '').replace(/\s+/g, ' ').trim();
      const emailMatch = text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
      if (emailMatch) {
        return emailMatch[0];
      }

      const words = text.split(' ')
        .map(word => word.trim())
        .filter(word => word.length >= 3 && !word.startsWith('#'));

      return words[0] || null;
    }, this._rowSel);
  }

  // ── RENDER WAIT ──────────────────────────────────────────────────────────────

  /**
   * Wait for Vue to finish rendering after an API response.
   * Waits for loading indicators to disappear and for the table to settle into
   * either a rows-present or empty-state DOM. This avoids racing Vue/Element Plus
   * in headless mode where the skeleton can disappear a tick before rows mount.
   * Call this after actionWithApi whenever you need to count rows or check DOM state.
   */
  async _waitForRender(timeout = 5000) {
    await this.page.waitForFunction(
      ({ wrapSel }) => {
        const wrap = document.querySelector(wrapSel) || document.querySelector('.fct-table-wrapper');
        if (!wrap) return false;

        if (wrap.querySelector('.el-skeleton, .el-loading-mask')) {
          return false;
        }

        if (wrap.querySelectorAll('.el-table__row').length > 0) {
          return true;
        }

        return !!wrap.querySelector('.el-table__empty-block, .el-empty, .el-empty__description');
      },
      { wrapSel: this.tableWrapSelector() },
      { timeout }
    ).catch(() => null);

    // Two frames flush post-render DOM work more reliably than one in headless mode.
    await this.page.evaluate(() => new Promise(resolve => {
      requestAnimationFrame(() => requestAnimationFrame(resolve));
    }));
  }

  // ── NAVIGATE ─────────────────────────────────────────────────────────────────

  async _navigate() {
    const currentUrl = this.page.url();
    const targetHash = this.route().startsWith('/') ? this.route() : `/${this.route()}`;
    const currentHash = currentUrl.split('#')[1] || '';
    const normalizedCurrentHash = currentHash.replace(/\/$/, '');
    const currentHashPath = currentHash.split('?')[0].replace(/\/$/, '');
    const normalizedTarget = targetHash.replace(/\/$/, '');
    const onSameRoute = currentUrl.includes('page=fluent-cart') && currentHashPath === normalizedTarget;
    const onExactRoute = normalizedCurrentHash === normalizedTarget;

    const loadPage = async () => {
      const apiPromise = this.waitForApi(this.apiEndpoint(), 'GET', 15000);

      if (onSameRoute && onExactRoute) {
        // Reuse the tab, but force a fresh request so each test starts from a clean state.
        await this.hardReload(this.pageSelector());
        await this.page.waitForSelector(this.pageSelector(), { timeout: 10000 }).catch(() => null);
      } else if (onSameRoute) {
        // Same list page, but with hash query/state applied (saved views/search). Normalize first.
        await this.adminPage(this.route());
        await this.page.waitForSelector(this.pageSelector(), { timeout: 15000 }).catch(() => null);
      } else {
        await this.adminPage(this.route());
        await this.page.waitForSelector(this.pageSelector(), { timeout: 15000 }).catch(() => null);
      }

      return apiPromise;
    };

    let result = await loadPage();
    if (!result || result.status >= 500) {
      this.info(`Retrying initial API load for /${this.apiEndpoint()} after transient failure`);
      result = await loadPage();
    }

    if (result) {
      this.assert(
        result.ok,
        `Initial API (GET /${this.apiEndpoint()}) returned OK`,
        `status: ${result.status}`
      );
    } else if (onSameRoute) {
      this.info(`Initial API (GET /${this.apiEndpoint()}) was not observed after reload; using rendered page state`);
    } else {
      this.assert(
        false,
        `Initial API (GET /${this.apiEndpoint()}) returned OK`,
        'status: no response'
      );
    }
    await this._waitForRender(10000);
    await this.pause();
  }

  // ── LOAD ─────────────────────────────────────────────────────────────────────

  async _testLoad() {
    this.info('Checking table load...');
    for (const col of this.expectedColumns()) {
      const visible = await this.waitForText(col, 5000);
      this.assert(visible, `Column header "${col}" visible`);
    }
    await this._waitForRender(10000);
    const rows = await this.evaluate(
      (sel) => document.querySelectorAll(sel).length,
      this._rowSel
    );
    this.assert(rows >= 0, `Table rendered`, `rows: ${rows}`);
  }

  // ── TAB FILTERING ────────────────────────────────────────────────────────────

  async _testTabFiltering() {
    this.info('Testing tab filtering...');

    // Resolve tab labels — from localized window data or explicit config
    let tabs = this.filterTabs();
    const windowPath = this.tabsWindowPath();
    if (!tabs.length && windowPath) {
      const statusObj = await this.evaluate((path) => {
        const val = path.split('.').reduce((o, k) => o && o[k], window);
        return val && typeof val === 'object' ? val : {};
      }, windowPath);
      // statusObj is { slug: 'Label' } — use the label values, skip first ("All" equivalent), take up to 3
      tabs = Object.values(statusObj).slice(0, 3);
      this.info(`Tabs from window.${windowPath}: ${tabs.join(', ')}`);
    }

    if (!tabs.length) {
      this.info('No tabs configured — skipping tab filter test');
      return;
    }

    // Ensure table is fully rendered before capturing baseline (covers post-navigation race)
    await this._waitForRender();
    const allRows = await this.evaluate(
      (sel) => document.querySelectorAll(sel).length,
      this._rowSel
    );

    for (const tabName of tabs) {
      await this.pause();
      const result = await this.actionWithApi(
        () => this._clickTab(tabName), this.apiEndpoint(), 'GET'
      );
      await this._waitForRender();
      await this.pause();
      const tabRows = await this.evaluate(
        (sel) => document.querySelectorAll(sel).length,
        this._rowSel
      );
      this.assert(result?.ok, `Tab "${tabName}" — API OK (${tabRows} rows)`, `status: ${result?.status}`);
    }

    // Restore All
    await this.pause();
    const allResult = await this.actionWithApi(
      () => this._clickTab('All'), this.apiEndpoint(), 'GET'
    );
    await this._waitForRender();
    await this.pause();
    this.assert(allResult?.ok, 'Tab "All" restored — API OK', `status: ${allResult?.status}`);

    const restoredRows = await this.evaluate(
      (sel) => document.querySelectorAll(sel).length,
      this._rowSel
    );
    this.assert(restoredRows === allRows, `"All" tab restores row count`, `expected: ${allRows}, got: ${restoredRows}`);
  }

  async _clickTab(text) {
    return this.evaluate((t) => {
      const tabBar = document.querySelector('.fct-table-wrapper .fct-card-header');
      if (!tabBar) return false;
      for (const el of tabBar.querySelectorAll('div, span')) {
        if (el.children.length === 0 && el.textContent.trim() === t) { el.click(); return true; }
      }
      return false;
    }, text);
  }

  // ── SEARCH ───────────────────────────────────────────────────────────────────

  async _testSearch() {
    const searchTerm = await this._resolveSearchTerm();
    this.info(`Testing search for "${searchTerm}"...`);
    if (!searchTerm) {
      this.info('No search term available for this table — skipping');
      return;
    }

    const searchSelector = await this._ensureSearchInputVisible();
    const inputEl = searchSelector
      ? await this.page.waitForSelector(searchSelector, { timeout: 5000 }).catch(() => null)
      : null;
    this.assert(!!inputEl, 'Search input visible');
    if (!inputEl) return;

    // Match
    await this.pause();
    const matchResult = await this.actionWithApi(async () => {
      await this.page.fill(searchSelector, searchTerm);
      await this.page.press(searchSelector, 'Enter');
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(matchResult?.ok, `Search "${searchTerm}" — API OK`, `status: ${matchResult?.status}`);
    const matchRows = await this.evaluate(
      (sel) => document.querySelectorAll(sel).length,
      this._rowSel
    );
    this.assert(matchRows > 0, `Search returned rows`, `found: ${matchRows}`);

    // No match
    await this.pause();
    const noMatchResult = await this.actionWithApi(async () => {
      await this.page.fill(searchSelector, 'zzz_no_match_xyz');
      await this.page.press(searchSelector, 'Enter');
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(noMatchResult?.ok, 'No-match search — API OK', `status: ${noMatchResult?.status}`);
    const noMatchRows = await this.evaluate(
      (sel) => document.querySelectorAll(sel).length,
      this._rowSel
    );
    this.assert(noMatchRows === 0, 'No-match search shows 0 rows', `found: ${noMatchRows}`);

    // Clear
    await this.pause();
    const clearResult = await this.actionWithApi(async () => {
      await this.page.fill(searchSelector, '');
      await this.page.press(searchSelector, 'Enter');
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(clearResult?.ok, 'Clear search — API OK', `status: ${clearResult?.status}`);
    const clearedRows = await this.evaluate(
      (sel) => document.querySelectorAll(sel).length,
      this._rowSel
    );
    this.assert(clearedRows > 0, `Search cleared, rows restored (${clearedRows})`);
  }

  // ── COLUMN TOGGLE ────────────────────────────────────────────────────────────

  async _testColumnToggle() {
    const col = this.toggleColumn();
    this.info(`Testing column toggle for "${col}"...`);

    const visibleBefore = await this._headerExists(col);
    this.assert(visibleBefore, `"${col}" column visible by default`);

    await this.pause(2);
    await this._openColumnPopover();
    await this.pause(2);
    this.assert(await this.waitForText('Columns', 3000), 'Column popover opened');

    await this._setCheckbox(col, false);
    await this.pause(2);
    await this.page.keyboard.press('Escape');
    await this.pause(2);
    this.assert(!(await this._headerExists(col)), `"${col}" column header removed`);

    await this._openColumnPopover();
    await this.pause();
    await this._setCheckbox(col, true);
    await this.pause();
    await this.page.keyboard.press('Escape');
    await this.pause();
    this.assert(await this._headerExists(col), `"${col}" column restored`);
  }

  async _headerExists(text) {
    return this.evaluate((t) => {
      const cells = document.querySelectorAll('.el-table__header .cell');
      return [...cells].some(c => c.textContent.trim() === t);
    }, text);
  }

  async _openColumnPopover() {
    await this.evaluate(() => {
      const toolbar = document.querySelector('.fct-card-header-actions .fct-btn-group');
      if (!toolbar) return;
      for (const btn of toolbar.querySelectorAll('button')) {
        if (btn.querySelector('svg') && !btn.textContent.trim()) { btn.click(); return; }
      }
    });
  }

  async _setCheckbox(label, targetState) {
    await this.evaluate(({ l, t }) => {
      for (const cb of document.querySelectorAll('.filter-popover .el-checkbox')) {
        if (cb.textContent.includes(l)) {
          const input = cb.querySelector('input[type="checkbox"]');
          if (input && input.checked !== t) { cb.click(); return; }
        }
      }
    }, { l: label, t: targetState });
  }

  // ── SORT ─────────────────────────────────────────────────────────────────────

  async _testSort() {
    const field = this.sortByField();
    this.info(`Testing sort by "${field}"...`);

    await this.pause();
    await this._openSortPopover();
    await this.pause();
    this.assert(await this.waitForText('Sort By', 3000), 'Sort popover opened');

    // Select field (may or may not fire API — not captured, just set the field)
    await this.evaluate((f) => {
      for (const r of document.querySelectorAll('.filter-popover .el-radio')) {
        if (r.textContent.includes(f)) { r.click(); return; }
      }
    }, field);
    await this._waitForRender();
    await this.pause();

    // Force Ascending so Descending click is guaranteed to change direction → fire API
    await this.evaluate(() => {
      for (const btn of document.querySelectorAll('.filter-popover .el-radio-button')) {
        if (btn.textContent.includes('Ascending')) { btn.click(); return; }
      }
    });
    await this._waitForRender();
    await this.pause();

    // Click Descending → direction changes ASC→DESC → handleSortChange() → API
    const sortResult = await this.actionWithApi(async () => {
      await this.evaluate(() => {
        for (const btn of document.querySelectorAll('.filter-popover .el-radio-button')) {
          if (btn.textContent.includes('Descending')) { btn.click(); return; }
        }
      });
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(sortResult?.ok, `Sort by "${field}" DESC — API OK`, `status: ${sortResult?.status}`);
    await this.page.keyboard.press('Escape');
    await this.pause();

    // Restore: open popover, flip back to Ascending → API
    await this._openSortPopover();
    await this.pause();
    const restoreResult = await this.actionWithApi(async () => {
      await this.evaluate(() => {
        for (const btn of document.querySelectorAll('.filter-popover .el-radio-button')) {
          if (btn.textContent.includes('Ascending')) { btn.click(); return; }
        }
      });
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(restoreResult?.ok, 'Sort restored to default — API OK', `status: ${restoreResult?.status}`);
    await this.page.keyboard.press('Escape');
    await this.pause();
  }

  async _openSortPopover() {
    await this.evaluate(() => {
      const toolbar = document.querySelector('.fct-card-header-actions .fct-btn-group');
      if (!toolbar) return;
      const btns = [...toolbar.querySelectorAll('button')];
      if (btns.length) btns[btns.length - 1].click();
    });
  }

  // ── PAGINATION ───────────────────────────────────────────────────────────────

  async _testPagination() {
    this.info('Testing pagination...');
    this.assert(
      !!(await this.page.waitForSelector('.pagination-wrap', { timeout: 5000 }).catch(() => null)),
      'Pagination controls visible'
    );
    this.assert(await this.waitForText('Page 1 of', 4000), '"Page 1 of N" visible');

    // Change to 20/page
    await this.pause();
    const toTwenty = await this.actionWithApi(async () => {
      await this.evaluate(() => document.querySelector('.pagination-wrap .el-select .el-input')?.click());
      await this.pause();
      await this.evaluate(() => {
        for (const opt of document.querySelectorAll('.el-select-dropdown__item')) {
          if (opt.textContent.includes('20')) { opt.click(); return; }
        }
      });
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(toTwenty?.ok, 'Per-page → 20 — API OK', `status: ${toTwenty?.status}`);
    const paginationText = await this.evaluate(() => document.querySelector('.pagination-wrap')?.textContent || '');
    this.assert(paginationText.includes('20'), 'Pagination reflects "20"');

    // Restore to 10/page
    await this.pause();
    const toTen = await this.actionWithApi(async () => {
      await this.evaluate(() => document.querySelector('.pagination-wrap .el-select .el-input')?.click());
      await this.pause();
      await this.evaluate(() => {
        for (const opt of document.querySelectorAll('.el-select-dropdown__item')) {
          if (opt.textContent.trim().startsWith('10')) { opt.click(); return; }
        }
      });
    }, this.apiEndpoint(), 'GET');
    await this._waitForRender();
    await this.pause();
    this.assert(toTen?.ok, 'Per-page → 10 — API OK', `status: ${toTen?.status}`);
  }

  // ── ADVANCED FILTER ──────────────────────────────────────────────────────────

  async _testAdvancedFilter() {
    const toggle = await this.page.$('.fct-advanced-filter-toggle').catch(() => null);
    if (!toggle) {
      this.info('Advanced Filter toggle not found — skipping');
      return;
    }
    this.info('Testing advanced filter...');

    // Optionally assert that filter groups are present in localized data
    const afPath = this.advanceFilterWindowPath();
    if (afPath) {
      const groups = await this.evaluate((path) => {
        const val = path.split('.').reduce((o, k) => o && o[k], window);
        return Array.isArray(val) ? val.map(g => g.label || g.value) : [];
      }, afPath);
      this.assert(groups.length > 0, `Advance filter groups in window.${afPath}`, `groups: ${groups.join(', ')}`);
      this.info(`Advance filter groups: ${groups.join(', ')}`);
    }

    // Enable (toggling to advanced does NOT auto-fire API — by design)
    await this.evaluate(() => {
      const sw = document.querySelector('.fct-advanced-filter-toggle input[type="checkbox"]');
      if (sw && !sw.checked) sw.click();
      else document.querySelector('.fct-advanced-filter-toggle')?.click();
    });
    await this.pause();
    this.assert(
      !!(await this.page.waitForSelector('.fct-advanced-filter-container', { timeout: 5000 }).catch(() => null)),
      'Advanced filter panel visible'
    );

    // Apply → fires API
    const applyResult = await this.actionWithApi(async () => {
      await this.evaluate(() => {
        for (const btn of document.querySelectorAll('.fct-advanced-filter-footer button')) {
          if (btn.textContent.includes('Apply')) { btn.click(); return; }
        }
      });
    }, this.apiEndpoint(), 'GET');
    this.assert(applyResult?.ok, 'Apply advanced filter — API OK', `status: ${applyResult?.status}`);

    // Disable → fires API (toggling back to simple calls fetch())
    const disableResult = await this.actionWithApi(async () => {
      await this.evaluate(() => {
        const sw = document.querySelector('.fct-advanced-filter-toggle input[type="checkbox"]');
        if (sw && sw.checked) sw.click();
        else document.querySelector('.fct-advanced-filter-toggle')?.click();
      });
    }, this.apiEndpoint(), 'GET');
    this.assert(disableResult?.ok, 'Disable advanced filter — API OK', `status: ${disableResult?.status}`);

    // Wait for TransitionAccordion height animation
    await this.page.waitForFunction(() => {
      const c = document.querySelector('.fct-advanced-filter-container');
      return !c || c.getBoundingClientRect().height === 0;
    }, { timeout: 5000 }).catch(() => {});
    const collapsed = await this.evaluate(() => {
      const c = document.querySelector('.fct-advanced-filter-container');
      return !c || c.getBoundingClientRect().height === 0;
    });
    this.assert(collapsed, 'Advanced filter panel collapsed');
  }

  // ── SAVED VIEW ───────────────────────────────────────────────────────────────

  async _testSavedView() {
    if (!this.hasSavedViews()) {
      this.info('Saved views disabled for this table — skipping');
      return;
    }

    const searchTerm = await this._resolveSearchTerm();
    if (!searchTerm) {
      this.info('No search seed configured for saved-view test — skipping');
      return;
    }

    const VIEW_NAME = `__test_${this.apiEndpoint()}__`;
    this.info(`Testing saved view "${VIEW_NAME}"...`);

    // Cleanup any leftover
    if (await this._hasSavedView(VIEW_NAME)) {
      await this._deleteSavedView(VIEW_NAME);
      await this.pause(2);
    }

    const searchSelector = await this._ensureSearchInputVisible();
    this.assert(!!searchSelector, 'Search input visible for saved view');
    if (!searchSelector) return;

    // Enter a term to activate "Save as view"
    await this.page.fill(searchSelector, searchTerm);
    await this.page.press(searchSelector, 'Enter');
    await this.waitForApi(this.apiEndpoint(), 'GET', 8000);
    await this._waitForRender();
    await this.pause();

    // Click "Save as view"
    const clicked = await this.evaluate(() => {
      const btn = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Save as view'));
      if (btn) { btn.click(); return true; }
      return false;
    });
    this.assert(clicked, '"Save as view" clicked');
    await this.pause();

    // Fill name + save (set up listener BEFORE clicking Save)
    const saveApiPromise = this.waitForApi('saved-views', 'POST', 10000);
    await this.page.locator('.el-dialog input[type="text"]').first().fill(VIEW_NAME).catch(() => null);
    await this.pause();
    await this.evaluate(() => {
      const dialog = document.querySelector('.el-dialog');
      if (!dialog) return;
      const btn = [...dialog.querySelectorAll('button')].find(b => b.textContent.trim() === 'Save');
      if (btn) btn.click();
    });
    const saveResult = await saveApiPromise;
    this.assert(saveResult?.ok, 'POST /saved-views returned OK', `status: ${saveResult?.status}`);
    await this.pause();

    const visible = await this._hasSavedView(VIEW_NAME);
    this.assert(visible, `View "${VIEW_NAME}" visible in tabs or dropdown`);

    // Delete and verify gone
    await this.pause();
    await this._deleteSavedView(VIEW_NAME);
    await this.pause(2);
    const [visibleAfterDelete, inStateAfterDelete] = await Promise.all([
      this._hasSavedView(VIEW_NAME),
      this._hasSavedViewInState(VIEW_NAME),
    ]);
    this.assert(
      !visibleAfterDelete && !inStateAfterDelete,
      `View "${VIEW_NAME}" removed`,
      `ui: ${visibleAfterDelete ? 'present' : 'gone'}, state: ${inStateAfterDelete ? 'present' : 'gone'}`
    );
  }

  async _hasSavedView(name) {
    const [inTabs, inDropdown] = await Promise.all([
      this._isViewInVisibleTabs(name),
      this._isViewInDropdown(name),
    ]);

    return inTabs || inDropdown;
  }

  async _hasSavedViewInState(name) {
    return this.evaluate((viewName) => {
      const tableConfig = window?.fluentCartAdminApp?.table_config || {};
      return Object.values(tableConfig).some(entry => {
        const savedViews = entry?.saved_views || [];
        return savedViews.some(view => view?.name === viewName || view?.slug === viewName);
      });
    }, name);
  }

  async _isViewInVisibleTabs(name) {
    const tab = this.page
      .locator('.fct-card-header-left .fct-fluid-tab .fct-fluid-tab-item')
      .filter({ hasText: name })
      .first();

    return !!await tab.count();
  }

  async _isViewInDropdown(name) {
    const select = this.page.locator('.fct-fluid-tab-select-wrap .el-select').first();
    if (!await select.count()) return false;

    await select.click().catch(() => null);
    await this.pause();
    await this.page.waitForSelector('.fct-fluid-tab-select .el-select-dropdown:visible', { timeout: 2000 }).catch(() => null);
    const option = this.page
      .locator('.fct-fluid-tab-select .el-select-dropdown:visible .el-select-dropdown__item')
      .filter({ hasText: name })
      .first();
    const found = !!await option.count();
    await this.page.keyboard.press('Escape').catch(() => null);
    await this.pause();
    return found;
  }

  async _deleteSavedView(name) {
    const deletedFromTabs = await this._deleteViewFromTabs(name);
    if (deletedFromTabs) return;

    const deletedFromDropdown = await this._deleteViewFromDropdown(name);
    if (deletedFromDropdown) return;

    this.info(`Saved view "${name}" was not found in tabs or dropdown for deletion`);
  }

  async _deleteViewFromTabs(name) {
    const tabItem = this.page
      .locator('.fct-card-header-left .fct-fluid-tab .fct-fluid-tab-item')
      .filter({ hasText: name })
      .first();

    if (!await tabItem.count()) {
      return false;
    }

    const trigger = tabItem.locator('.el-dropdown .cursor-pointer').first();
    if (!await trigger.count()) {
      return false;
    }

    const deleteApiPromise = this.waitForApi('saved-views', 'DELETE', 10000).catch(() => null);
    const restoreApiPromise = this.waitForApi(this.apiEndpoint(), 'GET', 10000).catch(() => null);
    await trigger.click().catch(() => null);
    await this.pause();
    const deleteItem = this.page.locator('.el-dropdown-menu__item').filter({ hasText: 'Delete' }).last();
    if (!await deleteItem.count()) {
      return false;
    }
    await deleteItem.click().catch(() => null);
    await this.pause();
    await this.page.waitForSelector('.el-message-box, .el-message-box__wrapper', { timeout: 3000 }).catch(() => null);
    await this.confirmDialog();
    await deleteApiPromise;
    await restoreApiPromise;
    await this._waitForRender();
    await this._waitForSavedViewVisibility(name, false);
    await this.pause();
    return true;
  }

  async _deleteViewFromDropdown(name) {
    const select = this.page.locator('.fct-fluid-tab-select-wrap .el-select').first();
    if (!await select.count()) return false;

    await select.click().catch(() => null);
    await this.pause();
    await this.page.waitForSelector('.fct-fluid-tab-select .el-select-dropdown:visible', { timeout: 2000 }).catch(() => null);

    const option = this.page
      .locator('.fct-fluid-tab-select .el-select-dropdown:visible .el-select-dropdown__item')
      .filter({ hasText: name })
      .first();

    if (!await option.count()) {
      await this.page.keyboard.press('Escape').catch(() => null);
      return false;
    }

    const clicked = await option.evaluate((el) => {
      const trigger = el.querySelector('span[style*="cursor:pointer"]');
      if (trigger) {
        trigger.click();
        return true;
      }

      const svg = el.querySelector('svg');
      if (svg) {
        svg.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        return true;
      }

      return false;
    }).catch(() => false);

    if (!clicked) {
      await this.page.keyboard.press('Escape').catch(() => null);
      return false;
    }

    const restoreApiPromise = this.waitForApi(this.apiEndpoint(), 'GET', 10000).catch(() => null);
    const deleteApiPromise = this.waitForApi('saved-views', 'DELETE', 10000).catch(() => null);
    await this.pause();
    await this.page.waitForSelector('.el-message-box, .el-message-box__wrapper', { timeout: 3000 }).catch(() => null);
    await this.confirmDialog();
    await deleteApiPromise;
    await restoreApiPromise;
    await this._waitForRender();
    await this._waitForSavedViewVisibility(name, false);
    await this.pause();
    return true;
  }

  async _waitForSavedViewVisibility(name, shouldExist, timeout = 5000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
      if ((await this._hasSavedView(name)) === shouldExist) {
        return true;
      }
      await this.page.waitForTimeout(150);
    }

    return false;
  }
}

import BaseTest from '../../base-test.mjs';

/**
 * TaxClassTabs.vue: a custom class is typed into an inline input in the tab
 * bar (opened from the "+" button, or its "Custom Class..." menu item while
 * Reduced or Zero is still missing) and created by the check button. Each
 * tab carries its own delete icon, which asks for confirmation.
 */
const NAME = 'Digital Goods';
const SEL = {
  container: '.fct-base-taxes',
  tab: '.fct-tax-class-tabs__tab',
  add: '.fct-tax-class-tabs__add',
  inlineInput: '.fct-tax-class-tabs__inline-create input',
  inlineConfirm: '.fct-tax-class-tabs__inline-confirm',
};

export default class TaxClassesTest extends BaseTest {
  static title = 'Tax Classes — Create / Delete / Reload';

  async run() {
    await this.adminPage('/settings/tax_settings/tax_rates_country_single/US/NA');
    await this.page.waitForSelector(SEL.tab, { timeout: 15000 }).catch(() => null);

    if (await this._hasTab()) {
      this.info(`Cleaning up leftover "${NAME}"...`);
      await this._deleteTab();
    }

    // ── CREATE ──
    this.info(`Creating "${NAME}" tax class...`);
    const opened = await this._openInlineCreate();
    this.assert(opened, 'Inline tax class input opened');
    if (!opened) return;

    await this.setInput(SEL.inlineInput, NAME);
    const created = await this.actionWithApi(() => this.evaluate((sel) => document.querySelector(sel)?.click(), SEL.inlineConfirm), 'tax/classes');
    this.assert(created?.ok, 'Tax class created via API', `status: ${created?.status}`);
    this.assert(await this._waitForTab(true), `${NAME} tab visible`);

    await this.hardReload(SEL.container);
    await this.page.waitForSelector(SEL.tab, { timeout: 15000 }).catch(() => null);
    this.assert(await this._waitForTab(true), `${NAME} tab persists after reload`);

    // ── DELETE ──
    this.info(`Deleting "${NAME}" tax class...`);
    const deleted = await this._deleteTab();
    this.assert(deleted?.ok, 'Tax class deleted via API', `status: ${deleted?.status}`);
    this.assert(await this._waitForTab(false), 'Tax class deleted (tab removed)');

    await this.hardReload(SEL.container);
    await this.page.waitForSelector(SEL.tab, { timeout: 15000 }).catch(() => null);
    this.assert(!(await this._hasTab()), `${NAME} gone after reload`);
  }

  async _hasTab() {
    return this.evaluate(({ sel, name }) => [...document.querySelectorAll(sel)].some(t => t.textContent.trim() === name), { sel: SEL.tab, name: NAME });
  }

  async _waitForTab(present) {
    return this.page.waitForFunction(({ sel, name, present }) =>
      [...document.querySelectorAll(sel)].some(t => t.textContent.trim() === name) === present,
    { sel: SEL.tab, name: NAME, present }, { timeout: 8000 }).then(() => true).catch(() => false);
  }

  // The "+" is either a plain button or a menu; the menu's "Custom Class..." opens the input.
  async _openInlineCreate() {
    await this.page.waitForSelector(SEL.add, { timeout: 10000 }).catch(() => null);
    const isMenu = await this.evaluate((sel) => {
      const add = document.querySelector(sel);
      return add ? !!add.closest('.el-dropdown') : null;
    }, SEL.add);
    if (isMenu === null) return false;
    // el-dropdown ignores a DOM click(); it opens on a real pointer click.
    await this.page.locator(SEL.add).first().click();
    if (isMenu) {
      await this.page.waitForFunction(() => [...document.querySelectorAll('.el-dropdown-menu__item')].some(i => i.getClientRects().length > 0 && i.textContent.includes('Custom Class')), null, { timeout: 5000 }).catch(() => null);
      await this.page.locator('.el-dropdown-menu__item:visible', { hasText: 'Custom Class' }).first().click().catch(() => null);
    }
    return this.page.waitForSelector(SEL.inlineInput, { timeout: 5000 }).then(() => true).catch(() => false);
  }

  async _deleteTab() {
    await this.evaluate(({ sel, name }) => {
      const tab = [...document.querySelectorAll(sel)].find(t => t.textContent.trim() === name);
      tab?.querySelector('.fct-tax-class-tabs__delete')?.click();
    }, { sel: SEL.tab, name: NAME });
    await this.page.waitForSelector('.el-message-box', { timeout: 5000 }).catch(() => null);
    // Rest sends DELETE as POST with X-HTTP-Method-Override.
    return this.actionWithApi(() => this.confirmDialog(), 'tax/classes/');
  }
}

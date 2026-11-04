import BaseTest from '../../base-test.mjs';

/**
 * BEM selectors from EUVatSettings.vue and EUVatCrossBorderCard.vue:
 *
 *  .fct-vat-card                        — each section card
 *  .fct-oss-rates-section               — OSS rates table section
 *  .fct-oss-rates-toggle                — collapsible toggle for OSS rates
 *  .fct-tax-rates-country-view          — overrides table container
 */

export default class EuVatTest extends BaseTest {
  static title = 'EU VAT — Cross-Border';

  async run() {
    await this.adminPage('/settings/tax_settings/eu');
    await this.page.waitForSelector('.fct-vat-card, .setting-wrap-inner', { timeout: 10000 }).catch(() => null);

    const text = await this.bodyText();

    // ─── Section visibility ──────────────────────────────────────────

    this.assert(
      text.includes('Collect VAT cross-border') || text.includes('Cross-border'),
      'Cross-border section visible'
    );
    this.assert(
      text.includes('Tax overrides') || text.includes('override'),
      'Tax overrides section visible'
    );

    // ─── Cross-border (Collect VAT on EU row) ────────────────────────

    await this.testCrossBorder();
  }

  // ── Cross-border section ────────────────────────────────────────────

  async testCrossBorder() {
    this.info('--- Cross-border VAT collection ---');

    // Reload to get clean state
    await this.adminPage('/settings/tax_settings/eu');
    await this.page.waitForSelector('.fct-vat-card, .setting-wrap-inner', { timeout: 10000 }).catch(() => null);

    // Unconfigured, the card offers "Configure". Configured, its menu offers "Edit registration".
    await this.page.waitForFunction(() => [...document.querySelectorAll('.fct-vat-card')].some(c => c.querySelector('h3')?.textContent.includes('Collect VAT cross-border')
      && (c.querySelector('.el-dropdown') || [...c.querySelectorAll('button')].some(b => b.textContent.trim() === 'Configure'))), null, { timeout: 10000 }).catch(() => null);
    const opened = await this.evaluate(() => {
      const card = [...document.querySelectorAll('.fct-vat-card')].find(c => c.querySelector('h3')?.textContent.includes('Collect VAT cross-border'));
      if (!card) return 'no-card';
      const collect = [...card.querySelectorAll('button')].find(b => b.textContent.trim() === 'Configure');
      if (collect) { collect.click(); return 'collect'; }
      const menu = card.querySelector('.el-dropdown button, .el-dropdown [role=button], .el-dropdown .el-tooltip__trigger') || card.querySelector('.el-dropdown')?.firstElementChild;
      if (menu) { menu.click(); return 'menu'; }
      return 'no-button';
    });
    if (opened === 'menu') {
      await this.page.waitForFunction(() => [...document.querySelectorAll('.el-dropdown-menu__item')].some(i => i.getClientRects().length > 0 && i.textContent.includes('Edit registration')), null, { timeout: 5000 }).catch(() => null);
      await this.evaluate(() => [...document.querySelectorAll('.el-dropdown-menu__item')].find(i => i.getClientRects().length > 0 && i.textContent.includes('Edit registration'))?.click());
    }
    this.assert(opened === 'collect' || opened === 'menu', 'Cross-border collection opened from its card', opened);
    if (opened !== 'collect' && opened !== 'menu') return;

    // Wait for dialog
    const dialogOpen = await this.page.waitForFunction(() => {
      return [...document.querySelectorAll('.el-overlay')].some(
        o => o.style.display !== 'none' && o.textContent.includes('Collect across')
      );
    }, { timeout: 5000 }).then(() => true).catch(() => false);
    this.assert(dialogOpen, '"Collect across the EU" dialog opened');
    if (!dialogOpen) return;

    await this.pause();

    // Verify OSS and Home radio options exist
    const options = await this.evaluate(() => {
      const text = document.body.textContent;
      return {
        oss: text.includes('One Stop Shop') || text.includes('OSS'),
        home: text.includes('home country registration')
      };
    });
    this.assert(options.oss, 'OSS radio option visible');
    this.assert(options.home, 'Home country radio option visible');

    // OSS should be selected by default — verify fields are visible
    const ossFieldsVisible = await this.evaluate(() => {
      const text = document.body.textContent;
      return text.includes('Country of OSS registration') && text.includes('VAT number');
    });
    this.assert(ossFieldsVisible, 'OSS fields visible (country + VAT number)');

    // Select Germany as OSS country
    this.info('Selecting Germany as OSS country...');
    await this.evaluate(() => {
      const overlays = [...document.querySelectorAll('.el-overlay')].filter(o => o.style.display !== 'none');
      for (const overlay of overlays) {
        const select = overlay.querySelector('.fct-radio-option-fields .el-select input');
        if (select) { select.click(); return; }
      }
    });
    await this.pause(2);

    const germanySelected = await this.evaluate(async () => {
      // Type Germany to filter
      const overlays = [...document.querySelectorAll('.el-overlay')].filter(o => o.style.display !== 'none');
      for (const overlay of overlays) {
        const input = overlay.querySelector('.fct-radio-option-fields .el-select input');
        if (input) {
          const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
          set.call(input, 'Germany');
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }
      await new Promise(r => setTimeout(r, 500));

      const poppers = document.querySelectorAll('.el-popper, .el-select-dropdown');
      for (const popper of poppers) {
        if (window.getComputedStyle(popper).display === 'none') continue;
        const items = popper.querySelectorAll('.el-select-dropdown__item');
        for (const item of items) {
          if (item.textContent.trim().includes('Germany')) { item.click(); return 'Germany'; }
        }
      }
      return false;
    });
    this.assert(!!germanySelected, 'Germany selected as OSS country');

    // Fill VAT number
    this.info('Filling OSS VAT number...');
    await this.evaluate(() => {
      const overlays = [...document.querySelectorAll('.el-overlay')].filter(o => o.style.display !== 'none');
      for (const overlay of overlays) {
        const fields = overlay.querySelectorAll('.fct-radio-option-fields .el-form-item');
        for (const field of fields) {
          const label = field.querySelector('label')?.textContent?.trim()?.toLowerCase() || '';
          if (label.includes('vat number')) {
            const input = field.querySelector('input');
            if (input) {
              const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
              set.call(input, 'DE999888777');
              input.dispatchEvent(new Event('input', { bubbles: true }));
            }
          }
        }
      }
    });
    await this.pause();

    // Save — click "Collect VAT" in the dialog footer
    const saveResult = await this.actionWithApi(
      () => this.evaluate(() => {
        const overlays = [...document.querySelectorAll('.el-overlay')].filter(o => o.style.display !== 'none');
        for (const overlay of overlays) {
          const footer = overlay.querySelector('.el-dialog__footer');
          if (!footer) continue;
          const btn = [...footer.querySelectorAll('button')].find(b => b.classList.contains('el-button--primary'));
          if (btn) { btn.click(); return true; }
        }
        return false;
      }),
      'eu-vat'
    );
    this.assert(saveResult?.ok, 'Cross-border OSS saved via API', `status: ${saveResult?.status || 'no response'}`);

    // Verify OSS summary view appears
    await this.pause(3);
    const ossSummary = await this.evaluate(() => {
      const text = document.body.textContent;
      return {
        hasOssLabel: text.includes('EU (OSS)') || text.includes('OSS'),
        hasCollecting: text.includes('Collecting in') || text.includes('Collecting'),
        hasDestinationRates: text.includes('Destination country rates') || text.includes('destination'),
      };
    });
    this.assert(ossSummary.hasOssLabel, 'OSS label visible in summary');
    this.assert(ossSummary.hasCollecting, '"Collecting in X regions" badge visible');
    this.assert(ossSummary.hasDestinationRates, 'Destination country rates section visible');

    // Hard reload and verify
    await this.hardReload('.fct-vat-card, .setting-wrap-inner');
    const persistsReload = await this.waitForText('OSS', 8000)
      || await this.waitForText('Collecting', 3000);
    this.assert(persistsReload, 'Cross-border settings persist after reload');

    await this.screenshot('eu-vat-cross-border-saved');

    // ── Test "Edit registration" from dropdown menu ──
    this.info('Testing Edit registration dropdown...');

    // Wait for the OSS summary to fully render (icon-button dropdown needs this)
    await this.waitForText('EU (OSS)', 8000);
    await this.page.waitForSelector('.el-dropdown button.icon-button', { timeout: 5000 }).catch(() => null);
    await this.pause();

    // Click the "..." (more) dropdown button
    const moreClicked = await this.evaluate(() => {
      const btn = document.querySelector('.el-dropdown button.icon-button');
      if (btn) { btn.click(); return true; }
      return false;
    });
    await this.pause();

    if (moreClicked) {
      // Verify dropdown has "Edit registration" and "Stop collecting"
      const dropdownItems = await this.evaluate(() => {
        const items = document.querySelectorAll('.el-dropdown-menu__item, [role=menuitem]');
        return [...items].map(i => i.textContent.trim()).filter(t => t.length > 0 && t.length < 50);
      });
      this.assert(
        dropdownItems.some(i => i.includes('Edit registration') || i.includes('Edit')),
        '"Edit registration" option in dropdown'
      );
      this.assert(
        dropdownItems.some(i => i.includes('Stop collecting') || i.includes('Stop')),
        '"Stop collecting" option in dropdown'
      );

      // Click "Edit registration" via Playwright locator (el-dropdown commands need real clicks)
      const editItem = await this.page.$('.el-dropdown-menu__item >> text=Edit registration').catch(() => null)
        || await this.page.$('[role=menuitem]:has-text("Edit")').catch(() => null);
      const editClicked = !!editItem;
      if (editItem) await editItem.click();
      await this.pause(2);

      if (editClicked) {
        const editDialogOpen = await this.page.waitForFunction(() => {
          return [...document.querySelectorAll('.el-overlay')].some(
            o => o.style.display !== 'none' && o.querySelector('.el-dialog') && o.textContent.includes('Collect across')
          );
        }, { timeout: 8000 }).then(() => true).catch(() => false);

        this.assert(editDialogOpen, 'Edit opens cross-border dialog');

        if (editDialogOpen) {
          // Verify Germany and VAT are pre-filled
          const prefilled = await this.evaluate(() => {
            const overlay = [...document.querySelectorAll('.el-overlay')].find(o => o.style.display !== 'none');
            const text = overlay?.textContent || '';
            return text.includes('Germany') || text.includes('DE999888777');
          });
          this.assert(prefilled, 'Edit dialog pre-filled with saved data');
          await this.closeDialog();
        }
      }
    } else {
      this.info('More dropdown button not found — may have different UI structure');
    }

    // ── Test "Stop collecting" ──
    this.info('Testing Stop collecting...');

    // Open dropdown and click "Stop collecting"
    // Note: el-dropdown requires real pointer events — works best in headed mode
    await this.page.waitForSelector('.el-dropdown button.icon-button', { timeout: 5000 }).catch(() => null);

    // Use Playwright click (not evaluate) for proper Vue event handling
    const iconBtn2 = await this.page.$('.el-dropdown button.icon-button');
    let stopClicked = false;
    if (iconBtn2) {
      await iconBtn2.click();
      await this.page.waitForTimeout(800);

      const stopItem = await this.page.locator('text=Stop collecting').first().elementHandle({ timeout: 3000 }).catch(() => null);
      if (stopItem) {
        await stopItem.click();
        stopClicked = true;
        await this.pause(2);
      } else {
        // Fallback via evaluate for headless
        stopClicked = await this.evaluate(() => {
          const items = document.querySelectorAll('.el-dropdown-menu__item');
          for (const item of items) {
            if (item.textContent.includes('Stop')) { item.click(); return true; }
          }
          return false;
        });
        await this.pause(2);
      }
    }

    this.assert(stopClicked, '"Stop collecting" clicked');
    if (!stopClicked) return;

    // Wait for confirm dialog
    await this.page.waitForTimeout(800);
    const hasConfirmBox = await this.page.$('.el-message-box').catch(() => null);
    this.assert(!!hasConfirmBox, 'Stop collecting asks for confirmation');
    if (!hasConfirmBox) return;

    // Click "Yes, Stop"
    const yesBtn = await this.page.$('.el-message-box__btns .el-button--primary').catch(() => null);
    if (yesBtn) await yesBtn.click();
    else await this.confirmDialog();

    await this.pause(2);

    // Verify OSS summary is gone
    // Read the card only: the closed modal stays in the DOM and still says "EU (OSS)".
    const notCollecting = () => [...document.querySelectorAll('.fct-vat-card')]
      .find(c => c.querySelector('h3')?.textContent.includes('Collect VAT cross-border'))
      ?.textContent.includes('Not collecting cross-border VAT');
    const stopped = await this.page.waitForFunction(notCollecting, null, { timeout: 8000 }).then(() => true).catch(() => false);

    if (stopped) {
      this.assert(true, 'Cross-border collection stopped');
      await this.hardReload('.fct-vat-card, .setting-wrap-inner');
      const stoppedReload = await this.page.waitForFunction(notCollecting, null, { timeout: 8000 }).then(() => true).catch(() => false);
      this.assert(stoppedReload, 'Stop collecting persists after reload');
    } else {
      this.assert(false, 'Cross-border collection stopped', 'card still collecting 8s after confirming');
    }
  }
}

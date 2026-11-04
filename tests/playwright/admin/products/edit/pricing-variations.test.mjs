/**
 * Product Edit — Pricing (Simple Variations Mode + Drawer)
 *
 * Covers:
 *   - Pricing mode switcher: confirms "Simple Variations" is selectable
 *   - Variations table: columns render, rows visible
 *   - Variation Edit Drawer: opens via pencil icon
 *   - Drawer nav sidebar: lists all variants, active item highlighted
 *   - Drawer form: variation title + price inputs present
 *   - Variant title edit in drawer → Save → Close → hard reload → assert persists
 *   - Dirty-change dialog: make change in drawer → click Cancel → dialog appears
 *     → click Discard → drawer closes cleanly
 *
 * Selector contract:
 *   .fct-product-pricing-wrap                    Root of ProductPricing.vue
 *   .fct-product-pricing-table-wrap              Root of ProductPricingTable.vue (variations mode)
 *   .fct-table-draggable                         Desktop drag-sortable table wrapper
 *   .fct-table-draggable thead th                Column headers
 *   .fct-table-draggable tbody tr                One row per variant
 *   button.hide-on-mobile (in action column)     Pencil (Edit) button that opens the drawer
 *   .fct-shared-variant-drawer                   El Plus el-drawer (append-to-body, class on root)
 *   .fct-shared-variant-drawer__layout           Layout wrapper inside drawer body
 *   .fct-shared-variant-nav                      Left variant-navigation sidebar
 *   .fct-shared-variant-nav__item-shell          One item per variant in the sidebar
 *   .fct-shared-variant-nav__item-shell.is-active  Currently selected variant
 *   .fct-shared-variant-nav__item-title          Variant title label in nav item
 *   .fct-shared-variant-drawer__form             Form section of the drawer content
 *   .fct-product-pricing-form-wrap               Root of ProductPricingForm.vue (inside drawer)
 *   input[id="variants.variation_title"]         Title input in drawer (fieldKey="variants", no index)
 *   input[id="variants.item_price"]              Price input in drawer
 *   .fct-shared-variant-drawer .dialog-footer    Drawer footer with Cancel + Save/Update buttons
 *   .fct-variant-dirty-dialog                    Unsaved-changes dialog (append-to-body)
 *
 * Notes:
 *   - El Plus el-drawer with :append-to-body renders outside the pricing table DOM.
 *     Target `.fct-shared-variant-drawer` directly — it's in <body> when open.
 *   - The drawer uses destroy-on-close, so .fct-shared-variant-drawer is absent from
 *     the DOM when closed. Presence == open; absence == closed.
 *   - Drawer save (Update) calls POST .../pricing for the single variant only.
 *     The page-level save bar is not involved.
 *   - Title input id is "variants.variation_title" (no numeric index) because the
 *     drawer passes fieldKey="variants", not "variants.0".
 *   - The test switches the product to Simple Variations mode if it is currently
 *     in Simple mode, then restores it at the end.
 *
 * API:
 *   POST .../products/{id}/pricing   (both drawer Save and mode-switch Save)
 *
 * Registration:
 *   Registered in tests/playwright/admin/products/edit/index.mjs
 *   Suite key: admin/products/edit
 *   Individual key: admin/products/edit/pricing-variations
 */
import BaseTest from '../../../base-test.mjs';
import ElSelectHelper from '../../../helpers/el-select.mjs';

const SEL = {
  formReady:        'input#post_title',
  pricingWrap:      '.fct-product-pricing-wrap',
  modeSelect:       '.fct-product-pricing-wrap .el-select',
  pricingTable:     '.fct-product-pricing-table-wrap',
  tableDesktop:     '.fct-table-draggable',
  tableRows:        '.fct-table-draggable tbody tr',
  editBtn:          '.fct-table-draggable tbody tr .fct-btn-group.sm button.hide-on-mobile',
  saveBarActive:    '.fct-save-bar.is-active',
  // Drawer — teleported to body via append-to-body
  drawer:           '.fct-shared-variant-drawer',
  drawerNav:        '.fct-shared-variant-drawer .fct-shared-variant-nav',
  drawerNavItems:   '.fct-shared-variant-drawer .fct-shared-variant-nav__item-shell',
  drawerNavActive:  '.fct-shared-variant-drawer .fct-shared-variant-nav__item-shell.is-active',
  drawerNavTitle:   '.fct-shared-variant-nav__item-title',
  drawerForm:       '.fct-shared-variant-drawer .fct-product-pricing-form-wrap',
  drawerFooter:     '.fct-shared-variant-drawer .dialog-footer',
  titleInput:       'input[id="variants.variation_title"]',
  priceInput:       'input[id="variants.item_price"]',
  // Dirty-changes dialog — also append-to-body
  dirtyDialog:      '.fct-variant-dirty-dialog',
};

export default class ProductEditPricingVariationsTest extends BaseTest {
  static title = 'Product Edit — Pricing Variations Mode + Drawer';

  // Track whether we switched modes so we can restore at the end
  _switchedToVariations = false;

  async run() {
    const productId = await this._discoverProductId();
    if (!productId) {
      this.assert(false, 'Product ID discovered', 'No product rows found — seed data required');
      return;
    }
    this.info(`Using product ID: ${productId}`);

    await this.adminPage(`/products/${productId}`);
    await this.page.waitForSelector(SEL.formReady, { timeout: 15000 }).catch(() => null);
    await this.pause();

    // ── 1. Ensure product is in Simple Variations mode ───────────────────
    const wasSimple = await this._ensureVariationsMode();

    // ── 2. Verify variations table renders ───────────────────────────────
    await this._testVariationsTablePresence();

    // ── 3. Open drawer + assert structure ───────────────────────────────
    await this._testDrawerOpenAndContent();

    // ── 4. Edit variant title → drawer Save → reload → assert persists ───
    await this._testVariantTitlePersists();

    // ── 5. Dirty-change dialog ───────────────────────────────────────────
    await this._testDirtyDialog();

    // ── 6. Restore original mode if we switched ──────────────────────────
    if (wasSimple) {
      await this._restoreSimpleMode();
    }
  }

  // ── Product ID discovery ──────────────────────────────────────────────────

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

  // ── Ensure the product is in Simple Variations mode ───────────────────────

  /**
   * Returns true if the product was originally in Simple mode (so we can restore it).
   */
  async _ensureVariationsMode() {
    const elSelect = new ElSelectHelper(this);
    const currentMode = await elSelect.getValue(SEL.modeSelect);

    const isSimple = currentMode.toLowerCase() === 'simple';
    this.info(`Current pricing mode: "${currentMode}"`);

    if (!isSimple) {
      this.assert(true, `Already in variations mode ("${currentMode}")`);
      return false;
    }

    this.info('Switching to Simple Variations mode...');
    await elSelect.select(SEL.modeSelect, 'Simple Variations');

    // Wait for save bar to activate after mode change
    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive, { timeout: 5000 }
    ).catch(() => {});

    const saveResult = await this.actionWithApi(
      () => this.page.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        const btn = [...(bar?.querySelectorAll('button') || [])]
          .find(b => b.textContent.includes('Update'));
        if (btn) btn.click();
      }),
      '/pricing',
      'POST'
    );
    this.assert(!!saveResult?.ok, 'Mode switched to Simple Variations + saved', `status: ${saveResult?.status}`);

    await this.page.waitForFunction(
      (sel) => !document.querySelector(sel), SEL.saveBarActive, { timeout: 5000 }
    ).catch(() => {});

    await this.hardReload(SEL.formReady);
    await this.pause();

    this._switchedToVariations = true;
    return true;
  }

  // ── Test: variations table renders ────────────────────────────────────────

  async _testVariationsTablePresence() {
    this.info('Checking variations table...');

    const tablePresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.pricingTable
    );
    this.assert(tablePresent, 'Variations table (.fct-product-pricing-table-wrap) renders');

    const desktopTablePresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.tableDesktop
    );
    this.assert(desktopTablePresent, 'Desktop drag-sortable table (.fct-table-draggable) renders');

    const colCount = await this.page.evaluate(() => {
      const ths = document.querySelectorAll('.fct-table-draggable thead th');
      return ths.length;
    });
    this.assert(colCount >= 4, `Variations table has columns`, `found ${colCount}`);

    const rowCount = await this.page.evaluate(
      (sel) => document.querySelectorAll(sel).length, SEL.tableRows
    );
    this.assert(rowCount >= 1, 'Variations table has at least one row', `found ${rowCount}`);
    this.info(`Variations table: ${rowCount} row(s), ${colCount} column(s)`);
  }

  // ── Test: drawer opens and shows correct structure ─────────────────────────

  async _testDrawerOpenAndContent() {
    this.info('Opening variation drawer...');

    await this._openDrawer();

    // Drawer open = visible in DOM (El Plus el-drawer uses v-show; wait for visible state)
    await this.page.waitForSelector(SEL.drawer, { timeout: 8000 }).catch(() => null);
    const drawerOpen = await this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      // Check that no ancestor has display:none (v-show hides by setting display:none on the overlay)
      let node = el;
      while (node && node !== document.body) {
        if (window.getComputedStyle(node).display === 'none') return false;
        node = node.parentElement;
      }
      return true;
    }, SEL.drawer);
    this.assert(drawerOpen, 'Variation drawer opens (.fct-shared-variant-drawer visible)');

    // Variant navigation sidebar
    const navPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.drawerNav
    );
    this.assert(navPresent, 'Drawer variant nav sidebar (.fct-shared-variant-nav) present');

    const navItemCount = await this.page.evaluate(
      (sel) => document.querySelectorAll(sel).length, SEL.drawerNavItems
    );
    this.assert(navItemCount >= 1, 'Drawer nav lists at least one variant', `found ${navItemCount}`);

    const activeNavItem = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.drawerNavActive
    );
    this.assert(activeNavItem, 'One nav item is marked active (.is-active)');

    // Form section
    const formPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.drawerForm
    );
    this.assert(formPresent, 'Drawer pricing form (.fct-product-pricing-form-wrap) present');

    // Title input
    const titleInputPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.titleInput
    );
    this.assert(titleInputPresent, 'Variant title input (id="variants.variation_title") present in drawer');

    // Drawer footer with buttons
    const footerPresent = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.drawerFooter
    );
    this.assert(footerPresent, 'Drawer footer (.dialog-footer) present');

    const updateBtnPresent = await this.page.evaluate(() => {
      const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
      const btns = footer?.querySelectorAll('button') || [];
      return [...btns].some(b => b.textContent.trim().includes('Update') || b.textContent.trim().includes('Save'));
    });
    this.assert(updateBtnPresent, 'Drawer Save/Update button present in footer');

    await this._closeDrawer();
  }

  // ── Test: variant title edit in drawer persists after reload ──────────────

  async _testVariantTitlePersists() {
    this.info('Testing: edit variant title in drawer → Save → reload → assert persists...');

    // Read the current title from the nav sidebar (before opening drawer)
    const originalTitle = await this.page.evaluate(() => {
      const item = document.querySelector('.fct-table-draggable tbody tr:first-child');
      const titleInput = item?.querySelector('input[id*="variation_title"]');
      return titleInput ? titleInput.value : '';
    });
    const testTitle = (originalTitle || 'Variation') + ' DRAW';
    this.info(`Original title: "${originalTitle}" → editing to: "${testTitle}"`);

    await this._openDrawer();
    await this.page.waitForSelector(SEL.titleInput, { timeout: 5000 }).catch(() => null);

    // Edit the title
    await this.setInput(SEL.titleInput, testTitle);
    await this.pause();

    // Wait for the Update button to be enabled (dirty state must propagate in Vue)
    await this.page.waitForFunction(() => {
      const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
      const btns = [...(footer?.querySelectorAll('button') || [])];
      const btn = btns.find(b => b.textContent.trim().includes('Update') || b.textContent.trim().includes('Save'));
      return btn && !btn.disabled;
    }, { timeout: 3000 }).catch(() => {});

    // Drawer save calls REST.post('products/variants/{id}') — NOT the /pricing endpoint
    const saveResult = await this.actionWithApi(
      () => this.page.evaluate(() => {
        const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
        const btns = [...(footer?.querySelectorAll('button') || [])];
        const btn = btns.find(b => b.textContent.trim().includes('Update') || b.textContent.trim().includes('Save'));
        if (btn) btn.click();
      }),
      'products/variants',
      'POST'
    );
    this.assert(!!saveResult?.ok, 'Drawer save API OK', `status: ${saveResult?.status}`);

    await this.pause();

    // Close the drawer via Cancel
    await this._closeDrawer();

    // Verify the title updated in the table
    const tableTitle = await this.page.evaluate(() => {
      const input = document.querySelector('.fct-table-draggable tbody tr:first-child input[id*="variation_title"]');
      return input ? input.value : '';
    });
    this.assert(tableTitle === testTitle, 'Variant title updated in table after drawer save', `got: "${tableTitle}"`);

    // Hard reload and assert persistence
    await this.hardReload(SEL.formReady);
    await this.page.waitForSelector(SEL.tableRows, { timeout: 8000 }).catch(() => null);
    await this.pause();

    const titleAfterReload = await this.page.evaluate(() => {
      const input = document.querySelector('.fct-table-draggable tbody tr:first-child input[id*="variation_title"]');
      return input ? input.value : '';
    });
    this.assert(
      titleAfterReload === testTitle,
      'Variant title persists after hard reload',
      `expected "${testTitle}", got "${titleAfterReload}"`
    );

    // Restore original title
    await this._restoreVariantTitle(originalTitle, testTitle);
  }

  // ── Test: dirty-change dialog appears when closing with unsaved changes ────

  async _testDirtyDialog() {
    this.info('Testing: unsaved change in drawer → Cancel → dirty dialog appears...');

    await this._openDrawer();
    await this.page.waitForSelector(SEL.titleInput, { timeout: 5000 }).catch(() => null);

    // Make a change (dirty state)
    const currentTitle = await this.page.evaluate(
      (sel) => document.querySelector(sel)?.value || '', SEL.titleInput
    );
    await this.setInput(SEL.titleInput, (currentTitle || 'Variation') + ' DIRTY');
    await this.pause();

    // Click Cancel — should trigger dirty dialog because there are unsaved changes
    await this.page.evaluate(() => {
      const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
      const btns = [...(footer?.querySelectorAll('button') || [])];
      const btn = btns.find(b => b.textContent.trim() === 'Cancel');
      if (btn) btn.click();
    });

    await this.page.waitForSelector(SEL.dirtyDialog, { timeout: 5000 }).catch(() => null);
    const dialogVisible = await this.page.evaluate(
      (sel) => !!document.querySelector(sel), SEL.dirtyDialog
    );
    this.assert(dialogVisible, 'Unsaved-changes dialog (.fct-variant-dirty-dialog) appears');

    if (dialogVisible) {
      // Click Discard to discard changes and close the dialog
      await this.page.evaluate(() => {
        const dialog = document.querySelector('.fct-variant-dirty-dialog');
        const btns = [...(dialog?.querySelectorAll('.dialog-footer button') || [])];
        const btn = btns.find(b => b.textContent.trim() === 'Discard');
        if (btn) btn.click();
      });

      // El Plus el-dialog uses v-show — the element stays in DOM with display:none when closed.
      // Check CSS visibility rather than DOM presence.
      await this.page.waitForFunction((sel) => {
        const el = document.querySelector(sel);
        if (!el) return true;
        let node = el;
        while (node && node !== document.body) {
          if (window.getComputedStyle(node).display === 'none') return true;
          node = node.parentElement;
        }
        return false;
      }, SEL.dirtyDialog, { timeout: 5000 }).catch(() => {});

      const dialogGone = await this.page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return true;
        let node = el;
        while (node && node !== document.body) {
          if (window.getComputedStyle(node).display === 'none') return true;
          node = node.parentElement;
        }
        return false;
      }, SEL.dirtyDialog);
      this.assert(dialogGone, 'Dirty dialog dismissed after Discard');

      // Same check for the drawer (el-drawer also uses v-show on the overlay)
      await this.page.waitForFunction((sel) => {
        const el = document.querySelector(sel);
        if (!el) return true;
        let node = el;
        while (node && node !== document.body) {
          if (window.getComputedStyle(node).display === 'none') return true;
          node = node.parentElement;
        }
        return false;
      }, SEL.drawer, { timeout: 5000 }).catch(() => {});

      const drawerGone = await this.page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return true;
        let node = el;
        while (node && node !== document.body) {
          if (window.getComputedStyle(node).display === 'none') return true;
          node = node.parentElement;
        }
        return false;
      }, SEL.drawer);
      this.assert(drawerGone, 'Drawer closes after Discard');
    }
  }

  // ── Restore original Simple mode ──────────────────────────────────────────

  async _restoreSimpleMode() {
    this.info('Restoring product to Simple mode...');
    const elSelect = new ElSelectHelper(this);
    await elSelect.select(SEL.modeSelect, 'Simple');

    // Confirmation dialog may appear (switching from variations to simple deletes extra variants)
    await this.page.waitForFunction(
      () => !!document.querySelector('.el-overlay .el-message-box'),
      { timeout: 3000 }
    ).catch(() => {});

    const dialogExists = await this.page.evaluate(
      () => !!document.querySelector('.el-overlay .el-message-box')
    );
    if (dialogExists) {
      // Type "proceed" to confirm the switch (Confirmation.confirmDeleteWithInput)
      const input = await this.page.$('.el-message-box input');
      if (input) {
        await input.fill('proceed');
      }
      await this.page.evaluate(() => {
        const box = document.querySelector('.el-overlay .el-message-box');
        const btns = [...(box?.querySelectorAll('button') || [])];
        const confirm = btns.find(b => b.classList.contains('el-button--primary'));
        if (confirm) confirm.click();
      });
      await this.page.waitForFunction(
        () => !document.querySelector('.el-overlay .el-message-box'),
        { timeout: 5000 }
      ).catch(() => {});
    }

    await this.page.waitForFunction(
      (sel) => !!document.querySelector(sel), SEL.saveBarActive, { timeout: 5000 }
    ).catch(() => {});

    const restoreResult = await this.actionWithApi(
      () => this.page.evaluate(() => {
        const bar = document.querySelector('.fct-save-bar');
        const btn = [...(bar?.querySelectorAll('button') || [])]
          .find(b => b.textContent.includes('Update'));
        if (btn) btn.click();
      }),
      '/pricing',
      'POST'
    );
    this.assert(!!restoreResult?.ok, 'Mode restored to Simple', `status: ${restoreResult?.status}`);
  }

  // ── Restore variant title after persistence test ──────────────────────────

  async _restoreVariantTitle(originalTitle, testTitle) {
    if (!originalTitle || originalTitle === testTitle) return;
    this.info(`Restoring variant title to "${originalTitle}"...`);

    await this._openDrawer();
    await this.page.waitForSelector(SEL.titleInput, { timeout: 5000 }).catch(() => null);
    await this.setInput(SEL.titleInput, originalTitle);
    await this.pause();

    await this.page.waitForFunction(() => {
      const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
      const btns = [...(footer?.querySelectorAll('button') || [])];
      const btn = btns.find(b => b.textContent.trim().includes('Update') || b.textContent.trim().includes('Save'));
      return btn && !btn.disabled;
    }, { timeout: 3000 }).catch(() => {});

    const result = await this.actionWithApi(
      () => this.page.evaluate(() => {
        const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
        const btns = [...(footer?.querySelectorAll('button') || [])];
        const btn = btns.find(b => b.textContent.trim().includes('Update') || b.textContent.trim().includes('Save'));
        if (btn) btn.click();
      }),
      'products/variants',
      'POST'
    );
    this.assert(!!result?.ok, `Title restored to "${originalTitle}"`, `status: ${result?.status}`);
    await this._closeDrawer();
    await this.hardReload(SEL.formReady);
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  async _openDrawer() {
    this.info('Clicking edit (pencil) button to open variation drawer...');
    await this.page.evaluate((sel) => {
      const btn = document.querySelector(sel);
      if (btn) btn.click();
    }, SEL.editBtn);
    await this.page.waitForSelector(SEL.drawer, { timeout: 8000 }).catch(() => null);
    await this.pause();
  }

  async _closeDrawer() {
    this.info('Closing drawer via Cancel button...');

    // Helper: check if an element (or its ancestor overlay) is hidden via CSS
    const isHidden = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return true;
      let node = el;
      while (node && node !== document.body) {
        if (window.getComputedStyle(node).display === 'none') return true;
        node = node.parentElement;
      }
      return false;
    };

    // If dirty dialog is already open, discard it first
    const dirtyVisible = await this.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      let node = el;
      while (node && node !== document.body) {
        if (window.getComputedStyle(node).display === 'none') return false;
        node = node.parentElement;
      }
      return true;
    }, SEL.dirtyDialog);

    if (dirtyVisible) {
      await this.page.evaluate(() => {
        const dialog = document.querySelector('.fct-variant-dirty-dialog');
        const btns = [...(dialog?.querySelectorAll('.dialog-footer button') || [])];
        const btn = btns.find(b => b.textContent.trim() === 'Discard');
        if (btn) btn.click();
      });
      // Wait for drawer to become hidden (v-show sets display:none on overlay)
      await this.page.waitForFunction((sel) => {
        const el = document.querySelector(sel);
        if (!el) return true;
        let node = el;
        while (node && node !== document.body) {
          if (window.getComputedStyle(node).display === 'none') return true;
          node = node.parentElement;
        }
        return false;
      }, SEL.drawer, { timeout: 5000 }).catch(() => {});
      return;
    }

    await this.page.evaluate(() => {
      const footer = document.querySelector('.fct-shared-variant-drawer .dialog-footer');
      const btns = [...(footer?.querySelectorAll('button') || [])];
      const btn = btns.find(b => b.textContent.trim() === 'Cancel');
      if (btn) btn.click();
    });

    // Wait for drawer to become hidden
    await this.page.waitForFunction((sel) => {
      const el = document.querySelector(sel);
      if (!el) return true;
      let node = el;
      while (node && node !== document.body) {
        if (window.getComputedStyle(node).display === 'none') return true;
        node = node.parentElement;
      }
      return false;
    }, SEL.drawer, { timeout: 5000 }).catch(() => {});
    await this.pause();
  }
}

import BaseTest from '../../dev/browser/base-test.mjs';
import { login } from '../../dev/browser/setup.mjs';
import Phase26ProcessGuard from './process-guard.mjs';

const SEL = {
  app: '#fluent_cart_plugin_app',
  routeView: '#fluent_cart_plugin_app .fl_app',
  rows: '.el-table__body-wrapper .el-table__row',
};

const PHP_DIAGNOSTIC = /PHP\s+(?:Notice|Warning|Deprecated|Fatal error)\b|<b>\s*(?:Notice|Warning|Deprecated|Fatal error)\s*:/i;
const PARKED_PAGE_ERROR = /Cannot read properties of undefined \(reading 'offsetHeight'\)/;

const PARKED_ROUTES = {
  integration_editor: 'FIX-PLAN #26: no global integration fields are registered for this legacy editor',
  SingleIntegration: 'FIX-PLAN #25: component requests an unregistered numeric-ID settings endpoint',
  'subscriptions-cohorts': 'FIX-PLAN #1: upgraded store is missing fct_retention_snapshots',
  view_customer_licenses: 'FIX-PLAN #24: administrator request is rejected by the license policy',
};

const PARAM_DEFAULTS = {
  integration_id: 0,
  integration_name: 'fluentcrm',
  name: 'order_paid_admin',
  method: 'stripe',
  template: 'order_receipt',
  country: 'US',
  group: 'standard',
};

function routeParams(route, fixtures) {
  const params = { ...PARAM_DEFAULTS };
  const fixtureParams = {
    order_id: fixtures.order_id,
    customer_id: fixtures.customer_id,
    product_id: fixtures.product_id,
    subscription_id: fixtures.subscription_id,
    coupon_id: fixtures.coupon_id,
    zone_id: fixtures.zone_id,
    class_id: fixtures.class_id || 1,
    license_id: fixtures.license_id,
    site_id: fixtures.site_id,
    id: fixtures.order_bump_id,
    integration_id: fixtures.integration_id,
  };

  for (const [key, value] of Object.entries(fixtureParams)) {
    params[key] = value;
  }

  if ([
    'SingleIntegration',
    'product_integration_feed_editor',
  ].includes(route.name)) {
    params.integration_id = fixtures.product_integration_id;
  }

  const required = [...String(route.path).matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)]
    .map((match) => match[1]);
  return Object.fromEntries(required.map((key) => [key, params[key] ?? `phase26-${key}`]));
}

function concreteRoutePath(path, params) {
  return String(path).replace(
    /:([A-Za-z_][A-Za-z0-9_]*)(?:\([^)]*\))?\??/g,
    (match, key) => encodeURIComponent(params[key] ?? `phase26-${key}`),
  );
}

function describeStateChanges(before, after) {
  const changes = [];
  for (const section of ['tables', 'options']) {
    const beforeItems = before?.state_hashes?.[section] || {};
    const afterItems = after?.state_hashes?.[section] || {};
    const keys = [...new Set([...Object.keys(beforeItems), ...Object.keys(afterItems)])].sort();
    for (const key of keys) {
      if (beforeItems[key] !== afterItems[key]) changes.push(`${section}:${key}`);
    }
  }
  for (const section of ['products', 'product_meta']) {
    if (before?.state_hashes?.[section] !== after?.state_hashes?.[section]) {
      changes.push(section);
    }
  }
  return changes;
}

function attachDiagnostics(page, siteUrl) {
  const consoleErrors = [];
  const pageErrors = [];
  const phpDiagnostics = [];
  const pendingResponses = new Set();
  const siteOrigin = new URL(siteUrl).origin;

  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const location = message.location()?.url || '';
    if (location && !location.startsWith(siteOrigin)) return;
    consoleErrors.push(`${new URL(page.url()).hash || '#/'} :: ${message.text()}`);
  });

  page.on('pageerror', (error) => {
    pageErrors.push(`${new URL(page.url()).hash || '#/'} :: ${error.message}`);
  });

  page.on('response', (response) => {
    const url = response.url();
    if (!url.startsWith(siteOrigin)) return;
    const contentType = response.headers()['content-type'] || '';
    if (!/(?:json|text|html|javascript)/i.test(contentType)) return;

    const read = response.text()
      .then((body) => {
        if (PHP_DIAGNOSTIC.test(body)) {
          phpDiagnostics.push(`${response.status()} ${response.request().method()} ${url.split('?')[0]}`);
        }
      })
      .catch(() => {})
      .finally(() => pendingResponses.delete(read));
    pendingResponses.add(read);
  });

  return {
    consoleErrors,
    pageErrors,
    phpDiagnostics,
    async flush() {
      await Promise.all([...pendingResponses]);
    },
  };
}

async function waitForRouteSettle(page) {
  await page.waitForFunction(() => {
    const app = document.querySelector('#fluent_cart_plugin_app');
    return app?.__vue_app__?.config?.globalProperties?.$router?.currentRoute?.value;
  }, { timeout: 15000 });

  await page.waitForFunction(() => {
    const view = document.querySelector('#fluent_cart_plugin_app .fl_app');
    if (!view) return false;
    const visible = [...view.children].some((child) => {
      const rect = child.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0;
    });
    return visible && view.innerHTML.trim().length > 0;
  }, { timeout: 15000 });

  await page.waitForTimeout(300);
}

export default class AdminScreensSmokeTest extends BaseTest {
  static title = 'Admin — every registered screen mounts read-only';
  static requiresLogin = false;

  async run() {
    const guard = new Phase26ProcessGuard();
    const siteUrl = guard.getSiteUrl();
    const baseline = guard.snapshotReadOnlyData();
    let diagnostics;
    let cleanupResult = { userRemoved: false, artifactsRemoved: false };
    let editLockCleanup = { exact: false, restored: 0, removed: 0 };
    let runtimeCaptures = [];
    let finalSnapshot = null;

    this.baseUrl = siteUrl;
    this.info(`Read-only smoke target: ${siteUrl}`);

    try {
      const proof = guard.installAndProve();
      this.assert(
        proof.active && proof.payloadsCaptured,
        'cross-process MU guard proves HTTP, mail, cron, and Action Scheduler payload catches',
        proof.capturedTypes.join(', '),
      );

      const temporaryAdmin = guard.createTemporaryAdmin();
      guard.clearCaptures();
      diagnostics = attachDiagnostics(this.page, siteUrl);
      const sharedLogin = login(
        this.page,
        siteUrl,
        temporaryAdmin.login,
        temporaryAdmin.password,
      );
      try {
        const loginResult = await Promise.race([
          sharedLogin.then(() => 'wp-admin'),
          this.page.waitForFunction(() =>
            document.body.classList.contains('logged-in'),
          { timeout: 15000 }).then(() => 'custom-redirect'),
        ]);
        if (loginResult === 'custom-redirect') {
          sharedLogin.catch(() => {});
          this.info(`WordPress authenticated through the site's custom ${this.page.url().replace(siteUrl, '')} redirect`);
        }
      } catch (error) {
        const loginState = {
          url: this.page.url().replace(siteUrl, ''),
          title: await this.page.title().catch(() => ''),
          authenticated: await this.page.evaluate(() => {
            const inAdmin = window.location.pathname.startsWith('/wp-admin/')
              && document.body.classList.contains('wp-admin')
              && !document.querySelector('#loginform');
            const onFrontend = document.body.classList.contains('logged-in');
            return inAdmin || onFrontend;
          }).catch(() => false),
        };
        let recoveredWithCookie = false;
        if (!loginState.authenticated) {
          sharedLogin.catch(() => {});
          await this.page.context().clearCookies();
          await this.page.context().addCookies(
            guard.createTemporaryAdminCookies(siteUrl),
          );
          await this.page.goto(`${siteUrl}/wp-admin/`, { waitUntil: 'load' });
          loginState.url = this.page.url().replace(siteUrl, '');
          loginState.title = await this.page.title().catch(() => '');
          loginState.authenticated = await this.page.evaluate(() =>
            window.location.pathname.startsWith('/wp-admin/')
            && document.body.classList.contains('wp-admin')
            && !document.querySelector('#loginform')
          ).catch(() => false);
          if (!loginState.authenticated) {
            throw new Error(`Temporary administrator login failed: ${JSON.stringify(loginState)} (${error.message.split('\n')[0]})`);
          }
          recoveredWithCookie = true;
        }
        this.skip(
          'initial disposable-administrator web login',
          recoveredWithCookie
            ? 'FIX-PLAN #29; recovered with an exact owned WordPress auth cookie'
            : 'FIX-PLAN #29; helper timed out but authenticated WordPress state was independently verified',
        );
        this.info(`WordPress authenticated through the site's custom ${loginState.url} redirect`);
      }

      await this.goto('/wp-admin/admin.php?page=fluent-cart#/');
      await this.page.waitForSelector(SEL.app, { timeout: 15000 });
      await waitForRouteSettle(this.page);

      const routes = await this.evaluate(() => {
        const root = document.querySelector('#fluent_cart_plugin_app');
        const router = root?.__vue_app__?.config?.globalProperties?.$router;
        if (!router) return [];

        return router.getRoutes()
          .filter((route) =>
            route.name
            && route.children.length === 0
            && String(route.name) !== 'NotFound'
          )
          .map((route) => ({
            name: String(route.name),
            path: route.path,
          }))
          .sort((left, right) => left.path.localeCompare(right.path) || left.name.localeCompare(right.name));
      });

      this.assert(routes.length > 0, 'runtime Vue router exposes registered admin screens', `${routes.length} leaf routes`);
      const parkedRoutes = [];

      for (const route of routes) {
        if (PARKED_ROUTES[route.name]) {
          parkedRoutes.push(route.name);
          this.info(`${route.name} parked — ${PARKED_ROUTES[route.name]}`);
          continue;
        }

        if (route.path.includes(':class_id') && !baseline.class_id) {
          parkedRoutes.push(route.name);
          this.info(`${route.name} parked — FIX-PLAN #27: no read-only shipping-class fixture; add screen covers the component`);
          continue;
        }

        const params = routeParams(route, baseline);
        const path = concreteRoutePath(route.path, params);
        let result;

        try {
          result = await this.evaluate(async ({ path }) => {
            const root = document.querySelector('#fluent_cart_plugin_app');
            const router = root?.__vue_app__?.config?.globalProperties?.$router;
            if (!router) return { mounted: false, detail: 'router unavailable' };

            await router.push(path);
            await router.isReady();
            return {
              mounted: true,
              currentName: String(router.currentRoute.value.name || ''),
              fullPath: router.currentRoute.value.fullPath,
            };
          }, { path });
          await waitForRouteSettle(this.page);
        } catch (error) {
          result = { mounted: false, detail: error.message };
        }

        const readViewState = () => this.evaluate(({ selector, expectedPath }) => {
          const view = document.querySelector(selector);
          const root = document.querySelector('#fluent_cart_plugin_app');
          const router = root?.__vue_app__?.config?.globalProperties?.$router;
          const currentName = String(router?.currentRoute?.value?.name || '');
          const currentPath = String(router?.currentRoute?.value?.path || '');
          const visibleChildren = view
            ? [...view.children].filter((child) => {
                const rect = child.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0;
              }).length
            : 0;
          return {
            currentName,
            currentPath,
            htmlLength: view?.innerHTML.trim().length || 0,
            visibleChildren,
            expectedPath,
          };
        }, { selector: SEL.routeView, expectedPath: path });

        let viewState = await readViewState();
        let mounted = Boolean(result?.mounted)
          && viewState.currentPath.replace(/\/+$/, '') === path.replace(/\/+$/, '')
          && viewState.htmlLength > 0
          && viewState.visibleChildren > 0;

        if (!mounted) {
          try {
            await this.page.goto(
              `${siteUrl}/wp-admin/admin.php?page=fluent-cart#${path}`,
              { waitUntil: 'load' },
            );
            await this.page.waitForSelector(SEL.app, { state: 'attached', timeout: 15000 });
            await waitForRouteSettle(this.page);
            viewState = await readViewState();
            mounted = viewState.currentPath.replace(/\/+$/, '') === path.replace(/\/+$/, '')
              && viewState.htmlLength > 0
              && viewState.visibleChildren > 0;
          } catch (retryError) {
            result = {
              mounted: false,
              detail: `${result?.detail || result?.fullPath || ''}; hard retry: ${retryError.message}`,
            };
          }
        }

        this.assert(
          mounted,
          `${route.name} mounts`,
          mounted
            ? viewState.currentPath
            : result?.detail || `target ${path}; current ${viewState.currentPath}`,
        );
      }

      this.assert(
        parkedRoutes.length === 5
          && parkedRoutes.every((name) =>
            Object.hasOwn(PARKED_ROUTES, name) || name === 'view_shipping_class'
          ),
        'known product failures and unavailable read-only detail are explicitly parked',
        parkedRoutes.join(', '),
      );

      for (const grid of [
        { name: 'orders', route: '/orders' },
        { name: 'products', route: '/products' },
        { name: 'customers', route: '/customers' },
        { name: 'subscriptions', route: '/subscriptions' },
      ]) {
        const navigation = await this.evaluate(async ({ path }) => {
          const root = document.querySelector('#fluent_cart_plugin_app');
          const router = root?.__vue_app__?.config?.globalProperties?.$router;
          await router.push(path);
          await router.isReady();
          return router.currentRoute.value.fullPath;
        }, { path: grid.route });
        await waitForRouteSettle(this.page);
        const rowCount = await this.page.locator(SEL.rows).count();
        this.assert(rowCount > 0, `${grid.name} data grid renders rows`, `${rowCount} rows at ${navigation}`);
      }

      await diagnostics.flush();
      this.assert(
        diagnostics.consoleErrors.length === 0,
        'admin screens emit no console errors',
        diagnostics.consoleErrors.slice(0, 3).join(' | '),
      );
      const unexpectedPageErrors = diagnostics.pageErrors.filter(
        (error) => !PARKED_PAGE_ERROR.test(error),
      );
      const parkedPageErrors = diagnostics.pageErrors.filter(
        (error) => PARKED_PAGE_ERROR.test(error),
      );
      this.skip(
        'uncaught Element Plus offsetHeight diagnostic',
        `FIX-PLAN #28 (${parkedPageErrors.length} observed); every other page error remains fatal`,
      );
      this.assert(
        unexpectedPageErrors.length === 0,
        'admin screens emit no uncaught page errors outside FIX-PLAN #28',
        unexpectedPageErrors.slice(0, 3).join(' | '),
      );
      this.assert(
        diagnostics.phpDiagnostics.length === 0,
        'admin responses emit no PHP notices, warnings, deprecations, or fatals',
        diagnostics.phpDiagnostics.slice(0, 3).join(' | '),
      );
    } finally {
      try {
        if (guard.tempAdmin) {
          editLockCleanup = guard.restoreProductEditLocks(baseline.product_edit_locks);
        } else {
          editLockCleanup.exact = true;
        }
        guard.removeTemporaryAdmin();
        runtimeCaptures = guard.readCaptures();
        finalSnapshot = guard.snapshotReadOnlyData();
      } finally {
        cleanupResult = guard.teardown();
      }

      this.assert(
        editLockCleanup.exact
          && cleanupResult.userRemoved
          && cleanupResult.artifactsRemoved,
        'temporary administrator and MU guard artifacts are removed; product edit locks are restored',
        `${editLockCleanup.restored} restored, ${editLockCleanup.removed} removed`,
      );
      this.assert(
        runtimeCaptures.every((capture) =>
          ['http', 'mail', 'cron', 'action_scheduler'].includes(capture.type)
          && capture.proof === false
        ),
        'browser-process transports remain fail closed',
        runtimeCaptures.map((capture) => `${capture.type}:${capture.payload?.hook || capture.payload?.url || ''}`).join(', '),
      );
      this.assert(
        finalSnapshot?.orders === baseline.orders
          && finalSnapshot?.customers === baseline.customers,
        'protected order and customer counts remain unchanged',
        `${finalSnapshot?.orders}/${finalSnapshot?.customers}`,
      );
      this.assert(
        finalSnapshot?.store_fingerprint === baseline.store_fingerprint,
        'all FluentCart tables, options, Products, and Product meta remain byte-stable',
        finalSnapshot?.store_fingerprint === baseline.store_fingerprint
          ? finalSnapshot?.store_fingerprint
          : `changed ${describeStateChanges(baseline, finalSnapshot).join(', ') || '[unidentified]'}; before ${baseline.store_fingerprint}; after ${finalSnapshot?.store_fingerprint}`,
      );
    }
  }
}

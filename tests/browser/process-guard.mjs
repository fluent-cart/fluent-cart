import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  truncateSync,
  unlinkSync,
  writeFileSync,
} from 'fs';
import { tmpdir } from 'os';
import { dirname, join, resolve } from 'path';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import { randomBytes } from 'crypto';

const PLUGIN_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const WP_ROOT = resolve(PLUGIN_ROOT, '../../..');
const GUARD_FILE = join(WP_ROOT, 'wp-content/mu-plugins/fluent-cart-phase26-browser-guard.php');
const GUARD_MARKER = 'FLUENT_CART_PHASE26_BROWSER_GUARD';
const PHP_DIAGNOSTIC = /(?:PHP\s+)?(?:Notice|Warning|Deprecated|Fatal error)\b/i;

function phpString(value) {
  return `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
}

function lastJsonLine(output, context) {
  const lines = String(output).trim().split(/\r?\n/).filter(Boolean);
  for (let index = lines.length - 1; index >= 0; index--) {
    try {
      return JSON.parse(lines[index]);
    } catch {
      // WordPress may print unrelated plugin chatter before the command result.
    }
  }
  throw new Error(`${context} did not return JSON.`);
}

export default class Phase26ProcessGuard {
  constructor() {
    this.token = `phase26-${randomBytes(10).toString('hex')}`;
    this.tempDir = mkdtempSync(join(tmpdir(), 'fluent-cart-phase26-'));
    this.captureFile = join(this.tempDir, 'captures.jsonl');
    this.guardSource = '';
    this.tempAdmin = null;
    this.installed = false;
    this.signalHandlersInstalled = false;
  }

  wpEval(code, extraEnv = {}) {
    let output;
    try {
      output = execFileSync(
        'wp',
        [`--path=${WP_ROOT}`, 'eval', code],
        {
          cwd: WP_ROOT,
          encoding: 'utf8',
          env: { ...process.env, ...extraEnv },
          stdio: ['ignore', 'pipe', 'pipe'],
        },
      );
    } catch (error) {
      const stderr = String(error.stderr || '').trim();
      const stdout = String(error.stdout || '').trim();
      throw new Error(
        `WP-CLI command failed${stderr ? `: ${stderr}` : stdout ? `: ${stdout}` : '.'}`,
      );
    }

    if (PHP_DIAGNOSTIC.test(output)) {
      throw new Error('WP-CLI emitted a PHP diagnostic while preparing the browser smoke tier.');
    }

    return String(output).trim();
  }

  getSiteUrl() {
    return execFileSync(
      'wp',
      [`--path=${WP_ROOT}`, 'option', 'get', 'siteurl'],
      { cwd: WP_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
    ).trim().replace(/\/+$/, '');
  }

  snapshotReadOnlyData() {
    const output = this.wpEval(`
      global $wpdb;
      $tablePattern = $wpdb->esc_like($wpdb->prefix . 'fct_') . '%';
      $storeTables = $wpdb->get_col(
        $wpdb->prepare('SHOW TABLES LIKE %s', $tablePattern)
      );
      sort($storeTables);
      $tableState = [];
      foreach ($storeTables as $table) {
        $safeTable = str_replace('\`', '\`\`', $table);
        $checksum = $wpdb->get_row(
          "CHECKSUM TABLE \`{$safeTable}\`",
          ARRAY_A
        );
        $tableState[$table] = [
          'rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM \`{$safeTable}\`"),
          'checksum' => isset($checksum['Checksum'])
            ? (string) $checksum['Checksum']
            : null
        ];
      }
      $optionState = $wpdb->get_results(
        "SELECT option_name, MD5(option_value) AS value_hash, autoload
         FROM {$wpdb->options}
         WHERE option_name LIKE 'fluent_cart%'
            OR option_name LIKE '_fluent_cart%'
            OR option_name LIKE 'fct_%'
         ORDER BY option_name",
        ARRAY_A
      );
      $productState = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->posts}
           WHERE post_type = %s
           ORDER BY ID",
          'fluent-products'
        ),
        ARRAY_A
      );
      $productMetaState = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT pm.* FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
           WHERE p.post_type = %s
           ORDER BY pm.meta_id",
          'fluent-products'
        ),
        ARRAY_A
      );
      $productEditLocks = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT pm.meta_id, pm.post_id, pm.meta_value
           FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
           WHERE p.post_type = %s
             AND pm.meta_key = %s
           ORDER BY pm.meta_id",
          'fluent-products',
          '_edit_lock'
        ),
        ARRAY_A
      );
      $stateHashes = [
        'tables' => array_map(
          static function ($state) {
            return hash('sha256', wp_json_encode($state));
          },
          $tableState
        ),
        'options' => [],
        'products' => hash('sha256', wp_json_encode($productState)),
        'product_meta' => hash('sha256', wp_json_encode($productMetaState))
      ];
      foreach ($optionState as $option) {
        $stateHashes['options'][$option['option_name']] = hash(
          'sha256',
          wp_json_encode($option)
        );
      }
      $storeFingerprint = hash(
        'sha256',
        wp_json_encode($stateHashes)
      );
      echo wp_json_encode([
        'orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"),
        'customers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers"),
        'subscriptions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_subscriptions"),
        'store_fingerprint' => $storeFingerprint,
        'state_hashes' => $stateHashes,
        'product_edit_locks' => $productEditLocks,
        'order_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_orders ORDER BY id ASC LIMIT 1"),
        'customer_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_customers ORDER BY id ASC LIMIT 1"),
        'subscription_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_subscriptions ORDER BY id ASC LIMIT 1"),
        'product_id' => (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s ORDER BY ID ASC LIMIT 1",
            'fluent-products',
            'trash'
          )
        ),
        'zone_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_shipping_zones ORDER BY id ASC LIMIT 1"),
        'class_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_shipping_classes ORDER BY id ASC LIMIT 1"),
        'coupon_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_coupons ORDER BY id ASC LIMIT 1"),
        'license_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_licenses ORDER BY id ASC LIMIT 1"),
        'site_id' => (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}fct_license_sites ORDER BY id ASC LIMIT 1"),
        'order_bump_id' => (int) $wpdb->get_var(
          "SELECT id FROM {$wpdb->prefix}fct_order_promotions WHERE type = 'order_bump' ORDER BY id ASC LIMIT 1"
        ),
        'integration_id' => (int) $wpdb->get_var(
          "SELECT id FROM {$wpdb->prefix}fct_meta WHERE object_type = 'order_integration' ORDER BY id ASC LIMIT 1"
        ),
        'product_integration_id' => (int) $wpdb->get_var(
          "SELECT id FROM {$wpdb->prefix}fct_product_meta WHERE object_type = 'product_integration' ORDER BY id ASC LIMIT 1"
        )
      ]);
    `);

    return lastJsonLine(output, 'Protected-data snapshot');
  }

  installAndProve() {
    if (existsSync(GUARD_FILE)) {
      throw new Error(`Refusing to replace an existing MU plugin at ${GUARD_FILE}.`);
    }

    mkdirSync(dirname(GUARD_FILE), { recursive: true });
    writeFileSync(this.captureFile, '', { flag: 'wx' });
    this.guardSource = this.buildGuardSource();
    writeFileSync(GUARD_FILE, this.guardSource, { flag: 'wx' });
    this.installed = true;
    this.installSignalHandlers();

    const proofOutput = this.wpEval(`
      $token = getenv('FCT_PHASE26_TOKEN');
      $http = wp_remote_post(
        'https://phase26.invalid/' . rawurlencode($token),
        ['body' => ['token' => $token, 'amount' => 12345]]
      );
      $mail = wp_mail(
        $token . '@example.invalid',
        'Phase 26 guard ' . $token,
        wp_json_encode(['token' => $token, 'amount' => 12345])
      );
      $cron = wp_schedule_single_event(
        time() + 300,
        'fluent_cart_phase26_' . $token,
        [$token, 12345],
        true
      );
      $action = function_exists('as_enqueue_async_action')
        ? as_enqueue_async_action(
            'fluent_cart_phase26_' . $token,
            ['token' => $token, 'amount' => 12345],
            'fluent-cart-phase26'
          )
        : null;
      echo wp_json_encode([
        'http_blocked' => is_wp_error($http)
          && $http->get_error_code() === 'fluent_cart_phase26_http_blocked',
        'mail_blocked' => $mail === true,
        'cron_blocked' => is_wp_error($cron)
          && $cron->get_error_code() === 'fluent_cart_phase26_cron_blocked',
        'action_scheduler_blocked' => $action === 0
      ]);
    `, { FCT_PHASE26_TOKEN: this.token });

    const apiProof = lastJsonLine(proofOutput, 'Cross-process guard proof');
    const captures = this.readCaptures();
    const expectedTypes = ['action_scheduler', 'cron', 'http', 'mail'];
    const proofCaptures = captures.filter((item) => item.proof === true);
    const capturedTypes = proofCaptures.map((item) => item.type).sort();
    const payloadsCaptured = proofCaptures.length === expectedTypes.length
      && proofCaptures.every((item) => JSON.stringify(item.payload).includes(this.token));
    const active = Object.values(apiProof).every(Boolean)
      && JSON.stringify(capturedTypes) === JSON.stringify(expectedTypes)
      && payloadsCaptured;

    if (!active) {
      throw new Error(
        `Cross-process guard proof failed: ${JSON.stringify({
          apiProof,
          capturedTypes,
          payloadsCaptured,
        })}`,
      );
    }

    truncateSync(this.captureFile, 0);
    return {
      active,
      apiProof,
      capturedTypes,
      payloadsCaptured,
      incidentalBlocked: captures.length - proofCaptures.length,
    };
  }

  createTemporaryAdmin() {
    const login = `fct_phase26_${randomBytes(8).toString('hex')}`;
    const password = randomBytes(24).toString('base64url');
    const email = `${login}@example.invalid`;
    const output = this.wpEval(`
      $login = getenv('FCT_PHASE26_LOGIN');
      $email = getenv('FCT_PHASE26_EMAIL');
      $password = getenv('FCT_PHASE26_PASSWORD');
      if (username_exists($login) || email_exists($email)) {
        throw new RuntimeException('Temporary Phase 26 user identity already exists.');
      }
      $id = wp_insert_user([
        'user_login' => $login,
        'user_email' => $email,
        'user_pass' => $password,
        'display_name' => 'FluentCart Phase 26 Browser Guard',
        'role' => 'administrator'
      ]);
      if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
      }
      echo wp_json_encode(['id' => (int) $id, 'login' => $login]);
    `, {
      FCT_PHASE26_LOGIN: login,
      FCT_PHASE26_EMAIL: email,
      FCT_PHASE26_PASSWORD: password,
    });

    const created = lastJsonLine(output, 'Temporary administrator creation');
    if (!created.id || created.login !== login) {
      throw new Error('Temporary administrator identity could not be verified.');
    }

    this.tempAdmin = { id: created.id, login, email, password };
    return { ...this.tempAdmin };
  }

  createTemporaryAdminCookies(siteUrl) {
    if (!this.tempAdmin) {
      throw new Error('Temporary administrator must exist before creating auth cookies.');
    }

    const { id, login, email } = this.tempAdmin;
    const output = this.wpEval(`
      $id = (int) getenv('FCT_PHASE26_USER_ID');
      $login = getenv('FCT_PHASE26_LOGIN');
      $email = getenv('FCT_PHASE26_EMAIL');
      $user = get_userdata($id);
      if (!$user || $user->user_login !== $login || $user->user_email !== $email) {
        throw new RuntimeException('Refusing to authenticate an unowned WordPress user.');
      }
      $expiration = time() + HOUR_IN_SECONDS;
      echo wp_json_encode([
        'expiration' => $expiration,
        'cookies' => [
          [
            'name' => SECURE_AUTH_COOKIE,
            'value' => wp_generate_auth_cookie($id, $expiration, 'secure_auth')
          ],
          [
            'name' => LOGGED_IN_COOKIE,
            'value' => wp_generate_auth_cookie($id, $expiration, 'logged_in')
          ]
        ]
      ]);
    `, {
      FCT_PHASE26_USER_ID: String(id),
      FCT_PHASE26_LOGIN: login,
      FCT_PHASE26_EMAIL: email,
    });

    const result = lastJsonLine(output, 'Temporary administrator auth-cookie creation');
    if (
      !Number.isInteger(result.expiration)
      || !Array.isArray(result.cookies)
      || result.cookies.length !== 2
      || result.cookies.some((cookie) => !cookie.name || !cookie.value)
    ) {
      throw new Error('Temporary administrator auth cookies could not be verified.');
    }

    return result.cookies.map((cookie) => ({
      ...cookie,
      url: siteUrl,
      expires: result.expiration,
      httpOnly: true,
      secure: siteUrl.startsWith('https://'),
      sameSite: 'Lax',
    }));
  }

  restoreProductEditLocks(baselineLocks) {
    if (!this.tempAdmin) {
      throw new Error('Temporary administrator must exist before restoring product edit locks.');
    }

    const output = this.wpEval(`
      global $wpdb;
      $userId = (int) getenv('FCT_PHASE26_USER_ID');
      $baseline = json_decode(getenv('FCT_PHASE26_EDIT_LOCKS'), true);
      if (!is_array($baseline)) {
        throw new RuntimeException('Product edit-lock baseline is invalid.');
      }
      $current = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT pm.meta_id, pm.post_id, pm.meta_value
           FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
           WHERE p.post_type = %s
             AND pm.meta_key = %s
           ORDER BY pm.meta_id",
          'fluent-products',
          '_edit_lock'
        ),
        ARRAY_A
      );
      $baselineById = [];
      foreach ($baseline as $row) {
        $baselineById[(string) $row['meta_id']] = $row;
      }
      $currentById = [];
      foreach ($current as $row) {
        $currentById[(string) $row['meta_id']] = $row;
      }
      $ownedSuffix = ':' . $userId;
      $restored = 0;
      $removed = 0;

      foreach ($baselineById as $metaId => $before) {
        if (!isset($currentById[$metaId])) {
          throw new RuntimeException('A pre-existing product edit lock was removed.');
        }
        $after = $currentById[$metaId];
        if ($after['meta_value'] === $before['meta_value']) {
          continue;
        }
        if (substr((string) $after['meta_value'], -strlen($ownedSuffix)) !== $ownedSuffix) {
          throw new RuntimeException('Refusing to replace an edit lock not owned by the temporary administrator.');
        }
        $updated = $wpdb->update(
          $wpdb->postmeta,
          ['meta_value' => $before['meta_value']],
          [
            'meta_id' => (int) $after['meta_id'],
            'post_id' => (int) $after['post_id'],
            'meta_key' => '_edit_lock',
            'meta_value' => $after['meta_value']
          ],
          ['%s'],
          ['%d', '%d', '%s', '%s']
        );
        if ($updated !== 1) {
          throw new RuntimeException('Product edit-lock restoration did not update exactly one row.');
        }
        $restored++;
      }

      foreach ($currentById as $metaId => $after) {
        if (isset($baselineById[$metaId])) {
          continue;
        }
        if (substr((string) $after['meta_value'], -strlen($ownedSuffix)) !== $ownedSuffix) {
          throw new RuntimeException('Refusing to remove an edit lock not owned by the temporary administrator.');
        }
        $deleted = $wpdb->delete(
          $wpdb->postmeta,
          [
            'meta_id' => (int) $after['meta_id'],
            'post_id' => (int) $after['post_id'],
            'meta_key' => '_edit_lock',
            'meta_value' => $after['meta_value']
          ],
          ['%d', '%d', '%s', '%s']
        );
        if ($deleted !== 1) {
          throw new RuntimeException('Product edit-lock cleanup did not remove exactly one row.');
        }
        $removed++;
      }

      $final = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT pm.meta_id, pm.post_id, pm.meta_value
           FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
           WHERE p.post_type = %s
             AND pm.meta_key = %s
           ORDER BY pm.meta_id",
          'fluent-products',
          '_edit_lock'
        ),
        ARRAY_A
      );
      echo wp_json_encode([
        'exact' => $final === $baseline,
        'restored' => $restored,
        'removed' => $removed
      ]);
    `, {
      FCT_PHASE26_USER_ID: String(this.tempAdmin.id),
      FCT_PHASE26_EDIT_LOCKS: JSON.stringify(baselineLocks),
    });

    const result = lastJsonLine(output, 'Product edit-lock cleanup');
    if (result.exact !== true) {
      throw new Error(`Product edit-lock cleanup failed: ${JSON.stringify(result)}`);
    }
    return result;
  }

  removeTemporaryAdmin() {
    if (!this.tempAdmin) return true;

    const { id, login, email } = this.tempAdmin;
    const output = this.wpEval(`
      $id = (int) getenv('FCT_PHASE26_USER_ID');
      $login = getenv('FCT_PHASE26_LOGIN');
      $email = getenv('FCT_PHASE26_EMAIL');
      $user = get_userdata($id);
      if (!$user || $user->user_login !== $login || $user->user_email !== $email) {
        throw new RuntimeException('Refusing to delete an unowned WordPress user.');
      }
      require_once ABSPATH . 'wp-admin/includes/user.php';
      $deleted = wp_delete_user($id, 1);
      echo wp_json_encode([
        'deleted' => $deleted === true,
        'login_absent' => username_exists($login) === false,
        'email_absent' => email_exists($email) === false
      ]);
    `, {
      FCT_PHASE26_USER_ID: String(id),
      FCT_PHASE26_LOGIN: login,
      FCT_PHASE26_EMAIL: email,
    });

    const result = lastJsonLine(output, 'Temporary administrator cleanup');
    if (!Object.values(result).every(Boolean)) {
      throw new Error(`Temporary administrator cleanup failed: ${JSON.stringify(result)}`);
    }

    this.tempAdmin = null;
    return true;
  }

  readCaptures() {
    if (!existsSync(this.captureFile)) return [];
    const content = readFileSync(this.captureFile, 'utf8').trim();
    if (!content) return [];
    return content.split(/\r?\n/).map((line) => JSON.parse(line));
  }

  clearCaptures() {
    if (!existsSync(this.captureFile)) {
      throw new Error('Phase 26 capture file is missing while the MU guard is active.');
    }
    truncateSync(this.captureFile, 0);
  }

  removeGuardArtifacts() {
    if (existsSync(GUARD_FILE)) {
      const current = readFileSync(GUARD_FILE, 'utf8');
      if (!current.includes(GUARD_MARKER) || (this.guardSource && current !== this.guardSource)) {
        throw new Error(`Refusing to remove an MU plugin not owned by Phase 26: ${GUARD_FILE}.`);
      }
      unlinkSync(GUARD_FILE);
    }
    this.installed = false;
    if (existsSync(this.tempDir)) {
      rmSync(this.tempDir, { recursive: true });
    }
    return !existsSync(GUARD_FILE) && !existsSync(this.tempDir);
  }

  teardown() {
    let userRemoved = false;
    let artifactsRemoved = false;
    let error = null;

    try {
      userRemoved = this.removeTemporaryAdmin();
    } catch (cleanupError) {
      error = cleanupError;
    }

    try {
      artifactsRemoved = this.removeGuardArtifacts();
    } catch (cleanupError) {
      error ||= cleanupError;
    }

    if (error) throw error;
    return { userRemoved, artifactsRemoved };
  }

  installSignalHandlers() {
    if (this.signalHandlersInstalled) return;
    this.signalHandlersInstalled = true;

    process.once('exit', () => {
      try {
        if (this.tempAdmin) this.removeTemporaryAdmin();
      } catch {
        // The ordinary test finally block reports cleanup failures.
      }
      try {
        if (this.installed) this.removeGuardArtifacts();
      } catch {
        // Never remove a file whose ownership check fails.
      }
    });

    for (const signal of ['SIGINT', 'SIGTERM']) {
      process.once(signal, () => {
        try {
          this.teardown();
        } finally {
          process.exit(signal === 'SIGINT' ? 130 : 143);
        }
      });
    }
  }

  buildGuardSource() {
    const captureFile = phpString(this.captureFile);
    const token = phpString(this.token);

    return `<?php
/**
 * Plugin Name: FluentCart Phase 26 Browser Guard
 * Description: Temporary fail-closed transport and scheduler guard for browser smoke tests.
 */

if (!defined('ABSPATH') || defined('${GUARD_MARKER}')) {
    return;
}

define('${GUARD_MARKER}', true);

function fluent_cart_phase26_capture(array $record)
{
    file_put_contents(
        ${captureFile},
        wp_json_encode($record) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function fluent_cart_phase26_is_proof($value)
{
    return strpos(wp_json_encode($value), ${token}) !== false;
}

add_filter('pre_http_request', function ($preempt, $args, $url) {
    $proof = fluent_cart_phase26_is_proof([$url, $args]);
    $parts = wp_parse_url((string) $url);
    $safeUrl = is_array($parts)
        ? ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '')
        : '[unparseable URL]';
    fluent_cart_phase26_capture([
        'type' => 'http',
        'proof' => $proof,
        'payload' => $proof
            ? ['url' => $url, 'method' => $args['method'] ?? 'GET', 'body' => $args['body'] ?? null]
            : ['url' => $safeUrl, 'method' => $args['method'] ?? 'GET']
    ]);
    return new WP_Error(
        'fluent_cart_phase26_http_blocked',
        'Outbound HTTP is blocked by the Phase 26 browser guard.'
    );
}, PHP_INT_MAX, 3);

add_filter('pre_wp_mail', function ($preempt, $attributes) {
    $proof = fluent_cart_phase26_is_proof($attributes);
    fluent_cart_phase26_capture([
        'type' => 'mail',
        'proof' => $proof,
        'payload' => $proof
            ? $attributes
            : [
                'to_count' => count((array) ($attributes['to'] ?? [])),
                'has_subject' => !empty($attributes['subject'])
            ]
    ]);
    return true;
}, PHP_INT_MAX, 2);

$fluentCartPhase26CronFilters = [
    'pre_schedule_event' => 'schedule',
    'pre_reschedule_event' => 'reschedule',
    'pre_unschedule_event' => 'unschedule',
    'pre_clear_scheduled_hook' => 'clear'
];

foreach ($fluentCartPhase26CronFilters as $filter => $operation) {
    add_filter($filter, function ($preempt, $eventOrHook) use ($operation) {
        $hook = is_object($eventOrHook) && isset($eventOrHook->hook)
            ? (string) $eventOrHook->hook
            : (is_string($eventOrHook) ? $eventOrHook : '[unknown hook]');
        $proof = fluent_cart_phase26_is_proof($eventOrHook);
        fluent_cart_phase26_capture([
            'type' => 'cron',
            'proof' => $proof,
            'payload' => $proof
                ? ['operation' => $operation, 'event' => $eventOrHook]
                : ['operation' => $operation, 'hook' => $hook]
        ]);
        return new WP_Error(
            'fluent_cart_phase26_cron_blocked',
            'Cron mutations are blocked by the Phase 26 browser guard.'
        );
    }, PHP_INT_MAX, 2);
}

function fluent_cart_phase26_action_scheduler_capture($operation, $hook, $arguments)
{
    $proof = fluent_cart_phase26_is_proof([$hook, $arguments]);
    fluent_cart_phase26_capture([
        'type' => 'action_scheduler',
        'proof' => $proof,
        'payload' => $proof
            ? ['operation' => $operation, 'hook' => $hook, 'arguments' => $arguments]
            : ['operation' => $operation, 'hook' => $hook]
    ]);
    return 0;
}

add_filter('pre_as_enqueue_async_action', function ($preempt, $hook, $args) {
    return fluent_cart_phase26_action_scheduler_capture('enqueue_async', $hook, $args);
}, PHP_INT_MAX, 6);

add_filter('pre_as_schedule_single_action', function ($preempt, $timestamp, $hook, $args) {
    return fluent_cart_phase26_action_scheduler_capture('schedule_single', $hook, $args);
}, PHP_INT_MAX, 7);

add_filter('pre_as_schedule_recurring_action', function ($preempt, $timestamp, $interval, $hook, $args) {
    return fluent_cart_phase26_action_scheduler_capture('schedule_recurring', $hook, $args);
}, PHP_INT_MAX, 8);

add_filter('pre_as_schedule_cron_action', function ($preempt, $timestamp, $schedule, $hook, $args) {
    return fluent_cart_phase26_action_scheduler_capture('schedule_cron', $hook, $args);
}, PHP_INT_MAX, 8);
`;
  }
}

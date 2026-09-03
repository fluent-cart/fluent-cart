#!/usr/bin/env bash
#
# Local WPFluent test runner, adapted from the wp-plugin-test-suite asset.
#
# The default PHP gate includes Phase 22 provider, Phase 23 commerce, and Phase
# 24 mutation-closure coverage alongside earlier tiers. Phase 25 Vitest, Phase
# 26 browser smoke, and Phase 27 E2E money flows remain standalone and opt-in.
#
# Usage:
#   tests/bin/run-all.sh              # static + pure + REST smoke + permissions + integration
#   tests/bin/run-all.sh static
#   tests/bin/run-all.sh static --full-php-lint
#   tests/bin/run-all.sh pure
#   tests/bin/run-all.sh pure money-to-cent
#   tests/bin/run-all.sh smoke
#   tests/bin/run-all.sh smoke orders # focused substring filter
#   tests/bin/run-all.sh permissions
#   tests/bin/run-all.sh permissions upload-editor-file
#   tests/bin/run-all.sh integration
#   tests/bin/run-all.sh integration customer-model-round-trip
#   tests/bin/run-all.sh integration order-model-round-trip
#   tests/bin/run-all.sh integration order-status-state-machine
#   tests/bin/run-all.sh integration shared-activity-discriminator
#   tests/bin/run-all.sh integration shared-label-discriminator
#   tests/bin/run-all.sh integration shared-coupon-meta-discriminator
#   tests/bin/run-all.sh integration shared-product-meta-discriminator
#   tests/bin/run-all.sh integration reports-order-aggregates
#   tests/bin/run-all.sh integration reports-default-sales-aggregates
#   tests/bin/run-all.sh integration reports-dashboard-range-aggregates
#   tests/bin/run-all.sh integration crud-customer-route-round-trip
#   tests/bin/run-all.sh integration crud-order-safe-route-round-trip
#   tests/bin/run-all.sh integration crud-product-safe-route-round-trip
#   tests/bin/run-all.sh integration crud-variant-route-round-trip
#   tests/bin/run-all.sh integration crud-coupon-route-round-trip
#   tests/bin/run-all.sh integration crud-label-supported-routes
#   tests/bin/run-all.sh integration background-schedulers
#   tests/bin/run-all.sh integration background-renewals
#   tests/bin/run-all.sh integration background-system-charge
#   tests/bin/run-all.sh integration background-side-effects
#   tests/bin/run-all.sh integration public-uuid
#   tests/bin/run-all.sh integration throughput
#   tests/bin/run-all.sh all --axis=strict-sql
#   tests/bin/run-all.sh all --axis=non-utc
#   tests/bin/run-all.sh all --axis=pro-absent
#
# The plugin directory is derived from this script. The WordPress root is
# resolved for future WP-CLI tiers without hardcoding a machine path.
# Override only when auto-detection cannot work:
#   export WP_PLUGIN_TEST_ROOT=/path/to/wordpress

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=lib/resolve-wp-root.sh
. "$PLUGIN_DIR/tests/bin/lib/resolve-wp-root.sh"
WP_ROOT="$(wp_plugin_test_resolve_wp_root "$PLUGIN_DIR")" || exit 2
SUITE="${1:-all}"
FILTER=""
FULL_PHP_LINT=0
ENVIRONMENT_AXIS=""
if [ "$#" -gt 1 ]; then
  for argument in "${@:2}"; do
    case "$argument" in
      --full-php-lint)
        FULL_PHP_LINT=1
        ;;
      --axis=strict-sql|--axis=non-utc|--axis=pro-absent)
        if [ -n "$ENVIRONMENT_AXIS" ]; then
          echo "Only one environment axis may be supplied."
          exit 2
        fi
        ENVIRONMENT_AXIS="${argument#--axis=}"
        ;;
      --*)
        echo "Unknown option: $argument"
        exit 2
        ;;
      *)
        if [ -n "$FILTER" ]; then
          echo "Only one suite filter may be supplied."
          exit 2
        fi
        FILTER="$argument"
        ;;
    esac
  done
fi
SMOKE_FILTER="$FILTER"
PERMISSION_FILTER="$FILTER"
INTEGRATION_FILTER="$FILTER"
PURE_FILTER="$FILTER"
WP_TEST_SKIP_PLUGINS=""

case "$ENVIRONMENT_AXIS" in
  strict-sql)
    export WP_PLUGIN_TEST_STRICT_SQL=1
    ;;
  non-utc)
    export WP_PLUGIN_TEST_GMT_OFFSET=-4.5
    ;;
  pro-absent)
    WP_TEST_SKIP_PLUGINS="fluent-cart-pro"
    ;;
esac

RED=$'\033[31m'
GREEN=$'\033[32m'
BOLD=$'\033[1m'
OFF=$'\033[0m'

FAILED=0
declare -a RESULTS=()

hr()
{
  printf '%s\n' "------------------------------------------------------------------------"
}

wp_test()
{
  if [ -n "$WP_TEST_SKIP_PLUGINS" ]; then
    wp --skip-plugins="$WP_TEST_SKIP_PLUGINS" "$@"
  else
    wp "$@"
  fi
}

record()
{
  if [ "$2" -eq 0 ]; then
    RESULTS+=("${GREEN}PASS${OFF}  $1")
  else
    RESULTS+=("${RED}FAIL${OFF}  $1")
    FAILED=1
  fi
}

run_static()
{
  echo "${BOLD}S0 — static gates${OFF}"
  hr

  local static_args=()
  if [ "$FULL_PHP_LINT" -eq 1 ]; then
    static_args+=("full-php-lint=1")
  fi

  local output
  if [ "${#static_args[@]}" -gt 0 ]; then
    output=$(
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-static.php" -- \
        "${static_args[@]}" 2>&1
    )
  else
    output=$(
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-static.php" 2>&1
    )
  fi
  local code=$?
  echo "$output"

  if [ "$code" -eq 97 ] || grep -Fq 'FC_PROTECTED_FAILURE=1' <<<"$output"; then
    echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
    exit 97
  fi

  local marker
  local label
  for marker in \
    "php-l|php -l" \
    "raw-sql-prefix|lint: raw-sql-prefix" \
    "route-coverage|lint: route-coverage" \
    "permission-inventory|lint: permission-inventory" \
    "name-mode-forms|lint: name-mode-forms" \
    "translation-map-integrity|lint: translation-map-integrity" \
    "lint-self-test|lint self-test"
  do
    label="${marker#*|}"
    marker="${marker%%|*}"
    if grep -Fq "FC_STATIC_RESULT $marker=0" <<<"$output"; then
      record "$label" 0
    else
      record "$label" 1
    fi
  done

  echo
}

run_smoke()
{
  echo "${BOLD}S1 — REST smoke${OFF}"
  hr

  if [ -n "$SMOKE_FILTER" ]; then
    (
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-smoke.php" -- "filter=$SMOKE_FILTER" 2>&1
    )
  else
    (
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-smoke.php" 2>&1
    )
  fi
  local code=$?

  if [ "$code" -eq 97 ]; then
    echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
    exit 97
  fi
  record "S1 — REST smoke" "$code"
  echo
}

run_pure()
{
  echo "${BOLD}Phases 12/13/22/24/30 — pure functions, providers, and mutation kills${OFF}"
  hr

  if [ -n "$PURE_FILTER" ]; then
    (
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-pure.php" -- \
        "filter=$PURE_FILTER" 2>&1
    )
  else
    (
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-pure.php" 2>&1
    )
  fi
  local code=$?

  if [ "$code" -eq 97 ]; then
    echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
    exit 97
  fi

  record "Phases 12/13/22/24/30 — pure functions, providers, and mutation kills" "$code"
  echo
}

run_permissions()
{
  echo "${BOLD}S3 — permission smoke${OFF}"
  hr

  if [ -n "$PERMISSION_FILTER" ]; then
    (
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-permissions.php" -- \
        "filter=$PERMISSION_FILTER" 2>&1
    )
  else
    (
      cd "$WP_ROOT" || exit 2
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-permissions.php" 2>&1
    )
  fi
  local code=$?

  if [ "$code" -eq 97 ]; then
    echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
    exit 97
  fi

  record "S3 — permission smoke" "$code"
  echo
}

run_integration()
{
  echo "${BOLD}Phases 16/18/22/23/24/29/30 — integration, commerce money, webhooks, and mutation kills${OFF}"
  hr

  local fixture_identity
  fixture_identity="wp-plugin-phase16-$(date +%s)-$$-${RANDOM}@example.invalid"

  if [ -n "$INTEGRATION_FILTER" ]; then
    (
      cd "$WP_ROOT" || exit 2
      WP_PLUGIN_TEST_FIXTURE_IDENTITY="$fixture_identity" \
        wp_test eval-file "$PLUGIN_DIR/tests/bin/run-integration.php" -- \
        "filter=$INTEGRATION_FILTER" 2>&1
    )
  else
    (
      cd "$WP_ROOT" || exit 2
      WP_PLUGIN_TEST_FIXTURE_IDENTITY="$fixture_identity" \
        wp_test eval-file "$PLUGIN_DIR/tests/bin/run-integration.php" 2>&1
    )
  fi
  local code=$?

  if [ "$code" -eq 97 ]; then
    echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
    exit 97
  fi

  record "Phases 16/18/22/23/24/29/30 — integration, commerce money, webhooks, and mutation kills" "$code"
  echo
}

if [ ! -d "$WP_ROOT" ]; then
  echo "${RED}WordPress root not found: $WP_ROOT${OFF}"
  echo "Set it with: export WP_PLUGIN_TEST_ROOT=/path/to/wordpress"
  exit 2
fi

echo
echo "${BOLD}Local plugin test run${OFF}  (suite: $SUITE)"
echo "plugin: $PLUGIN_DIR"
echo "wp:     $WP_ROOT"
if [ -n "$ENVIRONMENT_AXIS" ]; then
  echo "axis:   $ENVIRONMENT_AXIS (opt-in; default gate unchanged)"
fi
echo

if [ -n "$ENVIRONMENT_AXIS" ]; then
  echo "${BOLD}Phase 20 — $ENVIRONMENT_AXIS preflight${OFF}"
  hr
  (
    cd "$WP_ROOT" || exit 2
    WP_PLUGIN_TEST_ENVIRONMENT_AXIS="$ENVIRONMENT_AXIS" \
      wp_test eval-file "$PLUGIN_DIR/tests/bin/run-environment.php" 2>&1
  )
  environment_code=$?
  if [ "$environment_code" -eq 97 ]; then
    echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
    exit 97
  fi
  record "Phase 20 — $ENVIRONMENT_AXIS preflight" "$environment_code"
  echo
fi

case "$SUITE" in
  static)
    run_static
    ;;
  pure)
    run_pure
    ;;
  smoke)
    run_smoke
    ;;
  permissions)
    run_permissions
    ;;
  integration)
    run_integration
    ;;
  all)
    run_static
    run_pure
    run_smoke
    run_permissions
    run_integration
    ;;
  *)
    echo "Unknown or not-yet-installed suite: $SUITE (through Phase 20 supports: static|pure|smoke|permissions|integration|all)"
    exit 2
    ;;
esac

hr
echo "${BOLD}SUMMARY${OFF}"
for result in "${RESULTS[@]}"; do
  echo "  $result"
done
hr

exit "$FAILED"

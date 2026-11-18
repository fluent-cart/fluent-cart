#!/usr/bin/env bash
#
# Local WPFluent test runner, adapted from the wp-plugin-test-suite asset.
#
# The default PHP gate includes Phase 22 provider, Phase 23 commerce, and Phase
# 24 mutation-closure coverage alongside earlier tiers. Phase 25 Vitest, Phase
# 26 browser smoke, and Phase 27 E2E money flows remain standalone and opt-in.
#
# Usage:
#   tests/wp-cli/bin/run-all.sh              # static + pure + REST smoke + permissions + integration
#   tests/wp-cli/bin/run-all.sh static
#   tests/wp-cli/bin/run-all.sh static --full-php-lint
#   tests/wp-cli/bin/run-all.sh pure
#   tests/wp-cli/bin/run-all.sh pure money-to-cent
#   tests/wp-cli/bin/run-all.sh smoke
#   tests/wp-cli/bin/run-all.sh smoke orders # focused substring filter
#   tests/wp-cli/bin/run-all.sh permissions
#   tests/wp-cli/bin/run-all.sh permissions upload-editor-file
#   tests/wp-cli/bin/run-all.sh integration
#   tests/wp-cli/bin/run-all.sh integration customer-model-round-trip
#   tests/wp-cli/bin/run-all.sh integration order-model-round-trip
#   tests/wp-cli/bin/run-all.sh integration shared-label-discriminator
#   tests/wp-cli/bin/run-all.sh integration shared-product-meta-discriminator
#   tests/wp-cli/bin/run-all.sh integration reports-default-sales-aggregates
#   tests/wp-cli/bin/run-all.sh integration reports-dashboard-range-aggregates
#   tests/wp-cli/bin/run-all.sh integration crud-customer-route-round-trip
#   tests/wp-cli/bin/run-all.sh integration crud-label-supported-routes
#   tests/wp-cli/bin/run-all.sh integration background-schedulers
#   tests/wp-cli/bin/run-all.sh integration background-renewals
#   tests/wp-cli/bin/run-all.sh integration background-system-charge
#   tests/wp-cli/bin/run-all.sh integration background-side-effects
#   tests/wp-cli/bin/run-all.sh integration public-uuid
#   tests/wp-cli/bin/run-all.sh integration throughput
#   tests/wp-cli/bin/run-all.sh all --axis=strict-sql
#   tests/wp-cli/bin/run-all.sh all --axis=non-utc
#   tests/wp-cli/bin/run-all.sh all --axis=pro-absent
#
# A passing tier prints one summary line and this script prints one total line.
# A failing tier prints its context lines and the failing cases before its summary.
#
# The plugin directory is derived from this script. The WordPress root is
# resolved for future WP-CLI tiers without hardcoding a machine path.
# Override only when auto-detection cannot work:
#   export WP_PLUGIN_TEST_ROOT=/path/to/wordpress

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
# shellcheck source=lib/resolve-wp-root.sh
. "$PLUGIN_DIR/tests/wp-cli/bin/lib/resolve-wp-root.sh"
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
WP_TEST_SKIP_PLUGINS=""

# Every tier reads the axis, so Pro-only cases are not registered under pro-absent.
export WP_PLUGIN_TEST_ENVIRONMENT_AXIS="$ENVIRONMENT_AXIS"

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
OFF=$'\033[0m'

PASSED=0
FAILED=0

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
  if [ "$1" -eq 0 ]; then
    PASSED=$((PASSED + 1))
  else
    FAILED=$((FAILED + 1))
  fi
}

# A tier that exits 97 saw a protected-table count change.
hard_failure()
{
  echo "${RED}HARD FAILURE — protected-table count changed${OFF}"
  exit 97
}

run_static()
{
  local static_args=()
  # run-static.php writes one key=0|1 line per lint here, not to stdout.
  local result_file
  result_file="$(mktemp)"
  static_args+=("result-file=$result_file")
  if [ "$FULL_PHP_LINT" -eq 1 ]; then
    static_args+=("full-php-lint=1")
  fi

  (
    cd "$WP_ROOT" || exit 2
    wp_test eval-file "$PLUGIN_DIR/tests/wp-cli/bin/run-static.php" -- \
      "${static_args[@]}" 2>&1
  )
  local code=$?

  if [ "$code" -eq 97 ] || grep -Fxq 'protected_failure=1' "$result_file"; then
    rm -f "$result_file"
    hard_failure
  fi

  # A lint missing from the file did not run, which counts as a failure.
  local lint
  for lint in \
    php-l \
    raw-sql-prefix \
    route-coverage \
    permission-inventory \
    name-mode-forms \
    translation-map-integrity \
    state-translation-contexts \
    localized-dates \
    no-skip \
    review-layout-preset-contract \
    lint-self-test
  do
    if ! grep -Fxq "$lint=0" "$result_file"; then
      echo "static lint did not pass: $lint"
      code=1
    fi
  done
  rm -f "$result_file"

  record "$code"
}

# Run one runner script in the WordPress root. The runner prints its own summary
# line, and exit code 97 means a protected-table count changed.
run_tier()
{
  local script="$1"
  shift
  (
    cd "$WP_ROOT" || exit 2
    wp_test eval-file "$PLUGIN_DIR/tests/wp-cli/bin/$script" "$@" 2>&1
  )
  local code=$?

  if [ "$code" -eq 97 ]; then
    hard_failure
  fi
  record "$code"
}

# Pass the filter to the runner when one was given.
run_filtered_tier()
{
  local script="$1"
  if [ -n "$FILTER" ]; then
    run_tier "$script" -- "filter=$FILTER"
  else
    run_tier "$script"
  fi
}

run_integration()
{
  local fixture_identity
  fixture_identity="wp-plugin-phase16-$(date +%s)-$$-${RANDOM}@example.invalid"
  export WP_PLUGIN_TEST_FIXTURE_IDENTITY="$fixture_identity"
  run_filtered_tier run-integration.php
  unset WP_PLUGIN_TEST_FIXTURE_IDENTITY
}

if [ ! -d "$WP_ROOT" ]; then
  echo "${RED}WordPress root not found: $WP_ROOT${OFF}"
  echo "Set it with: export WP_PLUGIN_TEST_ROOT=/path/to/wordpress"
  exit 2
fi

if [ -n "$ENVIRONMENT_AXIS" ]; then
  run_tier run-environment.php
fi

case "$SUITE" in
  static)
    run_static
    ;;
  pure)
    run_filtered_tier run-pure.php
    ;;
  smoke)
    run_filtered_tier run-smoke.php
    ;;
  permissions)
    run_filtered_tier run-permissions.php
    ;;
  integration)
    run_integration
    ;;
  all)
    run_static
    run_filtered_tier run-pure.php
    run_filtered_tier run-smoke.php
    run_filtered_tier run-permissions.php
    run_integration
    ;;
  *)
    echo "Unknown or not-yet-installed suite: $SUITE (through Phase 20 supports: static|pure|smoke|permissions|integration|all)"
    exit 2
    ;;
esac

echo "GATE${ENVIRONMENT_AXIS:+ ($ENVIRONMENT_AXIS)}: $PASSED tiers passed, $FAILED failed  (${SECONDS}s)"

[ "$FAILED" -eq 0 ]

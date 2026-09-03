#!/usr/bin/env bash
#
# Opt-in Phase 27 E2E money-flow tier.
#
# Usage:
#   tests/bin/run-e2e.sh
#   tests/bin/run-e2e.sh guest-checkout-tax-stock  # focused development only

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=lib/resolve-wp-root.sh
. "$PLUGIN_DIR/tests/bin/lib/resolve-wp-root.sh"
WP_ROOT="$(wp_plugin_test_resolve_wp_root "$PLUGIN_DIR")" || exit 2

ALL_FLOWS=(
  "guest-checkout-tax-stock"
  "coupon-checkout-discount"
  "admin-refund-money-stock-idempotent"
)
SELECTED_FLOWS=("${ALL_FLOWS[@]}")
FOCUSED=0

if [ "$#" -gt 1 ]; then
  echo "Usage: tests/bin/run-e2e.sh [flow-id]"
  exit 2
fi
if [ "$#" -eq 1 ]; then
  FOCUSED=1
  SELECTED_FLOWS=("$1")
  valid=0
  for flow in "${ALL_FLOWS[@]}"; do
    if [ "$flow" = "$1" ]; then
      valid=1
      break
    fi
  done
  if [ "$valid" -ne 1 ]; then
    echo "Unknown Phase 27 flow: $1"
    exit 2
  fi
fi

protected_counts()
{
  (
    cd "$WP_ROOT" || exit 2
    wp eval-file "$PLUGIN_DIR/tests/bin/protected-counts.php" 2>&1
  )
}

start_output="$(protected_counts)"
start_code=$?
echo "$start_output"
if [ "$start_code" -ne 0 ]; then
  exit "$start_code"
fi
start_counts="$(sed -n 's/^protected counts: //p' <<<"$start_output")"
if [ -z "$start_counts" ]; then
  echo "Phase 27 could not parse protected run-start counts."
  exit 2
fi

fixture_identity="wp-plugin-phase27-$(date +%s)-$$-${RANDOM}@example.invalid"
failed=0
passed=0
safety_lines=0

echo
echo "Phase 27 E2E money flows"
echo "wp:       $WP_ROOT"
echo "identity: $fixture_identity"
if [ "$FOCUSED" -eq 1 ]; then
  echo "mode:     focused development run; not full-tier gate evidence"
else
  echo "mode:     full tier"
fi
echo

for flow in "${SELECTED_FLOWS[@]}"; do
  echo "FLOW $flow"
  output=$(
    cd "$WP_ROOT" || exit 2
    WP_PLUGIN_TEST_FIXTURE_IDENTITY="$fixture_identity" \
      wp eval-file "$PLUGIN_DIR/tests/bin/run-e2e.php" -- "flow=$flow" 2>&1
  )
  code=$?
  echo "$output"

  if [ "$code" -eq 97 ]; then
    echo "HARD FAILURE — protected-table count changed inside $flow"
    failed=1
    break
  fi
  if [ "$code" -eq 0 ]; then
    passed=$((passed + 1))
  else
    failed=1
  fi
  if grep -Fq \
    "safety guards: outbound_http=0 mail=0 cron=0 action_scheduler=0 provider_requests=0" \
    <<<"$output"; then
    safety_lines=$((safety_lines + 1))
  else
    failed=1
    echo "FLOW $flow did not emit the exact zero-transport safety summary."
  fi
  echo
done

end_output="$(protected_counts)"
end_code=$?
echo "$end_output"
if [ "$end_code" -ne 0 ]; then
  exit "$end_code"
fi
end_counts="$(sed -n 's/^protected counts: //p' <<<"$end_output")"
if [ "$start_counts" != "$end_counts" ]; then
  echo "HARD FAILURE — protected run counts changed: start=$start_counts end=$end_counts"
  exit 97
fi

expected="${#SELECTED_FLOWS[@]}"
if [ "$safety_lines" -ne "$expected" ]; then
  failed=1
fi

echo
echo "Phase 27 result: flows=$passed/$expected protected_start=$start_counts protected_end=$end_counts"
echo "Phase 27 safety: outbound_http=0 mail=0 cron=0 action_scheduler=0 provider_requests=0"
if [ "$FOCUSED" -eq 1 ]; then
  echo "Focused result is not full-tier gate evidence."
fi

exit "$failed"

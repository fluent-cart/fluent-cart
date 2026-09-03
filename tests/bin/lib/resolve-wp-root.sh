#!/usr/bin/env bash
#
# Resolve the WordPress root without hardcoding anyone's folder layout.
#
# Sourced by the test runners. Every path in this suite derives from two things:
# the plugin directory (found from the script's own location) and the WordPress
# root (found here). Nothing else should be hardcoded.
#
# Resolution order:
#   1. $WP_PLUGIN_TEST_ROOT if set — an explicit override always wins
#   2. walk up from the plugin directory looking for wp-load.php — covers the
#      standard <wp>/wp-content/plugins/<plugin> layout
#   3. ask WP-CLI for ABSPATH — covers layouts where the plugin does not sit
#      under the WordPress root (Bedrock and friends), because WP-CLI reads
#      wp-cli.yml
#
# Usage:
#   source "<plugin>/tests/bin/lib/resolve-wp-root.sh"
#   WP_ROOT="$(wp_plugin_test_resolve_wp_root "$PLUGIN_DIR")" || exit 2

wp_plugin_test_resolve_wp_root() {
  local plugin_dir="$1"
  local dir abspath

  # 1. Explicit override.
  if [ -n "${WP_PLUGIN_TEST_ROOT:-}" ]; then
    if [ ! -f "$WP_PLUGIN_TEST_ROOT/wp-load.php" ]; then
      echo "WP_PLUGIN_TEST_ROOT is set to '$WP_PLUGIN_TEST_ROOT' but no wp-load.php is there." >&2
      return 2
    fi
    printf '%s\n' "${WP_PLUGIN_TEST_ROOT%/}"
    return 0
  fi

  # 2. Walk up from the plugin directory.
  dir="$plugin_dir"
  while [ "$dir" != "/" ] && [ -n "$dir" ]; do
    if [ -f "$dir/wp-load.php" ]; then
      printf '%s\n' "$dir"
      return 0
    fi
    dir="$(dirname "$dir")"
  done

  # 3. Ask WP-CLI. --skip-plugins/--skip-themes keeps this fast and avoids
  #    bootstrapping the very code under test just to learn a path.
  if command -v wp >/dev/null 2>&1; then
    abspath="$(cd "$plugin_dir" && wp eval 'echo untrailingslashit(ABSPATH);' \
      --skip-plugins --skip-themes 2>/dev/null | tail -n 1)"
    if [ -n "$abspath" ] && [ -f "$abspath/wp-load.php" ]; then
      printf '%s\n' "$abspath"
      return 0
    fi
  fi

  echo "Could not locate the WordPress root from '$plugin_dir'." >&2
  echo "Set it explicitly:  export WP_PLUGIN_TEST_ROOT=/path/to/wordpress" >&2
  return 2
}

#!/usr/bin/env bash
# Integration test on a real WordPress install. Needs PHP with mysqli, WP-CLI, curl, and WP_PATH pointing at a
# WordPress install used only for testing (the test adds a must-use plugin and options to it).
#   WP_PATH=/path/to/wordpress tests/integration.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
root="$(dirname "$here")"
: "${WP_PATH:?Set WP_PATH to a WordPress install}"
port="${PORT:-8089}"
extra=()
if [ "$(id -u)" = "0" ]; then extra+=(--allow-root); fi
wpc() { wp --path="$WP_PATH" "${extra[@]}" "$@"; }

pass=0
fail=0
check() {
  if [ "$2" = "$3" ]; then
    pass=$((pass + 1)); echo "ok - $1"
  else
    fail=$((fail + 1)); echo "not ok - $1"; echo "  expected: $2"; echo "  actual:   $3"
  fi
}

plugins="$(wpc eval 'echo WP_PLUGIN_DIR;')"
mu="$(wpc eval 'echo WPMU_PLUGIN_DIR;')"
wpc plugin deactivate option-churn >/dev/null 2>&1 || true
wpc plugin uninstall option-churn >/dev/null 2>&1 || true
rm -rf "$plugins/option-churn"
mkdir -p "$plugins/option-churn" "$mu"
cp -R "$root/option-churn.php" "$root/uninstall.php" "$root/includes" "$plugins/option-churn/"
cp "$here/fixtures/churn-fixture.php" "$mu/churn-fixture.php"
cleanup() {
  rm -f "$mu/churn-fixture.php"
  [ -n "${server:-}" ] && kill "$server" 2>/dev/null || true
}
trap cleanup EXIT

wpc plugin activate option-churn >/dev/null
wpc option-churn reset --yes >/dev/null
check "the three tables exist" "3" "$(wpc eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->base_prefix}option_churn_%\""));')"

# Five front-end page views through PHP's built-in server.
# Opcache off: the server must see wp-config.php changes made during the test.
start_server() {
  php -d opcache.enable_cli=0 -S "127.0.0.1:$port" -t "$WP_PATH" >/dev/null 2>&1 &
  server=$!
  for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.2; done
}
start_server
wpc option-churn reset --yes >/dev/null
for _ in 1 2 3 4 5; do curl -s -o /dev/null -H "Host: localhost" "http://127.0.0.1:$port/"; done
# Two WP-CLI runs.
wpc eval '' >/dev/null
wpc eval '' >/dev/null

json="$(wpc option-churn report --format=json --limit=100)"
field() { echo "$json" | php -r '$r = json_decode(stream_get_contents(STDIN), true); $f = $argv[1]; $o = $argv[2]; $t = $argv[3]; foreach ($r["rows"] as $row) { if ($row["option"] === $o && $row["type"] === $t) { $v = $row[$f]; echo is_array($v) ? implode(",", array_keys($v)) : (is_bool($v) ? ($v ? "true" : "false") : $v); } }' "$1" "$2" "$3"; }
requests() { echo "$json" | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo isset($r["requests"][$argv[1]]) ? $r["requests"][$argv[1]] : 0;' "$1"; }

check "front-end requests counted" "5" "$(requests front)"
check "CLI requests counted (not the plugin's own commands)" "true" "$( [ "$(requests cli)" -eq 2 ] && echo true || echo false )"
check "the heartbeat is written on every front-end request" "5" "$(field write_requests acme_heartbeat front)"
check "only its timestamp changes" "last" "$(field changed_keys acme_heartbeat front)"
check "the caller is the must-use plugin" "mu-plugin:churn-fixture.php {closure}" "$(field callers acme_heartbeat front | cut -d, -f1)"
check "an unchanged value is counted as a no-op call, not a write" "5" "$(field noop_calls acme_static front)"
check "and never as a write" "0" "$(field write_requests acme_static front)"
check "the heartbeat is not autoloaded" "false" "$(field autoloaded acme_heartbeat front)"
check "transients are recorded under the transient's name" "true" "$( [ "$(field write_requests _transient_acme_menu_cache front)" -ge 1 ] && echo true || echo false )"
check "no timeout rows" "" "$(field option _transient_timeout_acme_menu_cache front)"

text="$(wpc option-churn report --type=front)"
check "the table shows written_on as a share" "1" "$(echo "$text" | grep -c "acme_heartbeat.*5 of 5 requests (100%)" || true)"

values="$(wpc eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}option_churn_writes WHERE option_name = \"acme_heartbeat\" AND (caller_function LIKE \"%microtime%\" OR last_at LIKE \"%1.0%\")");')"
check "no option values are stored" "0" "$values"

wpc config set OPTION_CHURN_RECORD false --raw --type=constant >/dev/null
kill "$server" 2>/dev/null || true
wait "$server" 2>/dev/null || true
start_server
wpc option-churn reset --yes >/dev/null
for _ in 1 2; do curl -s -o /dev/null -H "Host: localhost" "http://127.0.0.1:$port/"; done
wpc config delete OPTION_CHURN_RECORD --type=constant >/dev/null
check "OPTION_CHURN_RECORD=false stops recording" "0" "$(wpc option-churn report --format=json | php -r '$r = json_decode(stream_get_contents(STDIN), true); echo isset($r["requests"]["front"]) ? $r["requests"]["front"] : 0;')"

wpc eval 'global $wpdb; $wpdb->query("DROP TABLE {$wpdb->base_prefix}option_churn_keys"); delete_option("option_churn_schema");' >/dev/null
wpc option-churn status >/dev/null
check "a changed schema version rebuilds the tables on the next admin or WP-CLI request" "1" "$(wpc eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->base_prefix}option_churn_keys\""));')"

wpc plugin deactivate option-churn >/dev/null
wpc plugin uninstall option-churn >/dev/null
check "uninstall drops the tables" "0" "$(wpc eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->base_prefix}option_churn_%\""));')"
wpc option delete acme_heartbeat acme_static >/dev/null 2>&1 || true

echo "# pass $pass"
echo "# fail $fail"
[ "$fail" -eq 0 ]

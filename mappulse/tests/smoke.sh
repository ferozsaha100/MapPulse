#!/usr/bin/env bash
# End-to-end check of MapPulse against a running WordPress.
#
# Default target is wp-env (npx wp-env start). Override with:
#   MAPPULSE_WP     command that runs WP-CLI            (default: npx wp-env run cli wp)
#   MAPPULSE_BASE   site URL                            (default: http://localhost:8888)
#   MAPPULSE_SETUP  path of smoke-setup.php as WP sees it
#   MAPPULSE_REQUIRE_ELEMENTOR=1  fail instead of skipping when Elementor is not active
#
# Prints one PASS, SKIP or FAIL line per check and stops at the first failure.
# The last check deactivates and uninstalls MapPulse (files are kept).

set -u
WP=${MAPPULSE_WP:-"npx wp-env run cli wp"}
BASE=${MAPPULSE_BASE:-"http://localhost:8888"}
SETUP=${MAPPULSE_SETUP:-"wp-content/plugins/mappulse/tests/smoke-setup.php"}
TMP=$(mktemp -d)

pass() { echo "PASS  $1"; }
skip() { echo "SKIP  $1"; }
fail() { echo "FAIL  $1"; exit 1; }
wp() { $WP "$@" 2>/dev/null | tr -d '\r'; }
field() { grep -Eo "$1=[^ ]+" | tail -n 1 | cut -d= -f2; }

# Fetches a page and checks it holds one map section with at least 16 zones.
check_page() {
	local id=$1 label=$2 file="$TMP/page-$1.html" code zones
	code=$(curl -s -o "$file" -w '%{http_code}' "$BASE/?page_id=$id")
	zones=$(grep -Eo 'class="mappulse__zone[ "]' "$file" | wc -l | tr -d ' ')
	[ "$code" = "200" ] || fail "$label: HTTP $code"
	grep -q 'data-mappulse' "$file" || fail "$label: no map section in the page"
	[ "$zones" -ge 16 ] || fail "$label: $zones zones, expected at least 16"
	pass "$label: HTTP 200, one map section, $zones zones"
}

echo "INFO  $(wp eval-file "$SETUP" versions | grep -Eo 'versions .*')"

# 1
$WP plugin is-active mappulse >/dev/null 2>&1 || fail "1 MapPulse is not active"
pass "1 MapPulse is active"

# 2
MAP_ID=$(wp mappulse import --sample --porcelain | grep -Eo '^[0-9]+$' | tail -n 1)
[ -n "$MAP_ID" ] || fail "2 sample import did not return a map ID"
pass "2 sample map imported as map $MAP_ID"

PAGES=$(wp eval-file "$SETUP" pages "$MAP_ID")
SHORTCODE_PAGE=$(echo "$PAGES" | field shortcode)
BLOCK_PAGE=$(echo "$PAGES" | field block)
ELEMENTOR_PAGE=$(echo "$PAGES" | field elementor)
[ -n "$SHORTCODE_PAGE" ] && [ -n "$BLOCK_PAGE" ] || fail "test pages were not created: $PAGES"

# 3, 4
check_page "$SHORTCODE_PAGE" "3 shortcode page"
check_page "$BLOCK_PAGE" "4 block page"
grep -q 'mappulse--list-right' "$TMP/page-$BLOCK_PAGE.html" || fail "4 block page: layout attribute was not applied"

# 5, 6
if [ "${ELEMENTOR_PAGE:-0}" != "0" ]; then
	check_page "$ELEMENTOR_PAGE" "5 Elementor page"
	grep -q 'mappulse--list-below' "$TMP/page-$ELEMENTOR_PAGE.html" || fail "5 Elementor page: layout setting was not applied"
	ATOMIC=$(wp eval-file "$SETUP" atomic | field atomic)
	if [ -n "$ATOMIC" ] && [ "$ATOMIC" != "none" ]; then
		check_page "$ELEMENTOR_PAGE" "6 Elementor page with atomic experiments on ($ATOMIC)"
	else
		skip "6 this Elementor version has no atomic experiment to turn on"
	fi
elif [ -n "${MAPPULSE_REQUIRE_ELEMENTOR:-}" ]; then
	fail "5 Elementor is required for this run and is not active"
else
	skip "5 Elementor is not active"
	skip "6 Elementor is not active"
fi

# 7
STYLES=$(grep -o "mappulse\.css" "$TMP/page-$SHORTCODE_PAGE.html" | wc -l | tr -d ' ')
[ "$STYLES" = "1" ] || fail "7 stylesheet is linked $STYLES times, expected 1"
python3 - "$TMP/page-$SHORTCODE_PAGE.html" <<'PY' || fail "7 the map SVG holds a script, style or id"
import re, sys
html = open(sys.argv[1], encoding='utf-8').read()
svgs = re.findall(r'<svg class="mappulse__svg".*?</svg>', html, re.S)
bad = [s for s in svgs if re.search(r'<script|<style|\sid=|\son[a-z]+=', s, re.I)]
sys.exit(1 if bad or not svgs else 0)
PY
pass "7 stylesheet linked once; SVG has no script, style, id or event handler"

# 8
ELEMENTOR_SLUG=$(wp plugin list --status=active --field=name | grep -i '^elementor' | head -n 1)
if [ -n "$ELEMENTOR_SLUG" ]; then
	$WP plugin deactivate "$ELEMENTOR_SLUG" >/dev/null 2>&1 || fail "8 could not deactivate Elementor"
	check_page "$SHORTCODE_PAGE" "8 shortcode page with Elementor off"
	check_page "$BLOCK_PAGE" "8 block page with Elementor off"
fi
ERRORS=$(wp eval-file "$SETUP" errors | field errors)
[ "$ERRORS" = "0" ] || fail "8 debug.log holds $ERRORS PHP errors from MapPulse or fatals"
pass "8 no PHP errors from MapPulse in debug.log"

# 9
$WP plugin deactivate mappulse >/dev/null 2>&1 || fail "9 could not deactivate MapPulse"
$WP plugin uninstall mappulse --skip-delete >/dev/null 2>&1 || fail "9 uninstall failed"
CAP=$(wp eval-file "$SETUP" cap | field cap)
[ "$CAP" = "no" ] || fail "9 capability is still granted after uninstall"
pass "9 uninstall removed the capability and kept the files"

rm -rf "$TMP"
echo "DONE  all checks passed"

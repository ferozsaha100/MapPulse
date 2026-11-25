#!/usr/bin/env bash
# End-to-end check of MapPulse against a running WordPress.
#
# Default target is wp-env (npx wp-env start). Override with:
#   MAPPULSE_WP     command that runs WP-CLI            (default: npx wp-env run cli wp)
#   MAPPULSE_BASE   site URL                            (default: http://localhost:8888)
#   MAPPULSE_SETUP  path of smoke-setup.php as WP sees it
#   MAPPULSE_REQUIRE_ELEMENTOR=1  fail instead of skipping when Elementor is not active
#   MAPPULSE_LIVE=1               also ask the real boundary sources (needs outbound network)
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
	# -L: a site with pretty permalinks redirects ?page_id=N to the page's own URL.
	code=$(curl -sL -o "$file" -w '%{http_code}' "$BASE/?page_id=$id")
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

	# The widget's hover color control must reach the page as a CSS variable, inline or in Elementor's CSS file.
	CSS_OK=0
	grep -qi -- '--mappulse-zone-hover: *#7A1F1F' "$TMP/page-$ELEMENTOR_PAGE.html" && CSS_OK=1
	if [ "$CSS_OK" = "0" ]; then
		for href in $(grep -Eo "https?://[^\"' ]*elementor/css/post-$ELEMENTOR_PAGE\.css[^\"' ]*" "$TMP/page-$ELEMENTOR_PAGE.html" | sed 's/&#038;/\&/g; s/&amp;/\&/g' | sort -u); do
			curl -sL "$href" | grep -qi -- '--mappulse-zone-hover: *#7A1F1F' && CSS_OK=1
		done
	fi
	[ "$CSS_OK" = "1" ] || fail "5 Elementor page: the hover color control did not produce --mappulse-zone-hover"
	pass "5 Elementor color control reaches the page as --mappulse-zone-hover"
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

# 10 to 13: the real boundary sources
if [ "${MAPPULSE_LIVE:-}" = "1" ]; then
	FOUND=$(wp mappulse find gulfport --state=FL --type=city --format=json)
	RECORD=$(echo "$FOUND" | grep -Eo '"record":"place:12[0-9]+"' | head -n 1 | cut -d'"' -f4)
	if ! echo "$FOUND" | grep -q '"name":"Gulfport"' || [ -z "$RECORD" ]; then
		fail "10 live search did not find Gulfport, FL: $(echo "$FOUND" | tail -n 2 | cut -c1-300)"
	fi
	pass "10 live search found Gulfport, FL as $RECORD"

	SHAPE=$(wp mappulse shape census "$RECORD")
	POINTS=$(echo "$SHAPE" | grep -Eo '^points: [0-9]+' | grep -Eo '[0-9]+')
	WEST=$(echo "$SHAPE" | grep -Eo '^bbox: -?[0-9.]+' | grep -Eo -- '-?[0-9.]+$')
	[ "${POINTS:-0}" -ge 100 ] || fail "11 live shape has ${POINTS:-no} points, expected at least 100"
	python3 -c "import sys; sys.exit(0 if -82.8 < float(sys.argv[1]) < -82.6 else 1)" "${WEST:-0}" || fail "11 live shape is not where Gulfport is (west edge ${WEST:-unknown})"
	pass "11 live shape: $POINTS points, west edge $WEST"

	$WP mappulse sources add "https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22" --label=StPeteNeighborhoods --parent=StPetersburgFL >/dev/null 2>&1 || fail "12 could not register the St. Petersburg neighborhood layer"
	HOODS=$(wp mappulse find kenwood --type=neighborhood --format=json)
	echo "$HOODS" | grep -qi 'kenwood' || fail "12 live neighborhood search found no Kenwood: $(echo "$HOODS" | tail -n 2 | cut -c1-300)"
	pass "12 registered a city layer and found $(echo "$HOODS" | grep -Eo '"name":"[^"]*"' | wc -l | tr -d ' ') Kenwood neighborhoods"

	LAYERS=$(wp eval-file "$SETUP" base "-82.75,27.72,-82.60,27.82" | grep -Eo 'base .*')
	[ "$(echo "$LAYERS" | field status)" = "200" ] || fail "13 live base layers: $LAYERS"
	[ "$(echo "$LAYERS" | field water)" -ge 1 ] && [ "$(echo "$LAYERS" | field roadsMajor)" -ge 1 ] || fail "13 live base layers are missing water or major roads: $LAYERS"
	pass "13 live $LAYERS"

	ISLAND=$(wp mappulse find fisher --state=FL --type=city --format=json)
	echo "$ISLAND" | grep -q '"name":"Fisher Island"' && echo "$ISLAND" | grep -q '"record":"cdp:12' || fail "14 live search did not find Fisher Island, FL as an unincorporated place: $(echo "$ISLAND" | tail -n 2 | cut -c1-300)"
	pass "14 live search found Fisher Island, FL ($(echo "$ISLAND" | grep -Eo '"record":"cdp:12[0-9]+"' | head -n 1 | cut -d'"' -f4))"
else
	skip "10 to 14 live boundary sources (set MAPPULSE_LIVE=1)"
fi

# 15 The builder is compiled and its routes answer.
BUILT=$(wp eval 'echo ( is_readable( MAPPULSE_DIR . "build/builder.js" ) && is_readable( MAPPULSE_DIR . "build/builder.asset.php" ) ) ? "built=yes" : "built=no"; echo " routes=" . count( array_filter( array_keys( rest_get_server()->get_routes() ), static function ( $r ) { return in_array( $r, array( "/mappulse/v1/maps", "/mappulse/v1/render", "/mappulse/v1/links/search", "/mappulse/v1/links/match" ), true ); } ) );')
[ "$(echo "$BUILT" | field built)" = "yes" ] || fail "15 the compiled builder is missing from the plugin folder (run npm run build in mappulse/)"
[ "$(echo "$BUILT" | field routes)" = "4" ] || fail "15 the builder routes are not registered: $BUILT"
pass "15 builder is compiled and its routes are registered"

# 16 A hover card is printed once for its area, and not at all where the placement turns cards off.
CARD=$(wp eval-file "$SETUP" card "$MAP_ID")
[ "$(echo "$CARD" | field saved)" = "1" ] || fail "16 could not add a hover card to map $MAP_ID: $CARD"
curl -sL -o "$TMP/card.html" "$BASE/?page_id=$(echo "$CARD" | field with)"
curl -sL -o "$TMP/nocard.html" "$BASE/?page_id=$(echo "$CARD" | field without)"
WITH=$(grep -Eo 'class="mappulse__card"' "$TMP/card.html" | wc -l | tr -d ' ')
WITHOUT=$(grep -Eo 'class="mappulse__card"' "$TMP/nocard.html" | wc -l | tr -d ' ')
[ "$WITH" = "1" ] || fail "16 page with a hover card holds $WITH cards, expected 1"
grep -q 'Smoke check tagline' "$TMP/card.html" || fail "16 the hover card's text is not in the page"
grep -q 'data-mappulse' "$TMP/nocard.html" || fail "16 the page with cards off has no map section"
[ "$WITHOUT" = "0" ] || fail "16 page with cards=\"no\" holds $WITHOUT cards, expected 0"
pass "16 hover card printed once, and left out with cards=\"no\""

# 9
$WP plugin deactivate mappulse >/dev/null 2>&1 || fail "9 could not deactivate MapPulse"
$WP plugin uninstall mappulse --skip-delete >/dev/null 2>&1 || fail "9 uninstall failed"
CAP=$(wp eval-file "$SETUP" cap | field cap)
[ "$CAP" = "no" ] || fail "9 capability is still granted after uninstall"
pass "9 uninstall removed the capability and kept the files"

rm -rf "$TMP"
echo "DONE  all checks passed"

=== MapPulse ===
Tags: map, real estate, neighborhoods, elementor, block
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clickable, branded market maps for real estate websites, drawn from real boundary data.

== Description ==

MapPulse shows the areas an agent or brokerage serves as one clickable map. Each neighborhood, city or ZIP area can link to its area page or property search, and the map sits on a full base of land, coastline, water and major roads instead of floating shapes.

The map is inline SVG. It needs no map library and no API key, and it makes no third-party request on the front end.

Place a map with any of these:

* The **MapPulse Map** widget in Elementor.
* The **MapPulse Map** block in the block editor.
* The shortcode `[mappulse id="12" layout="list-left"]`.

All three show the same server-rendered output.

This is an early release for testing. Maps are added from a MapPulse map file; the visual map builder comes in a later version. A real sample map of St. Petersburg, Florida is included. Its areas have no links yet: links are set in the map file's `link` fields.

Since 0.2.0, MapPulse, Sources finds the real boundary of any US city, ZIP area or county from the US Census Bureau, and of neighborhoods from a city map layer you register. Boundary answers are saved on your site for 30 days.

== Installation ==

1. Upload `mappulse.zip` under Plugins, Add New, Upload Plugin, and activate it.
2. Go to MapPulse, Import, and choose "Import the sample map" (or upload a `.map.json` file).
3. Add the map to a page with the Elementor widget, the block, or the shortcode shown after the import.

== Frequently Asked Questions ==

= Does it need Elementor? =

No. The block and the shortcode work without Elementor. The widget appears when Elementor is active.

= How do I change the colors? =

In Elementor, use the widget's Style tab. Anywhere else, set the CSS custom properties on the map or a parent element, for example `.mappulse { --mappulse-zone-hover: #7a1f1f; }`. The properties are `--mappulse-zone`, `--mappulse-zone-hover`, `--mappulse-label`, `--mappulse-label-hover`, `--mappulse-water`, `--mappulse-land`, `--mappulse-context`, `--mappulse-road`, `--mappulse-font` and `--mappulse-max-height`.

= Where do the boundaries come from? =

Cities, ZIP areas and counties come from the US Census Bureau (TIGERweb). ZIP areas are Census ZIP Code Tabulation Areas, which follow USPS ZIP codes closely but not exactly. Neighborhoods come from ArcGIS map layers that cities publish; you register a layer's address under MapPulse, Sources. Your site contacts only the Census server and the layers you register, and only from the admin.

= Who can manage maps? =

Administrators. The capability is `manage_mappulse_maps`.

= What happens to my maps if I delete the plugin? =

They are kept in the database.

== Changelog ==

= 0.3.0 =
* New: the map builder (MapPulse, Add New Map). Search an area, check its outline on a live street map, add it, and see the finished map drawn as you go.
* New: clean outline. A place the source draws as separate pieces, such as Fisher Island, FL, becomes one solid shape.
* New: overlapping areas are cut automatically (a neighborhood out of a city, a city out of a ZIP area, a ZIP area out of a county), with a Backdrop option to keep an area whole underneath.
* New: land, water and major roads are drawn around the areas.
* Maps built here use the default colors and their areas are not links yet. Style and links arrive in 0.4.0.

= 0.2.1 =
* City search now also finds communities that are not incorporated cities (census designated places), such as Fisher Island, FL.

= 0.2.0 =
* New Sources screen: find the real boundary of a US city, ZIP area, county or registered neighborhood and see its outline.
* Register a city's neighborhood layer as a boundary source.
* Boundary routes under `mappulse/v1` and `wp mappulse find`, `shape`, `sources` and `cache` commands.
* Import button on the Maps screen, a Shortcode column, and a link from the empty widget notice.
* Uninstalling removes registered layers and saved boundary answers. Maps are kept.

= 0.1.0 =
* First installable version.
* Map storage, with each map's SVG cached and rebuilt when the map changes.
* One renderer behind an Elementor widget, a Gutenberg block and a shortcode.
* Four layouts: list on the left, list on the right, list below, map only.
* Hover and keyboard focus are synced between the map and the area list.
* Map file import, a WP-CLI import command and a bundled sample map.
* The cached SVG is signed with the site's salts and stored documents are cleaned again when read.

=== MapPulse ===
Tags: map, real estate, neighborhoods, elementor, block
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clickable, branded market maps for real estate websites, drawn from real boundary data.

== Description ==

MapPulse shows the areas an agent or brokerage serves as one clickable map. Each neighborhood, city or ZIP area is a real link to its area page or property search, and the map sits on a full base of land, coastline, water and major roads instead of floating shapes.

The map is inline SVG. It needs no map library and no API key, and it makes no third-party request on the front end.

Place a map with any of these:

* The **MapPulse Map** widget in Elementor.
* The **MapPulse Map** block in the block editor.
* The shortcode `[mappulse id="12" layout="list-left"]`.

All three show the same server-rendered output.

Version 0.1.0 is an early release. Maps are added from a MapPulse map file; the visual map builder comes in a later version. A real sample map of St. Petersburg, Florida is included.

== Installation ==

1. Upload `mappulse.zip` under Plugins, Add New, Upload Plugin, and activate it.
2. Go to MapPulse, Import, and choose "Import the sample map" (or upload a `.map.json` file).
3. Add the map to a page with the Elementor widget, the block, or the shortcode shown after the import.

== Frequently Asked Questions ==

= Does it need Elementor? =

No. The block and the shortcode work without Elementor. The widget appears when Elementor is active.

= How do I change the colors? =

In Elementor, use the widget's Style tab. Anywhere else, set the CSS custom properties on the map or a parent element, for example `.mappulse { --mappulse-zone-hover: #7a1f1f; }`. The properties are `--mappulse-zone`, `--mappulse-zone-hover`, `--mappulse-label`, `--mappulse-label-hover`, `--mappulse-water`, `--mappulse-land`, `--mappulse-context`, `--mappulse-road`, `--mappulse-font` and `--mappulse-max-height`.

= Who can manage maps? =

Administrators. The capability is `manage_mappulse_maps`.

= What happens to my maps if I delete the plugin? =

They are kept in the database.

== Changelog ==

= 0.1.0 =
* First installable version.
* Map storage, with each map's SVG cached and rebuilt when the map changes.
* One renderer behind an Elementor widget, a Gutenberg block and a shortcode.
* Four layouts: list on the left, list on the right, list below, map only.
* Hover and keyboard focus are synced between the map and the area list.
* Map file import, a WP-CLI import command and a bundled sample map.

# Boundary data sources

Every shape on a MapPulse map comes from a named source, is confirmed by a person on a live street
map, and is then stored in WordPress with its source and date. The public site never calls a map service.

## Sources by region type

| Region type | Source | How exact |
| --- | --- | --- |
| County, city | [US Census TIGERweb](https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer), queried by name, returned as GeoJSON | Legal boundary as of the layer's January 1 vintage. A recent annexation can lag. |
| ZIP area | Census [ZIP Code Tabulation Areas](https://www.census.gov/programs-surveys/geography/guidance/geo-areas/zctas.html) (ZCTA) | Approximate. A ZIP code is a set of delivery routes, not an area, and not every valid ZIP has a ZCTA. MapPulse labels these "ZIP area". |
| Neighborhood | The city or county GIS layer, registered once per market | Official where the city publishes it. There is no national neighborhood dataset. |
| Neighborhood, fallback | OpenStreetMap, then a hand-drawn or uploaded shape | Community-mapped or hand-made. The builder says which. |
| Coast, water, major roads | Census TIGERweb Hydro and Transportation services | Same vintage as the other Census layers |

## Layers used by the Phase 0 spike

| Layer | Service | Layer |
| --- | --- | --- |
| Neighborhood associations | City of St. Petersburg GIS, `ServicesDOTS/NeighborhoodRelations` | Neighborhood Associations |
| Cities | TIGERweb `tigerWMS_Current` | Incorporated Places |
| ZIP areas | TIGERweb `tigerWMS_Current` | ZIP Code Tabulation Areas |
| Land outline | TIGERweb `tigerWMS_Current` | Counties |
| Water | TIGERweb `Hydro` | Areal Hydrography |
| Roads | TIGERweb `Transportation` | Primary Roads, Secondary Roads |

The fetch script finds each layer by name and records the layer it used in `spike/results/<market>/sources.json`.

## Place search

- Cities, counties and ZIP areas are found by querying TIGERweb by name. No geocoder is needed.
- OpenStreetMap's public search (Nominatim) is optional and off by default. Its
  [usage policy](https://operations.osmfoundation.org/policies/nominatim/) allows at most one request
  per second per application, requires an identifying User-Agent or Referer, requires cached results,
  forbids autocomplete, and requires that the service can be switched off without a software update.
- When enabled, search runs on Enter, from the admin only, through a server-side proxy that throttles,
  caches and identifies the plugin.

## Attribution

- Census boundary files are public domain. No credit line is required.
- OpenStreetMap data requires attribution. The renderer prints "© OpenStreetMap contributors" when any
  OSM-derived shape is on the map.
- City and county GIS terms vary. The source record stores a license note per zone.

## Keeping shapes current

Each zone stores its source, record ID and fetch date, so a boundary can be checked for updates later
without redrawing the map.

## How the plugin talks to sources (0.2.0)

- Requests are made by the WordPress site, from the admin only, through one client: https only, a 20 second
  timeout, at least 300 ms between requests to one host, a 12 MB answer cap and two retries.
- The site may contact `tigerweb.geo.census.gov` and the hosts of the layers registered under MapPulse, Sources.
  Nothing else. The list can be changed with the `mappulse_allowed_hosts` filter.
- Answers are saved as JSON files in `wp-content/uploads/mappulse/cache/` for 30 days (searches and layer lists
  for one day). "Clear the boundary cache" on the Sources screen, or `wp mappulse cache flush`, removes them.
- A registered layer must be one polygon layer of an ArcGIS MapServer or FeatureServer. MapPulse reads its
  fields once and searches the first text field whose name contains "name".

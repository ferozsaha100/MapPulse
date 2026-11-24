#!/usr/bin/env bash
# Builds mappulse.zip from the plugin source, leaving out development files, and checks the result.
# Usage: bin/build-zip.sh [output path]. The zip holds one top-level folder, mappulse/.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-"$root/mappulse.zip"}
work=$(mktemp -d)

cp -R "$root/mappulse" "$work/mappulse"
# .distignore lists the development files, one path per line, relative to the plugin folder.
while IFS= read -r entry; do
	[ -n "$entry" ] && rm -rf "$work/mappulse/${entry#/}"
done < "$root/mappulse/.distignore"
rm -f "$out"
(cd "$work" && zip -qr "$out" mappulse)
rm -rf "$work"

list=$(unzip -Z1 "$out")
if echo "$list" | grep -qv '^mappulse/'; then echo "The zip has paths outside mappulse/"; exit 1; fi
if echo "$list" | grep -Eq '^mappulse/(tests|vendor)/'; then echo "The zip holds development files"; exit 1; fi
for needed in mappulse/mappulse.php mappulse/readme.txt mappulse/samples/st-petersburg.map.json mappulse/assets/css/mappulse.css mappulse/blocks/mappulse/block.json; do
	echo "$list" | grep -qx "$needed" || { echo "The zip is missing $needed"; exit 1; }
done

echo "Built $out: $(echo "$list" | grep -vc '/$') files, $(du -k "$out" | cut -f1) KB"

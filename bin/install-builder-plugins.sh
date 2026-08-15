#!/usr/bin/env bash
# Installs the free builder plugins mcLogiora's payload adapters are tested
# against, into the WordPress test installation.
#
# Only packages published on wordpress.org are downloaded. Commercial builders
# are never fetched here: a licensed product cannot be redistributed through
# CI, and a compatibility claim made without a legitimate copy would be a guess
# with a badge on it.
#
# Every plugin is optional. The tests that need one skip when it is absent, so
# a wordpress.org outage degrades this job to "nothing extra proven" rather
# than a false failure.

set -euo pipefail

WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}
PLUGIN_DIR="${WP_CORE_DIR}/wp-content/plugins"

# Slugs, not directory names. Two of these differ from the product name, which
# is exactly the kind of assumption that made mcLogiora's own detection wrong
# before Phase 15: Beaver Builder's free edition is
# `beaver-builder-lite-version` and SeedProd ships as `coming-soon`.
PLUGINS=(
	"elementor"
	"advanced-custom-fields"
	"kadence-blocks"
	"beaver-builder-lite-version"
)

mkdir -p "$PLUGIN_DIR"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sL -o "$2" "$1"
	else
		wget -nv -O "$2" "$1"
	fi
}

for slug in "${PLUGINS[@]}"; do
	if [ -d "${PLUGIN_DIR}/${slug}" ]; then
		echo "Already present: ${slug}"
		continue
	fi

	archive="/tmp/${slug}.zip"

	if ! download "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" "$archive"; then
		echo "::warning::Could not download ${slug}; its tests will skip."
		continue
	fi

	if ! unzip -q -o "$archive" -d "$PLUGIN_DIR"; then
		echo "::warning::Could not unpack ${slug}; its tests will skip."
		continue
	fi

	rm -f "$archive"
	echo "Installed: ${slug}"
done

echo "Builder plugins present:"
ls -1 "$PLUGIN_DIR"
